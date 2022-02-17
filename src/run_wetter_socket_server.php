#!/usr/bin/env php
<?php

namespace Schwarzwaldgeier\WetterSocket;

use Navarr\Socket\Exception\SocketException;

pcntl_async_signals(true);
$dir = dirname(__FILE__);
require_once "WetterSocket.php";
require_once "$dir/../vendor/autoload.php";


$port = 7977;
if (isset($argv[2])){
    $port = $argv[2];
}
$ip = '192.168.111.11';
if (isset($argv[1])){
    $ip = $argv[1];
}
$debug = false;
if (in_array("--debug", $argv)){
    echo "Debug mode enabled" . PHP_EOL;
    $debug = true;
}


try {
    date_default_timezone_set('Europe/Berlin');

    println("Starting server on $ip:$port");
    $saveFileDir = getenv("HOME");
    $savefile = $saveFileDir . "/wetter_socket_state.json" ;
    if (!is_writable($saveFileDir)){
        error_log("$savefile not writeable");
    }

    $server = new WetterSocket($ip, $port, $debug, $savefile);

    $server->run();



} catch (SocketException $e) {
    error_log($e->getMessage());
    die();
}


function println($msg)
{
    echo $msg . PHP_EOL;
}

