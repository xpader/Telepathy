<?php

use Eywa\EywaWorker;
use Eywa\Lib\Gateway;
use Workerman\Worker;

require_once __DIR__ . '/../../libs/Workerman/Autoloader.php';

//启动注册中心
$worker = new EywaWorker();
$worker->count = 4;
$worker->registerAddress = '127.0.0.1:1236';

$worker->onConnect = function($clientId) use ($worker) {
	echo "[Worker{$worker->id}] Client connected\n";
};

$worker->onMessage = function($clientId, $data) use ($worker) {
	echo "[Worker{$worker->id}][$clientId]Client message: $data\n";
	Gateway::sendToClient($clientId, 'got: '.$data);

	if ($data == 'set') {
		$worker->route->last = date('Y-m-d H:i:s');
		Gateway::sendToClient($clientId, 'set route: '.$worker->route->last);
	} elseif ($data == 'get') {
		Gateway::sendToClient($clientId, 'get route: '.$worker->route->last);
	}
};

$worker->onClose = function($clientId) use ($worker) {
	echo "[Worker{$worker->id}] Client closed\n";
};

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}