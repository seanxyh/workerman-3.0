<?php
namespace Workerman;

ini_set('display_errors', 'on');

require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/Timer.php';
require_once __DIR__ . '/Events/BaseEvent.php';
require_once __DIR__ . '/Events/Select.php';
require_once __DIR__ . '/Events/Libevent.php';

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\BaseEvent;
use \Exception;

class Worker 
{
    const VERSION = 3.0;
    
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
    
    protected $_mainSocket = null;
    
    protected $_socketName = '';
    
    public static $daemonize = false;
    
    public static $stdoutFile = '/dev/null';
    
    public static $pidFile = '';
    
    public static $globalEvent = null;

    protected static $_workers = array();
    
    protected static $_pidMap = array();
    
    protected static $_pidsToRestart = array();
    
    protected static $_status = self::STATUS_STARTING;
    
    protected static $_maxWorkerNameLength = 12;
    
    protected static $_globalStatistics = array(
        'start_timestamp' => 0,
        'worker_exit_info' => array()
    );
    
    public static $workerStatistics = array(
        'start_timestamp'      => 0, // 该进程开始时间戳
        'total_request'   => 0, // 该进程处理的总请求数
        'throw_exception' => 0, // 该进程逻辑处理时收到异常的总数
        'send_fail'       => 0, // 发送数据给客户端失败总数
    );
    
    public static function runAll()
    {
        self::init();
        self::daemonize();
        self::installSignal();
        self::resetStd();
        self::saveMasterPid();
        self::createWorkers();
        self::monitorWorkers();
    }
    
    public static function init()
    {
        self::$_status = self::STATUS_STARTING;
        self::$_globalStatistics['start_timestamp'] = time();
        Timer::init();
    }
    
