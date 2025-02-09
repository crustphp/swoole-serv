<?php

namespace App\Services;

use Swoole\Coroutine;
use Bootstrap\SwooleTableFactory;
use Carbon\Carbon;

class PushToken
{
    protected $websocketserver;
    protected $request;

    public function __construct($websocketserver, $request)
    {
        $this->websocketserver = $websocketserver;
        $this->request = $request;
    }

    public function handle()
    {
        if ($this->request->header["privileged-fd-key-for-ref-token"] == config('ref_config.privileged_fd_key_for_ref_token')) {

            $worker_id = $this->websocketserver->worker_id;
            $tokenFdsTable = SwooleTableFactory::getTable('token_fds');
            $tokenFdsTable->set($this->request->fd, ['fd' => $this->request->fd, 'worker_id' => $worker_id]);

            $refTokenTable =  \Bootstrap\SwooleTableFactory::getTable('ref_token_sw', true);
            $tokenRec = $refTokenTable->get('1');

            // Token is empty or expired
            while (empty($tokenRec) ||  Carbon::now()->timestamp - Carbon::parse($tokenRec['updated_at'])->timestamp >= ($tokenRec['expires_in'] - 60)) {
                $tokenRec = $refTokenTable->get('1');
                Coroutine::sleep(0.5);
            }

            if ($tokenRec) {
                $result = json_encode($tokenRec);

                if ($this->websocketserver->isEstablished($this->request->fd)) {
                    $this->websocketserver->push($this->request->fd, $result);
                }
            } else {
                var_dump(__METHOD__ . ' Could not retrieve ref token');
            }
        } else {
            // Invalid token endpoint key of porduction
            $msg = json_encode("Unauthenticated: Invalid token endpoint key of porduction.");
            if ($this->websocketserver->isEstablished($this->request->fd)) {
                $this->websocketserver->push($this->request->fd, $msg);
            }
            var_dump("Unauthenticated: Invalid token endpoint key of porduction.");
        }
    }

    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct() {
    }
}
