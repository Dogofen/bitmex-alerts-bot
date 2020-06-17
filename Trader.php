<?php

require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");


class Trader {

    const TICKER_PATH = '.ticker';
    const DATA_PATH = '.data.json';

    private $log;
    private $bitmex;

    private $env;
    private $tradeSymbols = array('XBTUSD', 'ETHUSD','XRPUSD');
    private $tradeTypes = array('Buy', 'Sell');
    private $pid;
    private $data;
    private $ichimokuMacDTradeIndicator;

    private $symbol;
    private $stopLossInterval;
    private $targetPercent;
    private $type;
    private $amount;
    private $strategy;


    public function __construct($argv) {
        $config = include('config.php');
        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        $this->ichimokuMacDTradeIndicator = $config['ichimokuMacDTradeIndicator'];
        $this->signalsTimeCondition =  $config['signalsTimeCondition'];
        if (file_exists(SELF::DATA_PATH)){
            $objData = file_get_contents(SELF::DATA_PATH);
            $this->data = unserialize($objData);
        }
        else {
            $this->data = array(
                'XBTUSD' => array(
                    "ichimokuMacDBuyIndicators"  => array(),
                    "ichimokuMacDSellIndicators" => array()
                ),
                'XRPUSD' => array(
                    "ichimokuMacDBuyIndicators"  => array(),
                    "ichimokuMacDSellIndicators" => array()
                ),
                'ETHUSD' => array(
                    "ichimokuMacDBuyIndicators"  => array(),
                    "ichimokuMacDSellIndicators" => array()
                )
            );
        }

        if (isset($argv[6])) {
            $this->strategy = $argv[6];
        }
        $this->log = create_logger(getcwd().'/Trades_'.$this->strategy.'.log');
        $this->symbol = $this->tradeSymbols[intval($argv[1])];
        try {
            $this->bitmex->setLeverage($config['leverage'], $this->symbol);
        } catch (Exception $e) {
            $this->log->error("Network failure to set leverage.", ["continue"=>True]);
        }

        $this->stopLossInterval = -$argv[2];
        $this->target = floatval($argv[3]) / 100;
        $this->type = $argv[4];
        $this->amount = $argv[5];


        if (isset($argv[7])) {
            $this->pid = $argv[7];
        }

        if ($config['testnet']) {
            $this->env = 'test';
        }
        else {
            $this->env = 'prod';
        }
        if (!(strpos($argv[8], $this->env) !== false)) {
            $this->log->warning("Trade command does not fit the enviroment, exiting.", ['command'=>$argv]);
            throw new Exception("Wrong enviroment.");
        }
    }

    public function __destruct() {
        $objData = serialize($this->data);
        file_put_contents(SELF::DATA_PATH, $objData);
    }


    public function is_buy() {
        return $this->type == 'Buy' ? 1:0;
    }

    public function get_opposite_trade_type() {
        return $this->type == "Buy" ? "Sell": "Buy";
    }

    public function get_ticker() {
        do {
            $ticker = unserialize(file_get_contents(self::TICKER_PATH.$this->symbol.'.txt'));
            if ($ticker['last'] == null) {
                $this->log->error("ticker is error, retrying in 3 seconds", ['ticker'=>$ticker]);
                sleep(3);
            }
        } while ($ticker['last'] == null);
        return $ticker;
    }

    public function get_open_order_type(){
        if (preg_match('/'.$this->get_opposite_trade_type().'/',shell_exec('ps -ax|grep '.$this->strategy))) {
            return $this->get_opposite_trade_type();
        }
        if (preg_match_all('/'.$this->type.'/',shell_exec('ps -ax|grep '.$this->strategy)) == 2) {
            return $this->type;
        }
        else {
            return False;
        }
    }

    public function get_liquidation_price() {
        do {
            $positions = $this->bitmex->getOpenPositions();
            if (!is_array($positions)) {
                sleep(2);
                continue;
            }
            foreach($positions as $position) {
                if ($position["symbol"] == $this->symbol) {
                    return $position["liquidationPrice"];
                }
            }
            $this->log->error("Failed to get position's liquidation price, retrying in 2 seconds.", ['error'=>$positions]);
            sleep(2);
        } while (1);
    }

