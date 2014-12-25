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
    /**
     * workerman version
     * @var string
     */
    const VERSION = '3.0.0';
    
    /**
     * status starting
     * @var int
     */
    const STATUS_STARTING = 1;
    
    /**
     * status running
     * @var int
     */
    const STATUS_RUNNING = 2;
    
    /**
     * status shutdown
     * @var int
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * status reloading
     * @var int
     */
    const STATUS_RELOADING = 8;
    
    /**
     * after KILL_WORKER_TIMER_TIME seconds if worker not quit
     * then send SIGKILL to the worker
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 1;
    
    /**
     * worker name for marking process
     * @var string
     */
    public $name = 'none';
    
    /**
     * when worker start, then run onStart
     * @var callback
     */
    public $onStart = null;
    
    /**
     * when client connect worker, onConnect will be run
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * when worker recv data, onMessage will be run
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * when connection closed, onClose will be run
     * @var callback
     */
    public $onClose = null;
    
    /**
     * when connection has error, onError will be run
     * @var unknown_type
     */
    public $onError = null;
    
    /**
     * when worker stop, which function will be run
     * @var callback
     */
    public $onStop = null;
    
    /**
     * how many processes will be created for the current worker
     * @var unknown_type
     */
    public $count = 1;
    
    /**
     * set the real user of the worker process, needs appropriate privileges (usually root) 
     * @var string
     */
    public $user = '';
    
    /**
     * if run as daemon
     * @var bool
     */
    public static $daemonize = false;
    
    /**
     * all output buffer (echo var_dump etc) will write to the file 
     * @var string
     */
    public static $stdoutFile = '/dev/null';
    
    /**
     * master process pid
     * @var int
     */
    public static $masterPid = 0;
    
    /**
     * pid file
     * @var string
     */
    public static $pidFile = '';
    
    /**
     * event loop
     * @var Select/Libevent
     */
    protected static $_globalEvent = null;
    
    /**
     * stream socket of the worker
     * @var stream
     */
    protected $_mainSocket = null;
    
    /**
     * socket name example tcp://0.0.0.0:80
     * @var string
     */
    protected $_socketName = '';
    
    /**
     * all instances of worker
     * @var array
     */
    protected static $_workers = array();
    
    /**
     * all workers and pids
     * @var array
     */
    protected static $_pidMap = array();
    
    /**
     * all processes to be restart
     * @var array
     */
    protected static $_pidsToRestart = array();
    
    /**
     * current status
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;
    
    /**
     * max length of $_workerName
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;
    
    /**
     * max length of $_socketName
     * @var int
     */
    protected static $_maxSocketNameLength = 12;
    
    /**
     * max length of $user's name
     * @var int
     */
    protected static $_maxUserNameLength = 12;
    
    /**
     * the path of status file, witch will store status of processes
     * @var string
     */
    protected static $_statisticsFile = '';
    
    /**
     * global statistics
     * @var array
     */
    protected static $_globalStatistics = array(
        'start_timestamp' => 0,
        'worker_exit_info' => array()
    );
    
    /**
     * run all workers
     * @return void
     */
    public static function runAll()
    {
        self::init();
        self::parseCommand();
        self::daemonize();
        self::initWorkers();
        self::installSignal();
        self::displayUI();
        self::resetStd();
        self::saveMasterPid();
        self::forkWorkers();
        self::monitorWorkers();
    }
    
    /**
     * initialize the environment variables 
     * @return void
     */
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
     * initialize the all the workers
     * @return void
     */
    protected static function initWorkers()
    {
        foreach(self::$_workers as $socket_name=>$worker)
        {
            // if worker->name not set then use worker->_socketName as worker->name
            if(empty($worker->name))
            {
                $worker->name = 'none';
            }
            // get the max length of worker->name for formating status info
            $worker_name_length = strlen($worker->name);
            if(self::$_maxWorkerNameLength < $worker_name_length)
            {
                self::$_maxWorkerNameLength = $worker_name_length;
            }
            // get the max length of worker->_socketName
            $socket_name_length = strlen($worker->getSocketName());
            if(self::$_maxSocketNameLength < $socket_name_length)
            {
                self::$_maxSocketNameLength = $socket_name_length;
            }
            // get the max length user name
            if(empty($worker->user))
            {
                $worker->user = self::getCurrentUser();
            }
            $user_name_length = strlen($worker->user);
            if(self::$_maxUserNameLength < $user_name_length)
            {
                self::$_maxUserNameLength = $user_name_length;
            }
            // listen
            $worker->listen();
        }
    }
    
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }
    
    protected static function displayUI()
    {
        echo "-----------------------\033[47;30m WORKERMAN \033[0m-----------------------------\n";
        echo 'Workerman version:' . Worker::VERSION . "          PHP version:".PHP_VERSION."\n";
        echo "------------------------\033[47;30m WORKERS \033[0m-------------------------------\n";
        echo "\033[47;30muser\033[0m",str_pad('', self::$_maxUserNameLength+2-strlen('user')), "\033[47;30mworker\033[0m",str_pad('', self::$_maxWorkerNameLength+2-strlen('worker')), "\033[47;30mlisten\033[0m",str_pad('', self::$_maxSocketNameLength+2-strlen('listen')), "\033[47;30mprocesses\033[0m \033[47;30m","status\033[0m\n";
        foreach(self::$_workers as $worker)
        {
            echo str_pad($worker->user, self::$_maxUserNameLength+2),str_pad($worker->name, self::$_maxWorkerNameLength+2),str_pad($worker->getSocketName(), self::$_maxSocketNameLength+2), str_pad(' '.$worker->count, 9), " \033[32;40m [OK] \033[0m\n";;
        }
        echo "----------------------------------------------------------------\n";
    }
    
    /**
     * php yourfile.php start | stop | restart | reload | status
     * @return void
     */
    public static function parseCommand()
    {
        // check command
        global $argv;
        $start_file = $argv[0]; 
        if(!isset($argv[1]))
        {
            exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
        
        $command = trim($argv[1]);
        
        // check if master process is running
        $master_pid = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                exit("Workerman[$start_file] is running\n");
            }
        }
        elseif($command !== 'start' && $command !== 'restart')
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
            // restart workerman
            case 'restart':
            // stop workeran
            case 'stop':
                echo "Workerman[$start_file] is stoping ...\n";
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
                    break;
                }
                break;
            // reload workerman
            case 'reload':
                posix_kill($master_pid, SIGUSR1);
                echo "Workerman[$start_file] reload\n";
                exit(0);
            // unknow command
            default :
                 exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
    }
    
    /**
     * installs signal handlers for master
     * @return void
     */
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
    
    /**
     * reinstall signal handlers for workers
     * @return void
     */
    protected static function reinstallSignal()
    {
        // uninstall stop signal handler
        pcntl_signal(SIGINT,  SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall  status signal handler
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        // reinstall stop signal handler
        self::$_globalEvent->add(SIGINT, BaseEvent::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        //  uninstall  reload signal handler
        self::$_globalEvent->add(SIGUSR1, BaseEvent::EV_SIGNAL,array('\Workerman\Worker', 'signalHandler'));
        // uninstall  status signal handler
        self::$_globalEvent->add(SIGUSR2, BaseEvent::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
    }
    
    /**
     * signal handler
     * @param int $signal
     */
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

    /**
     * run workerman as daemon
     * @throws Exception
     */
    protected static function daemonize()
    {
        if(!self::$daemonize)
        {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            throw new Exception('fork fail');
        }
        elseif($pid > 0)
        {
            exit(0);
        }
        if(-1 == posix_setsid())
        {
            throw new Exception("setsid fail");
        }
        // fork again avoid SVR4 system regain the control of terminal
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            throw new Exception("fork fail");
        }
        elseif(0 !== $pid)
        {
            exit(0);
        }
    }

    /**
     * redirecting output
     * @throws Exception
     */
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
    
    /**
     * save the pid of master for later stop/reload/restart/status command
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        self::$masterPid = posix_getpid();
        if(false === @file_put_contents(self::$pidFile, self::$masterPid))
        {
            throw new Exception('can not save pid to ' . self::$pidFile);
        }
    }
    
    /**
     * get all pids of workers
     * @return array
     */
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

    /**
     * fork worker processes
     * @return void
     */
    protected static function forkWorkers()
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

    /**
     * fork one worker and run it
     * @param Worker $worker
     * @throws Exception
     */
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

    /**
     * wait for the child process exit
     * @return void
     */
    protected static function monitorWorkers()
    {
        self::$_status = self::STATUS_RUNNING;
        while(1)
        {
            // calls signal handlers for pending signals
            pcntl_signal_dispatch();
            // suspends execution of the current process until a child has exited or  a signal is delivered
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
                // workerman is still running
                if(self::$_status !== self::STATUS_SHUTDOWN)
                {
                    self::forkWorkers();
                }
                else
                {
                    // workerman is shuting down
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
    
    /**
     * reload workerman, gracefully restart child processes one by one
     * @return void
     */
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
    
    /**
     * stop all workers
     * @return void
     */
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
            exit(0);
        }
    }
    
    /**
     * for workermand status command
     * @return void
     */
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
            file_put_contents(self::$_statisticsFile, "pid\tmemory  ".str_pad('listening', 20)." ".str_pad('worker_name', self::$_maxWorkerNameLength)." ".str_pad('total_request', 13)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)."\n", FILE_APPEND);
            
            foreach(self::getAllWorkerPids() as $worker_pid)
            {
                posix_kill($worker_pid, SIGUSR2);
            }
            return;
        }
        
        // for worker process
        $worker = current(self::$_workers);
        $wrker_status_str = posix_getpid()."\t".str_pad(round(memory_get_usage()/(1024*1024),2)."M", 7)." " .str_pad($worker->getSocketName(), 20) ." ".str_pad(($worker->name == $worker->getSocketName() ? 'none' : $worker->name), self::$_maxWorkerNameLength)." ";
        $wrker_status_str .=  str_pad(Connection::$statistics['total_request'], 14)." ".str_pad(Connection::$statistics['send_fail'],9)." ".str_pad(Connection::$statistics['throw_exception'],15)."\n";
        file_put_contents(self::$_statisticsFile, $wrker_status_str, FILE_APPEND);
    }
    
    /**
     * create a worker
     * @param string $socket_name
     * @return void
     */
    public function __construct($socket_name)
    {
        $this->_socketName = $socket_name;
        self::$_workers[$this->_socketName] = $this;
        self::$_pidMap[$this->_socketName] = array();
    }
    
    /**
     * listen and bind socket
     * @throws Exception
     */
    public function listen()
    {
        $this->_mainSocket = stream_socket_server($this->_socketName, $errno, $errmsg);
        if(!$this->_mainSocket)
        {
            throw new Exception($errmsg);
        }
        stream_set_blocking($this->_mainSocket, 0);
    }
    
    /**
     * get socket name
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName;
    }
    
    /**
     * run the current worker
     */
    public function run()
    {
        if(!self::$_globalEvent)
        {
            if(extension_loaded('libevent'))
            {
                self::$_globalEvent = new Libevent();
            }
            else
            {
                self::$_globalEvent = new Select();
            }
        }
        self::reinstallSignal();
        self::$_globalEvent->add($this->_mainSocket, BaseEvent::EV_READ, array($this, 'accept'));
        if($this->onStart)
        {
            $func = $this->onStart;
            $func($this);
        }
        self::$_globalEvent->loop();
    }
    
    /**
     * stop the current worker
     * @return void
     */
    public function stop()
    {
        if($this->onStop)
        {
            $func = $this->onStop;
            $func($this);
        }
    }

    /**
     * accept a connection of client
     * @param resources $socket
     * @return void
     */
    public function accept($socket)
    {
        $new_socket = @stream_socket_accept($socket, 0);
        if(false === $new_socket)
        {
            return;
        }
        $connection = new Connection($new_socket, self::$_globalEvent);
        if($this->onMessage)
        {
            $connection->onMessage = $this->onMessage;
        }
        if($this->onClose)
        {
            $connection->onClose = $this->onClose;
        }
        if($this->onError)
        {
            $connection->onError = $this->onError;
        }
        if($this->onConnect)
        {
            $func = $this->onConnect;
            try
            {
                $func($connection);
            }
            catch(Exception $e)
            {
                Connection::$statistics['throw_exception']++;
                echo $e;
            }
        }
    }
}
