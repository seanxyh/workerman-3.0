<?php
namespace Workerman;

ini_set('display_errors', 'on');

require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Events/BaseEvent.php';
require_once __DIR__ . '/Events/Select.php';
require_once __DIR__ . '/Events/Libevent.php';

use workerman\Events\Libevent;
use workerman\Events\Select;
use workerman\Events\BaseEvent;

class Worker extends Connection
{
    public $count = 1;

    protected static $pidMap = array();

    protected static $workers = array();

    public static function start($daemonize = false)
    {
        $daemonize && self::daemonize();
        self::createWorkers();
        self::monitorWorkers();
    }

    protected static function daemonize()
    {
        umask(0);
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            throw new \Exception("Daemonize fail ,can not fork");
        }
        elseif($pid > 0)
        {
            exit(0);
        }
        if(-1 == posix_setsid())
        {
            throw new \Exception("Daemonize fail ,setsid fail");
        }
        
        $pid2 = pcntl_fork();
        if(-1 == $pid2)
        {
            throw new \Exception("Daemonize fail ,can not fork");
        }
        elseif(0 !== $pid2)
        {
            exit(0);
        }
    }


    protected static function resetStd()
    {
        
    }


    protected static function createWorkers()
    {
        foreach(self::$workers as $address=>$worker)
        {
            while(count(self::$pidMap[$address]) < $worker->count)
            {
                self::forkOneWorker($worker);
            }
        }
    }

    protected static function forkOneWorker($worker)
    {
        $pid = pcntl_fork();
        if($pid > 0)
        {
            self::$pidMap[$worker->address][$pid] = $pid;
        }
        elseif(0 === $pid)
        {
            self::$pidMap = self::$workers = array();
            $worker->run();
            exit(250);
        }
        else
        {
            throw new \Exception("forkOneWorker fail");
        }
    }

    protected static function monitorWorkers()
    {
        while(1)
        {
            $pid = pcntl_wait($status, WUNTRACED);
            if($pid > 0)
            {
                foreach(self::$pidMap as $address => $pid_array)
                {
                    if(isset($pid_array[$pid]))
                    {
                        unset(self::$pidMap[$address][$pid]);
                        break;
                    }
                }
                self::createWorkers();
            }
        }
    }
    
    public function __construct($address)
    {
        $this->socket = stream_socket_server($address, $errno, $errmsg);
        if(!$this->socket)
        {
            throw new \Exception($errmsg);
        }
        self::$workers[$address] = $this;
        self::$pidMap[$address] = array();
        $this->address = $address;
    }
    
    public function run()
    {
        if(!self::$globalEvent)
        {
            if(extension_loaded('libevent'))
            {
                self::$globalEvent = new Libevent();
            }
            else
            {
                self::$globalEvent = new Select();
            }
        }
        self::$globalEvent->add($this->socket, BaseEvent::EV_READ, array($this, 'accept'));
        self::$globalEvent->loop();
    }

    public function accept($socket)
    {
        $new_socket = @stream_socket_accept($socket, 0);
        if(false === $new_socket)
        {
            return;
        }
        stream_set_blocking($new_socket, 0);
        $connection = new Connection($new_socket);
        $connection->join();
        if($this->onMessage)
        {
            $connection->onMessage = $this->onMessage;
        }
        if($this->onClose)
        {
            $connection->onClose = $this->onClose;
        }
        if($this->onConnect)
        {
            $func = $this->onConnect;
            $func($connection);
        }
    }
}
