<?php
namespace Workerman;

/**
 * Protocol interface
* @author walkor <walkor@workerman.net>
 */
interface ProtocolInterface
{
    /**
     * 
     * @param ConnectionInterface $connection
     * @param string $recv_buffer
     */
    public static function input($connection, $recv_buffer);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param string $buffer
     */
    public static function encode($connection, $buffer);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param mixed $data
     */
    public static function decode($connection, $data);
}
