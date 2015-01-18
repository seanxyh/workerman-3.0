<?php 
require_once __DIR__ . '/Bootstrap/Gateway.php';
require_once __DIR__ . '/Bootstrap/BusinessWorker.php';
require_once __DIR__ . '/Bootstrap/WebServer.php';

// gateway
$gateway = new Gateway("Websocket://0.0.0.0:8282");

$gateway->name = 'TodpoleGateway';

$gateway->count = 4;

$gateway->lanIp = '127.0.0.1';

$gateway->startPort = 4000;

$gateway->pingInterval = 10;

$gateway->pingData = '{"type":"ping"}';


// bussinessWorker
$worker = new BusinessWorker();

$worker->name = 'TodpoleBusinessWorker';

$worker->count = 4;


// WebServer
$web = new WebServer("http://0.0.0.0:8383");

$web->user = 'www-data';

$web->count = 2;

$web->addRoot('www.your_domain.com', __DIR__.'/Web');
