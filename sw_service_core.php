<?php

//if (!\class_exists('\OpenSwoole\Table')) {
//    \class_alias('\Swoole\Table', '\OpenSwoole\Table');
//}

//$swoole_ext_loaded = (extension_loaded('swoole') ? true : false);
//if (!$swoole_ext_loaded) {
//    foreach(get_declared_classes() as $declared_class) {
//        if (strpos($declared_class, 'OpenSwoole') === 0) {
//            $openswoole_declared_classa= explode('\\', $declared_class);
//            $openswoole_declared_classa[0] = 'swoole';
//            $swoole_declared_class= implode('\\', $openswoole_declared_classa);
//            print_r($openswoole_declared_classa);
//            echo $declared_class . PHP_EOL;
//            echo $swoole_declared_class . PHP_EOL;
//            if (!\class_exists($swoole_declared_class)) {
//                \class_alias($declared_class, $swoole_declared_class);
//            }
//        }
//    }
//}

use Swoole\Runtime;

use Swoole\Http\Server as swHttpServer;
//use OpenSwoole\Http\Server as oswHttpServer;

use Swoole\Http\Request;
use Swoole\Http\Response;

use Swoole\Coroutine as swCo;
use OpenSwoole\Coroutine as oswCo;

use Swoole\Runtime as swRunTime;
//use OpenSwoole\Runtime as oswRunTime;

use Swoole\Coroutine\Channel as swChannel;
//use OpenSwoole\Coroutine\Channel as oswChannel;

use DB\DBConnectionPool;

use Swoole\WebSocket\Server as swWebSocketServer;
//use OpenSwoole\WebSocket\Server as oswWebSocketServer;

use Swoole\WebSocket\Frame as swFrame;
//use OpenSwoole\WebSocket\Frame as oswFrame;

use Swoole\WebSocket\CloseFrame;// as swCloseFrame;
//use OpenSwoole\WebSocket\CloseFrame as oswCloseFrame;

use Swoole\Timer as swTimer;
//use OpenSwoole\Timer as oswTimer;

use Swoole\Constant as swConstant;
//use OpenSwoole\Constant as oswConstant;

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

use Bootstrap\ServiceContainer;

use Bootstrap\SwooleTableFactory;
use Crust\SwooleDb\Selector\TableSelector;
use Crust\SwooleDb\Selector\Enum\ConditionElementType;
use Crust\SwooleDb\Selector\Enum\ConditionOperator;
use Crust\SwooleDb\Selector\Bean\ConditionElement;
use Crust\SwooleDb\Selector\Bean\Condition;

use Swoole\Process;
use App\Core\Enum\ResponseStatusCode;
use Carbon\Carbon;
use DB\DbFacade;
use Swoole\ExitException;
use App\Constants\LogMessages;
use App\Services\RefHistoricalAPIConsumer;

use App\Core\Processes\MainProcess;

use App\Core\Services\SubscriptionManager;
use App\Services\PushToken;

use App\Core\Traits\CustomProcessesTrait;
use Swoole\Coroutine\Channel;

use Swoole\Coroutine\System;
use App\Services\FetchRefinitivIndicatorHistory;
use App\Services\NewsWebsocketService;

class sw_service_core {

    use CustomProcessesTrait;

    protected $swoole_vesion;
    public $server;
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
    // protected static $fds=[];

    protected $serviceContainer;

    protected $customProcessCallbacks = [];

   function __construct($ip, $port, $serverMode, $serverProtocol) {

       $this->ip = $ip;
       $this->port = $port;
       $this->serverMode = $serverMode;
       $this->serverProtocol = $serverProtocol;

       $this->swoole_ext = config('app_config.swoole_ext');

       date_default_timezone_set(config('app_config.time_zone')); // Set default timezone globally

       Swoole\Coroutine::enableScheduler();
        //OR
        //ini_set("swoole.enable_preemptive_scheduler", "1");
        // Opposite
        // Swoole\Coroutine::disableScheduler();

        // Migrate the Swoole Table Migrations
        SwooleTableFactory::migrate();

       if ($this->serverProtocol=='http') {
           // Ref: https://openswoole.com/docs/modules/swoole-server-construct

          $this->server = new swHttpServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL

           $this->bindHttpRequestEvent();

       }
       if ($this->serverProtocol=='websocket') {
           // Ref: https://openswoole.com/docs/modules/swoole-server-construct
           $this->server = new swWebSocketServer($ip, $port, $serverMode); // for http2 also pass last parameter as SWOOLE_SOCK_TCP | SWOOLE_SSL
           $this->bindWebSocketEvents();
       }

       $this->setDefault();
       $this->bindServerEvents();
       $this->bindWorkerEvents();
       $this->bindWorkerReloadEvents();

//    $this->channel = new swChannel(3);
    }

