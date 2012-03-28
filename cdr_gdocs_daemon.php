#!/usr/bin/php
<?php
//Set the ticks
declare(ticks = 1);
ini_set('track_errors', 'true');
error_reporting(0);

// Settings
$config = parse_ini_file('cdr_gdocs_daemon.conf');
if($config === false || !sizeof($config))
	die("Unable to parse the configuration file: $php_errormsg\n");

//Fork the current process
$pid = pcntl_fork();

//Check to make sure the forked ok.
if($pid == -1)
	die("Couldn't fork, uh oh!\n");
elseif($pid)	//This is the parent process.
	exit;

//We're now in the child process.

//Now, we detach from the terminal window, so that we stay alive when it is closed.
if(posix_setsid() == -1)
	die("Unable to detache from the parent session.\n");
 
//Create a new file with the process id in it.
file_put_contents("/var/run/cdr_gdocs_daemon.pid", posix_getpid());

// setup signal handlers to actually catch and direct the signals
function sig_handler($signo){
	global $queue;
	switch($signo)
	{
		case SIGTERM:
		case SIGHUP:
		case SIGINT:
			do_log("Got signal $signo, exiting.");
			// We "never" destroy the queue - the child can still post to it and we
			// will catch-up when we restart.
			//if($queue)
			//	msg_remove_queue($queue);
			exit;
			break;
	}
}
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");
pcntl_signal(SIGINT, "sig_handler");


error_reporting(E_ALL);
ini_set('display_errors', 'false');
ini_set('log_errors', 'true');
ini_set('error_log', '/tmp/cdr_gdocs_daemon.log');
function do_log($data) { return file_put_contents(ini_get('error_log'), trim($data)."\n", FILE_APPEND); }

do_log("CDR_GDOCS_DAEMON started");
do_log("Environment: ".print_r($_ENV, true));
do_log("Server: ".print_r($_SERVER, true));



$email = $config['email'];
$password = $config['password'];
$spreadsheet = $config['spreadsheet'];
$worksheet = $config['worksheet'];

do_log("Configured $email / $spreadsheet / $worksheet");

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/ZendGdata-1.11.11/library/');
do_log("include_path updated");


$queue = msg_get_queue( 42 /*ftok?*/);
if(!$queue)
{
	do_log("Error while connecting to IPC message queue: $php_errormsg");
	die();
}

require_once 'Zend/Loader.php';	// Overall loader
Zend_Loader::loadClass('Zend_Gdata');	// Overall Gdata class
Zend_Loader::loadClass('Zend_Http_Client');	// For all HTTP communications
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');	// For username/pass login
Zend_Loader::loadClass('Zend_Gdata_App_AuthException');	// For username/pass failure
Zend_Loader::loadClass('Zend_Gdata_Spreadsheets');	// For the spreadsheet itself
do_log("Zend libraries loaded.");


$gdClient = retry(3, 30, 'gdata_auth', $email, $password);
if($gdClient === false)
	die(do_log("Couldn't log in to Google. Exiting."));
$ss_id = retry(3, 30, 'load_spreadsheet', $gdClient, $spreadsheet);
if($ss_id === false)
	die(do_log("Couldn't find spreadsheet $spreadsheet. Exiting."));
$ws_id = retry(3, 30, 'load_worksheet', $gdClient, $ss_id, $worksheet);
if($ws_id === false)
	die(do_log("Couldn't find worksheet $worksheet. Exiting."));

