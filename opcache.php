<?php

/**
 * OPcache Status
 *
 * A one-page opcache status page for the PHP opcode cache.
 * https://github.com/wp-cloud/opcache-status
 *
 * @package OpCacheStatus
 * @version 0.3.0
 * @author WP-Cloud <code@wp-cloud.net>
 * @author Pedro Carvalho <p@goodomens.studio>
 * @copyright Copyright (c) 2016, WP-Cloud
 * @copyright Copyright (c) -2016, Rasmus Lerdorf
 * @license @todo
 */

define('THOUSAND_SEPARATOR',true);

if (!extension_loaded('Zend OPcache')) {
	echo '<div style="background-color: #F2DEDE; color: #B94A48; padding: 1em;">You do not have the Zend OPcache extension loaded, sample data is being shown instead.</div>';
	if(file_exists(stream_resolve_include_path('data-sample.php'))) {
		require 'data-sample.php';
	} else {
		die();
	}
}

class OpCacheDataModel
{
	private $_configuration;
	private $_status;
	private $_self_location;
	private $_d3Scripts = array();
	private $_inc_scripts = [
		'd3-3.0.1.min' => [ 
			'src' => 'https://cdnjs.cloudflare.com/ajax/libs/d3/3.0.1/d3.v3.min.js',
			'integrity' => 'sha512-RDZIrqTWd+SmpRhFvB7tk+lFXRQAajY9mHf3TRh4znTWL6PgjGqW2sIjLB0VYdThNmRatAjcYlz3bkU/YGo8Tw==',
			'crossorigin'=> 'anonymous',
			'referrerpolicy'=> 'no-referrer'
		],
		'jquery-1.11.0.min' => [ 
			'src' => 'https://cdnjs.cloudflare.com/ajax/libs/jquery/1.11.0/jquery.min.js',
			'integrity' => 'sha512-h9kKZlwV1xrIcr2LwAPZhjlkx+x62mNwuQK5PAu9d3D+JXMNlGx8akZbqpXvp0vA54rz+DrqYVrzUGDMhwKmwQ==',
			'crossorigin'=> 'anonymous',
			'referrerpolicy'=> 'no-referrer'
		]
	];

	public $version = '0.3.0';

	public function __construct()
	{
		$this->_configuration = opcache_get_configuration();
		$this->_status = opcache_get_status() ?: [];
		$this->_self_location = $_SERVER['DOCUMENT_URI'];
		$this->handlePost();
	}

	public function getPageTitle()
	{
		return 'PHP ' . phpversion() . " with OpCache {$this->_configuration['version']['version']}";
	}

	public function getStatusDataRows()
	{
		$rows = array();
		if( ! $this->has_scripts() ) {
			return "<tr><th>Status</th><td>off</td></tr>\n";
		}
		foreach ($this->_status as $key => $value) {
			if ($key === 'scripts') {
				continue;
			}

			if (is_array($value)) {
				foreach ($value as $k => $v) {
					if ($v === false) {
						$value = 'false';
					}
					if ($v === true) {
						$value = 'true';
					}
					if ($k === 'used_memory' || $k === 'free_memory' || $k === 'wasted_memory') {
						$v = $this->_size_for_humans(
							$v
						);
					}
					if ($k === 'current_wasted_percentage' || $k === 'opcache_hit_rate') {
						$v = number_format(
								$v,
								2
							) . '%';
					}
					if ($k === 'blacklist_miss_ratio') {
						$v = number_format($v, 2) . '%';
					}
					if ($k === 'start_time' || $k === 'last_restart_time') {
						$v = ($v ? date(DATE_RFC822, $v) : 'never');
					}
					if (THOUSAND_SEPARATOR === true && is_int($v)) {
						$v = number_format($v);
					}

					$rows[] = "<tr><th>$k</th><td>$v</td></tr>\n";
				}
				continue;
			}
			if ($value === false) {
				$value = 'false';
			}
			if ($value === true) {
				$value = 'true';
			}
			$rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
		}

		return implode("\n", $rows);
	}

