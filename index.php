<?php 
/**
 * API service
 * 
 * @author Han Lin Yap
 * @copyright 2010 zencodez.net
 * @website http://www.zencodez.net/
 * @create-date 10th July 2010
 * @last-modified
 * @license http://creativecommons.org/licenses/by-sa/3.0/
 */
class Json_exception extends Exception {
	protected $error;
	
	public function __construct($message, $code) {
		$this->error = array('message' => $message, 'code' => $code);
		parent::__construct($this->error['message'], $this->error['code']);
	}
	
	public function __toString() {
		return json_return(array(
			'error' => $this->error['message'],
			'error_code' => $this->error['code'],
		));
	}
}

function csv_find($file, $searches, $delimiter=';', $custom_match=false, $fields=false, $limit=10, $offset=0) {
	if (!is_array($searches)) $searches = array($searches);
	
	if ($custom_match==false) {
		$custom_match = function($info, $search) {
			foreach ($info AS $column => $value) {
				if (stripos($value, $search)===0) {
					return true;
				}
			}
			return false;
		};
	}
	
	$rows_found = array();
	if (($handle = fopen($file, "r")) !== FALSE) {
		while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
			if ($offset>0) {
				$offset--;
				continue;
			}
			
			if ($fields) {
				$diff = array_diff_key($data, $fields);
				$data = array_diff_key($data, $diff);
			}
			foreach($searches AS $search) {
				if ($custom_match($data, $search)) {
					// found
					$rows_found[] = $data;
					$limit--;
					if ($limit==0) {
						break 2;
					} else {
						continue 2;
					}
				}
			}
		}
		fclose($handle);
	}
	
	return $rows_found;
}

function json_return($data) {
	if (isset($_REQUEST['callback'])) {
		return $_REQUEST['callback'] . '(' . json_encode($data) . ')';
	} else {
		return json_encode($data);
	}
}

function ip_to_int($ip) {
	if (!filter_var($ip, FILTER_VALIDATE_IP))
		return false;
	$ip = explode(".",$ip);
	return 16777216*$ip[0] + 65536*$ip[1] + 256*$ip[2] + $ip[3];
}

//echo $_SERVER['PATH_INFO'] . '?' . $_SERVER['QUERY_STRING'];

