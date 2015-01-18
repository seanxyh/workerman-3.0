<?php 
namespace Protocols;
/**
 * 以回车为请求结束标记的 文本协议 
 * 协议格式 文本+回车
 * 由于是逐字节读取，效率会有些影响，与JsonProtocol相比JsonProtocol效率会高一些
 * @author walkor
 */
class TextProtocol 
{
    /**
     * 判断数据边界
     * @param string $buffer
     * @return number
     */
    public static function input($buffer)
    {
        $pos = strpos($buffer, "\n");
        return $pos === false ? 0 : $pos+1;
    }

    /**
     * 打包
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        return $data."\n";
    }

    /**
     * 解包
     * @param string $buffer
     * @return mixed
     */
    public static function decode($buffer)
    {
        return trim($buffer);
    }
}
