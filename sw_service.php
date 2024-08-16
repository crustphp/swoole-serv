<?php
    declare(strict_types=1);

use Swoole\Event;
use Swoole\Process;

    require_once realpath(__DIR__ . '/vendor/autoload.php');
    include_once realpath(__DIR__.'/helper.php');

    ini_set('memory_limit', -1);

    $key_dir = __DIR__. '/ssl';

    if (isset($argc)) {
        // If swoole-srv is part of a parent project then use parent .env for accessing parent project / database
        // This is because .env is not the part of git, hence changes to local .env can not be reflected just by changing it
        // whereas on servers which use Ploi only one single .env configuration of the parent project is defined / changed.

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
            //echo PHP_EOL.'Picking .env from within: '.__DIR__.PHP_EOL;
            $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
            $dotenv->safeLoad();
            $local_env_is_set = true;
        }

        include('service_creation_params.php');
        require_once(realpath(__DIR__ . '/config/app_config.php'));
        include('sw_service_core.php');

        if ($serverProtocol != 'close') {
//            $serverProcess = new Process(function() use ($ip, $port, $serverMode, $serverProtocol) {
                $sw_service  = new sw_service_core($ip, $port, $serverMode, $serverProtocol);
                $sw_service->start();
//                echo "Exiting";
//                exit;
//            }, false);
//            $serverProcess->start();
        } else {
            // Stop the TCP server and the process created once PHP code finishes execution.
            shell_exec('cd '.__DIR__.' && sudo kill -15 `cat sw-heartbeat.pid` && sudo rm -f sw-heartbeat.pid 2>&1 1> /dev/null&');
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
