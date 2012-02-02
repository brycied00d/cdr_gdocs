#!/usr/bin/php
<?php
// Settings
$email = 'brycec@qturbo.com';
$password = rhtwlecsfdqofcbx';
$spreadsheet = 'MEDITAB CALL LOG';
$worksheet = 'Sheet 1';

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/ZendGdata-1.11.11/library/');







$timex=0;
$time[$timex++] = microtime(true);
require_once 'Zend/Loader.php';	// Overall loader
Zend_Loader::loadClass('Zend_Gdata');	// Overall Gdata class
Zend_Loader::loadClass('Zend_Http_Client');	// For all HTTP communications
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');	// For username/pass login
Zend_Loader::loadClass('Zend_Gdata_App_AuthException');	// For username/pass failure
Zend_Loader::loadClass('Zend_Gdata_Spreadsheets');	// For the spreadsheet itself
$time[$timex++] = microtime(true);

// Login to Google
try {
	$client = Zend_Gdata_ClientLogin::getHttpClient($email, $password,
		Zend_Gdata_Spreadsheets::AUTH_SERVICE_NAME);
} catch (Zend_Gdata_App_AuthException $ae) {
	exit("Error: ". $ae->getMessage() ."\nCredentials provided were email: [$email] and password [$password].\n");
}
$gdClient = new Zend_Gdata_Spreadsheets($client, "QTurbo-cdr_gdocs-1.0");
$time[$timex++] = microtime(true);

// Open the spreadsheet
$spreadsheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
$spreadsheet_query->setTitleExact($spreadsheet);
$spreadsheet_query->setDocumentType('spreadsheets');
$spreadsheet_feed = $gdClient->getSpreadsheetFeed($spreadsheet_query);
$spreadsheet_id = explode('/', $spreadsheet_feed->entries[0]->id->text);
$spreadsheet_id = $spreadsheet_id[7];
#echo "Spreadsheet ID: {$spreadsheet_id}\n";
$time[$timex++] = microtime(true);

// Open the worksheet
$worksheet_query = new Zend_Gdata_Spreadsheets_DocumentQuery();
$worksheet_query->setTitleExact($worksheet);
$worksheet_query->setDocumentType('worksheets');
$worksheet_query->setSpreadsheetKey($spreadsheet_id);
$worksheet_feed = $gdClient->getSpreadsheetFeed($worksheet_query);
$worksheet_id = explode('/', $worksheet_feed->entries[0]->id->text);
$worksheet_id = $worksheet_id[8];
#echo "Worksheet ID: {$worksheet_id}\n";
$time[$timex++] = microtime(true);


// Parse args -- $1 name, $2 number, $3 dest? (if picked up)
$CIDNAME=$_SERVER['argv'][1];
$CIDNUM=$_SERVER['argv'][2];

// Insert a row and be done
$rowData=array('date'=>date('r'), 'customer'=>$CIDNAME, 'phone'=>$CIDNUM);
$gdClient->insertRow($rowData, $spreadsheet_id, $worksheet_id);
$time[$timex++] = microtime(true);

exit;

// For timing the whloe thing
for($i=1; $i<$timex; $i++)
	echo "[$i]: ".($time[$i]-$time[$i-1])."\n";
echo "[X]: ".($time[sizeof($time)-1]-$time[0])."\n";
?>
