<?php

namespace Sfere\Tests\Unit;

use Sfere\Sfere;
use Sfere\Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_the_client_resolves(): void
    {
        $this->assertInstanceOf(Sfere::class, $this->app->make(Sfere::class));
        $this->assertSame($this->app->make(Sfere::class), $this->app->make('sfere'));
    }
}
