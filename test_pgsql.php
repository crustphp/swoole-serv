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

$swoole_config = include './config/swoole_config.php';

Co::set($swoole_config['coroutine_settings']);

$httpServer = new Swoole\Http\Server("127.0.0.1", 9501, SWOOLE_PROCESS);


$httpServer->set($swoole_config['server_settings']);

//////////////////////////////
// Postgres Connection Test//
//////////////////////////////

//use Swoole\Coroutine\PostgreSQL;
//
//Co\run(function () {
//    $pg = new Swoole\Coroutine\PostgreSQL();
//
//    $conn = $pg->connect("host=127.0.0.1;port=5432;dbname=muasheratdb;user=postgres;password=passwd123");
//
//    if (!$conn) {
//        var_dump($pg->error);
//        return;
//    }
//
//    var_dump($pg);
//
//    $result = $pg->query('SELECT * FROM users;');
//    if (!$result) {
//        var_dump($pg->error);
//        return;
//    }
//
//    $arr = $pg->fetchAll($result);
//    var_dump($arr);
//});

###############################################
### Swoole Task, OnTask, onFinish
###############################################

//ini_set("register_argc_argv", true);
//
//$server = new Swoole\Server("127.0.0.1", 9501, SWOOLE_PROCESS);
//
//$server->set([
////    'daemonize' => 1,
//    'worker_num' => 2,
//    'task_worker_num' => 4,
//
//    // Logging
//    // Ref: https://openswoole.com/docs/modules/swoole-server/configuration#log_level
//    //'log_level' => SWOOLE_LOG_ERROR, //SWOOLE_LOG_DEBUG
//    'log_level' => SWOOLE_LOG_DEBUG,
//    'log_file' => '/var/www/html/swoole-prac/logs/swoole.log',
//    'log_rotation' => SWOOLE_LOG_ROTATION_HOURLY,
//    'log_date_format' => '%Y-%m-%d %H:%M:%S',
//    'log_date_with_microseconds' => true,
//
//    // Enable trace logs
//    // Ref: https://openswoole.com/docs/modules/swoole-server/configuration#trace_flags
//    'trace_flags' => SWOOLE_TRACE_ALL,
//]);
//
//$server->on('Receive', function (Swoole\Server $server, $fd, $reactorId, $data) {
//    echo "Received data: " . $data . "\n";
//    $data = trim($data);
//
//    $server->task($data." By Fakhar", 0, function (Swoole\Server $server, $task_id, $data) {
//        echo "Task Callback: \n";
//        var_dump($task_id, $data);
//    });
//
//    $task_id = $server->task($data, 1);
//
//    $server->send($fd, "New task started with id: $task_id\n");
//});
//
//$server->on('Task', function (Swoole\Server $server, $task_id, $reactorId, $data) {
//    echo "Task Worker Process with task-id {$task_id} received data\n";
//
//    echo "[Worker-Id: {$server->worker_id}]\tonTask: [PID={$server->worker_pid}]: task_id=$task_id, data_len=" . strlen($data) . " [Data={$data}]." . PHP_EOL;
//
//    $server->finish($data);
//});

//$server->on('Finish', function (Swoole\Server $server, $task_id, $data) {
//    echo "Task#$task_id finished, data_len=" . strlen($data) . PHP_EOL;
//});
//
//$server->on('workerStart', function ($server, $worker_id) use ($argv) {
////    ini_set("register_argc_argv", true);
//    if ($worker_id >= $server->setting['worker_num']) {
//        swoole_set_process_name("php {$argv[0]}: task_worker{$worker_id}");
//    } else {
//        swoole_set_process_name("php {$argv[0]}: worker{$worker_id}");
//    }
//});
//
//$server->start();

#####################################################
#####################################################
//Co\run(function () {
//    var_dump(Co::getContext());
//    var_dump(Co::getPcid()); // -1
//
//    go(function () {
//        var_dump(Co::getPcid()); // 1
//
//        go(function () {
//            var_dump(Co::getPcid()); //2
//
//            go(function () {
//                var_dump(Co::getPcid()); // 3
//            });
//
//            go(function () {
//                var_dump(Co::getPcid()); //3
//            });
//
//            go(function () {
//                var_dump(Co::getPcid()); //3
//            });
//        });
//
//        var_dump(Co::getPcid()); //1
//    });
//
//    var_dump(Co::getPcid()); // -1
//});

##########################################################
##########################################################

//Co\run(function () {
//    print_r(Co::getContext());
//    var_dump(Co::getPcid()); // -1
//
//    go(function () {
//        var_dump(Co::getPcid()); // 1
//
//        go(function () {
//            var_dump(Co::getPcid()); //2
//
//            go(function () {
//                var_dump(Co::getPcid()); // 3
//            });
//
//            go(function () {
//                var_dump(Co::getPcid()); //3
//            });
//
//            go(function () {
//                var_dump(Co::getPcid()); //3
//            });
//        });
//
//        var_dump(Co::getPcid()); //1
//    });
//
//    var_dump(Co::getPcid()); // -1
//});

