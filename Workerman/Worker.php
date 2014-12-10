<?php
namespace Workerman;

ini_set('display_errors', 'on');

require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/Events/BaseEvent.php';
require_once __DIR__ . '/Events/Select.php';
require_once __DIR__ . '/Events/Libevent.php';

use workerman\Events\Libevent;
use workerman\Events\Select;
use workerman\Events\BaseEvent;
use \Exception;

class Worker 
{
    public $onConnect = null;
    
    public $onMessage = null;
    
    public $onClose = null;
    
    public $count = 1;
    
    public $connections = array();
    
    public static $daemonize = false;
    
    public static $stdoutFile = '/dev/null';
    
    public static $pidFile = '/tmp/workerman.pid';
    
    protected $_mainSocket = null;

    protected static $_workers = array();
    
    protected static $_pidMap = array();

    public static function runAll()
    {
        if(self::$daemonize)
        {
            self::daemonize();
            self::resetStd();
            self::savePid();
        }
        self::createWorkers();
        self::monitorWorkers();
    }

    protected static function daemonize()
    {
         // 设置umask
        umask(0);
        // fork一次
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            // 出错退出
            throw new Exception('fork fail');
        }
        elseif($pid > 0)
        {
            // 父进程，退出
            exit(0);
        }
        // 成为session leader
        if(-1 == posix_setsid())
        {
            // 出错退出
            throw new Exception("setsid fail");
        }
    
        // 再fork一次，防止在符合SVR4标准的系统下进程再次获得终端
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            // 出错退出
            throw new Exception("fork fail");
        }
        elseif(0 !== $pid)
        {
            // 禁止进程重新打开控制终端
            exit(0);
        }
    }


    protected static function resetStd()
    {
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile,"a");
        if($handle) 
        {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile,"a");
            $STDERR = fopen(self::$stdoutFile,"a");
        }
        else
        {
            throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }
    
    public static function savePid()
    {
        $master_pid = posix_getpid();
        // 保存到文件中，用于实现停止、重启
        if(false === @file_put_contents(self::$pidFile, $master_pid))
        {
            throw new Exception('can not save pid to ' . self::$pidFile);
        }
    }

    protected static function createWorkers()
    {
        foreach(self::$_workers as $address=>$worker)
        {
            while(count(self::$_pidMap[$address]) < $worker->count)
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
            self::$_pidMap[$worker->address][$pid] = $pid;
        }
        elseif(0 === $pid)
        {
            self::$_pidMap = self::$_workers = array();
            $worker->run();
            exit(250);
        }
        else
        {
            throw new Exception("forkOneWorker fail");
        }
    }

    protected static function monitorWorkers()
    {
        while(1)
        {
            $pid = pcntl_wait($status, WUNTRACED);
            if($pid > 0)
            {
                foreach(self::$_pidMap as $address => $pid_array)
                {
                    if(isset($pid_array[$pid]))
                    {
                        unset(self::$_pidMap[$address][$pid]);
                        break;
                    }
                }
                self::createWorkers();
            }
        }
    }
    
    public function __construct($address)
    {
        $this->_mainSocket = stream_socket_server($address, $errno, $errmsg);
        if(!$this->_mainSocket)
        {
            throw new Exception($errmsg);
        }
        self::$_workers[$address] = $this;
        self::$_pidMap[$address] = array();
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
        self::$globalEvent->add($this->_mainSocket, BaseEvent::EV_READ, array($this, 'accept'));
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
        $connection = new Connection();
        $connection->socket = $new_socket;
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
