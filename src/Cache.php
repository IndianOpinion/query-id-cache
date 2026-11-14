<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

use SplObjectStorage;
use Throwable;
use WP_Post;
use WP_Query;

/**
 * Serving hits and recording misses (PRD 4.4 to 4.8).
 *
 * Hook map (see docs/ARCHITECTURE.md for why each priority):
 * - posts_pre_query, 20: serve a hit, or mark the query pending.
 * - posts_pre_query, PHP_INT_MAX: step aside when a later component
 *   replaced our answer or answered a pending query (case 8).
 * - posts_results, PHP_INT_MIN: snapshot the raw database IDs of a pending query.
 * - the_posts, PHP_INT_MAX: record the snapshot with the final count.
 * - found_posts_query and found_posts, PHP_INT_MAX: keep a served count
 *   for `fields => ids`; record a pending `ids` query.
 * - shutdown, 0: record pending `ids` queries, then write once.
 */
final class Cache
{
    public const FORMAT = 1;
    public const SKEW   = 60;

    /** @var SplObjectStorage<WP_Query, array{key: string, candidate: Candidate, ids: list<int>|null}> */
    private SplObjectStorage $pending;

    /** @var SplObjectStorage<WP_Query, array{found: int, ids: list<int>}> Served `ids` answers, for the count reassertion. */
    private SplObjectStorage $served;

    /** @var array<string, array<string, mixed>> New entries, written at shutdown. */
    private array $buffer = [];

    private ?Store $store = null;

    private ?int $seen_generation = null;

    private int $hits = 0;

    private int $misses = 0;

    private int $stored = 0;

    /**
     * @param callable(): Store $store_factory
     */
    public function __construct(
        private readonly RequestGate $gate,
        private readonly QueryInspector $inspector,
        private readonly Logger $logger,
        private readonly mixed $store_factory,
    ) {
        $this->pending = new SplObjectStorage();
        $this->served  = new SplObjectStorage();
    }

    /**
     * Filter: posts_pre_query.
     *
     * @param mixed $incoming Null unless another component answered.
     *
     * @return mixed
     */
    public function pre_query(mixed $incoming, mixed $query = null): mixed
    {
        try {
            return $this->do_pre_query($incoming, $query);
        } catch (Throwable $e) {
            $this->logger->error('internal', sprintf('%s in posts_pre_query: %s', $e::class, $e->getMessage()));
            if ($query instanceof WP_Query) {
                $this->pending->detach($query);
                $this->served->detach($query);
            }
            return $incoming;
        }
    }

    /**
     * Filter: posts_pre_query, latest priority. A component hooked after
     * ours may still answer the query. Its answer is not ours to record,
     * and a count we served must not be forced onto its answer.
     *
     * @param mixed $posts
     *
     * @return mixed
     */
    public function pre_query_settled(mixed $posts, mixed $query = null): mixed
    {
        if (! $query instanceof WP_Query) {
            return $posts;
        }
        if ($posts !== null && $this->pending->contains($query)) {
            $this->pending->detach($query);
        }
        if ($this->served->contains($query) && $posts !== $this->served[$query]['ids']) {
            $this->served->detach($query);
        }
        return $posts;
    }

    /**
     * Filter: posts_results, earliest priority. Takes the raw database
     * order before any other result filter sees it.
     *
     * @param mixed $posts
     *
     * @return mixed
     */
    public function snapshot(mixed $posts, mixed $query = null): mixed
    {
        try {
            if ($query instanceof WP_Query && $this->pending->contains($query)) {
                $rows = is_array($posts) ? $posts : [];
                $ids  = self::ids_of($rows);
                if (count($ids) !== count($rows)) {
                    // Rows without a usable ID (a custom posts_fields
                    // clause, say): an empty or short list would be wrong.
                    $this->pending->detach($query);
                    return $posts;
                }
                $state        = $this->pending[$query];
                $state['ids'] = $ids;
                $this->pending[$query] = $state;
            }
        } catch (Throwable $e) {
            $this->logger->error('internal', sprintf('%s in posts_results: %s', $e::class, $e->getMessage()));
        }
        return $posts;
    }

