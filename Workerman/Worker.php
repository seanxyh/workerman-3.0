<?php
namespace Workerman;

if(!ini_get('date.timezone') )
{
    date_default_timezone_set('Asia/Shanghai');
}
ini_set('display_errors', 'on');

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/Connection/ConnectionInterface.php';
require_once __DIR__ . '/Connection/TcpConnection.php';
require_once __DIR__ . '/Connection/AsyncTcpConnection.php';
require_once __DIR__ . '/Timer.php';
require_once __DIR__ . '/Lock.php';
require_once __DIR__ . '/Events/EventInterface.php';
require_once __DIR__ . '/Events/Select.php';
require_once __DIR__ . '/Events/Libevent.php';
require_once __DIR__ . '/ProtocolInterface.php';


use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
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
     * backlog
     * @var int
     */
    const DEFAUL_BACKLOG = 1024;
    
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
     * tcp/udp
     * @var string
     */
    public $transport = 'tcp';
    
    /**
     * protocol
     * @var string
     */
    protected $_protocol = '';
    
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
     * pid file
     * @var string
     */
    public static $pidFile = '';
    
    /**
     * log file path
     * @var unknown_type
     */
    public static $logFile = '';
    
    /**
     * master process pid
     * @var int
     */
    protected static $_masterPid = 0;
    
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
     * context
     * @var context
     */
    protected $_context = null;
    
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
     * start file path
     * @var string
     */
    protected static $_startFile = '';
    
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
            self::$_startFile = $backtrace[count($backtrace)-1]['file'];
            self::$pidFile = sys_get_temp_dir()."/workerman.".fileinode(self::$_startFile).".pid";
        }
        if(empty(self::$logFile))
        {
            self::$logFile = __DIR__ . '/../workerman.log';
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
        
        self::log("Workerman[$start_file] $command");
        
        // check if master process is running
        $master_pid = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                self::log("Workerman[$start_file] is running");
            }
        }
        elseif($command !== 'start' && $command !== 'restart')
        {
            self::log("Workerman[$start_file] not run");
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
                self::log("Workerman[$start_file] is stoping ...");
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
                            self::log("Workerman[$start_file] stop fail");
                            exit;
                        }
                        // avoid the cost of CPU time, sleep for a while
                        usleep(10000);
                        continue;
                    }
                    self::log("Workerman[$start_file] stop success");
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
                self::log("Workerman[$start_file] reload");
                exit;
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
        self::$_globalEvent->add(SIGINT, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        //  uninstall  reload signal handler
        self::$_globalEvent->add(SIGUSR1, EventInterface::EV_SIGNAL,array('\Workerman\Worker', 'signalHandler'));
        // uninstall  status signal handler
        self::$_globalEvent->add(SIGUSR2, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
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
        self::$_masterPid = posix_getpid();
        if(false === @file_put_contents(self::$pidFile, self::$_masterPid))
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
                            self::log("worker[".$worker->name.":$pid] exit with status $status");
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
                    if(!self::getAllWorkerPids())
                    {
                        self::exitAndClearAll();
                    }
                }
            }
            else 
            {
                if(self::$_status === self::STATUS_SHUTDOWN && !self::getAllWorkerPids())
                {
                   self::exitAndClearAll();
                }
            }
        }
    }
    
    /**
     * exit
     */
    protected static function exitAndClearAll()
    {
        @unlink(self::$pidFile);
        self::log("Workerman[".basename(self::$_startFile)."] has been stopped");
        exit(0);
    }
    
    /**
     * reload workerman, gracefully restart child processes one by one
     * @return void
     */
    protected static function reload()
    {
        // for master process
        if(self::$_masterPid === posix_getpid())
        {
            // set status
            if(self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN)
            {
                self::log("Workerman[".basename(self::$_startFile)."] reloading");
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
    public static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // for master process
        if(self::$_masterPid === posix_getpid())
        {
            self::log("Workerman[".basename(self::$_startFile)."] Stopping ...");
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
        if(self::$_masterPid === posix_getpid())
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
        $wrker_status_str .=  str_pad(ConnectionInterface::$statistics['total_request'], 14)." ".str_pad(ConnectionInterface::$statistics['send_fail'],9)." ".str_pad(ConnectionInterface::$statistics['throw_exception'],15)."\n";
        file_put_contents(self::$_statisticsFile, $wrker_status_str, FILE_APPEND);
    }
    
    /**
     * log
     * @param string $msg
     * @return void
     */
    protected static function log($msg)
    {
        $msg = $msg."\n";
        if(self::$_status === self::STATUS_STARTING || !self::$daemonize)
        {
            echo $msg;
        }
        file_put_contents(self::$logFile, date('Y-m-d H:i:s') . " " . $msg, FILE_APPEND);
    }
    
    /**
     * create a worker
     * @param string $socket_name
     * @return void
     */
    public function __construct($socket_name, $context_option = array())
    {
        $this->_socketName = $socket_name;
        self::$_workers[$this->_socketName] = $this;
        self::$_pidMap[$this->_socketName] = array();
        if(!isset($context_option['socket']['backlog']))
        {
            $context_option['socket']['backlog'] = self::DEFAUL_BACKLOG;
        }
        $this->_context = stream_context_create($context_option);
    }
    
    /**
     * listen and bind socket
     * @throws Exception
     */
    public function listen()
    {
        list($scheme, $address) = explode(':', $this->_socketName, 2);
        if($scheme != 'tcp' && $scheme != 'udp')
        {
            $scheme = ucfirst($scheme);
            $this->_protocol = '\\Protocols\\'.$scheme;;
            if(!class_exists($this->_protocol))
            {
                $this->_protocol = '\\Protocols\\'.$scheme . '\\' . $scheme;
                if(!class_exists($this->_protocol))
                {
                    throw new Exception('class ' .$this->_protocol . ' not exist');
                }
            }
        }
        elseif($scheme === 'udp')
        {
            $this->transport = 'udp';
        }
        
        $flags =  $this->transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        $this->_mainSocket = stream_socket_server($this->transport.":".$address, $errno, $errmsg, $flags, $this->_context);
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
        if($this->transport !== 'udp')
        {
            self::$_globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
        }
        else
        {
            self::$_globalEvent->add($this->_mainSocket,  EventInterface::EV_READ, array($this, 'acceptUdpConnection'));
        }
        
        Timer::init(self::$_globalEvent);
        
        if($this->onStart)
        {
            call_user_func($this->onStart, $this);
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
            call_user_func($this->onStop, $this);
        }
        self::$_globalEvent->del($this->_mainSocket, EventInterface::EV_READ);
        @fclose($this->_mainSocket);
    }

    /**
     * accept a connection of client
     * @param resources $socket
     * @return void
     */
    public function acceptConnection($socket)
    {
        $new_socket = @stream_socket_accept($socket, 0);
        if(false === $new_socket)
        {
            return;
        }
        $connection = new TcpConnection($new_socket, self::$_globalEvent);
        $connection->protocol = $this->_protocol;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        if($this->onConnect)
        {
            try
            {
                call_user_func($this->onConnect, $connection);
            }
            catch(Exception $e)
            {
                ConnectionInterface::$statistics['throw_exception']++;
                self::log($e);
            }
        }
    }
    
    /**
     * deall udp package
     * @param resource $socket
     */
    public function acceptUdpConnection($socket)
    {
        $recv_buffer = stream_socket_recvfrom($socket , self::MAX_UDP_PACKEG_SIZE, 0, $remote_address);
        if(false === $recv_buffer || empty($remote_address))
        {
            return false;
        }
        
        ConnectionInterface::$statistics['total_request'] ++;
         
        $connection = new UdpConnection($socket, $remote_address);
        if($this->onMessage)
        {
            $parser = $this->_protocol;
            try
            {
               call_user_func($this->onMessage, $connection, $parser::decode($recv_buffer, $connection));
            }
            catch(Exception $e)
            {
                ConnectionInterface::$statistics['throw_exception']++;
            }
        }
    }
}
