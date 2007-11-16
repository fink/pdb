<?
$title = "Package Database - Obsolete page";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

$server = $_SERVER['SERVER_NAME'];
$location = "pdb/browse.php";

if (isset($_GET['maintainer'])) {
	$maintainer = htmlspecialchars($_GET['maintainer']);
	$location .= "?maintainer=" . $maintainer;
}

// This page is obsolete. We redirect to browse.php
header("Location: http://" . $server . "/" . $location);

?>
