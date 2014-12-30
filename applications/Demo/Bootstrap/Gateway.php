<?php 
use Workerman\Worker;
require_once __DIR__ . '/../Lib/Autoloader.php';

// create socket and listen 5678 port
$worker = new Worker("tcp://0.0.0.0:5678");

$worker->name = 'Gateway';

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
