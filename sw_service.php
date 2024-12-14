<?php
declare(strict_types=1);

    include_once realpath(__DIR__ . '/vendor/autoload.php');
    include_once realpath(__DIR__.'/helper.php');

    use Swoole\Event;
    use Swoole\Process;
    use Websocketclient\WebSocketClient;


    ini_set('memory_limit', -1);

    $key_dir = __DIR__. '/ssl';

    if (isset($argc)) {
        // If swoole-srv is part of a parent project then use parent .env for accessing parent project / database
        // This is because .env is not the part of git, hence changes to local .env can not be reflected just by changing it
        // whereas on servers which use Ploi only one single .env configuration of the parent project is defined / changed.
        global $sw_service;
        include_once realpath(__DIR__ . '/includes/LoadEnv.php');

        include('service_creation_params.php');
        include('sw_service_core.php');

        // Priviliged Connection / FD
        $priviligedConnHeader = "privileged-key:" . config('app_config.privileged_fd_secret') . "\r\n\r\n";

        // Shutdown Server
        if ($serverProtocol == 'shutdown') {
            output('--- Shutting Down the Server ---');

            $w = new WebSocketClient($ip, $port);
            try {
                if ($x = $w->connect($priviligedConnHeader)) {
                    $w->send('shutdown', 'text', 1);
                } else {
                    output('Failed to connect WebSocket Server using TCP Client');
                }
            } catch (\RuntimeException $e) {
                output($e);
            }

            // Stop the the server
            // shutdown_swoole_server();
        } 
        // Restart the Server
        else if ($serverProtocol == 'restart') {
            output('--- Restarting the Server ---');

            $w = new WebSocketClient($ip, $port);
            try {
                if ($x = $w->connect($priviligedConnHeader)) {
                    $w->send('get-server-params', 'text', 1);
                    $data = $w->recv();
                    if ($data) {
                        output('--> Shutting Down the Server');

                        $data = json_decode($data, true);

                        $w->send('shutdown', 'text', 1);
                        
                        do {    
                            sleep(1);
                        }
                        while(isset($sw_service) && !is_null($sw_service->server));
                        
                        output('--> Starting the Server');
                        create_swoole_server($data['ip'], $data['port'], $data['serverMode'], $data['serverProtocol']);
                    } else {
                        output('Failed to get server params');
                    }
                } else {
                    output('Failed to connect WebSocket Server using TCP Client');
                }
            } catch (\RuntimeException $e) {
                output($e);
            }
        } 
        // Reload code
        else if ($serverProtocol == 'reload-code') {
            output('--- Reloading the Code ---');

            $w = new WebSocketClient($ip, $port);
            try {
                if ($x = $w->connect($priviligedConnHeader)) {
                    $w->send('reload-code', 'text', 1);
                } else {
                    output('Failed to connect WebSocket Server using TCP Client');
                }
            } catch (\RuntimeException $e) {
                output($e);
            }
        }  
        // Get the Server Stats
        else if ($serverProtocol == 'stats') {
            output('--- Fetching Server Stats ---');

            try {
                $w = new WebSocketClient($ip, $port);

                if ($x = $w->connect($priviligedConnHeader)) {
                    $w->send('get-server-stats', 'text', 1);
                    $statsData = $w->recv();
                    
                    output(json_decode($statsData, true));
                } else {
                    output('Failed to connect WebSocket Server using TCP Client');
                }
            } catch (\Throwable $e) {
                output($e);
            }
        }
        // Start the Server
        else {
            output('--- Starting the Server ---');
            
            //    $serverProcess = new Process(function() use ($ip, $port, $serverMode, $serverProtocol) {
            create_swoole_server($ip, $port, $serverMode, $serverProtocol);
//                echo "Exiting";
//                exit;
//            }, false);
//            $serverProcess->start();
        }
//        register_shutdown_function(function () use ($serverProcess) {
//            Process::kill(intval(shell_exec('cat '.__DIR__.'/sw-heartbeat.pid')), SIGTERM);
//            sleep(1);
//            Process::kill($serverProcess->pid);
//        });


        // NOTE: In most cases it's not necessary nor recommended to use method `Swoole\Event::wait()` directly in your code.
        // The example in this file is just for demonstration purpose.
//        Event::wait();
    } else {
        echo "argc and argv disabled\n";
    }

    function shutdown_swoole_server() {
        if (file_exists('server.pid')){
            shell_exec('cd '.__DIR__.' && kill -15 `cat server.pid` 2>&1 1> /dev/null&'); //&& sudo rm -f server.pid
        } else {
            echo PHP_EOL.'server.pid file not found. Looks like server is not running already.'.PHP_EOL;
        }
    }

    function create_swoole_server($ip, $port, $serverMode, $serverProtocol) {
        global $sw_service;
        $sw_service  = new sw_service_core($ip, $port, $serverMode, $serverProtocol);
        $sw_service->start();
        unset($sw_service);
    }
    