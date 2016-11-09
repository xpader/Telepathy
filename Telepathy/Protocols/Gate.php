<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Telepathy\Protocols;

/**
 * Gateway �� Worker ��ͨѶ�Ķ�����Э��
 *
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char       cmd,//������
 *     unsigned int        local_ip,
 *     unsigned short      local_port,
 *     unsigned int        client_ip,
 *     unsigned short      client_port,
 *     unsigned int        connection_id,
 *     unsigned char       flag,
 *     unsigned short      gateway_port,
 *     unsigned int        ext_len,
 *     char[ext_len]       ext_data,
 *     char[pack_length-HEAD_LEN] body//����
 * }
 * NCNnNnNCnN
 */
class Gate
{
	// ����worker��gateway��һ���µ�����
	const CMD_ON_CONNECTION = 1;

	// ����worker�ģ��ͻ�������Ϣ
	const CMD_ON_MESSAGE = 3;

	// ����worker�ϵĹر������¼�
	const CMD_ON_CLOSE = 4;

	// ����gateway���򵥸��û���������
	const CMD_SEND_TO_ONE = 5;

	// ����gateway���������û���������
	const CMD_SEND_TO_ALL = 6;

	// ����gateway���߳��û�
	const CMD_KICK = 7;

	// ����gateway��֪ͨ�û�session����
	const CMD_UPDATE_SESSION = 9;

	// ��ȡ����״̬
	const CMD_GET_ALL_CLIENT_INFO = 10;

	// �ж��Ƿ�����
	const CMD_IS_ONLINE = 11;

	// client_id�󶨵�uid
	const CMD_BIND_UID = 12;

	// ���
	const CMD_UNBIND_UID = 13;

	// ��uid��������
	const CMD_SEND_TO_UID = 14;

	// ����uid��ȡ�󶨵�clientid
	const CMD_GET_CLIENT_ID_BY_UID = 15;

	// ������
	const CMD_JOIN_GROUP = 20;

	// �뿪��
	const CMD_LEAVE_GROUP = 21;

	// �����Ա����Ϣ
	const CMD_SEND_TO_GROUP = 22;

	// ��ȡ���Ա
	const CMD_GET_CLINET_INFO_BY_GROUP = 23;

	// ��ȡ���Ա��
	const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;

	// worker����gateway�¼�
	const CMD_WORKER_CONNECT = 200;

	// ����
	const CMD_PING = 201;

	// GatewayClient����gateway�¼�
	const CMD_GATEWAY_CLIENT_CONNECT = 202;

	// ����client_id��ȡsession
	const CMD_GET_SESSION_BY_CLIENT_ID = 203;

	// ����gateway������session
	const CMD_SET_SESSION = 204;

	// �����Ǳ���
	const FLAG_BODY_IS_SCALAR = 0x01;

	// ֪ͨgateway��sendʱ������Э��encode�������ڹ㲥�鲥ʱ��������
	const FLAG_NOT_CALL_ENCODE = 0x02;

	/**
	 * ��ͷ����
	 *
	 * @var int
	 */
	const HEAD_LEN = 28;

	public static $empty = array(
		'cmd'           => 0,
		'local_ip'      => 0,
		'local_port'    => 0,
		'client_ip'     => 0,
		'client_port'   => 0,
		'connection_id' => 0,
		'flag'          => 0,
		'gateway_port'  => 0,
		'ext_data'      => '',
		'body'          => '',
	);

	/**
	 * ���ذ�����
	 *
	 * @param string $buffer
	 * @return int return current package length
	 */
	public static function input($buffer)
	{
		if (strlen($buffer) < self::HEAD_LEN) {
			return 0;
		}

		$data = unpack("Npack_len", $buffer);
		return $data['pack_len'];
	}

	/**
	 * ��ȡ�������� buffer
	 *
	 * @param mixed $data
	 * @return string
	 */
	public static function encode($data)
	{
		$flag = (int)is_scalar($data['body']);
		if (!$flag) {
			$data['body'] = serialize($data['body']);
		}
		$data['flag'] |= $flag;
		$ext_len      = strlen($data['ext_data']);
		$package_len  = self::HEAD_LEN + $ext_len + strlen($data['body']);
		return pack("NCNnNnNCnN", $package_len,
			$data['cmd'], $data['local_ip'],
			$data['local_port'], $data['client_ip'],
			$data['client_port'], $data['connection_id'],
			$data['flag'], $data['gateway_port'],
			$ext_len) . $data['ext_data'] . $data['body'];
	}

	/**
	 * �Ӷ���������ת��Ϊ����
	 *
	 * @param string $buffer
	 * @return array
	 */
	public static function decode($buffer)
	{
		$data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nconnection_id/Cflag/ngateway_port/Next_len",
			$buffer);
		if ($data['ext_len'] > 0) {
			$data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
			if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
				$data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
			} else {
				$data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
			}
		} else {
			$data['ext_data'] = '';
			if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
				$data['body'] = substr($buffer, self::HEAD_LEN);
			} else {
				$data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
			}
		}
		return $data;
	}
}