<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Global settings, read through constants and filters.
 */
final class Config
{
    public const DEFAULT_TTL         = 300;
    public const DEFAULT_MAX_ENTRIES = 300;
    public const DEFAULT_MAX_BYTES   = 524288;

    public static function ttl(): int
    {
        $ttl = defined('QUERY_ID_CACHE_TTL') ? (int) constant('QUERY_ID_CACHE_TTL') : self::DEFAULT_TTL;
        return (int) apply_filters('query_id_cache_ttl', $ttl);
    }

    public static function max_entries(): int
    {
        return max(1, (int) apply_filters('query_id_cache_max_entries', self::DEFAULT_MAX_ENTRIES));
    }

    public static function max_bytes(): int
    {
        return max(1024, (int) apply_filters('query_id_cache_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /**
     * Largest time to live across the global value and every signature.
     */
    public static function max_ttl(Registry $registry): int
    {
        $global = self::ttl();
        $max    = $global;
        foreach ($registry->all() as $signature) {
            $max = max($max, $signature->effective_ttl($global));
        }
        return max(1, $max);
    }

    public static function debug_header(): bool
    {
        $default = defined('QUERY_ID_CACHE_DEBUG') && (bool) constant('QUERY_ID_CACHE_DEBUG');
        return (bool) apply_filters('query_id_cache_debug_header', $default);
    }
}
