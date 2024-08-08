<?php

use Swoole\Runtime;

use Swoole\Http\Server as swHttpServer;
use OpenSwoole\Http\Server as oswHttpServer;

use Swoole\Http\Request;
use Swoole\Http\Response;

use Swoole\Coroutine as swCo;
use OpenSwoole\Coroutine as oswCo;

use Swoole\Runtime as swRunTime;
use OpenSwoole\Runtime as oswRunTime;

use Swoole\Coroutine\Channel as swChannel;
use OpenSwoole\Coroutine\Channel as oswChannel;

use DB\DBConnectionPool;

use Swoole\WebSocket\Server as swWebSocketServer;
use OpenSwoole\WebSocket\Server as oswWebSocketServer;

use Swoole\WebSocket\Frame as swFrame;
use OpenSwoole\WebSocket\Frame as oswFrame;

use Swoole\WebSocket\CloseFrame as swCloseFrame;
use OpenSwoole\WebSocket\CloseFrame as oswCloseFrame;

use Swoole\Timer as swTimer;
use OpenSwoole\Timer as oswTimer;

use OpenSwoole\Constant;

// OR Through Scheduler
//$sch = new Swoole\Coroutine\Scheduler();
//$sch->set(['hook_flags' => SWOOLE_HOOK_ALL]);

// For Coroutine Context Manager: For Isolating Each Request Data from Other Request When in mode max_concurrency > 1
// GitHub Ref:. https://github.com/alwaysLinger/swcontext/blob/master/src/Context.php
// Packagist Ref: https://packagist.org/packages/yylh/swcontext
//use Al\Swow\Context;

//use Smf\ConnectionPool\Connectors\CoroutineMySQLConnector;
//use Swoole\Coroutine\MySQL;
//Ref.: https://github.com/open-smf/connection-pool

//Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

class sw_service {

    protected $swoole_vesion;
    protected $server;
    protected $postgresDbKey = 'pg';
    protected $mySqlDbKey = 'mysql';
    protected $dbConnectionPools;
    protected $isSwoole;
    protected $swoole_ext;
    protected $channel;
    protected $ip;
    protected $port;
    protected $serverMode;
    protected $serverProtocol;
    protected $fds=[];

   function __construct($ip, $port, $serverMode, $serverProtocol) {

       $this->ip = $ip;
       $this->port = $port;
       $this->serverMode = $serverMode;
       $this->serverProtocol = $serverProtocol;
       $this->swoole_ext = ($GLOBALS['swoole_ext'] ?? (extension_loaded('swoole') ? 1 : (
           extension_loaded('openswoole') ? 2 : 0)));

       Swoole\Coroutine::enableScheduler();
        //OR
        //ini_set("swoole.enable_preemptive_scheduler", "1");
        // Opposite
        // Swoole\Coroutine::disableScheduler();

       if ($this->serverProtocol=='http') {
           // Ref: https://openswoole.com/docs/modules/swoole-server-construct

           if ($this->swoole_ext == 1) {
               $this->server = new swHttpServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL
           } else if ($this->swoole_ext == 2) {
               $this->server = new oswHttpServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL
           } else {
               echo "Swoole Or OpenSwoole Extension is Missing\n".PHP_EOL;
               return false;
           }

           $this->bindHttpRequestEvent();

       }
       if ($this->serverProtocol=='websocket') {
           // Ref: https://openswoole.com/docs/modules/swoole-server-construct
           if ($this->swoole_ext == 1) {
               $this->server = new swWebSocketServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL
           } else if ($this->swoole_ext == 2) {
               $this->server = new oswWebSocketServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL
           } else {
               echo "Swoole Or OpenSwoole Extension is Missing\n".PHP_EOL;
               return false;
           }

           $this->bindWebSocketEvents();
       }

//       if ($this->swoole_ext == 1) {
//           swTimer::set([
//               'enable_coroutine' => true,
//           ]);
//       } else {
//           oswTimer::set([
//               'enable_coroutine' => true,
//           ]);
//       }

       $this->setDefault();
       $this->bindServerEvents();
       $this->bindWorkerEvents();
       $this->bindWorkerReloadEvents();

//       if ($this->swoole_ext == 1) {
//           $this->channel = new swChannel(3);
//       } else {
//           $this->channel = new oswChannel(3);
//       }

    }

