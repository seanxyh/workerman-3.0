## workerman react 
create test.php
```php
require_once './Workerman/Worker.php';

// create socket and listen 1234 port
$worker = new Workerman\Worker("tcp://0.0.0.0:1234");

// when client connect 1234 port
$worker->onConnect = function($connection)
{
    echo "client connected\n";
};

// when client send data to 1234 port
$worker->onMessage = function($worker, $connection, $data)
{
    // send data to client
    $connection->send($data);
};

// when client close connection
$worker->onClose = function($connection)
{
    echo "client closed\n";
};

// run worker
$worker->run();
```

run width
php test.php start
