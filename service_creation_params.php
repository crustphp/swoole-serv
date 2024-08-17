<?php
ini_set("register_argc_argv", true);
if (isset($argv[1]) && in_array($argv[1], ['shutdown', 'websocket', 'http','http2', 'tcp', 'udp', 'mqtt', 'grpc', 'socket'])) { // Set Default IP
   $serverProtocol = $argv[1];
} else {
    $serverProtocol = 'http';
}

if (isset($argv[2])) { // Set Default IP
    $ip = $argv[2];
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo "Invalid format of IP address";
        exit;
    }
} else {
    $ip = "0.0.0.0";
}

// Default port 9501
$port = '9501';
if (isset($argv[3]) &&
    preg_match('^([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$', $argv[3])) {
   $port = $argv[3];
}

// Default Server Mode
// Ref.: https://openswoole.com/docs/modules/swoole-server-construct
if (isset($argv[3])) {
    $serverMode = $argv[3];
    if (strtoupper($serverMode) == 'SWOOLE_PROCESS') {
        $serverMode = SWOOLE_PROCESS; // OpenSwoole\Server::POOL_MODE
    } else {
        $serverMode = SWOOLE_BASE; // OpenSwoole\Server::SIMPLE_MODE
    }
} else {
    $serverMode = SWOOLE_PROCESS; // Default Mode: OpenSwoole\Server::POOL_MODE
}
