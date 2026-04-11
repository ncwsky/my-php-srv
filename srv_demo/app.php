#!/usr/bin/env php
<?php

$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行

const APP_PATH = __DIR__ . '/app';
const COMMON = __DIR__ . '/common';

#require __DIR__ . '/vendor/myphps/my-php-srv/Load.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/myphps/myphp/GetOpt.php';

//解析命令参数
GetOpt::parse('hsp:l:c:n:u:', ['help', 'swoole', 'port:', 'count:', 'name:', 'user:']);
//处理命令参数
$isSwoole = GetOpt::has('s', 'swoole');
//自动检测
if (!$isSwoole && !SrvBase::workermanCheck() && defined('SWOOLE_VERSION')) {
    $isSwoole = true;
}

//Phar包路径处理
if (class_exists(Phar::class, false) && Phar::running(false)) {
    define('MY_PHAR_PATH', Phar::running()); //Phar内部运行目录
    define('APP_RUN_DIR', dirname(Phar::running(false))); //Phar的运行目录
    $_SERVER['SCRIPT_FILENAME'] = Phar::running(false); //重置
    $inPhar = true;
} else {
    define('APP_RUN_DIR', __DIR__);
    $inPhar = false;
}

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php '.basename($_SERVER['SCRIPT_FILENAME']).' OPTION [restart|reload|stop]
    or: app.php OPTION [restart|reload|stop]
    
    -h --help
    -l --listen=0.0.0.0 监听IP
    -p --port=6051      监听端口
    -c --count=10       进程数量
    -n --name=myApp     进程名
    -u --user=www-data  运行权限
    -s --swoole         swolle运行', PHP_EOL;
    exit(0);
}
$listen = GetOpt::val('l', 'listen');
$port = (int)GetOpt::val('p', 'port');
$count = (int)GetOpt::val('c', 'count');
$name = GetOpt::val('n', 'name');
$user = GetOpt::val('u', 'user');

//移除指定配置项 在workerman模式下会当成一个运行文件处理
GetOpt::removeOptions($argv, ['c','u','n','name']);

//cpu数量
function cpu_num()
{
    if (function_exists('swoole_cpu_num')) {
        return swoole_cpu_num();
    }
    if (DIRECTORY_SEPARATOR === '\\') { //win
        return 1;
    }
    $num = 4;
    if (is_callable('shell_exec')) {
        if (strtolower(PHP_OS) === 'darwin') {
            $num = (int)shell_exec('sysctl -n machdep.cpu.core_count');
        } else {
            $num = (int)shell_exec('nproc');
        }
    }
    return $num > 0 ? $num : 4;
}

if (!is_dir(APP_RUN_DIR . '/log')) {
    mkdir(APP_RUN_DIR . '/log', 0755);
}
//针对Phar模式下默认的项目配置读取处理
$config = $inPhar ? require(MY_PHAR_PATH . '/app.conf.php') : require(APP_RUN_DIR . '/app.conf.php');
if (is_file(APP_RUN_DIR . '/app.conf.local.php')) {
    $config = array_merge($config, require(APP_RUN_DIR . '/app.conf.local.php'));
}
//临时目录
const RUNTIME = APP_RUN_DIR . '/runtime';
//站点目录
const SITE_WEB = APP_RUN_DIR . '/web';

//phar模式下路径处理
if ($inPhar) {
    //workerman状态
    $config['setting']['statusFile'] = APP_RUN_DIR . '/log/app.status';
}
//onWorker
$config['worker_load'] = [
    $inPhar ? MY_PHAR_PATH . '/conf.php' : APP_RUN_DIR . '/conf.php',
    __DIR__ . '/../vendor/myphps/myphp/base.php',
    __DIR__ . '/../vendor/autoload.php'
];
$config['setting']['stdoutFile'] = APP_RUN_DIR . '/log/app.log'; //终端输出 workerman
$config['setting']['pidFile'] = APP_RUN_DIR . '/app.pid';
$config['setting']['logFile'] = APP_RUN_DIR . '/log/app.log'; //日志文件

//指定运行参数
if ($name) {
    $config['name'] = $name;
}
if ($listen > 0) {
    $config['ip'] = $listen;
}
if ($port > 0) {
    $config['port'] = $port;
}
if ($count > 0) {
    $config['setting']['count'] = $count;
}
if ($user) {
    $config['setting']['user'] = $user;
}

if ($isSwoole) {
    $srv = $config['type'] == 'http' ? new SwooleHttpSrv($config) : new SwooleSrv($config);
} else {
    // 设置每个连接接收的数据包大小
    \Workerman\Connection\TcpConnection::$defaultMaxPackageSize = $config['setting']['package_max_length'] ?? 10 * 1024 * 1024;
    \Workerman\Protocols\Http\Session::$autoUpdateTimestamp = true;
    $srv = new WorkerManSrv($config);
    Worker2::$stopTimeout = 10; //强制进程结束等待时间
}
$srv->run($argv);
