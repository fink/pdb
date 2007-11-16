<?
/* $Id: header.inc,v 1.1 2007/11/16 19:36:09 rangerrick Exp $ */

$linkroot = "http://www.finkproject.org/";
$localroot = "http://pdb.finkproject.org/";

$server = $_SERVER['SERVER_NAME'];
$uri = $_SERVER['REQUEST_URI'];

if ($server == "fink.sourceforge.net") { 
  header("HTTP/1.1: 301 Moved Permanently");
  header("Location: http://pdb.finkproject.org$uri");
  exit();
}

if (!$cache_timeout) {
	// 1 hour
	$cache_timeout = 3600;
}

header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cache_timeout) . " GMT");
header("Cache-Control: max-age=" . $cache_timeout . ", s-maxage=" . ($cache_timeout / 2));

/* check path info */
$PATH_INFO = $HTTP_SERVER_VARS["PATH_INFO"];
if ($uses_pathinfo) {
  if ($PATH_INFO == "") {
    $pispec = "-";
    $title .= "(none)";
    $pdbroot = "";
  } elseif (ereg("^/([a-zA-Z0-9_.+-]+)$", $PATH_INFO, $r)) {
    $pispec = $r[1];
    $title .= $pispec;
    $pdbroot = "../";
  } elseif (ereg("^/([a-zA-Z0-9_.+-]+/[a-zA-Z0-9_.+-]+)$", $PATH_INFO, $r)) {
    $pispec = $r[1];
    $title .= $pispec;
    $pdbroot = "../../";
  } else {
    print '<p class="attention"><b>PATH_INFO not in expected format!</b></p>';
    exit;
  }
  $root = "-".$pdbroot;
}

/* generate page header and navigation */
$section = "packages";
$navbox = array(
  $localroot."pdb/index.php", "Introduction",
  $localroot."pdb/sections.php", "By Section",
  $localroot."pdb/browse.php", "By Package",
  $localroot."pdb/browse.php?nolist=on", "Search",
  $localroot."pdb/help.php", "Help needed"
);

$fsroot = "../";
if (substr($root,0,1) == "-") {
  $root = substr($root.$fsroot,1);
} else {
  $root = $fsroot;
}
include "hostlogo.inc";
include $fsroot."header.inc";

/* connect to database */
include $fsroot."db.inc.php";
$error_level = error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
try {
  $dbh = mysql_pconnect($db_host, $db_user, $db_passwd);
  mysql_select_db($db_name, $dbh);
}
catch (Exception $e) {
}
error_reporting($error_level);
if (!$dbh)
  print '<p class="attention"><b>Couldn\'t connect to database!</b></p>';


// Read url parameters. We use basic HTML encoding for now, and 
// cut off very long values, to make unforseen SQL injection hacks more difficult.
function get_safe_param($param_name, $valid_regexp='.*', $max_length=35) {
  $param_name = $_GET[$param_name];
  if ($param_name) $has_param_name = true;
  if (strlen($param_name) > $max_length || !preg_match($valid_regexp, $param_name)) {
    $param_name = '';
  } else {
    $param_name = mysql_real_escape_string(htmlspecialchars($param_name));
    if (strlen($param_name) > $max_length || !preg_match($valid_regexp, $param_name))
      $param_name = '';
  }
  if ($has_param_name && !$param_name)
    $invalid_param = true;
  else
    $invalid_param = false;
  return array (
    $param_name,
    $invalid_param,
  );
}
 

?>
