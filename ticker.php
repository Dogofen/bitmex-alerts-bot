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
        file_put_contents($ticker,  serialize($result));
    } catch (Exception $e) {
        $log->error("Failed retrieving ticker", ['error'=>$e]);
    }
    sleep(2);
}
?>