    protected function setDefault()  {
        // Co is the short name of Swoole\Coroutine.
        // go() can be used to create new coroutine which is the short name of Swoole\Coroutine::create
        // Co\run can be used to create a context to execute coroutines.
        include_once './config/swoole_config.php';
        if ($this->swoole_ext == 1) {
            $swoole_config['coroutine_settings']['hook_flags'] = SWOOLE_HOOK_ALL;
            swCo::set($swoole_config['coroutine_settings']);
        } else {
            $swoole_config['coroutine_settings']['hook_flags'] = oswRunTime::HOOK_ALL;
            oswCo::set($swoole_config['coroutine_settings']);
        }

        if ($this->serverProtocol=='http') {
            $swoole_config['server_settings']['open_http_protocol'] = true;
        }
        if ($this->serverProtocol=='websocket') {
            $swoole_config['server_settings']['open_websocket_protocol'] = true;
            $swoole_config['server_settings']['enable_delay_receive'] = true;
        }

        $this->server->set($swoole_config['server_settings']);

//        // Use function-style below for onTask event, when co-touine inside task worker is not enabled in swoole configuration
//        $this->server->on('task', function ($server, $task_id, $src_worker_id, $data) {
//            include_once __DIR__ . '/Controllers/LongTasks.php';
//            $longTask = new LongTasks($server, $task_id, $src_worker_id, $data);
//            $data = $longTask->handle();
//            $server->finish($data);
//        });

        $this->server->on('task', function($server, $task) {
// Available parameters
//        dump($this->task->data);
//        $this->task->dispatch_time;
//        $this->task->id;
//        $this->task->worker_id;
//        $this->task->flags;
            include_once __DIR__ . '/Controllers/LongTasks.php';
            $longTask = new LongTasks($server, $task);
            $result = $longTask->handle();
            $task->finish($result);
        });

        $this->server->on('finish', function ($server, $task_id, $task_result)
        {
            echo "Task#$task_id finished, data_len=" . strlen($task_result[1]). PHP_EOL;
            echo "\$result: {$task_result[1]} from inside onFinish"; dump($task_result);
            $server->push($task_result[0],
                json_encode(['data'=>$task_result[1].'from inside onFinish']));
        });

        // channel stuff
//        $consumeChannel = function () {
//            echo "consume start\n";
//            while (true) {
//
//                $this->data[] = $this->channel->pop();
//                var_dump($data);
//            }
//        };
    }

    protected function bindServerEvents() {
        $my_onStart = function ($serv)
        {
            $this->swoole_version = (($this->swoole_ext == 1) ? SWOOLE_VERSION : '22.1.2');
            echo "Asynch ". ucfirst($this->serverProtocol)." Server started at $this->ip:$this->port in Server Mode:$this->serverMode\n";
            echo "MasterPid={$serv->master_pid}|Manager_pid={$serv->manager_pid}\n".PHP_EOL;
            echo "Server: start.".PHP_EOL."Swoole version is [" . $this->swoole_version . "]\n".PHP_EOL;
        };

        $my_onShutdown = function ($serv)
        {
            echo "Server Shutdown\n".PHP_EOL;
        };

        $this->server->on('start', $my_onStart);
        $this->server->on('shutdown', $my_onShutdown);
    }

    protected function bindWorkerReloadEvents() {
        $this->server->on('BeforeReload', function($server)
        {
            echo "Test Statement: Before Reload". PHP_EOL;
//            var_dump(get_included_files());
        });

        $this->server->on('AfterReload', function($server)
        {
            echo "Test Statement: After Reload". PHP_EOL;
//            var_dump(get_included_files());
        });
    }

