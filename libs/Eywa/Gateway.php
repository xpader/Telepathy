<?php

namespace Eywa;

use Eywa\Protocols\Gate;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Gateway extends Worker {

	/**
	 * Gateway 向 Worker 开放的内部通讯地址
	 * @var string
	 */
	public $lanIp = '127.0.0.1';
	public $lanPort = 0;
	public $startPort = 2000;

	/**
	 * 指定分配 worker 的路由算法回调
	 *
	 * 不指定时将随机分配
	 *
	 * @var callable
	 */
	public $workerRouter = null;

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

	//事件中转
	public $_onWorkerStart = null;
	public $_onWorkerStop = null;
	public $_onConnect = null;
	public $_onClose = null;

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

		$this->gatewayPort = substr(strrchr($socketName,':'), 1);

		if (!is_callable($this->workerRouter)) {
			$this->workerRouter = ['\\Eywa\\Gateway', 'routerBind'];
		}
	}

	public function run() {
		$this->startTime = time();

		$this->_onWorkerStart = $this->onWorkerStart;
		$this->onWorkerStart = [$this, 'onWorkerStart'];

		$this->_onWorkerStop = $this->onWorkerStop;
		$this->onWorkerStop = [$this, 'onWorkerStop'];

		//客户端事件
		$this->_onConnect = $this->onConnect;
		$this->onConnect = [$this, 'onClientConnect'];
		$this->onMessage = [$this, 'onClientMessage'];
		$this->_onClose = $this->onClose;
		$this->onClose = [$this, 'onClientClose'];

		//记录进程启动的时间
		$this->startTime = time();

		parent::run();
	}

	/**
	 * Gateway 进程启动时
	 *
	 * @throws \Exception
	 */
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

	/**
	 * Gateway 进程停止时
	 */
	public function onWorkerStop() {
		if (is_callable($this->_onWorkerStop)) {
			call_user_func($this->_onWorkerStop, $this);
		}
	}

	/**
	 * Worker 连接上来时
	 *
	 * @param $connection
	 */
	public function onWorkerConnect($connection) {
		if (TcpConnection::$defaultMaxSendBufferSize === $connection->maxSendBufferSize) {
			$connection->maxSendBufferSize = 50 * 1024 * 1024;
		}

		$connection->authorized = $this->secretKey ? false : true;
	}

	/**
	 * Worker 发来消息时
	 *
	 * @param TcpConnection $connection
	 * @param mixed $data
	 */
	public function onWorkerMessage($connection, $data) {
		if (empty($connection->authorized) && $data['cmd'] !== Gate::CMD_WORKER_CONNECT
				&& $data['cmd'] !== Gate::CMD_GATEWAY_CLIENT_CONNECT) {
			echo "Unauthorized request from " . $connection->getRemoteIp() . ":" . $connection->getRemotePort() . "\n";
			$connection->close();
			return;
		}

		switch ($data['cmd']) {
			//业务进程连接Gateway
			case Gate::CMD_WORKER_CONNECT:
				$workerInfo = json_decode($data['body'], true);
				if ($workerInfo['secret_key'] !== $this->secretKey) {
					echo "Gateway: Worker key does not match {$this->secretKey} !== {$this->secretKey}\n";
					return $connection->close();
				}
				$connection->key = $connection->getRemoteIp() . ':' . $workerInfo['worker_key'];
				$this->workerConnections[$connection->key] = $connection;
				$connection->authorized = true;
				return;
		}
	}

	/**
	 * Worker 连接断开时
	 *
	 * @param $connection
	 */
	public function onWorkerClose($connection) {
		if (isset($connection->key)) {
			unset($this->workerConnections[$connection->key]);
		}
	}

	/**
	 * 客户端连接时
	 * 
	 * @param TcpConnection $connection
	 */
	public function onClientConnect($connection) {
		//保存该连接的内部通讯的数据包报头，避免每次重新初始化
		$connection->gatewayHeader = array(
			'local_ip'      => ip2long($this->lanIp),
			'local_port'    => $this->lanPort,
			'client_ip'     => ip2long($connection->getRemoteIp()),
			'client_port'   => $connection->getRemotePort(),
			'gateway_port'  => $this->gatewayPort,
			'connection_id' => $connection->id,
			'flag'          => 0,
		);

		//该连接的心跳参数
		$connection->pingNotResponseCount = -1;

		//保存客户端连接 connection 对象
		$this->clientConnections[$connection->id] = $connection;

		//如果用户有自定义 onConnect 回调，则执行
		if (is_callable($this->_onConnect)) {
			call_user_func($this->_onConnect, $connection);
		}

		//链接到 Worker
		$this->toWorker(Gate::CMD_ON_CONNECTION, $connection);
	}

	/**
	 * 当客户端连接时将数据转发至 Worker
	 * 
	 * @param TcpConnection $connection
	 * @param string $data
	 */
	public function onClientMessage($connection, $data) {
		$connection->pingNotResponseCount = -1;
		$this->toWorker(Gate::CMD_ON_MESSAGE, $connection, $data);
	}

	/**
	 * 客户端关闭连接时
	 * 
	 * @param TcpConnection $connection
	 */
	public function onClientClose($connection) {
		//通知 Worker
		$this->toWorker(Gate::CMD_ON_CLOSE, $connection);
		unset($this->clientConnections[$connection->id]);

		//清理 uid 数据
		if (!empty($connection->uid)) {
			$uid = $connection->uid;
			if (isset($this->uidConnections[$uid])) {
				unset($this->uidConnections[$uid][$connection->id]);
				unset($this->uidConnections[$uid]);
			}
		}
		
		//触发 onClose
		if (is_callable($this->_onClose)) {
			call_user_func($this->_onClose, $connection);
		}
	}

	/**
	 * 发送数据至 Worker
	 *
	 * @param int $cmd
	 * @param TcpConnection $connection
	 * @param string $data
	 * @return bool
	 */
	protected function toWorker($cmd, $connection, $data='') {
		$gatewayData = $connection->gatewayHeader;
		$gatewayData['cmd'] = $cmd;
		$gatewayData['body'] = $data;
		$gatewayData['ext_data'] = '';

		if ($this->workerConnections) {
			$workerConnection = call_user_func($this->workerRouter, $this->workerConnections, $connection, $cmd, $data);
			if ($workerConnection->send($gatewayData) === false) {
				$msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://wiki.workerman.net/Error2";
				$this->log($msg);
				return false;
			}
		} else {
			//gateway 启动后 1-2 秒内 SendBufferToWorker fail 是正常现象，因为与 worker 的连接还没建立起来，
			//所以不记录日志，只是关闭连接
			$timeDiff = 2;
			if (time() - $this->startTime >= $timeDiff) {
				$msg = 'SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready. See http://wiki.workerman.net/Error3';
				$this->log($msg);
			}
			$connection->destroy();
			return false;
		}

		return true;
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
	 * 注册中心连接断开事件
	 */
	public function onRegisterConnectionClose() {
		//当注册中心断开时尝试重连
		Timer::add(1, [$this, 'registerGateway'], null, false);
	}

	/**
	 * client_id 与 worker 绑定
	 *
	 * @param array         $workerConnections
	 * @param TcpConnection $clientConnection
	 * @param int           $cmd
	 * @param mixed         $buffer
	 * @return TcpConnection
	 */
	public static function routerBind($workerConnections, $clientConnection, $cmd, $buffer) {
		if (!isset($clientConnection->workerKey) || !isset($workerConnections[$clientConnection->workerKey])) {
			$clientConnection->workerKey = array_rand($workerConnections);
		}
		return $workerConnections[$clientConnection->workerKey];
	}

}