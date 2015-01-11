<?php 
require_once __DIR__ . '/Bootstrap/Gateway.php';
require_once __DIR__ . '/Bootstrap/BusinessWorker.php';

// gateway
$gateway = new Gateway("Websocket://0.0.0.0:7272");

$gateway->name = 'TodpoleGateway';

$gateway->count = 4;

$gateway->lanIp = '127.0.0.1';

$gateway->startPort = 4000;


// bussinessWorker
$worker = new BusinessWorker("tcp://0.0.0.0:7273");

$worker->name = 'TodpoleBusinessWorker';

$worker->count = 4;


// WebServer
$web = new WebServer("http://0.0.0.0:8383");

$web->count = 2;

$web->addRoot('www.your_domain.com', __DIR__.'/../Web');
