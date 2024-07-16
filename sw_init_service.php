<?php
    declare(strict_types=1);
    require_once realpath(__DIR__ . '/vendor/autoload.php');
    include_once realpath(__DIR__.'/helper.php');

    ini_set('memory_limit', -1);
    $key_dir = __DIR__. '/ssl';

    if (isset($argc)) {
        if (file_exists(realpath(__DIR__ . '/composer.json')) ||
            file_exists(realpath(__DIR__ . '/package.json'))
        ) {
            $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__));
            $dotenv->safeLoad();
        }
        if (!isset($_ENV['APP_ENV']) || $_ENV['APP_ENV'] == 'local') {
            $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
            $dotenv->safeLoad();
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
