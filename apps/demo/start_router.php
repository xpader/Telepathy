<?php
/**
 * Created by PhpStorm.
 * User: Pader
 * Date: 2016/11/20
 * Time: 20:16
 */
use Eywa\Router;
use Workerman\Worker;

require_once __DIR__ . '/../../libs/Workerman/Autoloader.php';

$router = new Router('127.0.0.1', 2999);
$router->registerAddress = '127.0.0.1:1236';

if (!defined('GLOBAL_START')) {
	Worker::runAll();
}