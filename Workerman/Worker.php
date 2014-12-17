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

/**
 * 
 * @author walkor<walkor@workerman.net>
 */
class Worker 
{
    const VERSION = '3.0.0';
    
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
    
    protected static $_statisticsFile = '';
    
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
        self::parseCommand();
        self::daemonize();
        self::installSignal();
        self::resetStd();
        self::saveMasterPid();
        self::createWorkers();
        self::monitorWorkers();
    }
    
    public static function init()
    {
        if(empty(self::$pidFile))
        {
            $backtrace = debug_backtrace();
            $start_file = $backtrace[count($backtrace)-1]['file'];
            self::$pidFile = sys_get_temp_dir()."/workerman.".fileinode($start_file).".pid";
        }
        self::$_status = self::STATUS_STARTING;
        self::$_globalStatistics['start_timestamp'] = time();
        self::$_statisticsFile = sys_get_temp_dir().'/workerman.status';
        Timer::init();
    }
    /**
     * php yourfile.php start | stop | restart | reload | status
     */
    public static function parseCommand()
    {
        global $argv;
        $start_file = $argv[0]; 
        if(!isset($argv[1]))
        {
            exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
        
        $command = trim($argv[1]);
        
        $master_pid = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                exit("Workerman[$start_file] is running\n");
            }
        }
        elseif($command !== 'start')
        {
            exit("Workerman[$start_file] not run\n");
        }
        
        switch($command)
        {
            // start workerman
            case 'start':
                break;
            // show status of workerman
            case 'status':
                // try to delete the statistics file , avoid read dirty data
                if(is_file(self::$_statisticsFile))
                {
                    @unlink(self::$_statisticsFile);
                }
                // send SIGUSR2 to master process ,then master process will send SIGUSR2 to all children processes
                // all processes will write statistics data to statistics file
                posix_kill($master_pid, SIGUSR2);
                // wait all processes wirte statistics data
                usleep(100000);
                // display statistics file
                readfile(self::$_statisticsFile);
                exit(0);
            case 'restart':
            case 'stop':
                echo "Stopping Workerman[$start_file] ...\n";
                // send SIGINT to master process, master process will stop all children process and exit
                posix_kill($master_pid, SIGINT);
                // if $timeout seconds master process not exit then dispaly stop failure
                $timeout = 5;
                // a recording start time
                $start_time = time();
                while(1)
                {
                    $master_is_alive = posix_kill($master_pid, 0);
                    if($master_is_alive)
                    {
                        // check whether has timed out
                        if(time() - $start_time >= $timeout)
                        {
                            exit("Workerman[$start_file] stop fail\n");
                        }
                        // avoid the cost of CPU time, sleep for a while
                        usleep(10000);
                        continue;
                    }
                    echo "Workerman[$start_file] stop success\n";
                    if($command === 'stop')
                    {
                        exit(0);
                    }
                }
                break;
            case 'reload':
                posix_kill($master_pid, SIGUSR1);
                echo "Workerman[$start_file] reload\n";
                exit(0);
            default :
                 exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
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
                self::writeStatisticsToStatusFile();
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
                        $worker = self::$_workers[$socket_name];
                        // check status
                        if($status !== 0)
                        {
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
        // for master process
        if(self::$masterPid === posix_getpid())
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
        // for children process
        else
        {
            self::stopAll();
        }
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
        // for master process
        if(self::$masterPid === posix_getpid())
        {
            $loadavg = sys_getloadavg();
            file_put_contents(self::$_statisticsFile, "---------------------------------------GLOBAL STATUS--------------------------------------------\n");
            file_put_contents(self::$_statisticsFile, 'Workerman version:' . Worker::VERSION . "          PHP version:".PHP_VERSION."\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, 'start time:'. date('Y-m-d H:i:s', self::$_globalStatistics['start_timestamp']).'   run ' . floor((time()-self::$_globalStatistics['start_timestamp'])/(24*60*60)). ' days ' . floor(((time()-self::$_globalStatistics['start_timestamp'])%(24*60*60))/(60*60)) . " hours   \n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, 'load average: ' . implode(", ", $loadavg) . "\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile,  count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids())." processes\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, str_pad('worker_name', self::$_maxWorkerNameLength) . " exit_status     exit_count\n", FILE_APPEND);
            foreach(self::$_pidMap as $socket_name =>$worker_pid_array)
            {
                $worker = self::$_workers[$socket_name];
                if(isset(self::$_globalStatistics['worker_exit_info'][$socket_name]))
                {
                    foreach(self::$_globalStatistics['worker_exit_info'][$socket_name] as $worker_exit_status=>$worker_exit_count)
                    {
                        file_put_contents(self::$_statisticsFile, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status, 16). " $worker_exit_count\n", FILE_APPEND);
                    }
                }
                else
                {
                    file_put_contents(self::$_statisticsFile, str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad(0, 16). " 0\n", FILE_APPEND);
                }
            }
            file_put_contents(self::$_statisticsFile,  "---------------------------------------PROCESS STATUS-------------------------------------------\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, "pid\tmemory  ".str_pad('listening', 20)." timestamp  ".str_pad('worker_name', self::$_maxWorkerNameLength)." ".str_pad('total_request', 13)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)."\n", FILE_APPEND);
            
            foreach(self::getAllWorkerPids() as $worker_pid)
            {
                posix_kill($worker_pid, SIGUSR2);
            }
            return;
        }
        
        // for worker process
        $worker = current(self::$_workers);
        $wrker_status_str = posix_getpid()."\t".str_pad(round(memory_get_usage()/(1024*1024),2)."M", 7)." " .str_pad($worker->getSocketName(), 20) ." ". self::$workerStatistics['start_timestamp'] ." ".str_pad(($worker->name == $worker->getSocketName() ? 'none' : $worker->name), self::$_maxWorkerNameLength)." ";
        $wrker_status_str .=  str_pad(self::$workerStatistics['total_request'], 14)." ".str_pad(self::$workerStatistics['send_fail'],9)." ".str_pad(self::$workerStatistics['throw_exception'],15)."\n";
        file_put_contents(self::$_statisticsFile, $wrker_status_str, FILE_APPEND);
    }
    
    public function __construct($socket_name)
    {
        global $argv;
        if(!isset($argv[1]) || $argv[1] === 'start')
        {
            $this->_mainSocket = stream_socket_server($socket_name, $errno, $errmsg);
            if(!$this->_mainSocket)
            {
                throw new Exception($errmsg);
            }
            stream_set_blocking($this->_mainSocket, 0);
            $this->_socketName = $socket_name;
            self::$_workers[$this->_socketName] = $this;
            self::$_pidMap[$this->_socketName] = array();
        }
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
        self::$workerStatistics['start_timestamp'] = time();
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
