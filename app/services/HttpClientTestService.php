<?php

namespace App\Services;

use DB\DbFacade;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Http\Client;

class HttpClientTestService
{
    protected $webSocketServer;
    protected $frame;
    protected $dbConnectionPools;
    protected $postgresDbKey;
    protected $mySqlDbKey;

    protected $muasheratUserToken;

    const FIELDS = 'CF_HIGH,CF_LAST,CF_LOW,CF_VOLUME,HIGH_1,HST_CLOSE,LOW_1,NETCHNG_1,NUM_MOVES,OPEN_PRC,PCTCHNG,TRDPRC_1,TURNOVER,YRHIGH,YRLOW,YR_PCTCH,CF_CLOSE,BID,ASK,ASKSIZE,BIDSIZE';

    public function __construct($webSocketServer, $frame, $dbConnectionPools, $postgresDbKey = null, $mySqlDbKey = null)
    {
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
        $this->webSocketServer = $webSocketServer;
        $this->frame = $frame;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->mySqlDbKey = $mySqlDbKey ?? $swoole_mysql_db_key;

        // Change this token ('Add Authentication Token here of any staging user')
        $this->muasheratUserToken = $_ENV['STAGING_USER_TOKEN'];
    }

    public function handle()
    {
        // Usecase:
        // We will fetch the company rics from the Database
        // Than we will make chunks of 100 as Refinitiv API can fetch the maximum data of 100 rics/universe.
        // We use execute the refinitiv API asynchronously for all the chunks and push the data to fd.

        $objDbPool = $this->dbConnectionPools[$this->postgresDbKey];
        $companiesRics = new \Swoole\Coroutine\Channel(1);
        $refinitivToken = new \Swoole\Coroutine\Channel(1);
        $barrier = Barrier::make();

        // Fetch the companies RICs from the database
        go(function () use ($companiesRics, $objDbPool, $barrier) {
            $dbQuery = "SELECT ric FROM companies
                WHERE ric IS NOT NULL
                AND ric NOT LIKE '%^%'";

            $dbFacade = new DbFacade();
            $rics = $dbFacade->query($dbQuery, $objDbPool);
            $companiesRics->push(array_column($rics, 'ric'));
        });

        // Fetch the Refinitiv AuthToken
        go(function () use ($refinitivToken, $barrier) {
            $token = $this->getRefinitivToken();
            $refinitivToken->push($token);
        });


        // Wait until we have both Company RICs and Refinitiv Access Token
        Barrier::wait($barrier);

        $ricsChunk = array_chunk($companiesRics->pop(), 100);

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

        $accessToken = $refinitivToken->pop();

        // We will execute each chunk in Corroutine to get data parallelly
        $dataChannel = new \Swoole\Coroutine\Channel(count($ricsChunk));

        // Here we needed to create a new Barrier
        $barrier = new Barrier();
        foreach ($ricsChunk as $chunk) {
            go(function () use ($chunk, $queryParams, $accessToken, $dataChannel, $barrier) {
                $queryParams['universe'] = '/' . implode(',/', $chunk);
                $snapshots = $this->getSnapshotData($accessToken, $queryParams);

                $dataChannel->push($snapshots);
            });
        }

        // Wait until we receive the data from all go()
        Barrier::wait($barrier);

        // Store all data in single variable and send it to FD
        // Using channel->length() to get number of elements in channel:
        // More Details: https://wiki.swoole.com/en/#/coroutine/channel?id=length
        // Avoid using length() directly in a loop, e.g., for ($i = 0; $i < $dataChannel->length(); $i++) {}
        // When using pop() inside the loop, the length decreases, causing the loop to run for fewer iterations than expected.
        $data = [];
        $channelLength = $dataChannel->length();
        for ($i = 0; $i < $channelLength; $i++) {
            $data = array_merge($data, json_decode($dataChannel->pop()));
        }

        if ($this->webSocketServer->isEstablished($this->frame->fd)) {
            $this->webSocketServer->push($this->frame->fd, json_encode($data));
        }
    }


    /**
     * Get the refinitiv access token from staging
     *
     * @return string
     */
    function getRefinitivToken(): string
    {
        $host = 'muasherat.devdksa.com';
        $port = 443; // This must be changed to port 80 if using http, instead of https.
        $endpoint = '/api/get-refinitive-token';

        $client = new Client($host, $port, true);

        // https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
        $client->set(['timeout' => 1]);

        // Better form to set header
        // Few Headers are commented as the client is fetching the data even without specifying these headers
        $headers = [
            'Host' => 'muasherat.devdksa.com',
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

        echo PHP_EOL;
        echo '\n Connection Related Error Code';
        var_dump($client->errCode);

        echo PHP_EOL;
        echo '\nConnection Related ErrorMessage';
        var_dump($client->errMsg);

        echo PHP_EOL;
        echo '\n Response Status Code:';
        var_dump($client->statusCode);

        $client->close();

        $token_record = json_decode($token_record);
        return $token_record->access_token;
    }

    /**
     * Get the pricing snapshots from Refinitiv
     *
     * @param  string $token
     * @param  array $queryParams
     * @return string A json encoded string of snapshot data
     */
    function getSnapshotData(string $token, array $queryParams): string
    {
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
        //     "query" => "fields=CF_HIGH,CF_LAST,CF_LOW,CF_VOLUME,HIGH_1 ....";
        // ];

        $isHttps = ($parsedUrl['scheme'] === 'https');
        $port = $isHttps ? 443 : 80; // Port 443 for https and ssl should be true. For http use port 80 with ssl equals false

        // Client Constructor Params: host, port, ssl
        $client = new Client($parsedUrl['host'], $port, $isHttps);

        // OpenSwoole: https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
        // Swoole: https://wiki.swoole.com/en/#/client?id=configuration
        $client->set(['timeout' => 2]);


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

        echo PHP_EOL;
        echo '\n Connection Related Error Code';
        var_dump($client->errCode);

        echo PHP_EOL;
        echo '\nConnection Related ErrorMessage';
        var_dump($client->errMsg);

        echo PHP_EOL;
        echo '\n Response Status Code:';
        var_dump($client->statusCode);

        $client->close();

        return $response;
    }
}
