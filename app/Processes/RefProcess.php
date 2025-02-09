<?php

namespace App\Processes;

use DB\DBConnectionPool;
use App\Services\RefDataService;

class RefProcess {

	protected $server;
    protected $process;

    protected $dbConnectionPools = [];
    protected $poolKey;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;

    protected $lock = null;


    public function __construct($server, $process, $lock = null)
    {
        $this->server = $server;
        $this->process = $process;
        $this->lock = $lock;

        // Create the DB Connection Pool
        $this->worker_id = $process->id;

        $app_type_database_driven = config('app_config.app_type_database_driven');
        if ($app_type_database_driven) {
            $this->poolKey = makePoolKey($this->worker_id, 'postgres');

            try {
                // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                // Important: make sure you use the same identifier as pool_key that you use for $this->dbConnectionPools
                // If you want to use different identifier than modify the __destruct code accordingly
                $this->dbConnectionPools[$this->poolKey] = new DBConnectionPool($this->poolKey, 'postgres', 'swoole', true);
                $this->dbConnectionPools[$this->poolKey]->create();
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }
        }

        $this->objDbPool = $this->dbConnectionPools[$this->poolKey];
    }

    public function handle()
    {
        // Use/call your services Here
        $refDataService = new RefDataService(server: $this->server, process: $this->process, objDbPool: $this->objDbPool, refTokenLock: $this->lock);
        $refDataService->handle();
    }

    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct()
    {
        // Revoking the Database related resources
        $app_type_database_driven = config('app_config.app_type_database_driven');

        if ($app_type_database_driven) {
            if (isset($this->dbConnectionPools)) {
                foreach ($this->dbConnectionPools as $key => $dbConnectionPool) {
                    echo PHP_EOL . "Closing Connection Pool: $key" . PHP_EOL;
                    $dbConnectionPool->closeConnectionPool($key);
                    unset($dbConnectionPool);
                }

                unset($this->dbConnectionPools);
            }
        }

        unset($this->objDbPool);
    }

    /**
     * Revoke the process resources
     *
     * @return void
     */
    public function revokeProcessResources() {
        if (method_exists($this, '__destruct')) {
            $this->__destruct();
        }
    }
}
