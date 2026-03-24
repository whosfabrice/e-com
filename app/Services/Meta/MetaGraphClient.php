<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class MetaGraphClient
{
    public function __construct(protected HttpFactory $http)
    {
    }

    public function get(string $path, array $query = []): array
    {
        $accessToken = config('services.meta.graph_api_access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Missing services.meta.graph_api_access_token configuration.');
        }

        return $this->http
            ->baseUrl((string) config('services.meta.graph_api_url'))
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get($path, $query)
            ->throw()
            ->json();
    }

    public function post(string $path, array $payload = []): array
    {
        $accessToken = config('services.meta.graph_api_access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Missing services.meta.graph_api_access_token configuration.');
        }

        return $this->http
            ->baseUrl((string) config('services.meta.graph_api_url'))
            ->withToken($accessToken)
            ->acceptJson()
            ->asForm()
            ->timeout(30)
            ->post($path, $payload)
            ->throw()
            ->json();
    }
}
