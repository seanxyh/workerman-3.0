<?php
namespace Workerman\Protocols;

use \Workerman\Connection\ConnectionInterface;

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
    public static function input($recv_buffer, ConnectionInterface $connection);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param string $buffer
     */
    public static function encode($buffer, ConnectionInterface $connection);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param mixed $data
     */
    public static function decode($data, ConnectionInterface $connection);
}
