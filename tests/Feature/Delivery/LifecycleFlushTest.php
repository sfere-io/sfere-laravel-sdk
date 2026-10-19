<?php

namespace Sfere\Tests\Feature\Delivery;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Laravel\Octane\Events\RequestTerminated;
use RuntimeException;
use Sfere\Buffer\EventBuffer;
use Sfere\Delivery\BatchDispatcher;
use Sfere\Delivery\Sender;
use Sfere\Sfere;
use Sfere\Support\Settings;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class LifecycleFlushTest extends DeliveryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['delivery' => 'batch']);

        if (! class_exists(RequestTerminated::class)) {
            require_once __DIR__.'/Stubs/RequestTerminated.php';
        }
    }

    public function test_it_flushes_when_the_app_terminates(): void
    {
        $id = $this->track();
        $this->assertSame([], $this->transport->batches);

        $this->app->terminate();

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
    }

    public function test_it_flushes_after_each_queued_job(): void
    {
        $id = $this->track();

        event(new JobProcessed('redis', $this->createStub(Job::class)));

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
    }

    public function test_it_flushes_after_a_failed_job(): void
    {
        $id = $this->track();

        event(new JobFailed('redis', $this->createStub(Job::class), new RuntimeException('job broke')));

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
    }

    public function test_it_flushes_after_a_console_command(): void
    {
        $id = $this->track();

        event(new CommandFinished('app:import', new ArrayInput([]), new NullOutput, 0));

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
    }

    public function test_it_flushes_and_empties_the_buffer_when_an_octane_request_ends(): void
    {
        $id = $this->track();

        event(new RequestTerminated($this->app, clone $this->app, null, null));

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
        $this->assertTrue($this->app->make(EventBuffer::class)->isEmpty(), 'the next request starts with an empty buffer');
    }

    public function test_it_flushes_a_client_resolved_only_in_the_octane_sandbox(): void
    {
        $sandbox = clone $this->app;
        $settings = $this->app->make(Settings::class);
        $client = $this->app->make(Sfere::class)->withDispatcher(
            new BatchDispatcher($this->app->make(Sender::class), new EventBuffer($settings)),
        );
        $this->app->forgetInstance(Sfere::class);
        $sandbox->instance(Sfere::class, $client);

        $id = $client->track('view_item', [], userId: 'u-1');
        $this->assertSame([], $this->transport->sent());

        event(new RequestTerminated($this->app, $sandbox, null, null));

        $this->assertSame([$id], Sender::messageIds($this->transport->sent()));
    }

    public function test_it_never_builds_a_client_just_to_flush(): void
    {
        $this->assertFalse($this->app->resolved(Sfere::class));

        $this->app->terminate();
        event(new JobProcessed('redis', $this->createStub(Job::class)));

        $this->assertFalse($this->app->resolved(Sfere::class));
        $this->assertSame([], $this->transport->sent());
    }

    public function test_a_failing_flush_does_not_break_termination(): void
    {
        $this->transport->respond = fn () => throw new RuntimeException('network down');
        $this->track();

        $this->app->terminate();

        $this->assertCount(1, $this->logger->at('error'));
        $this->assertTrue($this->app->make(EventBuffer::class)->isEmpty());
    }
}
