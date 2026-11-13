<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Freshness classes.
 *
 * FRESH lists are dropped the moment a publish change can alter them.
 * TTL lists live out their time to live.
 */
final class Freshness
{
    public const AUTO  = 'auto';
    public const FRESH = 'fresh';
    public const TTL   = 'ttl';

    public const ALL = [self::AUTO, self::FRESH, self::TTL];

    /** Order keys that keep a list in the fresh class under `auto`. */
    public const FRESH_ORDERS = ['', 'date', 'post_date', 'modified', 'post_modified', 'id', 'none'];

    private function __construct()
    {
    }
}
