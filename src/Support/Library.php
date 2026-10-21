<?php

namespace Sfere\Support;

/**
 * Identity of this SDK on the wire: `context.library` and the User-Agent header.
 */
final class Library
{
    public const NAME = '@fanfinity/sfere-laravel';

    /** Bumped on every release (see CHANGELOG.md). */
    public const VERSION = '0.1.0';

    public static function userAgent(): string
    {
        return self::NAME.'/'.self::VERSION;
    }

    /**
     * @return array{name: string, version: string, base: string}
     */
    public static function context(string $frameworkVersion): array
    {
        return ['name' => self::NAME, 'version' => self::VERSION, 'base' => 'laravel/framework@'.$frameworkVersion];
    }

    private function __construct() {}
}
