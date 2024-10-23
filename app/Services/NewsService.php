<?php

namespace App\Services;

class NewsService
{
    protected $server;
    protected $process;
    protected $dbConnectionPools;
    protected $postgresDbKey;

    public function __construct($server, $process, $postgresDbKey = null)
    {
        $this->server = $server;
        $this->process = $process;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
    }

    public function handle()
    {
        echo PHP_EOL . 'PROCESS ID: '.$this->process->id . PHP_EOL;
        echo PHP_EOL . 'PROCESS PID: '.$this->process->pid . PHP_EOL;

        // Create the Process PID File
        // $rootDir = dirname(__DIR__, 2);
        // $pidFile = $rootDir . DIRECTORY_SEPARATOR . 'test_service.pid';
        // file_put_contents($pidFile, $process->pid);

        // This method also works due to autoload
        // $serviceTwo = new TestServiceTwo();
        // $serviceTwo->handle($process);

        // You can also reload the code using include statement directly in handle() or Timer function
        // include(__DIR__. '/echo.php');
        
        echo 'Echo FROM OUTSIDE INCLUDE - 1' . PHP_EOL;       
    }
}
