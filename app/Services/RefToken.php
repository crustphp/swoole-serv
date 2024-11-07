<?php

namespace App\Services;

use DB\DBConnectionPool;
use DB\DbFacade;
use Carbon\Carbon;
use App\Core\Services\APIConsumer;

class RefToken
{
    protected $dbFacade;
    protected $objDbPool;
    protected $dbConnectionPools;
    protected $postgresDbKey;
    protected $workerId;
    protected $grantType;
    protected $username;
    protected $password;
    protected $scope;
    protected $takeExclusiveSignOnControl;
    protected $clientId;
    protected $refreshGrantType;
    protected $url;
    protected $timeout;

    public function __construct()
    {
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $this->postgresDbKey = $swoole_pg_db_key;
        $this->workerId = $GLOBALS['process_id'];

        $app_type_database_driven = config('app_config.app_type_database_driven');
        if ($app_type_database_driven) {
            try {
                // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                $this->dbConnectionPools[$this->workerId][$swoole_pg_db_key] = new DBConnectionPool($this->workerId, 'postgres', 'swoole', true);
                $this->dbConnectionPools[$this->workerId][$swoole_pg_db_key]->create();
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }
        }

        $this->objDbPool = $this->dbConnectionPools[$this->workerId][$swoole_pg_db_key];
        $this->dbFacade = new DbFacade();

        $this->grantType = config('ref_config.grant_type');
        $this->username = config('ref_config.username');
        $this->password = config('ref_config.password');
        $this->scope = config('ref_config.scope');
        $this->takeExclusiveSignOnControl = config('ref_config.take_exclusive_sign_on_control');
        $this->clientId = config('ref_config.client_id');
        $this->refreshGrantType = config('ref_config.refresh_grant_type');
        $this->url = config('ref_config.url');
        $this->timeout = config('app_config.refinitiv_req_timeout');
    }

    public function getToken( $refresh = '')
    {
        // Retrieve the first RefinitivAuthToken record from the database
        $token = $this->getRefTokenFromDB();
        // If no token is found, proceed to obtain a new one
        if (!$token) {

            if (config('app_config.env') != 'local' && config('app_config.env') != 'staging') {
                try {
                    // Begin the transaction
                    $this->dbFacade->beginTransaction($this->objDbPool);

                    // Lock the table to prevent concurrent operations
                    $this->dbFacade->lockTable($this->objDbPool, 'refinitiv_auth_tokens');

                    $token = $this->getRefTokenFromDB();

                    if (!$token) {
                        $refToken = $this->fetchRefTokenFromRefAPI();

                        if (!isset($refToken['error'])) {
                            $refToken = $refToken['response'];
                            $accessToken = $refToken['access_token'];
                            $refreshToken = $refToken['refresh_token'];
                            $expiresIn = $refToken['expires_in'];

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
                        } else {
                            var_dump('Refinitiv token API call failed', ['response' => $refToken]);
                            $this->dbFacade->rollBackTransaction($this->objDbPool);
                            throw new \RuntimeException('Refinitiv token API call failed' . json_encode($refToken));
                        }
                    }

                    $this->dbFacade->commitTransaction($this->objDbPool);
                } catch (\Exception $e) {
                    $this->dbFacade->rollBackTransaction($this->objDbPool);
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
                    // Begin the transaction
                    $this->dbFacade->beginTransaction($this->objDbPool);

                    // Lock the table to prevent concurrent operations
                    $this->dbFacade->lockTable($this->objDbPool, 'refinitiv_auth_tokens');

                    $token = $this->getRefTokenFromDB();

                    // Attempt to refresh the token using the refresh token
                    if (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= $token['expires_in']) {
                        $refToken = $this->fetchRefRefreshTokenFromRefAPI($token->refresh_token);

                        // If the refresh token is also expired, get a new token without using the refresh token
                        if (isset($refToken['error'])) {
                            var_dump('If the refresh token is also expired, get a new token request failed', ['response' => $refToken]);
                            $refToken = $this->fetchRefTokenFromRefAPI();
                        }

                        if (!isset($refToken['error'])) {
                            $refToken = $refToken['response'];
                            $accessToken = $refToken['access_token'];
                            $refreshToken = $refToken['refresh_token'];
                            $expiresIn = $refToken['expires_in'];

                            $dateTime = Carbon::now()->format('Y-m-d H:i:s');
                            $createdAt = $dateTime;
                            $updatedAt = $dateTime;

                            // Update the token in the database with the new values
                            $this->updateIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt,  $token['id']);

                            $token = [
                                'access_token' => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expires_in' => $expiresIn,
                                'updated_at' => $updatedAt,
                            ];
                        } else {
                            var_dump('Refinitive Update token failed on prod ', ['response' => $refToken]);
                            $this->dbFacade->rollBackTransaction($this->objDbPool);
                            throw new \RuntimeException('Refinitive Update token failed on prod ' . json_encode($refToken));
                        }
                    }

                    $this->dbFacade->commitTransaction($this->objDbPool);
                } catch (\Exception $e) {
                    $this->dbFacade->rollBackTransaction($this->objDbPool);
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
        $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
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
        $token = $apiConsumer->request('POST', $data);
        unset($apiConsumer);
        return $token;
    }

    function insertIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt)
    {
        $insertQuery = "INSERT INTO refinitiv_auth_tokens (access_token, refresh_token, expires_in, created_at, updated_at)
        VALUES ('$accessToken', '$refreshToken', $expiresIn, '$createdAt', '$updatedAt')";

        $this->dbFacade->query($insertQuery, $this->objDbPool);
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
        $token = $apiConsumer->request('POST', $data);

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

        $this->dbFacade->query($updateQuery, $this->objDbPool);
    }

}
