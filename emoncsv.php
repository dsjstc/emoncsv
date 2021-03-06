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

// Settings.php creates the following global static members:
//G::$host = new Host();
//G::$settings = new Settings();
include "settings.php";

// MAIN PROGRAM LOGIC
Settings::parse_and_validate();

if( G::$settings->dumpSettings ) {
	// Must be here; the switch doesn't process in order.
	print("Dump settings requested, exiting.\n");
	print_r(G::$settings); 
	exit();
}

switch(true) {
case G::$settings->printHelp:
	G::$settings->print_help();
	exit;
	
case G::$settings->dumpRows:
	dump_columns();
	break;

case G::$settings->flooper:
	flooper();
	break;

case G::$settings->sendBulk
||  G::$settings->printBulk:
	send_chunks();
	break;
	
case G::$settings->createInput:
	createInput();
	break;
}

// FUNCTIONS
function createInput() {
	// Make a post like this one:
	// http://em/emoncms/input/post.json?time=0&node=1&csv=0
	sendPoint(0,G::$settings->NodeNum,0);
}

function flooper() {
	// increments consumption by random integer 1 to 10 units.
	$i=0;
	$total = 0;
	$add = 0;
	while( $i < G::$settings->maxRows ) {
		$timestamp = time();
		if( $add++ > 10 ) $add = 0;
		$total += $add;
		$bulkData = '['.$timestamp.','.G::$settings->NodeNum.','.$total.']';
		$point_time = time();
		sendPoint($total, G::$settings->NodeNum, $point_time);
		//print "sending: ".$bulkData."\r\n";
		sleep(G::$settings->flooper);
		$i++;
	}
}

function sendPoint($totalCons, $nodeNum, $point_time) {
	$emoncms_url =  G::$host->url."input/post.json";
	$emoncms_api = G::$host->api;

	// EmonCMS.org does not respect any call with a time= argument.  
	$sendTo = $emoncms_url."?time=$point_time"."&apikey=".$emoncms_api."&node=".$nodeNum;
	#$sendTo = $emoncms_url."?apikey=".$emoncms_api."&node=".$nodeNum;
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

	print "server response ($rcode): ---"; 
	if(strlen($response) <= 0) $response = "no server response";
	print $response;
	print "===\r\n";
}

// BULK HANDLING FUNCTIONS
function dump_columns() {
	$file = fopen(G::$settings->InputFile, 'r');
	$chunkarr = get_chunk( $file, G::$settings->maxRows );
	
	echo "Time string, epoch time, data values...\n";
	foreach( $chunkarr as $chunkrow ) {
		echo str_putcsv($chunkrow);
	}
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

/**
 * Convert a multi-dimensional, associative array to CSV data
 * @param  array $data the array of data
 * @return string       CSV text
 * https://coderwall.com/p/zvzwwa/array-to-comma-separated-string-in-php
 */
function str_putcsv($data) {
        # Generate CSV data from array
        $fh = fopen('php://temp', 'rw'); # don't create a file, attempt
                                         # to use memory instead

        # write out the headers
        //fputcsv($fh, array_keys(current($data)));

        # write out the data
		fputcsv($fh, $data);
        rewind($fh);
        $csv = rtrim( stream_get_contents($fh), "\n\r");
        fclose($fh);

		
        return $csv;
}

function get_chunk($infile, $max_rows) {
	// returns a 2D array comprising at most $max_rows of input data
	$chunkarr = array();
	$nodeNum = G::$settings->NodeNum;  
	
	for( $i=0; $i < $max_rows; $i++ ) {
		$line = fgets($infile);
		//print( "line: $line \n");
		if( $line == FALSE ) break;
		$line = trim($line);
		$expl = explode(',', $line);
		
		// Verify / massage what we got
		if( sizeof($expl) < 2 || strlen($line) == 0 ) {
			print('bad line (' . strlen($line) . "/" . sizeof($expl) . '): ' . $line . "\n");
			continue;
		}
		foreach ($expl as $key => $field) {
			if( empty( $field ) ) {
				$expl[$key] = 0;
			}
		}
		
		$timestr = $expl[G::$settings->TimeCol];
		$timestr = trim( $timestr, "'" ). " GMT";
		$epoch = strtotime( $timestr );
		$chunkarr[$i][0] = $epoch;
		$chunkarr[$i][1] = $nodeNum;
		
		//echo "$timestr : $epoch\n";
		foreach( G::$settings->DataCol as $dColOffset ) {
			//if( empty($expl[$dColOffset]) )  $expl[$dColOffset] = 0;
			$chunkarr[$i][] = $expl[$dColOffset];
		}
	}
	return $chunkarr;
}

function build_chunk($chunkarr) {
	// returns complete data string
	$datastr = "[";
	foreach( $chunkarr as $chunkrow ) {
		//$epochtime = $chunkrow[0];
		//$dataval = $chunkrow[1];
		$datastr .= "[" . str_putcsv($chunkrow) . "],";
		if(G::$settings->format) $datastr .= "\n";

		//print ".$epochtime\n";
	}
	$datastr = rtrim($datastr, ",\n");
	$datastr .= "  ]";
	if(G::$settings->format) $datastr .= "\n";
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
		if( isset(G::$host->uspw) )
			curl_setopt($ch, CURLOPT_USERPWD, G::$host->uspw);

		$response = curl_exec($ch);
		$rcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
	} else {
		$response = "server not contacted";
		$rcode = '000';
	}
	
	if( G::$settings->verbose  
	|| G::$settings->printBulk ) {
		print $sendTo. "&";
		if(G::$settings->format) print "\n";
		print "data=" . $data;

		print "\nserver response ($rcode): ---"; 
		if(strlen($response) <= 0) $response = "no server response";
		print $response;
		print "===\r\n";
	}

	sleep(1);
}


