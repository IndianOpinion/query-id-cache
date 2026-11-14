<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Per-entry store for sites with a persistent object cache.
 *
 * Each entry is its own cache item with its own expiry. One generation item
 * is part of every item key, so a bump orphans every old item at once
 * without enumerating them; eviction by the cache is a harmless miss.
 *
 * An index item per generation lists the live keys. The hit path trusts an
 * entry only when the index names it, so an evicted index can never leave
 * an entry that invalidation cannot see. Any invalidation that would drop
 * an entry bumps the generation (one generation per store, by ruling).
 */
final class ObjectCacheStore implements Store
{
    public const GROUP = 'query-id-cache';

    private const GENERATION = 'generation';
    private const STATS      = 'stats';

    private const STATS_EXPIRY = 86400;

    private ?int $memo_gen = null;

    /** @var array<string, array{0: int, 1: int}>|null */
    private ?array $memo_index = null;

    public function __construct(
        private readonly Logger $logger,
        private readonly int $max_entries = Config::DEFAULT_MAX_ENTRIES,
        private readonly int $expiry = 2 * Config::DEFAULT_TTL,
    ) {
    }

    public function load(): array
    {
        $gen   = $this->generation();
        $index = $this->read_index($gen);
        if ($index === []) {
            return [];
        }
        $keys = [];
        foreach (array_keys($index) as $key) {
            $keys[] = self::entry_key($gen, $key);
        }
        $values = wp_cache_get_multiple($keys, self::GROUP, true);
        $out    = [];
        foreach (array_keys($index) as $key) {
            $value = $values[self::entry_key($gen, $key)] ?? false;
            if ($value !== false) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public function get(string $key): mixed
    {
        $gen = $this->memo_gen ?? $this->generation();
        if ($this->memo_index === null) {
            $this->memo_index = $this->read_index($gen);
        }
        if (! isset($this->memo_index[$key])) {
            return null;
        }
        $value = wp_cache_get(self::entry_key($gen, $key), self::GROUP);
        return $value === false ? null : $value;
    }

    public function save(array $data): bool
    {
        $gen      = $this->generation();
        $index    = $this->read_index($gen);
        $now      = Clock::now();
        $existing = $index;

        foreach ($index as $key => [$stored_at, $ttl]) {
            if (Entry::is_stale($stored_at, $ttl, $now)) {
                unset($index[$key]);
            }
        }

        $ok = true;
        foreach ($data as $key => $entry) {
            $ttl = isset($entry['ttl']) && is_int($entry['ttl']) ? $entry['ttl'] : Config::DEFAULT_TTL;
            $t   = isset($entry['t']) && is_int($entry['t']) ? $entry['t'] : $now;
            if (! wp_cache_set(self::entry_key($gen, $key), $entry, self::GROUP, $ttl + 60)) {
                $ok = false;
                continue;
            }
            $index[$key] = [$t, $ttl];
        }

        if (count($index) > $this->max_entries) {
            uasort($index, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $excess = array_slice($index, 0, count($index) - $this->max_entries, true);
            foreach (array_keys($excess) as $key) {
                wp_cache_delete(self::entry_key($gen, $key), self::GROUP);
                unset($index[$key]);
            }
        }

        if (! wp_cache_set(self::index_key($gen), $index, self::GROUP, max(1, $this->expiry))) {
            $ok = false;
        }
        $this->memo_index = $index;

        $stats = wp_cache_get(self::STATS, self::GROUP, true);
        wp_cache_set(self::STATS, Stats::record(is_array($stats) ? $stats : [], $data, $existing), self::GROUP, self::STATS_EXPIRY);

        if (! $ok) {
            $this->logger->error('store-write', 'writing to the object cache failed.');
        }
        return $ok;
    }

    public function drop(array $keys): int
    {
        return $this->bump_generation();
    }

    public function delete_all(): void
    {
        $old = $this->generation();
        wp_cache_delete(self::index_key($old), self::GROUP);
        $this->bump_generation();
    }

    public function generation(): int
    {
        $value = wp_cache_get(self::GENERATION, self::GROUP, true);
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            // Missing or evicted: start from the clock so an old generation
            // number, and the items keyed under it, can never come back.
            wp_cache_add(self::GENERATION, self::clock_generation(), self::GROUP);
            $value = wp_cache_get(self::GENERATION, self::GROUP, true);
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                $this->logger->error('store-read', 'the object cache did not keep the generation item.');
                $value = self::clock_generation();
            }
        }
        $gen = (int) $value;
        if ($this->memo_gen !== $gen) {
            $this->memo_index = null;
        }
        return $this->memo_gen = $gen;
    }

    public function bump_generation(): int
    {
        $current = $this->generation();
        $new     = wp_cache_incr(self::GENERATION, 1, self::GROUP);
        if (! is_int($new) || $new <= $current) {
            $new = max($current + 1, self::clock_generation());
            wp_cache_set(self::GENERATION, $new, self::GROUP);
        }
        $this->memo_gen   = $new;
        $this->memo_index = null;
        return $new;
    }

    public function stats(): array
    {
        $stats = wp_cache_get(self::STATS, self::GROUP, true);
        return Stats::clean(is_array($stats) ? $stats : []);
    }

    public function describe(): array
    {
        return ['type' => 'object-cache', 'bytes' => null];
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private function read_index(int $gen): array
    {
        $raw = wp_cache_get(self::index_key($gen), self::GROUP, true);
        if ($raw === false || $raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            $this->logger->error('store-read', 'store index was corrupt; treated as empty.');
            return [];
        }
        $index = [];
        foreach ($raw as $key => $row) {
            if (is_string($key) && is_array($row) && isset($row[0], $row[1]) && is_int($row[0]) && is_int($row[1])) {
                $index[$key] = [$row[0], $row[1]];
            }
        }
        return $index;
    }

    private static function entry_key(int $gen, string $key): string
    {
        return 'entry:' . $gen . ':' . $key;
    }

    private static function index_key(int $gen): string
    {
        return 'index:' . $gen;
    }

    private static function clock_generation(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
