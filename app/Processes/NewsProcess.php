<?php

namespace App\Processes;

use App\Services\NewsService;

class NewsProcess {

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
        $newsService = new NewsService($this->server, $this->process);
        $newsService->handle();
    }
}
