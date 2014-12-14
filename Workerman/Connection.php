<?php
namespace Workerman;
use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\BaseEvent;
use Workerman\Worker;
use \Exception;

class Connection
{
    const READ_BUFFER_SIZE = 8192;

    const STATUS_CONNECTING = 1;
    
    const STATUS_ESTABLISH = 2;

    const STATUS_CLOSING = 4;
    
    const STATUS_CLOSED = 8;
    
    protected $_connectionId = 0;
    
    protected $_owner = null;
    
    protected $_socket = null;

    protected $_sendBuffer = '';

    protected $_status = self::STATUS_ESTABLISH;
    
    protected $_remoteIp = '';
    
    protected $_remotePort = 0;
    
    protected $_remoteAddress = '';

    public function __construct($owner, $socket)
    {
        $this->_owner = $owner;
        $this->_socket = $socket;
        $this->_connectionId = (int)$socket;
        Worker::$globalEvent->add($this->_socket, BaseEvent::EV_READ, array($this, 'baseRead'));
    }
    
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
           $func($this->_owner, $this, $recv_buffer);
       }
    }

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
               $this->shutdown();
           }
        }
    }

    public function close()
    {
        $this->_status = self::STATUS_CLOSING;
        if($this->_sendBuffer === '')
        {
           $this->shutdown();
        }
    }

    public function shutdown()
    {
       if($this->_owner->onClose)
       {
           $func = $this->_owner->onClose;
           $func($this);
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
