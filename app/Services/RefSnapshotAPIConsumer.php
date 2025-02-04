<?php

namespace App\Services;

use Swoole\Coroutine\Barrier;
use Swoole\Coroutine;
use App\Core\Services\APIConsumer;
use Carbon\Carbon;
use Throwable;
use Swoole\Coroutine\Channel;

class RefSnapshotAPIConsumer
{
    protected $webSocketServer;
    protected $objDbPool;
    protected $chunkSize;
    protected $dbFacade;
    protected $mAIndicatorsData = [];
    protected $authCounter = 0;
    protected $tooManyRequestCounter = 0;
    protected $retry;
    protected $url;
    protected $timeout;

    public function __construct($webSocketServer, $objDbPool, $dbFacade, $url)
    {
        $this->webSocketServer = $webSocketServer;
        $this->objDbPool = $objDbPool;
        $this->dbFacade = $dbFacade;
        $this->retry = config('app_config.api_calls_retry');
        $this->url = $url;
        $this->timeout = config('app_config.api_req_timeout');
        $this->chunkSize = config('ref_config.ref_chunk_size');
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
            $channel = new Channel(1);
            go(function () use ( $dbQuery, $channel) {
                try {
                    $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                    $channel->push($result);
                } catch (Throwable $e) {
                    output($e);
                }
            });

            $results = $channel->pop();

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
        $tokenRec = $this->getRefTokenRecord();

        if($tokenRec) {
            $refAccessToken = $tokenRec['access_token'];
        } else {
            $refAccessToken = $tokenRec;
        }

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
                $this->sendRequest($queryParams, $refAccessToken, $mAIndicatorBarrier);
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
    public function getRefTokenRecord()
    {
        $dbQuery = "SELECT * FROM refinitiv_auth_token_sw LIMIT 1";

        $channel = new Channel(1);

        go(function () use ($dbQuery, $channel) {
            try {
                $try = 0;
                do {
                    $tokenRec = $this->dbFacade->query($dbQuery, $this->objDbPool);
                    $tokenRec =  $tokenRec ? ($tokenRec[0] ?? false) : false;
                    if ($tokenRec) {
                        if (!(Carbon::now()->timestamp - Carbon::parse($tokenRec['updated_at'])->timestamp >= ($tokenRec['expires_in'] - 60))) {
                            $channel->push($tokenRec);
                            break;
                        }
                    }

                    Coroutine::sleep(1);
                    $try++;
                } while ($try < 5);

                if ($try == 5) {
                    $channel->push(0);
                }
            } catch (Throwable $e) {
                output($e);
            }
        });

        $result = $channel->pop();

        return $result == 0 ? false : $result;
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

    public function sendRequest($queryParams, $accessToken, $mAIndicatorBarrier)
    {
        go(function () use ($queryParams, $accessToken, $mAIndicatorBarrier) {
             $overAllRepCounter = 0;
            do {

                do { // Check for status code 401 (Unauthorized)
                    // Fetch the data for this chunk
                    $responseData = $this->getIndicatorsData($accessToken, $queryParams);
                    $statusCode = $responseData['status_code'];
                    $response = $responseData['response'];

                    if ($statusCode == 401) {
                        var_dump('Swoole-serv: Unauthorized: Invalid token or session has expired.');
                        Coroutine::sleep(0.7);
                        $token = $this->getRefTokenRecord();
                        if($token) {
                            $accessToken = $token['access_token'];
                        } else {
                            $accessToken = $token;
                        }
                        $this->authCounter++;
                    } else {
                        break;
                    }
                } while ($this->authCounter <  $this->retry);

                if ($this->authCounter >=  $this->retry) {
                    $this->authCounter = 0;
                    var_dump('Swoole-serv: Unauthorized: Retry limit reached.');
                }

                if ($statusCode === 429) { // Check for status code 429 (Too Many Requests)

                    do {
                        $this->tooManyRequestCounter++;
                        $responseData = $this->getIndicatorsData($accessToken, $queryParams);
                        $statusCode = $responseData['status_code'];
                        $response = $responseData['response'];

                        if ($statusCode == 429) {
                            var_dump('Swoole-serv: Too Many Requests: failed.');
                            Coroutine::sleep($this->tooManyRequestCounter + 1);
                            $this->tooManyRequestCounter++;
                        } else {
                            break;
                        }

                    } while($this->tooManyRequestCounter <  $this->retry);

                    if ($this->tooManyRequestCounter >=  $this->retry) {
                        $this->tooManyRequestCounter = 0;
                        var_dump('Swoole-serv: Too many requests: Retry limit reached.');
                    }
                }

                if ($statusCode === 200) { // Return
                    $res = json_decode($response, true);
                    $this->mAIndicatorsData = array_merge($this->mAIndicatorsData, $res);
                } else {
                    var_dump('Swoole-serv: Invalid repsonse from Refinitive Snapshot API', $response);
                }


                if ($statusCode > 299) {
                    Coroutine::sleep(1);
                    $overAllRepCounter++;
                    if($overAllRepCounter == 2) {
                        var_dump('Swoole-serv: Overall retries limit exceeded');
                    }
                } else {
                    break;
                }
                // $statusCode > 299
            } while($overAllRepCounter < 2 && $statusCode > 299);
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
