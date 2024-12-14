<?php

namespace Websocketclient;

use Websocketclient\WebSocketClient;

$baseDir = dirname(__DIR__);

require_once realpath($baseDir . '/vendor/autoload.php');
include_once realpath($baseDir . '/includes/Autoload.php'); // Our custom Autoloader
include_once realpath($baseDir . '/helper.php');

// Setting up Env for using $_ENV variable
require_once realpath($baseDir . '/includes/LoadEnv.php');


$ip = '127.0.0.1';
if (isset($argv[1])) { // Set Default IP
    $argv1 = strtolower($argv[1]);

    if (filter_var($argv1, FILTER_VALIDATE_IP) || preg_match('/^(?!\-)(?:[a-z0-9\-]{1,63}\.)+[a-z]{2,}$/', $argv1)) {
        $ip = $argv1;
    }
}

// Default port 9501
$port = '9501';
if (isset($argv[2]) &&
    preg_match('/^([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$/', $argv[2])) {
   $port = $argv[2];
}

$w = new WebSocketClient($ip, $port);
if ($x = $w->connect()) {
//    var_dump($x);
    //reload-code
    if (isset($argv[1])) { // Code Reloading
        $cmd = strtolower($argv[1]);
        if (in_array($cmd, ['reload-code', 'shutdown'])) {

        }
        echo PHP_EOL."sending ".$cmd.PHP_EOL;
        $w->send($cmd, 'text', 1);
        // exit;
    }

    for ($i=1;$i<4;$i++)
        $w->send('test'.$i, 'text', 0);
    $w->send('end', 'text', 1);
    while(true) {
        $data = $w->recv();
        if ($data) {
            var_export($data);
            sleep(1);
        } else {
            break;
        }
    }
} else {
    echo "Could not connect to server".PHP_EOL;
}



/*
 *
 * use Swoole\Coroutine\HTTP\Client;

Co\run(function()
{
    $client = new Co\http\Client("127.0.0.1", 9501);

    $ret = $client->upgrade("/");

    if($ret)
    {
        while(true)
        {
            $client->push("Hello World!");
            var_dump($client->recv());
            Co\System::sleep(5);
        }
    }
});

 */