    protected static function installSignal()
    {
        // stop
        pcntl_signal(SIGINT,  array('\Workerman\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\Workerman\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\Workerman\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }
    
    protected static function reinstallSignal()
    {
        // uninstall stop signal handler
        pcntl_signal(SIGINT,  SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall  status signal handler
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        // reinstall stop signal handler
        self::$globalEvent->add(SIGINT, BaseEvent::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        //  uninstall  reload signal handler
        self::$globalEvent->add(SIGUSR1, BaseEvent::EV_SIGNAL,array('\Workerman\Worker', 'signalHandler'));
        // uninstall  status signal handler
        self::$globalEvent->add(SIGUSR2, BaseEvent::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
    }
    
    public static function signalHandler($signal)
    {
        switch($signal)
        {
            // stop
            case SIGINT:
                self::stopAll();
                break;
            // reload
            case SIGUSR1:
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
        if(!self::$daemonize)
        {
            return;
        }
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
        if(!self::$daemonize)
        {
            return;
        }
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
        // 保存到文件中，用于实现停止、重启
        self::$pidFile = empty(self::$pidFile) ? sys_get_temp_dir()."/workerman.".fileinode(__FILE__).".pid" : self::$pidFile;
        if(false === @file_put_contents(self::$pidFile, self::$masterPid))
        {
            throw new Exception('can not save pid to ' . self::$pidFile);
        }
    }
    
    protected static function getAllWorkerPids()
    {
        $pid_array = array(); 
        foreach(self::$_pidMap as $socket_name => $worker_pid_array)
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
        foreach(self::$_workers as $socket_name=>$worker)
        {
            // check worker->name etc
            if(self::$_status === self::STATUS_STARTING)
            {
                // if worker->name not set then use worker->_socketName as worker->name
                if(empty($worker->name))
                {
                    $worker->name = $worker->getSocketName();;
                }
                // get the max length of worker->name for formating status info
                $worker_name_length = strlen($worker->name);
                if(self::$_maxWorkerNameLength < $worker_name_length)
                {
                    self::$_maxWorkerNameLength = $worker_name_length;
                }
            }
            
            // create processes
            while(count(self::$_pidMap[$socket_name]) < $worker->count)
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
            self::$_pidMap[$worker->getSocketName()][$pid] = $pid;
        }
        elseif(0 === $pid)
        {
            self::$_pidMap = array();
            self::$_workers = array($worker->getSocketName() => $worker);
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
        self::$_status = self::STATUS_RUNNING;
        while(1)
        {
            $status = 0;
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status, WUNTRACED);
            if($pid > 0)
            {
                foreach(self::$_pidMap as $socket_name => $worker_pid_array)
                {
                    if(isset($worker_pid_array[$pid]))
                    {
                        // check status
                        if($status !== 0)
                        {
                            $worker = self::$_workers[$socket_name];
                            echo "worker[".$worker->name.":$pid] exit with status $status\n";
                        }
                       
                        // statistics
                        if(!isset(self::$_globalStatistics['worker_exit_info'][$worker->getSocketName()][$status]))
                        {
                            self::$_globalStatistics['worker_exit_info'][$worker->getSocketName()][$status] = 0;
                        }
                        self::$_globalStatistics['worker_exit_info'][$worker->getSocketName()][$status]++;
                        
                        // if realoding, continue
                        if(isset(self::$_pidsToRestart[$pid]))
                        {
                            unset(self::$_pidsToRestart[$pid]);
                            self::reload();
                        }
                        
                        // clear pid info
                        unset(self::$_pidMap[$socket_name][$pid]);
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
                        @unlink(self::$pidFile);
                        echo "Workerman has been stopped\n";
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
            echo "Workerman reloading\n";
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
            echo "Stoping Workerman...\n";
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
            }
            if(self::allWorkHasBeenDone())
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
    
    protected static function writeStatisticsToStatusFile()
    {
        $status_file = sys_get_temp_dir().'/workerman.status';
        if(self::$masterPid === posix_getpid())
        {
            $loadavg = sys_getloadavg();
            file_put_contents($status_file, "---------------------------------------GLOBAL STATUS--------------------------------------------\n");
            file_put_contents($status_file, 'Workerman version:' . Worker::VERSION . "          PHP version:".PHP_VERSION."\n", FILE_APPEND);
            file_put_contents($status_file, 'start time:'. date('Y-m-d H:i:s', self::$_globalStatistics['start_timestamp']).'   run ' . floor((time()-self::$_globalStatistics['start_timestamp'])/(24*60*60)). ' days ' . floor(((time()-self::$_globalStatistics['start_timestamp'])%(24*60*60))/(60*60)) . " hours   \n", FILE_APPEND);
            file_put_contents($status_file, 'load average: ' . implode(", ", $loadavg) . "\n", FILE_APPEND);
            file_put_contents($status_file,  count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids())." processes\n", FILE_APPEND);
            file_put_contents($status_file, str_pad('worker_name', self::$_maxWorkerNameLength) . " exit_status     exit_count\n", FILE_APPEND);
            foreach(self::$_pidMap as $socket_name =>$worker_pid_array)
            {
                $worker = self::$_workers[$socket_name];
                if(isset(self::$_globalStatistics['worker_exit_info'][$socket_name]))
                {
                    foreach(self::$_globalStatistics['worker_exit_info'][$socket_name] as $worker_exit_status=>$worker_exit_count)
                    {
                        file_put_contents($status_file, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status, 16). " $worker_exit_count\n", FILE_APPEND);
                    }
                }
                else
                {
                    file_put_contents($status_file, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad(0, 16). " 0\n", FILE_APPEND);
                }
            }
            file_put_contents($status_file,  "---------------------------------------PROCESS STATUS-------------------------------------------\n", FILE_APPEND);
            file_put_contents($status_file, "pid\tmemory  ".str_pad('listening', 20)." timestamp  ".str_pad('worker_name', self::$_maxWorkerNameLength)." ".str_pad('total_request', 13)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)."\n", FILE_APPEND);
            return;
        }
        $handle = fopen($status_file, 'r');
        if($handle)
        {
            flock($handle, LOCK_EX);
            $worker = current(self::$_workers);
            $wrker_status_str = posix_getpid()."\t".str_pad(round(memory_get_usage()/(1024*1024),2)."M", 7)." " .str_pad($worker->getSocketName(), 20) ." ". self::$workerStatistics['start_time'] ." ".str_pad(($worker->name == $worker->getSocketName ? 'none' : $worker->name), self::$_maxWorkerNameLength)." ";
            $wrker_status_str .=  str_pad(self::$workerStatistics['total_request'], 14)." ".str_pad(self::$workerStatistics['send_fail'],9)." ".str_pad(self::$workerStatistics['throw_exception'],15)."\n";
            file_put_contents($status_file, $wrker_status_str, FILE_APPEND);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    
    public function __construct($socket_name)
    {
        $this->_mainSocket = stream_socket_server($socket_name, $errno, $errmsg);
        if(!$this->_mainSocket)
        {
            throw new Exception($errmsg);
        }
        $this->_socketName = $socket_name;
        self::$_workers[$this->_socketName] = $this;
        self::$_pidMap[$this->_socketName] = array();
    }
    
    public function getSocketName()
    {
        return $this->_socketName;
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
        self::reinstallSignal();
        self::$_workerStatistics['start_timestamp'] = time();
        self::$globalEvent->add($this->_mainSocket, BaseEvent::EV_READ, array($this, 'accept'));
        self::$globalEvent->loop();
    }
    
    public function stop()
    {
        self::$globalEvent->del($this->_mainSocket, BaseEvent::EV_READ);
        fclose($this->_mainSocket);
        $this->_mainSocket = null;
        
        foreach($this->connections as $connection)
        {
            $connection->close();
        }
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
            try
            {
                $func($connection);
            }
            catch(Exception $e)
            {
                Worker::$workerStatistics['throw_exception']++;
                echo $e;
            }
        }
    }
}
