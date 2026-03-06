<?php

namespace HorizonHub\Agent\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubClient {
    private static bool $logged_missing_config = false;

    public function push(array $payload): void {
        $url = \rtrim(\config('horizonhub.hub_url'), '/') . \config('horizonhub.events_path');
        $apiKey = \config('horizonhub.api_key');

        if (\empty($url) || \empty($apiKey)) {
            if (! self::$logged_missing_config) {
                self::$logged_missing_config = true;
                Log::warning('Horizon Hub Agent: HORIZON_HUB_URL or HORIZON_HUB_API_KEY not set; events are not sent to the hub.');
            }
            return;
        }

        $body = \json_encode($payload);
        $timestamp = (string) \time();
        $signature = 'sha256=' . \hash_hmac('sha256', "$timestamp.$body", $apiKey);

        $retryTimes = \config('horizonhub.http.retry_times');
        $retrySleep = \config('horizonhub.http.retry_sleep_ms');

        Http::timeout(15)
            ->withHeaders([
                'X-Api-Key' => $apiKey,
                'X-Hub-Timestamp' => $timestamp,
                'X-Hub-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->retry($retryTimes, $retrySleep)
            ->post($url)
            ->throw();
    }
}
