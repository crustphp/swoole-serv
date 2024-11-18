<?php

if (
    realpath(dirname(__DIR__, 2) . '/composer.json') ||
    realpath(dirname(__DIR__, 2) . '/package.json') || realpath(dirname(__DIR__, 2) . '.env')
) { // if swoole-serv is the part of a parent project
    // set parent project's .env as default .env to use
    //echo PHP_EOL.'Picking .env initially from within: '.dirname(__DIR__).PHP_EOL;
    $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();

    // check further, if project is running in local environment, then use .env local to swoole-serv
    if (!isset($_ENV['APP_ENV']) || $_ENV['APP_ENV'] == 'local') {
        //echo PHP_EOL.'Re-Picking .env finally from within: '.__DIR__.PHP_EOL;
        $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
} else {
    // The project is an independent project, means not installed in a sub-directory of "external" MVC-Framework project
    //echo PHP_EOL.'Picking .env from within: '.__DIR__.PHP_EOL;
    $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__));
    $dotenv->safeLoad();
    $local_env_is_set = true;
}
