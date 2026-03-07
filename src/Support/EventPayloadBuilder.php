<?php

namespace HorizonHub\Agent\Support;

use Illuminate\Support\Str;

class EventPayloadBuilder {
    /** @var array<string, float> job_id => microtime when JobProcessing was built */
    private static array $jobStartedAt = [];

    /**
     * Get the event type from the event status.
     *
     * @param string $eventStatus
     * @return string
     */
    private static function getEventType(string $eventStatus): string {
        return \array_search($eventStatus, \config('horizonhub.event_types_status'));
    }

    /**
     * Get the event status from the event type.
     *
     * @param string $eventType
     * @return string
     */
    private static function getEventStatus(string $eventType): string {
        return \config("horizonhub.event_types_status.$eventType");
    }

    /**
     * Build the payload for the job processed event.
     *
     * @param object $event
     * @return array
     */
    public static function fromJobProcessed(object $event): array {
        // TODO: Test if this this method is called
        \Log::debug('Testing if this method is called fromJobProcessed', ['event' => $event]);
        $job = static::jobFromEvent($event);
        $base = static::baseJobPayload($event, $job, static::getEventType('processed'));
        $payload = \array_merge($base, [
            'processed_at' => \now()->toIso8601String(),
        ]);
        $runtimeSeconds = static::popRuntimeSeconds($base['job_id'] ?? '');
        if ($runtimeSeconds !== null) {
            $payload['runtime_seconds'] = \round($runtimeSeconds, 3);
        }
        return $payload;
    }

    /**
     * Build the payload for the job deleted event.
     *
     * @param object $event
     * @return array
     */
    public static function fromJobDeleted(object $event): array {
        $job = static::jobFromEvent($event);
        $base = static::baseJobPayload($event, $job, static::getEventType('processed'));
        $payload = \array_merge($base, [
            'processed_at' => \now()->toIso8601String(),
        ]);
        $runtimeSeconds = static::popRuntimeSeconds($base['job_id'] ?? '');
        if ($runtimeSeconds !== null) {
            $payload['runtime_seconds'] = \round($runtimeSeconds, 3);
        }
        return $payload;
    }

    /**
     * Build the payload for the job failed event.
     *
     * @param object $event
     * @return array
     */
    public static function fromJobFailed(object $event): array {
        $job = static::jobFromEvent($event);
        $base = static::baseJobPayload($event, $job, static::getEventType('failed'));
        $payload = \array_merge($base, [
            'failed_at' => \now()->toIso8601String(),
            'exception' => static::formatException($event->exception ?? null),
        ]);
        $runtimeSeconds = static::popRuntimeSeconds($base['job_id'] ?? '');
        if ($runtimeSeconds !== null) {
            $payload['runtime_seconds'] = \round($runtimeSeconds, 3);
        }
        return $payload;
    }

    /**
     * Build the payload for the job processing event.
     *
     * @param object $event
     * @return array
     */
    public static function fromJobProcessing(object $event): array {
        // TODO: Test if this this method is called
        \Log::debug('Testing if this method is called fromJobProcessing', ['event' => $event]);
        $job = static::jobFromEvent($event);
        $payload = static::baseJobPayload($event, $job, static::getEventType('processing'));
        $jobId = $payload['job_id'] ?? '';
        if ($jobId !== '') {
            self::$jobStartedAt[$jobId] = \microtime(true);
        }
        return $payload;
    }

    /**
     * Build JobProcessing payload from Horizon's JobReserved and record start time for runtime.
     * Horizon fires JobReserved when a worker picks up a job; JobDeleted when it finishes.
     *
     * @param object $event
     * @return array
     */
    public static function fromJobReserved(object $event): array {
        $jobId = \method_exists($event->payload, 'id') ? (string) $event->payload->id() : '';
        if ($jobId !== '') {
            self::$jobStartedAt[$jobId] = microtime(true);
        }
        $conn = \property_exists($event, 'connectionName') ? $event->connectionName : \config('horizonhub.queues.name');
        $queueName = \property_exists($event, 'queue') ? $event->queue : \config('horizonhub.queues.queue');
        $queue = "$conn.$queueName";
        $decoded = isset($event->payload->decoded) ?? $event->payload->decoded;
        $name = isset($decoded['displayName']) ?? $decoded['displayName'];
        $eventType = static::getEventType('processing');
        $result = [
            'event_type' => $eventType,
            'job_id' => $jobId,
            'queue' => $queue,
            'status' => static::getEventStatus($eventType),
            'attempts' => 0,
            'name' => $name,
            'payload' => $decoded,
        ];
        $queuedAt = static::queuedAtFromPayload(\is_array($decoded) ? $decoded : []);
        if ($queuedAt !== null) {
            $result['queued_at'] = $queuedAt;
        }
        return $result;
    }

    /**
     * Format the exception.
     *
     * @param mixed $exception
     * @return string|null
     */
    private static function formatException(mixed $exception): ?string {
        if ($exception === null) {
            return null;
        }
        if (\is_object($exception) && \method_exists($exception, 'getMessage')) {
            $msg = $exception->getMessage();
            $trace = \method_exists($exception, 'getTraceAsString') ? $exception->getTraceAsString() : '';
            return $trace !== '' ? "$msg\n\n$trace" : $msg;
        }
        return (string) $exception;
    }

