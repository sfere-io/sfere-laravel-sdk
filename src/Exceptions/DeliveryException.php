<?php

namespace Sfere\Exceptions;

use Sfere\Transport\DeliveryResult;

/**
 * Sfere ingestion did not accept some events after every retry.
 */
class DeliveryException extends SfereException
{
    public function __construct(public readonly DeliveryResult $result)
    {
        parent::__construct(sprintf(
            'Sfere: %d event(s) not delivered (status %s): %s',
            count($result->failedIds),
            $result->status ?? 'none',
            $result->reason ?? 'unknown error',
        ));
    }
}
