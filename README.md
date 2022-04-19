##使用
```
composer require myphps/my-php-srv
```
##示例1 通过 composer的autolad
```php
#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app'); #myphp运行时指定的项目目录

$cfg = require(__DIR__ . '/wokerman.conf.php'); #需要在此配置文件里配置myphp的目录路径
require __DIR__ . '/vendor/autoload.php';
/*
#使用wokerman时也可以直接加载
require __DIR__ . '/vendor/workerman/workerman/Autoloader.php';
require __DIR__ . '/vendor/myphps/my-php-srv/Load.php';
*/

$srv = new WorkerManHttpSrv($cfg);
$srv->run($argv);
```
##示例2 或直接通过自带Load.php载入
```php
#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app');

$cfg = require(__DIR__ . '/wokerman.conf.php');
require __DIR__ . '/../Load.php'; #使用workerman时需要把workerman的目录与Load.php同级或直接引用workerman/Autoloader.php

$srv = new WorkerManHttpSrv($cfg);
$srv->run($argv);
```
>conf.php是myphp的配置文件