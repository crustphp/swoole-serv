<?php

namespace App\Services;

use DB\DBConnectionPool;
use Carbon\Carbon;
use App\Core\Services\APIConsumer;
use Bootstrap\SwooleTableFactory;
use Websocketclient\WebSocketClient;
use Swoole\Coroutine\WaitGroup;
use Throwable;


class RefToken
{
    protected $dbFacade;
    protected $objDbPool;
    protected $grantType;
    protected $username;
    protected $password;
    protected $scope;
    protected $takeExclusiveSignOnControl;
    protected $clientId;
    protected $refreshGrantType;
    protected $url;
    protected $timeout;
    protected $server;

    protected $postgresDbKey;
    protected $worker_id;
    protected $dbConnectionPools;


    public function __construct($server ,$dbFacade, $objDbPool)
    {
        $this->grantType = config('ref_config.grant_type');
        $this->username = config('ref_config.username');
        $this->password = config('ref_config.password');
        $this->scope = config('ref_config.scope');
        $this->takeExclusiveSignOnControl = config('ref_config.take_exclusive_sign_on_control');
        $this->clientId = config('ref_config.client_id');
        $this->refreshGrantType = config('ref_config.refresh_grant_type');
        $this->url = config('ref_config.url');
        $this->timeout = config('app_config.api_req_timeout');

        $this->server = $server;
        $this->objDbPool = $objDbPool;
        $this->dbFacade = $dbFacade;
    }

