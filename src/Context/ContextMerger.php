<?php

namespace Sfere\Context;

/**
 * Merges context layers, later layers winning. Associative arrays merge recursively; lists and
 * scalars are replaced.
 */
final class ContextMerger
{
    /**
     * @param  array<array-key, mixed>  ...$layers
     * @return array<array-key, mixed>
     */
    public static function merge(array ...$layers): array
    {
        $merged = [];
        foreach ($layers as $layer) {
            $merged = self::mergeTwo($merged, $layer);
        }

        return $merged;
    }

    /**
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $over
     * @return array<array-key, mixed>
     */
    private static function mergeTwo(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && ! array_is_list($value)
                && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($base[$key])) {
                $base[$key] = self::mergeTwo($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
