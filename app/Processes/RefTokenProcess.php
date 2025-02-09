<?php

namespace App\Processes;

use App\Services\TPTokenSynchService;
use DB\DBConnectionPool;

class RefTokenProcess {

	protected $server;
    protected $process;
    protected $worker_id;

    public function __construct($server, $process, $lock = null)
    {
        $this->server = $server;
        $this->process = $process;

        // Create the DB Connection Pool
        // $this->worker_id = $process->id;
    }

    public function handle()
    {
        $tpTokenSynchService = new TPTokenSynchService(server: $this->server, process: $this->process);
        $tpTokenSynchService->handle();

        // Unset the tpTokenSynchService
        unset($tpTokenSynchService);
    }

     /**
     * __destruct
     *
     * @return void
     */
    public function __destruct()
    {
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
