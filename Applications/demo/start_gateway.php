<?php

use Telepathy\Gateway;

//��������
$gateway = new Gateway('tcp://127.0.0.1:2001');
$gateway->registerAddress = '127.0.0.1:1236';