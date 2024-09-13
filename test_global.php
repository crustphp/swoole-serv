<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__.'/helper.php';

ini_set('memory_limit', -1);
use Swoole\Coroutine as co;
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


co::set([
    'reactor_num' => 1,
    'worker_num' => 1,
    ]);

$webSocketServer = new Swoole\Coroutine\WebSocket\Server("127.0.0.1", 9501, SWOOLE_PROCESS);

//$webSocketServer->on("start", function ($webSocketServer) {
//});
//$webSocketServer->on("managerstart", function ($server) {
//});
//$webSocketServer->on("workerstart", function ($webSocketServer, $workerId) use (&$x) {
//});

$webSocketServer->on("message", function ($request, $response) use (&$x) {

});

$webSocketServer->start();

co::sleep(10);


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
