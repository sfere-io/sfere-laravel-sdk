<?php

namespace Sfere\Tests\Unit\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Sfere\Support\Library;
use Sfere\Support\Settings;
use Sfere\Tests\TestCase;
use Sfere\Transport\Backoff;
use Sfere\Transport\HttpTransport;

final class HttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Carbon::setTestNow('2026-10-19T10:00:00.123Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function transport(array $overrides = []): HttpTransport
    {
        $settings = Settings::fromConfig(array_replace_recursive([
            'host' => self::HOST,
            'write_key' => self::WRITE_KEY,
            'http' => ['retries' => 3, 'max_retry_after' => 10, 'compression' => false],
        ], $overrides));

        // Deterministic jitter: always the ceiling.
        return new HttpTransport($settings, $this->app->make(HttpFactory::class), new Backoff($settings->maxRetryAfter, fn (int $min, int $max): int => $max));
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $id = 'm-1', string $type = 'track'): array
    {
        return ['type' => $type, 'messageId' => $id, 'userId' => '10293', 'event' => 'purchase', 'properties' => ['value' => 10.0, 'url' => 'https://a.example/x'], 'context' => ['ip' => '203.0.113.7']];
    }

    private static function connectionFailure(): \Closure
    {
        return fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request->toPsrRequest()));
    }

    public function test_single_event_goes_to_the_s2s_path_with_basic_auth_and_exact_json(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertTrue($result->ok);
        $this->assertSame(['m-1'], $result->sentIds);
        $this->assertSame(200, $result->status);
        $this->assertSame(1, $result->attempts);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://ingest.example.com/api/s/s2s/track'
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['Basic '.base64_encode(self::WRITE_KEY.':')]
                && $request->header('Content-Type') === ['application/json']
                && $request->header('Accept') === ['application/json']
                && $request->header('User-Agent') === [Library::userAgent()]
                && ! $request->hasHeader('Content-Encoding')
                && $request->body() === '{"type":"track","messageId":"m-1","userId":"10293","event":"purchase","properties":{"value":10.0,"url":"https://a.example/x"},"context":{"ip":"203.0.113.7"}}';
        });
    }

    public function test_single_path_uses_the_event_type(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->transport()->sendOne(['type' => 'identify', 'messageId' => 'm-2', 'userId' => 'u', 'traits' => ['email' => 'a@b.c']]);

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://ingest.example.com/api/s/s2s/identify');
    }

    public function test_unsupported_single_type_is_not_sent(): void
    {
        Http::fake();

        $result = $this->transport()->sendOne(['type' => 'screen', 'messageId' => 'm-1']);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        $this->assertSame(['m-1'], $result->failedIds);
        Http::assertNothingSent();
    }

    public function test_batch_goes_to_v1_batch_with_write_key_and_sent_at_envelope(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'receivedEvents' => 2, 'okEvents' => 2])]);

        $result = $this->transport()->sendBatch([self::event('m-1'), self::event('m-2')]);

        $this->assertTrue($result->ok);
        $this->assertSame(['m-1', 'm-2'], $result->sentIds);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://ingest.example.com/v1/batch'
                && $request->header('Authorization') === ['Basic '.base64_encode(self::WRITE_KEY.':')]
                && array_keys($body) === ['batch', 'writeKey', 'sentAt']
                && $body['writeKey'] === self::WRITE_KEY
                && $body['sentAt'] === '2026-10-19T10:00:00.123Z'
                && count($body['batch']) === 2
                && ! array_key_exists('sentAt', $body['batch'][0])
                && ! array_key_exists('context', array_diff_key($body, ['batch' => 1]));
        });
    }

    public function test_empty_batch_sends_nothing(): void
    {
        Http::fake();

        $this->assertTrue($this->transport()->sendBatch([])->ok);
        Http::assertNothingSent();
    }

    public function test_compression_gzips_batch_bodies(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'receivedEvents' => 1, 'okEvents' => 1])]);

        $this->transport(['http' => ['compression' => true]])->sendBatch([self::event()]);

        Http::assertSent(function (Request $request): bool {
            $decoded = gzdecode($request->body());

            return $request->header('Content-Encoding') === ['gzip']
                && $decoded !== false
                && json_decode($decoded, true)['batch'][0]['messageId'] === 'm-1';
        });
    }

    public function test_compression_never_applies_to_the_single_path(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->transport(['http' => ['compression' => true]])->sendOne(self::event());

        Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('Content-Encoding') && json_decode($r->body(), true)['messageId'] === 'm-1');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retryableStatuses(): array
    {
        return ['429' => [429], '500' => [500], '502' => [502], '503' => [503]];
    }

    #[DataProvider('retryableStatuses')]
    public function test_retries_retryable_statuses_then_succeeds(int $status): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'x'], $status)
            ->push(['error' => 'x'], $status)
            ->push(['ok' => true], 200)]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertTrue($result->ok);
        $this->assertSame(3, $result->attempts);
        Http::assertSentCount(3);
        // Full jitter with the ceiling: 500 ms, then 1000 ms.
        Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
    }

    public function test_gives_up_after_the_configured_attempts(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $result = $this->transport(['http' => ['retries' => 2]])->sendOne(self::event());

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retryable);
        $this->assertSame(503, $result->status);
        $this->assertSame(2, $result->attempts);
        $this->assertSame(['m-1'], $result->failedIds);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_retries_connection_errors(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::connectionFailure()(new Request(new \GuzzleHttp\Psr7\Request('POST', self::HOST))))
            ->push(['ok' => true], 200)]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->attempts);
    }

    public function test_connection_errors_are_retryable_failures_after_the_last_attempt(): void
    {
        Http::fake(['*' => self::connectionFailure()]);

        $result = $this->transport()->sendBatch([self::event()]);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retryable);
        $this->assertNull($result->status);
        $this->assertSame(3, $result->attempts);
        $this->assertStringStartsWith('connection error:', (string) $result->reason);
        Sleep::assertSleptTimes(2);
    }

    public function test_retry_after_seconds_is_honoured(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '3'])
            ->push(['ok' => true], 200)]);

        $this->transport()->sendOne(self::event());

        Sleep::assertSequence([Sleep::for(3000)->milliseconds()]);
    }

    public function test_retry_after_is_capped(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 503, ['Retry-After' => '120'])
            ->push(['ok' => true], 200)]);

        $this->transport(['http' => ['max_retry_after' => 10]])->sendOne(self::event());

        Sleep::assertSequence([Sleep::for(10_000)->milliseconds()]);
    }

    public function test_retry_after_http_date_is_honoured(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 429, ['Retry-After' => Carbon::now()->addSeconds(5)->toRfc7231String()])
            ->push(['ok' => true], 200)]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertTrue($result->ok);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds >= 4000 && $duration->totalMilliseconds <= 5000);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonRetryableStatuses(): array
    {
        return ['400' => [400], '401' => [401], '402' => [402], '403' => [403], '404' => [404], '413' => [413]];
    }

    #[DataProvider('nonRetryableStatuses')]
    public function test_never_retries_other_4xx(int $status): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], $status)]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        $this->assertSame($status, $result->status);
        $this->assertSame(1, $result->attempts);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_200_with_error_body_is_a_failure_without_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => 'no destinations found for stream: abc'])]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        $this->assertSame('no destinations configured for this stream', $result->reason);
        Http::assertSentCount(1);
    }

    public function test_partial_batch_reports_the_rejected_message_ids(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => false, 'receivedEvents' => 3, 'okEvents' => 2,
            'errors' => ['Message ID: m-2: event error: bad'],
        ])]);

        $result = $this->transport()->sendBatch([self::event('m-1'), self::event('m-2'), self::event('m-3')]);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        $this->assertSame(['m-1', 'm-3'], $result->sentIds);
        $this->assertSame(['m-2'], $result->failedIds);
        Http::assertSentCount(1);
    }

    public function test_reasons_never_contain_the_write_key(): void
    {
        Http::fake(['*' => Http::response(['error' => 'stream not found: '.self::WRITE_KEY], 401)]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertStringContainsString('invalid write key', (string) $result->reason);
        $this->assertStringNotContainsString('5ecret', (string) $result->reason);
        $this->assertStringNotContainsString('k3yid', (string) $result->reason);
    }

    public function test_missing_configuration_fails_without_sending(): void
    {
        Http::fake();

        $result = (new HttpTransport(Settings::fromConfig([]), $this->app->make(HttpFactory::class)))->sendOne(self::event());

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        Http::assertNothingSent();
    }

    public function test_unexpected_exceptions_never_escape(): void
    {
        Http::fake(['*' => fn () => throw new \LogicException('boom')]);

        $result = $this->transport()->sendOne(self::event());

        $this->assertFalse($result->ok);
        $this->assertFalse($result->retryable);
        $this->assertSame(['m-1'], $result->failedIds);
    }
}