    public function true_create_order($type, $amount) {
        $this->log->info("Sending a Create Order command", ['type'=>$type.' '.$amount.' contracts']);
        do {
            try {
                $order = $this->bitmex->createOrder($this->symbol, "Market", $type, null, $amount);
            } catch (Exception $e) {
                if (strpos($e, 'Invalid orderQty') !== false) {
                    $this->log->error("Failed to sumbit, Invalid quantity", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'insufficient Available Balance') !== false) {
                    $this->log->error("Failed to sumbit, insufficient Available Balance", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'Invalid API Key') !== false) {
                    $this->log->error("Failed to sumbit, Invalid API Key", ['error'=>$e]);
                    return false;
                }
                $this->log->error("Failed to create/close position retrying in 3 seconds", ['error'=>$e]);
                sleep(3);
                continue;
            }
            $this->log->info("Position has been created on Bitmex.", ['Strategy'=>$this->strategy.' '.$this->type]);
            $this->log->info("Position successful, OrderId:".$order['orderID'], ['price'=>$order['price']]);
            break;
        } while (1);
        return true;
    }

    public function range_trade() {
        $rangeTrade = $this->strategy.'_'.$this->type.'_'.$this->pid;
        $oppositeRangeTrade = $this->strategy.'_'.$this->get_opposite_trade_type().'_'.$this->pid;

        if (file_exists($rangeTrade)) {
            exit();
        }
        shell_exec('touch '.$rangeTrade);
        sleep(5);
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $percentage = 0.4;

        $tradeArray = array();
        $result = False;
        $openPrice = $this->get_ticker()['last'];
        $tradeInterval =  $this->is_buy() ? $openPrice * $this->target : - $openPrice * $this->target;
        $target = $openPrice + $tradeInterval;
        $takeProfit = array(abs($tradeInterval), $percentage * $this->amount);

        if ($this->true_create_order($this->type, $this->amount) == false) {
            $this->log->error("Failed to create order", ['type'=>$this->type]);
        }
        $this->log->info("range trade opened profit will be taken at price: ".$target, ['Open Price'=>$openPrice]);

        do {
            $lastPrice = $this->get_ticker()['last'];
            $openProfit = $this->is_buy() ? $lastPrice - $openPrice: $openPrice - $lastPrice;

            if (file_exists($oppositeRangeTrade)) {
                $this->log->info("range arrived to target", ["openProfit"=>$openProfit]);
                $this->true_create_order($this->get_opposite_trade_type($type), $this->amount);
                shell_exec('rm '.$rangeTrade);
            }
            if ($openProfit < $this->stopLossInterval) {
                $this->log->info("Position reached Stop Loss level, thus Closing.", ['Close Price'=>$lastPrice]);
                $this->true_create_order($this->get_opposite_trade_type($type), $this->amount);
                $this->log->info("Range was terminated successfully", ['closePrice'=>$lastPrice]);
                shell_exec('touch '.$oppositeRangeTrade);
                break;
            }
            if ($openProfit > $takeProfit[0]) {
                $this->log->info("A Target was reached", ['target'=>$takeProfit[0]]);
                $this->true_create_order($this->get_opposite_trade_type($type), $takeProfit[1]);
                $this->amount = $this->amount - $takeProfit[1];
                $takeProfit[0] = 99999;
            }
            sleep(1);
        } while (file_exists($rangeTrade));
    }

