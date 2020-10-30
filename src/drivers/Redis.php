<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-10-29
 * Time: 15:46
 */

namespace xyqWeb\config\drivers;


class Redis extends ConfigFactory
{
    /**
     * @var \Redis
     */
    private static $_instance;

    /**
     * Redis constructor.
     * @throws ConfigException
     */
    public function __construct(array $params)
    {
        if (!extension_loaded('redis')) {
            throw new ConfigException('please install Redis extension');
        }
        $this->setParams($params);
        if (!(self::$_instance instanceof \Redis)) {
            self::$_instance = $this->getInstance('redis');
        }
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
        return self::$_instance->set($this->buildKey($key), $value);
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
        $key = $this->buildKey($key);
        if (self::$_instance->exists($key)) {
            return self::$_instance->get($key);
        } else {
            self::$_instance->set($key, '');
            return null;
        }
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
            $finalKey = $this->buildKey($parentKey);
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
     * 删除配置项
     *
     * @author xyq
     * @param string $key
     * @return bool
     */
    public function delete(string $key) : bool
    {
        $key = $this->buildKey($key);
        if (self::$_instance->exists($key)) {
            return (bool)self::$_instance->del($key);
        } else {
            return false;
        }
    }

    /**
     * 构建key
     *
     * @author xyq
     * @param string $key
     * @return string
     */
    private function buildKey(string $key) : string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['/', '_'], ' ', $key))));
    }

    /**
     * 获取所有子节点
     *
     * @author xyq
     * @param string $key
     * @return array
     */
    public function getChildren(string $key)
    {
        $key = $this->buildKey($key);
        $children = self::$_instance->keys($key . '*');
        if (empty($children)) {
            return [];
        }
        return array_map([$this, 'getOriginalKey'], $children);
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
        return strtolower(preg_replace(['/^' . $this->redisPrefix . '/', '/(?<!^)([A-Z])/'], ['', '_\\1'], $key));
    }
}