    /**
     * Returns runtime in seconds since JobProcessing and removes the stored start time.
     *
     * @param string $jobId
     * @return float|null
     */
    private static function popRuntimeSeconds(string $jobId): ?float {
        if ($jobId === '' || ! isset(self::$jobStartedAt[$jobId])) {
            return null;
        }
        $start = self::$jobStartedAt[$jobId];
        unset(self::$jobStartedAt[$jobId]);
        $seconds = \microtime(true) - $start;
        return $seconds >= 0 ? $seconds : null;
    }

    /**
     * Build the payload for the supervisor looped event.
     *
     * @param object $event
     * @return array
     */
    public static function fromSupervisorLooped(object $event): array {
        $supervisorName = \config('horizonhub.queues.queue');
        if (\property_exists($event, 'supervisor') && isset($event->supervisor)) {
            $supervisorName = isset($event->supervisor->name) ? (string) $event->supervisor->name : \config('horizonhub.queues.queue');
        }
        $eventType = static::getEventType('looped');
        return [
            'event_type' => $eventType,
            'job_id' => '',
            'queue' => $supervisorName,
            'status' => static::getEventStatus($eventType),
        ];
    }

    /**
     * Get connection name from a pause/resume event (Illuminate uses "connection", Horizon "connectionName").
     *
     * @param object $event
     * @return string
     */
    private static function connectionFromPauseResumeEvent(object $event): string {
        if (\property_exists($event, 'connectionName') && $event->connectionName !== '') {
            return (string) $event->connectionName;
        }
        if (\property_exists($event, 'connection') && $event->connection !== '') {
            return (string) $event->connection;
        }
        return (string) \config('horizonhub.queues.name');
    }

    /**
     * Get queue name from a pause/resume event.
     *
     * @param object $event
     * @return string
     */
    private static function queueNameFromPauseResumeEvent(object $event): string {
        if (\property_exists($event, 'queue') && $event->queue !== '') {
            return (string) $event->queue;
        }
        return (string) \config('horizonhub.queues.queue');
    }

    /**
     * Build the payload for the queue paused event.
     *
     * @param object $event
     * @return array
     */
    public static function fromQueuePaused(object $event): array {
        $connection = static::connectionFromPauseResumeEvent($event);
        $queue = static::queueNameFromPauseResumeEvent($event);
        $eventType = static::getEventType('paused');
        return [
            'event_type' => $eventType,
            'job_id' => '',
            'queue' => "$connection.$queue",
            'status' => static::getEventStatus($eventType),
        ];
    }

    /**
     * Build the payload for the queue resumed event.
     *
     * @param object $event
     * @return array
     */
    public static function fromQueueResumed(object $event): array {
        $connection = static::connectionFromPauseResumeEvent($event);
        $queue = static::queueNameFromPauseResumeEvent($event);
        $eventType = static::getEventType('resumed');
        return [
            'event_type' => $eventType,
            'job_id' => '',
            'queue' => "$connection.$queue",
            'status' => static::getEventStatus($eventType),
        ];
    }

    /**
     * Get the job from the event.
     *
     * @param object $event
     * @return object|null
     */
    private static function jobFromEvent(object $event): ?object {
        return \property_exists($event, 'job') ? $event->job : null;
    }

    /**
     * Build the base job payload.
     *
     * @param object $event
     * @param object|null $job
     * @param string $eventType
     * @return array
     */
    private static function baseJobPayload(object $event, ?object $job, string $eventType): array {
        $queue = \config('horizonhub.queues.name') . '.' . \config('horizonhub.queues.queue');
        $attempts = 0;
        $jobId = '';
        $payload = [];

        if ($job !== null) {
            if (\method_exists($job, 'getQueue')) {
                $conn = \property_exists($event, 'connectionName') ? $event->connectionName : \config('horizonhub.queues.name');
                $queue = "$conn." . ($job->getQueue() ?: \config('horizonhub.queues.queue'));
            }
            if (\method_exists($job, 'attempts')) {
                $attempts = (int) $job->attempts();
            }
            if (\method_exists($job, 'getJobId')) {
                $jobId = (string) $job->getJobId();
            }
            if (\method_exists($job, 'payload')) {
                $payload = $job->payload();
                if (\is_string($payload)) {
                    $payload = \json_decode($payload, true) ?: [];
                }
                if (isset($payload['uuid'])) {
                    $jobId = (string) $payload['uuid'];
                }
            }
        }

        if (empty($jobId)) {
            $jobId = Str::uuid()->toString();
        }

        $displayName = $payload['displayName'] ?? $payload['job'] ?? null;
        $name = \is_string($displayName) ? $displayName : (\is_array($displayName) ? ($displayName['displayName'] ?? null) : null);

        $result = [
            'event_type' => $eventType,
            'job_id' => $jobId,
            'queue' => $queue,
            'status' => static::getEventStatus($eventType),
            'attempts' => $attempts,
            'name' => $name,
            'payload' => $payload,
        ];
        $queuedAt = static::queuedAtFromPayload($payload);
        if ($queuedAt !== null) {
            $result['queued_at'] = $queuedAt;
        }
        return $result;
    }

    /**
     * Get the queued at from the payload.
     *
     * @param array<string, mixed> $payload Laravel job payload (decoded)
     * @return string|null
     */
    private static function queuedAtFromPayload(array $payload): ?string {
        $timestamp = $payload['created_at']
            ?? $payload['available_at']
            ?? $payload['pushedAt']
            ?? null;

        if (!\is_numeric($timestamp) || (float) $timestamp <= 0) {
            return null;
        }

        return \date('c', (int) $timestamp);
    }
}
