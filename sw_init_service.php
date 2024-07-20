<?php
    declare(strict_types=1);
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
        include('sw_service.php');

//        Co\run (function($ip, $port, $serverMode) {
            $service  = new sw_service($ip, $port, $serverMode, $serverProtocol);
            $service->start();
//        }, $ip, $port, $serverMode);
    } else {
        echo "argc and argv disabled\n";
    }
