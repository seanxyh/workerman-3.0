<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * connection 
 * @author walkor<walkor@workerman.net>
 */
class TcpConnection extends ConnectionInterface
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
     * when receive data, onMessage will be run 
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * when connection close, onClose will be run
     * @var callback
     */
    public $onClose = null;
    
    /**
     * when some thing wrong ,onError will be run
     * @var callback
     */
    public $onError = null;
    
    /**
     * protocol
     * @var string
     */
    public $protocol = '';
    
    /**
     * eventloop
     * @var EventInterface
     */
    protected $_event = null;
    
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
     * the buffer read from socket
     * @var string
     */
    protected $_recvBuffer = '';

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
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($socket, EventInterface $event)
    {
        $this->_socket = $socket;
        stream_set_blocking($this->_socket, 0);
        $this->_event = $event;
        $this->_event->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
    }
    
    /**
     * send buffer to client
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        if($this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
        }
        
        if($this->_sendBuffer === '')
        {
            $len = fwrite($this->_socket, $send_buffer);
            if($len === strlen($send_buffer))
            {
                return true;
            }
            
            if($len > 0)
            {
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                if(feof($this->_socket))
                {
                    self::$statistics['send_fail']++;
                    if($this->onError)
                    {
                        $func = $this->onError;
                        $func($this);
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            
            $this->_event->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            return null;
        }
        else
        {
            $this->_sendBuffer .= $send_buffer;
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
       while($buffer = fread($socket, self::READ_BUFFER_SIZE))
       {
          $this->_recvBuffer .= $buffer; 
       }
       
       if($this->_recvBuffer !== '' && $this->onMessage)
       {
           $func = $this->onMessage;
           // protocol has been set
           if($this->protocol)
           {
               $parser = $this->protocol;
               while($one_request_buffer = $parser::input($this->_recvBuffer, $this))
               {
                   $this->_recvBuffer = substr($this->_recvBuffer, strlen($one_request_buffer));
                   self::$statistics['total_request']++;
                   try
                   {
                       $func($this, $parser::decode($one_request_buffer, $this));
                   }
                   catch(Exception $e)
                   {
                       self::$statistics['throw_exception']++;
                       echo $e;
                   }
               }
               return;
           }
           // protocol not set
           self::$statistics['total_request']++;
           try 
           {
               $func($this, $this->_recvBuffer);
           }
           catch(Exception $e)
           {
               self::$statistics['throw_exception']++;
               echo $e;
           }
           $this->_recvBuffer = '';
       }
       else if(feof($socket))
       {
           $this->destroy();
           return;
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
            $this->_event->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            if($this->_status == self::STATUS_CLOSING)
            {
                $this->destroy();
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
               self::$statistics['send_fail']++;
               $this->destroy();
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
           $this->destroy();
        }
    }

    /**
     * destroy the connection
     * @void
     */
    protected function destroy()
    {
       if($this->onClose)
       {
           $func = $this->onClose;
           try
           {
               $func($this);
           }
           catch (Exception $e)
           {
               self::$statistics['throw_exception']++;
               echo $e;
           }
       }
       $this->_event->del($this->_socket, EventInterface::EV_READ);
       $this->_event->del($this->_socket, EventInterface::EV_WRITE);
       @fclose($this->_socket);
       $this->_status = self::STATUS_CLOSED;
    }
}
