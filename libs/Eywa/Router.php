<?php
/**
 * from https://github.com/walkor/GlobalData
 */
namespace Eywa;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

/**
 * Global data server.
 */
class Router {

	/**
	 * 注册中心地址
	 *
	 * @var string
	 */
	public $registerAddress = '127.0.0.1:1236';

	public $secretKey = '';

	/**
	 * 注册中心连接
	 * @var null
	 */
	protected $registerConnection = null;

	/**
	 * Worker instance.
	 * @var worker
	 */
	protected $worker = null;
	
	protected $lanAddr = '';

	/**
	 * All data.
	 * @var array
	 */
	protected $dataArray = array();

	/**
	 * Construct.
	 * @param string $lanIp
	 * @param int $port
	 */
	public function __construct($lanIp = '0.0.0.0', $port = 2207)
	{
		$this->lanAddr = $lanIp.':'.$port;
		
		$worker = new Worker("frame://{$this->lanAddr}");
		$worker->count = 1;
		$worker->name = 'Router';
		$worker->onWorkerStart = [$this, 'onWorkerStart'];
		$worker->onMessage = [$this, 'onMessage'];
		$worker->reloadable = false;
		$this->worker = $worker;
	}

	public function onWorkerStart() {
		$this->registerRouter();
	}

	/**
	 * @param \Workerman\Connection\TcpConnection $connection
	 * @param string $buffer
	 * @return void
	 */
	public function onMessage($connection, $buffer) {
		if($buffer === 'ping') {
			return;
		}
		
		$data = unserialize($buffer);
		if(!$buffer || !isset($data['cmd']) || !isset($data['key'])) {
			$connection->close(serialize('bad request'));
			return;
		}
		
		$cmd = $data['cmd'];
		$key = $data['key'];
		
		switch($cmd) {
			case 'get':
				if (!isset($this->dataArray[$key])) {
					$connection->send('N;');
					return;
				}
				$connection->send(serialize($this->dataArray[$key]));
				break;
			case 'set':
				$this->dataArray[$key] = $data['value'];
				$connection->send('b:1;');
				break;
			case 'add':
				if(isset($this->dataArray[$key])) {
					$connection->send('b:0;');
					return;
				}
				$this->dataArray[$key] = $data['value'];
				$connection->send('b:1;');
				break;
			case 'increment':
				if (!isset($this->dataArray[$key])) {
					$connection->send('b:0;');
					return;
				}
				if (!is_numeric($this->dataArray[$key])) {
					$this->dataArray[$key] = 0;
				}
				$this->dataArray[$key] = $this->dataArray[$key]+$data['step'];
				$connection->send(serialize($this->dataArray[$key]));
				break;
			case 'cas':
				if(isset($this->dataArray[$key]) && md5(serialize($this->dataArray[$key])) === $data['md5'])
				{
					$this->dataArray[$key] = $data['value'];
					$connection->send('b:1;');
					return;
				}
				$connection->send('b:0;');
				break;
			case 'delete':
				unset($this->dataArray[$key]);
				$connection->send('b:1;');
				break;
			default:
				$connection->close(serialize('bad cmd '. $cmd));
		}
	}

	/**
	 * 向注册中心注册本 Router
	 */
	public function registerRouter() {
		$this->registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");

		$data = [
			'event' => 'router_connect',
			'address' => $this->lanAddr,
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
		Timer::add(1, [$this, 'registerRouter'], null, false);
	}

}