if (isset($_SERVER['PATH_INFO'])) {

	// Parse url
	$path_info = pathinfo($_SERVER['PATH_INFO']);
	$namespace = $path_info['dirname'];
	$method = $path_info['filename'];
	$format = $path_info['extension'];
	
	// Get params
	//$query_string = $_REQUEST;
	
	// Global parameters
	define(PARAM_LIMIT, (isset($_REQUEST['limit']) && is_numeric($_REQUEST['limit'])) ? $_REQUEST['limit'] : 10);
	define(PARAM_OFFSET, (isset($_REQUEST['offset']) && is_numeric($_REQUEST['offset'])) ? $_REQUEST['offset'] : 0);

	$api = array(
		'/geo' => array(
			'postal_code' => function($format) {
				if (required_params(array('city' => 'string')) ||
					required_params(array('number' => 'int'))) {
					
					$postal_codes = csv_find('Postnummer0904.csv', array($_REQUEST['city'], $_REQUEST['number']), ';', false, array(0,1), PARAM_LIMIT, PARAM_OFFSET);
					
					$data = array();
					foreach($postal_codes AS $postal_code) {					
						$data[] = array(
							'postal_code' => intval($postal_code[0]),
							'city' => trim($postal_code[1])
						);
					}
					
					if ($format=='json') {
						echo json_return(array(
							'data' => $data
						));
					}
				} else {
					throw new Json_exception("Parameter missing. 'city' or 'number' is required.",1);
				}
			},
			'ip' => function($format) {
				if (required_params(array('address' => 'string'))) {
					
					$ip_address = $_REQUEST['address'];
					$ip_addresses = array_map('ip_to_int', explode(",",$ip_address));
					
					/*
					// Read from csv
					if (max($ip_addresses) < 400000000) {
						$custom_match_blocks = function($info, $search) {
							if (!is_numeric($info[0])) return false;
							if (intval($info[0]) <= $search && intval($info[1]) >= $search) 
								return true;
							return false;
						};
						
						$custom_match_location = function($info, $search) {
							if (intval($info[0]) == intval($search[2]))
								return true;
							return false;
						};
					
						$blocks = csv_find('GeoLiteCity-Blocks.csv', $ip_addresses, ',', $custom_match_blocks, false, min(PARAM_LIMIT, count($ip_addresses)), PARAM_OFFSET);
						if (count($blocks) > 0) {
							$locations = csv_find('GeoLiteCity-Location.csv', $blocks, ',', $custom_match_location, false, min(PARAM_LIMIT, count($ip_addresses)));
							
						} else {
							$locations = array();
						}
					} else {
						// Read from database
						require_once("../connection.php");
						
						// Parse to SQL
						$ip_sql = array();
						foreach ($ip_addresses AS $v) {
							$ip_sql[] = " (" . $v . ">= startIpNum AND " . $v . " <= endIpNum) ";
						}
						
						$r = mysql_query("SELECT locId FROM geolitecity_block WHERE startIpNum >= 400000000 AND " . implode(' OR ', $ip_sql) . " LIMIT " . PARAM_OFFSET . ", " . min(PARAM_LIMIT, count($ip_addresses)));
						
						if (mysql_num_rows($r) > 0) {
							$loc = array();
							while ($row = mysql_fetch_assoc($r))
								$loc[] = $row['locId'];
								
							$r = mysql_query("SELECT * FROM geolitecity_location WHERE locid IN (" . implode(',', $loc) . ") LIMIT " . min(PARAM_LIMIT, count($ip_addresses)));
							
							while ($row = mysql_fetch_array($r))
								$locations[] = $row;
						} else {
							$locations = array();
						}
					}
					*/
					
					require_once("geoipcity.inc");
					require_once("geoipregionvars.php");
					
					$gi = geoip_open("GeoLiteCity.dat",GEOIP_STANDARD);
					
					foreach(explode(',',$_REQUEST['address']) AS $ip) {
						$record = geoip_record_by_addr($gi,$ip);
						$locations[] = array(
							1 => $record->country_code,
							3 => $record->city,
							5 => $record->latitude,
							6 => $record->longitude
						);
					}
					
					geoip_close($gi);
					
					$data = array();
					foreach($locations AS $location) {
						$data[] = array(
							'country_code' => $location[1],
							'city' => utf8_encode($location[3]),
							'latitude' => $location[5],
							'longitude' => $location[6],
						);
					}
					
					if ($format=='json') {
						echo json_return(array(
							'data' => $data
						));
					}
				} else {
					throw new Json_exception("Parameter missing. 'address' is required.",1);
				}
			}
		)
	);

	function required_params($params) {
		global $query_string;
		
		if (!is_array($params)) $params = array($params);
		
		foreach($params AS $param => $type) {			
			if (!array_key_exists($param, $_REQUEST) || strlen($_REQUEST[$param])==0) {
				return false;
			}
			
			if ($type == 'int' && !is_numeric($_REQUEST[$param]))
				throw new Json_exception("Wrong datatype. Parameter " . $param . " should be integer.",2);
		}
		return true;
	}

	if (array_key_exists($namespace, $api)) {
		if (array_key_exists($method, $api[$namespace])) {
			try {
				$api[$namespace][$method]($format);
			} catch (Json_exception $e) {
				echo $e;
			}
			die();
		}
	}
	header("HTTP/1.1 404 Not Found");
	echo "404 Page not found";
	die();
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8" />
<link rel="stylesheet" type="text/css" href="/yap-goodies/css/global.css" />
<link rel="stylesheet" type="text/css" href="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/ui-lightness/jquery-ui.css" />

<title>Zencodez API</title>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
<script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/jquery-ui.min.js"></script>
<!--[if IE]>
<script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
<![endif]-->
<link href="http://alexgorbatchev.com.s3.amazonaws.com/pub/sh/3.0.83/styles/shThemeDefault.css"  rel="stylesheet" type="text/css" />
<script src="http://alexgorbatchev.com.s3.amazonaws.com/pub/sh/3.0.83/scripts/shCore.js" type="text/javascript"></script>
<script src="http://alexgorbatchev.com.s3.amazonaws.com/pub/sh/3.0.83/scripts/shBrushJScript.js" type="text/javascript"></script>
<style>
body {
	font-size: 12px;
}

pre {
	background-color: #ffffff;
}
.example-live {
	background-color: #ffffff;
	min-height: 100px;
}
</style>
<script>
$(function () {
	SyntaxHighlighter.all();
	$(".namespace").accordion({ 
		autoHeight: false,
		clearStyle: true,
		collapsible: true,
		header: 'h2'
	});
	$(".method").accordion({ 
		autoHeight: false,
		clearStyle: true,
		collapsible: true,
		header: 'h3' 
	
	});
});
</script>
</head>
<body>
<div id="wrapper">
	<div id="wrapper-inner">
		<h1>Zencodez API</h1>
		<iframe src="http://www.facebook.com/plugins/like.php?href=http%3A%2F%2Fapi.zencodez.net&amp;layout=standard&amp;show_faces=false&amp;width=450&amp;action=like&amp;colorscheme=light&amp;height=35" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:450px; height:35px;" allowTransparency="true"></iframe>
		<div>
			<h2>Global parameters</h2>
			<p>Parameters that exists in every method.<br />
			<b>callback</b> (required)- only in json-format.<br />
			<!--<b>fields</b>: all (string) (optional) specify the fields you want out. "all" is special keyword.<br />-->
			<b>limit</b>: 10 (int) (optional) items to show, default is 10.<br />
			<b>offset</b>: 0 (int) (optional) items to skip, default is 0<br />
			<b>version</b>: 1 (int) (optional) latest will be used if it is not defined.<br />
			</p>
		</div>
		<div class="namespace">
			<!-- Namespace: geo -->
			<h2><a href="#">Namespace: geo</a></h2>
			<div class="method">
				<!-- Method name: postal_code -->
				<h3>Method name: postal_code</h3>
				<div>
					<h1>Description</h1>
					<p>Search for swedish postal code and get city name<br />
					<b>NOTE! Postal code data is from April 2009!</b>
					</p>
					<h1>Syntax</h1>
<pre>
URL: geo/postal_code.json?v=1&number=[int]&city=[string]
Namespace: geo
Method name: postal_code
Format: json

Params:
	city (string) (optional) city to search
	number (int) (optional) postal_code
	
Returns:
{
	"data" : [
		{
			"city" : "City",
			"number" : 12345 
		} 
	]
}

Errors:
	1	- Parameter missing. 'city' or 'number' is required.
	2	- Wrong datatype. Parameter X should be integer.
</pre>
					<h1>Example</h1>
					<div class="example">
		<?php ob_start(); ?>
<form>
	<label>Enter swedish postal code 
		<input type="input" id="postal_code" />
	</label>
	<div id="postal_code_message"></div>
</form>
<script>
(function () {
	var $postal_code = $('#postal_code');
	$postal_code.closest('form').submit(function () {
		$('#postal_code_message').text('Searching ...');
		var url = 'http://api.zencodez.net/geo/postal_code.json?v=1&number=' + $postal_code.val();
		$.getJSON(url + '&callback=?', postal_code_callback);
		return false;
	});
	
	function postal_code_callback(result) {
		if (result.error) {
			$('#postal_code_message').text(result.error);
		} else if (result.data.length > 0) {
			$('#postal_code_message').text('City: ' + result.data[0].city);
		} else {
			$('#postal_code_message').text('Postal code does not exist');
		}
	}
})();
</script>
			<?php 
			$content = ob_get_contents();
			ob_end_clean();
			echo '<pre class="brush: js">' . htmlentities($content) . '</pre>';
			echo '<h1>Demo</h1><div class="example-live">' . $content . '</div>';
			?>
					</div>
					</p>

				</div>
				
				<!-- Method name: postal_code -->
				<h3>Method name: ip</h3>
				<div>
					<h1>Description</h1>
					<p>Search for ip and get city name, country code, latitude and longitude<br />
					<b>NOTE! IP info in range 0.0.0.0-23.215.0.0 is from July 2010 and the rest is from May 2010!</b>
					</p>
					<h1>Syntax</h1>
<pre>
URL: geo/ip.json?v=1&address=[string]
Namespace: geo
Method name: ip
Format: json

Params:
	address (string) (required) ip to search, You can search multiple ip address by delimit with comma(,).
	
Returns:
{
	"data" : [
		{
			"city" : "City",
			"country_code" : "SE",		# Max 2 length
			"latitude" : "58.9853",
			"longitude" : "16.1759"
		} 
	]
}

Errors:
	1	- Parameter missing. 'address' is required.
</pre>
					<h1>Example</h1>
					<div class="example">
		<?php ob_start(); ?>
<form>
	<label>Enter IP-address
		<input type="input" id="ip" />
	</label>
	<div id="ip_message"></div>
</form>
<script>
(function () {
	var $ip = $('#ip');
	$ip.closest('form').submit(function () {
		$('#ip_message').text('Searching ...');
		var url = 'http://api.zencodez.net/geo/ip.json?v=1&address=' + $ip.val();
		$.getJSON(url + '&callback=?', ip_callback);
		return false;
	});
	
	function ip_callback(result) {
		if (result.error) {
			$('#ip_message').text(result.error);
		} else if (result.data.length > 0) {
			$('#ip_message').text('City: ' + result.data[0].city);
		} else {
			$('#ip_message').text('IP-address does not exist');
		}
	}
})();
</script>
			<?php 
			$content = ob_get_contents();
			ob_end_clean();
			echo '<pre class="brush: js">' . htmlentities($content) . '</pre>';
			echo '<h1>Demo</h1><div class="example-live">' . $content . '</div>';
			?>
					</div>
					</p>

				</div>
			</div>
			<?php if (isset($_REQUEST['debug'])) { ?>
			<h2><a href="#">Namespace: math</a></h2>
			<div class="method">
				<h3>Method name: regression</h3>
				<div>
					<p>Coming soon ...</p>
				</div>
			</div>
			<h2><a href="#">Namespace: metroroll</a></h2>
			<div class="method">
				<h3>Method name: count</h3>
				<div>
					<p>Räknare, dagräknare, online</p>
				</div>
				<h3>Method name: count_extended</h3>
				<div>
					<p>Stats</p>
				</div>
				<h3>Method name: metrobloggen</h3>
				<div>
					<p>månadsräknare</p>
				</div>
				<h3>Method name: playlist</h3>
				<div>
					<p>musicplayer</p>
				</div>
				<h3>Method name: referrer</h3>
				<div>
					<p>referrer</p>
				</div>
			</div>
			<?php } ?>
		</div><!-- namespace -->
		<footer>
			<a href="http://www.zencodez.net">Copyright © 2010 Han Lin Yap</a>
		</footer>
	</div><!-- wrapper-inner -->
</div><!-- wrapper -->
<?php if (!isset($_REQUEST['debug'])) { ?>
<script type="text/javascript">
var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
</script>
<script type="text/javascript">
var pageTracker = _gat._REQUESTTracker("UA-1944741-2");
pageTracker._initData();
pageTracker._trackPageview();
</script>
<?php } ?>
</body>
</html>