<?php
/**
 * Created by PhpStorm.
 * User: pader
 * Date: 2016/11/9
 * Time: 14:48
 */

namespace Eywa;

use Eywa\Protocols\Gate;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class EywaWorker extends Worker {

	/**
	 * 注册中心的地址
	 *
	 * @var string
	 */
	public $registerAddress = '127.0.0.1:1236';
	public $secretKey = '';

	public $name = 'EywaWorker';

	public $_onWorkerStart = null;
	public $_onWorkerReload = null;
	public $_onWorkerStop = null;

	/**
	 * 与注册中心的连接
	 * @var TcpConnection
	 */
	protected $registerConnection;

	/**
	 * 所有 Gateway 的地址
	 * @var array
	 */
	protected $gatewayAddresses = [];
	protected $gatewayConnections = [];

	/**
	 * 处于连接状态的 gateway 通讯地址
	 *
	 * @var array
	 */
	protected $connectingGatewayAddresses = array();

	/**
	 * 等待连接个 gateway 地址
	 *
	 * @var array
	 */
	protected $waitingConnectGatewayAddresses = array();

	/**
	 * 用于保持长连接的心跳时间间隔
	 *
	 * @var int
	 */
	const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

	/**
	 * {@inheritdoc}
	 */
	public function run()
	{
		$this->_onWorkerStart  = $this->onWorkerStart;
		$this->_onWorkerReload = $this->onWorkerReload;
		$this->_onWorkerStop = $this->onWorkerStop;
		$this->onWorkerStart   = [$this, 'onWorkerStart'];
		$this->onWorkerReload  = [$this, 'onWorkerReload'];
		$this->onWorkerStop = [$this, 'onWorkerStop'];
		parent::run();
	}

	/**
	 * Worker 进程启动时
	 */
	public function onWorkerStart() {
		if (!class_exists('\Protocols\Gate')) {
			class_alias('Eywa\Protocols\Gate', 'Protocols\Gate');
		}

		$this->registerWorker();

		//\Eywa\Lib\Gateway::setBusinessWorker($this);
		//\GatewayWorker\Lib\Gateway::$secretKey = $this->secretKey;

		//Todo: EventHandler

		if (is_callable($this->_onWorkerStart)) {
			call_user_func($this->_onWorkerStart, $this);
		}

		// 如果Register服务器不在本地服务器，则需要保持心跳
		if (strpos($this->registerAddress, '127.0.0.1') !== 0) {
			Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, array($this, 'pingRegister'));
		}
	}

	/**
	 * Worker 进程停止时
	 */
	public function onWorkerStop() {
		if (is_callable($this->_onWorkerStop)) {
			call_user_func($this->_onWorkerStop, $this);
		}
	}

	/**
	 * Worker 进程重新载入（重新启动）
	 *
	 * @param Worker $worker
	 */
	public function onWorkerReload($worker) {
		//防止进程立刻退出
		$worker->reloadable = false;

		//延迟 0.05 秒退出，避免 BusinessWorker 瞬间全部退出导致没有可用的 BusinessWorker 进程
		Timer::add(0.05, array('Workerman\Worker', 'stopAll'));

		//执行用户定义的 onWorkerReload 回调
		if (is_callable($this->_onWorkerReload)) {
			call_user_func($this->_onWorkerReload, $this);
		}
	}

	/**
	 * 当连接到 Gateway 时
	 *
	 * 注意是 Worker 连接到 Gateway 而不是 Gateway 连接到 Worker
	 *
	 * @param TcpConnection $connection
	 */
	public function onGatewayConnected($connection) {
		$this->gatewayConnections[$connection->remoteAddress] = $connection;
		unset($this->connectingGatewayAddresses[$connection->remoteAddress], $this->waitingConnectGatewayAddresses[$connection->remoteAddress]);
	}

	public function onGatewayMessage($connection, $data) {
		$cmd = $data['cmd'];

		if ($cmd === Gate::CMD_PING) {
			return;
		}

		//Todo: here
	}

	/**
	 * 向注册中心注册本 Worker
	 */
	public function registerWorker() {
		$this->registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");
		$data = ['event'=>'worker_connect', 'secret_key'=>$this->secretKey];
		$this->registerConnection->send(serialize($data));
		$this->registerConnection->onClose   = array($this, 'onRegisterClose');
		$this->registerConnection->onMessage = array($this, 'onRegisterMessage');
		$this->registerConnection->connect();
	}

	/**
	 * 与注册中心连接关闭时，定时重连
	 *
	 * @return void
	 */
	public function onRegisterClose()
	{
		Timer::add(1, [$this, 'registerWorker'], null, false);
	}

	/**
	 * 当注册中心发来消息时
	 *
	 * @param TcpConnection $connection
	 * @param string $data
	 * @return void
	 */
	public function onRegisterMessage($connection, $data) {
		$data = @unserialize($data);

		if (!isset($data['event'])) {
			echo "Received bad data from Register\n";
			return;
		}

		switch ($data['event']) {
			case 'update_gateway_addresses':

				if (!is_array($data['addresses'])) {
					echo "Received bad data from Register. Addresses empty\n";
					return;
				}

				$this->gatewayAddresses = array();

				foreach ($data['addresses'] as $addr) {
					$this->gatewayAddresses[$addr] = 1;
				}

				$this->checkGatewayConnections($data['addresses']);
				break;
			default:
				echo "Received unknow event:{$data['event']} from Register.\n";
		}
	}

	/**
	 * 向 Register 发送心跳，用来保持长连接
	 */
	public function pingRegister() {
		if ($this->registerConnection) {
			$this->registerConnection->send(serialize(['event'=>'ping']));
		}
	}

	/**
	 * 检查 gateway 的通信端口是否都已经连
	 * 如果有未连接的端口，则尝试连接
	 *
	 * @param array $addresses
	 */
	public function checkGatewayConnections($addresses)
	{
		if (empty($addresses)) {
			return;
		}

		foreach ($addresses as $addr) {
			//if (!isset($this->_waitingConnectGatewayAddresses[$addr])) {
			//	$this->tryToConnectGateway($addr);
			//}
		}
	}

}