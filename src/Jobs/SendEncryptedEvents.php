<?php

namespace Sfere\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * SendEvents with its payload encrypted on the queue (`queue.encrypt`, the default): queued
 * events carry PII such as traits, IP addresses and click IDs.
 */
final class SendEncryptedEvents extends SendEvents implements ShouldBeEncrypted {}
