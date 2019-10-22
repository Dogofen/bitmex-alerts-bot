<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("BitMex.php");
$config = include('config.php');

function is_buy($type) {
    return $type == 'Buy' ? 1:0;
}
if (isset($argv[1])) {
    $symbol = $argv[1];
}
else {
    exit();
}
if (isset($argv[2])) {
    $intervalPercentage = floatval($argv[2]) / 100;
}
else {
    exit();
}
if (isset($argv[3])) {
    $target = $argv[3];
}
else {
    exit();
}
if (isset($argv[4])) {
    $type = $argv[4];
}
else {
    exit();
}
if (isset($argv[5])) {
    $amount = $argv[5];
}
else {
    exit();
}
$bitmex = new BitMex($config['key'],$config['secret']);
$bitmex->setLeverage($config['leverage'], $symbol);
$result = $bitmex->getTicker($symbol);
$lastPrice = $result["last"];
$interval = $lastPrice * $intervalPercentage;
$order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
if (!is_array($order) or is_array($order) and empty($order)) {
    echo "ERROR: Order Failure\n";
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
        $log->info("Position reached it's close price, thus Closing.", ['Close Price'=>$tmpLastPrice]);
        $close = $bitmex->closePosition($symbol, null);
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
