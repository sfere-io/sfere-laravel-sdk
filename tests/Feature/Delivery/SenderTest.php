<?php

namespace Sfere\Tests\Feature\Delivery;

use Illuminate\Support\Facades\Event;
use Sfere\Delivery\Sender;
use Sfere\Events\DeliveryFailed;
use Sfere\Events\EventsSent;
use Sfere\Exceptions\DeliveryException;
use Sfere\Transport\DeliveryResult;

final class SenderTest extends DeliveryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([EventsSent::class, DeliveryFailed::class]);
    }

    public function test_success_fires_events_sent(): void
    {
        $this->configure(['delivery' => 'sync']);

        $id = $this->track();

        Event::assertDispatched(EventsSent::class, fn (EventsSent $e) => $e->messageIds === [$id] && $e->okEvents === 1);
        Event::assertNotDispatched(DeliveryFailed::class);
        $this->assertSame([], $this->logger->records);
    }

    public function test_failure_logs_without_the_key_or_payload_and_fires_delivery_failed(): void
    {
        $this->configure(['delivery' => 'sync']);
        $this->transport->respond = fn (array $events) => DeliveryResult::failure(Sender::messageIds($events), 401, 'invalid write key', false);

        $id = $this->client()->track('purchase', ['email_like' => 'fan@example.com'], userId: 'u-1');

        Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $e) => $e->messageIds === [$id]
            && $e->status === 401 && $e->reason === 'invalid write key' && $e->retryable === false);
        Event::assertNotDispatched(EventsSent::class);

        $errors = $this->logger->at('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('1 event(s) not delivered: invalid write key', $errors[0]['message']);
        $this->assertSame([$id], $errors[0]['context']['messageIds']);
        $this->assertSame(401, $errors[0]['context']['status']);

        $logged = json_encode($this->logger->records);
        $this->assertStringNotContainsString(self::WRITE_KEY, (string) $logged);
        $this->assertStringNotContainsString('5ecret', (string) $logged);
        $this->assertStringNotContainsString('fan@example.com', (string) $logged);
    }

    public function test_a_partial_batch_reports_sent_and_failed_ids(): void
    {
        $this->configure(['delivery' => 'batch']);
        $this->transport->respond = function (array $events) {
            $ids = Sender::messageIds($events);

            return DeliveryResult::partial([$ids[0]], [$ids[1]], 200, '1 of 2 events rejected');
        };

        [$a, $b] = [$this->track(), $this->track()];
        $this->client()->flush();

        Event::assertDispatched(EventsSent::class, fn (EventsSent $e) => $e->messageIds === [$a] && $e->okEvents === 1);
        Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $e) => $e->messageIds === [$b] && ! $e->retryable);
    }

    public function test_a_failure_without_ids_is_attributed_to_the_whole_request(): void
    {
        $this->configure(['delivery' => 'batch']);
        $this->transport->respond = fn () => DeliveryResult::failure([], null, 'connection refused', true, 3);

        $ids = [$this->track(), $this->track()];
        $this->client()->flush();

        Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $e) => $e->messageIds === $ids && $e->retryable && $e->status === null);
        $this->assertSame(3, $this->logger->at('error')[0]['context']['attempts']);
    }

    public function test_failures_do_not_throw_by_default(): void
    {
        $this->configure(['delivery' => 'sync', 'throw_exceptions' => false]);
        $this->transport->respond = fn () => DeliveryResult::failure([], 500, 'server error', true);

        $id = $this->track();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertCount(1, $this->logger->at('error'));
    }

    public function test_failures_throw_when_throw_exceptions_is_on(): void
    {
        $this->configure(['delivery' => 'sync', 'throw_exceptions' => true]);
        $this->transport->respond = fn () => DeliveryResult::failure([], 500, 'server error', true);

        try {
            $this->track();
            $this->fail('expected a DeliveryException');
        } catch (DeliveryException $e) {
            $this->assertSame(500, $e->result->status);
            $this->assertTrue($e->result->retryable);
        }

        // Reported before throwing.
        Event::assertDispatched(DeliveryFailed::class);
        $this->assertCount(1, $this->logger->at('error'));
    }

    public function test_deliver_can_skip_throwing(): void
    {
        $this->configure(['throw_exceptions' => true]);
        $this->transport->respond = fn () => DeliveryResult::failure([], 503, 'unavailable', true);

        $result = $this->app->make(Sender::class)->deliver([['messageId' => 'm-1']], throw: false);

        $this->assertFalse($result->ok);
        $this->assertSame(['m-1'], $result->failedIds);
    }

    public function test_results_of_several_requests_are_combined(): void
    {
        $this->configure(['batch.max_size' => 2]);
        $calls = 0;
        $this->transport->respond = function (array $events) use (&$calls) {
            $calls++;

            return $calls === 2
                ? DeliveryResult::failure(Sender::messageIds($events), 429, 'rate limited', true, 3)
                : DeliveryResult::success(Sender::messageIds($events));
        };

        $events = [['messageId' => 'a'], ['messageId' => 'b'], ['messageId' => 'c'], ['messageId' => 'd'], ['messageId' => 'e']];
        $result = $this->app->make(Sender::class)->deliver($events, throw: false);

        $this->assertFalse($result->ok);
        $this->assertSame(['a', 'b', 'e'], $result->sentIds);
        $this->assertSame(['c', 'd'], $result->failedIds);
        $this->assertSame(429, $result->status);
        $this->assertTrue($result->retryable);
        $this->assertSame(3, $result->attempts);
        Event::assertDispatchedTimes(EventsSent::class, 2);
        Event::assertDispatchedTimes(DeliveryFailed::class, 1);
    }
}
