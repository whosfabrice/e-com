<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\Factory as HttpFactory;

class SlackWebhookClient
{
    public function __construct(protected HttpFactory $http)
    {
    }

    public function send(string $webhookUrl, array $message): void
    {
        $this->http
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post($webhookUrl, $message)
            ->throw();
    }
}
