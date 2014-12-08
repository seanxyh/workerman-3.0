<?php
namespace Workerman;
use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\BaseEvent;

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
