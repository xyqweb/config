<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-10-29
 * Time: 15:46
 */

namespace xyqWeb\config\drivers;


abstract class ConfigFactory
{
    /**
     * @var array $params 配置项
     */
    protected $params = [];
    /**
     * @var string $redisPrefix redis
     */
    protected $redisPrefix = '';
    /**
     * @var array|null $parent bind_block value
     */
    protected $parent = null;

    /**
     * 设置全局配置的参数
     *
     * @author xyq
     * @param array $params
     */
    protected function setParams(array $params)
    {
        $this->params = $params;
    }

    /**
     * 获取配置项
     *
     * @author xyq
     * @param string $key
     * @param bool $ignore_block
     * @return string|null
     */
    abstract public function get(string $key, bool $ignore_block = false);

    /**
     * 设置配置项
     *
     * @author xyq
     * @param string $key
     * @param string $value
     * @return bool
     */
    abstract public function set(string $key, string $value);

    /**
     * 删除配置项
     *
     * @author xyq
     * @param string $key
     * @return bool
     */
    abstract public function delete(string $key);

    /**
     * 获取所有子节点
     *
     * @author xyq
     * @param string $key
     * @return array
     */
    abstract public function getChildren(string $key);

    /**
     * 获取连接对象
     *
     * @author xyq
     * @param string $instance
     * @param string $prefix
     * @return \Redis|\Zookeeper|null
     * @throws ConfigException
     */
    protected function getInstance(string $instance, string $prefix = '')
    {
        $_instance = null;
        if ('redis' == $instance) {
            if (isset($this->params['prefix']) && !empty($this->params['prefix'])) {
                $prefix = $this->params['prefix'];
            } elseif (empty($prefix)) {
                $prefix = 'config';
            }
            $_instance = $this->getRedisInstance($prefix);
        } elseif ('zookeeper' == $instance) {
            $_instance = $this->getZookeeperInstance();
        } else {
            throw new ConfigException('only support redis、zookeeper');
        }
        return $_instance;
    }

    /**
     * 获取redis连接
     *
     * @author xyq
     * @param string $prefix
     * @return \Redis
     * @throws ConfigException
     */
    private function getRedisInstance(string $prefix = '')
    {
        $_instance = new \Redis();
        if (!isset($this->params['host']) || !isset($this->params['port']) || !isset($this->params['password']) || !isset($this->params['database'])) {
            throw new ConfigException('Redis config error,please check');
        }
        $_instance->connect($this->params['host'], $this->params['port'], 5);
        if (!$_instance) {
            throw new ConfigException('redis connect fail，host：' . json_encode($this->params['host'], JSON_UNESCAPED_UNICODE));
        }
        if ($this->params['password'] !== null) {
            $_instance->auth($this->params['password']);
        }
        if ($this->params['database'] !== null) {
            $_instance->select($this->params['database']);
        } else {
            $_instance->select(0);
        }
        if (!empty($prefix)) {
            $_instance->setOption(\Redis::OPT_PREFIX, $prefix);
        }
        $this->redisPrefix = $prefix;
        return $_instance;
    }

    /**
     * 获取zookeeper连接
     *
     * @author xyq
     * @return \Zookeeper|null
     * @throws ConfigException
     */
    private function getZookeeperInstance()
    {
        $_instance = null;
        if (!isset($this->params['address']) || empty($this->params['address'])) {
            throw new ConfigException('no zookeeper address，please check');
        }
        $address = explode(',', $this->params['address']);
        if (empty($address)) {
            throw new ConfigException('no zookeeper address,please check config');
        }
        if (count($address) > 1) {
            for ($i = 0; $i < 3; $i++) {
                $index = array_rand($address, 1);
                try {
                    $_instance = new \Zookeeper($address[$index], null, 1000);
                    $_instance->get('/zookeeper/quota');
                    break;
                } catch (\Exception $e) {
                    unset($address[$index]);
                    if (2 == $i) {
                        throw new ConfigException('zookeeper connect fail' . $e->getMessage());
                    }
                }
            }
        } else {
            $address = current($address);
            for ($i = 0; $i < 3; $i++) {
                try {
                    $_instance = new \Zookeeper($address, null, 1000);
                    $_instance->get('/zookeeper/quota');
                    break;
                } catch (\Exception $e) {
                    if (2 == $i) {
                        throw new ConfigException('zookeeper connect fail' . $e->getMessage());
                    }
                }
            }
        }
        return $_instance;
    }

    /**
     * 获取键值的关联关系
     *
     * @author xyq
     * @param string $key
     * @return mixed|null
     * @throws ConfigException
     */
    protected function getRelation(string $key)
    {
        if (isset($this->params['relation_file']) && file_exists($this->params['relation_file'])) {
            $relationFile = pathinfo($this->params['relation_file']);
            if (!isset($relationFile['extension']) || 'php' != $relationFile['extension']) {
                throw new ConfigException('The relation file must be PHP');
            }
            $relationArray = require $this->params['relation_file'];
        } else {
            $relationArray = require dirname(__DIR__) . '/relation.php';
        }
        if (!is_array($relationArray)) {
            throw new ConfigException('The relation file must return an array');
        }
        if (empty($relationArray)) {
            return null;
        }
        $key = explode('_', $key);
        array_shift($key);
        $key = implode('_', $key);
        if (isset($relationArray[$key])) {
            return $relationArray[$key];
        } else {
            return null;
        }
    }

    /**
     * 构建上级键值
     *
     * @author xyq
     * @param string $key
     * @return string|null
     */
    protected function buildParentKey(string $key)
    {
        $key = explode('_', $key);
        $first = array_shift($key);
        //兼容非标准格式配置
        if (!is_numeric($first)) {
            return null;
        }
        return $first . '_bind_block';
    }
}