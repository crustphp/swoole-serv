<?php

namespace App\Services;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine;
use Bootstrap\SwooleTableFactory;
use Carbon\Carbon;
use DB\DbFacade;
use Throwable;

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
        if ($this->request->header["refinitive-token-production-endpoint-key"] == config('ref_config.ref_production_token_endpoint_key')) {

            $worker_id = $this->websocketserver->worker_id;
            $tokenFdsTable = SwooleTableFactory::getTable('token_fds');
            $tokenFdsTable->set($this->request->fd, ['fd' => $this->request->fd, 'worker_id' => $worker_id]);

            $tokenRec = $this->getRefTokenRecord();

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

    public function getRefTokenRecord()
    {
        $dbQuery = "SELECT * FROM refinitiv_auth_token_sw LIMIT 1";

        $channel = new Channel(1);

        go(function () use ($dbQuery, $channel) {
            try {
                $try = 0;
                do {
                    $tokenRec = $this->dbFacade->query($dbQuery, $this->objDbPool);
                    $tokenRec =  $tokenRec ? ($tokenRec[0] ?? false) : false;
                    if ($tokenRec) {
                        if (!(Carbon::now()->timestamp - Carbon::parse($tokenRec['updated_at'])->timestamp >= ($tokenRec['expires_in'] - 60))) {
                            $channel->push($tokenRec);
                            break;
                        }
                    }

                    Coroutine::sleep(1);
                    $try++;
                } while ($try < 10);

                if ($try == 10) {
                    $channel->push(0);
                }
            } catch (Throwable $e) {
                output($e);
            }
        });

        $result = $channel->pop();

        return $result == 0 ? false : $result;
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
