#!/usr/bin/php
<?php
/* Copyright 2017 by Thundersun, Inc.
 * You may use it according to the terms of the accompanying license.
 * 
 * WHAT IS IT?
 * This script interacts with your EmonCMS server.  Among other things, it:
 * - pushes a random value at a specified interval, for testing live updates.
 * - bulk uploads a CSV including a PHP-comprehensible datetime and a number, in some column.
 * 
 * TODO:
 * - test the options (all testing thus far has been done with settings.php)
 * - more validations on the settings / parameters
 */

// Load some defaults.
include "settings.php";

// MAIN PROGRAM LOGIC
G::$settings->parse_args();

if( G::$settings->dumpRows ) 
	dump_columns();

if( G::$settings->flooper ) 
	flooper();

if( G::$settings->sendBulk 
||  G::$settings->printBulk ) 
	send_chunks();


// FUNCTIONS
function flooper() {
	// increments consumption by random integer 1 to 10 units.
	$i=0;
	$total = 0;
	$add = 0;
	while( true ) {
		$timestamp = time();
		if( $add++ > 10 ) $add = 0;
		$total += $add;
		$bulkData = '['.$timestamp.','.G::$settings->NodeNum.','.$total.']';
		sendPoint($total);
		//print "sending: ".$bulkData."\r\n";
		sleep(G::$settings->flooper);
		$i++;
		//if( $i > 1 ) break;
	}
}

function sendPoint($totalCons) {
	$emoncms_url =  G::$host->url."input/post.json";
	$emoncms_api = G::$host->api;
	$nodeNum = G::$settings->NodeNum;

	$time_now = time();
	$sendTo = $emoncms_url."?time=$time_now"."&apikey=".$emoncms_api."&node=".$nodeNum;
	$wholeUrl=$sendTo."&csv=".$totalCons;
	print $wholeUrl. "\n";

	$cur_opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FAILONERROR => true,
		CURLOPT_URL => $wholeUrl,
		CURLOPT_SAFE_UPLOAD => true];
	curl_setopt_array($ch = curl_init(), $cur_opts);
	
	if( isset(G::$host->uspw) )
		curl_setopt($ch, CURLOPT_USERPWD, G::$host->uspw);

	$response = curl_exec($ch);
	$rcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	print "($rcode)---"; 
	print $response;
	print "===\r\n";
}

// BULK HANDLING FUNCTIONS
function dump_columns() {
	$file = fopen(G::$settings->InputFile, 'r');
	$chunkarr = get_chunk( $file, G::$settings->maxRows );
	
	echo "Time string, epoch time, data value\n";
	array_map(function ($x) {
		echo $x[0], "," , $x[1], ", ", $x[2], "\n";
	}, $chunkarr);
	fclose($file);
}

function send_chunks() {
	$file = fopen(G::$settings->InputFile, 'r');
	$totrows = 0;
	while( !feof($file) && $totrows < G::$settings->maxRows ) {
		$getrows = min( [ G::$settings->maxRows - $totrows, G::$settings->chunkSize ] );
		//print( "get rows: $getrows\n");
		$chunkarr = get_chunk( $file, $getrows );
		$totrows += sizeof( $chunkarr ) ;
		if( sizeof( $chunkarr ) == 0 ) break;
		
		send_one_chunk($chunkarr);
	}
	fclose($file);
}

function get_chunk($infile, $max_rows) {
	// returns an array comprising at most $max_rows of input data
	$chunkarr = array();
	
	for( $i=0; $i < $max_rows; $i++ ) {
		$line = fgets($infile);
		//print( "line: $line \n");
		if( $line == FALSE ) break;
		$line = trim($line);
		$expl = explode(',', $line);
		if( sizeof($expl) < 2 || strlen($line) == 0 ) {
			print('bad line (' . strlen($line) . "/" . sizeof($expl) . '): ' . $line . "\n");
			continue;
		}
		$timestr = $expl[G::$settings->TimeCol];
		$chunkarr[$i][0] = $timestr;
		$timestr = trim( $timestr, "'" );
		$timestr .= " GMT";
		$epoch = strtotime( $timestr );
		$chunkarr[$i][1] = $epoch;
		//echo "$timestr : $epoch\n";
		$chunkarr[$i][2] = $expl[G::$settings->DataCol];
	}
	return $chunkarr;
}

function build_chunk($chunkarr) {
	// returns complete data string
	$nodeNum = G::$settings->NodeNum;  
	$datastr = "[";
	foreach( $chunkarr as $chunkrow ) {
		$epochtime = $chunkrow[1];
		$dataval = $chunkrow[2];
		$datastr .= "[ $epochtime, $nodeNum, $dataval ],\n";
		//print ".$epochtime\n";
	}
	$datastr = rtrim($datastr, ",\n");
	$datastr .= "  ]\n";
	return $datastr;
}