    /**
     * Filter: the_posts, latest priority. Records the snapshot together
     * with the count WordPress settled on.
     *
     * @param mixed $posts
     *
     * @return mixed
     */
    public function record(mixed $posts, mixed $query = null): mixed
    {
        try {
            if ($query instanceof WP_Query && $this->pending->contains($query)) {
                $state = $this->pending[$query];
                $this->pending->detach($query);
                if ($state['ids'] !== null) {
                    $this->buffer_entry($query, $state['key'], $state['candidate'], $state['ids']);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('internal', sprintf('%s in the_posts: %s', $e::class, $e->getMessage()));
        }
        return $posts;
    }

    /**
     * Filter: found_posts_query. On a served `ids` query WordPress would
     * run SELECT FOUND_ROWS() after our short-circuit and read a count
     * left over from some other query. Hand it a constant instead.
     *
     * @return mixed
     */
    public function found_posts_query(mixed $sql, mixed $query = null): mixed
    {
        if ($query instanceof WP_Query && $this->served->contains($query)) {
            return 'SELECT ' . (int) $this->served[$query]['found'];
        }
        return $sql;
    }

    /**
     * Filter: found_posts, latest priority. Reasserts the cached count for
     * served `ids` queries (case 22) and records pending `ids` queries,
     * which never reach posts_results or the_posts.
     *
     * @return mixed
     */
    public function found_posts(mixed $found, mixed $query = null): mixed
    {
        try {
            if (! $query instanceof WP_Query) {
                return $found;
            }
            if ($this->served->contains($query)) {
                return $this->served[$query]['found'];
            }
            if ($this->pending->contains($query)) {
                $state = $this->pending[$query];
                if ($state['candidate']->fields === 'ids' && is_array($query->posts)) {
                    $this->pending->detach($query);
                    $query->found_posts = (int) $found;
                    $this->buffer_entry($query, $state['key'], $state['candidate'], self::ids_of($query->posts));
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('internal', sprintf('%s in found_posts: %s', $e::class, $e->getMessage()));
        }
        return $found;
    }

    /**
     * Action: shutdown, priority 0 (before WordPress flushes output
     * buffers at priority 1, so the debug header still sees the count).
     */
    public function shutdown(): void
    {
        try {
            $this->resolve_pending_ids();
            $this->write();
        } catch (Throwable $e) {
            $this->logger->error('internal', sprintf('%s at shutdown: %s', $e::class, $e->getMessage()));
        }
    }

    /**
     * @return array{hits: int, misses: int, stored: int}
     */
    public function counters(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'stored' => $this->stored];
    }

    public function store(): Store
    {
        if ($this->store === null) {
            $store = ($this->store_factory)();
            if (! $store instanceof Store) {
                throw new \UnexpectedValueException('store factory did not return a Store.');
            }
            $this->store = $store;
        }
        return $this->store;
    }

    /**
     * Cache key (case 14): final SQL, fields mode, signature, blog ID and
     * format version.
     */
    public static function key(string $sql, string $fields, string $signature, int $blog_id): string
    {
        return hash('sha256', implode("\0", [(string) self::FORMAT, (string) $blog_id, $signature, $fields, $sql]));
    }

    private function do_pre_query(mixed $incoming, mixed $query): mixed
    {
        if (! $query instanceof WP_Query) {
            return $incoming;
        }

        // A reused query object starts a new run (case 48).
        $this->pending->detach($query);
        $this->served->detach($query);

        if ($incoming !== null) {
            return $incoming;
        }
        if ($query->is_main_query()) {
            return null;
        }
        if (function_exists('ms_is_switched') && ms_is_switched()) {
            return null;
        }
        if (! $this->gate->eligible()) {
            return null;
        }

        $candidate = $this->inspector->inspect($query);
        if ($candidate === null) {
            return null;
        }

        $sql = (string) $query->request;
        $key = self::key($sql, $candidate->fields, $candidate->signature->name, (int) get_current_blog_id());

        $store = $this->store();
        if ($this->seen_generation === null) {
            $this->seen_generation = $store->generation();
        }

        $entry = Entry::validate($store->get($key));
        if ($entry !== null && ! Entry::is_stale($entry['t'], $candidate->ttl, Clock::now(), self::SKEW)) {
            return $this->serve($query, $key, $candidate, $entry['ids'], $entry['found']);
        }

        $this->misses++;
        $this->pending[$query] = ['key' => $key, 'candidate' => $candidate, 'ids' => null];
        do_action('query_id_cache_miss', $key, $candidate->signature->name);
        return null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function serve(WP_Query $query, string $key, Candidate $candidate, array $ids, int $found): array
    {
        if ($ids !== [] && $candidate->fields === 'all') {
            $update_terms = (bool) $query->get('update_post_term_cache', true);
            $update_meta  = (bool) $query->get('update_post_meta_cache', true);
            if ((bool) $query->get('cache_results', true)) {
                _prime_post_caches($ids, $update_terms, $update_meta);
            } else {
                _prime_post_caches($ids, false, false);
            }
        }

        // Safety net (case 21), using only what is already in memory.
        $kept = [];
        foreach ($ids as $id) {
            $post = wp_cache_get($id, 'posts');
            if ($post === false) {
                if ($candidate->fields === 'all') {
                    continue; // Priming found no row: the post is gone.
                }
                $kept[] = $id; // Not loaded for `ids`; nothing in memory to check.
                continue;
            }
            $status = $post instanceof WP_Post || is_object($post) ? ($post->post_status ?? '') : '';
            if ($status !== 'publish') {
                continue;
            }
            $kept[] = $id;
        }
        if (count($kept) !== count($ids)) {
            $dropped = count($ids) - count($kept);
            $found   = max(0, $found - $dropped);
            $before  = $this->seen_generation;
            $after   = $this->store()->drop([$key]);
            if ($before !== null && $after === $before + 1) {
                // Our own drop: entries recorded in this request stay valid.
                $this->seen_generation = $after;
            }
            $this->logger->debug(sprintf('dropped %d unpublished ID(s) from a cached list; entry deleted.', $dropped));
        }

        $per_page             = (int) $query->get('posts_per_page', 10);
        $query->found_posts   = $found;
        $query->max_num_pages = $per_page > 0 ? (int) ceil($found / $per_page) : 1;
        if ($candidate->fields === 'ids') {
            $this->served[$query] = ['found' => $found, 'ids' => $kept];
        }

        $this->hits++;
        do_action('query_id_cache_hit', $key, $candidate->signature->name, count($kept));
        return $kept;
    }

    /**
     * @param list<int> $ids
     */
    private function buffer_entry(WP_Query $query, string $key, Candidate $candidate, array $ids): void
    {
        if (isset($this->buffer[$key])) {
            return; // Identical query earlier in this request (case 47).
        }
        $found = (bool) $query->get('no_found_rows', false) ? count($ids) : (int) $query->found_posts;
        if ($found < count($ids)) {
            $found = count($ids);
        }
        $this->buffer[$key] = Entry::make(
            Clock::now(),
            $ids,
            $found,
            $candidate->freshness,
            $candidate->scope,
            $candidate->reads_all,
            $candidate->post_types,
            $candidate->signature->name,
            (int) $this->seen_generation,
            $candidate->ttl,
        );
    }

    /**
     * `fields => ids` queries with no found-rows count fire no hook after
     * their results arrive. Read them here, from the query objects.
     */
    private function resolve_pending_ids(): void
    {
        $this->pending->rewind();
        $done = [];
        while ($this->pending->valid()) {
            $query = $this->pending->current();
            $state = $this->pending->getInfo();
            if ($state['candidate']->fields === 'ids' && is_array($query->posts)) {
                $this->buffer_entry($query, $state['key'], $state['candidate'], self::ids_of($query->posts));
            }
            $done[] = $query;
            $this->pending->next();
        }
        foreach ($done as $query) {
            $this->pending->detach($query);
        }
    }

    private function write(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $store   = $this->store();
        $current = $store->generation();
        if ($this->seen_generation === null || $current !== $this->seen_generation) {
            // An invalidation ran during this request (case 34): these
            // lists may predate it, so they are not written.
            $this->logger->debug(sprintf('discarded %d new entr(ies): generation changed during the request.', count($this->buffer)));
            $this->buffer = [];
            return;
        }
        $count        = count($this->buffer);
        $ok           = $store->save($this->buffer);
        $this->buffer = [];
        if ($ok) {
            $this->stored += $count;
            do_action('query_id_cache_stored', $count);
        }
    }

    /**
     * Reduce posts or IDs to a list of positive integers, in order.
     *
     * @param array<mixed> $posts
     *
     * @return list<int>
     */
    private static function ids_of(array $posts): array
    {
        $ids = [];
        foreach ($posts as $post) {
            if (is_object($post) && isset($post->ID)) {
                $id = (int) $post->ID;
            } elseif (is_numeric($post)) {
                $id = (int) $post;
            } else {
                continue;
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
