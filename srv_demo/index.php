#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app');

require __DIR__ .'/conf.php';
require(__DIR__ .'/../myphp/base.php');

$myphp = new myphp();
$myphp->Run();