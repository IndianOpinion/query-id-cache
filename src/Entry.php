<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Shape of one cached entry, and its validation.
 *
 * An entry holds only post IDs, a count, a time, a freshness class, term
 * taxonomy IDs, post type names, a signature name, a generation and a time
 * to live. No content, no user data, no request data.
 *
 * @phpstan-type EntryArray array{t: int, ids: list<int>, found: int, class: string, scope: list<int>, all: bool, types: list<string>, sig: string, gen: int, ttl: int}
 */
final class Entry
{
    /**
     * Build a well-formed entry.
     *
     * @param list<int>    $ids
     * @param list<int>    $scope
     * @param list<string> $types
     *
     * @return EntryArray
     */
    public static function make(
        int $time,
        array $ids,
        int $found,
        string $class,
        array $scope,
        bool $reads_all,
        array $types,
        string $signature,
        int $generation,
        int $ttl,
    ): array {
        return [
            't'     => $time,
            'ids'   => $ids,
            'found' => max(0, $found),
            'class' => $class,
            'scope' => $scope,
            'all'   => $reads_all,
            'types' => $types,
            'sig'   => $signature,
            'gen'   => $generation,
            'ttl'   => $ttl,
        ];
    }

    /**
     * Return the entry when it has a valid shape, null otherwise (case 55).
     *
     * @return EntryArray|null
     */
    public static function validate(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        foreach (['t', 'found', 'gen', 'ttl'] as $int_field) {
            if (! isset($raw[$int_field]) || ! is_int($raw[$int_field])) {
                return null;
            }
        }
        if (! isset($raw['ids'], $raw['scope'], $raw['types']) || ! is_array($raw['ids']) || ! is_array($raw['scope']) || ! is_array($raw['types'])) {
            return null;
        }
        if (! array_is_list($raw['ids']) || ! array_is_list($raw['scope']) || ! array_is_list($raw['types'])) {
            return null;
        }
        foreach ($raw['ids'] as $id) {
            if (! is_int($id) || $id <= 0) {
                return null;
            }
        }
        foreach ($raw['scope'] as $tt_id) {
            if (! is_int($tt_id)) {
                return null;
            }
        }
        foreach ($raw['types'] as $type) {
            if (! is_string($type)) {
                return null;
            }
        }
        if (! isset($raw['class'], $raw['sig'], $raw['all'])) {
            return null;
        }
        if (! in_array($raw['class'], [Freshness::FRESH, Freshness::TTL], true) || ! is_string($raw['sig']) || ! is_bool($raw['all'])) {
            return null;
        }

        /** @var EntryArray $raw */
        return $raw;
    }

    /**
     * Whether an entry is past its time to live, or stamped more than
     * $skew seconds in the future (clock skew between web nodes).
     */
    public static function is_stale(int $stored_at, int $ttl, int $now, int $skew = 60): bool
    {
        if ($ttl <= 0) {
            return true;
        }
        if ($stored_at > $now + $skew) {
            return true;
        }
        return ($now - $stored_at) >= $ttl;
    }
}
