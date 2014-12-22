<?php
namespace Workerman;
use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\BaseEvent;
use Workerman\Worker;
use \Exception;

/**
 * connection 
 * @author walkor<walkor@workerman.net>
 */
class Connection
{
    /**
     * when recv data from client ,how much bytes to read
     * @var unknown_type
     */
    const READ_BUFFER_SIZE = 8192;

    /**
     * connection status connecting
     * @var int
     */
    const STATUS_CONNECTING = 1;
    
    /**
     * connection status establish
     * @var int
     */
    const STATUS_ESTABLISH = 2;

    /**
     * connection status closing
     * @var int
     */
    const STATUS_CLOSING = 4;
    
    /**
     * connection status closed
     * @var int
     */
    const STATUS_CLOSED = 8;
    
    /**
     * connction id
     * @var int
     */
    protected $_connectionId = 0;
    
    /**
     * worker
     * @var Worker
     */
    protected $_owner = null;
    
    /**
     * the socket
     * @var resource
     */
    protected $_socket = null;

    /**
     * the buffer to send
     * @var string
     */
    protected $_sendBuffer = '';

    /**
     * connection status
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISH;
    
    /**
     * remote ip
     * @var string
     */
    protected $_remoteIp = '';
    
    /**
     * remote port
     * @var int
     */
    protected $_remotePort = 0;
    
    /**
     * remote address
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * create a connection
     * @param Worker $owner
     * @param resource $socket
     */
    public function __construct($owner, $socket)
    {
        $this->_owner = $owner;
        $this->_socket = $socket;
        $this->_connectionId = (int)$socket;
        Worker::$globalEvent->add($this->_socket, BaseEvent::EV_READ, array($this, 'baseRead'));
    }
    
    /**
     * send buffer to client
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        if($this->_sendBuffer === '')
        {
            $len = fwrite($this->_socket, $send_buffer);
            if($len === strlen($send_buffer))
            {
                return true;
            }
            elseif($len > 0)
            {
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                if(feof($this->_socket))
                {
                    Worker::$workerStatistics['send_fail']++;
                    $this->shutdown();
                    return;
                }
                $this->_sendBuffer = $send_buffer;
            }
        }
        if($this->_sendBuffer !== '')
        {
            $this->_sendBuffer .= $send_buffer;
            Worker::$globalEvent->add($this->_socket, BaseEvent::EV_WRITE, array($this, 'baseWrite'));
        }
    }
    
    /**
     * get remote ip
     * @return string
     */
    public function getRemoteIp()
    {
        if(!$this->_remoteIp)
        {
            if($this->_remoteAddress = stream_socket_get_name($this->_socket, false))
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
            }
        }
        return $this->_remoteIp;
    }
    
    /**
     * get remote port
     */
    public function getRemotePort()
    {
        if(!$this->_remotePort)
        {
            if($this->_remoteAddress = stream_socket_get_name($this->_socket, false))
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
            }
        }
        return $this->_remotePort;
    }

    /**
     * when socket is readable 
     * @param resource $socket
     * @return void
     */
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
       if($recv_buffer !== '' && $this->_owner->onMessage)
       {
           $func = $this->_owner->onMessage;
           Worker::$workerStatistics['total_request']++;
           try 
           {
               $func($this->_owner, $this, $recv_buffer);
           }
           catch(Exception $e)
           {
               Worker::$workerStatistics['throw_exception']++;
               echo $e;
           }
       }
    }

    /**
     * when socket is writeable
     * @return void
     */
    public function baseWrite()
    {
        $len = fwrite($this->_socket, $this->_sendBuffer);
        if($len === strlen($this->_sendBuffer))
        {
            Worker::$globalEvent->del($this->_socket, BaseEvent::EV_WRITE);
            $this->_sendBuffer = '';
            if($this->_status == self::STATUS_CLOSING)
            {
                $this->shutdown();
            }
            return true;
        }
        if($len > 0)
        {
           $this->_sendBuffer = substr($this->_sendBuffer, $len);
        }
        else
        {
           if(feof($this->_socket))
           {
               Worker::$workerStatistics['send_fail']++;
               $this->shutdown();
           }
        }
    }

    /**
     * close the connection
     * @void
     */
    public function close()
    {
        $this->_status = self::STATUS_CLOSING;
        if($this->_sendBuffer === '')
        {
           $this->shutdown();
        }
    }

    /**
     * shutdown the connection
     * @void
     */
    public function shutdown()
    {
       if($this->_owner->onClose)
       {
           $func = $this->_owner->onClose;
           try
           {
               $func($this);
           }
           catch (Exception $e)
           {
               Worker::$workerStatistics['throw_exception']++;
               echo $e;
           }
       }
       Worker::$globalEvent->del($this->_socket, BaseEvent::EV_READ);
       Worker::$globalEvent->del($this->_socket, BaseEvent::EV_WRITE);
       @fclose($this->_socket);
       unset($this->_owner->connections[$this->_connectionId]);
       $this->_status = self::STATUS_CLOSED;
       if(Worker::isStopingAll() && Worker::allWorkHasBeenDone())
       {
           exit(0);
       }
    }
}
