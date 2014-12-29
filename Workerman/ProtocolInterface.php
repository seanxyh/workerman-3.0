<?php
namespace Workerman;
use Workerman\Connection\ConnectionInterface;

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
    public static function input(ConnectionInterface $connection, $recv_buffer);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param string $buffer
     */
    public static function encode(ConnectionInterface $connection, $buffer);
    
    /**
     * 
     * @param ConnectionInterface $connection
     * @param mixed $data
     */
    public static function decode(ConnectionInterface $connection, $data);
}
