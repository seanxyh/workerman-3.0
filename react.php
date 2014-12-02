<?php
ini_set('display_errors', 'on');

define('SYNC', 1);

define('ASYNC', 2);

define('ERROR_SEND_FAIL', 30001);


class Worker extends Connection
{
    public function __construct($address)
    {
        $this->socket = stream_socket_server($address, $errno, $errmsg);
        if(!$this->socket)
        {
            throw new \Exception($errmsg);
        }
    }
    
    public function run()
    {
        if(!self::$globalEvent)
        {
            self::$globalEvent = new \Select();
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

class Client extends Connection
{
    public $onError = null;
    protected $flag = SYNC;
    protected $address = '';
    protected $timeout = 1;

    public function __constuct($address, $flag = SYNC, $timeout = 1)
    {
        $this->flag = $flag;
        $this->timeout = $timeout;
        if($this->flag === SYNC)
        {
            $this->socket = stream_socket_client($address, $errno, $errmsg, $timeout);
            if(!$this->socket)
            {
                $this->triggerError($errno, $errmsg);
                return;
            }
            stream_set_blocking($this->socket, 1);
            stream_set_timeout($this->socket, $this->timeout);
        }
        else
        { 
            $this->socket = stream_socket_client($address, $errno, $errmsg, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        }
    }

    public function send($buffer)
    {
        if($this->flag === ASYNC)
        {
            return parent::send($buffer);
        }
        // while(1){fwrite..}?
        $send_len = fwrite($this->socket, $buffer);
        if($send_len !== strlen($buffer))
        {
            if(feof($this->socket))
            {
                $this->triggerError(ERROR_SEND_FAIL, "send fail connection to {$this->address} closed by remote");
                if($this->onClose)
                {
                    @fclose($this->socket);
                    $func = $this->onClose;
                    $func($this);
                }
            }
            else
            {
                $buffer_len = strlen($buffer);
                $this->triggerError(ERROR_SEND_FAIL, "send $buffer_len bytes data fail, $send_len bytes had sent, maybe timeout");
            }
            return false;
        }
        return true;
    }

    protected function triggerError($errno, $errmsg)
    {
        if($this->onError)
        {
            $func = $this->onError;
            $func($this, $errno, $errmsg);
        }
        else
        {
            throw new \Exception($errmsg, $errno);
        }
    }
}

class Connection
{
    const READ_BUFFER_SIZE = 8192;

    const STATUS_NULL = 0;

    const STATUS_CONNECTING = 1;

    const STATUS_CLOSING = 8;

    public static $globalEvent = null;

    public $event = null;

    protected $socket = null;
    
    public $onConnect = null;

    public $onMessage = null;

    public $onClose = null;

    protected $sendBuffer = '';

    protected $status = self::STATUS_NULL;

    public function __construct($socket)
    {
        $this->socket = $socket;
    }
    
    public function send($send_buffer)
    {
        if($this->sendBuffer === '')
        {
            $len = fwrite($this->socket, $send_buffer);
            if($len === strlen($send_buffer))
            {
                return true;
            }
            elseif($len > 0)
            {
                $this->sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                if(feof($this->socket))
                {
                    $this->shutdown();
                    return;
                }
                $this->sendBuffer = $send_buffer;
            }
        }
        if($this->sendBuffer !== '')
        {
            $this->sendBuffer .= $send_buffer;
            $this->event->add($this->socket, BaseEvent::EV_WRITE, array($this, 'baseWrite'));
        }
    }

    public function join($event = null)
    {
        if($event)
        {
            $this->event = $event;
        }
        else
        {
            $this->event = self::$globalEvent;
        }
        $this->event->add($this->socket, BaseEvent::EV_READ, array($this, 'baseRead'));
    }

    public function baseRead($socket)
    {
       $recv_buffer = '';
       while($buffer = fread($socket, self::READ_BUFFER_SIZE))
       {
          $recv_buffer .= $buffer; 
       }
       
       if(feof($socket))
       {
           $this->shutdown();
           return;
       }
       if($recv_buffer !== '' && $this->onMessage)
       {
           $func = $this->onMessage;
           $func($this, $recv_buffer);
       }
       
    }

    public function baseWrite()
    {
        $len = fwrite($this->socket, $this->sendBuffer);
        if($len == strlen($this->sendBuffer))
        {
            $this->event->del($this->socket, BaseEvent::EV_WRITE);
            $this->sendBuffer = '';
            if($this->status == self::STATUS_CLOSING)
            {
                $this->shutdown();
            }
            return true;
        }
        if($len > 0)
        {
           $this->sendBuffer = substr($this->sendBuffer, $len);
        }
        else
        {
           if(feof($this->socket))
           {
               $this->shutdown();
           }
        }
    }

    public function close()
    {
        $this->status = self::STATUS_CLOSING;
        if($this->sendBuffer === '')
        {
           $this->shutdown();
        }
    }

    public function shutdown()
    {
       if($this->onClose)
       {
           $func = $this->onClose;
           $func($this);
       }
       $this->event->del($this->socket, BaseEvent::EV_READ);
       $this->event->del($this->socket, BaseEvent::EV_WRITE);
       fclose($this->socket);
    }
}

interface BaseEvent
{
    /**
     * 数据可读事件
     * @var integer
     */
    const EV_READ = 1;
    
    /**
     * 数据可写事件
     * @var integer
     */
    const EV_WRITE = 2;
    
    /**
     * 信号事件
     * @var integer
     */
    const EV_SIGNAL = 4;
    
    /**
     * 事件添加
     * @param resource $fd
     * @param int $flag
     * @param callable $func
     */
    public function add($fd, $flag, $func);
    
    /**
     * 事件删除
     * @param resource $fd
     * @param int $flag
     */
    public function del($fd, $flag);
    
    /**
     * 轮询
     */
    public function loop();
}

class Select implements BaseEvent
{
    /**
     * 记录所有事件处理函数及参数
     * @var array
     */
    public $allEvents = array();
    
    /**
     * 记录所有信号处理函数及参数
     * @var array
     */
    public $signalEvents = array();
    
    /**
     * 监听的读描述符
     * @var array
     */
    public $readFds = array();
    
    /**
     * 监听的写描述符
     * @var array
     */
    public $writeFds = array();
    
    /**
     * 搞个fd，避免 $readFds $writeFds 都为空时select 失败
     * @var resource
     */
    public $channel = null;
    
    /**
     *  读超时 毫秒
     * @var integer
     */
    protected $readTimeout = 1000;
    
    /**
     * 写超时 毫秒
     * @var integer
     */
    protected $writeTimeout = 1000;
    
    /**
     * 构造函数 创建一个管道，避免select空fd
     * @return void
     */
    public function __construct()
    {
        $this->channel = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if($this->channel)
        {
            stream_set_blocking($this->channel[0], 0);
            $this->readFds[0] = $this->channel[0];
        }
    }
   
    /**
     * 添加事件
     * @see \Man\Core\Events\BaseEvent::add()
     */
    public function add($fd, $flag, $func, $args = null)
    {
        // key
        $fd_key = (int)$fd;
        switch ($flag)
        {
            // 可读事件
            case self::EV_READ:
                $this->allEvents[$fd_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                $this->readFds[$fd_key] = $fd;
                break;
            // 写事件 目前没用到，未实现
            case self::EV_WRITE:
                $this->allEvents[$fd_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                $this->writeFds[$fd_key] = $fd;
                break;
            // 信号处理事件
            case self::EV_SIGNAL:
                $this->signalEvents[$fd_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
        }
        
        return true;
    }
    
    /**
     * 回调信号处理函数
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->signalEvents[$signal][self::EV_SIGNAL]['func'], array($signal, self::EV_SIGNAL, $signal));
    }
    
    /**
     * 删除某个fd的某个事件
     * @see \Man\Core\Events\BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch ($flag)
        {
            // 可读事件
            case self::EV_READ:
                unset($this->allEvents[$fd_key][$flag], $this->readFds[$fd_key]);
                if(empty($this->allEvents[$fd_key]))
                {
                    unset($this->allEvents[$fd_key]);
                }
                break;
            // 可写事件
            case self::EV_WRITE:
                unset($this->allEvents[$fd_key][$flag], $this->writeFds[$fd_key]);
                if(empty($this->allEvents[$fd_key]))
                {
                    unset($this->allEvents[$fd_key]);
                }
                break;
            // 信号
            case self::EV_SIGNAL:
                unset($this->signalEvents[$fd_key]);
                pcntl_signal($fd, SIG_IGN);
                break;
        }
        return true;
    }
    /**
     * 事件轮训库主循环
     * @see \Man\Core\Events\BaseEvent::loop()
     */
    public function loop()
    {
        $e = null;
        while (1)
        {
            $read = $this->readFds;
            $write = $this->writeFds;
            // stream_select false：出错 0：超时
            if(!($ret = @stream_select($read, $write, $e, 1)))
            {
                // 超时
                if($ret === 0)
                {
                }
                // 被系统调用或者信号打断
                elseif($ret === false)
                {
                }
                // 触发信号处理函数
                function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
                continue;
            }
            // 触发信号处理函数
            function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
            
            // 检查所有可读描述符
            if($read)
            {
                foreach($read as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->allEvents[$fd_key][self::EV_READ]))
                    {
                        call_user_func_array($this->allEvents[$fd_key][self::EV_READ]['func'], array($this->allEvents[$fd_key][self::EV_READ]['fd'], self::EV_READ,  $this->allEvents[$fd_key][self::EV_READ]['args']));
                    }
                }
            }
            
            // 检查可写描述符
            if($write)
            {
                foreach($write as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->allEvents[$fd_key][self::EV_WRITE]))
                    {
                        call_user_func_array($this->allEvents[$fd_key][self::EV_WRITE]['func'], array($this->allEvents[$fd_key][self::EV_WRITE]['fd'], self::EV_WRITE,  $this->allEvents[$fd_key][self::EV_WRITE]['args']));
                    }
                }
            }
        }
    }
    
}

/*
$worker = new Worker("tcp://0.0.0.0:1234");
$worker->onConnect = function($connection)
{
    echo "connected\n"; 
    //var_dump($connection);
};
$worker->onMessage = function($connection, $data)
{
    $connection->send($data);
};
$worker->onClose = function($connection)
{
    echo "closed\n";
    //var_dump($connection);
};
$worker->run();
*/
