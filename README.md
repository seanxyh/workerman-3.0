## workerman 3.0 
create test.php
```php
require_once './Workerman/Worker.php';
use Workerman\Worker;

// create socket and listen 1234 port
$hello_worker = new Worker("tcp://0.0.0.0:1234");

// 4 hello_worker processes
$hello_worker->count = 4;

// when client send data to 1234 port
$hello_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello $data \n");
};

// another worker
$hi_worker = new Worker("tcp://0.0.0.0:5678");
$hi_worker->count = 4;
$hi_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hi $data \n");
};


// run all workers
Worker::runAll();
```

run width
php test.php start

## available commands
php test.php stop  
php test.php restart  
php test.php status  
php test.php reload  

