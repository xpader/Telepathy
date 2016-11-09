<?php

namespace Telepathy;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Gateway extends Worker {

	public $lanIp = '127.0.0.1';
	public $lanPort = 0;
	public $startPort = 2000;
	public $registerAddress = '127.0.0.1:1236';
	public $reloadable = false;
	public $pingInterval = 25;
	public $pingNotResponseLimit = 0;
	public $pingData = '';
	public $secretKey = '';
	public $name = 'Gateway';

	protected $clientConnections = [];
	protected $uidConnections = [];
	protected $startTime = 0;
	protected $gatewayPort = 0;
	protected $registerConnection = null;
	protected $workerConnection = null;

	const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

	public function __construct($socketName, $contextOption=[]) {
		parent::__construct($socketName, $contextOption);

		$this->gatewayPort = substr(strrchr($socketName,':'),1);
	}

	public function run() {
		$this->startTime = time();

		$this->onWorkerStart = [$this, 'onWorkerStart'];

		parent::run();
	}

	public function onWorkerStart() {
		$this->lanPort = $this->startPort + $this->id;

		if (!class_exists('\Protocols\Gate')) {
			class_alias('Telepathy\Protocols\Gate', 'Protocols\Gate');
		}

		$this->workerConnection = new Worker("Gate://{$this->lanIp}:{$this->lanPort}");
		$this->workerConnection->listen();

		$this->workerConnection->onMessage = [$this, 'onGatewayMessage'];
		$this->workerConnection->onConnect = function() {};

		$this->workerConnection->onClose = function() {};

		$this->registerGateway();

		//$this->onUserWorkerStart();
	}

	public function onGatewayMessage($connection, $data) {

	}

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

	public function onRegisterConnectionClose() {
		Timer::add(1, [$this, 'registerGateway'], null, false);
	}

}