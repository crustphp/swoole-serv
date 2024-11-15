<?php

namespace App\Processes;

use App\Services\RefService;

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
        $refService = new RefService($this->server, $this->process);
        $refService->handle();
    }
}