    public function trade_open_and_manage() {
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $percentage1 = 0.3;
        $percentage2 = 0.2;

        $tradeArray = array();
        $result = False;
        $openPrice = $this->get_ticker()['last'];
        $tradeInterval =  $this->is_buy() ? $openPrice * $this->target : - $openPrice * $this->target;
        $target = $openPrice + $tradeInterval;
        $takeProfit = array(
            array(abs(0.500 * $tradeInterval), $percentage1 * $this->amount),
            array(abs(0.618 * $tradeInterval), $percentage1 * $this->amount),
            array(abs(0.786 * $tradeInterval), $percentage2 * $this->amount),
            array(abs($tradeInterval), $percentage2 * $this->amount),
            );

        if ($this->true_create_order($this->type, $this->amount) == false) {
            $this->log->error("Failed to create order", ['type'=>$this->type]);
        }
        $this->log->info("Target is at price: ".$target, ['Open Price'=>$openPrice]);
        $this->log->info("Interval is", ['Interval'=>$takeProfit]);
        $profitCounter = 0;
        $tradeArray = array(
            "openPrice"        => $openPrice,
            "takeProfit"       => $takeProfit,
            "stopLoss"         => $this->stopLossInterval
        );
        file_put_contents($this->strategy."_".$this->type."_".$this->pid, serialize($tradeArray));

        do {
            $profitPair = $takeProfit[$profitCounter];
            $lastPrice = $this->get_ticker()['last'];
            $openProfit = $this->is_buy() ? $lastPrice - $openPrice: $openPrice - $lastPrice;
            $params = unserialize(file_get_contents($this->strategy."_".$this->type."_".$this->pid));
            if ($params != $tradeArray) {
                $tradeArray = $params;
                $this->log->info("New params loaded to trade.", ["tradeArray"=>$tradeArray]);
                $takeProfit = $tradeArray["takeProfit"];
                $stopLoss = $tradeArray["stopLoss"];
            }

            if (file_exists("close_".$this->strategy)) {
                $this->log->info("Trade Process got an outside close signal", ["openProfit"=>$openProfit]);
                $openProfit = $this->stopLossInterval - 1;
                shell_exec('rm close_'.$this->strategy);
            }
            if ($openProfit < $stopLoss) {
                $this->log->info("Position reached Stop Loss level, thus Closing.", ['Close Price'=>$lastPrice]);
                $this->true_create_order($this->get_opposite_trade_type($type), $this->amount);
                $this->log->info("Trade has closed successfully", ['closePrice'=>$lastPrice]);
                break;
            }
            if ($openProfit > $profitPair[0]) {
                $this->log->info("A Target was reached", ['target'=>$profitPair[0]]);
                $this->true_create_order($this->get_opposite_trade_type($type), $profitPair[1]);
                $this->amount = $this->amount - $profitPair[1];
                $profitCounter = ++$profitCounter;
            }
            sleep(1);
        } while ($this->amount > 0);
        shell_exec("rm ".$this->strategy."_".$this->type."_".$this->pid);
    }

    public function ichimoku_macd() {
        $ichimokuMacDSignalArray;

        if ($this->is_buy()) {
            $ichimokuMacDSignalArray = &$this->data[$this->symbol]['ichimokuMacDBuyIndicators'];
        } else { $ichimokuMacDSignalArray = &$this->data[$this->symbol]['ichimokuMacDSellIndicators'];}

        array_push($ichimokuMacDSignalArray, microtime(true));

        foreach ($ichimokuMacDSignalArray as $signal) {
            if (microtime(true) - $signal > $this->signalsTimeCondition) {
                unset($ichimokuMacDSignalArray[array_search($signal, $ichimokuMacDSignalArray)]);
                $this->log->info("a signal was unset from ".$this->symbol." array.", ["diff"=>microtime(true) - $signal]);
            }
        }

        $this->log->info("ichimoku macd trade ".$this->type." ".$this->symbol." trade got a signal and inserted into array.", ["signals"=>sizeof($ichimokuMacDSignalArray)]);

        if (sizeof($ichimokuMacDSignalArray) < $this->ichimokuMacDTradeIndicator) {
            return False;
        }
        $this->trade_open_and_manage();
    }

    public function trend_line_alert() {
        $this->trade_open_and_manage();
    }

    public function anti_liquidation() {
        if ($this->true_create_order($this->type, $this->amount) == false) {
            $this->log->error("Anti liquidation Trade failed to create order", ['type'=>$this->type]);
            return False;
        }
        $liquidationPrice = $this->get_liquidation_price();
        $this->log->info("Anti liquidation position is being created.", ['liquidationPrice'=>$liquidationPrice]);
        $openPosition = True;
        do {
            if (file_exists("close_".$this->strategy)) {
                $openPosition = False;
            }
            $lastPrice = $this->get_ticker()['last'];
            $liquidationIndicator =  abs($lastPrice - $liquidationPrice);
            if (($liquidationIndicator + $this->stopLossInterval) < 0) {
                 $this->log->info("We have reached liquidation area and Creating a new ".$this->type." order.", ['liquidationIndicator'=>$liquidationIndicator]);
                 if ($this->true_create_order($this->type, $this->amount) == false) {
                     $this->log->error("Anti liquidation Trade failed to create order", ['type'=>$this->type]);
                     return False;
                 }
                 $liquidationPrice = $this->get_liquidation_price();
                 $this->log->info("New liquidation Price is.", ["liquidationPrice"=>$liquidationPrice]);
            }
            sleep(1);

        } while ($openPosition);
        shell_exec('rm close_'.$this->strategy);
    }

}
?>
