<?php

namespace App\Services;

use Swoole\Coroutine\Http\Client;

class TranslateService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('app_config.chatgpt_api_key');
    }

    public function translateToArabic(string $text): ?string
    {
        try {
            $url = 'https://api.openai.com';
            $path = '/v1/chat/completions';

            // Prepare request body
            $payload = json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional translator. Translate text to Arabic.'],
                    ['role' => 'user', 'content' => $text],
                ],
                'max_tokens' => 4096,
                'temperature' => 0.7,
            ]);

            // Initialize Swoole HTTP client
            $client = new Client('api.openai.com', 443, true); // HTTPS = true
            $client->setHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ]);
            $client->setMethod('POST');
            $client->setData($payload);

            // Send the request
            $client->execute($path);

            // Process the response
            if ($client->statusCode === 200) {
                $response = json_decode($client->body, true);
                $client->close(); // Close the client after the request
                return $response['choices'][0]['message']['content'] ?? null;
            } else {
                echo "API Error: {$client->body}" . PHP_EOL;
            }
        } catch (\Exception $e) {
            echo "Exception: {$e->getMessage()}" . PHP_EOL;
        }

        return null;
    }
}
