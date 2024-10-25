<?php

$registeredProcesses = [
    'news_process' => [
        // Callback of Process you want to call when the process will be created
        'callback' => [\App\Services\NewsService::class, 'handle'],

        // Optional: Array of constructor params to be used when creating the class
        'constructor_params' => [self::$server, self::$process],

        // Process Options
        'process_options' => [
            'redirect_stdin_and_stdout' => false,
            'pipe_type' => SOCK_DGRAM,
            'enable_coroutine' => true,
        ]
    ],
    'ref_process' => [
        // Callback of Process you want to call when the process will be created
        'callback' => [\App\Services\RefService::class, 'handle'],

        // Optional: Array of constructor params to be used when creating the class
        'constructor_params' => [self::$server, self::$process],

        // Process Options
        'process_options' => [
            'redirect_stdin_and_stdout' => false,
            'pipe_type' => SOCK_DGRAM,
            'enable_coroutine' => true,
        ],

    ],

    // Add More Processes Here
];

return $registeredProcesses;
