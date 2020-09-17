<?php
declare(strict_types=1);

namespace think;

use think\event\AppInit;
use think\helper\Str;
use think\initializer\BootService;
use think\initializer\Error;
use think\initializer\RegisterService;

class App extends Container
{
    const VERSION = '6.0.3';

    protected $appDebug = false;

    protected $beginTime;

    protected $beginMem;

    protected $namespace = 'app';

    protected $rootPath = '';

    protected $thinkPath = '';

    protected $appPath = '';

    protected $routePath = '';

    protected $configExt = '.php';

    // 应用初始化器
    protected $initializers = [
        Error::class,
        RegisterService::class,
        BootService::class,
    ];

    protected $services = [];

    protected $initialized = false;

    // 容器绑定标识
    protected $bind = [
        'app' => APP::class,
        'cache' => Cache::class,
        'config' => Config::class,
        'console' => Console::class,
        'cookie' => Cookie::class,
        'db' => Db::class,
        'env' => Env::class,
        'event' => Event::class,
        'http' => Http::class,
        'lang' => Lang::class,
        'log' => Log::class,
        'middleware' => Middleware::class,
        'request' => Request::class,
        'response' => Response::class,
        'route' => Route::class,
        'validate' => Validate::class,
        'view' => View::class,
        'filesystem' => Filesystem::class,
        'think\DbManager' => Db::class,
        'think\LogManager' => Log::class,
        'think\CacheManager' => Cache::class,
        // 接口依赖注入
        'Psr\Log\LoggerInterface' => Log::class,
    ];

    public function __construct(string $rootPath = '')
    {
        $this->thinkPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $this->rootPath = $rootPath ?
            rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR :
            $this->getDefaultRootPath();
        $this->appPath = $this->rootPath . 'app' . DIRECTORY_SEPARATOR;
        $this->runtimePath = $this->rootPath . 'runtime' . DIRECTORY_SEPARATOR;

        if (is_file($this->appPath . 'provider.php')) {
            $this->bind(include $this->appPath . 'provider.php');
        }

        static::setInstance($this);

        $this->instance('app', $this);
        $this->instance('think\Container', $this);
    }

    /**
     * 注册服务
     * @param Service|string $service 服务
     * @param bool|bool $force 强制重新注册
     * @return mixed
     */
    public function register($service, bool $force = false)
    {
        $registered = $this->getService($service);

        if ($registered && !$force) {
            return $registered;
        }

        if (is_string($service)) {
            $service = new $service($this);
        }

        if (method_exists($service, 'register')) {
            $service->register();
        }

        if (property_exists($service, 'register')) {
            $service->register();
        }

        if (property_exists($service, 'bind')) {
            $this->bind($service->bind);
        }

        $this->services[] = $service;
    }

    // 执行服务
    public function bootService($service)
    {
        if (method_exists($service, 'boot')) {
            return $this->invoke([$service, 'boot']);
        }
    }

    // 获取服务
    public function getService($service)
    {
        // 获取类的名称
        $name = is_string($service) ? $service : get_class($service);
        return array_values(
                array_filter(
                    $this->services, function ($value) use ($name) {
                    return $value instanceof $name;
                }, ARRAY_FILTER_USE_BOTH //同时接受
                )
            )[0] ?? null;
    }

    // 开启调试
    public function debug(bool $debug = true)
    {
        $this->appDebug = $debug;
        return $this;
    }

    // 是否为调试模式
    public function isDebug(): bool
    {
        return $this->appDebug;
    }

    // 设置应用命名空间
    public function setNamespace(string $namespace)
    {
        $this->namespace = $namespace;
        return $this;
    }

    // 获取命名空间
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    // 获取框架版本
    public function version(): string
    {
        return static::VERSION;
    }

    // 获取应用根目录
    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    // 获取应用基础目录
    public function getBasePath(): string
    {
        return $this->rootPath . 'app' . DIRECTORY_SEPARATOR;
    }

    //获取应用目录
    public function getAppPath(): string
    {
        return $this->appPath;
    }

    // 设置应用目录
    public function setAppPath(string $path)
    {
        $this->appPath = $path;
    }

    // 获取运行目录
    public function getRuntimePath(): string
    {
        return $this->runtimePath;
    }

    // 设置运行目录
    public function setRuntimePath(string $path): void
    {
        $this->runtimePath = $path;
    }

    // 获取核心框架目录
    public function getThinkPath(): string
    {
        return $this->thinkPath;
    }

    // 获取应用配置目录
    public function getConfigPath(): string
    {
        return $this->rootPath . 'config' . DIRECTORY_SEPARATOR;
    }

