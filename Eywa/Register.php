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
	protected $dataConnections = [];
	protected $startTime = 0;

	const TYPE_GATEWAY = 0;
	const TYPE_WORKER = 1;

	public function run() {
		$this->onConnect = [$this, 'onConnect'];
		$this->onClose = [$this, 'onClose'];
		$this->onMessage = [$this, 'onMessage'];
		$this->startTime = time();
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

				$this->gatewayConnections[$connection->id] = $data['address'];
				$this->broadcastAddresses();
				break;

			//当 Worker 连接上来时,注册 Worker 并向将所有 Gateway 地址发给这个 Worker
			case 'worker_connect':
				if ($secretKey !== $this->secretKey) {
					echo "Register: Key does not match $secretKey !== {$this->secretKey}\n";
					$connection->close();
					return;
				}

				$this->workerConnections[$connection->id] = $connection;
				$this->broadcastAddresses($connection);
				break;

			case 'ping':
				break;

			default:
				echo "Register unknown event:$event IP: ".$connection->getRemoteIp()." Raw:$raw. See http://wiki.workerman.net/Error4\n";
				$connection->close();
		}
	}

	/**
	 * 当 Gateway 或 Worker 断开连接时移除相关资源
	 * 
	 * @param \Workerman\Connection\TcpConnection $connection
	 */
	public function onClose($connection) {
		if (isset($this->gatewayConnections[$connection->id])) {
			unset($this->gatewayConnections[$connection->id]);
			$this->broadcastAddresses();
		}
		if (isset($this->workerConnections[$connection->id])) {
			unset($this->workerConnections[$connection->id]);
		}
	}

	/**
	 * 向 BusinessWorker 广播 gateway 内部通讯地址
	 *
	 * @param \Workerman\Connection\TcpConnection $connection
	 */
	public function broadcastAddresses($connection = null)
	{
		$data = [
			'event' => 'update_gateway_addresses',
			'addresses' => array_unique(array_values($this->gatewayConnections)),
		];

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