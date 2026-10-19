<?php

namespace Sfere\Support;

/**
 * The package configuration, normalised and typed once at boot.
 */
final readonly class Settings
{
    /** Hard limits of the ingestion endpoint (WIRE-CONTRACT §5). */
    public const MAX_BATCH_SIZE = 100;

    public const MAX_BATCH_BYTES = 500_000;

    public const DELIVERY_MODES = ['sync', 'batch', 'queue'];

    /**
     * @param  list<int>  $queueBackoff
     * @param  array<string, mixed>  $staticContext
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $host = null,
        public ?string $writeKey = null,
        public string $delivery = 'batch',
        public int $batchMaxSize = self::MAX_BATCH_SIZE,
        public int $batchMaxBytes = self::MAX_BATCH_BYTES,
        public int $batchMaxWaitSeconds = 10,
        public ?string $queueConnection = null,
        public string $queueName = 'default',
        public int $queueTries = 5,
        public array $queueBackoff = [10, 30, 60, 120],
        public bool $queueEncrypt = true,
        public float $timeout = 3.0,
        public float $connectTimeout = 2.0,
        public int $retries = 3,
        public int $maxRetryAfter = 10,
        public bool $compression = false,
        public bool $includeRequest = true,
        public bool $trustProxy = true,
        public ?string $anonymousIdCookie = 'ajs_anonymous_id',
        public array $staticContext = [],
        public bool $throwExceptions = false,
        public ?string $logChannel = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `sfere` config array
     */
    public static function fromConfig(array $config): self
    {
        $get = static fn (string $key, mixed $default = null): mixed => data_get($config, $key, $default);

        $delivery = strtolower(self::string($get('delivery')) ?? 'batch');

        return new self(
            enabled: self::bool($get('enabled'), true),
            host: self::normaliseHost(self::string($get('host'))),
            writeKey: self::string($get('write_key')),
            delivery: in_array($delivery, self::DELIVERY_MODES, true) ? $delivery : 'batch',
            batchMaxSize: max(1, min(self::MAX_BATCH_SIZE, self::int($get('batch.max_size'), self::MAX_BATCH_SIZE))),
            batchMaxBytes: max(1_000, min(self::MAX_BATCH_BYTES, self::int($get('batch.max_bytes'), self::MAX_BATCH_BYTES))),
            batchMaxWaitSeconds: max(0, self::int($get('batch.max_wait_seconds'), 10)),
            queueConnection: self::string($get('queue.connection')),
            queueName: self::string($get('queue.name')) ?? 'default',
            queueTries: max(1, self::int($get('queue.tries'), 5)),
            queueBackoff: array_values(array_map(static fn (mixed $s): int => max(0, self::int($s, 0)), (array) $get('queue.backoff', [10, 30, 60, 120]))),
            queueEncrypt: self::bool($get('queue.encrypt'), true),
            timeout: max(0.1, self::float($get('http.timeout'), 3.0)),
            connectTimeout: max(0.1, self::float($get('http.connect_timeout'), 2.0)),
            retries: max(1, self::int($get('http.retries'), 3)),
            maxRetryAfter: max(0, self::int($get('http.max_retry_after'), 10)),
            compression: self::bool($get('http.compression'), false),
            includeRequest: self::bool($get('context.include_request'), true),
            trustProxy: self::bool($get('context.trust_proxy'), true),
            anonymousIdCookie: self::string($get('context.anonymous_id_cookie', 'ajs_anonymous_id')),
            staticContext: self::assoc($get('context.static', [])),
            throwExceptions: self::bool($get('throw_exceptions'), false),
            logChannel: self::string($get('log_channel')),
        );
    }

    /**
     * Why the SDK cannot send, or an empty list when it can. Never includes the key itself.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (! $this->enabled) {
            $problems[] = 'disabled (SFERE_ENABLED=false)';
        }
        if ($this->host === null) {
            $problems[] = 'SFERE_HOST is missing or is not an http(s) origin without a path (e.g. https://ingest.example.com)';
        }
        if ($this->writeKey === null) {
            $problems[] = 'SFERE_WRITE_KEY is missing';
        } elseif (! str_contains($this->writeKey, ':')) {
            $problems[] = 'SFERE_WRITE_KEY is not a keyId:secret server key';
        }

        return $problems;
    }

    public function isOperational(): bool
    {
        return $this->problems() === [];
    }

    /**
     * `https://Ingest.example.com/` -> `https://ingest.example.com`. Anything with a path, query,
     * fragment, credentials or a non-http(s) scheme is rejected (null), like `parseHost` in
     * `@fanfinity/sfere-node`.
     */
    public static function normaliseHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $parts = parse_url($host);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $path = parse_url($host, PHP_URL_PATH);
        if (! in_array($path, [null, '', '/'], true)
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * `true`/`"true"`/`"1"`/`"on"` and their opposites; null, `""` (an empty env var) or anything
     * unparseable keep the default, so `SFERE_ENABLED=` does not silently disable the SDK.
     */
    private static function bool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function float(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
