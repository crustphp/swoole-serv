<?php

use Swoole\Timer;
use Swoole\Process;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

// Set the Process Name
cli_set_process_title('php-monitoring:swoole-monitoring-process');

// getopt Docs: https://www.php.net/manual/en/function.getopt.php
$options = getopt("", ["host::", "port::", "timeout::", "daemon::", "pingtimer::"]);

// Run the script in Daemon Mode. By Default it will run in deamon mode
$deamonMode = isset($options['daemon']) && (bool) $options['daemon'] == false ? false : true;
if ($deamonMode) {
    if (!Process::daemon(true, false)) {
        echo 'Failed to run the script in demon mode' . PHP_EOL;
        return;
    }
}

// Settings
$host = $options['host'] ?? '127.0.0.1';
$port = isset($options['port']) ? (int) $options['port'] : 9501;
$timeout = isset($options['timeout']) ? (int) $options['timeout'] : 15;
$pingTimer = isset($options['pingtimer']) ? (int) $options['pingtimer'] : 5000;

$client = null;
$lastPongTime = time();

Co\run(function () use ($host, $port, $timeout, &$lastPongTime, &$client, $pingTimer) {
    // To Prevent exta outout to terminal, just logs Error only
    Co::set(['log_level' => SWOOLE_LOG_ERROR]);

    echo "Connecting to WebSocket server at ws://{$host}:{$port}..." . PHP_EOL;

    // Create and upgrade the WebSocket client connection
    $client = createClient($host, $port);
    if (!$client) {
        echo 'Failed to retrieve the Websocket Client';
        return;
    }

    // Start the monitoring-coroutine for the PONG responses
    startMonitoringCoroutine($client, $lastPongTime);

    // Send Ping Frames using Timer
    Timer::tick($pingTimer, function () use (&$client, &$lastPongTime, $timeout, $host, $port) {
        // Check for timeout
        if (time() - $lastPongTime > $timeout) {
            echo "No PONG received for $timeout seconds. WebSocket server might be down." . PHP_EOL;
            restartServer($port);
            $lastPongTime = time();

            // Disconnect and recreate the client after server restart
            $client = null;
            Co::sleep(2);

            $client = createClient($host, $port);

            if (!$client) {
                echo "Failed to reconnect to the WebSocket server after restart." . PHP_EOL;
                return;
            }

            // Reset the lastPongTime since we just reconnected
            $lastPongTime = time();

            // Restart the monitoring-coroutine for the new client
            startMonitoringCoroutine($client, $lastPongTime);
        } else if ($client) {
            echo "Sending PING frame..." . PHP_EOL;
            $client->push('', WEBSOCKET_OPCODE_PING); // Send a PING frame
        }
    });
});

/**
 * Create the Websocket Client
 *
 * @param  mixed $host
 * @param  mixed $port
 * @return mixed
 */
function createClient($host, $port): mixed
{
    $client = new Client($host, $port);
    $client->set(['websocket_mask' => true]);

    if (!$client->upgrade('/')) {
        echo "Failed to connect to WebSocket server." . PHP_EOL;
        return null;
    }

    echo "Connected to WebSocket server. Monitoring started." . PHP_EOL;
    return $client;
}

/**
 * Start the Pong Monitoring inside a Coroutine
 *
 * @param  mixed $client
 * @param  mixed $lastPongTime
 * @return void
 */
function startMonitoringCoroutine($client, &$lastPongTime): void
{
    $frameChannel = new Channel(1);

    go(function () use ($frameChannel, $client, &$lastPongTime) {
        while (true) {
            go(function () use ($frameChannel, $client, &$lastPongTime) {
                $frame = $client->recv();
                Co::sleep(0.25);
                $frameChannel->push($frame);
            });

            Co::sleep(0.25);
            $frame = $frameChannel->pop();

            if ($frame === false) {
                echo "Connection error or server is unreachable." . PHP_EOL;
                break;
            }

            if ($frame === null) {
                continue;
            }

            if ($frame->opcode === WEBSOCKET_OPCODE_PONG) {
                echo "PONG received from server." . PHP_EOL;
                $lastPongTime = time();
            }

            Co::sleep(1);
        }
    });
}

/**
 * Kill and restart the Websocket Server
 *
 * @return void
 */
function restartServer($port): void
{
    echo "Restarting the WebSocket server..." . PHP_EOL;

    $myPid = getmypid();

    // Prevent the current script from killing
    $killCommand = "sudo kill -9 $(sudo lsof -t -i:$port | grep -v $myPid)";
    exec($killCommand, $output, $resultCode);

    if ($resultCode === 0) {
        echo "All processes using port $port terminated successfully." . PHP_EOL;
    } else {
        echo "Failed to terminate processes using port $port." . PHP_EOL;
    }

    // Start the server in a seperate new process
    $command = 'nohup php sw_service.php websocket > /dev/null 2>&1 &';
    exec($command, $output, $resultCode);

    if ($resultCode === 0) {
        echo "Server restarted successfully in a new process." . PHP_EOL;
    } else {
        echo "Failed to restart the server." . PHP_EOL;
    }
}
