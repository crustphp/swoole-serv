<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__.'/helper.php';

ini_set('memory_limit', -1);
use Swoole\Coroutine;
use Al\Swow\Context;
use Swoole\Runtime;

//ini_set("swoole.enable_preemptive_scheduler", "1");
//or
Swoole\Coroutine::enableScheduler();

// ini_set("swoole.enable_preemptive_scheduler", "1");

// Also, see :
// Swoole\Coroutine::disableScheduler();

// Enable all coroutine hooks before starting a server
Swoole\Runtime::enableCoroutine( true,SWOOLE_HOOK_ALL);

$test = 1;
$swoole_config = config('swoole_config');
co::set($swoole_config['coroutine_settings']);

$webSocketServer = new Swoole\WebSocket\Server("127.0.0.1", 9501, SWOOLE_PROCESS);
$swoole_config['server_settings']['open_websocket_protocol'] = true;
$webSocketServer->set($swoole_config['server_settings']);

//$webSocketServer->on("start", function ($webSocketServer) {
//});
//$webSocketServer->on("managerstart", function ($server) {
//});
//$webSocketServer->on("workerstart", function ($webSocketServer, $workerId) use (&$x) {
//});

$webSocketServer->on("message", function ($request, $response) {
    global $test;
    $test++;
    echo PHP_EOL.$test.PHP_EOL;
});

$webSocketServer->start();

//co\run (function() {
//    global $test;
    sleep(2);
    echo $test;
//});





/// This test proved succesful for change of global variable, and perhaps on reference from within coroutine
//global $test;
//$test = 2;
//co\run(function() {
//    global $test;
//    go(function() /*use(&$test)*/{
//        global $test;
//        co::sleep(3);
//        $test++; echo $test.'from inside onMesssage';
//    });
//    echo $test.'from outsie function';
//    co::sleep(4);
//    echo $test.'from outsie function: second time';
//});
