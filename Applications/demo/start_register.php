<?php

use Eywa\Register;
use Workerman\Worker;

require_once __DIR__ . '/../../Workerman/Autoloader.php';

//启动注册中心
$gateway = new Register('text://127.0.0.1:1236');

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}