    public function produceActiveToken( $refresh = '')
    {
        // Retrieve the first RefinitivAuthToken record from the database
        $token = $this->getRefTokenFromDB();
        // If no token is found, proceed to obtain a new one
        if (!$token) {

            if (config('app_config.env') != 'local' && config('app_config.env') != 'staging') {
                try {
                    // Get Postgres Client
                    $postgresClient = $this->dbFacade->getClient($this->objDbPool);

                    // Begin the transaction
                    $this->dbFacade->beginTransaction($postgresClient);

                    // Lock the table to prevent concurrent operations
                    $this->dbFacade->lockTable($postgresClient, 'refinitiv_auth_tokens');

                    $token = $this->getRefTokenFromDB();

                    if (!$token) {
                        $refToken = $this->fetchRefTokenFromRefAPI();

                        if (!isset($refToken['error'])) {
                            $refToken = json_decode($refToken['response']);
                            $accessToken = $refToken->access_token;
                            $refreshToken = $refToken->refresh_token;
                            $expiresIn = $refToken->expires_in;

                            $dateTime = Carbon::now()->format('Y-m-d H:i:s');
                            $createdAt = $dateTime;
                            $updatedAt = $dateTime;

                            $this->insertIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt);

                            $token = [
                                'access_token' => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expires_in' => $expiresIn,
                                'updated_at' => $updatedAt,
                            ];

                            $data = $token;
                            $data = json_encode($data);

                            $tokenFdsData =  SwooleTableFactory::getTableData(tableName: 'token_fds');

                            foreach ($tokenFdsData as $fd) {
                                if ($this->server->isEstablished((int) $fd['fd'])) {
                                    $this->server->push($fd['fd'], $data, WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
                                }
                            }

                        } else {
                            var_dump('Refinitiv token API call failed', ['response' => $refToken]);
                            $this->dbFacade->rollBackTransaction($postgresClient);
                            throw new \RuntimeException('Refinitiv token API call failed' . json_encode($refToken));
                        }
                    }

                    $this->dbFacade->commitTransaction($postgresClient);
                } catch (\Exception $e) {
                    $this->dbFacade->rollBackTransaction($postgresClient);
                    // Display detailed error message and stack trace
                    var_dump('Refinitiv token API call failed', [
                        'error_message' => $e->getMessage(),
                        'stack_trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }

            return $token;
        }

        // Check if the token has expired or if a refresh is requested
        if (
            (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= $token['expires_in']) // Token is expired
            ||
            ($refresh != '' && $token && $token['refresh_token']) // A refresh was requested
        ) {
            // If the environment is production, refresh the token using the API
            if (config('app_config.env') !== 'local' && config('app_config.env') !== 'staging') { // If token was Expired on Production
                $refToken = null;
                $token = null;

                try {
                    // Get Postgres Client
                    $postgresClient = $this->dbFacade->getClient($this->objDbPool);

                    // Begin the transaction
                    $this->dbFacade->beginTransaction($postgresClient);

                    // Lock the table to prevent concurrent operations
                    $this->dbFacade->lockTable($postgresClient, 'refinitiv_auth_tokens');

                    $token = $this->getRefTokenFromDB();
                    $token_id = $token["id"];

                    // Attempt to refresh the token using the refresh token
                    if (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= $token['expires_in']) {

                        $refToken = $this->fetchRefRefreshTokenFromRefAPI($token['refresh_token']);

                        // If the refresh token is also expired, get a new token without using the refresh token
                        if (isset($refToken['error'])) {
                            var_dump('If the refresh token is also expired, get a new token request failed', ['response' => $refToken]);
                            $refToken = $this->fetchRefTokenFromRefAPI();
                        }

                        if (!isset($refToken['error'])) {
                            $refToken = json_decode($refToken['response']);
                            $accessToken = $refToken->access_token;
                            $refreshToken = $refToken->refresh_token;
                            $expiresIn = $refToken->expires_in;

                            $dateTime = Carbon::now()->format('Y-m-d H:i:s');
                            $createdAt = $dateTime;
                            $updatedAt = $dateTime;

                            // Update the token in the database with the new values
                            $this->updateIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt,  $token_id);

                            $token = [
                                'access_token' => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expires_in' => $expiresIn,
                                'updated_at' => $updatedAt,
                            ];

                            $data = $token;
                            $data = json_encode($data);

                            $tokenFdsData =  SwooleTableFactory::getTableData(tableName: 'token_fds');

                            foreach ($tokenFdsData as $fd) {
                                if ($this->server->isEstablished((int) $fd['fd'])) {
                                    $this->server->push($fd['fd'], $data);
                                }
                            }

                        } else {
                            var_dump('Refinitive Update token failed on prod ', ['response' => $refToken]);
                            $this->dbFacade->rollBackTransaction($postgresClient);
                            throw new \RuntimeException('Refinitive Update token failed on prod ' . json_encode($refToken));
                        }
                    }

                    $this->dbFacade->commitTransaction( $postgresClient);
                } catch (\Exception $e) {
                    $this->dbFacade->rollBackTransaction( $postgresClient);
                    // Display detailed error message and stack trace
                    var_dump('Refinitiv update token API call failed', [
                        'error_message' => $e->getMessage(),
                        'stack_trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }
        }

        return $token;
    }

    public function getRefTokenFromDB()
    {
        $tableName = 'refinitiv_auth_tokens';

        $dbQuery = "SELECT * FROM $tableName LIMIT 1";
        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
        $result = null;

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

    function fetchRefTokenFromRefAPI(): array
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        $data = [
            'grant_type' => $this->grantType,
            'username' => $this->username,
            'password' => $this->password,
            'scope' => $this->scope,
            'TakeExclusiveSignOnControl' => $this->takeExclusiveSignOnControl,
            'client_id' => $this->clientId,
        ];

        $endpoint = $this->url;
        $data = http_build_query($data);

        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);
        $token = $apiConsumer->request('HTTP', 'POST', $data);
        unset($apiConsumer);
        return $token;
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

    function fetchRefRefreshTokenFromRefAPI($refreshToken)
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':')
        ];

        $data = [
            'refresh_token' => $refreshToken,
            'grant_type' => $this->refreshGrantType,
            'username' => $this->username,
        ];

        $endpoint = $this->url;
        $data = http_build_query($data);

        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);
        $token = $apiConsumer->request('HTTP', 'POST', $data);

        unset($apiConsumer);

        return $token;
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

}
