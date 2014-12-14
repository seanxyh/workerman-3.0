<?php
namespace Workerman;

ini_set('display_errors', 'on');

require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/Timer.php';
require_once __DIR__ . '/Events/BaseEvent.php';
require_once __DIR__ . '/Events/Select.php';
require_once __DIR__ . '/Events/Libevent.php';

use workerman\Events\Libevent;
use workerman\Events\Select;
use workerman\Events\BaseEvent;
use \Exception;

class Worker 
{
    const STATUS_STARTING = 1;
    
    const STATUS_RUNNING = 2;
    
    const STATUS_SHUTDOWN = 4;
    
    const STATUS_RELOADING = 8;
    
    const KILL_WORKER_TIMER_TIME = 1;
    
    public $name = '';
    
    public $onConnect = null;
    
    public $onMessage = null;
    
    public $onClose = null;
    
    public $count = 1;
    
    public $logger = null;
    
    public $connections = array();
    
    protected static $masterPid = 0;
    
    public static $daemonize = false;
    
    public static $stdoutFile = '/dev/null';
    
    public static $pidFile = '/tmp/workerman.pid';
    
    public static $globalEvent = null;
    
    protected $_mainSocket = null;

    protected static $_workers = array();
    
    protected static $_pidMap = array();
    
    protected static $_pidsToRestart = array();
    
    protected static $_status = self::STATUS_STARTING;
    

    public static function runAll()
    {
        self::$_status = self::STATUS_STARTING;
        Timer::init();
        self::saveMasterPid();
        self::installSignal();
        if(self::$daemonize)
        {
            self::daemonize();
            self::resetStd();
        }
        self::createWorkers();
        self::$_status = self::STATUS_RUNNING;
        self::monitorWorkers();
    }
    
    protected static function installSignal()
    {
        // stop
        pcntl_signal(SIGTERM,  array('\Workerman\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\Workerman\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\Workerman\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }
    
    public static function signalHandler($signal)
    {
        switch($signal)
        {
            // stop
            case SIGTERM:
                echo "Workerman is shutting down\n";
                self::stopAll();
                break;
            // reload
            case SIGUSR1:
                echo "Workerman reloading\n";
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
            // show status
            case SIGUSR2:
                break;
        }
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
    
    protected static function saveMasterPid()
    {
        self::$masterPid = posix_getpid();
        if(self::$daemonize)
        {
            // 保存到文件中，用于实现停止、重启
            if(false === @file_put_contents(self::$pidFile, self::$masterPid))
            {
                throw new Exception('can not save pid to ' . self::$pidFile);
            }
        }
    }
    
    protected static function getAllWorkerPids()
    {
        $pid_array = array(); 
        foreach(self::$_pidMap as $address => $worker_pid_array)
        {
            foreach($worker_pid_array as $worker_pid)
            {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
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
            self::$_pidMap = array();
            self::$_workers = array($worker->address => $worker);
            Timer::delAll();
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
            $status = 0;
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status, WUNTRACED);
            if($pid > 0)
            {
                foreach(self::$_pidMap as $address => $worker_pid_array)
                {
                    if(isset($worker_pid_array[$pid]))
                    {
                        if($status !== 0)
                        {
                            echo "worker[".self::$_workers[$address]->name.":$pid] exit with status $status\n";
                        }
                        if(isset(self::$_pidsToRestart[$pid]))
                        {
                            unset(self::$_pidsToRestart[$pid]);
                            self::reload();
                        }
                        unset(self::$_pidMap[$address][$pid]);
                        break;
                    }
                }
                if(self::$_status !== self::STATUS_SHUTDOWN)
                {
                    self::createWorkers();
                }
                else
                {
                    $all_worker_pids = self::getAllWorkerPids();
                    if(empty($all_worker_pids))
                    {
                        exit(0);
                    }
                }
            }
        }
    }
    
    protected static function reload()
    {
        // set status
        if(self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN)
        {
            self::$_status = self::STATUS_RELOADING;
        }
        // reload complete
        if(empty(self::$_pidsToRestart))
        {
            if(self::$_status !== self::STATUS_SHUTDOWN)
            {
                self::$_status = self::STATUS_RUNNING;
            }
            return;
        }
        // continue reload
        $one_worker_pid = current(self::$_pidsToRestart );
        posix_kill($one_worker_pid, SIGUSR1);
        Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($one_worker_pid, SIGKILL), false);
    } 
    
    protected static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // for master process
        if(Worker::$masterPid === posix_getpid())
        {
            $worker_pid_array = self::getAllWorkerPids();
            foreach($worker_pid_array as $worker_pid)
            {
                posix_kill($worker_pid, SIGINT);
                Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($worker_pid, SIGKILL),false);
            }
        }
        // for worker process
        else
        {
            foreach(self::$_workers as $worker)
            {
                $worker->stop();
                foreach($worker->connections as $connection)
                {
                    $connection->close();
                }
            }
            if(self::allWorkhasBeenDone())
            {
                exit(0);
            }
        }
    }
    
    public static function allWorkHasBeenDone()
    {
        foreach(self::$_workers as $worker)
        {
            if(!empty($worker->connections))
            {
                return false;
            }
        }
        return true;
    }
    
    public static function isStopingAll()
    {
        return self::$_status === self::STATUS_SHUTDOWN;
    }
    
    public function __construct($address)
    {
        $this->_mainSocket = stream_socket_server($address, $errno, $errmsg);
        if(!$this->_mainSocket)
        {
            throw new Exception($errmsg);
        }
        $this->address = $address;
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
    
    public function stop()
    {
        self::$globalEvent->del($this->_mainSocket, BaseEvent::EV_READ);
        fclose($this->_mainSocket);
        $this->_mainSocket = null;
    }

    public function accept($socket)
    {
        $new_socket = @stream_socket_accept($socket, 0);
        if(false === $new_socket)
        {
            return;
        }
        stream_set_blocking($new_socket, 0);
        $connection = new Connection($this, $new_socket);
        $this->connections[(int)$new_socket] = $connection;
        if($this->onConnect)
        {
            $func = $this->onConnect;
            $func($connection);
        }
    }
}
