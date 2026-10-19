<?php

namespace Sfere\Transport;

/**
 * The outcome of one send. `ok` is true only when every event was accepted.
 */
final readonly class DeliveryResult
{
    /**
     * @param  list<string>  $sentIds  messageIds ingestion accepted
     * @param  list<string>  $failedIds  messageIds it did not
     */
    public function __construct(
        public bool $ok,
        public array $sentIds,
        public array $failedIds,
        public ?int $status = null,
        public ?string $reason = null,
        public bool $retryable = false,
        public int $attempts = 1,
    ) {}

    /**
     * @param  list<string>  $ids
     */
    public static function success(array $ids, ?int $status = 200, int $attempts = 1): self
    {
        return new self(true, $ids, [], $status, null, false, $attempts);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function failure(array $ids, ?int $status, string $reason, bool $retryable, int $attempts = 1): self
    {
        return new self(false, [], $ids, $status, $reason, $retryable, $attempts);
    }

    /**
     * Some events of a batch were rejected (`okEvents < receivedEvents`). Never retryable.
     *
     * @param  list<string>  $sentIds
     * @param  list<string>  $failedIds
     */
    public static function partial(array $sentIds, array $failedIds, ?int $status, string $reason, int $attempts = 1): self
    {
        return new self(false, $sentIds, $failedIds, $status, $reason, false, $attempts);
    }
}
