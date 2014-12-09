<?php 
namespace Workerman\Events;
/**
 * 
 * libevent事件轮询库的封装
 * 
 * @author walkor <walkor@workerman.net>
 */
class Libevent implements BaseEvent
{
    /**
     * eventBase实例
     * @var object
     */
    protected $eventBase = null;
    
    /**
     * 记录所有监听事件 
     * @var array
     */
    protected $allEvents = array();
    
    /**
     * 记录信号回调函数
     * @var array
     */
    protected $eventSignal = array();
    
    /**
     * 初始化eventBase
     * @return void
     */
    public function __construct()
    {
        $this->_eventBase = event_base_new();
    }
   
    /**
     * 添加事件
     * @see BaseEvent::add()
     */
    public function add($fd, $flag, $func)
    {
        $fd_key = (int)$fd;
        
        if ($flag == self::EV_SIGNAL)
        {
            $real_flag = EV_SIGNAL | EV_PERSIST;
            // 创建一个用于监听的event
            $this->_eventSignal[$fd_key] = event_new();
            // 设置监听处理函数
            if(!event_set($this->_eventSignal[$fd_key], $fd, $real_flag, $func, null))
            {
                return false;
            }
            // 设置event base
            if(!event_base_set($this->_eventSignal[$fd_key], $this->_eventBase))
            {
                return false;
            }
            // 添加事件
            if(!event_add($this->_eventSignal[$fd_key]))
            {
                return false;
            }
            return true;
        }
        
        $real_flag = $flag == self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;
        
        // 创建一个用于监听的event
        $this->_allEvents[$fd_key][$flag] = event_new();
        
        // 设置监听处理函数
        if(!event_set($this->_allEvents[$fd_key][$flag], $fd, $real_flag, $func, null))
        {
            return false;
        }
        
        // 设置event base
        if(!event_base_set($this->_allEvents[$fd_key][$flag], $this->_eventBase))
        {
            return false;
        }
        
        // 添加事件
        if(!event_add($this->_allEvents[$fd_key][$flag]))
        {
            return false;
        }
        return true;
    }
    
    /**
     * 删除fd的某个事件
     * @see Events\BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch($flag)
        {
            // 读事件
            case BaseEvent::EV_READ:
            case BaseEvent::EV_WRITE:
                if(isset($this->_allEvents[$fd_key][$flag]))
                {
                    event_del($this->_allEvents[$fd_key][$flag]);
                }
                unset($this->_allEvents[$fd_key][$flag]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
            case  BaseEvent::EV_SIGNAL:
                if(isset($this->_eventSignal[$fd_key]))
                {
                    event_del($this->_eventSignal[$fd_key]);
                }
                unset($this->_eventSignal[$fd_key]);
        }
        return true;
    }

    /**
     * 轮训主循环
     * @see BaseEvent::loop()
     */
    public function loop()
    {
        event_base_loop($this->_eventBase);
    }
}

