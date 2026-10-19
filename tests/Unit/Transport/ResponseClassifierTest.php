<?php

namespace Sfere\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Sfere\Transport\ResponseClassifier;

final class ResponseClassifierTest extends TestCase
{
    public function test_single_ok_true_is_success(): void
    {
        $r = ResponseClassifier::classify(200, '{"ok":true}', ['a'], batch: false);

        $this->assertTrue($r->ok);
        $this->assertSame(['a'], $r->sentIds);
        $this->assertSame([], $r->failedIds);
    }

    public function test_single_200_with_error_is_a_non_retryable_failure(): void
    {
        $r = ResponseClassifier::classify(200, '{"error":"something else"}', ['a'], batch: false);

        $this->assertFalse($r->ok);
        $this->assertFalse($r->retryable);
        $this->assertSame('not accepted: something else', $r->reason);
    }

    public function test_no_destinations_has_a_clear_reason(): void
    {
        $r = ResponseClassifier::classify(200, '{"error":"no destinations found for stream: s1"}', ['a'], batch: false);

        $this->assertSame('no destinations configured for this stream', $r->reason);
        $this->assertFalse($r->retryable);
    }

    public function test_non_json_2xx_is_a_non_retryable_failure(): void
    {
        foreach (['<html>ok</html>', '', '"ok"'] as $body) {
            $r = ResponseClassifier::classify(200, $body, ['a'], batch: false);
            $this->assertFalse($r->ok, $body);
            $this->assertFalse($r->retryable, $body);
            $this->assertStringContainsString('non-JSON', (string) $r->reason);
        }
    }

    public function test_status_rows(): void
    {
        $cases = [
            [400, false, 'malformed'],
            [401, false, 'invalid write key'],
            [402, false, 'throttled'],
            [403, false, 'rejected (HTTP 403)'],
            [404, false, 'rejected (HTTP 404)'],
            [429, true, 'rate limited'],
            [500, true, 'server error'],
            [503, true, 'server error'],
        ];

        foreach ($cases as [$status, $retryable, $reason]) {
            $r = ResponseClassifier::classify($status, '{"error":"x"}', ['a', 'b'], batch: true, attempts: 2);
            $this->assertFalse($r->ok, (string) $status);
            $this->assertSame($retryable, $r->retryable, (string) $status);
            $this->assertSame($status, $r->status);
            $this->assertSame(['a', 'b'], $r->failedIds);
            $this->assertSame(2, $r->attempts);
            $this->assertStringContainsString($reason, (string) $r->reason, (string) $status);
        }
    }

    public function test_401_reason_never_echoes_the_body(): void
    {
        $r = ResponseClassifier::classify(401, '{"error":"stream not found: keyid:secret"}', ['a'], batch: false);

        $this->assertStringNotContainsString('secret', (string) $r->reason);
    }

    public function test_batch_ok_is_success(): void
    {
        $r = ResponseClassifier::classify(200, '{"ok":true,"receivedEvents":2,"okEvents":2}', ['a', 'b'], batch: true);

        $this->assertTrue($r->ok);
        $this->assertSame(['a', 'b'], $r->sentIds);
    }

    public function test_batch_deduplicated_events_are_not_failures(): void
    {
        $r = ResponseClassifier::classify(200, '{"ok":true,"receivedEvents":3,"okEvents":2,"deduplicatedEvents":1,"processedEvents":2}', ['a', 'b', 'c'], batch: true);

        $this->assertTrue($r->ok);
    }

    public function test_batch_partial_maps_errors_to_message_ids(): void
    {
        $body = json_encode(['ok' => false, 'receivedEvents' => 3, 'okEvents' => 1, 'errors' => [
            'Message ID: a: event error: bad field',
            'Message ID: c: no destinations found for stream: s1',
        ]]);
        $r = ResponseClassifier::classify(200, (string) $body, ['a', 'b', 'c'], batch: true);

        $this->assertFalse($r->ok);
        $this->assertFalse($r->retryable);
        $this->assertSame(['b'], $r->sentIds);
        $this->assertSame(['a', 'c'], $r->failedIds);
        $this->assertSame('2 of 3 event(s) rejected: event error: bad field; no destinations configured for this stream', $r->reason);
    }

    public function test_batch_all_rejected_is_a_failure(): void
    {
        $body = json_encode(['ok' => false, 'receivedEvents' => 1, 'okEvents' => 0, 'errors' => ['Message ID: a: throttled']]);
        $r = ResponseClassifier::classify(200, (string) $body, ['a'], batch: true);

        $this->assertFalse($r->ok);
        $this->assertSame([], $r->sentIds);
        $this->assertSame(['a'], $r->failedIds);
    }

    public function test_batch_unaccounted_failures_mark_every_event_failed(): void
    {
        // Two events failed but only one is identifiable: report all as failed.
        $body = json_encode(['ok' => false, 'receivedEvents' => 3, 'okEvents' => 1, 'errors' => ['Message ID: a: bad', 'garbled']]);
        $r = ResponseClassifier::classify(200, (string) $body, ['a', 'b', 'c'], batch: true);

        $this->assertSame([], $r->sentIds);
        $this->assertSame(['a', 'b', 'c'], $r->failedIds);
    }

    public function test_batch_not_ok_without_errors_marks_every_event_failed(): void
    {
        $r = ResponseClassifier::classify(200, '{"ok":false,"receivedEvents":2,"okEvents":2}', ['a', 'b'], batch: true);

        $this->assertFalse($r->ok);
        $this->assertSame(['a', 'b'], $r->failedIds);
    }

    public function test_connection_failure_is_retryable(): void
    {
        $r = ResponseClassifier::connectionFailure(['a'], 'cURL error 28: timed out', 3);

        $this->assertFalse($r->ok);
        $this->assertTrue($r->retryable);
        $this->assertNull($r->status);
        $this->assertSame(3, $r->attempts);
    }
}
