<?php

namespace App\Services;

use Bootstrap\SwooleTableFactory;
use DB\DbFacade;

class PushToken
{
    protected $websocketserver;
    protected $objDbPool;
    protected $dbFacade;
    protected $request;

    public function __construct($websocketserver, $request, $objDbPool)
    {
        $this->websocketserver = $websocketserver;
        $this->request = $request;
        $this->objDbPool = $objDbPool;
        $this->dbFacade  = new DbFacade();
    }

    public function handle()
    {
        if ($this->request->header["refinitive-token-production-endpoint-key"] == config('app_config.refinitive_production_token_endpoint_key')) {

            $worker_id = $this->websocketserver->worker_id;
            $tokenFdsTable = SwooleTableFactory::getTable('token_fds');
            $tokenFdsTable->set($this->request->fd, ['fd' => $this->request->fd, 'worker_id' => $worker_id]);

            $refToken = new RefToken($this->websocketserver, $this->dbFacade, $this->objDbPool);
            $token = $refToken->produceActiveToken();
            unset($refToken);

            $result = json_encode($token);

            if ($this->websocketserver->isEstablished($this->request->fd)) {
                $this->websocketserver->push($this->request->fd, $result);
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
        unset($this->dbFacade);
    }
}
