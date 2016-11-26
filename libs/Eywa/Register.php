<?php
/**
 * Created by PhpStorm.
 * User: pader
 * Date: 2016/11/9
 * Time: 12:24
 */

namespace Eywa;

use Workerman\Lib\Timer;
use Workerman\Worker;

class Register extends Worker {

	public $name = 'Register';
	public $reloadable = false;
	public $secretKey = '';

	protected $gatewayConnections = [];
	protected $workerConnections = [];
	protected $routerConnections = [];
	protected $startTime = 0;

	const TYPE_GATEWAY = 0;
	const TYPE_WORKER = 1;
	const TYPE_ROUTER = 2;

	public function run() {
		$this->onConnect = [$this, 'onConnect'];
		$this->onClose = [$this, 'onClose'];
		$this->onMessage = [$this, 'onMessage'];
		$this->startTime = time();

		//强制 Text 协议
		$this->protocol = '\Workerman\Protocols\Text';

		parent::run();
	}

	/**
	 * @param \Workerman\Connection\TcpConnection $connection
	 */
	public function onConnect($connection) {
		$connection->timeoutTimerId = Timer::add(10, function () use ($connection) {
			echo "Register auth timeout (".$connection->getRemoteIp()."). See http://wiki.workerman.net/Error4\n";
			$connection->close();
		}, null, false);
	}

	/**
	 * @param \Workerman\Connection\TcpConnection $connection
	 * @param string $raw
	 */
	public function onMessage($connection, $raw) {
		Timer::del($connection->timeoutTimerId);

		$data = @unserialize($raw);

		if (empty($data['event'])) {
			$error = "Bad request for Register service. Request info(IP:".$connection->getRemoteIp().", Request raw:$raw). See http://wiki.workerman.net/Error4\n";
			echo $error;
			$connection->close($error);
			return;
		}

		$event = $data['event'];
		$secretKey = $data['secret_key'];

		switch ($event) {
			//当 Gateway 连接上来时,注册 Gateway 并广播给所有 Workers
			case 'gateway_connect':
			case 'router_connect':
				if (empty($data['address'])) {
					echo "address not found\n";
					$connection->close();
					return;
				}

				if ($secretKey !== $this->secretKey) {
					echo "Register: Key does not match $secretKey !== {$this->secretKey}\n";
					$connection->close();
					return;
				}

				if ($event === 'gateway_connect') {
					$this->gatewayConnections[$connection->id] = $data['address'];
					$this->broadcastAddresses(self::TYPE_GATEWAY);
				} else {
					$this->routerConnections[$connection->id] = $data['address'];
					$this->broadcastAddresses(self::TYPE_ROUTER);
				}
				break;

			//当 Worker 连接上来时,注册 Worker 并向将所有 Gateway 地址发给这个 Worker
			case 'worker_connect':
				if ($secretKey !== $this->secretKey) {
					echo "Register: Key does not match $secretKey !== {$this->secretKey}\n";
					$connection->close();
					return;
				}

				$this->workerConnections[$connection->id] = $connection;
				$this->broadcastAddresses(self::TYPE_WORKER, $connection);
				break;

			case 'ping':
				break;

			default:
				echo "Register unknown event:$event IP: ".$connection->getRemoteIp()." Raw:$raw. See http://wiki.workerman.net/Error4\n";
				$connection->close();
		}

		//echo 'Register onMessage: '.$raw."\n";
//		echo 'Workers: '.join(',', array_keys($this->workerConnections))."\n";
//		echo 'Gateway: '.join(',', $this->gatewayConnections)."\n---------------\n";
	}

	/**
	 * 当 Gateway 或 Worker 断开连接时移除相关资源
	 * 
	 * @param \Workerman\Connection\TcpConnection $connection
	 */
	public function onClose($connection) {
		if (isset($this->gatewayConnections[$connection->id])) {
			unset($this->gatewayConnections[$connection->id]);
			$this->broadcastAddresses(self::TYPE_GATEWAY);
		} elseif (isset($this->routerConnections[$connection->id])) {
			//路由依赖哈希算法进行数据定位,路由服务数量理论上是不能变更的
			//否则会导致部分或大量 uid,group 无法接收到数据,即使是一致性哈希也无法很好的解决这个问题
			unset($this->routerConnections[$connection->id]);
			$this->broadcastAddresses(self::TYPE_ROUTER);
		} elseif (isset($this->workerConnections[$connection->id])) {
			unset($this->workerConnections[$connection->id]);
		}
	}

	/**
	 * 向 Worker 广播 Gateway 与 Router 内部通讯地址
	 *
	 * @param int $type
	 * @param \Workerman\Connection\TcpConnection $connection
	 */
	public function broadcastAddresses($type, $connection = null)
	{
		$data = ['event'=>'update_addresses'];
		
		switch ($type) {
			case self::TYPE_GATEWAY:
				$data['gateway_addresses'] = array_unique(array_values($this->gatewayConnections));
				break;
			case self::TYPE_ROUTER:
				$data['router_addresses'] = array_unique(array_values($this->routerConnections));
				break;
			//worker 类型代表向此 worker 发送所有地址
			case self::TYPE_WORKER:
				$data['gateway_addresses'] = array_unique(array_values($this->gatewayConnections));
				$data['router_addresses'] = array_unique(array_values($this->routerConnections));
				break;
		}

		$raw = serialize($data);

		if ($connection) {
			$connection->send($raw);
			return;
		}

		foreach ($this->workerConnections as $conn) {
			$conn->send($raw);
		}
	}

}