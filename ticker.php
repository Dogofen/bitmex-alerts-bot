<?php
require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
$config = include('config.php');
require_once('log.php');


$logPath =  getcwd().'/index.log';
$log = create_logger($logPath);

$ticker =".ticker.txt";
$bitmex = new BitMex($config['key'],$config['secret'], $config['testnet']);

while(1) {
    try {
        $result = $bitmex->getTicker('XBTUSD');
    } catch (Exception $e) {
        $log->error("Failed retrieving ticker, sleeping for 360 seconds", ['error'=>$e]);
        sleep(360);
        continue;
    }
    if (!$result) {
        $log->error("Failed retrieving ticker, Bitmex servers have blocked communication, sleeping for 360 seconds before retrying...", ['result'=>$result]);
        sleep(360);
        continue;
    }
    file_put_contents($ticker,  serialize($result));
    sleep(2);
}
?>
