<?
$title = "Package Database";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/16 19:36:09 $';

ini_set("memory_limit", "24M");

function addGETParam(&$params, $param_name) {
  $value = stripslashes($_GET[$param_name]);
  if ($value)
    $params[$param_name] = urlencode($value);
}


if (isset($_GET['submit']) && $_GET['submit'] == 'Search') {
  // Re-direct to clean out empty params
  $getparams = array();
  $value = $_GET['nomaintainer'];
  if ($value == 'on')
    $_GET['maintainer'] = 'None';
  addGETParam($getparams, 'maintainer');
  addGETParam($getparams, 'name');
  addGETParam($getparams, 'summary');
  addGETParam($getparams, 'nolist');
  addGETParam($getparams, 'dist');
  addGETParam($getparams, 'tree');
  addGETParam($getparams, 'section');
  addGETParam($getparams, 'nochildren');
  addGETParam($getparams, 'noshlibsdev');
  addGETParam($getparams, 'sort');
  addGETParam($getparams, 'showall');
  $redirect_url = '?';
  foreach ($getparams as $key => $value) {
    $redirect_url .= "$key=$value&";
  }
  $redirect_url = rtrim($redirect_url, '&?');
  header("Location: browse.php$redirect_url");
}

// are there any advanced search options? If no, collapse advanced search div
if ($_GET['maintainer'] || $_GET['name'] || $_GET['dist'] || $_GET['tree'] || $_GET['section'] || $_GET['nochildren'] || $_GET['noshlibsdev'] || $_GET['sort'])
  $pdb_has_adv_searchoptions = true;
else
  $pdb_has_adv_searchoptions = false;

// load javascript for pdb in header.inc
$pdb_scripts = true;

include "header.inc";
include "memcache.inc";

?>

<h1>Browse packages</h1>

<p>
On this page you can browse through all packages in the Fink package database,
optionally and at your direction narrowed down by various search parameters, set below.
</p>
<p>
This database includes
information about all packages found in the respective latest stable and unstable trees.
Furthermore, all packages from the most recent binary distributions are covered.
</p>

<?
/*
TODO: more output: put the list into a table, one column with versions for each tree/distro,
  maybe the maintainer, etc.
  
TODO: Eventually make use of <label for="...">
*/



/*
Read and parse input parameters. The following keys/values are currently used
(where true/false are substituted by 0/1, and "empty" implies the default
value, which is the first in the list (usually "any"):
$maintainer  any, name
$dist  any, dist name
$section any, sectio name
$tree  any, stable, testing (=stable version outdated), unstable, bindist
$nochildren  any, true, false
*/

/**
 * Simple function to replicate PHP 5 behaviour
 */
function microtime_float()
{
  list($usec, $sec) = explode(" ", microtime());
  return ((float)$usec + (float)$sec);
}

// This function generates a form popup, with the given
// variable name, current value, and list of possible values.
function genFormSelect($var_name, $cur_val, $values, $description = '') {
	echo "<select NAME='$var_name'>\n";
	foreach ($values as $key => $val) {
		echo "  <option value='$key' ";
		if ($cur_val == $key) echo "selected";
		echo ">$val</option>\n";
	}
	echo "</select>\n";
	echo $description ? " $description" : '';
	echo "<br>";
}

list($showall, $inv_p) = get_safe_param('showall', '/^on$/');

// Distribution values
$dist_values = array();
$q = "SELECT `dist_id`, `description` FROM `distribution` WHERE active='1' ";
if (!$showall)
  $q .= "AND visible='1' ";
$q .= "ORDER BY priority DESC";
$qdist = cachedQuery($q);
if (!$qdist) {
  die('<p class="attention"><b>Error during db query (distribution):</b> '.mysql_error().'</p>');
}
$dist_values[''] = 'Any';
foreach ($qdist as $dist) {
  $dist_values[$dist['dist_id']] = $dist['description'];
}

// Allowed values for certain fields
$tree_values = array(
		"" => "Any",
		"unstable" => "Unstable",
		"stable" => "Stable",
		"bindist" => "Binary Distribution",
		"testing" => "Packages that need testing"
	);
$sort_values = array(
	"" => "Ascending",
	"DESC" => "Descending"
	);