    protected function setDefault()  {
        // Co is the short name of Swoole\Coroutine.
        // go() can be used to create new coroutine which is the short name of Swoole\Coroutine::create
        // Co\run can be used to create a context to execute coroutines.
        $swoole_config = config('swoole_config');

        $swoole_config['coroutine_settings']['hook_flags'] = SWOOLE_HOOK_ALL;
        swCo::set($swoole_config['coroutine_settings']);

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
//        var_dump($this->task->data);
//        $this->task->dispatch_time;
//        $this->task->id;
//        $this->task->worker_id;
//        $this->task->flags;
            include_once __DIR__ . '/controllers/LongTasks.php';
            $longTask = new LongTasks($server, $task);
            $result = $longTask->handle();
            $task->finish($result);
        });

        $this->server->on('finish', function ($server, $task_id, $task_result)
        {
            //co::sleep(3); // Added delay only to demonstrate Asynchronous Processing. Next call to task (the one having own callback to process results) will still be processed, and due to this sleep that will call complete before this.
            echo "Task#$task_id finished, data_len=" . strlen($task_result[1]). PHP_EOL;
            echo "\$result: {$task_result[1]} from inside onFinish"; var_dump($task_result);

            if ($server->isEstablished($task_result[0])) {
                $server->push($task_result[0],
                    json_encode(['data'=>$task_result[1].'from inside onFinish']));
            }

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

        // Server FDs (We use this to store the FDs each worker has)
        $this->server->fds = [];

        // Background processes
        include_once __DIR__ . '/includes/Autoload.php';

        // Techniques to Reload the Code (Following commented code can be useful in future)
        // $processCallback = new ProcessCallback($this->server); // Its a service
        // $this->customProcessCallbacks['test_service'] = [$processCallback, 'handle'];

        // We can reload the Process by using include inside the handle function of callback below
        // Something like this function handle() { include 'path/to/original-process-callback.php'; }
        // $testProcess = new Process($this->customProcessCallbacks['test_service'], false, SOCK_DGRAM, true);
        // $this->server->addProcess($testProcess);

        // Create a MainProcess. This MainProcess will start other processes
        // When main process will be reloaded. It will start processes including new ones.
        $this->customProcessCallbacks['MainProcess'] = function ($process) {
            try {
                // Create the PID file of process - Used to kill the process in Before Reload
                $pidFile = __DIR__ . '/process_pids/MainProcess.pid';
                file_put_contents($pidFile, $process->pid);

                $mainProcessBase = new MainProcess($this->server, $process);
                $mainProcessBase->handle();
            } catch (\Throwable $e) {
                // On Local Environment, shutdown the server on Exception for debugging
                // Else just log the exception without exiting/shutting down the server
                if (config('app_config.env') == 'local') {
                    output(data: $e, server: $this->server, shouldExit: true);
                } else {
                    output($e);
                }
            }
        };

        if (!isset($this->server->customProcesses)) {
            $this->server->customProcesses = [];
        }

        $this->server->customProcesses['MainProcess'] = new Process($this->customProcessCallbacks['MainProcess'], false, SOCK_DGRAM, false);

        $this->server->addProcess($this->server->customProcesses['MainProcess']);
    }

    protected function bindServerEvents() {
        $my_onStart = function ($server)
        {
            $this->swoole_version = (($this->swoole_ext == 1) ? SWOOLE_VERSION : '22.1.5');
            if (!file_exists("server.pid")) {
                file_put_contents(__DIR__.'/server.pid', $server->master_pid);
            }
            echo "Asynch ". ucfirst($this->serverProtocol)." Server started at $this->ip:$this->port in Server Mode:$this->serverMode\n";
            echo "MasterPid={$server->master_pid}|Manager_pid={$server->manager_pid}\n".PHP_EOL;
            echo "Server: start.".PHP_EOL."Swoole version is [" . $this->swoole_version . "]\n".PHP_EOL;
        };

        $revokeAllResources = function($server) {
            echo "Shutting Down Server".PHP_EOL;
            $app_type_database_driven = config('app_config.app_type_database_driven');
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
                }
            }
            $swoole_daemonize = config('app_config.swoole_daemonize');
            if ($swoole_daemonize == false && file_exists('server.pid')) {
                shell_exec('cd '.__DIR__.' && rm -f server.pid 2>&1 1> /dev/null&');
            }

            // Remove the Main Process PID File (As this process is shutdown by Server itself so we need to remove file manually)
            $mainProcessPidDFile = __DIR__ . '/process_pids/MainProcess.pid';

            if (file_exists($mainProcessPidDFile)) {
                unlink($mainProcessPidDFile);
            }

            unset($this->server);
        };

        $onBeforeShutdown = function($server) {
            // echo PHP_EOL. 'SERVER WORKER ID on Before Shutdown: '. $server->worker_id . PHP_EOL;
            // Tell the FDs that server is shutting down, So they can reconnect
            // Important Note: We have achieved the better solution in onWorkerStop event
            // foreach ($server->connections as $conn) {
            //     $server->push($conn, json_encode(['message' => 'Server is shutting down.', 'status_code' => ResponseStatusCode::SERVICE_RESTART->value]));
            // }

            // Kill the custom user Processes
            $this->killCustomProcesses(true);
        };

        $this->server->on('start', $my_onStart);
        $this->server->on('shutdown', $revokeAllResources);
        $this->server->on('BeforeShutdown', $onBeforeShutdown);
    }

    protected function broadcastDataToFDs(&$server, $message, $fds) {
        foreach($fds as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $message);
            }
        }
    }

    protected function bindWorkerReloadEvents() {
        $this->server->on('BeforeReload', function($server)
        {
            // We iterate through the processes and kill them. Swoole create new processes
            // In this way we achieve reloading of custom user processes
            $this->killProcessesByPidFolder();
        });

        $this->server->on('AfterReload', function($server)
        {
            // echo PHP_EOL."Test Statement: After Reload". PHP_EOL;
        });
    }

    protected function bindWorkerEvents() {
        $init = function ($server, $worker_id) {
            if ($worker_id === 0) {
                // Log Rotation (Since swoole log_rotation does not work as expected and not for custom processes)
                // So following code do the Log File Rotation manually.
                $swooleLogRotationInterval = config('app_config.log_rotation_interval') * 60 * 1000;

                swTimer::tick($swooleLogRotationInterval, function () {
                    go(function () {
                        $logFile = __DIR__ . "/logs/swoole.log";
                        $timestamp = date('Y-m-d_H-i');
                        $rotatedLogFile = __DIR__ . "/logs/swoole_{$timestamp}.log";

                        if (is_file($logFile)) {
                            // Read the log file
                            $logFileData = System::readFile($logFile);

                            if ($logFileData === false || strlen($logFileData) === 0) {
                                return;
                            }

                            $writeSuccess = System::writeFile($rotatedLogFile, $logFileData);
                            if ($writeSuccess === false) {
                                output("Error - Failed to write the rotated log File");
                                return;
                            }

                            // Truncate original log file (empty it)
                            $clearSuccess = System::writeFile($logFile, '');
                            if ($clearSuccess === false) {
                                output("Error - Failed to clear the swoole log file");
                                return;
                            }
                        }
                    });
                });

                // Clean up the old swoole log files (Swoole Timer will run after every 6 Hours - 6 * 60 * 60 * 1000)
                $daysToKeep = config('app_config.log_retention_days');

                swTimer::tick(21600000, function () use ($daysToKeep) {
                    go(function () use ($daysToKeep) {
                        $thresholdDate = Carbon::now()->subDays($daysToKeep - 1)->startOfDay();
                        $files = glob(__DIR__ . '/logs/swoole_*.log');

                        foreach ($files as $filePath) {
                            if (preg_match('/swoole_(\d{4}-\d{2}-\d{2})_/', $filePath, $matches)) {
                                $fileDate = Carbon::createFromFormat('Y-m-d', $matches[1])->startOfDay();

                                if ($fileDate->lt($thresholdDate)) {
                                    if (@unlink($filePath)) {
                                        output("Deleted old log: $filePath");
                                    } else {
                                        output("Failed to delete log file: $filePath");
                                    }
                                }
                            }
                        }
                    });
                });
            }

            // Setting Global $server (This is currently used in Subscription Manager)
            $GLOBALS['global_server'] = $server;

            if (function_exists( 'opcache_get_status' ) && is_array(opcache_get_status())) {
                opcache_reset();
            }

            ///////////////////////////////////////////////////////////
            /////////////Hot Code Reload: Code Starts Here/////////////
            ///////////////////////////////////////////////////////////
            global $inotify_handles;
            global $watch_descriptors;
            $inotify_handles = [];
            $watch_descriptors = [];
            if ($worker_id == 0 ) {

                // Convert excluded folders into an array and trim whitespace around folder names for accurate matching
                $skipDirs = array_map('trim', explode(',', config('app_config.watch_excluded_folders')));

                // Get All Directories list recurrsively
                $dirs = getAllDirectories(__DIR__, $skipDirs);

                foreach ($dirs as $dir) {
                    $inotify_handles[] = inotify_init();
                    $watch_descriptors[] = inotify_add_watch($inotify_handles[count($inotify_handles)-1], $dir,
                        IN_DELETE | IN_CREATE | IN_MODIFY
                    );

                    Swoole\Event::add($inotify_handles[count($inotify_handles) -1], function () use ($server, $inotify_handles){

                        $events = inotify_read($inotify_handles[count($inotify_handles)-1]);
                        if ($events) {
                            echo PHP_EOL.count($events).PHP_EOL;
                            print_r($events);

                            foreach ($events as $event=>$evdetails) {
                                // React on the event type
                                if (!empty($evdetails['name'])) {
                                    $file_name_with_ext = $evdetails['name'];
                                    $file_name_arr = explode('.', $file_name_with_ext);
                                    $file_name = $file_name_arr[0] ?? '';
                                    $file_extension = $file_name_arr[1] ?? '';

                                    if (in_array($file_name, ['sw_service_core', 'ProcessesRegister', 'EnvironmentConfigurations']) || in_array($file_extension, ['php'])) {

                                        if (($evdetails['mask'] & IN_CREATE) || ($evdetails['mask'] & IN_MODIFY) || ($evdetails['mask'] & IN_DELETE)) {
                                            // Reloads the codes included / autoloaded inside the callbacks of only those events ..
                                            // which are scoped to Event Worker
                                            echo PHP_EOL.'Reloading Event Workers and Task Workers'.PHP_EOL;
                                            $server->reload();
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }
//            Swoole\Event::set($inotify_handle, null, null, SWOOLE_EVENT_READ);
            /////////////////////////////////////////////////////////
            /////////////Hot Code Reload: Code Ends Here/////////////
            /////////////////////////////////////////////////////////

            global $argv;
            $app_type_database_driven = config('app_config.app_type_database_driven');
            $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
            $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
            $serviceStartedBy = serviceStartedBy();

            if ($worker_id >= $server->setting['worker_num']) {
                if ($this->swoole_ext == 1) {
                    swoole_set_process_name("php-$serviceStartedBy-Swoole-task-worker-" . $worker_id);
                } else {
                    OpenSwoole\Util::setProcessName("php-$serviceStartedBy-Swoole-task-worker-" . $worker_id);
                }
            } else {
                if ($this->swoole_ext == 1) {
                    swoole_set_process_name("php-$serviceStartedBy-Swoole-event-worker-" . $worker_id);
                } else {
                    OpenSwoole\Util::setProcessName("php-$serviceStartedBy-Swoole-event-worker-" . $worker_id);
                }
            }
            // require __DIR__.'/bootstrap/ServiceContainer.php';

            // For Smf package based Connection Pool
            // Configure Connection Pool through SMF ConnectionPool class constructor
            // OR Swoole / OpenSwoole Connection Pool
            // We want to create the DBConnectionPools just for Worker Processes (Not for Task Workers) ...
            // We can pass the ConnectionPool to TaskWorkers if they need it.
            if ($app_type_database_driven && $worker_id < $server->setting['worker_num']) {
                $poolKey = makePoolKey($worker_id, 'postgres');
                try {
                    // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey,'postgres', 'swoole', true);

                    // Following Commented Code is for example purpose how you can dynamically update Pool Size before calling create()
                    // if ($worker_id == 2) {
                    //     $this->dbConnectionPools[$worker_id][$swoole_pg_db_key]->setPoolSize(5);
                    // }

                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key]->create();
                } catch (\Throwable $e) {
                    echo $e->getMessage() . PHP_EOL;
                    echo $e->getFile() . PHP_EOL;
                    echo $e->getLine() . PHP_EOL;
                    echo $e->getCode() . PHP_EOL;
                    var_dump($e->getTrace());
//                    var_dump($e->getFlags() === SWOOLE_EXIT_IN_COROUTINE);
                }

                /////////////////////////////////////////////////////
                //////// For Swoole Based PDO Connection Pool ///////
                /////////////////////////////////////////////////////
//           if (!empty(MYSQL_SERVER_DB))
                //$this->dbConnectionPools[$this->mySqlDbKey]->create(true);
//          require __DIR__.'/init_eloquent.php';

            }

            // Get the Service Container Instance
            $this->serviceContainer = ServiceContainer::get_instance();

            // If we have the any FDs in FDs Table then we restore them
            try {
                $fdsTable = SwooleTableFactory::getTable('fds_table');
                $fdsRecordsCount = $fdsTable->count();

                if ($fdsRecordsCount > 0) {
                    $selector = new TableSelector('fds_table');

                    // Select the FDS of current worker instead of all FDs if total FDs are more than Threshold
                    // Otherwise select all the FDs
                    if ($fdsRecordsCount > config('app_config.fds_reload_threshold')) {
                        // Fetch the FDs for specific worker
                        $selector->where()
                            ->firstCondition(new Condition(
                                new ConditionElement(ConditionElementType::var, 'worker_id', 'fds_table'),
                                ConditionOperator::equal,
                                new ConditionElement(ConditionElementType::const, $worker_id)
                            ));
                    }

                    $fdRecords = $selector->execute();

                    // Here we will load the fds into the server
                    foreach ($fdRecords as $record) {
                        $fd = $record['fds_table']->getValue('fd');
                        $fdWorkerId = $record['fds_table']->getValue('worker_id');

                        if ($worker_id == $fdWorkerId) {
                            // Add the FD to the worker process fds scope
                            $server->fds[$fd] = $fd;

                            // Remove it from the FDs Table
                            $fdsTable->del($fd);
                        }
                    }
                }
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }

            // Retrieve the existing subscription data from the Swoole Table to Worker Scope
            try {
                $subscriptionManager = new SubscriptionManager();
                $subscriptionManager->restoreSubscriptionsFromTable();

            }
            catch(\Throwable $e) {
                output($e);
            }
        };

        $revokeWorkerResources = function($server, $worker_id) {
            // Revoke Database Pool Resources
            $this->revokeDatabasePoolResources($worker_id);

            // Revoke Inotify Resources
            $this->revokeInotifyResources($worker_id);

            // Clear the Timers
            swTimer::clearAll();
        };

        // Docs: https://wiki.swoole.com/en/#/server/events?id=onworkererror
        $onWorkerError = function ($server, $worker_id, $worker_pid, $exit_code, $signal) use($revokeWorkerResources) {
            echo "worker abnormal exit.".PHP_EOL."WorkerId=$worker_id|Pid=$worker_pid|ExitCode=$exit_code|ExitSignal=$signal\n".PHP_EOL;
            $revokeWorkerResources($server, $worker_id);
        };

        $onWorkerStop = function ($server, int $workerId) {
            // Revoke Database Pool Resources
            $this->revokeDatabasePoolResources($workerId);

            // Revoke Inotify Resources
            $this->revokeInotifyResources($workerId);

            // echo "WorkerStop[$worker_id]|pid=" . posix_getpid() . ".\n".PHP_EOL;

            // In-case of Shutdown, inform the FDs to reconnect.
            try {
                $stopCodeTable = SwooleTableFactory::getTable('worker_stop_code');
                $isShuttingDown = false;
                if ($stopCodeTable->exists(1)) {
                    $isShuttingDown = $stopCodeTable->get(1, 'is_shutting_down');
                }

                if ($isShuttingDown) {
                    // This case will be executed when the server is going to shutdown by our explicit $server->shutDown() command
                    // We will tell fds to reconnect to the server
                    if ($workerId < config('swoole_config.server_settings.worker_num')) {
                        $serverFds = $server->fds;
                        $serverFdsChunk = array_chunk($serverFds, 100);

                        foreach ($serverFdsChunk as $chunk) {
                            foreach ($chunk as $fd) {
                                if ($server->isEstablished($fd)) {
                                    $server->push($fd, json_encode(['message' => 'Server is shutting down.', 'status_code' => ResponseStatusCode::SERVICE_RESTART->value]));
                                }
                            }
                        }
                    }

                } else {
                    // In all other cases we will store the FDs to restore them during worker start
                    $fdsTable = SwooleTableFactory::getTable('fds_table');
                    foreach ($server->fds as $fd) {
                        $fdsTable->set($fd, ['fd' => $fd, 'worker_id' => $workerId]);
                    }
                }
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }
        };

        // When a working process receives a message sent by $server->sendMessage() trigger the onPipeMessage event.
        // Both worker and task processes may trigger the onPipeMessage event. We can use it receive messages from other processes
        // Source Worker ID is the ID of the process from which we call sendMessage() function
        // More Details: https://wiki.swoole.com/en/#/server/events?id=onpipemessage
        $onPipeMessage = function($server, $src_worker_id, $message): void
        {
            // If Topic or Data is not provided than log the Error Message
            if (!isset($message['topic']) || !isset($message['message_data'])) {
                output('Error: Topic or Data missing for broadcasting.');
                return;
            }

            $topic = $message['topic'];
            $msgData = $message['message_data'];

            // Encode the msgData to json string if its an array
            if (is_array($msgData)) {
                $msgData = json_encode($msgData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($msgData == false) {
                    output("JSON encoding Topic: ($topic) data error: " . json_last_error_msg());
                    return;
                }
            }

            // Get FDs subscribed to provided topic
            $subscriptionManager = new SubscriptionManager();
            $fds = $subscriptionManager->getFdsOfTopic($topic);
            $fds = array_column($fds, 'fd'); // Temporary code in this PR to avoid breaking current functionality.
            unset($subscriptionManager);

            // send to your known fds in worker scope
            $this->broadcastDataToFDs($server,$msgData, $fds);
        };

        $this->server->on('workerstart', $init);
        //To Do: Upgrade code using https://wiki.swoole.com/en/#/server/events?id=onworkererror
        $this->server->on('workererror', $revokeWorkerResources);
        $this->server->on('workerexit', $revokeWorkerResources);
        $this->server->on('workerstop', $onWorkerStop); // Executes after Worker Exit
        // Bind pipeMessage event to server
        $this->server->on('pipeMessage', $onPipeMessage);

        // https://openswoole.com/docs/modules/swoole-server-on-task
        // https://openswoole.com/docs/modules/swoole-server-taskCo
        // TCP / UDP Client: https://openswoole.com/docs/modules/swoole-coroutine-client-full-example
    }

    protected function bindHttpRequestEvent() {
        $this->server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            include_once __DIR__ . '/controllers/HttpRequestController.php';
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
                $timerClass = swTimer::class;
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

            // Is connected FD a Privileged FD
            $isPrivilegedFd = isset($request->header) && isset($request->header['privileged-key']) && $request->header['privileged-key'] == config('app_config.privileged_fd_secret');

            if ($isPrivilegedFd) {
                $websocketserver->privilegedFds[$request->fd] = $request->fd;
            }

            if (isset($request->header) && isset($request->header["privileged-fd-key-for-ref-token"]) && !empty($request->header["privileged-fd-key-for-ref-token"])) {
              if (config('app_config.env') != 'local' && config('app_config.env') != 'staging' && config('app_config.env') != 'pre-production') {
                // Sending Refinitive Token to Envirenement FDS (local, stage, pre-production)
                $pushToken = new PushToken($websocketserver, $request);
                $pushToken->handle();
                unset($pushToken);
              }
            } else if (!$isPrivilegedFd) {
                // Add fd to scope
                // Here I am storing the FD in array index also as FD for directly accessing it in array.
                $websocketserver->fds[$request->fd] = $request->fd;

            //            $websocketserver->tick(1000, function() use ($websocketserver, $request) {
            //                $server->push($request->fd, json_encode(["hello", time()]));
            //            });

                // Start: Take the list of Topics FD wants to Subscribe to
                $subscriptionTopics = isset($request->get['topic_subcription']) && trim($request->get['topic_subcription']) !== '' ? array_map('trim', explode(',', $request->get['topic_subcription'])) : [];
                if (empty($subscriptionTopics)) {
                    $warnMsg = ['message' => 'Warning: Please provide the topics you want to subscribe to', 'status_code' => ResponseStatusCode::UNSUPPORTED_PAYLOAD->value];
                    $websocketserver->push($request->fd, json_encode($warnMsg));
                    return;
                }
                // Handle subscription via SubscriptionManager
                $subscriptionManager = new SubscriptionManager();
                $subscriptionResults = $subscriptionManager->manageSubscriptions($request->fd, $subscriptionTopics, [], $websocketserver->worker_id);

                if (!empty($subscriptionResults['errors'])) {
                    $websocketserver->push($request->fd, 'Subscription errors: ' . implode(', ', $subscriptionResults['errors']));
                }
                // End Code: Take the list of Topics FD wants to Subscribe to

                $objDbPool = $this->dbConnectionPools[$websocketserver->worker_id][config('app_config.swoole_pg_db_key')];
                $dbFacade = new DbFacade();

                // Get All topics of Fd
                $topics = $subscriptionManager->getTopicsOfFD($request->fd);
                $topics = array_column($topics, 'topic');

                // Broadcast the News data (First page, without filters, just KSA) when FD subscribe the news Topic
                if (in_array('news', $topics)) {
                    go(function () use ($websocketserver, $request) {
                        $newsFilters = [
                            'country' => 'KSA',
                            'page' => 1
                        ];

                        $service = new NewsWebsocketService($websocketserver, $request, $this->dbConnectionPools[$websocketserver->worker_id], $newsFilters, null, false);
                        $service->handle();
                    });
                }

                // Start Code: Broadcast data of Company's Indicators Data
                go(function () use ($objDbPool, $dbFacade, $subscriptionManager, $request, $websocketserver, $topics) {
                    $this->processCompaniesTopics($topics, $dbFacade, $objDbPool, $websocketserver, $request);
                });
                // -------- End Code for Refinitive topics broadcasting ----------- //

                // -------- Start Code: Broadcast data of Market Overview ---------- //
                go(function () use ($topics, $request, $websocketserver) {
                    $allMarketTopics = explode(',', config('ref_config.ref_market_overview'));
                    $marketTopics = array_intersect($topics, $allMarketTopics);
                    if (!empty($marketTopics)) {
                        $this->processTopicsRowwise(topics: $marketTopics, tableName: 'markets_overview', frame: $request, webSocketServer: $websocketserver);
                    }
                });
                // -------- End Code for Market Overview topics broadcasting ----------- //

                // -------- Start Code: Broadcast data of Market's Indicators ---------- //
                go(function () use ($objDbPool, $dbFacade, $request, $websocketserver, $topics) {
                    $this->processMarketTopics($topics, $dbFacade, $objDbPool, $websocketserver, $request);
                });
                // -------- End Code: Broadcast data of Market's Indicators ----------- //

                // Start Code: Broadcast data of Sector's Indicators Data
                go(function () use ($objDbPool, $dbFacade, $request, $websocketserver, $topics) {
                    $this->processSectorsTopics($topics, $dbFacade, $objDbPool, $websocketserver, $request);
                });
                // -------- End Code for Refinitive topics broadcasting ----------- //

                // -------- Start Code: Broadcast data of Refinitiv Market's Historical Indicators ---------- //
                go(function () use ($objDbPool, $dbFacade, $request, $websocketserver, $topics) {
                    $this->processMarketHistoricalTopics($topics, $dbFacade, $objDbPool, $websocketserver, $request);
                });
                // -------- End Code: Broadcast data of Refinitiv Market's Historical Indicators ----------- //

                // Code here for other topics broadcasting -----------

                // End Code: Broadcast data of Ref Company's Indicators Data

                unset($subscriptionManager);
            }
        });


        // This callback will be used in callback for onMessage event. next
        // $respond = function($timerId, $webSocketServer, $frame, $sw_websocket_controller) {
        //     if ($webSocketServer->isEstablished($frame->fd)) { // if the user / fd is connected then push else clear timer.
        //         if ($frame->data) { // when a new message arrives from connected client with some data in it
        //             $bl_response = $sw_websocket_controller->handle();
        //             $frame->data = false;
        //         } else {
        //             $bl_response = 1;
        //         }

        //         $webSocketServer->push($frame->fd,
        //             json_encode($bl_response),
        //             WEBSOCKET_OPCODE_TEXT,
        //             SWOOLE_WEBSOCKET_FLAG_FIN); // SWOOLE_WEBSOCKET_FLAG_FIN OR OpenSwoole\WebSocket\Server::WEBSOCKET_FLAG_FIN

        //     } else {
        //         echo "Inside Event's Callback: Clearing Timer ".$timerId.PHP_EOL;
        //         swTimer::clear($timerId);
        //     }
        // };

        $this->server->on('message', function($webSocketServer, $frame)  {
            $cmd = explode(" ", trim($frame->data));
            $cmd_len = count($cmd);

            $closeFrameClass = swCloseFrame::class;
            if ($frame === '') {
                $webSocketServer->close();
            } else if ($frame === false) {
                echo 'errorCode: ' . swoole_last_error() . "\n";
                $webSocketServer->close();
            } else if ( ($cmd_len==1 && $cmd[0] == 'close') || get_class($frame) === $closeFrameClass || $frame->opcode == 0x08) {
                echo "Close frame received: Code {$frame->code} Reason {$frame->reason}\n";
                $webSocketServer->disconnect($frame->fd, SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'Client Disconnected');
            } else if ($frame->opcode === WEBSOCKET_OPCODE_PING) { // WEBSOCKET_OPCODE_PING is 0x09
                echo "Ping frame received: Code {$frame->opcode}\n";
                // Reply with Pong frame
                $pongFrame = (($this->extension=1) ? new swFrame() : new oswFrame());
                $pongFrame->opcode = WEBSOCKET_OPCODE_PONG;

                if ($webSocketServer->isEstablished($frame->fd)) {
                    $webSocketServer->push($frame->fd, $pongFrame);
                }
            } else {
                $mainCommand = strtolower($cmd[0]);

                $isPrivilegedFd = false;
                if (isset($webSocketServer->privilegedFds) && in_array($frame->fd, $webSocketServer->privilegedFds)) {
                    $isPrivilegedFd = true;
                }

                // Privileged FD Cases/Commands
                if ($isPrivilegedFd) {
                    switch ($mainCommand) {
                        case 'shutdown':
                            // Setting the Shutdown Flag to True in Swoole Table
                            $stopCodeTable = SwooleTableFactory::getTable('worker_stop_code');
                            $stopCodeTable->set(1, ['is_shutting_down' => 1]);

                            // Force shutdown the server if it is not shutdown gracefully
                            // Shutdown method returns true on success: Reference https://wiki.swoole.com/en/#/server/methods?id=shutdown
                            $shutdownRes = $webSocketServer->shutdown();

                            if (!$shutdownRes) {
                                // If the server.pid file exists, kill the process by its PID; otherwise, kill processes listening on the server port.
                                if (file_exists('server.pid')) {
                                    exec('cd ' . __DIR__ . ' && kill -9 `cat server.pid` 2>&1 1> /dev/null && rm -f server.pid');
                                } else {
                                    exec('cd ' . __DIR__ . ' && kill -SIGKILL $(lsof -t -i:' . $this->port . ') 2>&1 1> /dev/null');
                                }
                            }

                            break;

                        case 'reload-code':
                            swTimer::clearAll();
                            //                    if ($this->swoole_ext == 1) { // for Swoole
                            echo PHP_EOL . 'In Reload-Code: Clearing All Swoole-based Timers' . PHP_EOL;
                            //                    } else { // for openSwoole
                            //                        echo PHP_EOL.'In Reload-Code: Clearing All OpenSwoole-based Timers'.PHP_EOL;
                            //                    }
                            echo "Reloading Code Changes (by Reloading All Workers)" . PHP_EOL;

                            $reloadStatus = $webSocketServer->reload();
                            echo (($reloadStatus === true) ? PHP_EOL . 'Code Reloaded' . PHP_EOL  :  PHP_EOL . 'Code Not Reloaded') . PHP_EOL;

                            break;

                        case 'get-server-params':

                            $server_params = [
                                'ip' => $this->ip,
                                'port' => $this->port,
                                'serverMode' => $this->serverMode,
                                'serverProtocol' => $this->serverProtocol,
                            ];

                            if ($webSocketServer->isEstablished($frame->fd)) {
                                $webSocketServer->push($frame->fd, json_encode($server_params));
                            }

                            break;

                        case 'get-server-stats':
                            $stats = $webSocketServer->stats() ?? [];

                            if ($webSocketServer->isEstablished($frame->fd)) {
                                $webSocketServer->push($frame->fd, json_encode($stats));
                            }
                            break;

                        default:
                            if ($webSocketServer->isEstablished($frame->fd)) {
                                $webSocketServer->push($frame->fd, 'Invalid command given');
                            }
                    }
                }
                // Non Priviliged FD Cases/Commands
                else {
                    $frameData = json_decode($frame->data, true) ?? [];

                    if (array_key_exists('command', $frameData)) {
                        switch ($frameData['command']) {
                            case 'boiler-http-client':
                                $service = new \App\Services\HttpClientTestService($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id]);
                                $service->handle();
                                break;
                            case 'boiler-swoole-table':
                                $service = new \App\Services\SwooleTableTestService($webSocketServer, $frame);
                                $service->newHandle();
                                break;
                            case 'get-news':
                                $service = new NewsWebsocketService($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id], $frameData['request']);
                                $service->handle();
                                break;
                            case 'get-news-detail':
                                $subscriptionManager = new SubscriptionManager();
                                $service = new \App\Services\NewsDetailWebsocketService($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id], $frameData['request']);
                                $service->handle();
                                break;
                            case 'users':
                                $table = SwooleTableFactory::getTable('users');
                                if (isset($frameData['add_data'])) {
                                    // Add Data to Table
                                    $table->set($table->count(), $frameData['add_data']);

                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $webSocketServer->push($frame->fd, "Data Added");
                                    }
                                } else {
                                    // Fetch all the records saved in table
                                    $selector = new \Crust\SwooleDb\Selector\TableSelector('users');
                                    $records = $selector->execute();

                                    foreach ($records as $record) {
                                        $data = [
                                            'id' => $record['users']->getValue('id'),
                                            'name' => $record['users']->getValue('name'),
                                            'email' => $record['users']->getValue('email'),
                                        ];

                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, json_encode($data));
                                        }
                                    }
                                }
                                break;
                            case 'frontend-broadcasting-eg':
                                try {
                                    // You can get the list of registered services using get_registered_services() method
                                    $registeredServices = $this->serviceContainer->get_registered_services();

                                    var_dump($registeredServices);

                                    // Create a Service Using Default Factory
                                    $serviceContainer = $this->serviceContainer;


                                    // Following code demonstrate how we can get the service instance using our custom factory in invokeable function
                                    // $frontendBraodcastingService = $serviceContainer('FrontendBroadcastingService', function($webSocketServer) {
                                    //     return new \App\Core\Services\FrontendBroadcastingService($webSocketServer);
                                    // }, $webSocketServer);

                                    // You can also get the instance of frontend broadcasting service by providing alias and constructor params to create_service_object()
                                    // In this sepecific example create_service_object will use the factory FrontendBroadcastingFactory as it is configured as default factory for FrontendBroadcastingService
                                    $frontendBraodcastingService = $serviceContainer->create_service_object('FrontendBroadcastingService', $webSocketServer);

                                    // ----------- Code related to frontend-broadcasting-eg command ----------------------
                                    $message = 'From Frontend command | Tiggered by worker: ' . $webSocketServer->worker_id;
                                    // Here $message is the data to be broadcasted. (Can be string or an array)
                                    $frontendBraodcastingService($message);

                                    // Following Message will be broadcasted only to the FDs of current worker (Example usecase of callback)
                                    $message = 'For FDs of Worker:' . $webSocketServer->worker_id . ' only';
                                    $frontendBraodcastingService($message, function ($server, $msg) {
                                        foreach ($server->fds as $fd) {
                                            $server->push($fd, $msg);
                                        }
                                    });

                                    // Following code will also work due to custom autoload
                                    $fbs = new \App\Core\Services\FrontendBroadcastingService($webSocketServer);
                                    $fbs('Hello World');
                                } catch (\Throwable $e) {
                                    echo $e->getMessage() . PHP_EOL;
                                    echo $e->getFile() . PHP_EOL;
                                    echo $e->getLine() . PHP_EOL;
                                    echo $e->getCode() . PHP_EOL;
                                    var_dump($e->getTrace());
                                }

                                break;
                            case 'get-fds':
                                $message = 'Worker ID: ' . $webSocketServer->worker_id . ' | FDs: ' . implode(',', $webSocketServer->fds);
                                $webSocketServer->push($frame->fd, $message);
                                break;
                            case 'get-fds-of-topic':
                                $topic = isset($frameData['topic']) ? trim($frameData['topic']) : "";
                                if (empty($topic)) {
                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $errMsg = ['message' => 'Topic is required.', 'status_code' => ResponseStatusCode::UNSUPPORTED_PAYLOAD->value];
                                        $webSocketServer->push($frame->fd, json_encode($errMsg));
                                    }

                                    break;
                                }

                                $subscriptionManager = new SubscriptionManager();
                                $data = $subscriptionManager->getFdsOfTopic($topic);
                                unset($subscriptionManager);

                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, json_encode($data));
                                }
                                break;
                            case 'get-topics-of-fd':
                                // Get the Provided FD, or current fd as default
                                $fd = isset($frameData['fd']) ? intval($frameData['fd']) : $frame->fd;
                                $subscriptionManager = new SubscriptionManager();
                                $subscribedTopics = $subscriptionManager->getTopicsOfFD($fd);

                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, json_encode($subscribedTopics));
                                }
                                break;
                            case 'get-subscription-manager-data':
                                // This command will return all the data of Subscription Manager (For Framework Testing)
                                $subscriptionManager = new SubscriptionManager();
                                $data = $subscriptionManager->getAllSubscriptionData();

                                unset($subscriptionManager);

                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, json_encode($data));
                                }
                                break;
                            case 'get-workers-subscription-manager-data':
                                // This command will return all the data of Subscription Manager (For Framework Testing)
                                $subscriptionManager = new SubscriptionManager();
                                $data = $subscriptionManager->getWorkerScopeData();
                                unset($subscriptionManager);

                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, json_encode($data));
                                }
                                break;
                            case 'manage-topic-subcription':
                                // Validate the presence of 'subscribe' and 'unsubscribe' keys and ensure they are arrays
                                if (
                                    !isset($frameData['subscribe'], $frameData['unsubscribe']) ||
                                    !is_array($frameData['subscribe']) ||
                                    !is_array($frameData['unsubscribe'])
                                ) {
                                    $webSocketServer->push($frame->fd, 'Error: Both subscribe and unsubscribe keys are required and must be arrays.');
                                    break;
                                }

                                $subscriptionManager = new SubscriptionManager();

                                // Manage Subscriptions
                                $results = $subscriptionManager->manageSubscriptions($frame->fd, $frameData['subscribe'], $frameData['unsubscribe'], $webSocketServer->worker_id);

                                // Broadcast the results of Subscription
                                $webSocketServer->push($frame->fd, json_encode($results));

                                // -------- Start: Broadcast the newly subscribed topics ----------- //
                                $topicsRequested = [...$results['already_subscribed'], ...$results['subscribed']];

                                // If no topics are subcribed, exit early to prevent further execution of the algorithm.
                                if (empty($topicsRequested)) {
                                    return;
                                }

                                $objDbPool =  $this->dbConnectionPools[$webSocketServer->worker_id][config('app_config.swoole_pg_db_key')];
                                $dbFacade = new DbFacade();

                                // Broadcast the News data (First page, without filters, just KSA) when FD subscribe the news Topic
                                if (in_array('news', $topicsRequested)) {
                                    go(function () use ($webSocketServer, $frame) {
                                        $newsFilters = [
                                            'country' => 'KSA',
                                            'page' => 1
                                        ];

                                        $service = new NewsWebsocketService($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id], $newsFilters, null, false);
                                        $service->handle();
                                    });
                                }

                                // -------- Start Code for Refinitive topics broadcasting ----------- //
                                go(function () use ($objDbPool, $dbFacade, $topicsRequested, $webSocketServer, $frame) {
                                    $this->processCompaniesTopics($topicsRequested, $dbFacade, $objDbPool, $webSocketServer, $frame);
                                });
                                // -------- End Code for Refinitive topics broadcasting ----------- //

                                // -------- Start Code: Broadcast data of Market Overview --------- //
                                go(function () use ($topicsRequested, $frame, $webSocketServer) {
                                    $allMarketTopics = explode(',', config('ref_config.ref_market_overview'));
                                    $marketTopics = array_intersect($topicsRequested, $allMarketTopics);
                                    if (!empty($marketTopics)) {
                                        $this->processTopicsRowwise(topics: $marketTopics, tableName: 'markets_overview', frame: $frame, webSocketServer: $webSocketServer);
                                    }
                                });
                                // -------- End Code for Market Overview topics broadcasting ----------- //

                                // -------- Start Code: Broadcast data of Market's Indicators ---------- //
                                go(function () use ($objDbPool, $dbFacade, $topicsRequested, $webSocketServer, $frame) {
                                    $this->processMarketTopics($topicsRequested, $dbFacade, $objDbPool, $webSocketServer, $frame);
                                });
                                // -------- End Code: Broadcast data of Market's Indicators ----------- //

                                // -------- Start Code for Refinitiv Sector's topics broadcasting ----------- //
                                go(function () use ($objDbPool, $dbFacade, $topicsRequested, $webSocketServer, $frame) {
                                    $this->processSectorsTopics($topicsRequested, $dbFacade, $objDbPool, $webSocketServer, $frame);
                                });
                                // -------- End Code for Refinitiv Sector's topics broadcasting ----------- //

                                // -------- Start Code: Broadcast data of Refinitiv Market's Historical Indicators ---------- //
                                go(function () use ($objDbPool, $dbFacade, $topicsRequested, $webSocketServer, $frame) {
                                    $this->processMarketHistoricalTopics($topicsRequested, $dbFacade, $objDbPool, $webSocketServer, $frame);
                                });
                                // -------- End Code: Broadcast data of Refinitiv Market's Historical Indicators ----------- //

                                // Code here for other topics broadcasting -----------

                                // -------- End: Broadcast the newly subscribed topics ----------- //
                                unset($subscriptionManager);

                                break;
                            case 'get-companies-ref-data':
                                // Validate the presence of 'subscribe' and 'unsubscribe' keys and ensure they are arrays
                                $indicators = isset($frameData['indicators']) && is_array($frameData['indicators']) ? $frameData['indicators'] : null;

                                if (!$indicators) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide indicators for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $market_ric = isset($frameData['market_ric']) && !empty($frameData['market_ric']) ? strtolower($frameData['market_ric']) : null;

                                if (!$market_ric) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide market_ric for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $companySwooleTableName = $market_ric . '_companies_indicators';

                                if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide correct market name', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                               // Get all Indicators of Ref Company Data
                                $allRefCompanyDataTopics = array_map('strtolower', explode(',', config('ref_config.ref_fields')));
                                // Get all Indicators of SP Company Data
                                $allRefCompanyDataTopics = array_merge($allRefCompanyDataTopics, array_map('strtolower', explode(',', config('spg_config.sp_fields'))));
                                // Get all drived Indicators of SP Company Data
                                $allRefCompanyDataTopics = array_merge($allRefCompanyDataTopics, array_map('strtolower', explode(',', config('spg_config.sp_drived_fields'))));

                                $finalIndicators = array_intersect($indicators, $allRefCompanyDataTopics);

                                if (count($indicators) != count($finalIndicators)) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide correct indicators', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                // Fetch the Data of provided Companies Indicators
                                $data = $this->getCompaniesIndicatorsTopicsData($finalIndicators, $companySwooleTableName, false);
                                $data = ['command' => $frameData['command']] + $data;
                                $data['status_code'] = ResponseStatusCode::OK->value;

                                $dataStructure = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                if ($dataStructure == false) {
                                    output("JSON encoding error for Command (" . $$frameData['command'] . ") : " . json_last_error_msg());
                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $webSocketServer->push($frame->fd, json_encode(['message' => 'Something went wrong while encoding data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                    }
                                    break;
                                }

                                // Push Data to FD
                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, $dataStructure);
                                }

                                // ============================== Start code: to send company indicators topic wise ========================= //
                                 // Fetch the Data of provided Companies Indicators
                                //  $indicatorsData = $this->getCompaniesIndicatorsTopicsData($finalIndicators, $companySwooleTableName, $market_ric . '_');

                                //  // Push Data to FD
                                //  if ($webSocketServer->isEstablished($frame->fd)) {
                                //      foreach ($indicatorsData as $key => $indicatorData) {
                                //          // Format data to be sent in the Frame
                                //          $indicatorFrameData[$key] = $indicatorData;
                                //          $indicatorFrameData['job_runs_at'] = getJobRunAt($companySwooleTableName);

                                //          $indicatorJsonData = json_encode($indicatorFrameData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                //          unset($indicatorFrameData);

                                //          // Log error in-case of failing to json_encode, broadcast otherwise
                                //          if ($indicatorJsonData == false) {
                                //              output("JSON encoding error: " . json_last_error_msg());
                                //          } else {
                                //              $webSocketServer->push(
                                //                  $frame->fd,
                                //                  $indicatorJsonData
                                //              );
                                //          }
                                //      }
                                //  }
                                // ============================== End code: to send company indicators topic wise ========================= //

                                unset($dataStructure);
                                break;
                            case "get-companies-indicators-data":
                                // Validate the presence of 'indicators' and 'companies' keys and ensure they are arrays
                                $indicators = isset($frameData['indicators']) && is_array($frameData['indicators']) ? $frameData['indicators'] : null;

                                if (!$indicators) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide indicators for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $companies = isset($frameData['companies']) && is_array($frameData['companies']) ? $frameData['companies'] : null;

                                if (!$companies) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide company(s) for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $tasiCompaniesIndicatorsTable = SwooleTableFactory::getTable('tasi_companies_indicators', true);
                                $nomucCompaniesIndicatorsTable = SwooleTableFactory::getTable('nomuc_companies_indicators', true);

                                if (!$tasiCompaniesIndicatorsTable || !$nomucCompaniesIndicatorsTable) {
                                    // Log the error as it could be in-case of Swoole Tables name are changed.
                                    output(LogMessages::MISSING_TASI_OR_NOMUC_INDICATORS_SWOOLE_TABLE);
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Tasi or Nomuc companies data unavailable', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                // Fetch the companies indicators data and format according to frontend.
                                $finalData = [];

                                foreach ($companies as $company) {
                                    $companyId = $company['company_id'] ?? $company;

                                    // If the market ric is Provided then get data from that market otherwise search both markets
                                    if (isset($company['market_ric']) && isset($company['company_id'])) {
                                        $companyIndicators = $company['market_ric'] == 'tasi' ? $tasiCompaniesIndicatorsTable->get($company['company_id']) : $nomucCompaniesIndicatorsTable->get($company['company_id']);
                                    }
                                    else {
                                        $companyIndicators = $tasiCompaniesIndicatorsTable->get($company);
                                        if (empty($companyIndicators)) {
                                            $companyIndicators = $nomucCompaniesIndicatorsTable->get($company);
                                        }
                                    }

                                    $data = [];

                                    // If the provided company ID or indicator doesn't exist, set the indicators to null for that company
                                    foreach ($indicators as $indicator) {
                                        $data[$indicator] = $companyIndicators[$indicator] ?? null;
                                    }

                                    $finalData[] = [
                                        'company_id' => $companyId,
                                        'data' => $data,
                                    ];
                                }

                                $dataStructure = json_encode([
                                    'command' => $frameData['command'],
                                    'companies_indicators_data' => $finalData,
                                    'status_code' => ResponseStatusCode::OK->value,
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                if ($dataStructure == false) {
                                    output("JSON encoding error for Command (" . $$frameData['command'] . ") : " . json_last_error_msg());
                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $webSocketServer->push($frame->fd, json_encode(['message' => 'Something went wrong while encoding data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                    }
                                    break;
                                }

                                // Push Data to FD
                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, $dataStructure);
                                }

                                unset($dataStructure);
                                break;
                            case "get-markets-indicators-data":
                                // Validate the presence of 'indicators' and 'markets' keys and ensure they are arrays
                                $indicators = isset($frameData['indicators']) && is_array($frameData['indicators']) ? $frameData['indicators'] : null;

                                if (!$indicators) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide indicators for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $markets = isset($frameData['markets']) && is_array($frameData['markets']) ? $frameData['markets'] : null;

                                if (!$markets) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide markets(s) for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                // Fetch the Data
                                try {
                                    $indicators[] = 'market_id';
                                    $marketsData = SwooleTableFactory::getSwooleTableData(tableName: 'markets_indicators', selectColumns: $indicators, retainOriginalKeys: true);

                                    if (!$marketsData) {
                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, json_encode(['message' => 'Market indicators data unavailable', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                        }
                                        break;
                                    }

                                    // Fetch the markets indicators data and format according to frontend.
                                    $finalData = [];

                                    foreach ($markets as $m) {
                                        $tableMarketKey = strtoupper($m);
                                        if (isset($marketsData[$tableMarketKey])) {
                                            $data['market_ric'] = $m;
                                            $data['market_id'] = $marketsData[$tableMarketKey]['market_id'];
                                            $data['data'] = $marketsData[$tableMarketKey];
                                            unset($data['data']['market_id']);
                                            $finalData[] = $data;
                                        } else {
                                            if ($webSocketServer->isEstablished($frame->fd)) {
                                                $webSocketServer->push($frame->fd, json_encode(['message' => $m . ' data unavailable', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                            }
                                        }
                                    }

                                    // Stop further execution of case/command if there is no final data
                                    if (empty($finalData)) {
                                        break;
                                    }

                                    $dataStructure = json_encode([
                                        'command' => $frameData['command'],
                                        'command_data' => $finalData,
                                        'status_code' => ResponseStatusCode::OK->value,
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                    if ($dataStructure == false) {
                                        output("JSON encoding error for Command (" . $$frameData['command'] . ") : " . json_last_error_msg());
                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, json_encode(['message' => 'Something went wrong while encoding data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                        }
                                        break;
                                    }

                                    // Push Data to FD
                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $webSocketServer->push($frame->fd, $dataStructure);
                                    }

                                    unset($finalData);
                                    unset($dataStructure);
                                    break;
                                } catch (\Throwable $e) {
                                    output($e);
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Market indicators data unavailable', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                    break;
                                }
                            case "get-indicators-history":
                                $objDbPool =  $this->dbConnectionPools[$webSocketServer->worker_id][config('app_config.swoole_pg_db_key')];
                                $dbFacade = new DbFacade();
                                $getRefinitivIndicatorsHistory = new FetchRefinitivIndicatorHistory($webSocketServer, $objDbPool, $dbFacade);
                                $getRefinitivIndicatorsHistory->handle($frame, $frameData);
                                unset($getRefinitivIndicatorsHistory);
                                unset($dbFacade);
                                break;
                            case "get-sectors-indicators-data":
                                // Validate the presence of 'indicators' and 'sectors' keys and ensure they are arrays
                                $indicators = isset($frameData['indicators']) && is_array($frameData['indicators']) ? $frameData['indicators'] : null;

                                if (!$indicators) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide indicators for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                $sectors = isset($frameData['sectors']) && is_array($frameData['sectors']) ? $frameData['sectors'] : null;

                                if (!$sectors) {
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Please provide sectors(s) for which you want to get data', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                    break;
                                }

                                // Fetch the Data
                                try {
                                    $indicators[] = 'sector_info';
                                    $sectorsData = SwooleTableFactory::getSwooleTableData(tableName: 'sectors_indicators', selectColumns: $indicators, jsonDecodeColumns:['sector_info'], retainOriginalKeys: true);

                                    if (!$sectorsData) {
                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, json_encode(['message' => 'Sector(s) indicators data unavailable', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                        }
                                        break;
                                    }

                                    // Fetch the sectors indicators data and format according to frontend.
                                    $finalData = [];

                                    foreach ($sectors as $s) {
                                        $tableSectorKey = strtoupper($s);
                                        if (isset($sectorsData[$tableSectorKey])) {
                                            $data['sector_id'] = $s;
                                            $data['sector_ric'] = $sectorsData[$tableSectorKey]['sector_info']['ric'];
                                            $data['data'] = $sectorsData[$tableSectorKey];
                                            unset($data['data']['sector_info']);
                                            $finalData[] = $data;
                                        } else {
                                            if ($webSocketServer->isEstablished($frame->fd)) {
                                                $webSocketServer->push($frame->fd, json_encode(['message' => 'Sectors ID (' . $s . ') data unavailable', 'status_code' => ResponseStatusCode::UNPROCESSABLE_CONTENT->value]));
                                            }
                                        }
                                    }

                                    // Stop further execution of case/command if there is no final data
                                    if (empty($finalData)) {
                                        break;
                                    }

                                    $dataStructure = json_encode([
                                        'command' => $frameData['command'],
                                        'command_data' => $finalData,
                                        'status_code' => ResponseStatusCode::OK->value,
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                    if ($dataStructure == false) {
                                        output("JSON encoding error for Command (" . $$frameData['command'] . ") : " . json_last_error_msg());
                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, json_encode(['message' => 'Something went wrong while encoding data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                        }
                                        break;
                                    }

                                    // Push Data to FD
                                    if ($webSocketServer->isEstablished($frame->fd)) {
                                        $webSocketServer->push($frame->fd, $dataStructure);
                                    }

                                    unset($finalData);
                                    unset($dataStructure);
                                    break;
                                } catch (\Throwable $e) {
                                    output($e);
                                    $webSocketServer->push($frame->fd, json_encode(['message' => 'Sector(s) indicators data unavailable', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                    break;
                                }
                            case "get-holidays":
                                    try {
                                        $objDbPool =  $this->dbConnectionPools[$webSocketServer->worker_id][config('app_config.swoole_pg_db_key')];
                                        $dbFacade = new DbFacade();

                                        $dbQuery = "SELECT name, from_date, to_date FROM holidays;";
                                        $holidayResult = executeDbFacadeQueryWithChannel($dbQuery, $objDbPool, $dbFacade);

                                        $dataStructure = json_encode([
                                            'command' => $frameData['command'],
                                            'data' => $holidayResult,
                                            'status_code' => ResponseStatusCode::OK->value,
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                        if ($dataStructure == false) {
                                            output("JSON encoding error for Command (" . $$frameData['command'] . ") : " . json_last_error_msg());
                                            if ($webSocketServer->isEstablished($frame->fd)) {
                                                $webSocketServer->push($frame->fd, json_encode(['message' => 'Something went wrong while encoding data', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                            }
                                            break;
                                        }

                                        if ($webSocketServer->isEstablished($frame->fd)) {
                                            $webSocketServer->push($frame->fd, $dataStructure);
                                        }

                                        unset($dbFacade);
                                        break;
                                    } catch (\Throwable $e) {
                                        output($e);
                                        $webSocketServer->push($frame->fd, json_encode(['message' => 'Failed to retrieve holidays from the database.', 'status_code' => ResponseStatusCode::INTERNAL_SERVER_ERROR->value]));
                                        break;
                                    }
                            case "sync-company-info-to-indicators-tables":
                                // Validate the presence of 'indicators' and 'markets' keys and ensure they are arrays
                                $companyInfo = isset($frameData['companyInfo']) && is_array($frameData['companyInfo']) ? $frameData['companyInfo'] : null;

                                if (!$companyInfo) {
                                    output('No company information provided. Please provide the company details you want to update.');
                                    break;
                                }

                                $companySwooleTableName = $companyInfo['parent_id'] == 1 ? 'tasi_companies_indicators' : 'nomuc_companies_indicators';
                                $company = $this->getTopicsFromSwooleTableRowwise(config('common_attributes_config'), $companySwooleTableName, $companyInfo['id'], 'company_info');

                                // Update the corresponding record in the Swoole-related table if company data is available
                                if (!empty($company)) {
                                    output('This company info is going to be updated:');
                                    output($company);

                                    $company['sp_comp_id'] = $companyInfo['sp_comp_id'];
                                    $company['isin_code'] = $companyInfo['isin_code'];
                                    $company['ric'] = $companyInfo['ric'];
                                    $company['company_info']->ric = $companyInfo['ric'];
                                    $company['company_info']->en_long_name = $companyInfo['name'];
                                    $company['company_info']->sp_comp_id = $companyInfo['sp_comp_id'];
                                    $company['company_info']->en_short_name = $companyInfo['short_name'];
                                    $company['company_info']->symbol = $companyInfo['symbol'];
                                    $company['company_info']->isin_code = $companyInfo['isin_code'];
                                    $company['company_info']->ar_long_name = $companyInfo['arabic_name'];
                                    $company['company_info']->ar_short_name = $companyInfo['arabic_short_name'];
                                    $company['company_info']->logo = $companyInfo['logo'];

                                    $company['company_info'] = json_encode($company['company_info'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                    $table = SwooleTableFactory::getTable($companySwooleTableName);
                                    $table->set($company['company_id'], $company);
                                } else {
                                    output('Could not found the Company in Swoole table to update:');
                                    output($company);
                                }

                                break;
                                case "delete-company-record-from-indicators-tables":
                                    // Validate the presence of 'indicators' and 'markets' keys and ensure they are arrays
                                    $companyInfo = isset($frameData['companyInfo']) && is_array($frameData['companyInfo']) ? $frameData['companyInfo'] : null;

                                    if (!$companyInfo) {
                                        output('No company information provided. Please provide the company details you want to delete.');
                                        break;
                                    }

                                    $companySwooleTableName = $companyInfo['parent_id'] == 1 ? 'tasi_companies_indicators' : 'nomuc_companies_indicators';
                                    $company = $this->getTopicsFromSwooleTableRowwise(config('common_attributes_config'), $companySwooleTableName, $companyInfo['id'], 'company_info');

                                    // Update the corresponding record in the Swoole-related table if company data is available
                                    if (!empty($company)) {
                                        output('This company record is going to be deleted:');
                                        output($company);

                                        // Get the Swoole table instance by name
                                        $table = Bootstrap\SwooleTableFactory::getTable($companySwooleTableName, true);
                                        // Delete a company's indicators record
                                        $table->del($companyInfo['id']);
                                    } else {
                                        output('Could not found the Company in Swoole table to delete:');
                                        output($company);
                                    }

                                    break;
                            default:
                                if ($webSocketServer->isEstablished($frame->fd)) {
                                    $webSocketServer->push($frame->fd, 'Invalid command given');
                                }
                        }
                    } else {
                        if ($webSocketServer->isEstablished($frame->fd)) {
                            $webSocketServer->push($frame->fd, 'Invalid Command/Message Format');
                        }

                        // Default Code
                        // include_once __DIR__ . '/controllers/WebSocketController.php';

                        // $app_type_database_driven = config('app_config.app_type_database_driven');
                        // if ($app_type_database_driven) {
                        //     $sw_websocket_controller = new WebSocketController($webSocketServer, $frame, $this->dbConnectionPools[$webSocketServer->worker_id]);
                        // } else {
                        //     $sw_websocket_controller = new WebSocketController($webSocketServer, $frame);
                        // }

                        // $timerTime = config('app_config.swoole_timer_time1');
                        // $timerId = swTimer::tick($timerTime, $respond, $webSocketServer, $frame, $sw_websocket_controller);
                        // self::$fds[$frame->fd][$timerId] = 1;
                    }
                }
            }
        });

        $this->server->on('close', function($server, $fd, $reactorId) {
            echo PHP_EOL."client {$fd} closed in ReactorId:{$reactorId}".PHP_EOL;

            // if (isset(self::$fds[$fd])) {
            //     echo PHP_EOL.'On Close: Clearing Swoole-based Timers for Connection-'.$fd.PHP_EOL;
            //     $fd_timers = self::$fds[$fd];
            //     foreach ($fd_timers as $fd_timer=>$value){
            //         if (swTimer::exists($fd_timer)) {
            //             echo PHP_EOL."In Connection-Close: clearing timer: ".$fd_timer.PHP_EOL;
            //             swTimer::clear($fd_timer);
            //         }
            //     }
            // }
            // unset(self::$fds[$fd]);

            // Unsubscribe the FD from its subscribed topics
            $subscriptionManager = new SubscriptionManager();
            $subscriptionManager->removeSubscriptionsForFD($fd);
            unset($subscriptionManager);

            // delete fd from scope
            unset($server->fds[$fd]);
            unset($server->privilegedFds[$fd]);
        });

        $this->server->on('disconnect', function($server, int $fd) {
            echo "connection disconnect: {$fd}\n";

            // if (isset(self::$fds[$fd])) {
            //     echo PHP_EOL.'On Disconnect: Clearing Swoole-based Timers for Connection-'.$fd.PHP_EOL;
            //     $fd_timers = self::$fds[$fd];
            //     foreach ($fd_timers as $fd_timer){
            //         if (swTimer::exists($fd_timer)) {
            //             echo PHP_EOL."In Disconnect: clearing timer: ".$fd_timer.PHP_EOL;
            //             swTimer::clear($fd_timer);
            //         }
            //     }
            // }
            // unset(self::$fds[$fd]);

            // Unsubscribe the FD from its subscribed topics
            $subscriptionManager = new SubscriptionManager();
            $subscriptionManager->removeSubscriptionsForFD($fd);
            unset($subscriptionManager);

            // delete fd from scope
            unset($server->fds[$fd]);
            unset($server->privilegedFds[$fd]);
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

    public function getServerParams() {
       return [
           'ip' => $this->ip,
           'port' => $this->port,
           'serverMode' => $this->serverMode,
           'serverProtocol' => $this->serverProtocol,
       ];
    }

    /**
     * This function kills the custom user processes
     *
     * @param bool $onlyServiceContainer If you only want to kill the processes registered in service container. Otherwise it will kill
     * the processes of both ServiceContainer and "customProcessCallbacks" property
     * @return void
     */
    public function killCustomProcesses(bool $onlyServiceContainer = false)
    {
        // Get the service container instance
        $serviceContainer = ServiceContainer::get_instance();

        if ($onlyServiceContainer) {
            // Kill the proccess registered in ServiceContainer only
            $processesToKill = $serviceContainer->get_registered_processes();
        } else {
            // Get the registered processes from the Service Container and merge with this file's "customProcessCallbacks"
            $processesToKill = array_merge($this->customProcessCallbacks, $serviceContainer->get_registered_processes());
        }

        foreach ($processesToKill as $key => $callback) {
            $processPidFile = __DIR__ . '/process_pids/' . $key . '.pid';

            if (file_exists($processPidFile)) {
                $pid = intval(shell_exec('cat ' . $processPidFile));

                // Processes that do not have a timer or loop will exit automatically after completing their tasks.
                // Therefore, some processes might have already terminated before reaching this point
                // So here we need to check first if the process is running by passing signal_no param as 0, as per documentation
                // Doc: https://wiki.swoole.com/en/#/process/process?id=kill
                if (Process::kill($pid, 0)) {
                    Process::kill($pid, SIGTERM);
                }

            }
        }
    }

    /**
     * This function revokes the database connection pool
     *
     * @param  mixed $worker_id The worker ID
     * @return void
     */
    public function revokeDatabasePoolResources(int $worker_id)
    {
        $app_type_database_driven = config('app_config.app_type_database_driven');
        if ($app_type_database_driven) {
            if (isset($this->dbConnectionPools[$worker_id])) {
                $worker_dbConnectionPools = $this->dbConnectionPools[$worker_id];
                $mysqlPoolKey = makePoolKey($worker_id, 'mysql');
                $pgPoolKey = makePoolKey($worker_id, 'postgres');
                foreach ($worker_dbConnectionPools as $dbKey => $dbConnectionPool) {
                    if ($dbConnectionPool->pool_exist($pgPoolKey)) {
                        echo "Closing Connection Pool: " . $pgPoolKey . PHP_EOL;
                        // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                        $dbConnectionPool->closeConnectionPool($pgPoolKey);
                    }

                    if ($dbConnectionPool->pool_exist($mysqlPoolKey)) {
                        echo "Closing Connection Pool: " . $mysqlPoolKey . PHP_EOL;
                        // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                        $dbConnectionPool->closeConnectionPool($mysqlPoolKey);
                    }
                    unset($dbConnectionPool);
                }
                unset($this->dbConnectionPools[$worker_id]);
            }
        }
    }

    /**
     * This function revokes the Inotify Resources / File Change Event
     *
     * @param  int $worker_id
     * @return void
     */
    public function revokeInotifyResources(int $worker_id)
    {
        if ($worker_id == 0) {
            global $inotify_handles;
            global $watch_descriptors;
            if (isset($inotify_handles)) {
                foreach ($inotify_handles as $index => $inotify_handle) {
                    if (Swoole\Event::isset($inotify_handle)) {
                        @inotify_rm_watch($inotify_handle, $watch_descriptors[$index]);
                        Swoole\Event::del($inotify_handle);
                    }
                }
                unset($inotify_handles);
            }
        }
    }

    /**
     * Get the All Rows indicators data based on subscribed Topics of FD
     *
     * @param  mixed $topics The topics that FD has subscribed
     * @param  string $swooleTableName Used to retrieve topics
     * @param  array $commonAttributes Used to retrieve common topics
     * @param  array $jsonDecodeColumns Decoded columns
     *
     * @return mixed
     */
    public function getAllRowsIndicatorsTopicsData($topics, $swooleTableName,  $commonAttributes, $jsonDecodeColumns): mixed
    {
        // Fetch data from swoole table jobs_runs_at
        $jobRunsAtData = getJobRunAt($swooleTableName);

        $data = SwooleTableFactory::getSwooleTableData(tableName: $swooleTableName, selectColumns: array_merge($topics, $commonAttributes), jsonDecodeColumns:$jsonDecodeColumns, retainOriginalKeys: false);

        // Here we will check if the data is encoded without any error
        $dataJson = json_encode([
            $swooleTableName => $data,
            'job_runs_at' => $jobRunsAtData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($dataJson == false) {
            output("JSON encoding error: " . json_last_error_msg());
            return false;
        } else {
            return $dataJson;
        }
    }

    /**
     * Fetches specific topic values from a Swoole table row-wise.
     *
     * This function retrieves data from a Swoole table using a given key
     * and extracts the values for the specified topics.
     *
     * @param array  $topics An array of topic names to fetch.
     * @param string $tableName The name of the Swoole table.
     * @param string  $key The key used to retrieve data from the table.
     *
     * @return array An associative array containing the requested topic values.
     */
    public function getTopicsFromSwooleTableRowwise(array $topics, string $tableName, string $key, ?string $jsonDecodeColumn = null): array
    {
        $swooleTable = SwooleTableFactory::getTable($tableName, true);
        $swooleTableData = $swooleTable->get($key);

        if (!$swooleTableData) {
            return []; // Return empty array if key is not found
        }

        $data = [];
        foreach ($topics as  $topic) {
            $data[$topic] = $jsonDecodeColumn == $topic ? json_decode($swooleTableData[$topic]) : $swooleTableData[$topic];
        }

        return $data;
    }

    /**
     * Pushes data to the WebSocket client.
     *
     * @param object $frame WebSocket frame containing the client's FD information.
     * @param object $webSocketServer WebSocket server instance used to push data.
     * @param string $data The topic data or delta to be sent.
     * @return void
     */
    public function pushTopicToWebSocket(object $frame, object $webSocketServer, string $data): void
    {
        if ($webSocketServer->isEstablished($frame->fd)) {
            $webSocketServer->push($frame->fd, $data);
        }
    }

    /**
     * Processes topics row-wise and sends data via WebSocket.
     *
     * @param array $topics List of topics to process.
     * @param string $tableName The name of the Swoole table to fetch data from.
     * @param object $frame WebSocket frame containing the client's FD information.
     * @param object $webSocketServer WebSocket server instance used to push data.
     * @return void
     */
    public function processTopicsRowwise(array $topics, string $tableName, object $frame, object $webSocketServer)
    {
        $marketOverviewTable = SwooleTableFactory::getTable($tableName, true);

        foreach ($topics as  $topic) {

            $marketOverviewData =  $marketOverviewTable->get($topic);
            $dataJson = json_encode([
                $topic => $marketOverviewData,
            ]);

            if ($dataJson == false) {
                output("JSON encoding error: " . json_last_error_msg());
            } else {
                $this->pushTopicToWebSocket($frame, $webSocketServer, $dataJson);
            }
        }
    }

    /**
     * Check if any topic exists in the reference topics list.
     *
     * This function removes the prefix from each topic and checks if it exists
     * in the list of reference data topics.
     *
     * @param array $topics List of topics to check.
     * @param array $allDataTopics List of reference topics.
     * @param int   $arraySlicedIndexValue The index from which to slice the topic string.
     * @return bool Returns true if at least one topic exists, otherwise false.
     */
    private function isTopicExist(array $topics, array $allDataTopics, int $arraySlicedIndexValue = 1): bool
    {
        foreach ($topics as $topic) {
            $actualTopic = implode('_', array_slice(explode('_', $topic), $arraySlicedIndexValue));
            if (in_array($actualTopic, $allDataTopics, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieve all markets from the database.
     *
     * This function fetches the market IDs and their associated `refinitiv_universe` names.
     *
     * @param object $dbFacade Database facade instance to execute queries.
     * @param object $objDbPool Database connection pool.
     * @return array Returns an array of markets, where each market contains 'id' and 'refinitiv_universe'.
     */
    private function getMarkets(object $dbFacade, object $objDbPool): array
    {
        $dbQuery = "SELECT id, refinitiv_universe FROM markets";
        return $dbFacade->query($dbQuery, $objDbPool);
    }

    /**
     * Filter topics that belong to a specific market.
     *
     * This function removes the market name prefix from each topic and checks if it exists
     * in the list of topics.
     *
     * @param array $topics List of topics received.
     * @param string $prefix The prefix used to identify relevant topics.
     * @param array $allDataTopics List of topics.
     * @return array Returns a list of filtered topics belonging to the given market.
     */
    private function filterTopics(array $topics, string $prefix, array $allDataTopics): array
    {
        $filteredTopics = [];

        foreach ($topics as $topic) {
            if (str_starts_with($topic, $prefix)) {
                $actualTopic = substr($topic, strlen($prefix));
                if (in_array($actualTopic, $allDataTopics, true)) {
                    $filteredTopics[] = $actualTopic;
                }
            }
        }
        return $filteredTopics;
    }

    /**
     * Process company topics and push relevant data to WebSocket.
     *
     * This function filters company topics based on market names, retrieves relevant data,
     * and sends it via WebSocket if applicable.
     *
     * Steps:
     * 1. Retrieves all company topics.
     * 2. Checks if any provided topic exists in the company topics list.
     * 3. Fetches market data from the database.
     * 4. Iterates over each market:
     *    - Ensures `refinitiv_universe` is set.
     *    - Checks if a corresponding Swoole table exists.
     *    - Filters company topics based on the market.
     *    - Retrieves company indicators and pushes data to WebSocket.
     *
     * @param array $topics List of topics to process.
     * @param object $dbFacade Database facade instance for executing queries.
     * @param object $objDbPool Database connection pool.
     * @param object $websocketserver WebSocket server instance for pushing data.
     * @param object $request The WebSocket request object.
     * @return void
     */
    public function processCompaniesTopics(array $topics, object $dbFacade, object $objDbPool, object $websocketserver, object $request)
    {
        try {
            // Retrieve all company topics, including Refinitiv, SP, and SP-derived indicators, and merge them into a single array.
            $allRefCompanyDataTopics = array_merge(
                array_map('strtolower', explode(',', config('ref_config.ref_fields'))),
                array_map('strtolower', explode(',', config('ref_config.ref_fields'))),
                array_map('strtolower', explode(',', config('ref_config.ref_daywise_fields'))),
                array_map('strtolower', explode(',', config('spg_config.sp_fields'))),
                array_map('strtolower', explode(',', config('spg_config.sp_drived_fields')))
            );

            // Check if any of the provided topics exist in the list
            if (!$this->isTopicExist($topics, $allRefCompanyDataTopics)) {
                return;
            }

            // Fetch markets from the database
            $markets = $this->getMarkets($dbFacade, $objDbPool);

            // If no markets exist, return with a message
            if (empty($markets)) {
                output(LogMessages::NO_MARKET_IN_DB);
                return;
            }

            // Iterate over each market
            foreach ($markets as $market) {
                if (empty($market['refinitiv_universe'])) {
                    output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                    continue;
                }

                $marketName = strtolower($market['refinitiv_universe']);
                $companySwooleTableName = $marketName . '_companies_indicators';

                // Ensure the Swoole table exists for this market
                if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                    output(sprintf(LogMessages::CREATE_SWOOLE_TABLE, $marketName));
                    continue;
                }

                // Filter company topics based on the market
                $companyTopics = $this->filterTopics($topics, $marketName.'_', $allRefCompanyDataTopics);

                // If there are valid company topics, retrieve data and push to WebSocket
                if (!empty($companyTopics)) {
                    $dataJson = $this->getAllRowsIndicatorsTopicsData($companyTopics, $companySwooleTableName, config('common_attributes_config'), ['company_info']);
                    $this->pushTopicToWebSocket($request, $websocketserver, $dataJson);
                    unset($dataJson);

                    // ============================== Start code: to send company indicators topic wise ========================= //
                    // $indicatorsData = $this->getCompaniesIndicatorsTopicsData($companyTopics, $companySwooleTableName, $marketName);

                    // // Push Data to FD
                    // if ($websocketserver->isEstablished($request->fd)) {
                    //     foreach ($indicatorsData as $key => $indicatorData) {
                    //         // Format data to be sent in the Frame
                    //         $indicatorFrameData[$key] = $indicatorData;
                    //         $indicatorFrameData['job_runs_at'] = getJobRunAt($companySwooleTableName);

                    //         $indicatorJsonData = json_encode($indicatorFrameData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    //         unset($indicatorFrameData);

                    //         // Log error in-case of failing to json_encode, broadcast otherwise
                    //         if ($indicatorJsonData == false) {
                    //             output("JSON encoding error: " . json_last_error_msg());
                    //         } else {
                    //             $websocketserver->push(
                    //                 $request->fd,
                    //                 $indicatorJsonData
                    //             );
                    //         }
                    //     }
                    // }
                    // unset($indicatorsData);
                    // ============================== End code: to send indicators topic wise ========================= //
                }
            }
        } catch (Throwable $e) {
            output($e);
        }
    }

    /**
     * Process sector topics and push relevant data to WebSocket.
     *
     * This function filters sector topics based on market names, retrieves relevant data,
     * and sends it via WebSocket if applicable.
     *
     * Steps:
     * 1. Retrieves all sector topics.
     * 2. Checks if any provided topic exists in the sector topics list.
     * 3. Fetches market data from the database.
     * 4. Iterates over each market:
     *    - Ensures `refinitiv_universe` is set.
     *    - Checks if a corresponding Swoole table exists.
     *    - Filters sector topics based on the market.
     *    - Retrieves sector indicators and pushes data to WebSocket.
     *
     * @param array $topics List of topics to process.
     * @param object $dbFacade Database facade instance for executing queries.
     * @param object $objDbPool Database connection pool.
     * @param object $websocketserver WebSocket server instance for pushing data.
     * @param object $request The WebSocket request object.
     * @return void
     */
    public function processSectorsTopics(array $topics, object $dbFacade, object $objDbPool, object $websocketserver, object $request)
    {
        try {
            // Retrieve all sector topics, including Refinitiv, SP, and SP-derived indicators, and merge them into a single array.
            $allRefSectorDataTopics = array_map('strtolower', explode(',', config('ref_config.ref_fields')));

            // Check if any of the provided topics exist in the list
            if (!$this->isTopicExist($topics, $allRefSectorDataTopics)) {
                return;
            }

            $sectorSwooleTableName = 'sectors_indicators';

            // Filter sector topics based on the market
            $sectorTopics = $this->filterTopics($topics, 'sector_', $allRefSectorDataTopics);

            // If there are valid sector topics, retrieve data and push to WebSocket
            if (!empty($sectorTopics)) {
                $dataJson = $this->getAllRowsIndicatorsTopicsData($sectorTopics, $sectorSwooleTableName, explode(',',config('ref_config.ref_sector_common_attributes')), ['sector_info']);
                $this->pushTopicToWebSocket($request, $websocketserver, $dataJson);
                unset($dataJson);
            }
        } catch (Throwable $e) {
            output($e);
        }
    }

     /**
     * Process market topics and push relevant data to WebSocket.
     *
     * This function filters market topics based on market names, retrieves relevant data,
     * and sends it via WebSocket if applicable.
     *
     * Steps:
     * 1. Retrieves all market topics.
     * 2. Checks if any provided topic exists in the market topics list.
     * 3. Fetches market data from the database.
     * 4. Iterates over each market:
     *    - Ensures `refinitiv_universe` is set.
     *    - Checks if a corresponding Swoole table exists.
     *    - Filters market topics based on the market.
     *    - Retrieves market indicators and pushes data to WebSocket.
     *
     * @param array $topics List of topics to process.
     * @param object $dbFacade Database facade instance for executing queries.
     * @param object $objDbPool Database connection pool.
     * @param object $websocketserver WebSocket server instance for pushing data.
     * @param object $request The WebSocket request object.
     * @return void
     */
    public function processMarketTopics(array $topics, object $dbFacade, object $objDbPool, object $websocketserver, object $request)
    {
        try {
            // Retrieve all market topics, including Refinitiv into a single array.
            $allRefMarektDataTopics = array_map('strtolower', explode(',', config('ref_config.ref_fields')));

            // Check if any of the provided topics exist in the list
            if (!$this->isTopicExist($topics, $allRefMarektDataTopics, 2)) {
                return;
            }

            // Fetch markets from the database
            $markets = $this->getMarkets($dbFacade, $objDbPool);

            // If no markets exist, return with a message
            if (empty($markets)) {
                output(LogMessages::NO_MARKET_IN_DB);
                return;
            }

            $marketSwooleTableName = 'markets_indicators';
            // Iterate over each market
            foreach ($markets as $market) {
                if (empty($market['refinitiv_universe'])) {
                    output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                    continue;
                }

                $normalizedMarketName =  strtolower($market['refinitiv_universe']);
                $marketName = $normalizedMarketName.'_market';

                // Filter market topics based on the market
                $marketTopics = $this->filterTopics($topics, $marketName.'_', $allRefMarektDataTopics);

                // If there are valid market topics, retrieve data and push to WebSocket
                if (!empty($marketTopics)) {
                    // $dataJson = $this->getMarketsIndicatorsTopicsData($marketTopics, $marketSwooleTableName, explode(',',config('ref_config.ref_market_common_attributes')));
                    $topicsData = $this->getTopicsFromSwooleTableRowwise(array_merge($marketTopics, explode(',',config('ref_config.ref_market_common_attributes'))), $marketSwooleTableName, $market['refinitiv_universe'], 'market_info');

                    $jobRunsAtData = getJobRunAt($marketSwooleTableName);

                    $jsonData = json_encode([
                        $normalizedMarketName.'_'.$marketSwooleTableName => $topicsData,
                        'job_runs_at' => $jobRunsAtData,
                    ]);

                    if ($jsonData == false) {
                        output("JSON encoding error: " . json_last_error_msg());
                    } else {
                        $this->pushTopicToWebSocket($request, $websocketserver, $jsonData);
                    }
                    unset($dataJson);
                }
            }
        } catch (Throwable $e) {
            output($e);
        }
    }

    /**
     * Process market historical topics and push relevant data to WebSocket.
     *
     * This function filters market topics based on market names, retrieves relevant data,
     * and sends it via WebSocket if applicable.
     *
     * Steps:
     * 1. Retrieves all market topics.
     * 2. Checks if any provided topic exists in the market topics list.
     * 3. Fetches market data from the database.
     * 4. Iterates over each market:
     *    - Ensures `refinitiv_universe` is set.
     *    - Checks if a corresponding Swoole table exists.
     *    - Filters market topics based on the market.
     *    - Retrieves market indicators and pushes data to WebSocket.
     *
     * @param array $topics List of topics to process.
     * @param object $dbFacade Database facade instance for executing queries.
     * @param object $objDbPool Database connection pool.
     * @param object $websocketserver WebSocket server instance for pushing data.
     * @param object $request The WebSocket request object.
     * @return void
     */
    public function processMarketHistoricalTopics(array $topics, object $dbFacade, object $objDbPool, object $websocketserver, object $request)
    {
        try {
            // Retrieve all market topics, including Refinitiv into a single array.
            $allRefMarektDataTopics = array_map('strtolower', explode(',', config('ref_config.ref_historical_fields')));

            // Check if any of the provided topics exist in the list
            if (!$this->isTopicExist($topics, $allRefMarektDataTopics, 3)) {
                return;
            }

            // Fetch markets from the database
            $markets = $this->getMarkets($dbFacade, $objDbPool);

            // If no markets exist, return with a message
            if (empty($markets)) {
                output(LogMessages::NO_MARKET_IN_DB);
                return;
            }

            $marketSwooleTableName = 'markets_historical_indicators';

            // Iterate over each market
            foreach ($markets as $market) {
                if (empty($market['refinitiv_universe'])) {
                    output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                    continue;
                }

                $normalizedMarketName =  strtolower($market['refinitiv_universe']);
                $marketName = $normalizedMarketName . '_market_historical';

                // Filter market topics based on the market
                $marketTopics = $this->filterTopics($topics, $marketName . '_', $allRefMarektDataTopics);

                // If there are valid market topics, retrieve data and push to WebSocket
                if (!empty($marketTopics)) {
                    // Get the actual Swoole table instance by passing true in getTable function
                    $marketHistoricalTable = SwooleTableFactory::getTable($marketSwooleTableName, true);

                    $marketWiseTopicData = [];
                    // Get only specific market data
                    foreach ($marketHistoricalTable as $key => $row) {
                        if ($market['id'] == $row['market_id']) {
                            $row['market_info'] = json_decode($row['market_info'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $marketWiseTopicData[] =  array_intersect_key($row, array_flip(array_merge($marketTopics, explode(',', config('ref_config.ref_historical_market_common_attributes')))));
                        }
                    }

                    $jobRunsAtData = getJobRunAt($marketSwooleTableName);

                    $jsonData = json_encode([
                        $normalizedMarketName . '_' . $marketSwooleTableName => $marketWiseTopicData,
                        'job_runs_at' => $jobRunsAtData,
                    ]);

                    if ($jsonData == false) {
                        output("JSON encoding error: " . json_last_error_msg());
                    } else {
                        $this->pushTopicToWebSocket($request, $websocketserver, $jsonData);
                    }
                    unset($marketHistoricalTable);
                }
            }
        } catch (Throwable $e) {
            output($e);
        }
    }

    // ============================== Start code: to send company indicators topic wise ========================= //
    // /**
    //  * Get the Companies indicators data based on subscribed Topics of FD
    //  *
    //  * @param  mixed $companyTopics The company topics that FD has subscribed
    //  * @param  string $companySwooleTableName Used to retrieve company topics for a specific market
    //  * @param  string $marketName The name of the market
    //  * @return mixed
    //  */
    // public function getCompaniesIndicatorsTopicsData($companyTopics, $companySwooleTableName, $marketName): mixed
    // {
    //     $marketName = strtolower($marketName);

    //     // Get common attributes
    //     $commonAttributes = config('common_attributes_config');

    //     $data = SwooleTableFactory::getSwooleTableData(tableName: $companySwooleTableName, selectColumns: array_merge($companyTopics, $commonAttributes));

    //     $finalData = [];
    //     // Split the data/indicators into topic-wise
    //     foreach ($data as $entry) {
    //         foreach ($companyTopics as $topic) {
    //             $filteredEntry = $entry;

    //             $otherTopics = array_diff($companyTopics, [$topic]); // Get the other topics other than current one
    //             foreach ($filteredEntry as $key => $value) {
    //                 if (in_array($key, $otherTopics)) {
    //                     unset($filteredEntry[$key]);
    //                 }
    //             }

    //             $finalData[$marketName . $topic][] = $filteredEntry;
    //         }
    //     }

    //     return $finalData;
    // }
    // ============================== End code: to send company indicators topic wise ========================= //

     /**
     * Get the Companies indicators data based on subscribed Topics of FD
     *
     * @param  mixed $companyTopics The company topics that FD has subscribed
     * @param  string $companySwooleTableName Used to retrieve company topics for a specific market
     * @param  bool $returnJson Returns the data in Json Format
     * @return mixed
     */
    public function getCompaniesIndicatorsTopicsData($companyTopics, $companySwooleTableName, $returnJson = true): mixed
    {
        // Fetch data from swoole table jobs_runs_at
        $jobRunsAtData = getJobRunAt($companySwooleTableName);

        // Get common attributes
        $commonAttributes = config('common_attributes_config');

        $data = SwooleTableFactory::getSwooleTableData(tableName: $companySwooleTableName, selectColumns: array_merge($companyTopics, $commonAttributes), jsonDecodeColumns:['company_info'], retainOriginalKeys: false);

        if ($returnJson) {
            // Here we will check if the data is encoded without any error
            $dataJson = json_encode([
                $companySwooleTableName => $data,
                'job_runs_at' => $jobRunsAtData,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($dataJson == false) {
                output("JSON encoding error: " . json_last_error_msg());
                return false;
            } else {
                return $dataJson;
            }
        }
        else {
            return [
                $companySwooleTableName => $data,
                'job_runs_at' => $jobRunsAtData,
            ];
        }
    }

    /**
     * Remove the server.pid when server shutdown
     */
    // function removeServerPidFile() {
    //     if (file_exists('server.pid')){
    //         shell_exec('cd '.__DIR__.' && kill -15 `cat server.pid` 2>&1 1> /dev/null&'); //&& sudo rm -f server.pid
    //     } else {
    //         echo PHP_EOL.'server.pid file not found. Looks like server is not running already.'.PHP_EOL;
    //     }
    // }
}

#################
/*
 * Co-routine Available Methods
 *
 * https://openswoole.com/docs/modules/swoole-coroutine#available-methods
 */

## Run as Systemd
//https://openswoole.com/docs/modules/swoole-server-construct#systemd-setup-for-swoole-server

// swoole_last_error(), co::sleep(), co::yield(), co::resume($cid), co::select(), co::getCid(), co::getPcid(), $serv->shutdown();

