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
     * Make a GET request to the API
     *
     * @return array Response body
     */
    public function request(): array
    {
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
        $client->get($this->endpoint);

        // Capture response and error details if any
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
        $response = $client->body;

        // Close connection
        $client->close();

        return [
            'status_code' => $statusCode,
            'response' => $response,
        ];
    }
}
