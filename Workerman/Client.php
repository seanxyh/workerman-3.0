<?php
namespace Workerman;

define('WORKERMAN_CLIENT_SYNC', 1);

define('WORKERMAN_CLIENT_ASYNC', 2);

define('ERROR_SEND_FAIL', 30001);

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
