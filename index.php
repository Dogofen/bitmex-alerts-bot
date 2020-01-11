<?php
require_once("GmailClient.php");
// Get the API client and construct the service object.
$gmailClient = new GmailClient();
$gmailClient->populateMessagesIds();
while(1) {
    $msgs = $gmailClient->getNewMessagesIds();
    if(empty($msgs)) {
        sleep(1);
    }
    else {
        $msg = $gmailClient->getMessage($msgs[0]);
        $params = $gmailClient->getAlertSubject($msg);
        $command = 'php CreateTrade.php '.$params.' > /dev/null 2>&1 &';
        echo $command;
        $res = $gmailClient->isMessageAlert($msg);
        $res == True ? shell_exec('php CreateTrade.php '.$params.' > /dev/null 2>&1 &') : "Not alert message\n";
        $gmailClient->populateMessagesIds();
    }
}
?>
