<?php
require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
$config = include('config.php');
require_once('log.php');


$logPath =  getcwd().'/index.log';
$log = create_logger($logPath);

$ticker =".ticker";
$bitmex = new BitMex($config['key'],$config['secret'], $config['testnet']);
$symbols = array("XBTUSD", "ETHUSD", "XRPUSD");
while(1) {
    foreach($symbols as $symbol) {
        try {
            $result = $bitmex->getTicker($symbol);
        } catch (Exception $e) {
            $log->error("Failed retrieving ticker, sleeping for 360 seconds", ['error'=>$e]);
            sleep(180);
            continue;
        }
        if (!$result) {
            $log->error("Failed retrieving ticker, Bitmex servers have blocked communication, sleeping for 360 seconds before retrying...", ['result'=>$result]);
            sleep(180);
            continue;
        }
        file_put_contents($ticker.$symbol.'.txt',  serialize($result));
        sleep(1);
    }
}
?>
