<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("BitMex.php");
$config = include('config.php');

function is_buy($type) {
    return $type == 'Buy' ? 1:0;
}

function get_open_positions_by_symbol($bitmex, $symbol, $log) {
    $positions = False;
    do {
        try {
            $positions = $bitmex->getOpenPositions();
        } catch (Exception $e) {
            $log->error("Failed retrieving open positions", ['error'=>$e]);
        }
        if(!is_array($positions)) {
            $log->error("Failed retrieving open positions", ['posistion'=>$position]);
            sleep(2);
        }
    } while (!is_array($positions));
    foreach($positions as $pos) {
        if($pos["symbol"] == $symbol) {
            return $pos;
        }
    }
    return False;
}

function get_opposite_trade_type($type) {
    return $type == "Buy" ? "Sell": "Buy";
}

$tradeSymbols = array('XBTUSD', 'ETHUSD','XBT7D_U105', 'ADAZ19', 'BCHZ19', 'EOSZ19', 'LTCZ19', 'TRXZ19', 'XRPZ19');
$tradeTypes = array('Buy', 'Sell');

if (isset($argv[1]) and intval($argv[1]) <= sizeof($tradeSymbols)) {
    $symbol = $tradeSymbols[intval($argv[1])];
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

$timestamp = date("Y-m-d_H:i:s");
$logPath = is_buy($type) ? getcwd().'/long'.$timestamp.'.log' : getcwd().'/short'.$timestamp.'.log';
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

$bitmex = new BitMex($config['key'],$config['secret']);
$bitmex->setLeverage($config['leverage'], $symbol);

$result = False;
do {
    try {
        $result = $bitmex->getTicker($symbol);
    } catch (Exception $e) {
        $log->error("Failed retrieving ticker", ['error'=>$e]);
    }
} while ($result == False);
$lastPrice = $result["last"];
$interval = $lastPrice * $intervalPercentage;

$target = is_buy($type) ? $lastPrice + $lastPrice * $targetPercent :  $lastPrice - $lastPrice * $targetPercent;

$order = False;
do {
    try {
        $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
    } catch (Exception $e) {
        $log->error("Failed Creating position", ['error'=>$e]);
    }

} while ($order == False);
if (!is_array($order) or is_array($order) and empty($order)) {
    $log->error("Order Failure", ['order'=>$order]);
    exit();
}

sleep(5);

$log->info("Position has been created on Bitmex", ['info'=>$order]);
$log->info("Target is at price", ['Target'=>$target]);
$log->info("Interval is", ['Interval'=>$interval]);

$close = is_buy($type) ? $lastPrice - $interval :  $lastPrice + $interval;
$log->info("Setting current Stoploss price", ['Close Price'=>$close]);

$flag = 0;
do {
    $position = get_open_positions_by_symbol($bitmex, $symbol, $log);
    $result = False;
    do {
        try {
            $result = $bitmex->getTicker($symbol);
        } catch (Exception $e) {
            $log->error("Failed retrieving ticker", ['error'=>$e]);
        }
    } while ($result == False);


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
            try {
                $close = $bitmex->createOrder($symbol, "Market",get_opposite_trade_type($type), null, $amount);
            } catch (Exception $e) {
                $log->error("Failed closing positions", ['error'=>$e]);
            }

        } while ($close == False);
        $log->info("Trade has closed successfully", ['info'=>$close]);
        exit();
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
} while (1);
$log->info("Postion was closed outside the function thus exiting", ['Close Price'=>$close]);

?>
