<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("BitMex.php");


$key = "moHvOy3DuhVqpSuapwmgkMrt";
$secret = "7R1kKlFRZQgILMFlFb4yNKNpf72NEYHbkc-s1xuG6kzeqCKN";
$bitmex = new BitMex($key,$secret);
$result = $bitmex->getTicker();
$lastPrice = $result["last"];
$buyPrice = floatval($lastPrice) - 100;
$order = $bitmex->createOrder("Limit","Sell", $buyPrice,10);
$orderId = $order['orderID'];

$startTime = microtime(true);
$log = new Logger('BOT');
$log->pushHandler(new StreamHandler(getcwd().'/short'.$orderId.'.log', Logger::DEBUG));
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

$log->info("Short position has been created on Bitmex", ['info'=>$order]);
if (isset($argv[1])) {
    $interval = $lastPrice * floatval($argv[1]);
}
else {
    $log->error("CreateShort has failed to find interval");
    exit();
}
$close = $lastPrice + $interval;
$log->info("Setting current Close price", ['Close Price'=>$close]);

while(1) {
    $result = $bitmex->getTicker();
    $tmpLastPrice = $result["last"];
    if ($tmpLastPrice > $close) {
        $log->info("Position reached it's close price, thus Closing.", ['Close Price'=>$tmpLastPrice]);
        $close = $bitmex->closePosition(null);
        $log->info("Short was closed successfully", ['info'=>$close]);
        break;
    }
    if($tmpLastPrice < $lastPrice) {
        $lastPrice = $tmpLastPrice;
        $close = $lastPrice + $interval;
        $log->info("Price was moved down", ['Price'=>$lastPrice]);
        $log->info("Updating entry to stop loss at price", ['Close Price'=>$close]);
    }
    sleep(5);
}

?>
