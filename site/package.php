<?php
$title = "Package Database - Package ";
$cvs_author = '$Author: rangerrick $';
$cvs_date = '$Date: 2007/11/30 22:11:04 $';

$uses_pathinfo = 1;
include "header.inc";
include "memcache.inc";
include "functions.inc";
include "../php-lib/releases.inc";
$package = $pispec;

// Get url parameters
list($version, $inv_p) = get_safe_param('version', '/^[0-9\-.:]+$/');
list($distribution, $inv_p) = get_safe_param('distribution', '/^[a-z0-9\-.]+$/');
list($release, $inv_p) = get_safe_param('release', '/^[0-9.]{3,}$|^unstable$|^stable$/');
list($architecture, $inv_p) = get_safe_param('architecture', '/^powerpc$|^i386$/');
list($rel_id, $inv_p) = get_safe_param('rel_id', '/^[[:alnum:]\-\_\.\:]+$/');
list($showall, $inv_p) = get_safe_param('showall', '/^on$/');
list($doc_id, $inv_p) = get_safe_param('doc_id', '/^[[:alnum:]\-\_\.\:]+$/');

$basicQuery = new SolrQuery();

$basicQuery->addSort("dist_priority desc");
$basicQuery->addSort("rel_priority desc");

$basicQuery->addQuery("name_e:\"$package\"", true);
if (!$showall) {
	$basicQuery->addQuery("dist_visible:true", true);
}

$fullQuery = clone $basicQuery;

if ($version) {
	list($epoch, $version, $revision) = parse_version($version);
	if ($epoch != null)
		$fullQuery->addQuery("epoch:$epoch", true);
	if ($version != null)
		$fullQuery->addQuery("version_e:$version", true);
	if ($revision != null)
		$fullQuery->addQuery("revision_e:$revision", true);
}

if ($doc_id) {
	$fullQuery->addQuery("doc_id:\"$doc_id\"", true);
} elseif ($rel_id) {
	$fullQuery->addQuery("rel_id:\"$rel_id\"", true);
} else { // no need to parse the other parameters
	if ($distribution) {
		$fullQuery->addQuery("dist_name:\"$distribution\"", true);
	}
	if ($release) {
		if ($release == 'unstable' || $release == 'stable') {
			$fullQuery->addQuery("rel_type:$release", true);
		} else {
			$fullQuery->addQuery("rel_version:$release", true);
		}
	}
	if ($architecture) {
		$fullQuery->addQuery("dist_architecture:$architecture", true);
	}
}

$result = $fullQuery->fetch();

// print_r($result);
// exit(0);

$warning = '';
if ($result == null || $result->response->numFound == 0) { # No specific version found, try latest
	$result = $basicQuery->fetch();
	error_reporting($error_level);
	$warning = "<b>Warning: Package $package $version not found";
	$warning .= $distribution ? " in distribution $distribution" : '';
	$warning .= $architecture ? "-$architecture" : '';
	$warning .= $release ? "-$release" : '';
	$warning .= $rel_id ? " for selected release" : '';
	$warning .= "!</b>";
}

if ($result == null || $result->response->numFound == 0) { # No package found
?>
<p><b>Package '<?=$package?>' not found in Fink!</b></p>
<?
} else {

	$pobj = array_shift($result->response->docs);
	$fullversion = $pobj->version . '-' . $pobj->revision;
	if ($pobj->epoch > 0) {
		$fullversion = $pobj->epoch . ':' . $fullversion;
	}

?>
<h1>Package <? print $pobj->name . '-' . $fullversion ?></h1>
<?

	function avail_td($text='', $extras='', $extra_div_style='') {
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
	
	function version_tags($p) {
		global $showall;
		global $pobj;

		if ($p->version == $pobj->version && $p->rel_id == $pobj->rel_id) {
			$open_tag = '<b>';
			$close_tag = '</b>';
		} else {
			$open_tag = "<a href=\"$package?doc_id=" . $p->doc_id;
			if ($showall)
				$open_tag .= "&showall=on";
			$open_tag .= '">';
			$close_tag = '</a>';
		}
		return array ( $open_tag, $close_tag );
	}

	/*
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
	*/
	
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

	$color_count = 0;
	$last_identifier = '';
	$has_unsupported_dists = false;

	global $distributions;
	global $releases;
	global $dists_to_releases;

	foreach ($distributions as $dist) {
		$dist_id = $dist->getId();

		if ($last_dist_id != $dist_id)
			$color_count++;

		if ($color_count == 1) {
			$row_color='bgcolor="#e3caff"';
		} else if ($color_count == 2) {
			$row_color='bgcolor="#f1e2ff"';
		} else {
			$row_color='bgcolor="#f6ecff"';
		}

		if (!$showall && !$dist->isVisible())
			continue;

		print "<tr $row_color>";

		if ($dist->isSupported()) {
			avail_td(nl2br($dist->getDescription()));
			$has_unsupported_dists = true;
		} else {
			avail_td(nl2br($dist->getDescription() . ' *'), '', 'color:gray; ');
		}

		foreach(array("bindist", "stable", "unstable") as $rel_type) {
			$pack = fetch_package($package, null, $dist->getName(), $rel_type, $dist->getArchitecture(), $showall);
			if ($pack == null) {
				avail_td("<i>no $rel_type distribution</i>");
			} else {
				if (is_array($pack)) {
					$pack = $pack[0];
				}
				list($open_tag, $close_tag) = version_tags($pack);
				avail_td($open_tag . $pack->pkg_id . $close_tag . ' (bindist ' . $pack->rel_version . ')');
			}
		}

		continue;

		// bindist
		if (isset($dists_to_releases[$dist_id]['bindist'])) {
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

		$last_dist_id = $dist_id;
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
