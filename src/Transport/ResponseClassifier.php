<?php

namespace Sfere\Transport;

/**
 * Turns an ingestion response into a DeliveryResult (SFERE_SDK_SPEC §2, WIRE-CONTRACT §6).
 *
 * Ingestion answers many failures with HTTP 200, so the body decides: any 2xx whose JSON body is
 * not `ok === true` is a failure. Reasons never contain the write key or event payloads.
 */
final class ResponseClassifier
{
    /** Longest ingestion error text copied into a reason. */
    private const MAX_REASON_LENGTH = 200;

    private const NO_DESTINATIONS = 'no destinations configured for this stream';

    /**
     * @param  list<string>  $ids  messageIds of the events in the request, in order
     */
    public static function classify(int $status, string $body, array $ids, bool $batch, int $attempts = 1): DeliveryResult
    {
        if ($status >= 200 && $status < 300) {
            return self::classifySuccessStatus($status, $body, $ids, $batch, $attempts);
        }

        $error = self::errorText(self::decode($body));

        return match (true) {
            $status === 401 => DeliveryResult::failure($ids, $status, 'invalid write key (stream not found); check SFERE_WRITE_KEY is a server key of this stream', false, $attempts),
            $status === 402 => DeliveryResult::failure($ids, $status, self::withError('throttled by Sfere ingestion (HTTP 402)', $error), false, $attempts),
            $status === 429 => DeliveryResult::failure($ids, $status, 'rate limited by Sfere ingestion (HTTP 429)', true, $attempts),
            $status >= 500 => DeliveryResult::failure($ids, $status, "Sfere ingestion server error (HTTP {$status})", true, $attempts),
            $status === 400 => DeliveryResult::failure($ids, $status, self::withError('rejected as malformed (HTTP 400)', $error), false, $attempts),
            default => DeliveryResult::failure($ids, $status, self::withError("rejected (HTTP {$status})", $error), false, $attempts),
        };
    }

    /**
     * A network error (no response): connection refused, DNS, TLS, timeout. Always retryable.
     *
     * @param  list<string>  $ids
     */
    public static function connectionFailure(array $ids, string $message, int $attempts = 1): DeliveryResult
    {
        return DeliveryResult::failure($ids, null, 'connection error: '.self::truncate($message), true, $attempts);
    }

    /**
     * @param  list<string>  $ids
     */
    private static function classifySuccessStatus(int $status, string $body, array $ids, bool $batch, int $attempts): DeliveryResult
    {
        $json = self::decode($body);
        if ($json === null) {
            return DeliveryResult::failure($ids, $status, "unexpected non-JSON response (HTTP {$status})", false, $attempts);
        }

        if (! $batch) {
            if (($json['ok'] ?? null) === true) {
                return DeliveryResult::success($ids, $status, $attempts);
            }
            $error = self::errorText($json);

            return DeliveryResult::failure(
                $ids,
                $status,
                $error !== null && self::isNoDestinations($error) ? self::NO_DESTINATIONS : self::withError('not accepted', $error),
                false,
                $attempts,
            );
        }

        return self::classifyBatch($status, $json, $ids, $attempts);
    }

    /**
     * `{ok, receivedEvents, okEvents, errors?[], deduplicatedEvents?, processedEvents?}`.
     *
     * `ok` is false whenever any processed event failed. Events dropped by in-batch deduplication
     * are not failures (`ok` stays true), so `okEvents < receivedEvents` alone is not used.
     *
     * Failed events are identified from `errors[]` ("Message ID: <id>: <reason>"). When the errors
     * do not account for every missing event, the events that cannot be confirmed are reported as
     * failed too: over-reporting a failure is safer than silently losing an event.
     *
     * @param  array<array-key, mixed>  $json
     * @param  list<string>  $ids
     */
    private static function classifyBatch(int $status, array $json, array $ids, int $attempts): DeliveryResult
    {
        $errors = array_values(array_filter(
            is_array($json['errors'] ?? null) ? $json['errors'] : [],
            'is_string',
        ));

        if (($json['ok'] ?? null) === true && $errors === []) {
            return DeliveryResult::success($ids, $status, $attempts);
        }

        $failed = [];
        $reasons = [];
        foreach ($errors as $error) {
            if (preg_match('/^Message ID: (.+?): (.*)$/s', $error, $m) === 1 && in_array($m[1], $ids, true)) {
                $failed[$m[1]] = true;
                $reasons[] = $m[2];
            } else {
                $reasons[] = $error;
            }
        }

        $okEvents = is_int($json['okEvents'] ?? null) ? $json['okEvents'] : null;
        $processed = is_int($json['processedEvents'] ?? null) ? $json['processedEvents']
            : (is_int($json['receivedEvents'] ?? null) ? $json['receivedEvents'] : count($ids));
        $unaccounted = $okEvents === null ? 0 : $processed - $okEvents - count($failed);

        if ($unaccounted > 0 || ($failed === [] && ($json['ok'] ?? null) !== true)) {
            // Cannot tell which events were rejected: report every unconfirmed one as failed.
            $failed = array_fill_keys($ids, true);
        }

        $failedIds = array_values(array_filter($ids, static fn (string $id): bool => isset($failed[$id])));
        $sentIds = array_values(array_filter($ids, static fn (string $id): bool => ! isset($failed[$id])));

        $reason = self::summarise($reasons, count($failedIds), count($ids));

        return $sentIds === []
            ? DeliveryResult::failure($failedIds, $status, $reason, false, $attempts)
            : DeliveryResult::partial($sentIds, $failedIds, $status, $reason, $attempts);
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function summarise(array $reasons, int $failed, int $total): string
    {
        $unique = array_values(array_unique(array_map(
            static fn (string $r): string => self::isNoDestinations($r) ? self::NO_DESTINATIONS : self::truncate($r),
            $reasons,
        )));

        $summary = "{$failed} of {$total} event(s) rejected";
        if ($unique === []) {
            return $summary;
        }

        return $summary.': '.self::truncate(implode('; ', array_slice($unique, 0, 3)));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $json
     */
    private static function errorText(?array $json): ?string
    {
        $error = $json['error'] ?? null;

        return is_string($error) && $error !== '' ? self::truncate($error) : null;
    }

    private static function withError(string $reason, ?string $error): string
    {
        return $error === null ? $reason : $reason.': '.$error;
    }

    private static function isNoDestinations(string $error): bool
    {
        return stripos($error, 'no destinations') !== false;
    }

    private static function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_REASON_LENGTH ? mb_substr($text, 0, self::MAX_REASON_LENGTH).'…' : $text;
    }
}
