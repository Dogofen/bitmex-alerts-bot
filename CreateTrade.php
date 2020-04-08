<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = new Trader($argv);

if (isset($argv[6]) and $argv[6] == "reverse_pos") {
    $trader->reverse_pos();
    exit();
}

if (isset($argv[6]) and $argv[6] == "with_id") {
    $trader->with_id_trade();
    exit();
}

?>
