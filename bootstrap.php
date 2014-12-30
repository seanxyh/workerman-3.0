<?php
/**
 * run with command 
 * php bootstrap.php start
 */

ini_set('display_errors', 'on');
use WorkerMan\Worker;

require_once __DIR__ . '/Workerman/Worker.php';

foreach(glob(__DIR__.'/applications/*/Bootstrap/*.php') as $start_file)
{
    require_once $start_file;;
}

Worker::runAll();