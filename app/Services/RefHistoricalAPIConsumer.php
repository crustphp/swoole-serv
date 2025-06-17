<?php

namespace App\Services;

use App\Constants\LogMessages;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine;
use App\Core\Services\APIConsumer;
use Swoole\Coroutine\Channel;

class RefHistoricalAPIConsumer
{
    protected $webSocketServer;
    protected $authCounter = 0;
    protected $tooManyRequestCounter = 0;
    protected $retry;
    protected $url;
    protected $timeout;
    protected $refTokenLock;


    public function __construct($webSocketServer, $url, $refTokenLock)
    {
        $this->webSocketServer = $webSocketServer;
        $this->retry = config('app_config.api_calls_retry');
        $this->url = $url;
        $this->timeout = config('app_config.api_req_timeout');
        $this->refTokenLock = $refTokenLock;
    }

    /**
     * Fetches indicator historical data for the given module using Refinitiv API.
     *
     * Extracts RICs from the module data, obtains an access token, and sends
     * async requests to fetch indicator data.
     *
     * @param array  $moduleData   Input data containing RICs.
     * @param array  $queryParams  Parameters for the data request.
     * @param string $column       Column to extract RICs from (default: 'ric').
     * @param string $tableName    Source table name (default: 'companies').
     *
     * @return array Responses from the data service, or an empty array on failure.
     */
    public function handle(array $moduleData, array $queryParams, string $column = 'ric', string $tableName = 'companies'): array
    {
        if (!$moduleData) {
            output(LogMessages::REF_HISTORICAL_NO_MODULE_DATA_PROVIDED, $tableName);
            return [];
        }

        $rics = array_column($moduleData, $column);

        // Fetch Refinitiv access token
        $tokenRec = getActiveRefToken($this->webSocketServer, $this->refTokenLock);

        $refAccessToken = $tokenRec ? $tokenRec['access_token'] :  $tokenRec;

        if (!empty($rics) && !empty($refAccessToken)) {
            // Proceed if both RICs and token are available
            $this->authCounter = 0;
            $this->tooManyRequestCounter = 0;

            $mAIndicatorBarrier = Barrier::make();
            $responsesChannel = new Channel(count($moduleData));

            foreach ($rics as $ric) {
                $ric = $tableName == 'companies' ? $ric : '.' . $ric;
                $this->sendRequest($ric, $queryParams, $refAccessToken, $responsesChannel, $mAIndicatorBarrier);
            }

            Barrier::wait($mAIndicatorBarrier);

            $responses = [];
            while (!$responsesChannel->isEmpty()) {
                $responses[] = $responsesChannel->pop();
            }

            return $responses;
        }
        output(LogMessages::REF_TOKEN_COMPANY_ISSUE);
        return array();
    }

    /**
     * Retrieves historical indicator data from Refinitiv for a given RIC.
     *
     * @param string $ric         The Instrument Code\Key.
     * @param string $token       Refinitiv access token.
     * @param array  $queryParams Query parameters for the request.
     *
     * @return array JSON-decoded response containing indicator data.
     */
    function getIndicatorsData(string $ric, string $token, array $queryParams): array
    {
        $headers = $this->getHeaders($token);

        $endpoint = $this->url . $ric;
        $endpoint .= '?' . http_build_query($queryParams);

        $apiConsumer = new APIConsumer($endpoint, $headers, $this->timeout);

        return $apiConsumer->request();
    }

    /**
     * Sends an async request to fetch indicator data for a given RIC.
     *
     * Handles retries for unauthorized (401) and rate-limited (429) responses.
     * Pushes the result to the response channel if successful.
     *
     * @param string $ric               The instrument code.
     * @param array  $queryParams       Parameters for the API request.
     * @param string $accessToken       Refinitiv access token.
     * @param object $responsesChannel  Channel to collect responses.
     * @param object $mAIndicatorBarrier Barrier to synchronize requests.
     *
     * @return void
     */
    public function sendRequest(string $ric, array $queryParams, string $accessToken, object $responsesChannel, object $mAIndicatorBarrier)
    {
        go(function () use ($ric, $queryParams, $accessToken, $responsesChannel, $mAIndicatorBarrier) {

            $overAllRepCounter = 0;
            do {

                do { // Check for status code 401 (Unauthorized)
                    // Fetch the data for this chunk
                    $responseData = $this->getIndicatorsData($ric, $accessToken, $queryParams);
                    $statusCode = $responseData['status_code'];
                    $response = $responseData['response'];

                    if ($statusCode == 401) {
                        output(LogMessages::REF_HISTORICAL_UNAUTHORIZED_ACCESS);
                        Coroutine::sleep(0.7);
                        $token = getActiveRefToken($this->webSocketServer, $this->refTokenLock);
                        if ($token) {
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
                    output(LogMessages::REF_HISTORICAL_UNAUTHORIZED_RETRY_LIMIT);
                }

                if ($statusCode === 429) { // Check for status code 429 (Too Many Requests)

                    do {
                        $this->tooManyRequestCounter++;
                        $responseData = $this->getIndicatorsData($ric, $accessToken, $queryParams);
                        $statusCode = $responseData['status_code'];
                        $response = $responseData['response'];

                        if ($statusCode == 429) {
                            output(LogMessages::REF_HISTORICAL_TOO_MANY_REQUESTS);
                            Coroutine::sleep($this->tooManyRequestCounter + 1);
                            $this->tooManyRequestCounter++;
                        } else {
                            break;
                        }
                    } while ($this->tooManyRequestCounter <  $this->retry);

                    if ($this->tooManyRequestCounter >=  $this->retry) {
                        $this->tooManyRequestCounter = 0;
                        output(LogMessages::REF_HISTORICAL_TOO_MANY_REQUESTS_RETRY_LIMIT);
                    }
                }

                if ($statusCode === 200) { // Return
                    $res = json_decode($response, true);
                    if ($res) {
                        $responsesChannel->push($res[0]);
                    }
                } else {
                    output(sprintf(LogMessages::REF_HISTORICAL_INVALID_RESPONSE, json_encode($response)));
                }

                if ($statusCode > 299) {
                    Coroutine::sleep(1);
                    $overAllRepCounter++;
                    if ($overAllRepCounter == 2) {
                        output(LogMessages::REF_HISTORICAL_OVERALL_RETRIES_LIMIT_EXCEEDED);
                    }
                } else {
                    break;
                }
                // $statusCode > 299
            } while ($overAllRepCounter < 2 && $statusCode > 299);
        });
    }

    /**
     * Get the headers
     *
     * @param  string $token
     * @return array
     */
    function getHeaders(string $token): array
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
