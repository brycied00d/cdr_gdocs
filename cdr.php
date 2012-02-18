#!/usr/bin/php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'false');
ini_set('log_errors', 'true');
ini_set('error_log', '/tmp/cdr_gdocs.log');
function do_log($data) { return file_put_contents(ini_get('error_log'), trim($data)."\n", FILE_APPEND); }

do_log("CDR_GDOCS started");
do_log("Environment: ".print_r($_ENV, true));
do_log("Server: ".print_r($_SERVER, true));



// Settings
$email = 'brycec@qturbo.com';
$password = 'rhtwlecsfdqofcbx';
$spreadsheet = 'MEDITAB CALL LOG';
$worksheet = 'Log';

do_log("Configured $email : $password / $spreadsheet / $worksheet");

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/ZendGdata-1.11.11/library/');
do_log("include_path updated");





$timex=0;
$time[$timex++] = microtime(true);
require_once 'Zend/Loader.php';	// Overall loader
Zend_Loader::loadClass('Zend_Gdata');	// Overall Gdata class
Zend_Loader::loadClass('Zend_Http_Client');	// For all HTTP communications
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');	// For username/pass login
Zend_Loader::loadClass('Zend_Gdata_App_AuthException');	// For username/pass failure
Zend_Loader::loadClass('Zend_Gdata_Spreadsheets');	// For the spreadsheet itself
$time[$timex++] = microtime(true);
do_log("Zend libraries loaded.");

// Login to Google
try {
	$client = Zend_Gdata_ClientLogin::getHttpClient($email, $password,
		Zend_Gdata_Spreadsheets::AUTH_SERVICE_NAME);
} catch (Zend_Gdata_App_AuthException $ae) {
	do_log("Unable to login to Google: ". $ae->getMessage());
	exit("Error: ". $ae->getMessage() ."\nCredentials provided were email: [$email] and password [$password].\n");
}
$gdClient = new Zend_Gdata_Spreadsheets($client, "QTurbo-cdr_gdocs-1.0");
$time[$timex++] = microtime(true);
do_log("Connected to Google.");

// Open the spreadsheet
$spreadsheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
$spreadsheet_query->setTitleExact($spreadsheet);
$spreadsheet_query->setDocumentType('spreadsheets');
try {
	$spreadsheet_feed = $gdClient->getSpreadsheetFeed($spreadsheet_query);
} catch (Zend_Gdata_App_Exception $e) {
	do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
	exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
}
$spreadsheet_id = explode('/', $spreadsheet_feed->entries[0]->id->text);
$spreadsheet_id = $spreadsheet_id[7];
#echo "Spreadsheet ID: {$spreadsheet_id}\n";
$time[$timex++] = microtime(true);
do_log("Spreadsheet loaded: $spreadsheet_id");

// Open the worksheet
$worksheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
$worksheet_query->setTitleExact($worksheet);
$worksheet_query->setDocumentType('worksheets');
$worksheet_query->setSpreadsheetKey($spreadsheet_id);
try {
	$worksheet_feed = $gdClient->getSpreadsheetFeed($worksheet_query);
} catch (Zend_Gdata_App_Exception $e) {
	do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
	exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
}
$worksheet_id = explode('/', $worksheet_feed->entries[0]->id->text);
$worksheet_id = $worksheet_id[8];
#echo "Worksheet ID: {$worksheet_id}\n";
$time[$timex++] = microtime(true);
do_log("Worksheet loaded: $worksheet_id");

// Parse args -- $1 name, $2 number, $3 dest? (if picked up)
$CIDNAME=$_SERVER['argv'][1];
$CIDNUM=$_SERVER['argv'][2];
do_log("Name: $CIDNAME Number: $CIDNUM");

// Insert a row and be done
$rowData=array('date'=>date('m/d/Y H:i:s', $_SERVER['REQUEST_TIME']+3600), 'customer'=>$CIDNAME, 'phone'=>$CIDNUM);
//var_dump($rowData, $spreadsheet_id, $worksheet_id);
try {
	$res = $gdClient->insertRow($rowData, $spreadsheet_id, $worksheet_id);
} catch (Zend_Gdata_App_Exception $e) {
	do_log("Zend_Gdata_App_Exception: ". $e->getMessage() . "\n" . $e->getTraceAsString());
	exit("Error: Zend_Gdata_App_Exception: ". $e->getMessage()."\n");
}
$time[$timex++] = microtime(true);
//var_dump($res);
do_log("Row posted: $res");
//exit;

// For timing the whole thing
for($i=1; $i<$timex; $i++)
	do_log("[$i]: ".($time[$i]-$time[$i-1]));
do_log("[X]: ".($time[sizeof($time)-1]-$time[0]));
//	echo "[$i]: ".($time[$i]-$time[$i-1])."\n";
//echo "[X]: ".($time[sizeof($time)-1]-$time[0])."\n";

do_log("CDR_GDOCS complete. Exit.");
?>
