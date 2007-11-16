<?
$title = "Package Database";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

include "header.inc";
include "memcache.inc";
?>


<h1>Archive Sections</h1>

<p>
The package archive is divided into thematic sections.
That makes it easier to find the package you want.
Here are the sections:
</p>

<?
$q = "SELECT * FROM sections ORDER BY name ASC";
$rs = cachedQuery($q);
if (!$rs) {
  print '<p><b>error during query:</b> '.mysql_error().'</p>';
} else {
  $seccount = count($rs);
?>

<ul>
<?
  foreach ($rs as $row) {
    print '<li><a href="browse.php?section='.$row[name].'">'.$row[name].'</a>'.
      ($row[description] ? (' - '.$row[description]) : '').
      '</li>'."\n";
  }
?>
</ul>
<?
}
?>


<?
include "footer.inc";
?>
