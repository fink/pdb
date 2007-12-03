#!/usr/bin/env php
<?php

include "web/pdb/releases.inc";

$print_releases = 0;

if ($argv[1] == "-r") {
	$print_releases = 1;
}

$stdout = fopen('php://output', 'w');

if (!$print_releases) {
	fputcsv($stdout, array('id', 'name', 'architecture', 'description', 'rcspath', 'priority', 'isactive', 'isvisible', 'issupported'));
	foreach ($distributions as $key => $value) {
		$distinfo = array($value->getId(), $value->getName(), $value->getArchitecture(), $value->getDescription(), $value->getRcsPath(), $value->getPriority(), $value->isActive(), $value->isVisible(), $value->isSupported());
		fputcsv($stdout, $distinfo);
	}
} else {
	fputcsv($stdout, array('id', 'distribution_id', 'type', 'version', 'priority', 'isactive'));
	foreach ($releases as $key => $value) {
		$distinfo = array($value->getId(), $value->getDistribution()->getId(), $value->getType(), $value->getVersion(), $value->getPriority(), $value->isActive());
		fputcsv($stdout, $distinfo);
	}
}

fclose($stdout);

?>
