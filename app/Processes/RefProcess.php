<?php

namespace App\Processes;

use App\Services\RefDataService;

class RefProcess {

	protected $server;
    protected $process;

    public function __construct($server, $process)
    {
        $this->server = $server;
        $this->process = $process;
    }

    public function handle()
    {
        // Use/call your services Here
        $refService = new RefDataService($this->server, $this->process);
        $refService->handle();
    }
}
