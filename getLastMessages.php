<?php
set_time_limit(5);

require __DIR__ . '/vendor/autoload.php';
require_once("GmailClient.php");

$gmailClient = new GmailClient();
return $gmailClient->getLastMessages();

?>
