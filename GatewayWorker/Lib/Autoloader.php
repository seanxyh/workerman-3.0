<?php
namespace GatewayWorker\Lib;

class AutoLoader
{
    protected static $_rootPath = '';
    
    public static function setRootPath($root_path)
    {
        self::$_rootPath = $root_path;
        spl_autoload_register('loadByNamespace');
    }
    
    public static function loadByNamespace($name)
    {
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
        $class_file = self::$_rootPath . $class_path.'.php';
        if(is_file($class_file))
        {
            require_once($class_file);
            if(class_exists($name, false))
            {
                return true;
            }
        }
        return false;
    }
}