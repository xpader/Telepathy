<?php

use Eywa\Gateway;
use Workerman\Worker;

require_once __DIR__ . '/../../libs/Workerman/Autoloader.php';

//启动网关
$gateway = new Gateway('websocket://127.0.0.1:3000');
$gateway->startPort = 2000;
$gateway->count = 2;
$gateway->registerAddress = '127.0.0.1:1236';

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}
