## workerman react 
create test.php
```php
require_once 'react.php';

// create socket and listen 1234 port
$worker = new Worker("tcp://0.0.0.0:1234");

// when client connect 1234 port
$worker->onConnect = function($connection)
{
    echo "connected\n";
};

// when client send data to 1234 port
$worker->onMessage = function($connection, $data)
{
    $connection->send($data);
};

// when client close connection
$worker->onClose = function($connection)
{
    echo "closed\n";
};

// run worker
$worker->run();
```

run php test.php
