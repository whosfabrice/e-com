<?php

namespace App\Services\Slack;

use Illuminate\Http\Request;

class SlackSignatureVerifier
{
    public function isValid(Request $request): bool
    {
        $signingSecret = config('services.slack.signing_secret');
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp', '');
        $signature = (string) $request->header('X-Slack-Signature', '');

        if (! is_string($signingSecret) || $signingSecret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $baseString = sprintf('v0:%s:%s', $timestamp, $request->getContent());
        $expectedSignature = 'v0='.hash_hmac('sha256', $baseString, $signingSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
