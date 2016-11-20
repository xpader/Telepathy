<?php

use Eywa\EywaWorker;
use Workerman\Worker;

require_once __DIR__ . '/../../Workerman/Autoloader.php';

//启动注册中心
$worker = new EywaWorker();
$worker->count = 4;

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}