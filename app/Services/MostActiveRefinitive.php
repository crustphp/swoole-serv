<?php

namespace App\Services;

use DB\DbFacade;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

class MostActiveRefinitive
{
    protected $webSocketServer;
    protected $dbConnectionPools;
    protected $postgresDbKey;
    protected $mySqlDbKey;
    protected $muasheratUserToken;
    protected $chunkSize = 100;

    const FIELDS = 'CF_VOLUME,NUM_MOVES,PCTCHNG,TRDPRC_1,TURNOVER';

    public function __construct($webSocketServer, $dbConnectionPools, $postgresDbKey = null, $mySqlDbKey = null)
    {
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
        $this->webSocketServer = $webSocketServer;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->mySqlDbKey = $mySqlDbKey ?? $swoole_mysql_db_key;

        // Change this token ('Add Authentication Token here of any staging user')
        $this->muasheratUserToken = $_ENV['STAGING_USER_TOKEN'];
    }

    public function handle()
    {
        // Database connection pool and channel initialization
        $objDbPool = $this->dbConnectionPools[$this->postgresDbKey];

        $tokenCompanyBerrier = Barrier::make();
        $companiesRics = new Channel(1);
        $refinitivToken = new Channel(1);
        // Coroutine to fetch company RICs from the database
        go(function () use ($objDbPool, $companiesRics, $tokenCompanyBerrier) {
            $dbQuery = "SELECT ric FROM companies WHERE ric IS NOT NULL AND ric NOT LIKE '%^%'";
            $dbFacade = new DbFacade();
            $rics = $dbFacade->query($dbQuery, $objDbPool);

            // Split the RICs into chunks and push to the channel
            $chunkedRics = array_column($rics, 'ric');
            if ($chunkedRics) {
                $companiesRics->push($chunkedRics);
            }
        });

        // Coroutine to fetch the Refinitiv Auth Token
        go(function () use ($refinitivToken, $tokenCompanyBerrier) {
            $token = $this->getRefinitivToken();
            $refinitivToken->push($token);
        });

        Barrier::wait($tokenCompanyBerrier);

        if (!$companiesRics->isEmpty() && !$refinitivToken->isEmpty()) {
            $ricsChunks =  array_chunk($companiesRics->pop(), $this->chunkSize);
            $accessToken = $refinitivToken->pop();

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

            $mostActiveBarrier = Barrier::make();
            $mostActiveData = [];
            // Process each chunk asynchronously using coroutines
            foreach ($ricsChunks as $chunk) {
                go(function () use ($chunk, $queryParams, $accessToken, &$mostActiveData, $mostActiveBarrier) {
                    $queryParams['universe'] = '/' . implode(',/', $chunk);
                    // Fetch the data for this chunk
                    $response = $this->getMostActiveData($accessToken, $queryParams);
                    $response = json_decode($response, true);
                    $mostActiveData = array_merge($mostActiveData, $response);
                });
            }

            Barrier::wait($mostActiveBarrier);

            return $mostActiveData;
        }
        throw new \RuntimeException('Failed to retrieve data of most active.');
    }



    /**
     * Get the refinitiv access token from staging
     *
     * @return string
     */
    function getRefinitivToken(): string
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

        $token_record = json_decode($token_record);
        return $token_record->access_token;
    }

    /**
     * Get the most active from Refinitiv
     *
     * @param  string $token
     * @param  array $queryParams
     * @return string A json encoded string of snapshot data
     */
    function getMostActiveData(string $token, array $queryParams): string
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

        $client->close();

        return $response;
    }

    public function __destruct() {

    }
}
