<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = new Trader($argv);

if (isset($argv[6]) and $argv[6] == "reverse_pos") {
    $trader->reverse_pos();
    exit();
}

if (isset($argv[6]) and $argv[6] == "with_id") {
    sleep(2);
    $trader->with_id_trade();
    exit();
}

if (isset($argv[6]) and $argv[6] == "trend_line") {
    $trader->trend_line_alert();
    exit();
}

?>
