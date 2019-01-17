<?php

namespace Mix\Http;

use Mix\Core\BeanObject;
use Mix\Core\Coroutine;
use Mix\Helpers\ProcessHelper;

/**
 * Class Server
 * @package Mix\Http
 * @author LIUJIAN <coder.keda@gmail.com>
 */
class Server extends BeanObject
{

    /**
     * 主机
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * 端口
     * @var int
     */
    public $port = 9501;

    /**
     * 配置文件
     * @var string
     */
    public $configurationFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $settings = [];

    /**
     * 默认运行参数
     * @var array
     */
    protected $_settings = [
        // 开启协程
        'enable_coroutine' => false,
        // 主进程事件处理线程数
        'reactor_num'      => 8,
        // 工作进程数
        'worker_num'       => 8,
        // 任务进程数
        'task_worker_num'  => 0,
        // 进程的最大任务数
        'max_request'      => 10000,
        // PID 文件
        'pid_file'         => '/var/run/mix-httpd.pid',
        // 日志文件路径
        'log_file'         => '/tmp/mix-httpd.log',
        // 异步安全重启
        'reload_async'     => true,
        // 退出等待时间
        'max_wait_time'    => 60,
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay' => true,
    ];

    /**
     * 服务器
     * @var \Swoole\Http\Server
     */
    protected $_server;

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->_server = new \Swoole\Http\Server($this->host, $this->port);
        // 配置参数
        $this->_settings = $this->settings + $this->_settings;
        $this->_server->set($this->_settings);
        // 绑定事件
        $this->onStart();
        $this->onManagerStart();
        $this->onWorkerStart();
        $this->onRequest();
        // 欢迎信息
        $this->welcome();
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     */
    protected function onStart()
    {
        $this->_server->on('Start', function ($server) {
            try {
                // 进程命名
                ProcessHelper::setProcessTitle("mix-httpd: master {$this->host}:{$this->port}");
            } catch (\Throwable $e) {
                \Mix::$app->error->handleException($e);
            }
        });
    }

    // 管理进程启动事件
    protected function onManagerStart()
    {
        $this->_server->on('ManagerStart', function ($server) {
            try {
                // 进程命名
                ProcessHelper::setProcessTitle("mix-httpd: manager");
            } catch (\Throwable $e) {
                \Mix::$app->error->handleException($e);
            }
        });
    }

    /**
     * 工作进程启动事件
     */
    protected function onWorkerStart()
    {
        $this->_server->on('WorkerStart', function ($server, $workerId) {
            try {
                // 进程命名
                if ($workerId < $server->setting['worker_num']) {
                    ProcessHelper::setProcessTitle("mix-httpd: worker #{$workerId}");
                } else {
                    ProcessHelper::setProcessTitle("mix-httpd: task #{$workerId}");
                }
                // 实例化App
                $config = require $this->configurationFile;
                new \Mix\Http\Application($config);
            } catch (\Throwable $e) {
                \Mix::$app->error->handleException($e);
            }
        });
    }

    /**
     * 请求事件
     */
    protected function onRequest()
    {
        $this->_server->on('request', function ($request, $response) {
            try {
                // 执行请求
                \Mix::$app->request->setRequester($request);
                \Mix::$app->response->setResponder($response);
                \Mix::$app->run();
                // 开启协程时，移除容器
                if (($tid = Coroutine::id()) !== -1) {
                    \Mix::$app->container->delete($tid);
                }
            } catch (\Throwable $e) {
                \Mix::$app->error->handleException($e);
            }
        });
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion    = PHP_VERSION;
        echo <<<EOL
                             _____
_______ ___ _____ ___   _____  / /_  ____
__/ __ `__ \/ /\ \/ /__ / __ \/ __ \/ __ \
_/ / / / / / / /\ \/ _ / /_/ / / / / /_/ /
/_/ /_/ /_/_/ /_/\_\  / .___/_/ /_/ .___/
                     /_/         /_/


EOL;
        println('Server         Name:      mix-httpd');
        println('System         Name:      ' . strtolower(PHP_OS));
        println('Framework      Version:   ' . \Mix::VERSION);
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->_settings['reactor_num']);
        println('Worker         Num:       ' . $this->_settings['worker_num']);
        println('Hot            Update:    ' . ($this->_settings['max_request'] == 1 ? 'enabled' : 'disabled'));
        println('Coroutine      Mode:      ' . ($this->_settings['enable_coroutine'] ? 'enabled' : 'disabled'));
        println("Configuration  File:      {$this->configurationFile}");
    }

}
