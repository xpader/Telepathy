<?php

use Eywa\EywaWorker;
use Workerman\Worker;

require_once __DIR__ . '/../../libs/Workerman/Autoloader.php';

//启动注册中心
$worker = new EywaWorker();
$worker->count = 4;
$worker->registerAddress = '127.0.0.1:1236';

$worker->onConnect = function($connection) use ($worker) {
	echo "[Worker{$worker->id}] Client connected\n";
};

$worker->onMessage = function($connection, $data) use ($worker) {
	echo "[Worker{$worker->id}]Client message: $data\n";
};

$worker->onClose = function($connection) use ($worker) {
	echo "[Worker{$worker->id}] Client closed\n";
};

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}