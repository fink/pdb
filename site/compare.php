<?
$title = "Package Database";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

include "header.inc";
include "releases.inc";
include "memcache.inc";
?>
  <STYLE TYPE="text/css">
.bgreen { background: #66FF10; display: inline; }
       
.bred { background: red; display: inline; }

.tiny {
  font-size: x-small;
  font-weight: bold;
}
  </STYLE>
  <hr>
<h3>Compare Trees</h3>
This search compares package names in two trees. 
It does not take versions into account.<br>

<form action="compare.php" method="GET">

<?PHP
$tree1 = 'current-10.2-gcc3.3-unstable';
if(param("tree1"))
	$tree1 = param("tree1");
$tree2 = 'current-10.3-unstable';
if(param("tree2"))
	$tree2 = param("tree2");
$cmp = 0;
if(param("cmp"))
	$cmp = param("cmp");
$splitoffs = 0;
if(param("splitoffs"))
	$splitoffs = param("splitoffs");
$sort = 'maintainer';
if(param("sort"))
	$sort = param("sort");
else
	$sort = 'maintainer';
if(param("hidewhite"))
	$hidewhite = param("hidewhite");
	
if(param("hidegreen"))
	$hidegreen = param("hidegreen");
	
if(param("hidered"))
	$hidered = param("hidered");
	
if(param("red"))
	$op = 3;
if(param("green"))
	$op = 2;
if(param("white"))
	$op = 1;

if($op) {
	foreach ($HTTP_POST_VARS as $argb) {	
		if (preg_match("/chg=([^!]+)/i", $argb, $matches)) {
			$name = $matches[1];
			$q = "UPDATE move SET moveflag = ".($op - 1)." WHERE (`release` = '".$tree1.
				"' AND name='".$name."')";	
			$rsr = cached_query($q);
			if (mysql_errno()) {
				print '<p><b>errno $err error during UPDATE:</b> '.mysql_error().'</p>';
				die;
			}							
		}
	}
}
$q = "SELECT * FROM `release`";
$rs = cached_query($q);
if (!$rs) {
  print "<p><b>error during query ".$q.':</b> '.mysql_error().'</p>';
} else {
  foreach ($rs as $row) {
    $menu = $menu . '<option value="'. $row['name']. '" '.
    		(strcmp($row['name'], $tree1) ? '>' : 'selected>').
    		$row['name'];
    $menu2 = $menu2 . '<option value="'. $row['name']. '" '.
    		(strcmp($row['name'], $tree2) ? '>' : 'selected>').
    		$row['name'];
	}
		
  print "Show packages in:<SELECT name = tree1>" . $menu . '</SELECT>';
  print 'that <SELECT name = cmp>'.
  		'<option value=1 '. ($cmp ? 'selected>' : '>'). 'are'.
  		'<option value=0 '. ($cmp ? '>' : 'selected>'). 'are not</SELECT>'; 
  print 'in:<SELECT name = tree2>' . $menu2 . '</SELECT>';
  print "<input type=checkbox name=\"splitoffs\" ".
  		($splitoffs ? 'checked>' : '>').
  		"Include Splitoffs<br>";
  print 'Sort: <SELECT name = sort>'.
  		'<option value=maintainer '. (strcmp($sort, "maintainer") ? '>' : 'selected>'). 'Maintainer'.
  		'<option value=name '. (strcmp($sort, "name") ? '>' : 'selected>'). 'Package Name</SELECT>';
    print "Hide: <input type=checkbox name=\"hidewhite\" ".($hidewhite ? 'checked>' : '>')."White".
     	"<input type=checkbox name=\"hidegreen\" ".($hidegreen ? 'checked>' : '>')."Green".
     	"<input type=checkbox name=\"hidered\" ".($hidered ? 'checked>' : '>')."Red";
?>  
<input type="submit" value="Search">
</form>
<?PHP
	#Special case for 10.2-gcc3.3 to 10.3 move
	if(! strcmp($tree1, "current-10.2-gcc3.3-unstable") && ! strcmp($tree2, "current-10.3-unstable") && $cmp == 0)
	{
?>  
<div tiny>
<form action="compare.php" method="POST">

<?PHP
	}
}
$q = "SELECT name,maintainer,version,revision,moveflag,needtest FROM package ".
  	 "WHERE `release` LIKE \"$tree1\" ".
  	 ($splitoffs ? '' : 'AND parentname IS NULL ').
  	 "ORDER BY ".(strcmp($sort, "name") ? 'maintainer,name' : 'name')." ASC";
$rs = cachedQuery($q);
if (mysql_errno()) {
  print "<p><b>error during query ".$q.':</b> '.mysql_error().'</p>';
} else {
  $count = count($rs);

  print '<p>'.$count." Packages Found in $tree1</p>";
  $hitcount = 0;

#Special case for 10.2-gcc3.3 to 10.3 move
  if(! strcmp($tree1, "current-10.2-gcc3.3-unstable") && ! strcmp($tree2, "current-10.3-unstable") && $cmp == 0)
  {
  	$line = $greencount = $redcount = $whitecount = 0;
   	print "Key:<br><ul><li><div class=\"bgreen\">Will not be moved, obsolete or changed names</div><li><div class=\"bred\">Does not compile</div></ul>";
   	print "\"Wow, you know that's a lot of checkboxes\" - Check packages then click the buttons below to change a line's status.<br>";
  }
 $pkglist = $pkglist . "<ul>\n";
  foreach ($rs as $row) {
	$q2 = "SELECT name FROM package ".
      "WHERE `release` LIKE \"$tree2\" AND name=\"" . $row['name'] . '"';	
	$rs2 = cachedQuery($q2);
	if (mysql_errno()) {
	  print "<p><b>error during query ".$q2.':</b> '.mysql_error().'</p>';
	} else {
  		$count = count($rs2);
  		$hit = 0;
		if($cmp == 0) {
  			# are NOT - count will be 0
			if($count == 0) $hit = 1;
		} else {
			# are - count > 0
			if($count > 0) $hit = 1;
 		}
  		if($hit)
  		{
			#Special case for 10.2-gcc3.3 to 10.3 move
			if(! strcmp($tree1, "current-10.2-gcc3.3-unstable") && ! strcmp($tree2, "current-10.3-unstable") && $cmp == 0)
			{
				$qmove = "SELECT moveflag FROM move ".
      				"WHERE `release` LIKE \"$tree1\" AND name=\"" . $row['name'] . '"';
				$rsm = cachedQuery($qmove);
				$err = mysql_errno();
				if ($err) {
					print '<p><b>errno $err error during query :</b> '.mysql_error()."$qmove".'</p>';
					die;	
				}			
				
  				$mcount = count($rsm);
  				
  				if($mcount == 0) {
					### Must be new here. Insert the record into the move table		
					$qmove2 = "INSERT INTO move (`release`, name, moveflag) VALUES (\"".$tree1.
							  "\",\"".$row[name]."\", 0)"; 
					$rs1 = cachedQuery($qmove2);
					$err = mysql_errno();
					if ($err) {
						print '<p><b>errno $err error during query :</b> '.mysql_error()."$qmove2".'</p>';
						die;	
					}		
					$moveflag = 0;					
  				} elseif($mcount =! 1) {
  					### This should never happen. PRIMARY KEY violated?
  					die("Error: mcount for ".$row[name]."in move table is $mcount !");
				} else {
					### Fetch the moveflag from the first SELECT		
					foreach ($rsm as $row3) {
						$moveflag = $row3[moveflag];
					}
				}
			
			if($moveflag == 1) {
					$greencount++;
					if($hidegreen)
						continue;
					$pkglist = $pkglist."<li><div class=\"bgreen\">";
					$green = 1;
					$red = 0;
				}	elseif($moveflag == 2) {
					$redcount++;
					if($hidered)
						continue;
					$pkglist = $pkglist."<li><div class=\"bred\">";
					$red = 1;
					$green = 0;
				} elseif ($hidewhite) {
					$whitecount++;
					continue;
				} else {
					$whitecount++;
					$pkglist = $pkglist."<li>";	
				}
				$line++;
				$pkglist = $pkglist . 
					"<input type=checkbox name=change-$line value=chg=".$row[name].'!'.$row[version].'!'.$row[revision].'>   '; 	
			} else {
				$pkglist = $pkglist . '<li>';
			}

			$desc = "";  			
			
			if(preg_match("/([^<]+)<.*/i", $row[maintainer], $matches))
				$maintainer = $matches[1];
			else
				$maintainer = 'None ';
				
			if(! strcmp($sort, "maintainer"))
				$pkglist = $pkglist . $maintainer.'<a href="package.php/'.$row[name].'">'.$row[name].'</a> '.
				 $row[version].'-'.$row[revision].$desc . "\n";  
			else
				$pkglist = $pkglist . '<a href="package.php/'.$row[name].'">'.$row[name].'</a> '.
				 $row[version].'-'.$row[revision].$desc .' - '.$maintainer."\n"; 

			if($row[moveflag] > 0)
				$pkglist = $pkglist."</div>";
	
			$pkglist = $pkglist."</li>\n";
#			$pkglist = $pkglist."<br>";
  			$hitcount++;			
  		}
	} 
  }
  $pkglist = $pkglist . '</ul>';
  print "<br>Found $hitcount Packages in $tree1 that ". ($cmp ? 'are' : 'are not') .
  		" in $tree2.<br>";
  print "Total: $greencount green, $redcount red, $whitecount white packages.<br>\n";
  print "$pkglist\n";
	#Special case for 10.2-gcc3.3 to 10.3 move
	if(! strcmp($tree1, "current-10.2-gcc3.3-unstable") && ! strcmp($tree2, "current-10.3-unstable") && $cmp == 0)
	{
		print '<input type="hidden" name=tree1 value='.$tree1.'>';
		?>
		<input type="submit" name=green value="Set Green">
		<input type="submit" name=red value="Set Red">
		<input type="submit" name=white value="Set White">
		</form>
		</div>
		<?
	}
}
?>

<?
include "footer.inc"; 
?>
