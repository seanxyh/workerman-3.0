<?php
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Lock;
use \Lib\Store;
use \Protocols\GatewayProtocol;
use \Lib\Context;

require_once __DIR__ . '/../Lib/Autoloader.php';

class BusinessWorker extends Worker
{
    const MAX_RETRY_COUNT = 5;
    
    public $gatewayConnections = array();
    
    public $badGatewayAddress = array();
    
    protected function onStart()
    {
        Timer::add(1, array($this, 'checkGatewayConnections'));
        $this->checkGatewayConnections();
        Gateway::setBusinessWorker($this);
    }
    
    public function onGatewayMessage($packageage)
    {
        Context::$client_ip = $package->header['client_ip'];
        Context::$client_port = $package->header['client_port'];
        Context::$local_ip = $package->header['local_ip'];
        Context::$local_port = $package->header['local_port'];
        Context::$client_id = $package->header['client_id'];
        $_SERVER = array(
                'REMOTE_ADDR' => Context::$client_ip,
                'REMOTE_PORT' => Context::$client_port,
                'GATEWAY_ADDR' => Context::$local_ip,
                'GATEWAY_PORT'  => Context::$local_port,
                'GATEWAY_CLIENT_ID' => Context::$client_id,
        );
        if($package->ext_data != '')
        {
            $_SESSION = Context::sessionDecode($package->ext_data);
        }
        else
        {
            $_SESSION = null;
        }
        // 备份一次$package->ext_data，请求处理完毕后判断session是否和备份相等，不相等就更新session
        $session_str_copy = $package->ext_data;
        $cmd = $package->header['cmd'];
    
        try{
            switch($cmd)
            {
                case GatewayProtocol::CMD_ON_GATEWAY_CONNECTION:
                    Event::onGatewayConnect(Context::$client_id);
                    break;
                case GatewayProtocol::CMD_ON_MESSAGE:
                    Event::onMessage(Context::$client_id, $package->body);
                    break;
                case GatewayProtocol::CMD_ON_CLOSE:
                    Event::onClose(Context::$client_id);
                    break;
            }
        }
        catch(\Exception $e)
        {
            $msg = 'client_id:'.Context::$client_id."\tclient_ip:".Context::$client_ip."\n".$e->__toString();
            $this->log($msg);
        }
    
        $session_str_now = $_SESSION !== null ? Context::sessionEncode($_SESSION) : '';
        if($session_str_copy != $session_str_now)
        {
            Gateway::updateSocketSession(Context::$socket_id, $session_str_now);
        }
    
        Context::clear();
    }
    
    public function checkGatewayConnections()
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::instance('gateway')->get($key);
        if(empty($addresses_list))
        {
            return;
        }
        foreach($addresses_list as $addr)
        {
            if(!isset($this->gatewayConnections[$addr]))
            {
                $gateway_connection = new AsyncTcpConnection("GatewayProtocol://$addr", self::$_globalEvent);
                $gateway_connection->remoteAddress = $addr;
                $gateway_connection->onConnect = array($this, 'onConnectGateway');
                $gateway_connection->onError = array($this, 'onError');
            }
        }
    }
    
    public function onConnectGateway($connection)
    {
        $this->gatewayConnections[$connection->remoteAddress] = $connection;
        unset($this->badGatewayAddress[$connection->remoteAddress]);
    }
    
    public function onError($connection, $error_no, $error_msg)
    {
         $this->tryToDeleteGatewayAddress($connection->remoteAddress, $error_msg);
    }
    
    public function tryToDeleteGatewayAddress($addr, $errstr)
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        if(!isset($this->badGatewayAddress[$addr]))
        {
            $this->badGatewayAddress[$addr] = 0;
        }
        // 删除连不上的端口
        if($this->badGatewayAddress[$addr]++ > self::MAX_RETRY_COUNT)
        {
            Lock::get();
            $addresses_list = Store::instance('gateway')->get($key);
            unset($addresses_list[$addr]);
            Store::instance('gateway')->set($key, $addresses_list);
            Lock::release();
            $this->log("tcp://$addr ".$errstr." del $addr from store", false);
        }
    }
}