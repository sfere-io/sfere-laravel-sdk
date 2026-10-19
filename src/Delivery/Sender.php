<?php

namespace Sfere\Delivery;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Psr\Log\LoggerInterface;
use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Transport;
use Sfere\Events\DeliveryFailed;
use Sfere\Events\EventsSent;
use Sfere\Exceptions\DeliveryException;
use Sfere\Support\Settings;
use Sfere\Transport\DeliveryResult;

/**
 * Sends built events through the transport, in requests that respect the batch limits, then
 * reports the outcome: `EventsSent` / `DeliveryFailed` Laravel events and a log line per failure.
 * Logs carry counts, messageIds, status and reason only: never the write key or event payloads.
 */
final class Sender
{
    /** Bytes reserved in every batch body for `{"batch":[...],"writeKey":"…","sentAt":"…"}`. */
    public const ENVELOPE_OVERHEAD = 200;

    public function __construct(
        private readonly Transport $transport,
        private readonly Settings $settings,
        private readonly EventDispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  bool  $single  one `/api/s/s2s/{type}` request per event instead of `/v1/batch`
     * @param  bool|null  $throw  throw a DeliveryException on failure; default `throw_exceptions`
     *
     * @throws DeliveryException
     */
    public function deliver(array $events, bool $single = false, ?bool $throw = null): DeliveryResult
    {
        if ($events === []) {
            return DeliveryResult::success([]);
        }

        $results = [];
        if ($single) {
            foreach ($events as $event) {
                $results[] = $this->report([$event], $this->transport->sendOne($event));
            }
        } else {
            foreach (self::split($events, $this->settings) as $chunk) {
                $results[] = $this->report($chunk, $this->transport->sendBatch($chunk));
            }
        }

        $result = self::combine($results);

        if (! $result->ok && ($throw ?? $this->settings->throwExceptions)) {
            throw new DeliveryException($result);
        }

        return $result;
    }

    /**
     * Splits events into batches of at most `batch.max_size` events and `batch.max_bytes` bytes
     * (envelope included). An event bigger than the byte limit on its own travels alone.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<list<array<string, mixed>>>
     */
    public static function split(array $events, Settings $settings): array
    {
        $chunks = [];
        $chunk = [];
        $bytes = self::ENVELOPE_OVERHEAD;

        foreach ($events as $event) {
            $size = EventBuffer::size($event) + 1; // + the separating comma
            if ($chunk !== [] && (count($chunk) >= $settings->batchMaxSize || $bytes + $size > $settings->batchMaxBytes)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = self::ENVELOPE_OVERHEAD;
            }
            $chunk[] = $event;
            $bytes += $size;
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    public static function messageIds(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $id = $event['messageId'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Fires the Laravel events and logs a failure for one request.
     *
     * @param  list<array<string, mixed>>  $chunk
     */
    private function report(array $chunk, DeliveryResult $result): DeliveryResult
    {
        // A transport that failed without naming the events: everything not accepted failed.
        if (! $result->ok && $result->failedIds === []) {
            $failed = array_values(array_diff(self::messageIds($chunk), $result->sentIds));
            $result = new DeliveryResult(false, $result->sentIds, $failed, $result->status, $result->reason, $result->retryable, $result->attempts);
        }

        if ($result->sentIds !== []) {
            $this->events->dispatch(new EventsSent($result->sentIds, count($result->sentIds)));
        }

        if (! $result->ok) {
            $reason = $result->reason ?? 'unknown error';
            $this->logger->error(sprintf('Sfere: %d event(s) not delivered: %s', count($result->failedIds), $reason), [
                'count' => count($result->failedIds),
                'messageIds' => $result->failedIds,
                'status' => $result->status,
                'retryable' => $result->retryable,
                'attempts' => $result->attempts,
            ]);
            $this->events->dispatch(new DeliveryFailed($result->failedIds, $result->status, $reason, $result->retryable));
        }

        return $result;
    }

    /**
     * @param  list<DeliveryResult>  $results
     */
    private static function combine(array $results): DeliveryResult
    {
        if (count($results) === 1) {
            return $results[0];
        }

        $sent = [];
        $failed = [];
        $status = null;
        $reason = null;
        $retryable = false;
        $attempts = 1;
        $ok = true;

        foreach ($results as $result) {
            $sent = [...$sent, ...$result->sentIds];
            $failed = [...$failed, ...$result->failedIds];
            $attempts = max($attempts, $result->attempts);
            if (! $result->ok) {
                $ok = false;
                $status = $result->status;
                $reason ??= $result->reason;
                $retryable = $retryable || $result->retryable;
            }
        }

        return new DeliveryResult($ok, $sent, $failed, $ok ? 200 : $status, $reason, $retryable, $attempts);
    }
}
