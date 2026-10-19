<?php

namespace Sfere\Tests\Feature\Delivery;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Bus;
use Sfere\Delivery\Sender;
use Sfere\Exceptions\DeliveryException;
use Sfere\Jobs\SendEncryptedEvents;
use Sfere\Jobs\SendEvents;
use Sfere\Transport\DeliveryResult;

final class QueueDeliveryTest extends DeliveryTestCase
{
    public function test_queue_mode_dispatches_a_job_per_batch_on_the_configured_queue(): void
    {
        Bus::fake();
        $this->configure([
            'delivery' => 'queue',
            'queue.connection' => 'redis',
            'queue.name' => 'analytics',
            'queue.tries' => 7,
            'queue.backoff' => [1, 2],
            'batch.max_size' => 2,
        ]);

        $ids = [$this->track(), $this->track(), $this->track()];
        $this->client()->flush();

        $this->assertSame([], $this->transport->sent(), 'nothing is sent in the request');

        $jobs = [];
        Bus::assertDispatched(SendEncryptedEvents::class, function (SendEvents $job) use (&$jobs) {
            $jobs[] = $job;

            return $job->connection === 'redis' && $job->queue === 'analytics'
                && $job->tries === 7 && $job->backoff() === [1, 2];
        });
        Bus::assertDispatchedTimes(SendEncryptedEvents::class, 2);
        $this->assertSame($ids, array_merge(...array_map(fn (SendEvents $j) => Sender::messageIds($j->events), $jobs)));
    }

    public function test_events_carry_their_context_into_the_job(): void
    {
        Bus::fake();
        $this->configure(['delivery' => 'queue']);

        $this->client()->track('purchase', [], userId: 'u-1', context: ['ip' => '203.0.113.7']);
        $this->client()->flush();

        Bus::assertDispatched(SendEncryptedEvents::class, fn (SendEvents $job) => $job->events[0]['context']['ip'] === '203.0.113.7');
    }

    public function test_queued_payloads_are_encrypted_by_default_and_can_be_left_plain(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendEncryptedEvents([]));

        Bus::fake();
        $this->configure(['delivery' => 'queue', 'queue.encrypt' => false]);

        $this->client()->track('purchase', [], userId: 'u-1');
        $this->client()->flush();

        Bus::assertDispatched(SendEvents::class);
        Bus::assertNotDispatched(SendEncryptedEvents::class);
    }

    public function test_the_job_survives_serialisation(): void
    {
        $job = new SendEvents([['messageId' => 'm-1', 'context' => ['ip' => '203.0.113.7']]], 3, [5]);

        $copy = unserialize(serialize($job));

        $this->assertInstanceOf(SendEvents::class, $copy);
        $this->assertSame($job->events, $copy->events);
        $this->assertSame(3, $copy->tries);
        $this->assertSame([5], $copy->backoff());
    }

    public function test_the_job_runs_on_a_sync_queue_at_the_end_of_the_request(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->configure(['delivery' => 'queue']);

        $ids = [$this->track(), $this->track()];
        $this->assertSame([], $this->transport->sent());

        $this->app->terminate();

        $this->assertCount(1, $this->transport->batches);
        $this->assertSame($ids, Sender::messageIds($this->transport->batches[0]));
    }

    public function test_a_retryable_failure_fails_the_attempt_so_the_queue_retries(): void
    {
        $this->transport->respond = fn (array $events) => DeliveryResult::failure(Sender::messageIds($events), 503, 'unavailable', true, 3);

        $this->expectException(DeliveryException::class);

        (new SendEvents([['messageId' => 'm-1']]))->handle($this->app->make(Sender::class));
    }

    public function test_a_non_retryable_failure_completes_the_job(): void
    {
        $this->transport->respond = fn (array $events) => DeliveryResult::failure(Sender::messageIds($events), 400, 'malformed', false);

        (new SendEvents([['messageId' => 'm-1']]))->handle($this->app->make(Sender::class));

        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(1, $this->logger->at('error'));
    }

    public function test_the_job_never_throws_on_success_even_with_throw_exceptions(): void
    {
        $this->configure(['throw_exceptions' => true]);
        $this->transport->respond = fn (array $events) => DeliveryResult::failure(Sender::messageIds($events), 401, 'invalid write key', false);

        (new SendEvents([['messageId' => 'm-1']]))->handle($this->app->make(Sender::class));

        $this->assertCount(1, $this->transport->batches);
    }
}
