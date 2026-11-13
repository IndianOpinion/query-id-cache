<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Single-blob store in one transient, for sites without a persistent
 * object cache.
 *
 * One transient read per request is cheap; one per query is not. The blob
 * holds a format version, a generation, the entries and the write stats.
 * Its expiry is twice the largest time to live, so it outlives its newest
 * entry, and each entry's own age is checked on read. A transient with an
 * expiry is not autoloaded, so requests that never touch an eligible query
 * never read it.
 *
 * @phpstan-type Blob array{v: int, gen: int, entries: array<string, mixed>, stats: array<mixed>}
 */
final class TransientStore implements Store
{
    public const TRANSIENT = 'query_id_cache';
    public const FORMAT    = 1;

    /** @var Blob|null Blob as last read in this request. */
    private ?array $memo = null;

    private bool $size_logged = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly int $max_entries = Config::DEFAULT_MAX_ENTRIES,
        private readonly int $max_bytes = Config::DEFAULT_MAX_BYTES,
        private readonly int $expiry = 2 * Config::DEFAULT_TTL,
    ) {
    }

    public function load(): array
    {
        return $this->read(true)['entries'];
    }

    public function get(string $key): mixed
    {
        $blob = $this->memo ?? $this->read(false);
        return $blob['entries'][$key] ?? null;
    }

    public function save(array $data): bool
    {
        $blob     = $this->read(true);
        $now      = Clock::now();
        $existing = $blob['entries'];

        $kept = [];
        foreach ($existing as $key => $raw) {
            $entry = Entry::validate($raw);
            if ($entry === null || Entry::is_stale($entry['t'], $entry['ttl'], $now)) {
                continue;
            }
            $kept[$key] = $entry;
        }

        $blob['stats'] = Stats::record($blob['stats'], $data, $existing);

        foreach ($data as $key => $entry) {
            $kept[$key] = $entry;
        }

        $kept = self::cap_oldest_first($kept, $this->max_entries);

        $blob['entries'] = $kept;
        $trimmed         = false;
        while ($blob['entries'] !== [] && strlen(serialize($blob)) > $this->max_bytes) {
            $blob['entries'] = self::cap_oldest_first($blob['entries'], max(0, count($blob['entries']) - 1));
            $trimmed         = true;
        }
        if ($trimmed && ! $this->size_logged) {
            $this->size_logged = true;
            $this->logger->error('size', sprintf('store blob exceeded %d bytes; dropped the oldest entries to fit.', $this->max_bytes));
        }

        return $this->write($blob);
    }

    public function drop(array $keys): int
    {
        $blob = $this->read(true);
        foreach ($keys as $key) {
            unset($blob['entries'][$key]);
        }
        $blob['gen']++;
        $this->write($blob);
        return $blob['gen'];
    }

    public function delete_all(): void
    {
        $blob            = $this->read(true);
        $blob['entries'] = [];
        $blob['gen']++;
        $this->write($blob);
    }

    public function generation(): int
    {
        return $this->read(true)['gen'];
    }

    public function bump_generation(): int
    {
        $blob = $this->read(true);
        $blob['gen']++;
        $this->write($blob);
        return $blob['gen'];
    }

    public function stats(): array
    {
        return Stats::clean($this->read(true)['stats']);
    }

    public function describe(): array
    {
        $raw = get_transient(self::TRANSIENT);
        return [
            'type'  => 'transient',
            'bytes' => $raw === false ? 0 : strlen(serialize($raw)),
        ];
    }

    /**
     * Read the blob. A fresh read bypasses WordPress's in-request option
     * cache so a write by another request is seen.
     *
     * @return Blob
     */
    private function read(bool $fresh): array
    {
        if ($fresh && ! wp_using_ext_object_cache()) {
            wp_cache_delete('_transient_' . self::TRANSIENT, 'options');
            wp_cache_delete('_transient_timeout_' . self::TRANSIENT, 'options');
        }

        $raw  = get_transient(self::TRANSIENT);
        $blob = ['v' => self::FORMAT, 'gen' => 0, 'entries' => [], 'stats' => []];

        if ($raw === false) {
            return $this->memo = $blob;
        }

        if (! is_array($raw) || ($raw['v'] ?? null) !== self::FORMAT || ! isset($raw['entries']) || ! is_array($raw['entries'])) {
            $this->logger->error('store-read', 'store value was corrupt or from another format version; treated as empty.');
            if (is_array($raw) && isset($raw['gen']) && is_int($raw['gen'])) {
                $blob['gen'] = $raw['gen'];
            }
            return $this->memo = $blob;
        }

        $blob['gen']     = isset($raw['gen']) && is_int($raw['gen']) ? $raw['gen'] : 0;
        $blob['entries'] = array_filter($raw['entries'], static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
        $blob['stats']   = isset($raw['stats']) && is_array($raw['stats']) ? $raw['stats'] : [];

        return $this->memo = $blob;
    }

    /**
     * @param Blob $blob
     */
    private function write(array $blob): bool
    {
        $this->memo = $blob;
        $ok         = set_transient(self::TRANSIENT, $blob, max(1, $this->expiry));
        if (! $ok) {
            $this->logger->error('store-write', 'writing the store transient failed.');
        }
        return $ok;
    }

    /**
     * Keep the newest $max entries.
     *
     * @param array<string, mixed> $entries
     *
     * @return array<string, mixed>
     */
    private static function cap_oldest_first(array $entries, int $max): array
    {
        if (count($entries) <= $max) {
            return $entries;
        }
        uasort($entries, static function ($a, $b): int {
            $ta = is_array($a) && isset($a['t']) && is_int($a['t']) ? $a['t'] : 0;
            $tb = is_array($b) && isset($b['t']) && is_int($b['t']) ? $b['t'] : 0;
            return $ta <=> $tb;
        });
        return array_slice($entries, count($entries) - $max, null, true);
    }
}
