<?php

namespace App\Processes;

use App\Services\RefTokenService;

class RefTokenProcess {

	protected $server;
    protected $process;

    public function __construct($server, $process)
    {
        $this->server = $server;
        $this->process = $process;
    }

    public function handle()
    {
        $refTokenService = new RefTokenService($this->server, $this->process);
        $refTokenService->handle();

        // Unset the RefTokenService
        unset($refTokenService);
    }
}
