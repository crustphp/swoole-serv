<?php
    declare(strict_types=1);
    require_once realpath(__DIR__ . '/vendor/autoload.php');
    include_once __DIR__.'/helper.php';

    ini_set('memory_limit', -1);
    $key_dir = __DIR__. '/ssl';

    if (isset($argc)) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
        include('service_creation_params.php');
        include('sw_service.php');

//        Co\run (function($ip, $port, $serverMode) {
            $service  = new sw_service($ip, $port, $serverMode, $serverProtocol);
            $service->start();
//        }, $ip, $port, $serverMode);
    } else {
        echo "argc and argv disabled\n";
    }