	public function getConfigDataRows()
	{
		$rows = array();
		foreach ($this->_configuration['directives'] as $key => $value) {
			if ($value === false) {
				$value = 'false';
			}
			if ($value === true) {
				$value = 'true';
			}
			if ($key == 'opcache.memory_consumption') {
				$value = $this->_size_for_humans($value);
			}
			$rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
		}

		return implode("\n", $rows);
	}

	public function getScriptStatusRowsJs()
	{
		if ( ! $this->has_scripts() ) {
			return;
		}
		$dirs = array();
		foreach ($this->_status['scripts'] as $key => $data) {
			$dirs[dirname($key)][basename($key)] = $data;
			$this->_arrayPset($this->_d3Scripts, $key, array(
				'name' => basename($key),
				'size' => $data['memory_consumption'],
			));
		}

		asort($dirs);

		$basename = '';
		while (true) {
			if (count($this->_d3Scripts) !=1) break;
			$basename .= DIRECTORY_SEPARATOR . key($this->_d3Scripts);
			$this->_d3Scripts = reset($this->_d3Scripts);
		}

		$this->_d3Scripts = $this->_processPartition($this->_d3Scripts, $basename);

		$rows = [];
		foreach ($dirs as $dir => $files) {
			$row = [$dir, []];
			foreach ($files as $file => $data) {
				$row[1][] = [ $file, $data['hits'], $data['memory_consumption'] ];
			}
			$rows[] = $row;
		}
		return json_encode($rows);
	}

	public function getScriptStatusCount()
	{
		if ( ! $this->has_scripts() ) {
			return 0;
		}
		return count( $this->_status["scripts"] );
	}

	private function has_scripts() {
		if( ! isset( $this->_status["scripts"] ) || ! is_array( $this->_status["scripts"] ) ) {
			return false;
		}
		return count( $this->_status["scripts"] ) > 0;
	}

	public function getGraphDataSetJson()
	{
		$dataset = array();
		$dataset['memory'] = array(
			$this->_status['memory_usage']['used_memory'],
			$this->_status['memory_usage']['free_memory'],
			$this->_status['memory_usage']['wasted_memory'],
		);

		$dataset['keys'] = array(
			$this->_status['opcache_statistics']['num_cached_keys'],
			$this->_status['opcache_statistics']['max_cached_keys'] - $this->_status['opcache_statistics']['num_cached_keys'],
			0
		);

		$dataset['hits'] = array(
			$this->_status['opcache_statistics']['misses'],
			$this->_status['opcache_statistics']['hits'],
			0,
		);

		$dataset['restarts'] = array(
			$this->_status['opcache_statistics']['oom_restarts'],
			$this->_status['opcache_statistics']['manual_restarts'],
			$this->_status['opcache_statistics']['hash_restarts'],
		);

		if (THOUSAND_SEPARATOR === true) {
			$dataset['TSEP'] = 1;
		} else {
			$dataset['TSEP'] = 0;
		}

		return json_encode($dataset);
	}

	public function getHumanUsedMemory()
	{
		return $this->_size_for_humans($this->getUsedMemory());
	}

	public function getHumanFreeMemory()
	{
		return $this->_size_for_humans($this->getFreeMemory());
	}

	public function getHumanWastedMemory()
	{
		return $this->_size_for_humans($this->getWastedMemory());
	}

	public function getUsedMemory()
	{
		return $this->_status['memory_usage']['used_memory'];
	}

	public function getFreeMemory()
	{
		return $this->_status['memory_usage']['free_memory'];
	}

	public function getWastedMemory()
	{
		return $this->_status['memory_usage']['wasted_memory'];
	}

	public function getWastedMemoryPercentage()
	{
		return number_format($this->_status['memory_usage']['current_wasted_percentage'], 2);
	}

