<?
$title = "Package Database";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

// 2 hours, this page does not change much
$cache_timeout = 7200;

include "header.inc";
include "memcache.inc";
?>

<h1>Package Database Introduction</h1>

<p>
This database lists all available Fink packages.
It knows about the "stable" tree of the latest release and
about all packages in CVS ("current-stable" and "current-unstable").
Note that some packages are only available in the "unstable" tree.
</p>

<p>
<b>Read this:</b>
The above means that a default install of Fink will not recognize some
packages listed here.
That is because those packages are in a section of the archive called
"unstable" because they are not well-tested.
You can help improve the situation by testing those packages and
reporting both success and failure to the package maintainer.
The <a href="browse.php?tree=testing&nochildren=on">Packages in Testing</a> page lists all
packages that still have to pass testing.
In order to test the packages, you need to configure Fink to <a href="http://fink.sourceforge.net/faq/usage-fink.php#unstable">use
unstable</a> and then download the latest descriptions by running <i>fink selfupdate-rsync</i> 
(or <i>fink selfupdate-cvs</i> if you can't use rsync for some reason).
</p>
<p>Help is also needed to find new maintainers for the <a
href="browse.php?maintainer=None&nochildren=on">packages without maintainers</a>.</p>

<?
$q = "SELECT COUNT(DISTINCT name) FROM package";
$rs = cachedQuery($q);
if (!$rs) {
  print '<p><b>error during query:</b> '.mysql_error().'</p>';
  $pkgcount = '?';
} else {
  $pkgcount = array_shift($rs);
  $pkgcount = $pkgcount[0];
}

$q = "SELECT MAX(UNIX_TIMESTAMP(infofilechanged)) FROM package";
$rs = cachedQuery($q);
if (!$rs) {
  print '<p><b>error during query:</b> '.mysql_error().'</p>';
  $dyndate = '';
} else {
  $dyndate = array_shift($rs);
  $dyndate = $dyndate[0];
}

$q = "SELECT * FROM sections ORDER BY name ASC";
$rs = cachedQuery($q);
if (!$rs) {
  print '<p><b>error during query:</b> '.mysql_error().'</p>';
} else {
  $seccount = count($rs);
?>

<p>
The database was last updated
<? print gmstrftime("at %R GMT on %A, %B %d", $dyndate) ?> and currently lists
<? print $pkgcount ?> packages in <? print $seccount ?> sections.
</p>

<form action="browse.php" method="GET">
<p>Search for package: <input type="text" name="summary" size="15" value="">
<input type="submit" value="Search">
</p>
</form>

<p>
You can browse a <a href="browse.php">complete list of packages</a>,
or you can browse by archive section:
</p>

<ul>
<?
  foreach ($rs as $row) {
    print '<li><a href="browse.php?section='.$row[name].'">'.$row[name].'</a>'.  ($row[description] ? (' - '.$row[description]) : '').  '</li>'."\n";
  }
?>
</ul>
<?
}
?>

<script type="text/javascript" language="JavaScript" src="http://db3.net-filter.com/script/13500.js"></script>
<noscript><img src="http://db3.net-filter.com/db.php?id=13500&amp;page=unknown" alt=""></noscript>

<?
include "footer.inc";
?>
