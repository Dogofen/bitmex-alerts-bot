<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = new Trader($argv);

if (isset($argv[6]) and $argv[6] == "reverse_pos") {
    $trader->reverse_pos();
    exit();
}

if (isset($argv[6]) and $argv[6] == "ichimoku_macd") {
    $trader->ichimoku_macd();
    exit();
}

if (isset($argv[6]) and $argv[6] == "trend_line") {
    $trader->trend_line_alert();
    exit();
}

if (isset($argv[6]) and $argv[6] == "anti_liq") {
    $trader->anti_liquidation();
    exit();
}

?>
