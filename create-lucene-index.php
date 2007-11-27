#!/usr/bin/env php -q
<?php

ini_set("include_path", "php-lib");

require_once 'Zend/Search/Lucene.php';
require_once 'File_Find/Find.php';
require_once 'releases.inc';

$temp_package = array();
$temp_tag     = "";

array_shift($argv);
$xmlpath = $argv[0];
array_shift($argv);

if (!is_dir($xmlpath)) {
	print "$xmlpath is not a directory!";
	exit(1);
}

if (is_dir("lucene-index")) {
	$index = new Zend_Search_Lucene("lucene-index");
} else {
	$index = new Zend_Search_Lucene("lucene-index", true);
}

if ($dh = opendir($xmlpath)) {
	while(($entry = readdir($dh)) !== false) {
		if ($entry == "." || $entry == "..") { continue; }
		if (is_dir($xmlpath . '/' . $entry)) {
			index_dir($xmlpath . '/' . $entry);
		}
	}
}

closedir($dh);

$index->optimize();

echo $index->count() . " documents indexed.\n";

function index_dir($dir) {
	global $index;
	global $xmlpath;
	global $releases;
	global $temp_package;

	print "- indexing directory $dir\n";

	$finder = new File_Find();
	$tree = $finder->mapTree($dir);

	foreach ($tree[1] as $file) {
		$xmlfile = str_replace($xmlpath . '/', '', $file);
		$split = split('/', $xmlfile);
		$release = $split[0];
		$package = $split[1];
		$package = str_replace(".xml", '', $package);
	
		$temp_package = array();
	
		$xml_parser = xml_parser_create();
		xml_parser_set_option($xml_parser, XML_OPTION_CASE_FOLDING, true);
		xml_set_element_handler($xml_parser, "startElement", "endElement");
		xml_set_character_data_handler($xml_parser, "characterData");
		xml_set_default_handler($xml_parser, "characterData");
	
		if (!($fp = fopen($file, 'r'))) {
			die("could not open XML file $file!");
		}
	
		while ($data = fread($fp, 4096)) {
			if (!xml_parse($xml_parser, $data, feof($fp))) {
				die(sprintf("XML error: %s at line %d", xml_error_string(xml_get_error_code($xml_parser)), xml_get_current_line_number($xml_parser)));
			}
		}
	
		xml_parser_free($xml_parser);
	
		$temp_package['pkg_id'] = $temp_package['id'];
		unset($temp_package['id']);
	
		$release      = $releases[$temp_package['rel_id']];
		$temp_package['dist_name']         = $release->getDistribution()->getName();
		$temp_package['dist_architecture'] = $release->getDistribution()->getArchitecture();
		$temp_package['dist_description']  = $release->getDistribution()->getDescription();
		$temp_package['dist_active']       = $release->getDistribution()->isActive();
		$temp_package['dist_visible']      = $release->getDistribution()->isVisible();
		$temp_package['dist_supported']    = $release->getDistribution()->isSupported();
	
		$temp_package['rel_type']          = $release->getType();
		$temp_package['rel_version']       = $release->getVersion();
		$temp_package['rel_priority']      = $release->getPriority();
		$temp_package['rel_active']        = $release->isActive();
	
		$temp_package['doc_id'] = $temp_package['rel_id'] . '/' . $temp_package['pkg_id'];
	
		remove_document($temp_package['doc_id']);
		add_document($temp_package);
	}
	$index->commit();
}

function add_document($package) {
	global $index;
	print "- adding package " . $package['doc_id'] . "\n";

	$doc = new Zend_Search_Lucene_Document();

	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'doc_id',                   $package['doc_id']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'pkg_id',                   $package['pkg_id']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'dist_id',                  $package['dist_id']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rel_id',                   $package['rel_id']));

	$doc->addField(Zend_Search_Lucene_Field::Text(     'name',                     $package['name']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'version',                  $package['version']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'revision',                 $package['revision']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'epoch',                    $package['epoch']));

	$doc->addField(Zend_Search_Lucene_Field::Text(     'descshort',                $package['descshort']));
	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'desclong',                 $package['desclong']));
	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'descusage',                $package['descusage']));

	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'homepage',                 $package['homepage']));
	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'license',                  $package['license']));
	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'maintainer',               $package['maintainer']));

	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'section',                  $package['section']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rcspath',                  $package['rcspath']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'tag',                      $package['tag']));
	$doc->addField(Zend_Search_Lucene_Field::Text(     'parentname',               $package['parentname']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'infofile',                 $package['infofile']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'infofilechanged',          $package['infofilechanged']));

	$doc->addField(Zend_Search_Lucene_Field::Text(     'dist_name',                $package['dist_name']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'dist_architecture',        $package['dist_architecture']));
	$doc->addField(Zend_Search_Lucene_Field::UnStored( 'dist_description',         $package['dist_description']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'dist_active',              $package['dist_active']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'dist_visible',             $package['dist_visible']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'dist_supported',           $package['dist_supported']));

	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rel_type',                 $package['rel_type']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rel_version',              $package['r_version']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rel_priority',             $package['r_priority']));
	$doc->addField(Zend_Search_Lucene_Field::Keyword(  'rel_active',               $package['r_active']));

	$index->addDocument($doc);
}

function remove_document($doc_id) {
	global $index;
	$hits = $index->find('doc_id:' . $doc_id);
	foreach ($hits as $hit) {
		print "- deleting package ID $doc_id(" . $hit->id . ")\n";
		$index->delete($hit->id);
	}
}

function startElement($parser, $name, $attrs) {
	global $temp_tag;

	if ($name != "infofile") {
		$temp_tag = strtolower($name);
	}
}

function endElement($parser, $name) {
	global $temp_tag;
	$temp_tag = "";
}

function characterData($parser, $data) {
	global $temp_package;
	global $temp_tag;

	if (!preg_match('/^[\s\r\n]*$/si', $data) ) {
		$temp_package[$temp_tag] .= $data;
	}
}

?>
