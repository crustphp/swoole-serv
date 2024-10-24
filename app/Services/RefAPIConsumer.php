<?php

namespace App\Services;

use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine;

class RefAPIConsumer
{
    protected $webSocketServer;
    protected $dbConnectionPools;
    protected $muasheratUserToken;
    protected $chunkSize = 100;
    protected $dbFacade;
    protected $mAIndicatorsData = [];
    protected $authCounter = 0;
    protected $tooManyRequestCounter = 0;
    protected $retry;

    const FIELDS = 'CF_VOLUME,NUM_MOVES,PCTCHNG,TRDPRC_1,TURNOVER';

    public function __construct($webSocketServer, $dbConnectionPools, $dbFacade)
    {
        $this->webSocketServer = $webSocketServer;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->dbFacade = $dbFacade;
        // Change this token ('Add Authentication Token here of any staging user')
        $this->muasheratUserToken = $_ENV['STAGING_USER_TOKEN'];
        $this->retry =  config('app_config.refinitv_retry');
    }

    public function handle($companies = null)
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

            // Process the results: create an associative array with 'ric' as the key and 'id' as the value
            foreach ($results as $row) {
                $companiesRics[$row['ric']] = $row;
            }

            $companiesRics = array_column($companiesRics, 'ric');
        }

        // Fetch Refinitive access token
        $refAccessToken = $this->getRefToken();

        $refAccessToken = json_decode($refAccessToken);
        // Check if access_token is set in the refAccessToken object
        $refAccessToken = isset($refAccessToken->access_token) ? $refAccessToken->access_token : "";

        if (!empty($companiesRics) && !empty($refAccessToken)) {
            $ricsChunks =  array_chunk($companiesRics, $this->chunkSize);

            // Proceed if both RICs and token are available
            $queryParams = [
                "fields" => self::FIELDS,
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
        throw new \RuntimeException('Failed to retrieve data of most active.');
    }



    /**
     * Get the refinitiv access token from staging
     *
     * @return string
     */
    function getRefToken(): string
    {
        $host =  config('app_config.app_url');
        $port = 443; // This must be changed to port 80 if using http, instead of https.
        $endpoint = '/api/get-refinitive-token';

        $client = new Client($host, $port, true);

        // https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
        $client->set(['timeout' => config('app_config.refinitiv_req_timeout')]);

        // Better form to set header
        // Few Headers are commented as the client is fetching the data even without specifying these headers
        $headers = [
            'Host' => config('app_config.app_url'),
            'Connection' => 'keep-alive\r\n',
            'Cache-Control' => 'max-age=0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml, application.json;q=0.9,*/*;q=0.8',
            'User-Agent' => 'swoole-http-client',
            'Authorization' => 'Bearer ' . $this->muasheratUserToken,
        ];

        $client->setHeaders($headers);

        $client->get($endpoint);

        // Read Response
        $token_record = $client->body;

        if ($client->statusCode != 200) {
            echo PHP_EOL;
            echo '\n Connection Related Error Code';
            var_dump($client->errCode);

            echo PHP_EOL;
            echo '\nConnection Related ErrorMessage';
            var_dump($client->errMsg);

            echo PHP_EOL;
            echo '\n Response Status Code:';
            var_dump($client->statusCode);
        }

        $client->close();

       return $token_record;
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
        // var_dump('getMostActiveData function start');
        // Here I will use parse_url() function of PHP
        $apiEndpoint = "https://api.refinitiv.com/data/pricing/snapshots/v1/";

        // Add Query Params to URL
        $apiEndpoint .= '?' . http_build_query($queryParams);

        // parse_url function return various components of the url e.g host, scheme, port, path, query, fragment
        // not necessarily returns all the components, it depends upon the url you are passing
        // Find details of parse url function: https://www.php.net/manual/en/function.parse-url.php
        $parsedUrl = parse_url($apiEndpoint);

        // in this url we get following 3 components when parsed
        // array:3 [
        //     "scheme" => "https"
        //     "host" => "api.refinitiv.com"
        //     "path" => "/data/pricing/snapshots/v1/"
        //     "query" => "fields=CF_VOLUME,NUM_MOVES,PCTCHNG,TRDPRC_1,TURNOVER ....";
        // ];

        $isHttps = ($parsedUrl['scheme'] === 'https');
        $port = $isHttps ? 443 : 80; // Port 443 for https and ssl should be true. For http use port 80 with ssl equals false

        // Client Constructor Params: host, port, ssl
        $client = new Client($parsedUrl['host'], $port, $isHttps);

        // OpenSwoole: https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
        // Swoole: https://wiki.swoole.com/en/#/client?id=configuration
        $client->set(['timeout' => config('app_config.refinitiv_req_timeout')]);


        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $client->setHeaders($headers);

        // Get call - Method 1
        $client->get($apiEndpoint);

        // The following code for sending get request also works
        // Example code for this method:
        // Swoole: https://wiki.swoole.com/en/#/coroutine_client/http_client?id=execute

        // Get call - Method 2
        // $client->setMethod('GET');
        // $status = $client->execute($apiEndpoint);
        // echo PHP_EOL.'STATUS: ';
        // var_dump($status);

        // Read Response
        $response = $client->body;

        if ($client->statusCode != 200) {
            echo PHP_EOL;
            echo '\n Connection Related Error Code';
            var_dump($client->errCode);

            echo PHP_EOL;
            echo '\nConnection Related ErrorMessage';
            var_dump($client->errMsg);

            echo PHP_EOL;
            echo '\n Response Status Code:';
            var_dump($client->statusCode);
        }

        $statusCode = $client->statusCode;

        $client->close();

        return [
            'status_code' => $statusCode,
            'response' => $response,
        ];
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


    public function __destruct() {}
}
