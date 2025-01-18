<?php

namespace App\Services;

use Carbon\Carbon;
use DB\DBConnectionPool;
use Swoole\Timer as swTimer;
use DB\DbFacade;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\WaitGroup;
use Throwable;

class TPTokenRetrievalAndSynchService
{
    protected $server;
    protected $postgresDbKey;
    protected $process;
    protected $refProductionTokenEndpointKey;
    protected $dbConnectionPools;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;

    public function __construct($server, $process, $postgresDbKey = null)
    {
        $this->refProductionTokenEndpointKey = config('ref_config.ref_production_token_endpoint_key');
        $this->server = $server;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->process = $process;
        $this->worker_id = $this->process->id;

        $app_type_database_driven = config('app_config.app_type_database_driven');
        if ($app_type_database_driven) {
            $poolKey = makePoolKey($this->worker_id, 'postgres');
            try {
                // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey, 'postgres', 'swoole', true);
                $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key]->create();
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }
        }

        $this->objDbPool = $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key];
        $this->dbFacade = new DbFacade();
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        // In case of stage or local make websocket connection with prod
        if (config('app_config.env') == 'local' || config('app_config.env') == 'staging') {
            $this->getTokenFrmProductionSever(config('app_config.production_ip'));
        } else {
//            Co::sleep(2);
            swTimer::tick(config('app_config.api_token_time_span'), function ($timerId) {
                $refToken = new RefToken($this->server, $this->dbFacade, $this->objDbPool);
                $refToken->produceActiveToken();
                unset($refToken);
//                swTimer::clear($timerId);
            });
        }
    }

    function getTokenFrmProductionSever($ip)
    {
        $objTokenRetrieval = new Client($ip, 9501);
        $objTokenRetrieval->set(['timeout' => -1]);
        $objTokenRetrieval->setHeaders(['refinitive-token-production-endpoint-key' => $this->refProductionTokenEndpointKey]);
        $connTokenRetrieval = $objTokenRetrieval->upgrade('/');

        if ($connTokenRetrieval) {
            while (true) {
                $recievedata = $objTokenRetrieval->recv();
                if ($recievedata) {
                    $recievedata = json_decode($recievedata->data ?? null);

                    if (
                        isset($recievedata->access_token)
                        && isset($recievedata->refresh_token)
                        && isset($recievedata->expires_in)
                        && isset($recievedata->updated_at)

                    ) {
                        $serverUpdatedAt = Carbon::parse($recievedata->updated_at)->timezone('UTC');
                        $localUpdatedAt = $serverUpdatedAt->timezone(config('app_config.time_zone'));
                        $createdAt = Carbon::now()->format('Y-m-d H:i:s');

                        // Get refinitive token from DB
                        $token = $this->getRefTokenFromDB();

                        if (!$token) { // If there is no token into the DB
                            $this->insertIntoRefAuthTable($recievedata->access_token, $recievedata->refresh_token, $recievedata->expires_in, $createdAt, $localUpdatedAt);
                        } else { // Update the token if token exist already
                            $this->updateIntoRefAuthTable($recievedata->access_token, $recievedata->refresh_token, $recievedata->expires_in, $createdAt, $localUpdatedAt, $token['id']);
                        }
                    }
                }
                Co::sleep(1);
            }
        } else {
            echo "Could not connect to server" . PHP_EOL;
        }
    }

    public function getRefTokenFromDB()
    {
        $tableName = 'refinitiv_auth_tokens';
        $result = null;
        $dbQuery = "SELECT * FROM $tableName LIMIT 1";

        $waitGroup = new WaitGroup();
        $waitGroup->add();
        go(function () use ($waitGroup, $dbQuery, &$result) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
            } catch (Throwable $e) {
                output($e);
            }
            $waitGroup->done();
        });

        $waitGroup->wait();

        return $result ? $result[0] : null;
    }

    function updateIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt, $tokenId)
    {
        $updateQuery = "UPDATE refinitiv_auth_tokens
        SET access_token = '$accessToken',
            refresh_token = '$refreshToken',
            expires_in = $expiresIn,
            created_at = '$createdAt',
            updated_at = '$updatedAt'
        WHERE id = $tokenId";

        go(function () use ($updateQuery) {
            try {
                $this->dbFacade->query($updateQuery, $this->objDbPool);
            } catch (Throwable $e) {
                output($e);
            }
        });
    }

    function insertIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt)
    {

        $insertQuery = "INSERT INTO refinitiv_auth_tokens (access_token, refresh_token, expires_in, created_at, updated_at)
            VALUES ('$accessToken', '$refreshToken', $expiresIn, '$createdAt', '$updatedAt')";

        go(function () use ($insertQuery) {
            try {
                $this->dbFacade->query($insertQuery, $this->objDbPool);
            } catch (Throwable $e) {
                output($e);
            }
        });
    }

    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct() {
        $app_type_database_driven = config('app_config.app_type_database_driven');
        $worker_id = $this->worker_id;

        if ($app_type_database_driven) {
            if (isset($this->dbConnectionPools[$worker_id])) {
                $worker_dbConnectionPools = $this->dbConnectionPools[$worker_id];
                $mysqlPoolKey = makePoolKey($worker_id, 'mysql');
                $pgPoolKey = makePoolKey($worker_id, 'postgres');
                foreach ($worker_dbConnectionPools as $dbKey => $dbConnectionPool) {
                    if ($dbConnectionPool->pool_exist($pgPoolKey)) {
                        output("Closing Connection Pool: " . $pgPoolKey);
                        // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                        $dbConnectionPool->closeConnectionPool($pgPoolKey);
                    }

                    if ($dbConnectionPool->pool_exist($mysqlPoolKey)) {
                        output("Closing Connection Pool: " . $mysqlPoolKey);
                        // Through ConnectionPoolTrait, as used in DBConnectionPool Class
                        $dbConnectionPool->closeConnectionPool($mysqlPoolKey);
                    }
                    unset($dbConnectionPool);
                }

                unset($this->dbConnectionPools);
            }
        }

        unset($this->objDbPool);

        // Uncomment following if needed to revoke memory immediately.
        // $this->dbConnectionPools = null;
        // $this->objDbPool = null;
    }
}
