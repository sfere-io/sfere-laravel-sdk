<?php

namespace Sfere\Exceptions;

/**
 * The event cannot be sent as given: missing event name, no userId or anonymousId, an invalid
 * caller messageId, a placeholder userId, or an event over the size limit.
 */
class InvalidEventException extends SfereException
{
    public static function because(string $type, string $reason): self
    {
        return new self("Sfere: invalid {$type} event: {$reason}.");
    }
}
