<?php
    declare(strict_types=1);

    use Swoole\Event;
    use Swoole\Process;

    require_once realpath(__DIR__ . '/vendor/autoload.php');
    include_once realpath(__DIR__.'/helper.php');
    include_once realpath(__DIR__.'/websocketclient/wbsocketclient.php');

    ini_set('memory_limit', -1);

    $key_dir = __DIR__. '/ssl';

    if (isset($argc)) {
        // If swoole-srv is part of a parent project then use parent .env for accessing parent project / database
        // This is because .env is not the part of git, hence changes to local .env can not be reflected just by changing it
        // whereas on servers which use Ploi only one single .env configuration of the parent project is defined / changed.
        global $sw_service;
        if (realpath(dirname(__DIR__) . '/composer.json') ||
            realpath(dirname(__DIR__) . '/package.json') || realpath(dirname(__DIR__) . '.env')
        ) { // if swoole-serv is the part of a parent project
            // set parent project's .env as default .env to use
            //echo PHP_EOL.'Picking .env initially from within: '.dirname(__DIR__).PHP_EOL;
            $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__));
            $dotenv->safeLoad();

            // check further, if project is running in local environment, then use .env local to swoole-serv
            if (!isset($_ENV['APP_ENV']) || $_ENV['APP_ENV'] == 'local') {
                //echo PHP_EOL.'Re-Picking .env finally from within: '.__DIR__.PHP_EOL;
                $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
                $dotenv->safeLoad();
            }
        } else {
            // The project is an independent project, means not installed in a sub-directory of "external" MVC-Framework project
            //echo PHP_EOL.'Picking .env from within: '.__DIR__.PHP_EOL;
            $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
            $dotenv->safeLoad();
            $local_env_is_set = true;
        }

        include('service_creation_params.php');
        require_once(realpath(__DIR__ . '/config/app_config.php'));
        include('sw_service_core.php');

        if ($serverProtocol == 'shutdown') {
            // Stop the the server
            shutdown_swoole_server();
        } else if ($serverProtocol == 'restart') {
                // Stop the the server

            $ip = '127.0.0.1';
            if (isset($argv[1]) && in_array($argv[1], ['remote'])) { // Set Default IP
                $ip = '45.76.35.99';
            }

            $w = new WebSocketClient($ip, 9501);
            try {
                if ($x = $w->connect()) {
                    $w->send('get-server-params', 'text', 1);
                    $data = $w->recv();
                    if ($data) {
                        echo PHP_EOL.'Shutting Down The Server'.PHP_EOL;
                        $data = json_decode($data, true);
                        shutdown_swoole_server();
                        sleep(1);
                        create_swoole_server($data['ip'], $data['port'], $data['serverMode'], $data['serverProtocol']);
                    } else {
                        echo PHP_EOL.'Failed to get server params'.PHP_EOL;
                    }
                } else {
                    echo PHP_EOL.'Failed to connect WebSocket Server using TCP Client'.PHP_EOL;
                }
            } catch (\RuntimeException $e) {
                echo PHP_EOL.$e->getMessage().PHP_EOL;
            }
        }  else if ($serverProtocol == 'reload-code') {
            // Stop the the server

            $ip = '127.0.0.1';
            if (isset($argv[1]) && in_array($argv[1], ['remote'])) { // Set Default IP
                $ip = '45.76.35.99';
            }

            $w = new WebSocketClient($ip, 9501);
            try {
                if ($x = $w->connect()) {
                    $w->send('reload-code', 'text', 1);
                } else {
                    echo PHP_EOL.'Failed to connect WebSocket Server using TCP Client'.PHP_EOL;
                }
            } catch (\RuntimeException $e) {
                echo PHP_EOL.$e->getMessage().PHP_EOL;
            }
        } else {
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
            // kill -9 $(lsof -t -i:9501) OR kill -15 $(lsof -t -i:9501)
        } else {
            echo PHP_EOL.'server.pid file not found. Looks like server is not running already.'.PHP_EOL;
        }
    }

    function create_swoole_server($ip, $port, $serverMode, $serverProtocol) {
        global $sw_service;
        $sw_service  = new sw_service_core($ip, $port, $serverMode, $serverProtocol);
        $sw_service->start();
    }