while(true)
{
	$msgtype=null;
	$message=null;
	if(!msg_receive($queue, 0, $msgtype, 1e6, $message))
	{
		do_log("huh, odd... msg_receive failed. Going to try again.");
		sleep(5);
		continue;
	}
	do_log("Processing message($msgtype)=".print_r($message, true));
	// try... and upon failure, re-queue
	$row_id = retry(5, 30, 'insert_row', $gdClient, $ss_id, $ws_id, $message);
	if($row_id === false)
	{
		do_log("Seems like Google is being a prick. Re-queue message. Sleep. Refresh login.");
		msg_send($queue, $msgtype+1, $message);
		sleep(15);
		$gdClient = retry(3, 30, 'gdata_auth', $email, $password);
		if($gdClient === false)
			die(do_log("Couldn't log back in to Google. Exiting."));
		$ss_id = retry(3, 30, 'load_spreadsheet', $gdClient, $spreadsheet);
		if($ss_id === false)
			die(do_log("Couldn't find spreadsheet $spreadsheet. Exiting."));
		$ws_id = retry(3, 30, 'load_worksheet', $gdClient, $ss_id, $worksheet);
		if($ws_id === false)
			die(do_log("Couldn't find worksheet $worksheet. Exiting."));
	}
	sleep(1);
}


do_log("CDR_GDOCS_DAEMON complete. Exit.");



function retry()
{
	$args = func_get_args();
	$tries = array_shift($args);
	$timeout = array_shift($args);
	$name = array_shift($args);
	$start = microtime(true);
	for($i=0; $i<$tries; $i++)
	{
		if($timeout && (microtime(true)-$start) > $timeout)
			return false;
		$res = call_user_func_array($name, $args);
		if($res !== false)
			return $res;
		do_log("Retry($name): i=$i failed.");
		sleep(1);
	}
	return false;
}

function gdata_auth($email, $password)
{
	// Login to Google
	try {
		$client = Zend_Gdata_ClientLogin::getHttpClient($email, $password,
			Zend_Gdata_Spreadsheets::AUTH_SERVICE_NAME);
	} catch (Zend_Gdata_App_AuthException $ae) {
		do_log("Unable to login to Google: ". $ae->getMessage());
		//exit("Error: ". $ae->getMessage() ."\nCredentials provided were email: [$email] and password [$password].\n");
		return false;
	}
	$gdClient = new Zend_Gdata_Spreadsheets($client, "QTurbo-cdr_gdocs-1.0");
	do_log("Connected to Google.");
	return $gdClient;
}

function load_spreadsheet($gdClient, $spreadsheet)
{
	// Open the spreadsheet
	$spreadsheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
	$spreadsheet_query->setTitleExact($spreadsheet);
	$spreadsheet_query->setDocumentType('spreadsheets');
	try {
		$spreadsheet_feed = $gdClient->getSpreadsheetFeed($spreadsheet_query);
	} catch (Zend_Gdata_App_Exception $e) {
		do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
		//exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
		return false;
	}
	$spreadsheet_id = explode('/', $spreadsheet_feed->entries[0]->id->text);
	$spreadsheet_id = $spreadsheet_id[7];
	#echo "Spreadsheet ID: {$spreadsheet_id}\n";
	do_log("Spreadsheet loaded: $spreadsheet_id");
	return $spreadsheet_id;
}

function load_worksheet($gdClient, $spreadsheet_id, $worksheet)
{
	// Open the worksheet
	$worksheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
	$worksheet_query->setTitleExact($worksheet);
	$worksheet_query->setDocumentType('worksheets');
	$worksheet_query->setSpreadsheetKey($spreadsheet_id);
	try {
		$worksheet_feed = $gdClient->getSpreadsheetFeed($worksheet_query);
	} catch (Zend_Gdata_App_Exception $e) {
		do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
		//exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
		return false;
	}
	$worksheet_id = explode('/', $worksheet_feed->entries[0]->id->text);
	$worksheet_id = $worksheet_id[8];
	#echo "Worksheet ID: {$worksheet_id}\n";
	do_log("Worksheet loaded: $worksheet_id");
	return $worksheet_id;
}

function insert_row($gdClient, $spreadsheet_id, $worksheet_id, $rowData)
{
	try {
		$res = $gdClient->insertRow($rowData, $spreadsheet_id, $worksheet_id);
	} catch (Zend_Gdata_App_Exception $e) {
		do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
		//exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
		return false;
	}
	do_log("Row posted @ ".substr($res->getTitle()->getText(), 5).": $res");
	return $res;
}
?>
