## workerman react 
create test.php
```php
require_once 'react.php';

$worker = new Worker("tcp://0.0.0.0:1234");
$worker->onConnect = function($connection)
{
    echo "connected\n";
};
$worker->onMessage = function($connection, $data)
{
    $connection->send($data);
};
$worker->onClose = function($connection)
{
    echo "closed\n";
};
$worker->run();
```

run php test.php
