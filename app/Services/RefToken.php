<?php

namespace App\Services;

use Carbon\Carbon;
use App\Core\Services\APIConsumer;
use Bootstrap\SwooleTableFactory;

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

    protected $refreshTokenExpired;
    protected $refTokenTable;

    /**
     * __construct
     *
     * @param  mixed $server
     * @return void
     */
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
        $this->refTokenTable = SwooleTableFactory::getTable('ref_token_sw', true);
    }

    /**
     * Generate the new Refinitiv token, store it into the Swoole Table and push it to the developers local and staging
     *
     * @param  mixed $token Previous token
     * @return mixed
     */
    public function produceNewToken($token)
    {
        // // Fetch token from swoole
        // $token = $this->refTokenTable->get('1');

            $refToken = null;

            if (empty($token) || Carbon::now()->timestamp - Carbon::parse($token['updated_at'])->timestamp >= ($token['expires_in'] + 240)) {
                $this->refreshTokenExpired = true;
            }

            if (!$this->refreshTokenExpired) {
                $refToken = $this->getRefreshGrantTokenFromRefAPI($token['refresh_token']);
            }

            // If the refresh token is also expired, get a new token without using the refresh token
            if ($this->refreshTokenExpired || isset($refToken['error'])) {
                $this->refreshTokenExpired = true;
                output(__CLASS__ . ' If the refresh token is also expired, get a new token request failed');
                output(['refresh_token_response' => $refToken]);
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

                // Save into the swoole table
                $token['id'] = 1;
                $token['created_at'] = $createdAt;
                $token['updated_by_process'] = cli_get_process_title() ?? "1";
                $this->refTokenTable->set('1', $token);

                go(function () use ($token) {
                    $tokenRec = json_encode($token);

                    $tokenFdsData =  SwooleTableFactory::getTableData(tableName: 'token_fds');

                    foreach ($tokenFdsData as $fd) {
                        if ($this->server->isEstablished((int) $fd['fd'])) {
                            $this->server->push($fd['fd'], $tokenRec);
                        }
                    }
                });
            } else {
                output(__CLASS__ . ' --> Refinitive Update token failed on prod');
                output(['response' => $refToken]);
                $token = null;
            }

        return $token;
    }

    /**
     * Fetch the new Refinitv API-Access Token using "Password Grant"
     *
     * @param  bool $takeExclusiveSignOnControl Clear the current active session and create entirely new Token
     * @return array
     */
    function getPasswordGrantTokenFromRefAPI(bool $takeExclusiveSignOnControl = false): array
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        $data = [
            'grant_type' => $this->passwordGrantType,
            'username' => $this->username,
            'password' => $this->password,
            'scope' => $this->scope,
            'takeExclusiveSignOnControl' => ($takeExclusiveSignOnControl ? 'true' : 'false'),
            'client_id' => $this->clientId,
        ];

        $endpoint = $this->url;
        $data = http_build_query($data);

        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);
        $token = $apiConsumer->request('HTTP', 'POST', $data);
        unset($apiConsumer);
        return $token;
    }

    /**
     * Fetch the new Refinitv API-Access Token using "Refresh Grant" / Refresh-Token-key
     *
     * @param  string $refreshToken The refresh token
     * @return mixed
     */
    function getRefreshGrantTokenFromRefAPI(string $refreshToken): mixed
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
}
