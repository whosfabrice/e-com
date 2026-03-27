<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;
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

    public function batchGetNodes(array $ids, string $fields): array
    {
        if ($ids === []) {
            return [];
        }

        $batch = array_map(fn (string $id): array => [
            'method' => 'GET',
            'relative_url' => sprintf(
                '%s?fields=%s',
                $id,
                rawurlencode($fields),
            ),
        ], $ids);

        try {
            $response = $this->post('', [
                'batch' => json_encode($batch, JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return collect($ids)
                ->mapWithKeys(function (string $id) use ($fields): array {
                    try {
                        $response = $this->get($id, [
                            'fields' => $fields,
                        ]);
                    } catch (Throwable) {
                        $response = [];
                    }

                    return [$id => $response];
                })
                ->all();
        }

        if (! is_array($response)) {
            throw new RuntimeException('Unexpected Meta batch response.');
        }

        $decoded = [];

        foreach ($response as $index => $entry) {
            $id = $ids[$index] ?? null;
            $code = (int) ($entry['code'] ?? 0);
            $body = (string) ($entry['body'] ?? '');

            if (! is_string($id) || $id === '') {
                continue;
            }

            if ($code < 200 || $code >= 300 || $body === '') {
                $decoded[$id] = [];
                continue;
            }

            $decoded[$id] = json_decode($body, true) ?: [];
        }

        return $decoded;
    }
}
