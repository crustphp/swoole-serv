<?php

namespace App\Core\Services;

class FrontendBroadcastingService {
    protected $webSocketServer;

    /**
     * __construct
     *
     * @param  mixed $webSocketServer
     * @return void
     */
    public function __construct($webSocketServer) {
        $this->webSocketServer = $webSocketServer;
    }

    /**
     * Magic invoke method to broadcast the message to all workers fds.
     *
     * @param string|array $message The message to broadcast, either as a string or array.
     * @return void
     */
    public function __invoke(string|array $message):void {
        // Convert array message to JSON string if necessary
        if (is_array($message)) {
            $message = json_encode($message);
        }

        // Pipe the message to all other workers
        for ($i = 0; $i < $this->webSocketServer->setting['worker_num']; $i++) {
            // Swoole\Server::sendMessage(): can't send messages to self (It gives warning if we remove this if condition)
            if ($i !== $this->webSocketServer->worker_id) {
                $this->webSocketServer->sendMessage($message, $i);
            }
        }

        // message to all fds in this scope (Worker Process)
        foreach($this->webSocketServer->fds as $fd) {
            $this->webSocketServer->push($fd, $message);
        }
    }
}
