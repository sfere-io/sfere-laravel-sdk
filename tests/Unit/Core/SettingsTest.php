<?php

namespace Sfere\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sfere\Support\Settings;

final class SettingsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        $config = require __DIR__.'/../../../config/sfere.php';
        $config['host'] = 'https://ingest.example.com';
        $config['write_key'] = 'keyid:secret';
        foreach ($overrides as $key => $value) {
            data_set($config, $key, $value);
        }

        return $config;
    }

    public function test_defaults_from_the_published_config(): void
    {
        $settings = Settings::fromConfig(self::config());

        $this->assertTrue($settings->enabled);
        $this->assertSame('https://ingest.example.com', $settings->host);
        $this->assertSame('keyid:secret', $settings->writeKey);
        $this->assertSame('batch', $settings->delivery);
        $this->assertSame(100, $settings->batchMaxSize);
        $this->assertSame(500_000, $settings->batchMaxBytes);
        $this->assertSame(10, $settings->batchMaxWaitSeconds);
        $this->assertNull($settings->queueConnection);
        $this->assertSame('default', $settings->queueName);
        $this->assertSame(5, $settings->queueTries);
        $this->assertSame([10, 30, 60, 120], $settings->queueBackoff);
        $this->assertSame(3.0, $settings->timeout);
        $this->assertSame(2.0, $settings->connectTimeout);
        $this->assertSame(3, $settings->retries);
        $this->assertSame(10, $settings->maxRetryAfter);
        $this->assertFalse($settings->compression);
        $this->assertTrue($settings->includeRequest);
        $this->assertTrue($settings->trustProxy);
        $this->assertSame('ajs_anonymous_id', $settings->anonymousIdCookie);
        $this->assertSame([], $settings->staticContext);
        $this->assertFalse($settings->throwExceptions);
        $this->assertNull($settings->logChannel);
        $this->assertTrue($settings->isOperational());
        $this->assertSame([], $settings->problems());
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function hosts(): array
    {
        return [
            'origin' => ['https://ingest.example.com', 'https://ingest.example.com'],
            'trailing slash' => ['https://ingest.example.com/', 'https://ingest.example.com'],
            'uppercase' => ['HTTPS://Ingest.Example.COM', 'https://ingest.example.com'],
            'port' => ['http://localhost:8080', 'http://localhost:8080'],
            'whitespace' => ['  https://ingest.example.com  ', 'https://ingest.example.com'],
            'path' => ['https://ingest.example.com/api/s/s2s/track', null],
            'batch path' => ['https://ingest.example.com/v1/batch', null],
            'query' => ['https://ingest.example.com?x=1', null],
            'fragment' => ['https://ingest.example.com#top', null],
            'credentials' => ['https://user:pass@ingest.example.com', null],
            'no scheme' => ['ingest.example.com', null],
            'other scheme' => ['ftp://ingest.example.com', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('hosts')]
    public function test_host_must_be_an_http_origin(?string $host, ?string $expected): void
    {
        $this->assertSame($expected, Settings::fromConfig(self::config(['host' => $host]))->host);
    }

    public function test_batch_limits_are_capped_to_the_ingestion_limits(): void
    {
        $settings = Settings::fromConfig(self::config(['batch.max_size' => 500, 'batch.max_bytes' => 10_000_000]));
        $this->assertSame(100, $settings->batchMaxSize);
        $this->assertSame(500_000, $settings->batchMaxBytes);

        $settings = Settings::fromConfig(self::config(['batch.max_size' => 0, 'batch.max_bytes' => 10, 'batch.max_wait_seconds' => -5]));
        $this->assertSame(1, $settings->batchMaxSize);
        $this->assertSame(1_000, $settings->batchMaxBytes);
        $this->assertSame(0, $settings->batchMaxWaitSeconds);

        $settings = Settings::fromConfig(self::config(['batch.max_size' => '25']));
        $this->assertSame(25, $settings->batchMaxSize);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function deliveries(): array
    {
        return [
            'sync' => ['sync', 'sync'],
            'queue' => ['queue', 'queue'],
            'uppercase' => ['QUEUE', 'queue'],
            'unknown' => ['kafka', 'batch'],
            'null' => [null, 'batch'],
        ];
    }

    #[DataProvider('deliveries')]
    public function test_unknown_delivery_falls_back_to_batch(mixed $delivery, string $expected): void
    {
        $this->assertSame($expected, Settings::fromConfig(self::config(['delivery' => $delivery]))->delivery);
    }

    public function test_env_strings_are_read_as_booleans(): void
    {
        $settings = Settings::fromConfig(self::config(['enabled' => 'false', 'throw_exceptions' => 'true', 'http.compression' => '1']));

        $this->assertFalse($settings->enabled);
        $this->assertTrue($settings->throwExceptions);
        $this->assertTrue($settings->compression);
    }

    public function test_empty_or_unparseable_booleans_keep_their_default(): void
    {
        $settings = Settings::fromConfig(self::config(['enabled' => '', 'context.include_request' => null, 'throw_exceptions' => 'maybe']));

        $this->assertTrue($settings->enabled);
        $this->assertTrue($settings->includeRequest);
        $this->assertFalse($settings->throwExceptions);
    }

    public function test_anonymous_id_cookie_can_be_disabled(): void
    {
        $this->assertNull(Settings::fromConfig(self::config(['context.anonymous_id_cookie' => null]))->anonymousIdCookie);
    }

    public function test_problems_explain_why_the_sdk_is_a_no_op(): void
    {
        $this->assertSame(['disabled (SFERE_ENABLED=false)'], Settings::fromConfig(self::config(['enabled' => false]))->problems());

        $problems = Settings::fromConfig(self::config(['host' => null, 'write_key' => null]))->problems();
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('SFERE_HOST', $problems[0]);
        $this->assertStringContainsString('SFERE_WRITE_KEY is missing', $problems[1]);

        $settings = Settings::fromConfig(self::config(['host' => 'https://ingest.example.com/v1/batch']));
        $this->assertFalse($settings->isOperational());
        $this->assertStringContainsString('SFERE_HOST', $settings->problems()[0]);
    }

    public function test_a_key_without_a_colon_is_rejected_without_logging_the_key(): void
    {
        $settings = Settings::fromConfig(self::config(['write_key' => 'plaintextbrowserkey123']));

        $this->assertFalse($settings->isOperational());
        $this->assertSame(['SFERE_WRITE_KEY is not a keyId:secret server key'], $settings->problems());
        $this->assertStringNotContainsString('plaintextbrowserkey123', implode(' ', $settings->problems()));
    }

    public function test_queue_and_http_settings_are_normalised(): void
    {
        $settings = Settings::fromConfig(self::config([
            'queue.connection' => 'redis',
            'queue.name' => '',
            'queue.tries' => 0,
            'queue.backoff' => ['5', -1, 'x'],
            'http.timeout' => '1.5',
            'http.connect_timeout' => 0,
            'http.retries' => 0,
            'context.static' => 'not-an-array',
        ]));

        $this->assertSame('redis', $settings->queueConnection);
        $this->assertSame('default', $settings->queueName);
        $this->assertSame(1, $settings->queueTries);
        $this->assertSame([5, 0, 0], $settings->queueBackoff);
        $this->assertSame(1.5, $settings->timeout);
        $this->assertSame(0.1, $settings->connectTimeout);
        $this->assertSame(1, $settings->retries);
        $this->assertSame([], $settings->staticContext);
    }
}