    protected function bindWorkerEvents() {
        $init = function ($server, $worker_id) {

            global $argv;
            global $app_type_database_driven;
            global $swoole_pg_db_key;
            global $swoole_mysql_db_key;

            if($worker_id >= $server->setting['worker_num']) {
                if ($this->swoole_ext == 1) {
                    swoole_set_process_name("php {$argv[0]} Swoole task worker");
                } else {
                    OpenSwoole\Util::setProcessName("php {$argv[0]} Swoole task worker");
                }
            } else {
                if ($this->swoole_ext == 1) {
                    swoole_set_process_name("php {$argv[0]} Swoole event worker");
                } else {
                    OpenSwoole\Util::setProcessName("php {$argv[0]} Swoole event worker");
                }
            }
            // require __DIR__.'/bootstrap/ServiceContainer.php';

            // For Smf package based Connection Pool
            // Configure Connection Pool through SMF ConnectionPool class constructor
            // OR Swoole / OpenSwoole Connection Pool
            if ($app_type_database_driven) {
                $poolKey = makePoolKey($worker_id, 'postgres');
                try {
                    // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey,'postgres', 'swoole', true);
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key]->create();
                } catch (\Throwable $e) {
                    dump($e->getMessage());
                    dump($e->getFile());
                    dump($e->getLine());
                    dump($e->getCode());
                    dump($e->getTrace());
//                    var_dump($e->getFlags() === SWOOLE_EXIT_IN_COROUTINE);
                }

                /////////////////////////////////////////////////////
                //////// For Swoole Based PDO Connection Pool ///////
                /////////////////////////////////////////////////////
//           if (!empty(MYSQL_SERVER_DB))
                //$this->dbConnectionPools[$this->mySqlDbKey]->create(true);
//          require __DIR__.'/init_eloquent.php';
            }
        };

        $revokeWorkerResources = function($server, $worker_id) {
            global $app_type_database_driven;
            if ($app_type_database_driven) {
                if (isset($this->dbConnectionPools[$worker_id])) {
                    $worker_dbConnectionPools = $this->dbConnectionPools[$worker_id];
                    $mysqlPoolKey = makePoolKey($worker_id,'mysql');
                    $pgPoolKey = makePoolKey($worker_id,'postgres');
                    foreach ($worker_dbConnectionPools as $dbKey=>$dbConnectionPool) {
                        if ($dbConnectionPool->pool_exist($pgPoolKey)) {
                            echo "Closing Connection Pool: ".$pgPoolKey.PHP_EOL;
                            // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                            $dbConnectionPool->closeConnectionPool($pgPoolKey);
                        }

                        if ($dbConnectionPool->pool_exist($mysqlPoolKey)) {
                            echo "Closing Connection Pool: ".$mysqlPoolKey.PHP_EOL;
                            // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                            $dbConnectionPool->closeConnectionPool($mysqlPoolKey);
                        }
                        unset($dbConnectionPool);
                    }
                    unset($this->dbConnectionPools[$worker_id]);
                }
            }
        };

        $revokeAllResources = function($server) {
            global $app_type_database_driven;
            if ($app_type_database_driven) {
                if (isset($this->dbConnectionPools)) {
                    echo "Closing All Pools, Pool Containing objects, and Arrays referencing to the pool containing objects".PHP_EOL;
                    foreach ($this->dbConnectionPools as $worker_id=>$dbEngines_ConnectionPools) {
                        foreach ($dbEngines_ConnectionPools as $poolKey => $connectionPool) {
                            if ($worker_id = 0) { // Internal static array of connection pools can be closed through any one single $connectionPool object
                                $connectionPool->closeConnectionPools();
                            }
                            unset($connectionPool);
                        }
                        unset($dbEngines_ConnectionPools);
                    }
                    $this->dbConnectionPools = null;
                    unset($this->dbConnectionPools);
                    echo "Shutting Down Server".PHP_EOL;
                }
            }
        };

        $onWorkerError = function (OpenSwoole\Server $server, int $workerId) {
            echo "worker abnormal exit.".PHP_EOL."WorkerId=$worker_id|Pid=$worker_pid|ExitCode=$exit_code|ExitSignal=$signal\n".PHP_EOL;
            $revokeWorkerResources($serv, $worker_id);
        };

        $onWorkerStop = function (OpenSwoole\Server $server, int $workerId) {
            echo "WorkerStop[$worker_id]|pid=" . posix_getpid() . ".\n".PHP_EOL;
            $revokeWorkerResources($serv, $worker_id);
        };

        $this->server->on('workerstart', $init);
        $this->server->on('workerstop', $revokeWorkerResources);
        //To Do: Upgrade code using https://wiki.swoole.com/en/#/server/events?id=onworkererror
        $this->server->on('workererror', $revokeWorkerResources);
        $this->server->on('workerexit', $revokeWorkerResources);
        $this->server->on('shutdown', $revokeAllResources);

