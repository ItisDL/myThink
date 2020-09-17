<?php

declare(strict_types=1);

namespace think;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use think\exception\ClassNotFoundException;
use think\exception\FuncNotFoundException;
use think\helper\Str;

class Container implements ContainerInterface, ArrayAccess, IteratorAggregate, Countable
{
    // 容器对象实例
    protected static $instance;

    // 容器中的对象实例
    protected $instances = [];

    // 容器绑定标识
    protected $bind = [];

    // 容器回调
    protected $invokeCallback = [];

    // 获取当前容器实例
    public static function getInstance()
    {
        // 如果instance没有值
        if (is_null(static::$instance)) {
            // 设置instance为
            static::$instance = new static;
        }
        // 如果instance是一个闭包
        if (static::$instance instanceof Closure) {
            return (static::$instance)();
        }
        return static::$instance;
    }

    // 设置当前容器的实例
    public static function setInstance($instance): void
    {
        static::$instance = $instance;
    }

    // 注册一个容器对象回调
    public function resolving($abstract, Closure $callback = null): void
    {
        // 回调是一个闭包
        if ($abstract instanceof Closure) {
            $this->invokeCallback['*'][] = $abstract;
            return;
        }

        // 别名获取类名
        $abstract = $this->getAlias($abstract);

        $this->invokeCallback[$abstract][] = $callback;
    }

    // 获取容器中的对象实例 不存在则创建
    public static function pull(
        string $abstract,
        array $vars = [],
        bool $newInstance = false
    )
    {
        return static::getInstance()
            ->make($abstract,
                $vars,
                $newInstance);
    }

    // 获取容器中的对象实例
    public function get($abstract)
    {
        if ($this->has($abstract)) {
            return $this->make($abstract);
        }
        throw new ClassNotFoundException('class not exits: ' . $abstract, $abstract);
    }

    // 绑定一个类 闭包 实例 接口实现到容器
    public function bind($abstract, $concrete = null)
    {
        if (is_array($abstract)) {
            //数组循环绑定
            foreach ($abstract as $key => $val) {
                $this->bind($key, $val);
            }
        } elseif ($concrete instanceof Closure) {
            // 闭包直接绑定
            $this->bind[$abstract] = $concrete;
        } elseif (is_object($concrete)) {
            // 对象直接绑定
            $this->instance($abstract, $concrete);
        } else {
            // 获取类名然后绑定
            $abstract = $this->getAlias($abstract);
            $this->bind[$abstract] = $concrete;
        }
        return $this;
    }

    // 别名获取真实类名
    public function getAlias(string $abstract): string
    {
        // 如果bind中存在了别名
        if (isset($this->bind[$abstract])) {
            // 获取bind
            $bind = $this->bind[$abstract];
            // 如果是一个字符串继续调用自身
            if (is_string($bind)) {
                return $this->getAlias($bind);
            }
        }
        // 不存在原样返回
        return $abstract;
    }

    // 绑定一个类实例到容器
    public function instance(string $abstract, $instance)
    {
        // 获取真实类名
        $abstract = $this->getAlias($abstract);
        $this->instances[$abstract] = $instance;
        return $this;
    }

    // 判断容器中是否存在类以及标识
    public function bound(string $abstract): bool
    {
        return
            isset($this->bind[$abstract])
            ||
            isset($this->instances[$abstract]);
    }

    // 判断容器中是否存在类及标识
    public function has($name): bool
    {
        return $this->bound($name);
    }

    // 判断容器中是否存在对象实例
    public function exists(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);
        return isset($this->instances[$abstract]);
    }

    // 创建类的实例 已存在则直接获取
    public function make(string $abstract, array $vars = [], bool $newInstance = false)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])
            && !$newInstance
        ) {
            return $this->instances[$abstract];
        }

        if (isset($this->bind[$abstract]) && $this->bind[$abstract] instanceof Closure) {
            $object = $this->invokeFunction($this->bind[$abstract], $vars);
        } else {
            $object = $this->invokeClass($abstract, $vars);
        }

        if (!$newInstance) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    // 删除容器中的对象实例
    public function delete($name)
    {
        $name = $this->getAlias($name);
        if (isset($this->instances[$name])) {
            unset($this->instances[$name]);
        }
    }

    public function invokeFunction($function, array $vars = [])
    {
        try {
            $reflect = new ReflectionFunction($function);
        } catch (ReflectionException $e) {
            throw new FuncNotFoundException("function not exists");
        }

        $args = $this->bindParams($reflect, $vars);

        return $function(...$args);
    }

    // 调用反射执行类的方法 支持参数绑定
    public function invokeMethod($method, array $vars = [], bool $accessible = false)
    {
        if (is_array($method)) {
            [$class, $method] = $method;
            $class = is_object($class) ? $class : $this->invokeClass($class);
        } else {
            // 分割类名和方法名
            [$class, $method] = explode('::', $method);
        }
        try {
            $reflect = new ReflectionMethod($class, $method);
        } catch (ReflectionException $e) {
            $class = is_object($class) ? get_class($class) : $class;
            throw new FunNotFoundException('method not exists');
        }

        $args = $this->bindParams($reflect, $vars);

        if ($accessible) {
            $reflect->setAccessible($accessible);
        }

        return $reflect->invokeArgs(is_object($class) ? $class : null, $args);

    }

    // 调用反射执行类的方法 支持参数绑定
    public function invokeReflectMethod($instance, $reflect, array $vars = [])
    {
        $args = $this->bindParams($reflect, $vars);
        return $reflect->invokeArgs($instance, $args);
    }

    // 调用反射执行callable 支持参数绑定
    public function invoke($callable, array $vars = [], bool $accessible = false)
    {
        if ($callable instanceof Closure) {
            return $this->invokeFunction($callable, $vars);
        } elseif (is_string($callable) && false === strpos($callable, '::')) {
            return $this->invokeFunction($callable, $vars);
        } else {
            return $this->invokeMethod($callable, $vars, $accessible);
        }
    }

    // 调用反射类执行类的实例化 支持依赖注入
    public function invokeClass(string $class, array $vars = [])
    {
        try {
            $reflect = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw ClassNotFoundException('class not exists: ');
        }

        if ($reflect->hasMethod('__make')) {
            // 获取__make方法
            $method = $reflect->getMethod('__make');
            // 方法是公有和静态的
            if ($method->isPublic() && $method->isStatic()) {
                $args = $this->bindParams($method, $vars);
                // 用指定的参数调用方法并返回结果
                return $method->invokeArgs(null, $args);
            }
        }

        // 获取构造函数
        $constructor = $reflect->getConstructor();


        $args = $constructor ? $this->bindParams($constructor, $vars) : [];

        $object = $reflect->newInstanceArgs($args);

        $this->invokeAfter($class, $object);

        return $object;
    }
}