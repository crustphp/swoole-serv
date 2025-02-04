<?php

namespace App\Core\Services;

use Swoole\Coroutine\Http\Client;

class APIConsumer
{
    protected float $timeout;
    protected string $endpoint;
    protected array $headers;

    /**
     * Initialize the APIConsumer with the necessary endpoint, headers, and timeout configuration.
     *
     * @param string $endpoint The URL endpoint to which the request will be made.
     * @param array $headers An associative array of headers to be included in the request.
     * @param float $timeout The maximum duration (in seconds) the request should take before timing out.
     */
    public function __construct(string $endpoint, array $headers, float $timeout)
    {
        $this->timeout = $timeout;
        $this->endpoint = $endpoint;
        $this->headers = $headers;
    }

    /**
     * Make a request to the API
     * param $method string
     * param $body array
     * param $request_type string: [Websocket | HTTP]
     * @return array Response body
     */
    public function request($request_type = 'HTTP', $method = 'GET', $body = []): array
    {
        $result = [];

        if ($request_type == 'HTTP') {
            // HTTP request handling logic here
            $result = $this->httpRequestHandle($method, $body);
        } else if ($request_type == 'WEBSOCKET') {
            // WebSocket request handling logic here
            $result = $this->websocketRequestHandle();
        }

        return $result;
    }

    /**
     * Make a request to the API
     * param $method string
     * param $body array
     * @return array Response body
     */
    private function httpRequestHandle($method = 'GET', $body = []): array {
        $parsedUrl = parse_url($this->endpoint);

        // Check for required components in the parsed URL
        if (!isset($parsedUrl['host']) || !isset($parsedUrl['path'])) {
            throw new \Exception("Invalid URL format: Missing host or path.");
        }

        $isHttps = ($parsedUrl['scheme'] ?? '') === 'https';
        $port = $isHttps ? 443 : 80;

        // Initialize the Swoole client
        $client = new Client($parsedUrl['host'], $port, $isHttps);
        $client->set(['timeout' => $this->timeout]);

        // Define headers, including authorization if provided
        $this->headers['Host'] = $parsedUrl['host'];

        $client->setHeaders($this->headers);
        if ($method == 'POST') {
            $client->post($this->endpoint, $body);
        } else {
            $client->get($this->endpoint);
        }

        $statusCode = $client->statusCode;
        $response = $client->body;

        $result = [
            'status_code' => $statusCode,
            'response' => $response,
        ];

        if ($statusCode > 299) {
            output('Connection Related Error Code: ' . $client->errCode);
            output('Connection Related Error Message: ' . $client->errMsg);
            output('Response Status Code: ' . $client->statusCode);

            $result['error'] = "Request failed with status code $statusCode";
            $result['error_code'] = $client->errCode;
            $result['error_message'] = $client->errMsg;
        }

        $client->close();

        return $result;
    }

    public function websocketRequestHandle() {
        // In future here will be the logic relating to websocket
    }
}
