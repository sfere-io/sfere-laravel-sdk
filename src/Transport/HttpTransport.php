<?php

namespace Sfere\Transport;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use JsonException;
use Sfere\Contracts\Transport;
use Sfere\Payload\EventFactory;
use Sfere\Support\Library;
use Sfere\Support\Settings;
use Throwable;

/**
 * Sends wire events to Sfere ingestion over Laravel's HTTP client (so apps can `Http::fake()`).
 *
 * Retries network errors, 429 and 5xx with exponential backoff and full jitter, honouring
 * `Retry-After`; never retries any other 4xx. Never throws.
 */
final class HttpTransport implements Transport
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const SINGLE_TYPES = ['track', 'identify', 'page', 'group', 'alias'];

    private readonly Backoff $backoff;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpFactory $http,
        ?Backoff $backoff = null,
    ) {
        $this->backoff = $backoff ?? new Backoff($settings->maxRetryAfter);
    }

    public function sendOne(array $event): DeliveryResult
    {
        $ids = self::ids([$event]);
        $type = $event['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::SINGLE_TYPES, true)) {
            return DeliveryResult::failure($ids, null, 'unsupported event type for the single-event endpoint', false, 0);
        }

        try {
            $body = json_encode($event, self::JSON_FLAGS);
        } catch (JsonException $e) {
            return DeliveryResult::failure($ids, null, 'the event cannot be encoded as JSON ('.$e->getMessage().')', false, 0);
        }

        // Never gzip the single-event path: ingestion does not decode it there (HTTP 400).
        return $this->post('/api/s/s2s/'.$type, $body, $ids, batch: false, gzip: false);
    }

    public function sendBatch(array $events): DeliveryResult
    {
        if ($events === []) {
            return DeliveryResult::success([], null, 0);
        }

        $ids = self::ids($events);

        try {
            $body = json_encode([
                'batch' => $events,
                'writeKey' => (string) $this->settings->writeKey,
                'sentAt' => EventFactory::formatTimestamp(Carbon::now()),
            ], self::JSON_FLAGS);
        } catch (JsonException $e) {
            return DeliveryResult::failure($ids, null, 'the batch cannot be encoded as JSON ('.$e->getMessage().')', false, 0);
        }

        return $this->post('/v1/batch', $body, $ids, batch: true, gzip: $this->settings->compression);
    }

    /**
     * @param  list<string>  $ids
     */
    private function post(string $path, string $body, array $ids, bool $batch, bool $gzip): DeliveryResult
    {
        if ($this->settings->host === null || $this->settings->writeKey === null) {
            return DeliveryResult::failure($ids, null, 'Sfere is not configured (SFERE_HOST / SFERE_WRITE_KEY)', false, 0);
        }

        $headers = [
            'Authorization' => 'Basic '.base64_encode($this->settings->writeKey.':'),
            'Accept' => 'application/json',
            'User-Agent' => Library::userAgent(),
        ];

        if ($gzip) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $url = $this->settings->host.$path;
        $maxAttempts = max(1, $this->settings->retries);

        for ($attempt = 1; ; $attempt++) {
            $retryAfter = null;

            try {
                $response = $this->http
                    ->withHeaders($headers)
                    ->timeout($this->settings->timeout)
                    ->connectTimeout($this->settings->connectTimeout)
                    ->withBody($body, 'application/json')
                    ->post($url);

                $result = ResponseClassifier::classify($response->status(), $response->body(), $ids, $batch, $attempt);
                $retryAfter = $response->header('Retry-After');
            } catch (ConnectionException|TransferException $e) {
                $result = ResponseClassifier::connectionFailure($ids, $e->getMessage(), $attempt);
            } catch (Throwable $e) {
                $result = DeliveryResult::failure($ids, null, 'unexpected transport error ('.$e::class.')', false, $attempt);
            }

            if (! $result->retryable || $attempt >= $maxAttempts) {
                return $result;
            }

            Sleep::usleep($this->backoff->delayMs($attempt, $retryAfter === '' ? null : $retryAfter) * 1000);
        }
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $events
     * @return list<string>
     */
    private static function ids(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $id = $event['messageId'] ?? null;
            $ids[] = is_scalar($id) ? (string) $id : '';
        }

        return $ids;
    }
}
