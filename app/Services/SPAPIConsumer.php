<?php

namespace App\Services;

use Swoole\Coroutine\Barrier;
use Swoole\Coroutine;
use App\Core\Services\APIConsumer;
use Carbon\Carbon;
use Throwable;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine as Co;

class SPAPIConsumer
{
    protected $webSocketServer;
    protected $dbConnectionPools;
    protected $chunkSize;
    protected $dbFacade;
    protected $tooManyRequestCounter = 0;
    protected $retry;
    protected $url;
    protected $timeout;
    protected $date;
    protected $authToken;

    public function __construct($webSocketServer, $dbConnectionPools, $dbFacade, $url)
    {
        $this->webSocketServer = $webSocketServer;
        $this->dbConnectionPools = $dbConnectionPools;
        $this->dbFacade = $dbFacade;
        $this->retry = config('app_config.api_calls_retry');
        $this->url = $url;
        $this->timeout = config('app_config.api_req_timeout');
        $this->chunkSize = config('spg_config.sp_chunck_size');
        $this->date = Carbon::now()->format('m/d/Y');
        $username = config('spg_config.sp_global_api_user');
        $secret = config('spg_config.sp_global_api_secret');
        $this->authToken = base64_encode("$username:$secret");
    }

    public function handle($companies = null, $fields)
    {
        if (!$companies) {
            $dbQuery = "SELECT ric FROM companies
            WHERE sp_comp_id IS NOT NULL";

            // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
            $channel = new Channel(1);
            go(function () use ($dbQuery, $channel) {
                try {
                    $result = $this->dbFacade->query($dbQuery, $this->dbConnectionPools);
                    $channel->push($result);
                } catch (Throwable $e) {
                    output($e);
                }
            });

            $companies = $channel->pop();
        }

        if (!empty($companies)) {
            $companiesChunks =  array_chunk($companies, $this->chunkSize);

            $SPIndicatorBarrier = Barrier::make();
            $responsesChannel = new Channel(count($companies)*2);

            foreach ($companiesChunks as $companiesChunk) {
                $spgRequests['inputRequests'] = [];
                foreach ($companiesChunk as $company) {

                    foreach ($fields as $field) {
                        $spgRequests['inputRequests'][] = [
                            'function' => 'GDSP',
                            'mnemonic' => $field,
                            'identifier' => $company['sp_comp_id'],
                            'properties' => [
                                'asOfDate' => $this->date,
                                'currencyId' => 'SAR',
                            ],
                        ];
                    }
                    $requests_body = json_encode($spgRequests);
                }

                $this->sendRequest($requests_body, $responsesChannel, $SPIndicatorBarrier);
            }


            Barrier::wait($SPIndicatorBarrier);

            $responses = [];

            while (!$responsesChannel->isEmpty()) {
                $responses[] = $responsesChannel->pop();
            }

            return $responses;
        }
        output("There is an issue, the companies do not exist in the database.");
        return array();
    }

    /**
     * Get the dat from SP
     *
     * @param  string $requestBody json encoded
     * @return array A json encoded array of sp data
     */
    function getIndicatorsData(string $requestBody): array
    {
        $headers = $this->getHeaders($requestBody);
        $endpoint = $this->url;
        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);

        return $apiConsumer->request('HTTP', 'POST', $requestBody);
    }

    public function sendRequest($queryParams, $responsesChannel, $SPIndicatorBarrier)
    {
        go(function () use ($queryParams, $responsesChannel, $SPIndicatorBarrier) {

            $overAllRepCounter = 0;
            do {
                // Fetch the data for this chunk
                $responseData = $this->getIndicatorsData($queryParams);
                $statusCode = $responseData['status_code'];
                $responses = $responseData['response'];

                if ($statusCode === 401) {
                    output('Unauthorized: Invalid username or password.');
                    output($responses);
                }

                if ($statusCode === 429) { // Check for status code 429 (Too Many Requests)

                    do {
                        $this->tooManyRequestCounter++;
                        $responseData = $this->getIndicatorsData($queryParams);
                        $statusCode = $responseData['status_code'];
                        $responses = $responseData['response'];

                        if ($statusCode == 429) {
                            output('Swoole-serv: Too Many Requests: failed.');
                            Coroutine::sleep($this->tooManyRequestCounter + 1);
                            $this->tooManyRequestCounter++;
                        } else {
                            break;
                        }
                    } while ($this->tooManyRequestCounter <  $this->retry);

                    if ($this->tooManyRequestCounter >=  $this->retry) {
                        $this->tooManyRequestCounter = 0;
                        output('Swoole-serv: Too many requests: Retry limit reached.');
                    }
                }

                if ($statusCode === 200) { // Return
                    $responses = json_decode($responses, true);

                    if ($responses) {
                        foreach($responses['GDSSDKResponse'] as $response) {
                            $responsesChannel->push($response);
                        }
                    }
                } else {
                    output('Swoole-serv: Invalid repsonse from S&P API : ');
                    output($responses);
                }

                if ($statusCode > 299) {
                    Coroutine::sleep(1);
                    $overAllRepCounter++;
                    if ($overAllRepCounter == 2) {
                        output('Swoole-serv: Overall retries limit exceeded');
                    }
                } else {
                    break;
                }
            } while ($overAllRepCounter < 2 && $statusCode > 299);
        });
    }

    function getHeaders($requestBody)
    {
        return [
            'Authorization' => 'Basic ' . $this->authToken,
            'Connection' => 'keep-alive',
            'Content-Length' => strlen($requestBody),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function __destruct() {}
}
