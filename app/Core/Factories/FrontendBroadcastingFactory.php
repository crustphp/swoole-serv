<?php

namespace App\Core\Factories;

use App\Core\Services\FrontendBroadcastingService;

class FrontendBroadcastingFactory
{
    // The fully qualified class name of the service to be created.
    public $serviceClass = FrontendBroadcastingService::class;

    
    /**
     * Builds and returns an instance of the service class.
     * 
     * @param mixed ...$params Parameters required for creating service.
     * @return mixed An instance of the created service
     */
    public function build(...$params): mixed
    {
        return new $this->serviceClass(...$params);
    }
}
