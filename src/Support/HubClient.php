<?php

namespace HorizonHub\Agent\Support;

use Illuminate\Support\Facades\Http;

class HubClient {

    /**
     * Push the payload to Horizon Hub API.
     *
     * @param array $payload
     * @return void
     */
    public function push(array $payload): void {
        $url = \rtrim(\config('horizonhub.hub_url'), '/') . \config('horizonhub.events_path');
        $apiKey = \config('horizonhub.api_key');

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