	public function getD3Scripts()
	{
		return $this->_d3Scripts;
	}
	
	public function secondsToTime($seconds_time)
	{
		$days = floor($seconds_time / 86400);
		if($days > 0) return $days . 'd ' . gmdate('H:i:s', $seconds_time - $days * 86400);
		return gmdate('H:i:s', $seconds_time);
	}

	public function getVersionAndUptime()
	{
		if ( function_exists('fpm_get_status') ) {
			$fpm_status = fpm_get_status();
			if( $fpm_status !== false && isset($fpm_status['start-since']) ) {
				#return print_r( $fpm_status, 1);
				return $this->version . ' / Uptime: ' . $this->secondsToTime( $fpm_status['start-since'] );
			}
		}

		return $this->version;
	}

	public function printScriptTags()
	{
		foreach( $this->_inc_scripts as $script => $external ) {
			$local_src = 'inc/' . $script . '.js';
			if(file_exists($local_src)) {
				echo "<script src='{$local_src}'></script>\n";
			} else {
				$ret = "<script";
				foreach( $external as $f => $v ) {
					$ret .= " {$f}='{$v}'";
				}
				$ret .= "></script>\n";
				echo $ret;
			}
		}
	}

	private function _processPartition($value, $name = null)
	{
		if (array_key_exists('size', $value)) {
			return $value;
		}

		$array = array('name' => $name,'children' => array());

		foreach ($value as $k => $v) {
			$array['children'][] = $this->_processPartition($v, $k);
		}

		return $array;
	}

	private function _format_value($value)
	{
		if (THOUSAND_SEPARATOR === true) {
			return number_format($value);
		} else {
			return $value;
		}
	}

	private function _size_for_humans($bytes)
	{
		if ($bytes > 1048576) {
			return sprintf('%.2f&nbsp;MB', $bytes / 1048576);
		} else {
			if ($bytes > 1024) {
				return sprintf('%.2f&nbsp;kB', $bytes / 1024);
			} else {
				return sprintf('%d&nbsp;bytes', $bytes);
			}
		}
	}

	// Borrowed from Laravel
	private function _arrayPset(&$array, $key, $value)
	{
		if (is_null($key)) return $array = $value;
		$keys = explode(DIRECTORY_SEPARATOR, ltrim($key, DIRECTORY_SEPARATOR));
		while (count($keys) > 1) {
			$key = array_shift($keys);
			if ( ! isset($array[$key]) || ! is_array($array[$key])) {
				$array[$key] = array();
			}
			$array =& $array[$key];
		}
		$array[array_shift($keys)] = $value;
		return $array;
	}

	private function flushCache() {
		if ( ! function_exists('opcache_reset') ) {
			return;
		}
		return (int) opcache_reset();
	}

	/**
	 * Check if flush was properly done and present it
	 */
	public function getFlushCacheStatus() {
		$status_msg = '(failed)';
		if ( ! isset( $_GET['flush_status'] ) ) {
			return '';
		}
		if ( $_GET['flush_status'] == 1) {
			$status_msg = '(success)';
		}
		printf( '<p class="clear_cache__status" title="Status of the flush done here.">%1$s</p>', $status_msg );
	}


	/**
	 * Get last flush and present in a human readble way
	 */
	public function getFlushTimeAgo() : string {

		$stats = opcache_get_status( false );
		if ( empty( $stats['opcache_statistics']['last_restart_time'] ) ){
			return '';
		}
		$timeago_dt = new \DateTime('@' . $stats['opcache_statistics']['last_restart_time'] );

		$now = new DateTime();
		$interval = $timeago_dt->diff( $now, true );
		$reset_ago = '<p class="clear_cache__timeago">Last reset was ';
		$reset_ago_end = '</p>';
		if ( $interval->y ) { return $reset_ago . $interval->y . ' years ago.' . $reset_ago_end; };
		if ( $interval->m ) { return $reset_ago . $interval->m . ' months ago.' . $reset_ago_end; };
		if ( $interval->d ) { return $reset_ago . $interval->d . ' days ago.' . $reset_ago_end; };
		if ( $interval->h ) { return $reset_ago . $interval->h . ' hours ago.' . $reset_ago_end; };
		if ( $interval->i ) { return $reset_ago . $interval->i . ' minutes ago.' . $reset_ago_end; };
		return $reset_ago . ' less than 1 minute ago.' . $reset_ago_end;
	}

