<?php
require __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once("BitMex.php");
$config = include('config.php');
$tickerFile =  getcwd().'/.ticker.txt';

$timestamp = date("Y-m-d_H:i:s");
$logPath = getcwd().'/trade'.$timestamp.'.log';
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
function get_ticker($file) {
    $ticker = fopen($file, "r");
    $lastPrice = floatval(fread($ticker, filesize($file)));
    fclose($ticker);
    return $lastPrice;
}

$tradeSymbols = array('XBTUSD', 'ETHUSD','XBT7D_U105', 'ADAZ19', 'BCHZ19', 'EOSZ19', 'LTCZ19', 'TRXZ19', 'XRPZ19');
$tradeTypes = array('Buy', 'Sell');
$strategies = array('force_close', 'only_execute', 'reverse_pos');

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
     echo 'Error: not a valid amount'."\n";
     exit();
}

if (isset($argv[6]) and $argv[6] == "force_close") {
    $newIntervalFlag = 0.0001;
}
else {
    $newIntervalFlag = 0.182;
}
if (isset($argv[6]) and $argv[6] == "only_execute" and isset($argv[7])) {
    $pid = explode('_', $argv[7])[1];
    if (strpos($argv[7], 'longSig') !== false or strpos($argv[7], 'shortSig') !== false) {
        if (!file_exists(getcwd().'/long_'.$pid) and !file_exists(getcwd().'/short_'.$pid)) {
            $fileName = strpos($argv[7], 'longSig') !== false ? getcwd().'/long_'.$pid:getcwd().'/short_'.$pid;
            shell_exec('touch '.$fileName);
            try {
                $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
            } catch (Exception $e) {
                $log->error("Failed to create/close position", ['error'=>$e]);
            }
            exit();
        }
        else {
            $log->warning("Trade is already opened", ['pid'=>$pid]);
            exit();
        }
    }
    if (strpos($argv[7], 'closeShort') !== false and file_exists(getcwd().'/short_'.$pid) or strpos($argv[7], 'closeLong') !== false and file_exists(getcwd().'/long_'.$pid)) {
        $fileName = strpos($argv[7], 'closeLong') !== false ? getcwd().'/long_'.$pid:getcwd().'/short_'.$pid;
        shell_exec('rm '.$fileName);
        try {
            $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
        } catch (Exception $e) {
            $log->error("Failed to create/close position", ['error'=>$e]);
        }
        exit();
    }
    else {
        $log->warning("No Openned trades or mismatch", ['pid'=>$pid]);
        exit();
    }
}
if (isset($argv[6]) and $argv[6] == "reverse_pos") {
    $currentFileName = 'reverse_'.get_opposite_trade_type($type);
    $nextFileName = 'reverse_'.$type;
    if (file_exists($currentFileName)) {
        try {
            $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount*2);
            sleep(1);
        } catch (Exception $e) {
            $log->error("Failed to create/close position", ['error'=>$e]);
        }
        shell_exec('rm '.$currentFileName);
        shell_exec('touch '.$nextFileName);
        exit();
    }
    elseif (!file_exists($nextFileName)) {
        try {
            $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
            sleep(1);
        } catch (Exception $e) {
            $log->error("Failed to create/close position", ['error'=>$e]);
        }
        shell_exec('touch '.$nextFileName);
        exit();
    }
    else {
        $log->warning("Reverse possision of this type exists", ['type'=>$type]);
        exit();
    }
}



$bitmex->setLeverage($config['leverage'], $symbol);

$result = False;
$lastPrice = get_ticker($tickerFile);

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


$log->info("Position has been created on Bitmex", ['info'=>$order]);
$log->info("Target is at price", ['Target'=>$target]);
$log->info("Interval is", ['Interval'=>$interval]);

$close = is_buy($type) ? $lastPrice - $interval :  $lastPrice + $interval;
$log->info("Setting current Stoploss price", ['Stop Loss'=>$close]);

do {

    $tmpLastPrice = get_ticker($tickerFile);
    if($tmpLastPrice <= $target and !is_buy($type) or $tmpLastPrice >= $target and is_buy($type)) {
        if ($newIntervalFlag) {
            $interval = $interval * $newIntervalFlag;
            $log->info("Target was reached, setting new interval", ['interval'=>$interval]);
            $newIntervalFlag = 0;
        }
    }
    if ($tmpLastPrice > $close and !is_buy($type) or $tmpLastPrice < $close and is_buy($type)) {
        $close = False;
        $log->info("Position reached it's close price, thus Closing.", ['Stop Loss'=>$tmpLastPrice]);
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
        $log->info("Price moved down", ['Last Price'=>$lastPrice]);
        $log->info("Updating entry to stop loss at price", ['Stop Loss'=>$close]);
    }
    if($tmpLastPrice > $lastPrice and is_buy($type)) {
        $lastPrice = $tmpLastPrice;
        $close = $lastPrice - $interval;
        $log->info("Price moved up", ['Last Price'=>$lastPrice]);
        $log->info("Updating entry to stop loss at price", ['Stop Loss'=>$close]);
    }

    sleep(1);
} while (1);

?>
