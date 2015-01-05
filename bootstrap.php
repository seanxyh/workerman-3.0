<?php
/**
 * run with command 
 * php bootstrap.php start
 */

ini_set('display_errors', 'on');
use WorkerMan\Worker;

require_once __DIR__ . '/Workerman/Worker.php';

foreach(glob(__DIR__.'/applications/*/bootstrap.php') as $bootstrap_file)
{
    require_once $bootstrap_file;;
}

Worker::runAll();