<?php
use Swoole\Coroutine;
use Al\Swow\Context;
use DB\DBFacade;
use Swoole\Runtime;
use Swoole\Http\Request;

// Available Params
//Swoole\Http\Request $request,
//Swoole\Http\Response $response
//this->httpServer
class HttpRequestController {
    protected $httpServer;
    protected Swoole\Http\Request $request;
    protected Swoole\Http\Response $response;
    protected $dbConnectionPools;
    protected $postgresDbKey = 'pg';
    protected $mySqlDbKey = 'mysql';

    public function __construct(
        $httpServer,
        Request $request,
        $dbConnectionPools,
        $postgresDbKey = 'pg',
        $mySqlDbKey = 'mysql'
    )
    {
        $this->httpServer = $httpServer;
        $this->request = $request;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->postgresDbKey = $postgresDbKey;
        $this->mySqlDbKey = $mySqlDbKey;
    }

    public function handle(){
        if (!isset($this->request->post['message'])) {
            return [
                'response'  => 'POST parameter \'message\' is missing',
//                'result' => $result,
//                'clients' => $clients,
            ];
        }
        if ($this->request->post['message'] == 'reload-code') {
            echo "Reloading Code Changes (by Reloading All Workers)".PHP_EOL;
            $this->httpServer->reload();
        } else {
            echo 'POST[\'message\']: '.$this->request->post['message'].PHP_EOL;
            $conn_pool = $this->dbConnectionPools[$this->postgresDbKey]->get_connection_pool_with_key($this->postgresDbKey.$this->httpServer->worker_id);

            // A coroutine context is created for each callback function of Swoole Server. So, no need to run co\run here
            $pcid = Coroutine::getCid();
            $pctx = new Context();
            $pctx->set('_GET', (array)$this->request->get);
            $pctx->set('_POST', (array)$this->request->post);
            $pctx->set('_FILES', (array)$this->request->files);
            $pctx->set('_COOKIE', (array)$this->request->cookie);

            // Function below is erroniously called and gives no output as it goes in non-terminating condition
//            go(function() use ($pcid, $pctx) {
//
//                go(function() use ($pcid, $pctx) {
//                    $pctx->getglobal('_GET', "Test");
//                });
//            });

            // // To get values
            // $pctx->get($key [, $cid, [,default_value]]);
            // // OR
            // $pctx->getGlobal($key, [, default value [, $cid]]);
            // Other functions
            // Swoole\coroutine::getContext();
            // Swoole\coroutine::getContext($cid);
            // $pctx->getcontainer(); // returns array form of the context

//            var_dump(Co::getContext());
//            echo Co::getCid();
//            echo Co::getPcid();
//
//            go(function() {
//                var_dump(Co::getContext());
//                echo Co::getCid();
//                echo Co::getPcid();
//
//                go(function() {
//                    var_dump(Co::getContext());
//                    echo Co::getCid();
//                    echo Co::getPcid();
//                });
//            });

            ////////////////////////////////////////////////////////////////////////////////
            //// Get DB Connection from a Connection Pool created through 'smf' package ////
            ////////////////////////////////////////////////////////////////////////////////
            $record_set = new Swoole\Coroutine\Channel(1);
            go(function() use ($record_set, $conn_pool) {
                //$this->httpServer
                //$this->request,
                $db = new DBFacade();
                $db_query = 'SELECT * FROM users;';
                $db_result = $db->query($db_query, $conn_pool);
                $record_set->push($db_result);
            });


            //////////////////////////////////////////////////////////////////////////////////////
            //// Get a Redis Connection from Connection Pool created through 'swoole library' ////
            //////////////////////////////////////////////////////////////////////////////////////

//            $pool2 = $this->getConnectionPool('redis');
//            /**@var \Redis $redis */
//            $redis = $pool2->borrow();
//            $clients = $redis->info('Clients');
//            // Return the connection to pool as soon as possible
//            $pool2->return($redis);

            // Other Business logic

            return [
                'response'  => $record_set->pop(),
//                'result' => $result,
//                'clients' => $clients,
            ];
        }
    }
}