function send_one_chunk($chunkarr) {
  	$emoncms_url =  G::$host->url."input/bulk.json";
	$emoncms_api = G::$host->api;

	$time_now = time();
	$sendTo = $emoncms_url."?sentat=$time_now"."&apikey=".$emoncms_api;

	$data = build_chunk($chunkarr); 
	
	if( G::$settings->sendBulk ) {
		curl_setopt_array($ch = curl_init(), array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_URL => $sendTo,
			CURLOPT_POSTFIELDS => array(
			  "data" => $data,
			),
			CURLOPT_SAFE_UPLOAD => true,
			));
		$response = curl_exec($ch);
		curl_close($ch);
	} else {
		$response = "server not contacted";
	}
	
	if( G::$settings->verbose  
	|| G::$settings->printBulk ) {
		print $sendTo. "&\n";
		print "data=" . $data;

		print "\r\n----"; 
		print $response;
		print "----\r\n";
	}

	sleep(1);
}


// CLASSES
class G {
	// Holder for global statics (for concise global access)
	public static $settings;
	public static $host;
}

class Settings {
	public function __construct($row){
	 foreach ($row as $prop=>$value){
		if( property_exists(get_class(), $prop) ) {
			$this->$prop=$value; 
		} else {
			print("Bad property: ".$prop."\n");
			print_r( debug_backtrace() );
		} } }	

	// SETTINGS
	public $InputFile;  // CSV file to parse.
	public $chunkSize;  // How many data points to send at once
	public $Serial; 	// Acquisition device
	public $SubDevice;	// Acquisition sub-device	  
	public $TimeCol; 	// Column offset to date-time.
	public $DataCol; 	// Column offset to meter data.
	public $NodeNum;
	public $maxRows;  	// Ignore input file beyond this many rows.
	public $verbose;

	// ACTION FLAGS (only one of these can be set!)
	public $dumpRows;  	// Dump this many rows on the console and exit. [debugging]
	public $flooper;	// Every $flooper seconds, uploads a random value 1-10.
	public $sendBulk;   // Upload bulk data
	public $printBulk; 	// Show what would have been sent, if this were a real send.
	
	function parse_args() {
		global $argv;

		$opts = getopt('hdf:spvc:r:e:t:d:n:m:', [], $optind);
		$pos_args = array_slice($argv, $optind);

		// Help.
		if( isset($opts["h"]) ) {
			echo("Usages: \n
emoncsv.php -h
emoncsv.php [options] FILENAME.CSV\n
Actions:
  -d - dump rows
  -f - send random consumption
  -s - send bulk data to server
  -p - print bulk data that would be send to server

Flags:
  -v - extra console output, sometimes.
  
Settings:
  -cN - upload no more than N rows at a time
  -rX - set data source's serial number to X (does nothing at present) 
  -eX - set data source subdevice to X (does nothing at present) 
  -tN - extract time data from human-readable date string in column N
  -dN - extract upload data from numeric value in column N
  -nN - upload to EmonCMS node number N
  -mN - stop processing after N input rows
"); 
			exit();
		}

		// Actions
		if( isset($opts["d"]) ) G::$settings->dumpRows = TRUE;
		if( isset($opts["f"]) ) G::$settings->flooper = $opts["f"];
		if( isset($opts["s"]) ) G::$settings->sendBulk = TRUE;
		if( isset($opts["p"]) ) G::$settings->printBulk = TRUE;

		// Flags
		if( isset($opts["v"]) ) G::$settings->verbose = $opts["v"];

		// Settings
		//if( isset($opts["I"]) ) G::$settings->InputFile;
		if( isset($opts["c"]) ) G::$settings->chunkSize = $opts["c"];
		if( isset($opts["r"]) ) G::$settings->Serial 	= $opts["r"];
		if( isset($opts["e"]) ) G::$settings->SubDevice = $opts["e"];
		if( isset($opts["t"]) ) G::$settings->TimeCol = $opts["t"];
		if( isset($opts["d"]) ) G::$settings->DataCol = $opts["d"];
		if( isset($opts["n"]) ) G::$settings->NodeNum = $opts["n"];
		if( isset($opts["m"]) ) G::$settings->maxRows = $opts["m"];

		// File.
		if( isset($pos_args[0]) ) G::$settings->InputFile = $pos_args[0];

		// Misc settings
		if( G::$settings->maxRows == 0 ) 
			G::$settings->maxRows = PHP_INT_MAX;
		
		// Validate only one action.
		$a = 0;
		if( G::$settings->dumpRows ) $a++;
		if( G::$settings->flooper ) $a++;
		if( G::$settings->sendBulk ) $a++;
		if( G::$settings->printBulk ) $a++;
		
		if( $a == 0 )  G::$settings->sendBulk = TRUE;
		if( $a > 1 ) {
			print("Error: Specified more than one action.\n");
			print_r(G::$settings);
			exit();
		}
	}
}

class Host {
	public function __construct($row){
	 foreach ($row as $prop=>$value){
		if( property_exists(get_class(), $prop) ) {
			$this->$prop=$value;
		} else {
			print("Bad property: ".$prop."\n");
			print_r( debug_backtrace() );
		} } }	
	
	public $url;
	public $api;
	public $uspw;
}

?>
