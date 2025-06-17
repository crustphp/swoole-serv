<?php

namespace App\Services;

use Swoole\Coroutine\Http\Client;
use Throwable;
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
            $client = new Client('api.openai.com', 443, true); // HTTPS = true

            $client->setHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ]);

            $client->setMethod('POST');
            $client->setData(json_encode([
                'model' => 'gpt-4o', // Updated to latest model
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional translator. Translate text to Arabic.'],
                    ['role' => 'user', 'content' => $text],
                ],
                'max_tokens' => 4096,
                'temperature' => 0.7,
            ]));

            $client->execute('/v1/chat/completions');

            if ($client->statusCode === 200) {
                $response = json_decode($client->body, true);
                $client->close();

                $translated = $response['choices'][0]['message']['content'] ?? null;

                if (empty($translated)) {
                    output('Translation API returned an empty response.');
                    return null;
                }

                return $translated;
            } else {
                output('API Error: ' . $client->body);
            }

            $client->close();

        } catch (Throwable $e) {
            output('Translation exception: ' . $e->getMessage());
        }

        return null;
    }

}