	/**
	 * Flush OpCache and redicted when Action is set to flush.
	 */
	private function handlePost() {
		$action = isset($_POST['action']) ? $_POST['action'] : false;
		if ( !$action ) {
			return;
		}
		if ( $action === 'flush' ) {
			$flush_status = $this->flushCache();
			header( 'Location: ' . $this->_self_location . '?flush_status=' . $flush_status );
			die();
		} elseif ( $action === 'invalidate' ) {
			if( isset($_POST['path']) && function_exists('opcache_invalidate') ) {
				$status = opcache_invalidate( $_POST['path'], true );
			}
		}
	}
}

$dataModel = new OpCacheDataModel();
?>
<!DOCTYPE html>
<meta charset="utf-8">
<html>
<head>
	<style>
		:root {
			--color-background: #f9f9f9;
			--color-background-accent: #eee;
			--color-background-accent2: #ddd;
			--color-default: #000;
		}

		[data-theme="dark"] {
			--color-background: #202124;
			--color-background-accent: #303134;
			--color-background-accent2: #505164;
			--color-default: #eee;
		}

		body {
			font-family: "Helvetica Neue",Helvetica,Arial,sans-serif;
			margin: 0;
			padding: 0;
			background: var(--color-background);
			color: var(--color-default);
			empty-cells: show;
		}

		#container {
			width: 90%;
			margin: auto;
			position: relative;
		}

		h1 {
			padding: 10px 0;
		}

		table {
			border-collapse: collapse;
		}

		tbody tr:nth-child(even) {
			background: var(--color-background-accent);
		}

		p.capitalize {
			text-transform: capitalize;
		}

		.tabs {
			position: relative;
			float: left;
			width: -webkit-calc(100% - 420px);
			width:	-moz-calc(100% - 420px);
			width:		 calc(100% - 420px);
		}

		.graph {
			width: 400px;
		}

		.tab {
			float: left;
		}

		.tab label {
			background: var(--color-background-accent);
			padding: 10px 12px;
			border: 1px solid #ccc;
			margin-left: -1px;
			position: relative;
			left: 1px;
		}

		.tab [type=radio] {
			display: none;
		}

		.tab th, .tab td {
			padding: 8px 12px;
		}

		.content {
			position: absolute;
			top: 28px;
			left: 0;
			background: var(--color-background);
			border: 1px solid #ccc;
			height: 450px;
			width: 100%;
			overflow: auto;
		}

		.content table {
			width: 100%;
		}

		.content th, .tab:nth-child(3) td {
			text-align: left;
		}

		.content td {
			text-align: right;
		}

		.head_expanded {
			color: #aaa;
		}

		.clickable {
			cursor: pointer;
			background: var(--color-background-accent2);
		}

		.files_header {
			cursor: pointer;
			position:sticky;
			top:0;
			background: var(--color-background);
		}

		.invalidate {
			padding: 0px;
			cursor: pointer;
			background-image: url("data:image/png;base64,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");
			background-repeat: no-repeat;
		}

		[type=radio]:checked ~ label {
			background-color: var(--color-background);
			border-bottom: 1px solid white;
			z-index: 2;
		}

		[type=radio]:checked ~ label ~ .content {
			z-index: 1;
		}

		#graph {
			float: right;
			width: 40%;
			position: relative;
		}

		#graph > form {
			position: absolute;
			right: 60px;
			top: -20px;
		}

		#graph > svg {
			position: absolute;
			top: 0;
			right: 0;
		}

		#stats {
			position: absolute;
			right: 125px;
			top: 145px;
		}

		#stats th, #stats td {
			padding: 6px 10px;
			font-size: 0.8em;
		}

		#partition {
			position: absolute;
			width: 100%;
			height: 100%;
			z-index: 10;
			top: 0;
			left: 0;
			background: #ddd;
			display: none;
		}

		#close-partition {
			display: none;
			position: absolute;
			z-index: 20;
			right: 15px;
			top: 15px;
			background: #f9373d;
			color: #fff;
			padding: 12px 15px;
		}

		#close-partition:hover {
			background: #D32F33;
			cursor: pointer;
		}

		#partition rect {
			stroke: #fff;
			fill: #aaa;
			fill-opacity: 1;
		}

		#partition rect.parent {
			cursor: pointer;
			fill: steelblue;
		}

		#partition text {
			pointer-events: none;
		}

		label {
			cursor: pointer;
		}

		.actions {
			margin: 20px 0;
			padding: 10px;
			border: 1px solid #cacaca;
		}
		.clear_cache__status {
			font-weight: 100;
			text-decoration: underline dotted blue;
		}
		.clear_cache__timeago {
			font-weight: 100;
			font-style: italic;
		}

	</style>
	<?php echo $dataModel->printScriptTags(); ?>
	<script>
		var hidden = {};
		function toggleVisible(head, row) {
			var hide = hidden[row] = !hidden[row];
			d3.selectAll(head).classed('head_expanded', hide);
			d3.selectAll(row).transition().style('display', hide ? 'none' : null);
		}

		function invalidate(s) {
			$.post( window.location, {action: 'invalidate', path: s}, function( data ) {
				var newDoc = document.open("text/html", "replace");
				newDoc.write(data);
				newDoc.close();
			});
		}

		if(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
			document.documentElement.setAttribute("data-theme", "dark");
		}
	</script>
	<title><?php echo $dataModel->getPageTitle(); ?></title>
