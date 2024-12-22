#!/usr/bin/env php
<?php

define('APP_PATH', __DIR__ .'/app');

require __DIR__ .'/conf.php';
require __DIR__ .'/../vendor/myphps/myphp/base.php';

myphp::Run();