// Load legal sections
$section_values = array('' => 'Any');
$query = "SELECT `name`, `description` FROM sections ORDER BY name ASC";
$rs = cachedQuery($query);
if (!$rs) {
	print '<p class="attention"><b>Error during db query (sections):</b> '.mysql_error().'</p>';
} else {
	$seccount = count($rs);
	foreach ($rs as $row) {
		$section_values[$row["name"]] = $row["name"] . " - " . $row["description"];
	}
}

// Read url parameters
// NOTE: You have to change the parameter list at the top of this file as well
$invalid_param = false;
list($maintainer, $inv_p) = get_safe_param('maintainer', '/^[a-zA-Z0-9\.@%\&\'\\\ ]+$/');
$invalid_param = $invalid_param || $inv_p;
if ($inv_p) { $invalid_param_text = 'Maintainer contained invalid characters!'; }
list($name, $inv_p) = get_safe_param('name', '/^[a-z0-9+\-.%]+$/');
$invalid_param = $invalid_param || $inv_p;
if ($inv_p) { $invalid_param_text = 'Name contained invalid characters!'; }
list($summary, $inv_p) = get_safe_param('summary', '/...*/');
$invalid_param = $invalid_param || $inv_p;
if ($inv_p) { $invalid_param_text = 'Summary search must be at least 2 characters!'; }
list($nolist, $inv_p) = get_safe_param('nolist', '/on/');
$invalid_param = $invalid_param || $inv_p;

// Extract the distribution
$dist = $_GET['dist'];
if (!isset($dist_values[$tree]))
	$tree = '';

// Extract the tree
$tree = $_GET['tree'];
if (!isset($tree_values[$tree]))
	$tree = '';

// Extract the section
$section = $_GET['section'];
if (!isset($section_values[$section]))
	$section = '';

// 
$nochildren = $_GET['nochildren'];
if ($nochildren != "on") $nochildren = '';

// 
$noshlibsdev = $_GET['noshlibsdev'];
if ($noshlibsdev != "on") $noshlibsdev = '';

// Sort direction
$sort = $_GET['sort'];
if ($sort != "DESC") $sort = '';

?>

<form action="browse.php" method="get" name="pdb_browser" id="pdb_browser" onreset="resetForm();return false;">
<?if ($showall) print '<input name="showall" type="hidden" value="on">';?>
<br>
Summary:
<input name="summary" type="text" value="<?=stripslashes(stripslashes($summary))?>"> (Leave empty to list all)
<br>

<input name="submit" type="submit" value="Search">
<input type="reset" value="Clear Form">
<br>
<?if ($invalid_param) print '<p class="attention">Invalid Input Parameters.  ' . $invalid_param_text . '</p>';?>
<br>

<span class="expand_adv_options">
<a href="javascript:switchMenu('advancedsearch','triangle');" title="Advanced search options"><img src="<? echo $root ?>img/collapse.png" alt="" id="triangle" width="9" height="8">&nbsp;Advanced search options</a>
</span>
<br>

<div id="advancedsearch">

<table><tr>
<td>Package Name:</td>
<td><input name="name" type="text" value="<?=$name?>"> (Exact match. Use '%' as wildcard.)</td>
</tr><tr>
<td>Maintainer:</td>
<td>
<input name="maintainer" type="text" value="<?=stripslashes(stripslashes($maintainer))?>" onChange="set_list_nomaintainer(this.value)">
<input name="nomaintainer" type="checkbox" onchange="list_unmaintained_packages(this.checked)"  <? if ($maintainer == "None") echo "checked";?>>
No maintainer
</td>
</tr>

<?

// We need to set a specific distribution if showing packages in "testing"
// Select the one with the highest priority
if ($tree == 'testing' && !$dist) {
  reset($dist_values);
  next($dist_values);
  $dist = key($dist_values);
}
?>

<tr>
<td>Distribution:</td>
<td><?genFormSelect("dist", $dist, $dist_values);?></td>
</tr><tr>
<td>Tree:</td>
<td><?genFormSelect("tree", $tree, $tree_values);?></td>
</tr><tr>
<td>Section:</td>
<td><?genFormSelect("section", $section, $section_values);?></td>
</tr><tr>
<td>Sort order:</td>
<td><?genFormSelect("sort", $sort, $sort_values);?></td>
</tr></table>

