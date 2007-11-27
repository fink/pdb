<?

require_once 'Zend/Search/Lucene.php';

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