        // https://openswoole.com/docs/modules/swoole-server-on-task
        // https://openswoole.com/docs/modules/swoole-server-taskCo
        // TCP / UDP Client: https://openswoole.com/docs/modules/swoole-coroutine-client-full-example
    }

    protected function bindHttpRequestEvent() {
        $this->server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            include_once __DIR__ . '/Controllers/HttpRequestController.php';
            $sw_http_controller = new HttpRequestController($this->server, $request, $this->dbConnectionPools[$this->server->worker_id]);
            $responseData = $sw_http_controller->handle();
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($responseData));
        });
    }

    protected function bindWebSocketEvents() {
        $this->server->on('connect', function($websocketserver, $fd) {
            if (($fd % 3) === 0) {
                // 1 of 3 of all requests have to wait for two seconds before being processed.
                $timerClass = (($this->swoole_ext == 1) ? swTimer::class : oswTimer::class);
                $timerClass::after(2000, function () use ($websocketserver, $fd) {
                    $websocketserver->confirm($fd);
                });
            } else {
                // 2 of 3 of all requests are processed immediately by the server.
                $websocketserver->confirm($fd);
            }
        });

        $this->server->on('open', function($websocketserver, $request) {
            echo "server: handshake success with fd{$request->fd}\n";

//            $websocketserver->tick(1000, function() use ($websocketserver, $request) {
//                $server->push($request->fd, json_encode(["hello", time()]));
//            });
        });

        // This callback will be used in callback for onMessage event. next
        $respond = function($timerId, $webSocketServer, $frame, $sw_websocket_controller) {
            if (isset($this->fds[$frame->fd])) { // if the user / fd is connected then push else clear timer.
                if ($frame->data) { // when a new message arrives from connected client with some data in it
                    $bl_response = $sw_websocket_controller->handle();
                    $frame->data = false;
                } else {
                    $bl_response = 1;
                }
                $webSocketServer->push($frame->fd,
                    json_encode($bl_response),
                    WEBSOCKET_OPCODE_TEXT,
                    SWOOLE_WEBSOCKET_FLAG_FIN); // SWOOLE_WEBSOCKET_FLAG_FIN OR OpenSwoole\WebSocket\Server::WEBSOCKET_FLAG_FIN

            } else {
                echo "Clearing Timer ".$timerId.PHP_EOL;
                if ($this->swoole_ext == 1) {
                    swTimer::clear($timerId);
                } else {
                    oswTimer::clear($timerId);
                }
            }
        };
        $this->server->on('message', function($webSocketServer, $frame) use ($respond) {
//            if (isset($this->fds[$frame->fd])) {
//                if(class_exists(swTimer::class) && swTimer::exists($this->fds[$frame->fd])){
//                    swTimer::clear($this->fds[$frame->fd]);
//                } else if (class_exists(oswTimer::class) && oswTimer::exists($this->fds[$frame->fd])) {
//                    oswTimer::clear($this->fds[$frame->fd]);
//                }
//            }

            $closeFrameClass = (($this->swoole_ext == 1) ? swCloseFrame::class : oswCloseFrame::class);
            if ($frame === '') {
                $webSocketServer->close();
            } else if ($frame === false) {
                echo 'errorCode: ' . swoole_last_error() . "\n";
                $webSocketServer->close();
            } else if (trim($frame->data) == 'close' || get_class($frame) === $closeFrameClass || $frame->opcode == 0x08) {
                echo "Close frame received: Code {$frame->code} Reason {$frame->reason}\n";
            } else {
                $i=0;
                while (!$frame->finish) {
                    if ($i > 4000) {
                        $server->disconnect($frame->fd, SWOOLE_WEBSOCKET_CLOSE_NORMAL, "Frame Finish Time Exceeded.");
                    } else {
                        $i++;
                        echo "Frame is not Finished".PHP_EOL;
                        continue;
                    }
                }

                include_once __DIR__ . '/Controllers/WebSocketController.php';

                global $app_type_database_driven;
                if ($app_type_database_driven) {
                    $sw_websocket_controller = new WebSocketController($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id]);
                } else {
                    $sw_websocket_controller = new WebSocketController($webSocketServer, $frame);
                }

                $timerTime = $_ENV['SWOOLE_TIMER_TIME1'];
                if ($this->swoole_ext == 1) {
                    $this->fds[$frame->fd] = swTimer::tick($timerTime, $respond, $webSocketServer, $frame, $sw_websocket_controller);
                } else {
                    $this->fds[$frame->fd] = oswTimer::tick($timerTime, $respond, $webSocketServer, $frame, $sw_websocket_controller);
                }
            }
        });

        $this->server->on('close', function($server, $fd, $reactorId) {
            echo "client {$fd} closed\n in ReactorId:{$reactorId}";
            unset($this->fds[$fd]);
        });

        $this->server->on('disconnect', function(Server $server, int $fd) {
            echo "connection disconnect: {$fd}\n";
        });

