<?php

namespace App\Services;


use Carbon\Carbon;
use App\Core\Services\APIConsumer;
use Bootstrap\SwooleTableFactory;
use Throwable;
use App\Core\Services\PdoService;


class RefToken
{
    protected $passwordGrantType;
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

    protected $pdoClient;
    protected $refreshTokenExpired;

    public function __construct($server)
    {
        $this->passwordGrantType = config('ref_config.grant_type');
        $this->username = config('ref_config.username');
        $this->password = config('ref_config.password');
        $this->scope = config('ref_config.scope');
        $this->takeExclusiveSignOnControl = config('ref_config.take_exclusive_sign_on_control');
        $this->clientId = config('ref_config.client_id');
        $this->refreshGrantType = config('ref_config.refresh_grant_type');
        $this->url = config('ref_config.url');
        $this->timeout = config('app_config.api_req_timeout');

        $this->server = $server;

        $this->refreshTokenExpired = false;
    }

    public function produceActiveToken($refresh = '')
    {
        $pdoService = new PdoService();
        // Retrieve the first RefinitivAuthToken record from the database
        $token = $this->getRefTokenFromDB($pdoService);
        // If no token is found, proceed to obtain a new one
        if (!$token) {

            if (config('app_config.env') != 'local' && config('app_config.env') != 'staging') {
                try {
                    // Begin the transaction
                    $pdoService->beginTransaction();

                    // Lock the table to prevent concurrent operations
                    $pdoService->lockTable('refinitiv_auth_token_sw');

                    $token = $this->getRefTokenFromDB($pdoService);

                    if (!$token) {
                        $refToken = $this->getPasswordGrantTokenFromRefAPI(true);


                        if (!isset($refToken['error'])) {
                            $refToken = json_decode($refToken['response']);
                            $accessToken = $refToken->access_token;
                            $refreshToken = $refToken->refresh_token;
                            $expiresIn = $refToken->expires_in;

                            $dateTime = Carbon::now()->format('Y-m-d H:i:s');
                            $createdAt = $dateTime;
                            $updatedAt = $dateTime;

                            $token = [
                                'access_token' => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expires_in' => $expiresIn,
                                'updated_at' => $updatedAt,
                            ];

                            go(function () use ($token, $createdAt, $pdoService) {
                                $this->insertIntoRefAuthTable($token['access_token'], $token['refresh_token'], $token['expires_in'], $createdAt, $token['updated_at'], $pdoService);
                                $pdoService->commitTransaction();
                            });

                            go(function () use ($token) {
                                $data = json_encode($token);

                                $tokenFdsData =  SwooleTableFactory::getTableData(tableName: 'token_fds');

                                foreach ($tokenFdsData as $fd) {
                                    $fd = (int) $fd['fd'];
                                    // To check this loop if it's working
                                    if ($this->server->isEstablished($fd)) {
                                        $this->server->push($fd, $data, WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
                                    }
                                }
                            });
                        } else {
                            $pdoService->rollBackTransaction();
                            var_dump('Refinitiv token API call failed', ['response' => $refToken]);
                        }
                    }
                } catch (\Exception $e) {
                    $pdoService->rollBackTransaction();
                    // Display detailed error message and stack trace
                    var_dump('Refinitiv token API call failed', [
                        'error_message' => $e->getMessage(),
                        'stack_trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }
            unset($pdoService);

            return $token;
        }
        output('Check if the token has expired or if a refresh is requested');
        // Check if the token has expired or if a refresh is requested
        if (
            (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= ($token['expires_in'] - 60)) // Token is expired
            // ||
            // ($refresh != '' && $token && $token['refresh_token']) // A refresh was requested
        ) {
            // If the environment is production, refresh the token using the API
            if (config('app_config.env') !== 'local' && config('app_config.env') !== 'staging') { // If token was Expired on Production
                $refToken = null;
                $token = null;

                try {
                    // Begin the transaction
                    $pdoService->beginTransaction();

                    // Lock the table to prevent concurrent operations
                    $pdoService->lockTable('refinitiv_auth_token_sw');

                    $token = $this->getRefTokenFromDB($pdoService);
                    $token_id = $token["id"];


                    // Attempt to refresh the token using the refresh token
                    if (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= ($token['expires_in'] - 60)) {

                        if (Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= ($token['expires_in'] + 240)) {
                            $this->refreshTokenExpired = true;
                        }

                        if (!$this->refreshTokenExpired) {
                            $refToken = $this->getRefreshGrantTokenFromRefAPI($token['refresh_token']);
                        }

                        // If the refresh token is also expired, get a new token without using the refresh token
                        if ($this->refreshTokenExpired || isset($refToken['error'])) {
                            $this->refreshTokenExpired = true;
                            // var_dump('Debug backtrace ', debug_backtrace());
                            var_dump('If the refresh token is also expired, get a new token request failed', ['response' => $refToken]);
                            $refToken = $this->getPasswordGrantTokenFromRefAPI(true);
                        }

                        if (!isset($refToken['error'])) {
                            $this->refreshTokenExpired = false;
                            $refToken = json_decode($refToken['response']);
                            $accessToken = $refToken->access_token;
                            $refreshToken = $refToken->refresh_token;
                            $expiresIn = $refToken->expires_in;

                            $dateTime = Carbon::now()->format('Y-m-d H:i:s');
                            $createdAt = $dateTime;
                            $updatedAt = $dateTime;

                            $token = [
                                'access_token' => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expires_in' => $expiresIn,
                                'updated_at' => $updatedAt,
                            ];

                            go(function () use ($token, $createdAt, $token_id, $pdoService) {
                                // Update the token in the database with the new values
                                $this->updateIntoRefAuthTable($token['access_token'], $token['refresh_token'], $token['expires_in'], $createdAt, $token['updated_at'], $token_id, $pdoService);
                                $pdoService->commitTransaction();
                            });

                            go(function () use ($token) {
                                $data = json_encode($token);

                                $tokenFdsData =  SwooleTableFactory::getTableData(tableName: 'token_fds');

                                foreach ($tokenFdsData as $fd) {
                                    if ($this->server->isEstablished((int) $fd['fd'])) {
                                        $this->server->push($fd['fd'], $data);
                                    }
                                }
                            });
                        } else {
                            // var_dump('Debug backtrace ', debug_backtrace());
                            $pdoService->rollBackTransaction();
                            var_dump('Refinitive Update token failed on prod ', ['response' => $refToken]);
                            $token = null;
                        }
                    }
                } catch (\Exception $e) {
                    $pdoService->rollBackTransaction();
                    // Display detailed error message and stack trace
                    var_dump('Refinitiv update token API call failed', [
                        'error_message' => $e->getMessage(),
                        'stack_trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }
        }
        unset($pdoService);

        return $token;
    }

    public function getRefTokenFromDB($pdoService)
    {
        $dbQuery = "SELECT * FROM refinitiv_auth_token_sw LIMIT 1";

        try {
            $result = $pdoService->query($dbQuery);
        } catch (Throwable $e) {
            unset($pdoService);
            output($e);
        }
        return $result ? $result[0] : null;
    }

    function getPasswordGrantTokenFromRefAPI($takeExclusiveSignOnControl = false): array
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        $data = [
            'grant_type' => $this->passwordGrantType,
            'username' => $this->username,
            'password' => $this->password,
            'scope' => $this->scope,
            'takeExclusiveSignOnControl' =>  ($takeExclusiveSignOnControl ? 'true' : 'false'),
            'client_id' => $this->clientId,
        ];

        $endpoint = $this->url;
        $data = http_build_query($data);

        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);
        $token = $apiConsumer->request('HTTP', 'POST', $data);
        unset($apiConsumer);
        return $token;
    }

    function insertIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt, $pdoService)
    {
        $insertQuery = "INSERT INTO refinitiv_auth_token_sw (access_token, refresh_token, expires_in, created_at, updated_at)
                VALUES (:access_token, :refresh_token, :expires_in, :created_at, :updated_at)";

        $params = ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => $expiresIn, 'created_at' => $createdAt, 'updated_at' => $updatedAt];

        try {
            $pdoService->query($insertQuery, $params);
        } catch (Throwable $e) {
            unset($pdoService);
            output($e);
        }
    }

    function getRefreshGrantTokenFromRefAPI($refreshToken)
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

    function updateIntoRefAuthTable($accessToken, $refreshToken, $expiresIn, $createdAt, $updatedAt, $tokenId, $pdoService)
    {

        $updateQuery = "update refinitiv_auth_token_sw  set access_token = :access_token, refresh_token = :refresh_token, expires_in = :expires_in, created_at = :created_at, updated_at = :updated_at where id = :id";
        $params = ["id" => $tokenId, 'access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => $expiresIn,  'created_at' => $createdAt, 'updated_at' => $updatedAt];

        try {
            $pdoService->query($updateQuery, $params);
        } catch (Throwable $e) {
            unset($pdoService);
            output($e);
        }
    }
}
