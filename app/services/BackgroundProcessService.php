<?php

use Swoole\Timer as swTimer;
use Swoole\Process;
use DB\DBConnectionPool;

class BackgroundProcessService
{
    protected $server;
    protected $dbConnectionPools;

    public function __construct($server) {
        $this->server = $server;
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        $backgroundProcess = new Process(function ($process) {
            /// DB connection
            $app_type_database_driven = config('app_config.app_type_database_driven');
            $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
            $worker_id = $process->id;

            if ($app_type_database_driven) {
                $poolKey = makePoolKey($worker_id, 'postgres');
                try {
                    // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey, 'postgres', 'swoole', true);
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key]->create();
                } catch (\Throwable $e) {
                    dump($e->getMessage());
                    dump($e->getFile());
                    dump($e->getLine());
                    dump($e->getCode());
                    dump($e->getTrace());
                }
            }

            swTimer::tick(config('app_config.most_active_refinitive_timespan'), function () use ($worker_id) {
                include_once __DIR__ . '/MostActiveRefinitive.php';
                $service = new MostActiveRefinitive($this->server, $this->dbConnectionPools[$worker_id]);
                $mostActiveValues = $service->handle();
                // Compare fetched data with existing data
                // if there is found difference in comparison then Broadcast data
                // Save into Database tables
                // Save into swoole table for cache purpose

                // for ($i = 0; $i < $this->server->setting['worker_num']; $i++) {
                //     $message = 'From Backend | For Worker: ' . $i;
                //     $this->server->sendMessage($message, $i);
                // }

            });
        }, false, SOCK_DGRAM, true);

        $this->server->addProcess($backgroundProcess);
    }
}