// The Request event closure callback is passed the context of $server
//        $this->server->on('Request', function($request, $response) use ($server)
//        {
//            /*
//             * Loop through all the WebSocket connections to
//             * send back a response to all clients. Broadcast
//             * a message back to every WebSocket client.
//             */
//            foreach($server->connections as $fd)
//            {
//                // Validate a correct WebSocket connection otherwise a push may fail
//                if($server->isEstablished($fd))
//                {
//                    $server->push($fd, $request->get['message']);
//                }
//            }
//        });
    }

    public function start() {
        return $this->server->start();
    }
}

/*
 *
 * List of Server Events
Swoole\Server->on('start', fn)
Swoole\Server->on('shutdown', fn)
Swoole\Server->on('workerstart', fn)
Swoole\Server->on('workerstop', fn)
Swoole\Server->on('timer', fn)
Swoole\Server->on('connect', fn)
Swoole\Server->on('receive', fn)
Swoole\Server->on('packet', fn)
Swoole\Server->on('close', fn)
Swoole\Server->on('task', fn)
Swoole\Server->on('finish', fn)
Swoole\Server->on('pipemessage', fn)
Swoole\Server->on('workererror', fn)
Swoole\Server->on('managerstart', fn)
Swoole\Server->on('managerstop', fn)
Swoole\Server->on('beforereload', fn)
Swoole\Server->on('afterreload', fn)
 */

#################
/*
 * Co-routine Available Methods
 *
 * https://openswoole.com/docs/modules/swoole-coroutine#available-methods
 */

/* To-Dos :
## Hot Server Reload
https://openswoole.com/docs/modules/swoole-server-reload
https://github.com/swoole/swoole-src/issues/4577

## Run as Systemd
https://openswoole.com/docs/modules/swoole-server-construct#systemd-setup-for-swoole-server

// Swoole Server Task
https://openswoole.com/docs/modules/swoole-server-task
*/

// swoole_last_error(), co::sleep(), co::yield(), co::resume($cid), co::select(), co::getCid(), co::getPcid(), $serv->shutdown();

// Run-time Commands
//Check the Swoole processes:
// ps -aux | grep swool
// ps faux | grep -i sw_init_service.php
// sudo lsof -t -i:9501


// Track memory errors:
// USE_ZEND_ALLOC=0 valgrind php your_file.php

//To Start WebSocket Server:
//cd to swoole-serv foler, and then run the command below
//php sw_init_service.php websocket
//
//To send some messages from TCP Client use below:
//sudo php ./websocketclient/websocketclient_usage.php
//
//To reload server's workers:
//sudo php ./websocketclient/websocketclient_usage.php reload-code
//
//To Close Web Socker Server:
//php sw_init_service.php close

// Reload Workers and Task Workers, both, gracefully; after completing current requests
//kill -USR1 MASTER_PID
// Reload Task Worker Gracefully by completing current task
//kill -USR2 MASTER_PID

// Kill Service safely
// Kill (SIGTERM) Swoole Service:
// sudo kill -SIGTERM $(sudo lsof -t -i:9501)
// OR
// sudo kill -15 $(sudo lsof -t -i:9501)
// OR
// Kill the process:
// kill -15 [process_id]]
// OR (for daemon = 1 (daemonize mode)
// sudo kill `cat server.pid`

// Switch from Swoole to OpenSwoole, and vice versa
/*
sudo phpdismod -s cli swoole && \
sudo phpenmod -s cli openswoole
*/

// Switch from OpenSwoole to Swoole, and vice versa
/*
sudo phpdismod -s cli openswoole && \
sudo phpenmod -s cli swoole
*/
