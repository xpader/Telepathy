<?php

namespace Eywa;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Gateway extends Worker {

	/**
	 * Gateway 服务地址配置
	 * @var string
	 */
	public $lanIp = '127.0.0.1';
	public $lanPort = 0;
	public $startPort = 2000;

	/**
	 * 注册中心地址
	 * @var string
	 */
	public $registerAddress = '127.0.0.1:1236';

	public $reloadable = false;
	public $pingInterval = 25;
	public $pingNotResponseLimit = 0;
	public $pingData = '';
	public $secretKey = '';
	public $name = 'Gateway';

	public $_onWorkerStart = null;

	protected $clientConnections = [];
	protected $uidConnections = [];
	protected $workerConnections = [];
	protected $startTime = 0;
	protected $gatewayPort = 0;
	protected $registerConnection;
	protected $workerListen = [];

	const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

	public function __construct($socketName, $contextOption=[]) {
		parent::__construct($socketName, $contextOption);

		$this->gatewayPort = substr(strrchr($socketName,':'),1);
	}

	public function run() {
		$this->startTime = time();

		$this->_onWorkerStart = $this->onWorkerStart;
		$this->onWorkerStart = [$this, 'onWorkerStart'];

		parent::run();
	}

	public function onWorkerStart() {
		$this->lanPort = $this->startPort + $this->id;

		if (!class_exists('\Protocols\Gate')) {
			class_alias('Eywa\Protocols\Gate', 'Protocols\Gate');
		}

		//等待 Worker 连接
		$this->workerListen = new Worker("Gate://{$this->lanIp}:{$this->lanPort}");
		$this->workerListen->listen();
		$this->workerListen->onMessage = [$this, 'onWorkerMessage'];
		$this->workerListen->onConnect = [$this, 'onWorkerConnect'];
		$this->workerListen->onClose = [$this, 'onWorkerClose'];

		$this->registerGateway();

		//调用用户的 onWorkerStart
		if (is_callable($this->_onWorkerStart)) {
			call_user_func($this->_onWorkerStart, $this);
		}
	}

	public function onWorkerConnect($connection) {
		if (TcpConnection::$defaultMaxSendBufferSize === $connection->maxSendBufferSize) {
			$connection->maxSendBufferSize = 50 * 1024 * 1024;
		}

		$connection->authorized = $this->secretKey ? false : true;
	}

	public function onWorkerMessage($connection, $data) {

	}

	public function onWorkerClose($connection)
	{
		if (isset($connection->key)) {
			unset($this->workerConnections[$connection->key]);
		}
	}

	/**
	 * 向注册中心注册 Gateway
	 */
	public function registerGateway() {
		$addr = $this->lanIp.':'.$this->lanPort;
		$this->registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");

		$data = [
			'event' => 'gateway_connect',
			'address' => $addr,
			'secret_key' => $this->secretKey,
		];

		$this->registerConnection->send(serialize($data));
		$this->registerConnection->onClose = [$this, 'onRegisterConnectionClose'];
		$this->registerConnection->connect();
	}

	/**
	 * 当注册中心断开时尝试重连
	 */
	public function onRegisterConnectionClose() {
		Timer::add(1, [$this, 'registerGateway'], null, false);
	}

}