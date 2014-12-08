<?php
namespace workerman\Events;

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
     * @return bool
     */
    public function add($fd, $flag, $func);
    
    /**
     * 事件删除
     * @param resource $fd
     * @param int $flag
     * @return bool
     */
    public function del($fd, $flag);
    
    /**
     * 轮询
     * @return void
     */
    public function loop();
}
