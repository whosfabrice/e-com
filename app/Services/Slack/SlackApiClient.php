<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SlackApiClient
{
    public function __construct(protected HttpFactory $http)
    {
    }

    public function openView(string $triggerId, array $view): array
    {
        return $this->request('views.open', [
            'trigger_id' => $triggerId,
            'view' => $view,
        ]);
    }

    public function postMessage(string $channelId, array $message): array
    {
        return $this->request('chat.postMessage', [
            'channel' => $channelId,
            ...$message,
        ]);
    }

    protected function request(string $endpoint, array $payload): array
    {
        $botToken = config('services.slack.bot_token');

        if (! is_string($botToken) || $botToken === '') {
            throw new RuntimeException('Missing services.slack.bot_token configuration.');
        }

        $response = $this->http
            ->baseUrl('https://slack.com/api')
            ->withToken($botToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($endpoint, $payload)
            ->throw()
            ->json();

        if (($response['ok'] ?? false) !== true) {
            Log::error('Slack API request failed.', [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'response' => $response,
            ]);

            $details = data_get($response, 'response_metadata.messages');

            throw new RuntimeException(sprintf(
                'Slack API error: %s%s',
                $response['error'] ?? 'unknown_error',
                is_array($details) && $details !== [] ? ' | '.implode(' ', $details) : '',
            ));
        }

        return $response;
    }
}
