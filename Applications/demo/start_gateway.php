<?php

use Eywa\Gateway;

//启动网关
$gateway = new Gateway('tcp://127.0.0.1:2001');
$gateway->registerAddress = '127.0.0.1:1236';