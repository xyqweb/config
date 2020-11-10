<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-10-29
 * Time: 15:47
 */

namespace xyqWeb\config\drivers;
use xyqWeb\config\Config;


class QConf extends ConfigFactory
{
    /**
     * @var \Zookeeper \Zookeeper连接对象
     */
    private static $_instance;
    /**
     * @var string 基本路径
     */
    private $bashPath = '';

    /**
     * QConf constructor.
     * @throws ConfigException
     */
    public function __construct(array $params)
    {
        if (!extension_loaded('zookeeper')) {
            throw new ConfigException('please install Zookeeper extension');
        }
        if (!extension_loaded('qconf')) {
            throw new ConfigException('please install QConf extension');
        }
        $this->setParams($params);
        $this->bashPath = $this->params['basePath'];
    }


    /**
     * 获取Zookeeper连接
     *
     * @author xyq
     * @return \Zookeeper
     */
    public function getZookeeper()
    {
        if (!(self::$_instance instanceof \Zookeeper)) {
            self::$_instance = call_user_func([$this, 'getInstance'], 'zookeeper');
        }
        return self::$_instance;
    }

    /**
     * 获取配置
     *
     * @author xyq
     * @param string $key
     * @param bool $ignore_block
     * @return string|null
     * @throws ConfigException
     */
    public function get(string $key, bool $ignore_block = false)
    {
        if (false == $ignore_block && 0 == $this->checkParentStatus($key, $this->getRelation($key))) {
            return null;
        }
        $path = $this->buildPath($key);
        $result = \QConf::getConf($path);
        if (!is_numeric($result) && !is_string($result)) {
            $this->getZookeeper();
            if (!self::$_instance->exists($path)) {
                $this->set($key, '');
                Config::$logDriver->write('qconfConfigMiss.log', "Key: $key");
                $result = null;
            } else {
                $result = self::$_instance->get($path);
            }
        }
        return $result;
    }

    /**
     * 校验板块是否开启
     *
     * @author xyq
     * @param string $key
     * @param $value
     * @return int
     */
    private function checkParentStatus(string $key, $value)
    {
        if (!is_numeric($value)) {
            return 1;
        }
        $parentKey = $this->buildParentKey($key);
        if (!is_string($parentKey)) {
            return 1;
        }
        if (is_array($this->parent) && isset($this->parent[$parentKey])) {
            $parent = $this->parent[$parentKey];
        } else {
            $finalKey = $this->buildPath($parentKey);
            $parent = \QConf::getConf($finalKey);
            if (!is_numeric($parent) && !is_string($parent)) {
                $this->getZookeeper();
                if (!self::$_instance->exists($finalKey)) {
                    $parent = 0;
                } else {
                    $parent = self::$_instance->get($finalKey);
                    $this->parent = [$parentKey => $parent];
                }
            } else {
                $this->parent = [$parentKey => $parent];
            }
        }
        if (empty($parent)) {
            return 0;
        }
        $parent = explode(',', $parent);
        if (in_array($value, $parent)) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * 处理key为zookeeper中的path路径
     *
     * @author xyq
     * @param string $key
     * @return string
     */
    private function buildPath(string $key) : string
    {
        return rtrim($this->bashPath . str_replace(['/', '_'], ['_', '/'], $key), '/');
    }

    /**
     * 设置配置项
     *
     * @author xyq
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function set(string $key, string $value) : bool
    {
        $path = $this->buildPath($key);
        $this->getZookeeper();
        if (!self::$_instance->exists($path)) {
            $this->makePath($path);
            $result = $this->makeNode($path, $value);
            if ($result == $path) {
                return true;
            } else {
                return false;
            }
        } else {
            return self::$_instance->set($path, $value);
        }
    }

    /**
     * 删除配置项
     *
     * @author xyq
     * @param string $key
     * @return bool
     * @throws \Exception
     */
    public function delete(string $key) : bool
    {
        $path = $this->buildPath($key);
        $this->getZookeeper();
        if (!self::$_instance->exists($path)) {
            return false;
        } else {
            return self::$_instance->delete($path);
        }
    }

    /**
     * 创建路径
     *
     * @author xyq
     * @param $path
     * @param string $value
     */
    private function makePath(string $path, string $value = '')
    {
        $parts = explode('/', $path);
        $parts = array_filter($parts);//过滤空值
        $subPath = '';
        while (count($parts) > 1) {
            $subPath .= '/' . array_shift($parts);//数组第一个元素弹出数组
            if (!self::$_instance->exists($subPath)) {
                $this->makeNode($subPath, $value);
            }
        }
    }

    /**
     * 获取所有子节点(暂时不适合redis和zookeeper公共负载)
     *
     * @author xyq
     * @param $path
     * @return mixed
     * @throws \Exception
     */
    public function getChildren(string $path)
    {
        $path = $this->buildPath($path);
        $this->getZookeeper();
        if (!self::$_instance->exists($path)) {
            return [];
        }
        return $this->getAllChildren($path);
    }

    /**
     * 递归获取所有子节点
     *
     * @author xyq
     * @param string $path
     * @param array $childrenArray
     * @return array
     */
    private function getAllChildren(string $path, array &$childrenArray = [])
    {
        $children = self::$_instance->getChildren($path);
        if (!empty($children)) {
            foreach ($children as $child) {
                $child = $path . '/' . $child;
                $childrenArray[] = $this->getOriginalKey($child);
                $this->getAllChildren($child, $childrenArray);
            }
        }
        return $childrenArray;
    }

    /**
     * 创建节点
     *
     * @author xyq
     * @param string $path
     * @param $value
     * @param array $params
     * @return mixed
     */
    private function makeNode(string $path, string $value, array $params = array())
    {
        if (empty($params)) {
            $params = [
                [
                    'perms'  => \Zookeeper::PERM_ALL,
                    'scheme' => 'ip',
                    'id'     => $this->params['authIp'] ?? '0.0.0.0/0',
                ]
            ];
        }
        return self::$_instance->create($path, $value, $params);
    }

    /**
     * 获取原始键值
     *
     * @author xyq
     * @param string $key
     * @return string
     */
    private function getOriginalKey(string $key)
    {
        $prefix = str_replace('/', '\/', $this->bashPath);
        return preg_replace(['/^' . $prefix . '/', '/\//'], ['', '_\\1'], $key);
    }
}