//use Swoole\Coroutine as Co;

#######################################
#######################################
//use Swoole\Coroutine as Co;
//
//$run = new Swoole\Coroutine\Scheduler;
//
//// Context 1
//$run->add(function()
//{
//    $context = Co::getContext();
//    $context['Context1'] = 'Context 1';
//    Co::sleep(1);
//    echo "Context 1 is done.\n";
//    echo $context['Context1'].PHP_EOL;
//});
//
//// Context 2
//$run->add(function()
//{
//    $context = Co::getContext();
//    $context['Context2'] = 'Context 2';
//    Co::sleep(2);
//    echo "Context 2 is done.\n";
//    echo $context['Context2'].PHP_EOL;
//});
//
//// Context 3
//$run->add(function()
//{
//    echo "Context 3 is done.\n";
//    $context = Co::getContext();
//    $context['Context3'] = 'Context 3';
//    echo $context['Context3'].PHP_EOL;
//});
//
//// Required or context containers won't run
//$run->start();

///////////////////////////////////////////////
//////// Context Manager example //////////////
//////////////////////////////////////////////

/*
 $httpServer->on("request", function ($request, $response) {
    $pcid = Coroutine::getCid();
    $pctx = new Context();
    $pctx->set('a', 'a');
    $pctx->set('b', 'b');
    $pctx->set('c', 'c');
    $pctx->delete('c');
    $pContainer = $pctx->getContainer();
    var_dump($pContainer);
    go(function () use ($pcid) {
        $ctx = new Context();
        $ctx->copy($pcid);
        var_dump($ctx->getContainer());
        $ctx->set('c', 'c');
        var_dump($ctx->getContainer());
    });
    Coroutine::sleep(0.2);
    // echo 123, PHP_EOL;
    $response->end("Hello World\n");
});
 */

$x =2;

$httpServer->on("start", function ($httpServer) use (&$x) {
    //$x++;
    echo $x.": on_start", PHP_EOL;
});

echo 'Global Increment '.++$x.PHP_EOL;

$httpServer->on("managerstart", function ($server) use (&$x) {
    //$x+=2;
    echo $x.': on_managerStart'.PHP_EOL;
    $x++;
});

echo 'Global-2: '.$x.PHP_EOL;

$httpServer->on("workerstart", function ($httpServer, $workerId) use (&$x) {
    echo $x.': on_workerStart '.$workerId, PHP_EOL; // The change made to a global variable in workerstart is reflected in onRequest

    class Test {
        private $data;
        public function __construct($param) {
            $this->data = $param;
        }
        public function increment() {
            $this->data++;
        }

        public function get(){
            return $this->data;
        }
    }

    class ServiceContainer {
        private static $instances = [];
        private static $callback;

        protected function __construct()  {
        }

        /**
         * Singletons should not be cloneable.
         */
        protected function __clone() { }

        /**
         * Singletons should not be restorable from strings.
         */
        public function __wakeup()
        {
            throw new \Exception("Cannot unserialize a singleton.");
        }

        public static function getInstance(callable $factory = null) {
            self::$callback = $factory ?? 'defaultFactory';
            $cls = static::class;
            if (!isset(self::$instances[$cls])) {
                self::$instances[$cls] = new static();
            }

            return self::$instances[$cls];
        }

        public function __invoke($val) {
            return (isset($this->callback) ? $this->callback($val) : new Test($val));
        }
    }
});

$httpServer->on("request", function ($request, $response) use (&$x) {
    $channel = new swoole\coroutine\channel(2);

    $cid1 = go(function() use ($channel)
    {
        $testFactory = ServiceContainer::getInstance();
        $obj1 = $testFactory(2);
        co::sleep(4);
        $channel->push($obj1);
        echo "Coro-1: ".$obj1->get().PHP_EOL;
        $obj1->increment();
    });

    $cid2 = go(function() use ($channel)
    {
        $testFactory = ServiceContainer::getInstance();
        $obj1 = $testFactory(3);
        $obj1->increment();
        co::sleep(2);
        $channel->push($obj1);
        echo "Coro-2: ".$obj1->get().PHP_EOL;
    });

    $cid3 = go(function()  use ($channel) {
         co::sleep(6);
        $x = $channel->pop();
        $y = $channel->pop();
        var_dump($x);
        var_dump($y);
    });

     $response->write(': on_request');
     $response->write(': on_request again');
    // $response->end(': on_request final');
});

$httpServer->on('Task', function (Swoole\Server $server, $task_id, $reactorId, $data)
{
    echo "Task Worker Process received data";

    echo "#{$server->worker_id}\tonTask: [PID={$server->worker_pid}]: task_id=$task_id, data_len=" . strlen($data) . "." . PHP_EOL;

    $server->finish($data);
});

$httpServer->start();
