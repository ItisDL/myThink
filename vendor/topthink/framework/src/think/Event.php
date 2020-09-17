<?php
declare(strict_types=1);

namespace think;

use ReflectionClass;
use ReflectionMethod;

class Event
{
    // 监听者
    protected $listener = [];

    // 事件别名
    protected $bind = [
        'AppInit' => event\AppInit::class,
        'HttpRun' => event\HttpRun::class,
        'HttpEnd' => event\HttpEnd::class,
        'RouteLoaded' => event\RouteLoaded::class,
        'LogWrite' => event\LogWrite::class,
    ];

    // 应用对象
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // 批量注册事件监听
    public function listenEvents(array $events)
    {
        foreach ($events as $event => $listeners) {
            if (isset($this->bind[$event])) {
                $event = $this->bind[$event];
            }
            $this->listener[$event]
                = array_merge(
                $this->listener[$event] ??
                [],
                $listeners
            );
        }

        return $this;
    }

    // 注册事件监听
    public function listen(string $event, $listener, bool $first = false)
    {
        if (isset($this->bind[$event])) {
            $event = $this->bind[$event];
        }
        if ($first && isset($this->listener[$event])) {
            // 在listener的头部插入监听者
            array_unshift($this->listener[$event], $listener);
        } else {
            // 在listener的尾部插入监听者
            $this->listener[$event][] = $listener;
        }
        return $this;
    }

    // 是否存在事件监听
    public function hasListener(string $event): bool
    {
        // 是否是事件列表的事件
        if (isset($this->bind[$event])) {
            // 如果是 就返回类的完整名称
            $event = $this->bind[$event];
        }
        return isset($this->listener[$event]);
    }

    // 移除事件监听
    public function remove(string $event): void
    {
        if (isset($this->bind[$event])) {
            $event = $this->bind[$event];
        }
        unset($this->listener[$event]);
    }

    // 指定事件别名标识
    public function bind(array $events)
    {
        $this->bind = array_merge($this->bind, $events);
        return $this;
    }

    public function subscribe($subscriber)
    {
        $subscribers = (array)$subscriber;

        foreach ($subscribers as $subscriber) {
            if (is_string($subscriber)) {
                $subscriber = $this->app->make($subscriber);
            }

            if (method_exists($subscriber, 'subscriber')) {
                // 手动订阅
                $subscriber->subscriber($this);
            } else {
                // 智能订阅
                $this->observe($subscriber);
            }
            return $this;
        }
    }

    // 自动注册时间观察者
    public function observe($observer, string $prefix = '')
    {
        if (is_string($observer)) {
            $observer = $this->app->make($observer);
        }
        // 生成反射类
        $reflect = new ReflectionClass($observer);
        // 获取所有的公有方法
        $methods = $reflect->getMethods(ReflectionMethod::IS_PUBLIC);

        // 没有前缀 + 有eventPrefix属性
        if (empty($prefix) && $reflect->hasProperty('eventPrefix')) {
            // 获取属性
            $reflectProperty = $reflect->getProperty('eventPrefix');
            // 设置属性为可访问的
            $reflectProperty->setAccessible(true);
            // 获取属性值
            $prefix = $reflectProperty->getValue($observer);
        }
        foreach ($methods as $method) {
            $name = $method->getName();
            // 查看on是不是在name第一个出现
            if (0 === strpos($name, 'on')) {
                $this->listen($prefix . substr($name, 2), [$observer, $name]);
            }
        }
        return $this;
    }

    // 触发事件
    public function trigger($event, $params = null, bool $once = false)
    {
        // 如果传入的是一个object 则获取其类名
        if (is_object($event)) {
            $params = $event;
            $event = get_class($event);
        }

        $result = [];

        // 查看是否有监听者 没有赋予[]
        $listeners = $this->listener[$event] ?? [];
        // 去重复值 按照原顺序返回
        $listeners = array_unique($listeners, SORT_REGULAR);

        foreach ($listeners as $key => $listener) {
            $result[$key] = $this->dispatch($listener, $params);
            if (false === $result[$key]
                ||
                (!is_null($result[$key]) && $once)) {
                break;
            }
        }
        return $once ? end($result) : $result;
    }

    // 触发事件 只获取一个有效的返回值

    public function until($event, $params = null)
    {
        return $this->trigger($event, $params, true);
    }

    // 执行事件调度
    protected function dispatch($event, $params = null)
    {
        if (!is_string($event)) {
            // 不是字符串则直接赋予call
            $call = $event;
        } elseif (strpos($event, '::')) {
            // 如果存在::则直接赋予call 认为是类
            $call = $event;
        } else {

            $obj = $this->app->make($event);
            $call = [$obj, 'handle'];
        }
        return $this->app->invoke($call, [$params]);
    }
}