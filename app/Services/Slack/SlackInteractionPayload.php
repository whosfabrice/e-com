<?php

namespace App\Services\Slack;

use Illuminate\Http\Request;

class SlackInteractionPayload
{
    public function fromRequest(Request $request): array
    {
        $payload = $request->string('payload')->toString();

        if ($payload === '') {
            parse_str($request->getContent(), $formData);
            $payload = is_string($formData['payload'] ?? null) ? $formData['payload'] : '';
        }

        $decodedPayload = json_decode($payload !== '' ? $payload : '{}', true);

        return is_array($decodedPayload) ? $decodedPayload : [];
    }

    public function isCampaignSelectAction(array $payload): bool
    {
        return ($payload['type'] ?? null) === 'block_actions'
            && ($payload['actions'][0]['action_id'] ?? null) === 'campaign_select';
    }

    public function isAddToCampaignAction(array $payload): bool
    {
        return ($payload['type'] ?? null) === 'block_actions'
            && ($payload['actions'][0]['action_id'] ?? null) === 'add_to_campaign';
    }

    public function triggerId(array $payload): string
    {
        return (string) ($payload['trigger_id'] ?? '');
    }

    public function brandId(array $payload): int
    {
        $metadata = $this->metadata($payload);

        return (int) ($metadata['brand_id'] ?? 0);
    }

    public function adId(array $payload): string
    {
        $metadata = $this->metadata($payload);

        return (string) ($metadata['ad_id'] ?? '');
    }

    public function adName(array $payload): string
    {
        $metadata = $this->metadata($payload);

        return (string) ($metadata['ad_name'] ?? '');
    }

    public function channelId(array $payload): ?string
    {
        $channelId = data_get($payload, 'channel.id');

        if (! is_string($channelId) || $channelId === '') {
            $metadata = $this->metadata($payload);
            $channelId = $metadata['channel_id'] ?? null;
        }

        return is_string($channelId) && $channelId !== '' ? $channelId : null;
    }

    public function threadTs(array $payload): ?string
    {
        $threadTs = data_get($payload, 'container.message_ts');

        if (! is_string($threadTs) || $threadTs === '') {
            $metadata = $this->metadata($payload);
            $threadTs = $metadata['thread_ts'] ?? null;
        }

        return is_string($threadTs) && $threadTs !== '' ? $threadTs : null;
    }

    public function selectedCampaignId(array $payload): ?string
    {
        $value = data_get($payload, sprintf(
            'state.values.winner_%s.campaign_select.selected_option.value',
            $this->adId($payload),
        ));

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function metadata(array $payload): array
    {
        return json_decode(
            (string) ($payload['actions'][0]['value'] ?? '{}'),
            true,
        ) ?: [];
    }
}
