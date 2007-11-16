<?
$title = "Package Database - Package ";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

$uses_pathinfo = 1;
include "header.inc";
include "memcache.inc";
$package = $pispec;
?>


<?
if ($package == "-") {
?>
<p class="attention"><b>No package specified.</b></p>
<?
} else { /* if (no package) */


// Get url parameters
list($version, $inv_p) = get_safe_param('version', '/^[0-9\-.:]+$/');
list($distribution, $inv_p) = get_safe_param('distribution', '/^[a-z0-9\-.]+$/');
list($release, $inv_p) = get_safe_param('release', '/^[0-9.]{3,}$|^unstable$|^stable$/');
list($architecture, $inv_p) = get_safe_param('architecture', '/^powerpc$|^i386$/');
list($rel_id, $inv_p) = get_safe_param('rel_id', '/^[0-9]+$/');
list($showall, $inv_p) = get_safe_param('showall', '/^on$/');

// TODO/FIXME: Do we really need all the params above? On the other hand --
// if the user clicks on a specific package version, shouldn't we select
// that version via the *pkg_id* ?
// In particular, it seems rather ugly to search the DB with a concat...
// A reason to keep those params would be to allow looking at a specific 
// package version w/o knowing a pkg_id. But when would we ever be in such
// a situation? I.e. is there *anything* that would need to do that?


// Get package data to display (use for version-nonspecific pkg metadata)
$qtodisplay_sel = "SELECT p.*,r.*,d.*,p.version AS pkg_version ";
$qtodisplay_sel .= "FROM `package` AS p ";
$qtodisplay_sel .= "INNER JOIN `release` r ON r.rel_id = p.rel_id ";
$qtodisplay_sel .= "INNER JOIN `distribution` d ON r.dist_id = d.dist_id ";
if (!$showall)
  $qtodisplay_sel .= "AND d.visible='1' ";

$qtodisplay_where .= "WHERE p.name='$package' ";
if ($version) {
  if (strrpos($version, ':'))
    $qtodisplay_where .= "AND CONCAT(p.epoch,':',p.version,'-',p.revision)='$version' ";
  else
    $qtodisplay_where .= "AND CONCAT(p.version,'-',p.revision)='$version' ";
}
if ($rel_id) {
  $qtodisplay_where .= "AND r.rel_id='$rel_id' ";
} else { // no need to parse the other parameters
  if ($distribution) {
    $qtodisplay_where .= "AND d.identifier='$distribution' ";
  }
  if ($release) {
    if ($release == 'unstable' || $release == 'stable') {
      $qtodisplay_where .= "AND r.type='$release' ";
    } else {
      $qtodisplay_where .= "AND r.version='$release' ";
    }
  }
  if ($architecture) {
    $qtodisplay_where .= "AND d.architecture='$architecture' ";
  }
}

$qtodisplay_order .= "ORDER BY d.priority DESC,r.priority DESC";
$qtodisplay = $qtodisplay_sel.$qtodisplay_where.$qtodisplay_order;
$error_level = error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
$qs = cachedQuery($qtodisplay);
error_reporting($error_level);
if ($qs) {
  $pkg2disp = array_shift($qs);
}

$warning = '';
if (!$pkg2disp) { # No specific version found, try latest
  $qtodisplay = $qtodisplay_sel."WHERE p.name='$package' ".$qtodisplay_order;
  $error_level = error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
  $qs = cachedQuery($qtodisplay);
  error_reporting($error_level);
  if ($qs) {
    $pkg2disp = array_shift($qs);
  }
  $warning = "<b>Warning: Package $package $version not found";
  $warning .= $distribution ? " in distribution $distribution" : '';
  $warning .= $architecture ? "-$architecture" : '';
  $warning .= $release ? "-$release" : '';
  $warning .= $rel_id ? " for selected release" : '';
  $warning .= "!</b>";
}

if (!$pkg2disp) { # No package found
?>
<p><b>Package '<?=$package?>' not found in Fink!</b></p>
<?
} else { /* if (no package found) */

if ($pkg2disp["epoch"] > 0) {
  $fullversion = $pkg2disp["epoch"].':'.$pkg2disp["pkg_version"]."-".$pkg2disp["revision"];
} else {
  $fullversion = $pkg2disp["pkg_version"]."-".$pkg2disp["revision"];
}

?>
<h1>Package <? print $package." ".$fullversion ?></h1>
<?


 function avail_td($text, $extras='', $extra_div_style='') {
   print '<td align="center" valign="top" ' . $extras . '>';
   print '<div style="white-space:nowrap; ' . $extra_div_style . ' ">' . $text . '</div>';
   print '</td>';
 }
 
 function show_desc($label, $text) {
   $text = htmlentities($text);
   if ($text) {
     # Try to detect urls
     $text = preg_replace('/http:\/\/[^ &:]+/', '<a href="${0}">${0}</a>', $text);
     $text = str_replace("\\n", "\n", $text);
     $text = '<div class="desc">' . $text . '</div>';
     if ($label)
       it_item($label, '');
     it_item('', $text);
   }
 }
 
 function version_tags($package, $vers, $rel_id, $disp_vers, $disp_rel_id, $showall = false) {
   if ($vers == $disp_vers && $rel_id == $disp_rel_id) {
     $open_tag = '<b>';
     $close_tag = '</b>';
   } else {
     $open_tag = "<a href=\"$package?version=$vers&rel_id=$rel_id";
     if ($showall)
       $open_tag .= '&showall=on';
     $open_tag .= '">';
     $close_tag = '</a>';
   }
   return array (
     $open_tag,
     $close_tag,
   );
 }
 
 function link_to_package($package, $vers, $rel_id, $showall = false, $description='') {
   $pkg_str = '<a href="'.$package;
   $pkg_param = '';
   if ($vers) {
     $pkg_param .= '?version='.$vers;
     if ($rel_id)
       $pkg_param .= '&rel_id='.$rel_id;
   }
   elseif ($rel_id)
     $pkg_param .= '?rel_id='.$rel_id;
   if ($showall) {
     if ($pkg_param)
       $pkg_param .= '&showall=on';
     else
       $pkg_param .= '?showall=on';
   }
   $pkg_str .= $pkg_param.'">'.$package.'</a> ';
   if ($description)
     $pkg_str .= htmlentities($description);
   return $pkg_str;
 }


 print '<table class="pkgversion" cellspacing="2" border="0">'."\n";

 print '<tr bgcolor="#ffecbf">';
 print '<th width="100" align="center" valign="bottom" rowspan="2">System</th>';
 print '<th width="150" align="center" valign="bottom" rowspan="2">Binary Distributions</th>';
 print '<th width="202" align="center" colspan="2">CVS/rsync Source Distributions</th>';
 print "</tr>\n";

 print '<tr bgcolor="#ffecbf">';
 print '<th width="100" align="center"><a href="http://feeds.feedburner.com/FinkProjectNews-stable"><img src="' . $pdbroot . 'rdf.png" alt="stable RSS feed" border="0"  width="14" height="14" /></a> stable</th>';
 print '<th width="100" align="center"><a href="http://feeds.feedburner.com/FinkProjectNews-unstable"><img src="' . $pdbroot . 'rdf.png" alt="unstable RSS feed" border="0"  width="14" height="14" /></a> unstable</th>';
 print "</tr>\n";

 // Fetch list of all distributions
 $q = "SELECT * FROM `distribution` WHERE active='1' ";
 if (!$showall)
   $q .= "AND visible='1' ";
 $q .= "ORDER BY priority DESC";
 $qdist = cachedQuery($q);
 if (!$qdist) {
   die('<p class="attention"><b>Error during db query (Distributions):</b> '.mysql_error().'</p>');
 }
 $color_count = 0;
 $last_identifier = '';
 $has_unsupported_dists = false;
 foreach ($qdist as $dist_row) {
   if ($last_identifier != $dist_row['identifier'])
     $color_count++;
   if ($color_count == 1) {
     $row_color='bgcolor="#e3caff"';
   } else if ($color_count == 2) {
     $row_color='bgcolor="#f1e2ff"';
   } else {
     $row_color='bgcolor="#f6ecff"';
   }
   $last_identifier = $dist_row['identifier'];

   // Query all releases (typically: stable, unstable, bindist) for the given distribution.
   $q = "SELECT r.type FROM `release` r WHERE r.dist_id='" . $dist_row['dist_id'] . "' AND r.active='1' ORDER BY r.priority ASC";
   $qrel = cachedQuery($q);
   if (!$qrel) {
     die('<p class="attention"><b>Error during db query (Releases):</b> '.mysql_error().'</p>');
   }
   $has_bindist = false;
   $has_cvs_rsync = false;
   foreach ($qrel as $rel_row) {
     if ($rel_row['type'] == 'bindist')
       $has_bindist = true;
     elseif ($rel_row['type'] == 'unstable' || $rel_row['type'] == 'stable')
       $has_cvs_rsync = true;
   }

   // Now query all pkginfo per release (typically: stable, unstable, bindist) for the given distribution.
   $q = "SELECT p.*, r.type, r.version AS rel_version FROM `package` p, `release` r WHERE p.name = '$package' AND p.rel_id = r.rel_id AND r.dist_id='" . $dist_row['dist_id'] . "' AND active='1' ORDER BY r.priority ASC";
   $qrel = cachedQuery($q);
   
   $pkg_release = array();
   
   foreach ($qrel as $rel_row) {
     $type = $rel_row['type'];
     $rel_version = $rel_row["version"]."-".$rel_row["revision"];
     if($rel_row["epoch"] > 0)
       $rel_version = $rel_row["epoch"].":".$rel_version;
     $pkg_release[$type]["version"] = $rel_version;
     $pkg_release[$type]["restrictive"] = (strcasecmp($rel_row["license"],'Restrictive')==0);
     $pkg_release[$type]["rel_id"] = $rel_row['rel_id'];
     $pkg_release[$type]["pkg_id"] = $rel_row['pkg_id'];
     $pkg_release[$type]["rel_version"] = $rel_row['rel_version'];
   }
   // System
   print "<tr $row_color>";
   if ($dist_row['unsupported']) {
     avail_td(nl2br($dist_row['description'] . ' *'), '', 'color:gray; ');
     $has_unsupported_dists = true;
   }
   else {
     avail_td(nl2br($dist_row['description']));
   }

    // bindist
    if ($has_bindist) {
      $vers = $pkg_release["bindist"]["version"];
      list($open_tag, $close_tag) = 
        version_tags($package, $vers, $pkg_release["bindist"]["rel_id"], $fullversion, $pkg2disp["rel_id"], $showall);
      avail_td(
        strlen($vers) && !$pkg_release["bindist"]["restrictive"]
        ? $open_tag . $vers . $close_tag . ' (bindist '.$pkg_release["bindist"]["rel_version"].')'
        : '&mdash;'
        , $bindist_rowspan
      );
      // need to use specific tag for info file in fink cvs?
      if (strlen($vers) && !$pkg_release["bindist"]["restrictive"])
        if ($vers == $fullversion && $pkg_release["bindist"]["rel_id"] == $pkg2disp["rel_id"])
          $pkg2disp['bindist'] = $pkg_release["bindist"]["rel_version"];
    } else {
      avail_td('<i>no binary distribution</i>',$bindist_rowspan);
    }

    // CVS/rsync dist
    if ($has_cvs_rsync) {
      $vers = $pkg_release["stable"]["version"];
      list($open_tag, $close_tag) = 
        version_tags($package, $vers, $pkg_release["stable"]["rel_id"], $fullversion, $pkg2disp["rel_id"], $showall);
      avail_td(
        strlen($vers)
          ? $open_tag . $vers . $close_tag
          : '&mdash;'
        , $bindist_rowspan
      );
      $vers = $pkg_release["unstable"]["version"];
      list($open_tag, $close_tag) = 
        version_tags($package, $vers, $pkg_release["unstable"]["rel_id"], $fullversion, $pkg2disp["rel_id"], $showall);
      avail_td(
        strlen($vers)
          ? $open_tag . $vers . $close_tag
          : '&mdash;'
        , $bindist_rowspan
      );
    } else {
      avail_td("<i>unsupported</i>",$bindist_rowspan.' colspan=2');
    }
    print "</tr>\n";
  }
  
  print "</table>\n";

  print "<br>";

  it_start();
  
  if ($warning)
    it_item('', $warning);

  it_item("Description:", htmlentities($pkg2disp[descshort]) . " (" . $fullversion . ")");
  show_desc('', $pkg2disp[desclong]);

  show_desc('Usage&nbsp;Hints:', $pkg2disp[descusage]);

  it_item("Section:", '<a href="'.$pdbroot.'browse.php?section='.$pkg2disp[section].'">'.$pkg2disp[section].'</a>');

  // Get the maintainer field, and try to parse out the email address
  if ($pkg2disp[maintainer]) {
	$maintainers = $pkg2disp[maintainer];
	preg_match("/^(.+?)\s*<(\S+)>/", $maintainers, $matches);
    $maintainer = $matches[1];
    $email = $matches[2];
  } else {
    $maintainer = "unknown";
  }
  // If there was an email specified, make the maintainer field a mailto: link
  if ($email) {
    $email = str_replace(array("@","."), array("AT","DOT"), $email);
    it_item("Maintainer:", '<a href="'.$pdbroot.'browse.php?maintainer='.$maintainer.'">'.$maintainer.' &lt;'.$email.'&gt;'.'</a>');
#    it_item("Maintainer:", '<a href="mailto:'.$email.'">'.$maintainer.'</a>');
  } else {
    it_item("Maintainer:", '<a href="'.$pdbroot.'browse.php?maintainer='.$maintainer.'">'.$maintainer.'</a>');
  }
  if ($pkg2disp[homepage]) {
    it_item("Website:", '<a href="'.$pkg2disp[homepage].'">'.$pkg2disp[homepage].'</a>');
  }
  if ($pkg2disp[license]) {
    it_item("License:", '<a href="http://fink.sourceforge.net/doc/packaging/policy.php#licenses">'.$pkg2disp[license].'</a>');
  }
  if ($pkg2disp[parentname]) {
    it_item("Parent:", link_to_package($pkg2disp[parentname], $version, $rel_id, $showall));
  }
  if ($pkg2disp[infofile]) {
    # where the info file sits on a local Fink installation
    $infofile_path = "/sw/" . str_replace('dists/', '', $pkg2disp[infofile]);
    $infofile_cvs_url = 'http://fink.cvs.sourceforge.net/'.$pkg2disp[infofile];
    if ($pkg2disp['bindist'])
      $infofile_tag = '?pathrev=release_'.str_replace('.', '_', $pkg2disp['bindist']);
    else
      $infofile_tag = '';
    $infofile_html  = '<a href="'.$infofile_cvs_url.$infofile_tag.($infofile_tag ? '&' : '?').'view=markup">'.$infofile_path.'</a><br>';
    $infofile_html .= '<a href="'.$infofile_cvs_url.$infofile_tag.'">CVS log</a>, Last Changed: '.$pkg2disp[infofilechanged];
    it_item("Info-File:", $infofile_html);
  }


	// List the splitoffs of this package

	$q = "SELECT * FROM `package` WHERE rel_id='$pkg2disp[rel_id]' AND parentname='$pkg2disp[name]' ORDER BY name";
   $rs = cachedQuery($q);
	if($row = array_shift($rs))
	  it_item("SplitOffs:", link_to_package($row["name"], $version, $rel_id, $showall, $row["descshort"]));
	foreach ($rs as $row) {
	  it_item(" ", link_to_package($row["name"], $version, $rel_id, $showall, $row["descshort"]));
	}
  it_end();
?>



<?
} /* if (no package found) */
} /* if (no package) */
?>

<p><a href="<? print $pdbroot ?>sections.php">Section list</a> -
<a href="<? print $pdbroot ?>browse.php">Flat package list</a> -
<a href="<? print $pdbroot ?>browse.php?nolist=on">Search packages</a>
</p>


<?
if ($has_unsupported_dists) {
?>
<p>(*) = Unsupported distribution.</p>
<?
}
include "footer.inc";
?>
