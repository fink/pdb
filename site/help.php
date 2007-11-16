<?
$title = "Package Database - Help Needed";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

$have_key = isset($maintainer);

include "header.inc";
?>

<h1>Packages in Testing</h1>

<p>
Help is needed for testing packages with a version in
unstable that is newer than the version in stable.
The list is based on the latest <a
href="http://fink.sourceforge.net/doc/cvsaccess/index.php">packages from CVS</a>.
</p>

<p>
<a
href="browse.php?tree=testing&nochildren=on">
Browse the full list
</a> of packages that need testing.
</p>

<h1>Packages without Maintainer</h1>

<p>
Help is also needed for packages without an active maintainer.
</p>

<p>
<a
href="browse.php?maintainer=None&nochildren=on">
Browse the full list
</a> of packages without maintainer.
</p>


<?
include "footer.inc";
?>
