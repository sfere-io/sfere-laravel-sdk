<?php

namespace Sfere\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Sfere\Context\ContextMerger;

final class ContextMergerTest extends TestCase
{
    public function test_associative_arrays_merge_recursively_and_later_layers_win(): void
    {
        $merged = ContextMerger::merge(
            ['page' => ['url' => 'https://a.test/', 'referrer' => 'https://ref.test/'], 'locale' => 'ar'],
            ['page' => ['url' => 'https://b.test/'], 'traits' => ['email' => 'a@b.test']],
            ['traits' => ['phone' => '+966500000000'], 'locale' => 'en'],
        );

        $this->assertSame([
            'page' => ['url' => 'https://b.test/', 'referrer' => 'https://ref.test/'],
            'locale' => 'en',
            'traits' => ['email' => 'a@b.test', 'phone' => '+966500000000'],
        ], $merged);
    }

    public function test_lists_and_scalars_are_replaced_not_merged(): void
    {
        $merged = ContextMerger::merge(
            ['tags' => ['a', 'b', 'c'], 'campaign' => ['source' => 'google'], 'session' => ['id' => 1]],
            ['tags' => ['z'], 'campaign' => 'flat', 'session' => ['id' => 2]],
        );

        $this->assertSame(['tags' => ['z'], 'campaign' => 'flat', 'session' => ['id' => 2]], $merged);
    }

    public function test_an_associative_array_replaces_a_scalar_or_list(): void
    {
        $merged = ContextMerger::merge(['a' => 'x', 'b' => [1, 2]], ['a' => ['k' => 'v'], 'b' => ['k' => 'v']]);

        $this->assertSame(['a' => ['k' => 'v'], 'b' => ['k' => 'v']], $merged);
    }

    public function test_no_layers_merge_to_an_empty_array(): void
    {
        $this->assertSame([], ContextMerger::merge());
        $this->assertSame(['a' => 1], ContextMerger::merge([], ['a' => 1], []));
    }
}
