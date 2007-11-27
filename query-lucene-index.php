#!/usr/bin/env php -q
<?php

ini_set("include_path", "php-lib");

require_once 'Zend/Search/Lucene.php';
require_once 'File_Find/Find.php';
require_once 'releases.inc';

Zend_Search_Lucene::setDefaultSearchField(null);
$index = new Zend_Search_Lucene("lucene-index");

array_shift($argv);
$query = join(" ", $argv);

$hits = $index->find($query);

echo "Search for '$query' returned " . count($hits) . " hits\n\n";

foreach ($hits as $hit) {
	print $hit->name . " (" . $hit->doc_id . ")\n";
	printf("\tScore: %.2f\n", $hit->score);
}

?>
