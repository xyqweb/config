<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-10-29
 * Time: 15:28
 */

namespace xyqWeb\config;


use xyqWeb\config\drivers\ConfigException;
use xyqWeb\config\drivers\ConfigFactory;

class Config
{
    /**
     * @var $driver drivers\Zookeeper|drivers\Redis|drivers\QConf
     */
    protected static $driver;

    /**
     * Config constructor.
     * @throws ConfigException
     */
    protected function __construct()
    {
        if (defined('YII_ENV')) {
            $configStorageMedium = \Yii::$app->params['configStorageMedium'];
            $configStorageParams = \Yii::$app->params['configStorageParams'];
        } elseif (defined('APP_ENVIRONMENT')) {
            $configStorageMedium = \Phalcon\Di::getDefault()->get('config')->params->configStorageMedium;
            $configStorageParams = \Phalcon\Di::getDefault()->get('config')->params->configStorageParams->toArray();
        } else {
            $file = __DIR__ . '/config.json';
            $config = file_exists($file) ? json_decode(file_get_contents(__DIR__ . '/config.json'), true) : null;
            if (!is_array($config)) {
                throw new ConfigException('no config,please check');
            }
            $configStorageMedium = $config['configStorageMedium'] ?? [];
            $configStorageParams = $config['configStorageParams'] ?? [];
        }
        $class = '\xyqWeb\config\drivers\\' . $configStorageMedium;
        if (!class_exists($class)) {
            throw new ConfigException('no driver,please check');
        }
        self::$driver = new $class($configStorageParams);
    }

    /**
     * 初始化对象
     *
     * @author xyq
     * @return drivers\QConf|drivers\Redis|drivers\Zookeeper
     * @throws ConfigException
     */
    protected static function init()
    {
        if (!self::$driver instanceof ConfigFactory) {
            new static();
        }
        return self::$driver;
    }

    /**
     * 获取配置项
     *
     * @author xyq
     * @param string $key
     * @param bool $ignore_block
     * @return bool|string|null
     * @throws ConfigException
     */
    public static function get(string $key, bool $ignore_block = false)
    {
        self::checkKey($key);
        return self::init()->get($key, $ignore_block);
    }

    /**
     * 设置配置项
     *
     * @author xyq
     * @param string $key
     * @param string $value
     * @return bool
     * @throws ConfigException
     */
    public static function set(string $key, string $value)
    {
        self::checkKey($key);
        return self::init()->set($key, $value);
    }

    /**
     * 删除配置项
     *
     * @author xyq
     * @param string $key
     * @return bool
     * @throws ConfigException
     */
    public static function delete(string $key)
    {
        self::checkKey($key);
        return self::init()->delete($key);
    }

    /**
     * 获取所子节点有
     *
     * @author xyq
     * @param string $key
     * @return array
     * @throws ConfigException
     */
    public static function getChildren(string $key)
    {
        self::checkKey($key);
        return self::init()->getChildren($key);
    }

    /**
     * 校验配置项键值
     *
     * @author xyq
     * @param string $key
     * @throws ConfigException
     */
    private static function checkKey(string $key)
    {
        if (empty($key)) {
            throw new ConfigException('获取配置参数错误');
        }
        if (0 === strpos($key, '_') || is_numeric(strpos($key, '__'))) {
            throw new ConfigException('获取配置参数' . $key . '错误');
        }
    }
}