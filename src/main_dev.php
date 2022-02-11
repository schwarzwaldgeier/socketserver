#!/usr/bin/env php
<?php

namespace Schwarzwaldgeier\WetterSocket;

use Navarr\Socket\Exception\SocketException;


$dir = dirname(__FILE__);
require_once "socket.php";
require_once "$dir/../vendor/autoload.php";


$port = 7977;
if (isset($argv[2])){
    $port = $argv[2];
}
$ip = '192.168.111.11';
if (isset($argv[1])){
    $ip = $argv[1];
}


try {
    date_default_timezone_set('Europe/Berlin');

    println("Starting server on $ip:$port");
    $server = new WetterSocket($ip, $port);

    $server->run();


} catch (SocketException $e) {
    error_log($e->getMessage());
    die();
}


function println($msg)
{
    echo $msg . PHP_EOL;
}

