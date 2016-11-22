<?php
/**
 * Created by PhpStorm.
 * User: pader
 * Date: 2016/11/9
 * Time: 14:48
 */

namespace Eywa;

use Eywa\Lib\Context;
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
	protected $connectingGatewayAddresses = [];

	/**
	 * 等待连接个 gateway 地址
	 *
	 * @var array
	 */
	protected $waitingConnectGatewayAddresses = [];

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

		//上下文数据
		Context::$client_ip     = $data['client_ip'];
		Context::$client_port   = $data['client_port'];
		Context::$local_ip      = $data['local_ip'];
		Context::$local_port    = $data['local_port'];
		Context::$connection_id = $data['connection_id'];
		Context::$client_id     = Context::addressToClientId($data['local_ip'], $data['local_port'], $data['connection_id']);

		$_SERVER = array(
			'REMOTE_ADDR'       => long2ip($data['client_ip']),
			'REMOTE_PORT'       => $data['client_port'],
			'GATEWAY_ADDR'      => long2ip($data['local_ip']),
			'GATEWAY_PORT'      => $data['gateway_port'],
			'GATEWAY_CLIENT_ID' => Context::$client_id,
		);

		//调用业务回调
		//注意 EywaWorker 的 onConnect, onMessage, onClose 代表的是客户端连接，消息和连接关闭事件
		switch ($cmd) {
			case Gate::CMD_ON_CONNECTION:
				if (is_callable($this->onConnect)) {
					call_user_func($this->onConnect, Context::$client_id);
				}
				break;
			case Gate::CMD_ON_MESSAGE:
				if (is_callable($this->onMessage)) {
					call_user_func($this->onMessage, Context::$client_id, $data['body']);
				}
				break;
			case Gate::CMD_ON_CLOSE:
				if (is_callable($this->onClose)) {
					call_user_func($this->onClose, Context::$client_id);
				}
				break;
		}
	}

	/**
	 * 当与 Gateway 的连接断开时触发
	 *
	 * @param TcpConnection $connection
	 * @return  void
	 */
	public function onGatewayClose($connection) {
		$addr = $connection->remoteAddress;

		unset($this->gatewayConnections[$addr], $this->connectingGatewayAddresses[$addr]);

		if (isset($this->gatewayAddresses[$addr]) && !isset($this->waitingConnectGatewayAddresses[$addr])) {
			Timer::add(1, [$this, 'connectGateway'], [$addr], false);
			$this->waitingConnectGatewayAddresses[$addr] = 1;
		}
	}

	/**
	 * 当与 gateway 的连接出现错误时触发
	 *
	 * @param TcpConnection $connection
	 * @param int $errorNo
	 * @param string $errorMsg
	 */
	public function onGatewayError($connection, $errorNo, $errorMsg) {
		echo "GatewayConnection Error: $errorNo, $errorMsg\n";
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

		echo "Worker {$this->id} onRegisterMessage:".join(',', $data['addresses'])."\n---------\n";
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
	 * 尝试连接 Gateway 内部通讯地址
	 *
	 * @param string $addr
	 */
	public function connectGateway($addr)
	{
		if (!isset($this->gatewayConnections[$addr]) && !isset($this->connectingGatewayAddresses[$addr]) && isset($this->gatewayAddresses[$addr])) {
			echo "Worker {$this->id} connectGateway: $addr\n--------------\n";
			$gatewayConnection = new AsyncTcpConnection("Gate://$addr");
			$gatewayConnection->remoteAddress = $addr;
			$gatewayConnection->onConnect     = [$this, 'onGatewayConnected'];
			$gatewayConnection->onMessage     = [$this, 'onGatewayMessage'];
			$gatewayConnection->onClose       = [$this, 'onGatewayClose'];
			$gatewayConnection->onError       = [$this, 'onGatewayError'];

			if (TcpConnection::$defaultMaxSendBufferSize == $gatewayConnection->maxSendBufferSize) {
				$gatewayConnection->maxSendBufferSize = 50 * 1024 * 1024;
			}

			$gatewayData = Gate::$empty;
			$gatewayData['cmd'] = Gate::CMD_WORKER_CONNECT;
			$gatewayData['body'] = json_encode([
				'worker_key' => "{$this->name}:{$this->id}",
				'secret_key' => $this->secretKey,
			]);

			$gatewayConnection->send($gatewayData);
			$gatewayConnection->connect();

			$this->connectingGatewayAddresses[$addr] = 1;
		}

		unset($this->waitingConnectGatewayAddresses[$addr]);
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
			if (!isset($this->waitingConnectGatewayAddresses[$addr])) {
				$this->connectGateway($addr);
			}
		}
	}

}