    // 获取配置后缀
    public function getConfigExt(): string
    {
        return $this->configExt;
    }

    // 获取开启时间
    public function getBeginTime(): float
    {
        return $this->beginTime;
    }

    // 获取开启内存
    public function getBeginMem(): int
    {
        return $this->beginMem;
    }

    // 初始化应用
    public function initialize()
    {
        // 初始化标记
        $this->initialized = true;

        $this->beginTime = microtime(true);
        $this->beginMem = memory_get_usage();

        // 环境变量
        if (is_file($this->rootPath . '.env')) {
            $this->env->load($this->rootPath . '.env');
        }
        $this->configExt = $this->env->get('config_ext', '.php');

        $this->debugModeInit();

        // 全局初始化文件
        $this->load();

        // 加载语言
        $langSet = $this->lang->defaultLangSet();

        $this->lang->load($this->thinkPath . 'lang' . DIRECTORY_SEPARATOR . $langSet . '.php');

        // 加载应用语言包
        $this->loadLangPack($langSet);

        // 监听AppInit
        $this->event->trigger(AppInit::class);

        // 设置时区
        date_default_timezone_set($this->config->get('app.default_timezone', 'Asia/Shanghai'));

        // 初始化容器
        foreach ($this->initializers as $initializer) {
            $this->make($initializer)->init($this);
        }

        return $this;
    }

    // 是否初始化过
    public function initialized()
    {
        return $this->initialized;
    }

    // 加载语言包
    public function loadLangPack($langset)
    {
        if (empty($langset)) {
            return;
        }

        // 加载系统语言包 glob 正则获取文件夹下的文件
        $files = glob($this->appPath . 'lang' . DIRECTORY_SEPARATOR . $langset . '.*');
        $this->lang->load($files);

        // 加载扩展语言包
        $list = $this->config->get('lang.extend_list', []);

        if (isset($list[$langset])) {
            $this->lang->load($list[$langset]);
        }
    }

    // 引导应用 执行注册的服务
    public function boot(): void
    {
        // 循环数组 分别执行
        array_walk(
            $this->services, function ($service) {
            $this->bootService($service);
        });
    }

    // 加载应用文件和配置
    protected function load(): void
    {
        $appPath = $this->getAppPath();
        if (is_file($appPath . 'common.php')) {
            include_once $appPath . 'common.php';
        }

        include_once $this->thinkPath . 'helper.php';

        $configPath = $this->getConfigPath();

        $files = [];

        // 获取所有的配置文件
        if (is_dir($configPath)) {
            $files = glob($configPath . '*' . $this->configExt);
        }


        foreach ($files as $file) {
            // pathinfo 数组方式返回路径文件信息 option 返回文件名称
            $this->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($appPath, 'event.php')) {
            $this->loadEvent(include $appPath . 'event.php');
        }

        if (is_file($appPath . 'service.php')) {
            $services = include $appPath . 'service.php';
            foreach ($services as $service) {
                $this->register($service);
            }
        }
    }

    // 调试模式设置
    protected function debugModeInit(): void
    {
        if (!$this->appDebug) {
            $this->appDebug = $this
                ->env
                ->get('app_debug') ? true : false;
            ini_set('display_errors', 'Off');
        }

        if (!$this->runningInConsole()) {
            // 重新申请一块比较大的buffer
            if (ob_get_level() > 0) {
                $output = ob_get_clean();
            }
            ob_start();
            if (!empty($output)) {
                echo $output;
            }
        }
    }

    // 注册应用事件
    public function loadEvent(array $event): void
    {
        if (isset($event['bind'])) {
            $this->event->bind($event['bind']);
        }

        if (isset($event['listen'])) {
            $this->event->listenEvents($event['listen']);
        }

        if (isset($event['subscribe'])) {
            $this->event->subscribe($event['subscribe']);
        }
    }

    /**
     * 解析应用类的类名
     * @param string $layer
     * @param string $name
     * @return string
     */
    public function parseClass(string $layer, string $name): string
    {
        $name = str_replace(['/', '.'], '\\', $name);
        $array = explode('\\', $name);
        $class = Str::studly(array_pop($aray));
        $path = $array ? implode('\\', $array) . '\\' : '';
        return $this->namespace . '\\' . $layer . '\\' . $path . $class;
    }

    /**
     * 是否运行在命令行下
     * @return bool
     */
    public function runningInConsole()
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }

    // 获取应用根目录
    protected function getDefaultRootPath(): string
    {
        return dirname($this->thinkPath, 4) . DIRECTORY_SEPARATOR;
    }
}