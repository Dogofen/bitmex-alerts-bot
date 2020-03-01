<?php
require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");
$config = include('config.php');
$tickerFile =  getcwd().'/.ticker.txt';

$logPath = getcwd().'/Trades.log';
$log = create_logger($logPath);

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
$strategies = array('only_execute', 'reverse_pos');
$pid = false;

if (isset($argv[1]) and intval($argv[1]) <= sizeof($tradeSymbols)) {
    $symbol = $tradeSymbols[intval($argv[1])];
}
else {
    $log->error($argv[1].' is not a valid symbol');
    exit();
}
if (isset($argv[2])) {
    $stopLossInterval = -$argv[2];
}
if (isset($argv[3]) and 0.001 < $argv[3] and $argv[3] < 100) {
    $targetPercent = floatval($argv[3]) / 100;
}
else {
    $log->error($argv[3]. ' is not a valid target');
    exit();
}
if (isset($argv[4]) and in_array($argv[4], $tradeTypes)) {
    $type = $argv[4];
}
else {
    $log->error($argv[4]. ' is not a valid trade type');
    exit();
}
if (isset($argv[5])) {
    $amount = $argv[5];
}
else {
     $log->error('not a valid amount');
     exit();
}
if (isset($argv[6]) and $argv[6] == "only_execute" and isset($argv[7])) {
    $pid = explode('_', $argv[7])[1];
    if (strpos($argv[7], 'longSig') !== false or strpos($argv[7], 'shortSig') !== false) {
        if (!file_exists(getcwd().'/long_'.$pid) and !file_exists(getcwd().'/short_'.$pid)) {
            $fileName = strpos($argv[7], 'longSig') !== false ? getcwd().'/long_'.$pid:getcwd().'/short_'.$pid;
            try {
                $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
            } catch (Exception $e) {
                $log->error("Failed to create/close position", ['error'=>$e]);
            }
            shell_exec('touch '.$fileName);
            exit();
        }
        else {
            $log->warning("Trade is already opened", ['pid'=>$pid]);
            exit();
        }
    }
    if (strpos($argv[7], 'closeShort') !== false and file_exists(getcwd().'/short_'.$pid) or strpos($argv[7], 'closeLong') !== false and file_exists(getcwd().'/long_'.$pid)) {
        $fileName = strpos($argv[7], 'closeLong') !== false ? getcwd().'/long_'.$pid:getcwd().'/short_'.$pid;
        try {
            $order = $bitmex->createOrder($symbol, "Market",$type, null, $amount);
        } catch (Exception $e) {
            $log->error("Failed to create/close position", ['error'=>$e]);
        }
        shell_exec('rm '.$fileName);
        exit();
    }
    else {
        $log->warning("No Openned trades or mismatch", ['pid'=>$pid]);
        exit();
    }
}
if (isset($argv[6]) and $argv[6] == "reverse_pos") {
    $pid = $argv[7];
    $currentFileName = 'reverse_'.get_opposite_trade_type($type).'_'.$pid;
    $nextFileName = 'reverse_'.$type.'_'.$pid;
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
        exit();
    }
}

if (isset($argv[6]) and $argv[6] == "with_id") {
    $pid = $argv[7];
    $currentFileName = $type.'_with_id_'.$pid;
    if (file_exists($currentFileName)) {
        exit();
    }
    else {
        shell_exec('touch '.$currentFileName);
    }
}
$bitmex->setLeverage($config['leverage'], $symbol);

$result = False;
$openPrice = get_ticker($tickerFile);
$tradeInterval =  is_buy($type) ? $openPrice * $targetPercent : - $openPrice * $targetPercent;
$target = $openPrice + $tradeInterval;
$fibArray = array(
    array(abs(0.786*$tradeInterval), abs(0.382*$tradeInterval)),
    array(abs(0.618*$tradeInterval), abs(0.236*$tradeInterval)),
    array(abs($stopLossInterval), 0)
);

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
$log->info("Interval is", ['Interval'=>$fibArray]);
$profitPair = array_pop($fibArray);
do {
    $tmpLastPrice = get_ticker($tickerFile);
    $openProfit = is_buy($type) ? $tmpLastPrice - $openPrice: $openPrice - $tmpLastPrice;
    if ($tmpLastPrice <= $target and !is_buy($type) or $tmpLastPrice >= $target and is_buy($type)) {
            $stopLossInterval = $openProfit*2;
            $log->info("Target was reached, setting new stop loss interval", ['lastPrice'=>$tmpLastPrice]);
    }
    if ($openProfit < $stopLossInterval) {
        $log->info("Position reached it's close price, thus Closing.", ['Stop Loss'=>$tmpLastPrice]);
        do {
            try {
                $close = $bitmex->createOrder($symbol, "Market",get_opposite_trade_type($type), null, $amount);
            } catch (Exception $e) {
                $log->error("Failed closing positions", ['error'=>$e]);
            }
        } while ($close == False);
        if ($pid != false) {
            shell_exec('rm '.$type.'_with_id_'.$pid);
        }
        $log->info("Trade has closed successfully", ['info'=>$close]);
        exit();
    }
    if ($openProfit > $profitPair[0] and is_array($fibArray)) {
        $stopLossInterval = $profitPair[1];
        $log->info("Position reached Profits that are bigger than threshold.", ['Stop Loss profits'=>$stopLossInterval]);
        if (sizeof($fibArray) > 0) {
            $profitPair = array_pop($fibArray);
        }
        else {
            $fibArray = false;
        }
    }
    sleep(1);
} while (1);

?>
