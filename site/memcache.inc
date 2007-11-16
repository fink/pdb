<?

$memcache = new Memcache();
$memcache->connect('localhost', 11211);

$memcache_debug = 0;

function cachedQuery($sql, $compress = 0) {
	global $dbh;
	global $memcache;
	global $memcache_debug;

	$key = md5($sql);

	if ($returnVals = $memcache->get($key)) {
		if ($memcache_debug) echo " <!-- cached($compress) $key: $sql --> ";
		return $returnVals;
	} else {
		if ($memcache_debug) echo " <!-- NOT cached($compress) $key: $sql --> ";

		$returnVals = array();
		$resultSet = mysql_query($sql, $dbh);

		while ($row = mysql_fetch_array($resultSet)) {
			array_push($returnVals, $row);
		}

		// 4 hour cache -- update-piecewise resets it anyways
		if ($memcache->set($key, $returnVals, $compress, (60 * 60 * 4))) {
			return $returnVals;
		} else {
			if ($memcache_debug) echo " <!-- unable to set $key($compress) --> ";
			return;
		}
	}

}

?>
