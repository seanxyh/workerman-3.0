<?php
require_once './workerman/Worker.php';
use workerman\Worker;

$worker = new Worker("tcp://0.0.0.0:1234");
$worker->count = 4;
/*$worker->onConnect = function($connection)
{
    //echo "connected\n"; 
    //var_dump($connection);
};
*/
$worker->onMessage = function($connection, $data)
{
    $connection->send("HTTP/1.0 200 OK\r\nConnection: Keep-Alive\r\nContent-Type: text/html\r\nContent-Length: 5\r\n\r\nhello");
    //$connection->send("HTTP/1.0 200 OK\r\nContent-Type: text/html\r\nContent-Length: 5\r\n\r\nhello");
    //$connection->close();
};
/*$worker->onClose = function($connection)
{
    //echo "closed\n";
    //var_dump($connection);
};
*/
$worker2 = new Worker("tcp://0.0.0.0:4567");
$worker2->onMessage = function($connection, $data)
{
    $connection->send("**".$data);
};
//$worker->run();
Worker::start();


