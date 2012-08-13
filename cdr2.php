#!/usr/bin/php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'false');
ini_set('log_errors', 'true');
ini_set('track_errors', 'true');
ini_set('error_log', '/tmp/cdr_gdocs2.log');
function do_log($data) { return file_put_contents(ini_get('error_log'), trim($data)."\n", FILE_APPEND); }

do_log("CDR_GDOCS2 started");
do_log("Environment: ".print_r($_ENV, true));
do_log("Server: ".print_r($_SERVER, true));

// Parse args -- $1 name, $2 number, $3 dest? (if picked up)
$CIDNAME=$_SERVER['argv'][1];
$CIDNUM=$_SERVER['argv'][2];
do_log("Name: $CIDNAME Number: $CIDNUM");

$queue = msg_get_queue( 42 /*ftok?*/);
if(!$queue)
{
	do_log("Error while connecting to IPC message queue: $php_errormsg");
	die();
}

// !DST compensation
//$message = array('date'=>date('m/d/Y H:i:s', $_SERVER['REQUEST_TIME']+3600), 'customer'=>$CIDNAME, 'phone'=>$CIDNUM);
ini_set('date.timezone', 'America/Phoenix');
$message = array('date'=>date('m/d/Y H:i:s', $_SERVER['REQUEST_TIME']), 'customer'=>$CIDNAME, 'phone'=>$CIDNUM);
do_log("Queueing message=".print_r($message, true));

if(!msg_send($queue, 1, $message))
{
	do_log("Error while stuffing the IPC message queue: $php_errormsg");
	die();
}

do_log("CDR_GDOCS2 complete. Exit.");
?>
