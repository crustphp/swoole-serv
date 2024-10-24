<?php

$registeredServices = [
    // Hint: Alias => ServiceClass Namespace::class
    'FrontendBroadcastingService' => [
        'class' => \App\Core\Services\FrontendBroadcastingService::class,

        // You can either pass a factory with factory class and method name
        // or by directly adding a callback function

        // Using Factory Class and its Method/Function
        'factory' => [\App\Core\Factories\FrontendBroadcastingFactory::class, 'build'],

        // Using a callback function
        // 'factory' => function ($websocketserver) {
        //     return new \App\Core\Services\FrontendBroadcastingService($websocketserver);
        // },
    ],

    // Add More Services Here
];

return $registeredServices;