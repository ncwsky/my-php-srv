#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app');

$cfg = require(__DIR__ . '/wokerman2.conf.php');
#require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/workerman/workerman/Autoloader.php';
require __DIR__ . '/vendor/myphps/my-php-srv/Load.php';

$srv = new WorkerManHttpSrv($cfg);
$srv->run($argv);