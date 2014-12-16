<?php
namespace Workerman\Events;

class Select implements BaseEvent
{
    /**
     * 记录所有事件处理函数及参数
     * @var array
     */
    public $_allEvents = array();
    
    /**
     * 记录所有信号处理函数及参数
     * @var array
     */
    public $_signalEvents = array();
    
    /**
     * 监听的读描述符
     * @var array
     */
    protected $_readFds = array();
    
    /**
     * 监听的写描述符
     * @var array
     */
    protected $_writeFds = array();
    
    /**
     * 添加事件
     * @see Events\BaseEvent::add()
     */
    public function add($fd, $flag, $func)
    {
        // key
        $fd_key = (int)$fd;
        switch ($flag)
        {
            // 可读事件
            case self::EV_READ:
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_readFds[$fd_key] = $fd;
                break;
            // 写事件 目前没用到，未实现
            case self::EV_WRITE:
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_writeFds[$fd_key] = $fd;
                break;
            // 信号处理事件
            case self::EV_SIGNAL:
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                function_exists('pcntl_signal') && pcntl_signal($fd, array($this, 'signalHandler'));
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
        call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }
    
    /**
     * 删除某个fd的某个事件
     * @see Events\BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch ($flag)
        {
            // 可读事件
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            // 可写事件
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            // 信号
            case self::EV_SIGNAL:
                unset($this->_signalEvents[$fd_key]);
                function_exists('pcntl_signal') && pcntl_signal($fd, SIG_IGN);
                break;
        }
        return true;
    }
    /**
     * main loop
     * @see Events\BaseEvent::loop()
     */
    public function loop()
    {
        $e = null;
        while (1)
        {
            // calls signal handlers for pending signals
            function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
            // 
            $read = $this->_readFds;
            $write = $this->_writeFds;
            // waits for $read and $write to change status
            if(!($ret = @stream_select($read, $write, $e, PHP_INT_MAX)))
            {
                // maybe interrupt by sianals, so calls signal handlers for pending signals
                function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
                continue;
            }
            
            // 检查所有可读描述符
            if($read)
            {
                foreach($read as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_READ]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0], array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }
            
            // 检查可写描述符
            if($write)
            {
                foreach($write as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_WRITE]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0], array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }
        }
    }
}