// CLASSES
class G {
	// Holder for global statics (for concise global access)
	public static $settings;
	public static $host;
	public static $cmdlinesettings;
}

class Settings {
	public function __construct($arglist = null){
	if( $arglist == null ) return;
	 foreach ($arglist as $prop=>$value){
		if( property_exists(get_class(), $prop) ) {
			if( is_array( $this->$prop ) ) {
				$nva = explode(',', $value );
				$this->$prop = array_merge( $this->$prop, $nva );
				//print( "array add $prop = " . print_r($this->$prop, true) );
			} else 
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
	public $DataCol = []; 	// Column offset to meter data.
	public $NodeNum;
	public $maxRows;  	// Ignore input file beyond this many rows.
	public $verbose;	// extra console output
	public $format;		// add newlines to output
	public $dumpSettings;// dump settings for debugging

	// ACTION FLAGS (only one of these can be set!)
	public $printHelp = FALSE;
	public $dumpRows = FALSE;  	// Dump this many rows on the console and exit. [debugging]
	public $flooper = FALSE;	// Every $flooper seconds, uploads a random value 1-10.
	public $sendBulk = FALSE;   // Upload bulk data
	public $printBulk = FALSE; 	// Show what would have been sent, if this were a real send.
	public $createInput = FALSE;// Create an empty input, so you can go and make some feeds.

	// Other properties
	private $parsed = FALSE;

	public static function parse_and_validate() {
		// 1. Validate commandline arguments.
		G::$cmdlinesettings = new Settings(); // Validate the commandline without settings.php interference.
		G::$cmdlinesettings->parse_args();
		G::$cmdlinesettings->validate_settings("commandline");

		// 2. Validate settings.php 
		G::$settings->validate_settings("settings");

		// 3. Parse commandline into global settings and validate again.
		G::$settings->parse_args();
		G::$settings->validate_settings("settings and commandline");
	}

	function validate_settings($argsrc) {		
		// Validates that the settings are sane.
		if( $this->count_actions() > 1 ) {
			print("Error: More than one action requested with $argsrc\n");
			print_r($this);
			exit();
		}
			
		// Misc settings
		if( $this->maxRows == 0 ) 
			$this->maxRows = PHP_INT_MAX;
	}
	
	function parse_args() {
		// Am I global?
		$amGlobal = false;
		if( $this == G::$settings ) $amGlobal = true;
		
		// load commandline args into properties.
		global $argv;
		$longopts  = array("format", "help", "settings" );
		$opts = getopt('iouhcf:spvx:r:e:t:d:n:m:g:', $longopts, $optind);
		$pos_args = array_slice($argv, $optind);

		// Only when parsing args in global settings, clear defaults if necessary.
		if( $amGlobal 
		&& G::$cmdlinesettings-> count_actions() > 0 
		&&  G::$settings-> count_actions() > 0) {
				// action specified in settings and on the commandline.
				print "Commandline action overrides action from settings.php\n";
				$this->dumpRows = FALSE;
				$this->flooper = 0;
				$this->sendBulk = FALSE;
				$this->printBulk = FALSE;
				$this->createInput = FALSE;
				$this->printHelp = FALSE;
		}
		if( $amGlobal
		&& strlen( G::$cmdlinesettings->InputFile ) > 0
		&& strlen( G::$settings->InputFile ) > 0 ) {
			print("Override default input file\n");
			unset($this->InputFile);
		}
		
		// Set properties based on options.
		if( isset($opts["u"]) ) $this->dumpRows = TRUE;
		if( isset($opts["f"]) ) $this->flooper = $opts["f"];
		if( isset($opts["s"]) ) $this->sendBulk = TRUE;
		if( isset($opts["p"]) ) $this->printBulk = TRUE;
		if( isset($opts["h"]) ) $this->printHelp = TRUE;
		if( isset($opts["help"]) ) $this->printHelp = TRUE; // NB: getopt is broken for long options.
		if( isset($opts["c"]) ) $this->createInput = TRUE;

		// Flags
		if( isset($opts["v"]) ) $this->verbose = $opts["v"];
		if( isset($opts["o"]) ) $this->format = TRUE;
		if( isset($opts["i"]) ) $this->dumpSettings = TRUE;

		// Settings
		//if( isset($opts["I"]) ) $this->InputFile;
		if( isset($opts["x"]) ) $this->chunkSize = $opts["x"];
		if( isset($opts["r"]) ) $this->Serial 	= $opts["r"];
		if( isset($opts["e"]) ) $this->SubDevice = $opts["e"];
		if( isset($opts["t"]) ) $this->TimeCol = $opts["t"];
		if( isset($opts["d"]) ) {
			//print_r(explode( ',', $opts["d"] ));
			$this->DataCol = explode( ',', $opts["d"] );
		}
		if( isset($opts["n"]) ) $this->NodeNum = $opts["n"];
		if( isset($opts["m"]) ) $this->maxRows = $opts["m"];

		// File -- positional parameters not otherwise specified.
		foreach( $pos_args as $parg ) {
			if (isset ($this->InputFile ) ) {
				print("Quitting due to second filename specified: $parg\n");
				print("Use -h or --help.\n");
				exit();
			} else {
				$this->InputFile = $pos_args[0];
			}
		}
		
	}
	function count_actions() {
		$a = 0;
		if( $this->dumpRows ) $a++;
		if( $this->flooper ) $a++;
		if( $this->sendBulk ) $a++;
		if( $this->printBulk ) $a++;
		if( $this->createInput ) $a++;
		if( $this->printHelp ) $a++;
		return $a;
	}
	function print_help() {
		echo("Usage: \n
emoncsv.php -h
emoncsv.php [options] FILENAME.CSV\n
Actions:
  -u - dump rows
  -f - send random consumption
  -s - send bulk data to server
  -p - print bulk data that would be sent to server
  -c - create empty input for the current node (you need to establish feeds before uploading)
  -h - print this help

Flags:
  -v - extra console output, sometimes.
  -o - format console output with newlines
  -i - dump all specified settings and exit
  
Settings:
  -gX - NOOP load specified instead of settings.php (not implemented)
  -xN - CHUNKSIZE upload no more than N rows at a time
  -rX - SERIAL set data source's serial number to X (does nothing at present) 
  -eX - MBDEV set data source subdevice to X (does nothing at present) 
  -tN - TIME N is 0-base offset to column with human-readable time data in UTC
  -dXX- DATA XX is a series of , delimited 0-base offsets to numeric data values in the CSV
  -nN - NODE upload to EmonCMS node number N
  -mN - ROWS stop processing after N input rows\n"); 
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
