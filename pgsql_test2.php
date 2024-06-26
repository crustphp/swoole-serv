<?php


use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Coroutine;
Swoole\Coroutine::enableScheduler();
// or
//ini_set("swoole.enable_preemptive_scheduler", "1");

// Swoole\Coroutine::disableScheduler();

Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

use Swoole\Runtime;

co\run(function() {
    $pg = new Swoole\Coroutine\PostgreSQL();
    $conn = $pg->connect('host=127.0.0.1;port=5432;dbname=swooledb;user=postgres;password=passwd123');
    if ($conn === false) {
        throw new \RuntimeException(sprintf('Failed to connect PostgreSQL server: %s', $conn->error));
    }
    $statement = $pg->query('SELECT * FROM users;');
    $arr = $pg->fetchAll($statement);
// $arr = $connection->fetch($stat);
    $json = [
        'status'  => $arr,
    ];
//$response->header('Content-Type', 'application/json');
    var_dump(json_encode($json));
});

