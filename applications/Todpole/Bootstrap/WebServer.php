<?php
use Workerman\Worker;
use \Protocols\Http\Http;
use \Protocols\Http\HttpCache;

/**
 * 
 *  WebServer
 *  HTTP协议
 *  
 * @author walkor <walkor@workerman.net>
 */
class WebServer extends Worker
{
    /**
     * 默认mime类型
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';
    
    /**
     * 服务器名到文件路径的转换
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected $serverRoot = array();
    
    /**
     * mime类型映射关系
     * @var array
     */
    protected static $mimeTypeMap = array();
    
    
    /**
     * add server root
     */
    public  function addRoot($domain, $root_path)
    {
        $this->serverRoot[$domain] = $root_path;
    }
    
    /**
     * 
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        $this->onStart = array($this, 'onStart');
        $this->onMessage = array($this, 'onMessage');
        $this->name = 'WebServer';
        list($scheme, $address) = explode(':', $socket_name, 2);
        parent::__construct('http:'.$address, $context_option);
    }
    
    /**
     * 进程启动的时候一些初始化工作
     */
    public function onStart()
    {
        if(empty($this->serverRoot))
        {
            throw new Exception('server root not set, please use WebServer::addRoot($domain, $root_path) to set server root path');
        }
        // 初始化HttpCache
        HttpCache::init();
        // 初始化mimeMap
        $this->initMimeTypeMap();
    }
    
    /**
     * 初始化mimeType
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = Http::getMimeTypesFile();
        if(!is_file($mime_file))
        {
            $this->notice("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!is_array($items))
        {
            $this->log("get $mime_file mime.type content fail");
            return;
        }
        foreach($items as $content)
        {
            if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
            {
                $mime_type = $match[1];
                $extension_var = $match[2];
                $extension_array = explode(' ', substr($extension_var, 0, -1));
                foreach($extension_array as $extension)
                {
                    self::$mimeTypeMap[$extension] = $mime_type;
                } 
            }
        }
    }
    
    /**
     * 数据接收完整后处理业务逻辑
     */
    public function onMessage($connection, $data)
    {
        // 请求的文件
        $url_info = parse_url($_SERVER['REQUEST_URI']);
        if(!$url_info)
        {
            Http::header('HTTP/1.1 400 Bad Request');
            return $connection->close('<h1>400 Bad Request</h1>');
        }
        
        $path = $url_info['path'];
        
        $path_info = pathinfo($path);
        $extension = isset($path_info['extension']) ? $path_info['extension'] : '' ;
        if($extension == '')
        {
            $path = ($len = strlen($path)) && $path[$len -1] == '/' ? $path.'index.php' : $path . '/index.php';
            $extension = 'php';
        }
        
        $root_dir = isset($this->serverRoot[$_SERVER['HTTP_HOST']]) ? $this->serverRoot[$_SERVER['HTTP_HOST']] : current($this->serverRoot);
        
        $file = "$root_dir/$path";
        
        // 对应的php文件不存在则直接使用根目录的index.php
        if($extension == 'php' && !is_file($file))
        {
            $file = "$root_dir/index.php";
        }
        
        // 请求的文件存在
        if(is_file($file))
        {
            // 判断是否是站点目录里的文件
            if((!($request_realpath = realpath($file)) || !($root_dir_realpath = realpath($root_dir))) || 0 !== strpos($request_realpath, $root_dir_realpath))
            {
                Http::header('HTTP/1.1 400 Bad Request');
                return $connection->close('<h1>400 Bad Request</h1>');
            }
            
            $file = realpath($file);
            
            // 如果请求的是php文件
            if($extension == 'php')
            {
                $cwd = getcwd();
                chdir($root_dir);
                ini_set('display_errors', 'off');
                // 缓冲输出
                ob_start();
                // 载入php文件
                try 
                {
                    // $_SERVER变量
                    $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
                    include $file;
                }
                catch(\Exception $e) 
                {
                    // 如果不是exit
                    if($e->getMessage() != 'jump_exit')
                    {
                        echo $e;
                    }
                }
                $content = ob_get_clean();
                ini_set('display_errors', 'on');
                $connection->close($content);
                chdir($cwd);
                return ;
            }
            
            // 请求的是静态资源文件
            if(isset(self::$mimeTypeMap[$extension]))
            {
               Http::header('Content-Type: '. self::$mimeTypeMap[$extension]);
            }
            else 
            {
                Http::header('Content-Type: '. self::$defaultMimeType);
            }
            
            // 获取文件信息
            $info = stat($file);
            
            $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' GMT' : '';
            
            // 如果有$_SERVER['HTTP_IF_MODIFIED_SINCE']
            if(!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info)
            {
                // 文件没有更改则直接304
                if($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE'])
                {
                    // 304
                    Http::header('HTTP/1.1 304 Not Modified');
                    // 发送给客户端
                    return $connection->close('');
                }
            }
            
            if($modified_time)
            {
                Http::header("Last-Modified: $modified_time");
            }
            // 发送给客户端
           return $connection->close(file_get_contents($file));
        }
        else 
        {
            // 404
            Http::header("HTTP/1.1 404 Not Found");
            return $connection->close('<html><head><title>404 页面不存在</title></head><body><center><h3>404 Not Found</h3></center></body></html>');
        }
    }
}
