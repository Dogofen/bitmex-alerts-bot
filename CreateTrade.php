<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("BitMex.php");
$config = include('config.php');

function is_buy($type) {
    return $type == 'Buy' ? 1:0;
}

$tradeSymbols = array('XBTUSD', 'XBT7D_U105', 'ADAZ19', 'BCHZ19', 'EOSZ19','ETHUSD', 'LTCZ19', 'TRXZ19', 'XRPZ19');
$tradeTypes = array('Buy', 'Sell');

if (isset($argv[1]) and in_array($argv[1], $tradeSymbols)) {
    $symbol = $argv[1];
}
else {
    echo 'Error: '.$argv[1].' is not a valid symbol'."\n";
    exit();
}
if (isset($argv[2]) and 0.001 < $argv[2] and $argv[2] < 100) {
    $intervalPercentage = floatval($argv[2]) / 100;
}
else {
    echo 'Error: '.$argv[2]. ' is not a valid interval'."\n";
    exit();
}
if (isset($argv[3]) and 0.001 < $argv[3] and $argv[3] < 100) {
    $targetPercent = floatval($argv[3]) / 100;
}
else {
    echo 'Error: '.$argv[3]. ' is not a valid target'."\n";
    exit();
}
if (isset($argv[4]) and in_array($argv[4], $tradeTypes)) {
    $type = $argv[4];
}
else {
    echo 'Error: '.$argv[4]. ' is not a valid trade type'."\n";
    exit();
}
if (isset($argv[5])) {
    $amount = $argv[5];
}
else {
    echo 'Error: failed to get amount'."\n";
    exit();
}
$order = False;
$bitmex = new BitMex($config['key'],$config['secret']);
$bitmex->setLeverage($config['leverage'], $symbol);
$result = $bitmex->getTicker($symbol);
$lastPrice = $result["last"];
$interval = $lastPrice * $intervalPercentage;
$target = $lastPrice + $lastPrice * $targetPercent;
do {
    $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
} while ($order == False);
if (!is_array($order) or is_array($order) and empty($order)) {
    echo "ERROR: Order Failure\n";
    var_dump($order);
    exit();
}
$orderId = $order['orderID'];

$logPath = is_buy($type) ? getcwd().'/long'.$orderId.'.log' : getcwd().'/short'.$orderId.'.log';
$startTime = microtime(true);
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
$log->info("Position has been created on Bitmex", ['info'=>$order]);
$log->info("Target is at price", ['Target'=>$target]);
$log->info("Interval is", ['Interval'=>$interval]);

$close = is_buy($type) ? $lastPrice - $interval :  $lastPrice + $interval;
$log->info("Setting current Close price", ['Close Price'=>$close]);

$flag = 0;
while(1) {
    $result = $bitmex->getTicker($symbol);
    $tmpLastPrice = $result["last"];
    if($tmpLastPrice <= $target and !is_buy($type) or $tmpLastPrice >= $target and is_buy($type)) {
        if (!$flag) {
            $interval = $interval * 0.282;
            $log->info("Target was reached, setting new interval", ['interval'=>$interval]);
            $flag = 1;
        }
    }
    if ($tmpLastPrice > $close and !is_buy($type) or $tmpLastPrice < $close and is_buy($type)) {
        $close = False;
        $log->info("Position reached it's close price, thus Closing.", ['Close Price'=>$tmpLastPrice]);
        do {
            $close = $bitmex->closePosition($symbol, null);
        } while ($close == False);
        $log->info("Trade has closed successfully", ['info'=>$close]);
        break;
    }
    if($tmpLastPrice < $lastPrice and !is_buy($type)) {
        $lastPrice = $tmpLastPrice;
        $close = $lastPrice + $interval;
        $log->info("Price moved down", ['Price'=>$lastPrice]);
        $log->info("Updating entry to stop loss at price", ['Close Price'=>$close]);
    }
    if($tmpLastPrice > $lastPrice and is_buy($type)) {
        $lastPrice = $tmpLastPrice;
        $close = $lastPrice - $interval;
        $log->info("Price moved up", ['Price'=>$lastPrice]);
        $log->info("Updating entry to stop loss at price", ['Close Price'=>$close]);
    }

    sleep(2);
}

?>