</head>

<body>
	<div id="container">
		<span style="float:right;font-size:small;">OPcache Status v<?php echo $dataModel->getVersionAndUptime(); ?></span>
		<h1><?php echo $dataModel->getPageTitle(); ?></h1>

		<div class="tabs">

			<div class="tab">
				<input type="radio" id="tab-status" name="tab-group-1" checked>
				<label for="tab-status">Status</label>
				<div class="content">
					<table>
						<?php echo $dataModel->getStatusDataRows(); ?>
					</table>
				</div>
			</div>

			<div class="tab">
				<input type="radio" id="tab-config" name="tab-group-1">
				<label for="tab-config">Configuration</label>
				<div class="content">
					<table>
						<?php echo $dataModel->getConfigDataRows(); ?>
					</table>
				</div>
			</div>

			<div class="tab" id="tab-files">
				<input type="radio" id="tab-scripts" name="tab-group-1">
				<label for="tab-scripts">Files</label>
				<div class="content">
					<table style="font-size:0.8em;">
						<thead class='files_header'><tr>
							<th onclick="renderFilesTab(this)" order="1" width="100"><nobr>Hits <span style="visibility:hidden">█</span></nobr></th>
							<th onclick="renderFilesTab(this)" order="2" width="100"><nobr>Memory <span style="visibility:hidden">█</span></nobr></th>
							<th onclick="renderFilesTab(this)" order="3" width="100%"><nobr>Path <span style="visibility:hidden">█</span></nobr></th>
							<th width="32">-</th>
						</tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>

			<div class="tab">
				<input type="radio" id="tab-visualise" name="tab-group-1">
				<label for="tab-visualise">Visualise Partition</label>
				<div class="content"></div>
			</div>

			<div class="tab">
				<input type="radio" id="tab-utilities" name="tab-group-1">
				<label for="tab-utilities">Utilities</label>
				<div class="content">
					<table>
						<tr>
							<th>Flush cache
								<?php echo $dataModel->getFlushCacheStatus(); ?>
								<?php echo $dataModel->getFlushTimeAgo(); ?>
							</th>
							<td>
								<form method="POST">
									<input type="hidden" name="action" value="flush" />
									<button name="submit" type="submit">Flush</button>
								</form>
							</td>
						</tr>
					</table>
				</div>
			</div>

		</div>

		<div id="graph">
			<form>
				<label><input type="radio" name="dataset" value="memory" checked> Memory</label>
				<label><input type="radio" name="dataset" value="keys"> Keys</label>
				<label><input type="radio" name="dataset" value="hits"> Hits</label>
				<label><input type="radio" name="dataset" value="restarts"> Restarts</label>
			</form>

			<div id="stats"></div>
		</div>
	</div>

	<div id="close-partition">&#10006; Close Visualisation</div>
	<div id="partition"></div>

	<script>
		var dirs = JSON.parse('<?php echo $dataModel->getScriptStatusRowsJs(); ?>');
		var dataset = <?php echo $dataModel->getGraphDataSetJson(); ?>;

		var width = 400,
			height = 400,
			radius = Math.min(width, height) / 2,
			colours = ['#B41F1F', '#1FB437', '#ff7f0e'];

		d3.scale.customColours = function() {
			return d3.scale.ordinal().range(colours);
		};

		var colour = d3.scale.customColours();
		var pie = d3.layout.pie().sort(null);

		var arc = d3.svg.arc().innerRadius(radius - 20).outerRadius(radius - 50);
		var svg = d3.select("#graph").append("svg")
					.attr("width", width)
					.attr("height", height)
					.append("g")
					.attr("transform", "translate(" + width / 2 + "," + height / 2 + ")");

		var path = svg.selectAll("path")
					  .data(pie(dataset.memory))
					  .enter().append("path")
					  .attr("fill", function(d, i) { return colour(i); })
					  .attr("d", arc)
					  .each(function(d) { this._current = d; }); // store the initial values

		d3.selectAll("input").on("change", change);
		set_text("memory");

		function set_text(t) {
			if (t === "memory") {
				d3.select("#stats").html(
					"<table><tr><th style='background:#B41F1F;'>Used</th><td><?php echo $dataModel->getHumanUsedMemory()?></td></tr>"+
					"<tr><th style='background:#1FB437;'>Free</th><td><?php echo $dataModel->getHumanFreeMemory()?></td></tr>"+
					"<tr><th style='background:#ff7f0e;' rowspan=\"2\">Wasted</th><td><?php echo $dataModel->getHumanWastedMemory()?></td></tr>"+
					"<tr><td><?php echo $dataModel->getWastedMemoryPercentage()?>%</td></tr></table>"
				);
			} else if (t === "keys") {
				d3.select("#stats").html(
					"<table><tr><th style='background:#B41F1F;'>Cached keys</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
					"<tr><th style='background:#1FB437;'>Free Keys</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
				);
			} else if (t === "hits") {
				d3.select("#stats").html(
					"<table><tr><th style='background:#B41F1F;'>Misses</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
					"<tr><th style='background:#1FB437;'>Cache Hits</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
				);
			} else if (t === "restarts") {
				d3.select("#stats").html(
					"<table><tr><th style='background:#B41F1F;'>Memory</th><td>"+dataset[t][0]+"</td></tr>"+
					"<tr><th style='background:#1FB437;'>Manual</th><td>"+dataset[t][1]+"</td></tr>"+
					"<tr><th style='background:#ff7f0e;'>Keys</th><td>"+dataset[t][2]+"</td></tr></table>"
				);
			}
		}

		function change() {
			// Skip if the value is undefined for some reason
			if (typeof dataset[this.value] !== 'undefined') {
				// Filter out any zero values to see if there is anything left
				var remove_zero_values = dataset[this.value].filter(function(value) {
					return value > 0;
				});
				if (remove_zero_values.length > 0) {
					$('#graph').find('> svg').show();
					path = path.data(pie(dataset[this.value])); // update the data
					path.transition().duration(750).attrTween("d", arcTween); // redraw the arcs
				// Hide the graph if we can't draw it correctly, not ideal but this works
				} else {
					$('#graph').find('> svg').hide();
				}

				set_text(this.value);
			}
		}

		function arcTween(a) {
			var i = d3.interpolate(this._current, a);
			this._current = i(0);
			return function(t) {
				return arc(i(t));
			};
		}

		function size_for_humans(bytes) {
			if (bytes > 1048576) {
				return (bytes/1048576).toFixed(2) + ' MB';
			} else if (bytes > 1024) {
				return (bytes/1024).toFixed(2) + ' KB';
			} else return bytes + ' bytes';
		}

		function format_value(value) {
			if (dataset["TSEP"] == 1) {
				return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
			} else {
				return value;
			}
		}

		var w = window.innerWidth,
			h = window.innerHeight,
			x = d3.scale.linear().range([0, w]),
			y = d3.scale.linear().range([0, h]);

		var vis = d3.select("#partition")
					.style("width", w + "px")
					.style("height", h + "px")
					.append("svg:svg")
					.attr("width", w)
					.attr("height", h);

		var partition = d3.layout.partition()
				.value(function(d) { return d.size; });

		root = JSON.parse('<?php echo json_encode($dataModel->getD3Scripts()); ?>');

		var g = vis.selectAll("g")
				   .data(partition.nodes(root))
				   .enter().append("svg:g")
				   .attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; })
				   .on("click", click);

		var kx = w / root.dx,
				ky = h / 1;

		g.append("svg:rect")
		 .attr("width", root.dy * kx)
		 .attr("height", function(d) { return d.dx * ky; })
		 .attr("class", function(d) { return d.children ? "parent" : "child"; });

		g.append("svg:text")
		 .attr("transform", transform)
		 .attr("dy", ".35em")
		 .style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; })
		 .text(function(d) { return d.name; })

		d3.select(window)
		  .on("click", function() { click(root); })

		function click(d) {
			if (!d.children) return;

			kx = (d.y ? w - 40 : w) / (1 - d.y);
			ky = h / d.dx;
			x.domain([d.y, 1]).range([d.y ? 40 : 0, w]);
			y.domain([d.x, d.x + d.dx]);

			var t = g.transition()
					 .duration(d3.event.altKey ? 7500 : 750)
					 .attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; });

			t.select("rect")
			 .attr("width", d.dy * kx)
			 .attr("height", function(d) { return d.dx * ky; });

			t.select("text")
			 .attr("transform", transform)
			 .style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; });

			d3.event.stopPropagation();
		}

		function transform(d) {
			return "translate(8," + d.dx * ky / 2 + ")";
		}

		function fmtSize(b) {
			if(b >= 1048576) return (b / 1048576).toFixed( 2 ) + " MB";
			if(b >= 1024) return (b / 1024).toFixed( 1 ) + " kB";
			return b + " bytes";
		}

		const files_tab = $('#tab-files');
		const files_table = files_tab.find('TABLE TBODY');
		const files_label = files_tab.find('LABEL');

		function __renderFilesGrp(files, dir = false, dirId = -1) {
			var tr = dirId === -1 ? '<tr>' : '<tr id="row-' + dirId + '">';
			for (var f of files) {
				var file = f[0], hits = f[1], size = f[2];
				var fullPath = dir ? dir + "/" + file : file;
				files_table.append($(tr)
						.append($('<td><nobr>' + hits.toLocaleString() + '</nobr></td>'))
						.append($('<td><nobr>' + fmtSize(size) + '</nobr></td>'))
						.append($('<td><nobr>' + (dirId === -1 ? fullPath : file) + '</nobr></td>'))
						.append($("<td class='invalidate' onclick=\"invalidate('" + fullPath + "')\"></td>"))
				);
			}
		}

		function renderFilesTab(e = null) {
			var OrderBy = null;
			if(e !== null) {
				var $e = $(e);
				var $eSPAN = $e.find("SPAN");
				var $eVis = $eSPAN.css('visibility') == 'visible';
				var OrderBy = parseInt( $e.attr('order') );

				$e.parent().find("[order]").each(function() {
					var $this = $(this);
					var $SPAN = $this.find("SPAN");
					var o = parseInt($this.attr('order'));
					if(o < 0) {
						o = -o;
						$this.attr('order', o)
					}
					$SPAN.css('visibility','hidden');
				});
				
				if($eVis && OrderBy > 0) {
					OrderBy = null; //reset sorting
				} else {
					var sign = 1;
					var signChar = "▼";
					var neg = -OrderBy;
					if(OrderBy < 0) {
						sign = -1;
						signChar = "▲";
						OrderBy = -OrderBy;
					}
					$eSPAN.text(signChar);
					$eSPAN.css('visibility','visible');
					$e.attr('order', neg);

					switch (OrderBy) {
						case 1:
							OrderBy = (a, b) => sign * (b[1] - a[1]);
							break;
						case 2:
							OrderBy = sortfn = (a, b) => sign * (b[2] - a[2]);
							break;
						case 3:
							OrderBy = (a, b) => sign * a[0].localeCompare(b[0]);
							break;
						default:
							OrderBy = null;
							break;
					}
				}
			}

			if(OrderBy === null) {
				var dirId = 0, result = 0;
			} else {
				var ordered = [];
			}

			files_table.html('');
			for (var e of dirs) {
				var dir_sz = 0;
				var dir = e[0];
				var cnt = e[1].length;

				if(OrderBy === null) {
					result += cnt;
					for (var f of e[1]) dir_sz += f[2];

					//var dir_grp = (cnt > 1) && (OrderBy === null);
					if(cnt > 1) {
						var th = "<th class='clickable' id='head-" + dirId + "' onclick=\"toggleVisible('#head-" + dirId + "', '#row-" + dirId + "')\"><nobr>";
						files_table.append($('<tr>')
								.append($(th + " [ " + cnt.toLocaleString() + " files ] " + '</nobr></th>'))
								.append($(th + fmtSize(dir_sz) + '</nobr></th>'))
								.append($(th + dir + '</nobr></th>'))
								.append($("<th class='invalidate' onclick=\"invalidate('" + dir + "/')\"></th>"))
						);
						__renderFilesGrp(e[1], dir, dirId++);
					} else {
						__renderFilesGrp(e[1], dir);
					}
				} else {
					for (var f of e[1]) {
						ordered.push( [ dir + "/" + f[0], f[1], f[2] ] );
					}
				}
			}

			if(OrderBy === null) return result;
			ordered.sort(OrderBy);
			__renderFilesGrp(ordered);
		}
		
		var sum_files = renderFilesTab();
		files_label.text( files_label.text() + " " + sum_files );

		$(document).ready(function() {
			function handleVisualisationToggle(close) {
				$('#partition, #close-partition').fadeToggle();

				// Is the visualisation being closed? If so show the status tab again
				if (close) {
					$('#tab-visualise').removeAttr('checked');
					$('#tab-status').trigger('click');
				}
			}

			$('label[for="tab-visualise"], #close-partition').on('click', function() {
				handleVisualisationToggle(($(this).attr('id') === 'close-partition'));
			});

			$(document).keyup(function(e) {
				if (e.keyCode == 27) handleVisualisationToggle(true);
			});
		});
	</script>
</body>
</html>
