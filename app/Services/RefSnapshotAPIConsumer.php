<?php

namespace App\Services;

use Swoole\Coroutine\Barrier;
use Swoole\Coroutine;
use App\Core\Services\APIConsumer;

class RefSnapshotAPIConsumer
{
    protected $webSocketServer;
    protected $dbConnectionPools;
    protected $chunkSize = 100;
    protected $dbFacade;
    protected $mAIndicatorsData = [];
    protected $authCounter = 0;
    protected $tooManyRequestCounter = 0;
    protected $retry;
    protected $url;
    protected $timeout;

    public function __construct($webSocketServer, $dbConnectionPools, $dbFacade, $url)
    {
        $this->webSocketServer = $webSocketServer;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->dbFacade = $dbFacade;
        $this->retry = config('app_config.refinitv_retry');
        $this->url = $url;
        $this->timeout = config('app_config.refinitiv_req_timeout');
    }

    public function handle($companies = null, $fields)
    {
        if ($companies) {
            $companiesRics = array_column($companies, 'ric');
        } else {
            $dbQuery = "SELECT ric FROM companies
            WHERE ric IS NOT NULL
            AND ric NOT LIKE '%^%'
            AND ric ~ '^[0-9a-zA-Z\\.]+$'";

            // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
            $results = $this->dbFacade->query($dbQuery, $this->dbConnectionPools);
            $companiesRics = [];

            if (!empty($results)) {
                // Process the results: create an associative array with 'ric' as the key and 'id' as the value
                foreach ($results as $row) {
                    $companiesRics[$row['ric']] = $row;
                }

                $companiesRics = array_column($companiesRics, 'ric');
            }
        }

        // Fetch Refinitive access token
        $refAccessToken = $this->getRefToken();

        if (!empty($companiesRics) && !empty($refAccessToken)) {
            $ricsChunks =  array_chunk($companiesRics, $this->chunkSize);

            // Proceed if both RICs and token are available
            $queryParams = [
                "fields" => $fields,
                "count" => 1,
                "interval" => "P1D",
                "sessions" => "normal",
                "adjustments" => [
                    "exchangeCorrection",
                    "manualCorrection",
                    "CCH",
                    "CRE",
                    "RTS",
                    "RPO"
                ]
            ];

            $mAIndicatorBarrier = Barrier::make();
            $this->mAIndicatorsData = [];
            $this->authCounter = 0;
            $this->tooManyRequestCounter = 0;
            // Process each chunk asynchronously using coroutines
            foreach ($ricsChunks as $chunk) {
                    $queryParams['universe'] = '/' . implode(',/', $chunk);
                    $this->sendRequest($chunk, $queryParams, $refAccessToken, $mAIndicatorBarrier);
            }

            Barrier::wait($mAIndicatorBarrier);

            return $this->mAIndicatorsData;
        }
        output("There is an issue retrieving the token from the database, or the companies do not exist in the database.");
        return array();
    }

    /**
     * Get the refinitiv access token from staging
     *
     * @return string
     */
    function getRefToken(): string
    {
        $token = new RefToken($this->webSocketServer, $this->dbFacade, $this->dbConnectionPools);
        $refToken = $token->getToken();
        unset($token);

        $refAccessToken = '';

        if ($refToken) {
            $refAccessToken = $refToken['access_token'];
        }

        return $refAccessToken;
    }

    /**
     * Get the most active from Refinitiv
     *
     * @param  string $token
     * @param  array $queryParams
     * @return array A json encoded array of snapshot data
     */
    function getIndicatorsData(string $token, array $queryParams): array
    {
        $headers = $this->getHeaders($token);
        $endpoint = $this->url;
        $endpoint .= '?' . http_build_query($queryParams);
        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);

        return $apiConsumer->request();
    }

    public function sendRequest($chunk, $queryParams, $accessToken, $mAIndicatorBarrier)
    {
        go(function () use ($chunk, $queryParams, $accessToken, $mAIndicatorBarrier) {
            // Fetch the data for this chunk
            $responseData = $this->getIndicatorsData($accessToken, $queryParams);
            $statusCode = $responseData['status_code'];
            $response = $responseData['response'];

            if ($statusCode === 401) { // Check for status code 401 (Unauthorized)
                if ($this->authCounter <  $this->retry) {
                    $this->authCounter++;
                    var_dump('Unauthorized: Invalid token or session has expired.');
                    $token = $this->getRefToken();
                    $this->sendRequest($chunk, $queryParams, $token, $mAIndicatorBarrier);
                } else {
                    var_dump('Unauthorized: Retry limit reached.');
                }
            } else if ($statusCode === 429) { // Check for status code 429 (Too Many Requests)
                if ($this->tooManyRequestCounter <  $this->retry) {
                    $this->tooManyRequestCounter++;
                    var_dump('Too Many Requests: Rate limit exceeded.');
                    Coroutine::sleep($this->tooManyRequestCounter);
                    $this->sendRequest($chunk, $queryParams, $accessToken, $mAIndicatorBarrier);
                } else {
                    var_dump('Too many requests: Retry limit reached.');
                }
            } else if ($statusCode === 200) { // Return
                $res = json_decode($response, true);
                $this->mAIndicatorsData = array_merge($this->mAIndicatorsData, $res);
            } else {
                var_dump('Invalid repsonse from Refinitive Snapshot API', $response);
            }
        });
    }

    function getHeaders($token)
    {
        return [
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-cache',
            'Accept' => 'application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'swoole-http-client',
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    public function __destruct() {}
}
