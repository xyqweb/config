<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-10-29
 * Time: 15:47
 */

namespace xyqWeb\config\drivers;


class Zookeeper extends ConfigFactory
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
     * Zookeeper constructor.
     * @param array $params
     * @throws ConfigException
     */
    public function __construct(array $params)
    {
        if (!extension_loaded('zookeeper')) {
            throw new ConfigException('please install Zookeeper extension');
        }
        $this->setParams($params);
        $this->bashPath = $this->params['basePath'];
        if (!(self::$_instance instanceof \Zookeeper)) {
            self::$_instance = $this->getInstance('zookeeper');
        }
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
    public function get(string $key, bool $ignore_block = false)
    {
        if (false == $ignore_block && 0 == $this->checkParentStatus($key, $this->getRelation($key))) {
            return null;
        }
        $path = $this->buildPath($key);
        if (!self::$_instance->exists($path)) {
            return null;
        }
        return self::$_instance->get($path);
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
            if (false == self::$_instance->exists($finalKey)) {
                return 0;
            }
            $parent = self::$_instance->get($finalKey);
            $this->parent = [$parentKey => $parent];
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
        if (!self::$_instance->exists($path)) {
            if (!$this->makePath($path)) {
                return false;
            }
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
     */
    public function delete(string $key) : bool
    {
        $path = $this->buildPath($key);
        if (!self::$_instance->exists($path)) {
            return false;
        } else {
            return (bool)self::$_instance->delete($path);
        }
    }

    /**
     * 创建路径
     *
     * @author xyq
     * @param $path
     * @param string $value
     * @return bool
     */
    private function makePath($path, string $value = '')
    {
        $originParts = explode('/', $path);
        $parts = array_filter($originParts);//过滤空值
        if ((count($originParts) - 1) != count($parts)) {
            return false;
        }
        $subPath = '';
        while (count($parts) > 1) {
            $subPath .= '/' . array_shift($parts);//数组第一个元素弹出数组
            if (!self::$_instance->exists($subPath)) {
                $this->makeNode($subPath, $value);
            }
        }
        return true;
    }

    /**
     * 获取所有子节点(暂时不适合redis和zookeeper公共负载)
     *
     * @author xyq
     * @param string $path
     * @return array
     */
    public function getChildren(string $path)
    {
        $path = $this->buildPath($path);
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