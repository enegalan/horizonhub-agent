<?php

namespace HorizonHub\Agent\Listeners;

use HorizonHub\Agent\Support\EventPayloadBuilder;
use HorizonHub\Agent\Support\HubClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Events\JobFailed as IlluminateJobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobFailed;
use Laravel\Horizon\Events\JobReserved;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Laravel\Horizon\Events\SupervisorLooped;

class PushHorizonEventToHub {
    /** The Horizon Hub client to push events to. */
    private HubClient $client;

    /**
     * Construct the push horizon event to hub listener.
     *
     * @param HubClient $client
     */
    public function __construct(HubClient $client) {
        $this->client = $client;
    }

    /**
     * Validate the Horizon Hub Agent configuration.
     *
     * @return bool
     */
    public function validate(): bool {
        $valid = !empty(\config('horizonhub.hub_url')) && !empty(\config('horizonhub.api_key'));
        if (!$valid) {
            Log::warning('Horizon Hub Agent: HORIZON_HUB_URL or HORIZON_HUB_API_KEY not set; events will not be sent to Horizon Hub Dashboard.');
        }
        return $valid;
    }

    /**
     * Handle the push horizon event to hub.
     *
     * @param object $event
     * @return void
     */
    public function handle(object $event): void {
        $payload = $this->buildPayload($event);
        if ($payload === null) {
            return;
        }

        $payload['service_name'] = \config('horizonhub.service_name');

        try {
            $this->client->push($payload);
        } catch (\Throwable $e) {
            Log::warning('Horizon Hub Agent: failed to push event', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the payload for the push horizon event to hub.
     *
     * @param object $event
     * @return array|null
     */
    private function buildPayload(object $event): ?array {
        return match ($event::class) {
            JobProcessed::class => EventPayloadBuilder::fromJobProcessed($event),
            JobDeleted::class => EventPayloadBuilder::fromJobDeleted($event),
            JobFailed::class, IlluminateJobFailed::class => EventPayloadBuilder::fromJobFailed($event),
            JobProcessing::class => EventPayloadBuilder::fromJobProcessing($event),
            JobReserved::class => EventPayloadBuilder::fromJobReserved($event),
            SupervisorLooped::class => EventPayloadBuilder::fromSupervisorLooped($event),
            QueuePaused::class => EventPayloadBuilder::fromQueuePaused($event),
            QueueResumed::class => EventPayloadBuilder::fromQueueResumed($event),
            default => null,
        };
    }
}
