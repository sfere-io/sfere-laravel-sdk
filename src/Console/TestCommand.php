<?php

namespace Sfere\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sfere\Contracts\Transport;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Payload\EventFactory;
use Sfere\Payload\Scope;
use Sfere\Support\Settings;

/**
 * Sends one real `track` event straight to Sfere ingestion (whatever the delivery mode) and
 * reports the outcome, to check the host and write key.
 */
final class TestCommand extends Command
{
    protected $signature = 'sfere:test';

    protected $description = 'Send a test event to Sfere ingestion and report the result';

    public function handle(Settings $settings, EventFactory $factory, Transport $transport): int
    {
        $this->line('Host:      '.($settings->host ?? '<missing or invalid>'));
        $this->line('Write key: '.self::keyId($settings->writeKey));

        $problems = $settings->problems();
        if ($problems !== []) {
            $this->error('Sfere is not configured, so no event was sent:');
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }
            $this->serverKeyReminder();

            return self::FAILURE;
        }

        try {
            $event = $factory->make(
                'track',
                ['event' => 'sfere_sdk_test', 'properties' => ['source' => 'sfere:test']],
                new Scope,
                null,
                'sfere-test-'.Str::lower(Str::random(16)),
                null,
                [],
                (string) Str::uuid(),
            );
        } catch (InvalidEventException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $transport->sendOne($event);

        if ($result->ok) {
            $this->info("Event delivered (messageId {$event['messageId']}, HTTP ".($result->status ?? '?').').');
            $this->serverKeyReminder();

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Event not delivered: %s (HTTP %s, %d attempt(s)%s).',
            $result->reason ?? 'unknown error',
            $result->status ?? 'none',
            $result->attempts,
            $result->retryable ? ', retryable' : '',
        ));
        $this->serverKeyReminder();

        return self::FAILURE;
    }

    private function serverKeyReminder(): void
    {
        $this->newLine();
        $this->warn('SFERE_WRITE_KEY must be a SERVER key. A browser key is accepted too, but then every event reports your server\'s IP instead of your customer\'s.');
    }

    /**
     * The public key id only; the secret is never printed.
     */
    private static function keyId(?string $writeKey): string
    {
        if ($writeKey === null) {
            return '<missing>';
        }

        return str_contains($writeKey, ':') ? Str::before($writeKey, ':').':********' : '<not a keyId:secret key>';
    }
}
