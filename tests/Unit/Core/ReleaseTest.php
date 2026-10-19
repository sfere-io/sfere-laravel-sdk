<?php

namespace Sfere\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Sfere\Support\Library;

/**
 * Catches a version bump without a CHANGELOG entry (or the reverse) on every PR, before a tag
 * fails the release workflow. Runs the same guard the release workflow runs.
 */
final class ReleaseTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../../.github/scripts/check-release.php';

    public function test_library_version_has_a_dated_changelog_entry(): void
    {
        [$exit, $output] = $this->check('v'.Library::VERSION);

        $this->assertSame(0, $exit, $output);
    }

    public function test_a_tag_that_does_not_match_library_version_is_rejected(): void
    {
        [$exit, $output] = $this->check('v999.0.0');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not match Library::VERSION', $output);
    }

    public function test_a_tag_without_the_v_prefix_is_rejected(): void
    {
        [$exit] = $this->check(Library::VERSION);

        $this->assertSame(1, $exit);
    }

    /**
     * @return array{int, string}
     */
    private function check(string $tag): array
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::SCRIPT).' '.escapeshellarg($tag).' 2>&1';
        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }
}
