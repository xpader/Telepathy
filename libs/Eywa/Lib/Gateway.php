<?php
/**
 * Created by PhpStorm.
 * User: pader
 * Date: 16-11-26
 * Time: 上午12:40
 */
namespace Eywa\Lib;

use Eywa\Protocols\Gate;
use Exception;

class Gateway {

	/**
	 * 注册中心地址
	 *
	 * @var string
	 */
	public static $registerAddress = '127.0.0.1:1236';

	/**
	 * 秘钥
	 * @var string
	 */
	public static $secretKey = '';

	/**
	 * gateway 实例
	 *
	 * @var \Eywa\EywaWorker
	 */
	protected static $worker = null;

	/**
	 * 链接超时时间
	 * @var int
	 */
	public static $connectTimeout = 3;

	/**
	 * 与Gateway是否是长链接
	 * @var bool
	 */
	public static $persistentConnection = true;

	/**
	 * 向指定客户端连接发消息
	 *
	 * @param int    $clientId
	 * @param string $message
	 * @return bool
	 */
	public static function sendToClient($clientId, $message)
	{
		return self::command($clientId, Gate::CMD_SEND_TO_ONE, $message);
	}

	/**
	 * 判断某个客户端连接是否在线
	 *
	 * @param int $clientId
	 * @return bool
	 */
	public static function isOnline($clientId) {
		$addressData = Context::clientIdToAddress($clientId);
		if (!$addressData) {
			return false;
		}

		$address = long2ip($addressData['local_ip']) .':'.$addressData['local_port'];
		if (self::$worker && !isset(self::$worker->gatewayConnections[$address])) {
			return false;
		}

		$gatewayData = Gate::$empty;
		$gatewayData['cmd'] = Gate::CMD_IS_ONLINE;
		$gatewayData['connection_id'] = $addressData['connection_id'];
		return (bool)self::sendAndRecv($address, $gatewayData);
	}

	/**
	 * 关闭某个客户端
	 *
	 * @param int $clientId
	 * @return bool
	 */
	public static function closeClient($clientId) {
		$addressData = Context::clientIdToAddress($clientId);
		if (!$addressData) {
			return false;
		}
		$address = long2ip($addressData['local_ip']).':'.$addressData['local_port'];
		return self::kickAddress($address, $addressData['connection_id']);
	}
	
	protected static function command($clientId, $cmd, $message, $extData='') {
		$addressData  = Context::clientIdToAddress($clientId);

		if (!$addressData) {
			return false;
		}

		$address = long2ip($addressData['local_ip']) .':'.$addressData['local_port'];
		$connectionId = $addressData['connection_id'];

		$gatewayData                  = Gate::$empty;
		$gatewayData['cmd']           = $cmd;
		$gatewayData['connection_id'] = $connectionId;
		$gatewayData['body']          = $message;

		if (!empty($extData)) {
			$gatewayData['ext_data'] = $extData;
		}

		return self::sendToGateway($address, $gatewayData);
	}

	/**
	 * 发送数据并返回
	 *
	 * @param int   $address
	 * @param mixed $data
	 * @return bool
	 * @throws Exception
	 */
	protected static function sendAndRecv($address, $data) {
		$buffer = Gate::encode($data);
		self::$secretKey && $buffer = self::getAuthBuffer().$buffer;
		
		$client = stream_socket_client("tcp://$address", $errno, $errmsg, self::$connectTimeout);
		if (!$client) {
			throw new Exception("can not connect to tcp://$address $errmsg");
		}

		if (strlen($buffer) === stream_socket_sendto($client, $buffer)) {
			$timeout = 5;
			// 阻塞读
			stream_set_blocking($client, 1);
			// 1秒超时
			stream_set_timeout($client, 1);
			$all_buffer = '';
			$time_start = microtime(true);
			$pack_len = 0;
			while (1) {
				$buf = stream_socket_recvfrom($client, 655350);
				if ($buf !== '' && $buf !== false) {
					$all_buffer .= $buf;
				} else {
					if (feof($client)) {
						throw new Exception("connection close tcp://$address");
					} elseif (microtime(true) - $time_start > $timeout) {
						break;
					}
					continue;
				}
				$recv_len = strlen($all_buffer);
				if (!$pack_len && $recv_len >= 4) {
					$pack_len= current(unpack('N', $all_buffer));
				}
				// 回复的数据都是以\n结尾
				if (($pack_len && $recv_len >= $pack_len + 4) || microtime(true) - $time_start > $timeout) {
					break;
				}
			}
			// 返回结果
			return unserialize(substr($all_buffer, 4));
		} else {
			throw new Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
		}
	}

	/**
	 * 发送数据到网关
	 *
	 * @param string $address
	 * @param array  $gatewayData
	 * @return bool
	 */
	protected static function sendToGateway($address, $gatewayData)
	{
		return self::sendBufferToGateway($address, Gate::encode($gatewayData));
	}

	/**
	 * 发送buffer数据到网关
	 * @param string $address
	 * @param string $buffer
	 * @return bool
	 */
	protected static function sendBufferToGateway($address, $buffer) {
		//有$worker说明是workerman环境，使用$worker发送数据
		if (self::$worker) {
			return isset(self::$worker->gatewayConnections[$address]) ?
				self::$worker->gatewayConnections[$address]->send($buffer, true) : false;
		}

		//非workerman环境
		self::$secretKey && $buffer = self::getAuthBuffer() . $buffer;
		$flag = self::$persistentConnection ? STREAM_CLIENT_PERSISTENT | STREAM_CLIENT_CONNECT : STREAM_CLIENT_CONNECT;
		$client = stream_socket_client("tcp://$address", $errno, $errmsg, self::$connectTimeout, $flag);
		return strlen($buffer) == stream_socket_sendto($client, $buffer);
	}

	/**
	 * 踢掉某个网关的 socket
	 *
	 * @param string $address
	 * @param int $connectionId
	 * @return bool
	 */
	protected static function kickAddress($address, $connectionId) {
		$gatewayData = Gate::$empty;
		$gatewayData['cmd'] = Gate::CMD_KICK;
		$gatewayData['connection_id'] = $connectionId;
		return self::sendToGateway($address, $gatewayData);
	}

	/**
	 * 生成验证包，用于验证此客户端的合法性
	 *
	 * @return string
	 */
	protected static function getAuthBuffer() {
		$gatewayData         = Gate::$empty;
		$gatewayData['cmd']  = Gate::CMD_GATEWAY_CLIENT_CONNECT;
		$gatewayData['body'] = json_encode(array(
			'secret_key' => self::$secretKey,
		));
		return Gate::encode($gatewayData);
	}

	/**
	 * 设置 gateway 实例
	 *
	 * @param \Eywa\EywaWorker $eywaWorkerInstance
	 */
	public static function setWorker($eywaWorkerInstance) {
		self::$worker = $eywaWorkerInstance;
	}

}
