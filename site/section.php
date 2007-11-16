<?php
$title = "Package Database - Obsolete page";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

/* check path info */
$PATH_INFO = $HTTP_SERVER_VARS["PATH_INFO"];
if (ereg("^/([a-zA-Z0-9_.+-]+)$", $PATH_INFO, $r)) {
	$section = $r[1];
} elseif (ereg("^/([a-zA-Z0-9_.+-]+/[a-zA-Z0-9_.+-]+)$", $PATH_INFO, $r)) {
	$section = $r[1];
}

$server = $_SERVER['SERVER_NAME'];
$location = "pdb/browse.php";

if (isset($section)) {
	$location .= "?section=" . $section;
}

// This page is obsolete. We redirect to browse.php
header("Location: http://$server/$location");

?>