<input name="nochildren" type="checkbox" <? if ($nochildren == "on") echo "checked";?>>
Exclude packages with parent (includes most "-dev", "-shlibs", ... splitoffs)
<br>

<input name="noshlibsdev" type="checkbox" <? if ($noshlibsdev == "on") echo "checked";?>>
Exclude -shlibs, -dev, -bin, -common, -doc packages 
<br>

</div>

</form>


<?

if (!$nolist && !$invalid_param) {

//
// Build the big query string
//
$query = 
     "SELECT p.name, p.version, p.revision, p.descshort, r.rel_id ";
if ($dist && $tree) {
  // show pkg of specifc dist/tree
  if ($tree == 'testing') {
    $query .= ", CONCAT(p.version, '-', p.revision) AS version_unstable, ".
            "    CONCAT(sp.version, '-', sp.revision) AS version_stable ".
            "FROM `release` r, `package` p ".
            "LEFT OUTER JOIN (`package` sp, `release` sr)  ".
            "     ON (p.name = sp.name ".
            "         AND sp.rel_id = sr.rel_id ".
            "         AND sr.dist_id = $dist ".
            "         AND sr.type = 'stable') ".
            "   WHERE p.rel_id = r.rel_id ".
            "     AND r.dist_id = $dist ".
            "     AND r.type = 'unstable' ";
  } else {
    $query .= "FROM `package` p, `release` r ".
            "WHERE p.rel_id = r.rel_id".
            "  AND r.dist_id = $dist ".
            "  AND r.type = '$tree' ";
  }
} else if ($dist && !$tree) {
  // show latest pkg of specifc dist or tree
  $query .= "FROM `package` p, `release` r ".
            "WHERE p.rel_id = r.rel_id ".
            "  AND r.dist_id = $dist ".
            "  AND r.priority = (SELECT MAX(rX.priority) ".
            "    FROM `package` pX, ".
            "         `release` rX ".
            "    WHERE p.name = pX.name ".
            "      AND pX.rel_id = rX.rel_id ".
            "      AND rX.dist_id = $dist ".
            "    GROUP BY pX.name) ";
} else if (!$dist && $tree) {
  // show everything in a given tree, regardless of the dist
  // Note: This query is almost identical to the (!$dist && !$tree) one.
  // The only additions are the '$tree' specifiers.
  $query .= "FROM `package` p, `release` r, `distribution` d ".
            "WHERE p.rel_id = r.rel_id ".
            "  AND r.dist_id = d.dist_id ";
  if (!$showall)
    $query .= "AND d.visible='1' ";
  $query .= "  AND r.type = '$tree' ".
            "  AND d.priority = (SELECT MAX(dX.priority) ".
            "    FROM `package` pX, ".
            "         `release` rX, ".
            "         `distribution` dX ".
            "    WHERE p.name = pX.name ".
            "      AND pX.rel_id = rX.rel_id ".
            "      AND rX.dist_id = dX.dist_id ".
            "      AND rX.type = '$tree' ";
  if (!$showall)
    $query .= "    AND dX.visible='1' ";
  $query .= "    GROUP BY pX.name) ".
            "  AND r.priority = (SELECT MAX(rX.priority) ".
            "    FROM `package` pX, ".
            "         `release` rX ".
            "    WHERE p.name = pX.name ".
            "      AND pX.rel_id = rX.rel_id ".
            "      AND rX.dist_id = d.dist_id ".
            "      AND rX.type = '$tree' ".
            "    GROUP BY pX.name) ";
} else if (!$dist && !$tree) {
  // show latest if no specifc dist/tree
  $query .= "FROM `package` p, `release` r, `distribution` d ".
            "WHERE p.rel_id = r.rel_id ".
            "  AND r.dist_id = d.dist_id ";
  if (!$showall)
    $query .= "AND d.visible='1' ";
  $query .= "  AND d.priority = (SELECT MAX(dX.priority) ".
            "    FROM `package` pX, ".
            "         `release` rX, ".
            "         `distribution` dX ".
            "    WHERE p.name = pX.name ".
            "      AND pX.rel_id = rX.rel_id ".
            "      AND rX.dist_id = dX.dist_id ";
  if (!$showall)
    $query .= "    AND dX.visible='1' ";
  $query .= "    GROUP BY pX.name) ".
            "  AND r.priority = (SELECT MAX(rX.priority) ".
            "    FROM `package` pX, ".
            "         `release` rX ".
            "    WHERE p.name = pX.name ".
            "      AND pX.rel_id = rX.rel_id ".
            "      AND rX.dist_id = d.dist_id ".
            "    GROUP BY pX.name) ";
}

if ($nochildren == "on")
	$query .= "AND p.parentname IS NULL ";

if ($noshlibsdev == "on")
	$query .= "AND !(p.name REGEXP '.*-(dev|shlibs|bin|common|doc)$') ";

if ($maintainer != "")
	$query .= "AND p.maintainer LIKE '%$maintainer%' ";

if ($name != "")
	$query .= "AND p.name LIKE '$name' ";

if ($summary != "")
	$query .= "AND p.summary_index LIKE '%$summary%' ";

if ($section) {
	if ($section == "games") {
		$sectionquery = " (p.section='$section' OR p.parentname REGEXP 'kdegames3|kdetoys3') ";
	} else if ($section == "graphics") {
		$sectionquery = " (p.section='$section' OR p.parentname='kdegraphics3') ";
	} else if ($section == "sound") {
		$sectionquery = " (p.section='$section' OR p.parentname='kdemultimedia3') ";
	} else if ($section == "utils") {
		$sectionquery = " (p.section='$section' OR p.parentname='kdeutils3') ".
		                " AND (p.parentname IS NULL OR p.parentname != 'webmin') ";
	} else {
		$sectionquery = " p.section='$section' ";
	}
	$query .= "AND $sectionquery ";
}
if ($tree == 'testing')
	$query .= "HAVING version_stable IS NULL OR version_unstable != version_stable ";
$query .= "ORDER BY p.name $sort";

$time_sql_start = microtime_float();
$rs = cachedQuery($query, MEMCACHE_COMPRESSED);
#$rs = cachedQuery($query);
$time_sql_end = microtime_float();
if (0) {
  print '<p class="attention"><b>Error during db query (list packages):</b> '.mysql_error().'</p>';
} else {
  $count = count($rs);


// Maybe display an overview of the search settings used to obtain the results here?
// Many seach servics (e.g. Google) do this: While the search settings are initially
// still visible in the widgets on the page, the user may have altered them.
?>
<p>
Found <?=$count?> 
package<?=($count==1 ? '' : 's')?><?=($maintainer=='None' ? ' without maintainer' : '')?><?=($tree=='testing' ? ' that need testing' : '')?>:
</p>
<?
  if ($count > 0) {
?>
<table class="pdb" cellspacing="2" border="0">
<?
  if ($tree == 'testing') {
    print '<tr class="pdbHeading"><th>Name</th><th>Version in unstable</th><th>Version in stable</th><th>Description</th></tr>';
  } 
  elseif ($tree && $dist) {
    print '<tr class="pdbHeading"><th>Name</th><th>Version</th><th>Description</th></tr>';
  }
  else {
    print '<tr class="pdbHeading"><th>Name</th><th>Latest Version</th><th>Description</th></tr>';
  }
  foreach ($rs as $row) {
    print '<tr class="package">';
    if ($tree || $dist)
      $rel_id_str = '?rel_id='.$row["rel_id"].($showall ? '&showall=on' : '');
    else
      $rel_id_str = ($showall ? '?showall=on' : '');
    print '<td class="packageName"><a href="package.php/'.$row["name"].$rel_id_str.'">'.$row["name"].'</a></td>';
    if ($tree == 'testing') {
      print '<td>'.$row['version_unstable'].'</td>'.
            '<td>'.$row['version_stable'].'</td>';
    } else {
      print '<td class="packageName">'.$row['version'].'-'.$row['revision'].'</td>';
    }
    print '<td>'.$row['descshort']."</td></tr>\n";
  }
?>
</table>
<? } // no packages to list ?>
<p>Query took <? printf("%.2f", $time_sql_end - $time_sql_start); ?> sec</p>
<?
} // no sql error
} // if($nolist)
?>

<?
include "footer.inc";
?>
