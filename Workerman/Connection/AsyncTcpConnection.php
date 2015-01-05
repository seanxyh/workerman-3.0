<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;


define(ERROR_CONNECT_FAIL, 1);

/**
 * async connection 
 * @author walkor<walkor@workerman.net>
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * status
     * @var int
     */
    protected $_status = self::STATUS_CONNECTING;
    
    /**
     * when connect success , onConnect will be run
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * create a connection
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($remote_address, EventInterface $event)
    {
        list($scheme, $address) = explode(':', $remote_address, 2);
        if($scheme != 'tcp')
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
        $this->_event = $event;
        $this->_socket = stream_socket_client("tcp://$address", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        if(!$this->_socket)
        {
            $this->emitError(ERROR_CONNECT_FAIL, $errstr);
            return;
        }
        
        $this->_event->add($this->_socket, EventInterface::EV_READ, array($this, 'checkConnection'));
    }
    
    protected function emitError($code, $msg)
    {
        if($this->onError)
        {
            $func = $this->onError;
            try{
                $func($this, $code, $msg);
            }
            catch(Exception $e)
            {
                echo $e;
            }
        }
    }
    
    public function checkConnection($socket)
    {
        $this->_event->del($this->_socket, EventInterface::EV_READ);
        // php bug ?
        if(!feof($this->_socket) && !feof($this->_socket))
        {
            $this->_event->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            if($this->_sendBuffer)
            {
                $this->_event->add($this->_socket, EventInterface::EV_READ, array($this, 'baseWrite'));
            }
            $this->_status = self::STATUS_ESTABLISH;
        }
        else
        {
            $this->emitError(ERROR_CONNECT_FAIL, 'connect fail, maybe timedout');
        }
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
        
        if($this->_status === self::STATUS_CONNECTING)
        {
            $this->_sendBuffer .= $send_buffer;
            return null;
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
    
}
