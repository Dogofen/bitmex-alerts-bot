<?php
require __DIR__ . '/vendor/autoload.php';
require_once("GmailClient.php");
require_once('log.php');

$logPath =  getcwd().'/index.log';
$log = create_logger($logPath);
$oldMessagesIds = false;

$log->info("Bot index has started", ['info'=>'start']);
$gmailClient = new GmailClient();

try {
    $gmailClient->populateMessagesIds();
} catch (Exception $e) {
    $log->error('An error occurred at populateMessagesIds: ', ['error'=>$e->getMessage()]);
}



while(1) {
    try {
        $newMessagesIds = $gmailClient->getNewMessagesIds();
    } catch (Exception $e) {
        $log->error('An error occurred at getLastMessages: ', ['error'=>$e->getMessage()]);
    }

    if(empty($newMessagesIds)) {
        shell_exec('touch bot_idle');
        sleep(15);
    }

    else {
        $log->info("New messages fetched", ['messages'=>$newMessagesIds]);
        foreach($newMessagesIds as $msgId) {
            try {
                $msg = $gmailClient->getMessage($msgId);
                $params = preg_replace('/.*:/', "", $gmailClient->getAlertSubject($msg));
            } catch (Exception $e) {
                $log->error('An error occurred at getMessage or getAlertSubject: ', ['error'=>$e->getMessage()]);
            }
            $command = 'php CreateTrade.php '.$params.' > /dev/null 2>&1 &';
            $res = $gmailClient->isMessageAlert($msg);
            $res == True ? shell_exec('php CreateTrade.php '.$params.' > /dev/null 2>&1 &') : "Not alert message\n";
            $log->info('Command was sent to Trader '.$command, ['msg id'=>$msgId]);
            $gmailClient->populateMessageId($msgId);
        }
    }
}
?>
