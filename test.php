<?php
ini_set('display_errors', 'on');

require_once './Workerman/Worker.php';
use Workerman\Worker;

// create socket and listen 1234 port
$worker = new Workerman\Worker("tcp://0.0.0.0:1234");

// create 4 processes
$worker->count = 4;

// when client connect 1234 port
$worker->onConnect = function($connection)
{
    echo "client " . $connection->getRemoteIp() . " connected\n";
};

// when client send data to 1234 port
$worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send($data);
};

// when client close connection
$worker->onClose = function($connection)
{
    echo "client closed\n";
};
