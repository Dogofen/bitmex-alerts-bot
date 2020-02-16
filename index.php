<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("GmailClient.php");
require_once("BitMex.php");
$config = include('config.php');
// Get the API client and construct the service object.

$ticker =".ticker.txt";
$timestamp = date("Y-m-d_H:i:s");
$startTime = microtime(true);

$logPath =  getcwd().'/index.log';
$log = new Logger('BOT');
$log->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
$log->pushProcessor(function ($entry) use($startTime) {
    $endTime = microtime(true);
    $s = $endTime - $startTime;
    $h = floor($s / 3600);
    $s -= $h * 3600;
    $m = floor($s / 60);
    $s -= $m * 60;
     $entry['extra']['Time Elapsed'] = $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
    return $entry;
});

$gmailClient = new GmailClient($log);
try {
    $gmailClient->populateMessagesIds();
} catch (Exception $e) {
    $log->error('An error occurred at populateMessagesIds: ', ['error'=>$e->getMessage()]);
}
$bitmex = new BitMex($config['key'],$config['secret']);
$log->info("Bot index has started", ['info'=>'start']);

while(1) {
    try {
        $result = $bitmex->getTicker('XBTUSD');
        file_put_contents($ticker,  strval($result["last"]));
    } catch (Exception $e) {
        $log->error("Failed retrieving ticker", ['error'=>$e]);
    }

    try {
        $msgs = $gmailClient->getNewMessagesIds();
    } catch (Exception $e) {
         $log->error('An error occurred at getNewMessagesIds: ', ['error'=>$e->getMessage()]);
    }

    if(empty($msgs)) {
        sleep(2);
    }
    else {
        $log->info("New messages fetched", ['messages'=>$msgs]);
        foreach($msgs as $msgId) {
            try {
                $msg = $gmailClient->getMessage($msgId);
                $params = $gmailClient->getAlertSubject($msg);
            } catch (Exception $e) {
                $log->error('An error occurred at getMessage or getAlertSubject: ', ['error'=>$e->getMessage()]);
            }
            $command = 'php CreateTrade.php '.$params.' > /dev/null 2>&1 &';
            $log->info('Command was sent to Trader', ['command'=>$command]);
            $res = $gmailClient->isMessageAlert($msg);
            $res == True ? shell_exec('php CreateTrade.php '.$params.' > /dev/null 2>&1 &') : "Not alert message\n";
            $gmailClient->populateMessageId($msgId);
        }
    }
}
?>
