<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Where entries live.
 *
 * The five methods named in the PRD (load, save, delete_all, generation,
 * bump_generation) are joined by get() for the per-query hit path, drop()
 * for one-write selective invalidation, stats() for observability and
 * describe() for WP-CLI. See docs/ARCHITECTURE.md.
 *
 * Every read returns raw values; callers validate entries with Entry.
 */
interface Store
{
    /**
     * Fresh read of every current entry, key => raw entry. Never served
     * from a per-request memo: used before writes and invalidations.
     *
     * @return array<string, mixed>
     */
    public function load(): array;

    /**
     * One entry for the hit path, or null. May use a per-request memo.
     */
    public function get(string $key): mixed;

    /**
     * Merge these new entries (key => entry) into the store: re-read,
     * drop expired entries, merge, apply the entry and size caps, write.
     *
     * @param array<string, array<string, mixed>> $data
     *
     * @return bool False when the write failed.
     */
    public function save(array $data): bool;

    /**
     * Remove these keys and bump the generation, in as few writes as the
     * store allows. Returns the new generation.
     *
     * @param list<string> $keys
     */
    public function drop(array $keys): int;

    /**
     * Drop every entry and bump the generation.
     */
    public function delete_all(): void;

    /**
     * Current generation, read fresh.
     */
    public function generation(): int;

    /**
     * Increase the generation and return the new value.
     */
    public function bump_generation(): int;

    /**
     * Per-signature write statistics (see Stats).
     *
     * @return array<string, array{stored: int, repeats: int, recent: list<string>}>
     */
    public function stats(): array;

    /**
     * Facts for `wp query-id-cache status`: at least `type`, and `bytes`
     * when the store has a measurable size.
     *
     * @return array<string, int|string|null>
     */
    public function describe(): array;
}
