<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Wall clock with a seam for tests.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    /**
     * Freeze the clock at a timestamp, or unfreeze with null. Tests only.
     */
    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }
}
