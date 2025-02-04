<?php

namespace App\Services;

use Carbon\Carbon;
use DB\DbFacade;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\WaitGroup;
use Throwable;

class TPTokenRetrievalAndSynchService
{
    protected $server;
    protected $process;
    protected $refProductionTokenEndpointKey;
    protected $objDbPool;
    protected $dbFacade;

    public function __construct($server, $process, $objDbPool)
    {
        $this->refProductionTokenEndpointKey = config('ref_config.ref_production_token_endpoint_key');
        $this->server = $server;
        $this->process = $process;
        $this->objDbPool = $objDbPool;
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
            try {
                $refToken = new RefToken($this->server);
                while (true) {
                  $token = $refToken->produceActiveToken();
                  if($token) {
                      usleep(200000);
                  } else {
                    sleep(3);
                  }
                }
            } catch (Throwable $e) {
                output($e);
            } finally {
                unset($refToken);
            }

            output('Exitting the TPTokenRetrievalAndSynchService Process.');
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
        $result = null;
        $dbQuery = "SELECT * FROM refinitiv_auth_token_sw LIMIT 1";

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
        $updateQuery = "UPDATE refinitiv_auth_token_sw
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

        $insertQuery = "INSERT INTO refinitiv_auth_token_sw (access_token, refresh_token, expires_in, created_at, updated_at)
            VALUES ('$accessToken', '$refreshToken', $expiresIn, '$createdAt', '$updatedAt')";

        go(function () use ($insertQuery) {
            try {
                $this->dbFacade->query($insertQuery, $this->objDbPool);
            } catch (Throwable $e) {
                output($e);
            }
        });
    }

}
