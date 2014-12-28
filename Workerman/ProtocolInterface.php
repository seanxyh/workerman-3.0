<?php
namespace Workerman;
use Workerman\Connection;

/**
 * Protocol interface
* @author walkor <walkor@workerman.net>
 */
interface ProtocolInterface
{
    /**
     * 
     * @param Connection $connection
     * @param string $recv_buffer
     */
    public static function input(Connection $connection, &$recv_buffer);
    
    /**
     * 
     * @param Connection $connection
     * @param string $buffer
     */
    public static function encode(Connection $connection, $buffer);
    
    /**
     * 
     * @param Connection $connection
     * @param mixed $data
     */
    public static function decode(Connection $connection, $data);
}
