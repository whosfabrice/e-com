<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\Factory as HttpFactory;
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
            throw new RuntimeException('Slack API error: '.($response['error'] ?? 'unknown_error'));
        }

        return $response;
    }
}
