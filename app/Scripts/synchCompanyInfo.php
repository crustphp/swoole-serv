<?php

namespace App\Scripts;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine as Co;

$data = json_decode($argv[1] ?? '{}', true);

\Swoole\Coroutine\run(function () use ($data) {
    $maxRetries = 5;
    $retryInterval = 1; // in seconds

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $client = new Client('0.0.0.0', 9501);
        $client->set(['timeout' => -1]);

        if ($client->upgrade('/')) {
            $command = [
                'command'    => $data['command'],
                'companyInfo' => $data['companyInfo'],
            ];

            $client->push(json_encode($command));
            break; // success
        } else {
            var_dump("[$attempt/$maxRetries] Could not connect to server, retrying...");
            Co::sleep($retryInterval);
        }

        // On final failed attempt
        if ($attempt === $maxRetries) {
            var_dump("Failed after $maxRetries attempts. Server might be down.");
        }
    }
});
