<?php

$memcache = new Memcache;
$memcache->connect('localhost', 11211) or die ("Could not connect to the memcache daemon");
$debug = true;

function parse_version($version) {
	$epoch = null;
	$revision = null;

	if (strrpos($version, ':')) {
		list($epoch, $version) = split(":", $version);
	}
	if (strrpos($version, "-")) {
		list($version, $revision) = split("-", $version, 2);
	}

	return array($epoch, $version, $revision);
}

function fetch_package($name, $version = null, $distribution = null, $release = null, $architecture = null, $showall = false) {
	$query = new SolrQuery();

	$query->addQuery("name_e:\"$name\"", true);
	if ($version != null)
		$query->addQuery("version_e:\"$version\"", true);
	if ($distribution != null)
		$query->addQuery("dist_name:\"$distribution\"", true);
	if ($release != null)
		$query->addQuery("rel_type:\"$release\"", true);
	if ($architecture != null)
		$query->addQuery("dist_architecture:\"$architecture\"", true);
	if (!$showall)
		$query->addQuery("dist_visible:true", true);

	$response = $query->fetch();
	if ($response == null || $response == false) {
		return null;
	} elseif ($response->response->numFound == 0) {
		return null;
	} elseif ($response->response->numFound == 1) {
		return $response->response->docs[0];
	} else {
		return $response->response->docs;
	}
}

class SolrQuery {
	protected $action;
	protected $url;
	protected $type;
	protected $timeout = 3600; // 1 hour

	protected $fields = array();
	protected $sort   = array();
	protected $query  = array();

	public function __construct($action = 'select', $url = 'http://localhost:8983/solr', $type = 'json') {
		$this->setAction($action);
		$this->setUrl($url);
		$this->setType($type);
	}

	public function getAction()          { return $this->action; }
	public function setAction($action)   { $this->action = $action; }
	public function getUrl()             { return $this->url; }
	public function setUrl($url)         { $this->url = $url; }
	public function getType()            { return $this->type; }
	public function setType($type)       { $this->type = $type; }

	public function getTimeout()         { return $this->timeout; }
	public function setTimeout($seconds) { $this->timeout = $timeout; }
	public function getFields()          { return $this->fields; }
	public function setFields($fields)   { $this->fields = $fields; }
	public function addField($field)     { array_push($this->fields, $field); }

	public function getSort()            { return $this->sort; }
	public function setSort($sort)       { $this->sort = $sort; }
	public function addSort($sort)       { array_push($this->sort, $sort); }

	public function getQuery()           { return $this->query; }
	public function setQuery($query)     { $this->query = $query; }

	public function addQuery($query, $required = false, $exclude = false) {
		if ($required) {
			array_push($this->query, '+' . $query);
		} elseif ($exclude) {
			array_push($this->query, '-' . $query);
		} else {
			array_push($this->query, $query);
		}
	}

	public function fetch() {
		$key = "solr-query-" . $this->getAction() . "-" . md5("" . $this);
		global $memcache;
		global $debug;

		$result = null;

		if ($memcache && !$debug) {
			$result = $memcache->get($key);
		}

		if ($result == null || $result == false) {
			$result = $this->fetch_uncached();
			if ($memcache) {
				$memcache->set($key, $result, false, $this->getTimeout());
			}
//		} else {
//			print "result is cached ($key)<br />\n";
		}

//		print "<!-- result = ";
//		print_r($result);
//		print " -->\n";
		return $result;
	}

	public function fetch_uncached() {
		if ($this->getType() != "json") {
			throw new Exception("This class only knows how to handle JSON for now");
		}

		$result = null;
		$get_result = file_get_contents($this);
		if ($this->getType() == "json") {
			$result = json_decode($get_result);
		}

		return $result;
	}

	public function __toString() {
		$returnval = $this->url . "/" . $this->action . "?wt=" . $this->type;

		if (count($this->getSort()) > 0) {
			$returnval .= "&sort=" . urlencode(join(",", $this->getSort()));
		}

		if (count($this->getFields()) > 0) {
			$returnval .= "&fl=" . urlencode(join(",", $this->getFields()));
		} else {
			$returnval .= "&fl=*";
		}

		$returnval .= "&q=" . urlencode(join(" ", $this->getQuery()));

		return $returnval;
	}
}

?>
