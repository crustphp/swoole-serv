<?php

use Swoole\Coroutine\Http\Client;

class HttpClientTestService
{
    protected $webSocketServer;
    protected $frame;

    public function __construct($webSocketServer, $frame)
    {
        $this->webSocketServer = $webSocketServer;
        $this->frame = $frame;
    }

    public function handle()
    {
        // Using Free API: https://fruityvice.com/doc/index.html#api-GET-getAll
        $defaultEndpoint = "https://www.fruityvice.com/api/fruit/all";

        // parse_url function return various components of the url e.g host, scheme, port, path, query, fragment
        // not necessarily returns all the components, it depends upon the url you are passing
        // Find details of parse url function: https://www.php.net/manual/en/function.parse-url.php 
        $parsedUrl = parse_url($defaultEndpoint);

        // in this url we get following 3 components when parsed
        // array:3 [
        //     "scheme" => "https"
        //     "host" => "www.fruityvice.com"
        //     "path" => "/api/fruit/all"
        // ];

        $isHttps = ($parsedUrl['scheme'] === 'https');
        $port = $isHttps ? 443 : 80; // Port 443 for https and ssl should be true. For http use port 80 with ssl equals false

        // Client Constructor Params: host, port, ssl
        $client = new Client($parsedUrl['host'], $port, $isHttps);

        // OpenSwoole: https://openswoole.com/docs/modules/swoole-client-overall-config-set-options
        // Swoole: https://wiki.swoole.com/en/#/client?id=configuration
        $client->set(['timeout' => 1]);

        $headers = [
            'Host' => $parsedUrl['host'],
            'Connection' => 'keep-alive\r\n',
            'Cache-Control' => 'max-age=0',
            'Accept' => 'application/json',
            'User-Agent' => 'swoole-http-client',
        ];

        $client->setHeaders($headers);

        // Get call - Method 1
        $client->get($parsedUrl['path']);

        // The following code for sending get request also works
        // Example code for this method:
        // Swoole: https://wiki.swoole.com/en/#/coroutine_client/http_client?id=execute

        // Get call - Method 2
        // $client->setMethod('GET');
        // $status = $client->execute($parsedUrl['path']);
        // echo PHP_EOL.'STATUS: ';
        // var_dump($status);

        // Read Response
        $response = $client->body;
        var_dump($response);

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

        $this->webSocketServer->push($this->frame->fd, $response);
    }
}
