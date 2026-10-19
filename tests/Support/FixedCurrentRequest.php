<?php

namespace Sfere\Tests\Support;

use Illuminate\Http\Request;
use Sfere\Context\CurrentRequest;

/**
 * A CurrentRequest that always returns the given request (or none).
 */
final class FixedCurrentRequest extends CurrentRequest
{
    public function __construct(private readonly ?Request $request = null) {}

    public function get(): ?Request
    {
        return $this->request;
    }
}
