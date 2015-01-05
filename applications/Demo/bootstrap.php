<?php 
require_once __DIR__ . '/Bootstrap/Gateway.php';
require_once __DIR__ . '/Bootstrap/BusinessWorker.php';

$gateway = new Gateway("tcp://0.0.0.0:8480");

$gateway->name = 'Gateway';

$gateway->count = 4;

$gateway->lanIp = '127.0.0.1';

$gateway->startPort = 4000;



$worker = new BusinessWorker("tcp://0.0.0.0:8481");

$worker->name = 'BusinessWorker';

$worker->count = 4;

