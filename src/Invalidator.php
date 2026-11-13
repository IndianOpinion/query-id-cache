<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

use Throwable;
use WP_Post;

/**
 * Invalidation (PRD 4.9). Runs in every context: admin, REST, cron,
 * WP-CLI, AJAX and imports. Serving is front-end only; invalidation is
 * everywhere.
 *
 * Every handler reads the store once and writes at most once, and only
 * when it drops at least one entry.
 */
final class Invalidator
{
    /** @var array<int, array{type: string, terms: list<int>|null}> Published posts about to be deleted. */
    private array $deleting = [];

    /** @var array<int, list<int>> Ancestor tt_ids of terms about to be edited. */
    private array $editing = [];

    /**
     * @param callable(): Store $store_factory
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly Logger $logger,
        private readonly mixed $store_factory,
    ) {
    }

    /**
     * Action: transition_post_status (cases 36 and 37).
     */
    public function on_transition(mixed $new_status, mixed $old_status, mixed $post): void
    {
        $this->guard(function () use ($new_status, $old_status, $post): void {
            if (! $post instanceof WP_Post || $new_status === $old_status) {
                return;
            }
            if (! $this->covered($post->post_type)) {
                return;
            }
            if ($new_status === 'publish') {
                $this->drop_publish('publish', $post->post_type, $this->terms_of($post), (int) $post->ID, false);
            } elseif ($old_status === 'publish') {
                $this->drop_publish('unpublish', $post->post_type, $this->terms_of($post), (int) $post->ID, true);
            }
        });
    }

    /**
     * Action: post_updated (case 38). Only order-relevant changes matter.
     */
    public function on_updated(mixed $post_id, mixed $after, mixed $before): void
    {
        $this->guard(function () use ($after, $before): void {
            if (! $after instanceof WP_Post || ! $before instanceof WP_Post) {
                return;
            }
            if ($after->post_status !== 'publish' || $before->post_status !== 'publish') {
                return; // Status changes are handled by transition_post_status.
            }
            $changed = $after->post_date !== $before->post_date
                || $after->post_date_gmt !== $before->post_date_gmt
                || (int) $after->menu_order !== (int) $before->menu_order
                || $after->post_type !== $before->post_type
                || (int) $after->post_parent !== (int) $before->post_parent;
            if (! $changed) {
                return; // Title, content and excerpt edits need nothing: posts load fresh.
            }
            $terms = $this->terms_of($after);
            foreach (array_unique([$before->post_type, $after->post_type]) as $type) {
                if ($this->covered($type)) {
                    $this->drop_publish('update', $type, $terms, (int) $after->ID, $after->post_type !== $before->post_type);
                }
            }
        });
    }

    /**
     * Action: set_object_terms (case 40). Gives old and new tt_ids in one
     * call, and also catches editors that publish first and set terms after.
     *
     * @param mixed $tt_ids     New term taxonomy IDs.
     * @param mixed $old_tt_ids Term taxonomy IDs before the change.
     */
    public function on_set_terms(mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy, mixed $append, mixed $old_tt_ids): void
    {
        $this->guard(function () use ($object_id, $tt_ids, $old_tt_ids): void {
            $post = get_post((int) $object_id);
            if (! $post instanceof WP_Post || $post->post_status !== 'publish' || ! $this->covered($post->post_type)) {
                return;
            }
            $new = self::int_list($tt_ids);
            $old = self::int_list($old_tt_ids);
            $a   = $new;
            $b   = $old;
            sort($a);
            sort($b);
            if ($a === $b) {
                return;
            }
            $changed = array_values(array_unique(array_merge($new, $old)));
            $id      = (int) $post->ID;
            $type    = $post->post_type;
            $this->drop_where('terms', static function (array $e) use ($changed, $id, $type): bool {
                $in_scope = array_intersect($e['scope'], $changed) !== [];
                if ($e['class'] === Freshness::FRESH && in_array($type, $e['types'], true) && ($e['all'] || $in_scope)) {
                    return true;
                }
                return $in_scope && in_array($id, $e['ids'], true);
            });
        });
    }

    /**
     * Action: before_delete_post (case 39). Terms are still attached here.
     */
    public function on_before_delete(mixed $post_id, mixed $post = null): void
    {
        $this->guard(function () use ($post_id, $post): void {
            $post = $post instanceof WP_Post ? $post : get_post((int) $post_id);
            if (! $post instanceof WP_Post || $post->post_status !== 'publish' || ! $this->covered($post->post_type)) {
                return;
            }
            $this->deleting[(int) $post->ID] = ['type' => $post->post_type, 'terms' => $this->terms_of($post)];
        });
    }

    /**
     * Action: deleted_post (case 39). Acts as an unpublish.
     */
    public function on_deleted(mixed $post_id, mixed $post = null): void
    {
        $this->guard(function () use ($post_id): void {
            $id = (int) $post_id;
            if (! isset($this->deleting[$id])) {
                return;
            }
            $state = $this->deleting[$id];
            unset($this->deleting[$id]);
            $this->drop_publish('delete', $state['type'], $state['terms'], $id, true);
        });
    }

    /**
     * Action: edit_term, before the change. Remembers the old ancestors,
     * since a parent change alters which lists expand to this term.
     */
    public function on_edit_term(mixed $term_id, mixed $tt_id, mixed $taxonomy): void
    {
        $this->guard(function () use ($term_id, $tt_id, $taxonomy): void {
            $this->editing[(int) $tt_id] = $this->ancestor_tt_ids((int) $term_id, (string) $taxonomy);
        });
    }

    /**
     * Action: edited_term (case 41).
     */
    public function on_edited_term(mixed $term_id, mixed $tt_id, mixed $taxonomy): void
    {
        $this->guard(function () use ($term_id, $tt_id, $taxonomy): void {
            $tt    = (int) $tt_id;
            $ids   = array_merge([$tt], $this->editing[$tt] ?? [], $this->ancestor_tt_ids((int) $term_id, (string) $taxonomy));
            unset($this->editing[$tt]);
            $this->drop_terms('term-edit', $ids);
        });
    }

    /**
     * Action: delete_term (case 41).
     */
    public function on_delete_term(mixed $term, mixed $tt_id, mixed $taxonomy = null, mixed $deleted = null, mixed $object_ids = null): void
    {
        $this->guard(function () use ($tt_id): void {
            $this->drop_terms('term-delete', [(int) $tt_id]);
        });
    }

    /**
     * Action: split_shared_term (case 41).
     */
    public function on_split_term(mixed $old_term_id, mixed $new_term_id, mixed $tt_id, mixed $taxonomy = null): void
    {
        $this->guard(function () use ($tt_id): void {
            $this->drop_terms('term-split', [(int) $tt_id]);
        });
    }

    /**
     * Actions: add_option_sticky_posts and update_option_sticky_posts (case 42).
     */
    public function on_sticky(): void
    {
        $this->guard(function (): void {
            $this->drop_where('sticky', static fn (array $e): bool => true);
        });
    }

    /**
     * Publish, unpublish, update and delete (cases 36 to 39).
     *
     * @param list<int>|null $terms Null when the terms could not be read: every fresh entry of the type is dropped.
     */
    private function drop_publish(string $reason, string $type, ?array $terms, int $id, bool $remove_id): void
    {
        $this->drop_where($reason, static function (array $e) use ($type, $terms, $id, $remove_id): bool {
            if ($remove_id && in_array($id, $e['ids'], true)) {
                return true;
            }
            if ($e['class'] !== Freshness::FRESH || ! in_array($type, $e['types'], true)) {
                return false;
            }
            return $e['all'] || $terms === null || array_intersect($e['scope'], $terms) !== [];
        });
    }

    /**
     * @param list<int> $tt_ids
     */
    private function drop_terms(string $reason, array $tt_ids): void
    {
        $tt_ids = array_values(array_unique(array_filter($tt_ids, static fn (int $id): bool => $id > 0)));
        if ($tt_ids === []) {
            return;
        }
        $this->drop_where($reason, static fn (array $e): bool => array_intersect($e['scope'], $tt_ids) !== []);
    }

    /**
     * Read the store once; write once if anything matches (case 44).
     * Malformed entries always go.
     *
     * @param callable(array{t: int, ids: list<int>, found: int, class: string, scope: list<int>, all: bool, types: list<string>, sig: string, gen: int, ttl: int}): bool $predicate
     */
    private function drop_where(string $reason, callable $predicate): void
    {
        $store   = $this->store();
        $entries = $store->load();
        if ($entries === []) {
            return;
        }
        $drop = [];
        foreach ($entries as $key => $raw) {
            $entry = Entry::validate($raw);
            if ($entry === null || $predicate($entry)) {
                $drop[] = (string) $key;
            }
        }
        if ($drop === []) {
            return;
        }
        $store->drop($drop);
        $this->logger->debug(sprintf('invalidated: reason=%s dropped=%d', $reason, count($drop)));
        do_action('query_id_cache_invalidated', $reason, count($drop));
    }

    /**
     * Term taxonomy IDs of a post across its type's taxonomies, or null
     * when they cannot be read.
     *
     * @return list<int>|null
     */
    private function terms_of(WP_Post $post): ?array
    {
        $taxonomies = get_object_taxonomies($post->post_type);
        if (! is_array($taxonomies) || $taxonomies === []) {
            return [];
        }
        $terms = wp_get_object_terms((int) $post->ID, array_values(array_map('strval', $taxonomies)), ['fields' => 'tt_ids', 'update_term_meta_cache' => false]);
        if (! is_array($terms)) {
            $this->logger->error('terms', 'could not read the terms of a post; dropping every fresh entry of its type.');
            return null;
        }
        return self::int_list($terms);
    }

    /**
     * @return list<int>
     */
    private function ancestor_tt_ids(int $term_id, string $taxonomy): array
    {
        $out = [];
        foreach (get_ancestors($term_id, $taxonomy, 'taxonomy') as $ancestor) {
            $term = get_term((int) $ancestor, $taxonomy);
            if (is_object($term) && isset($term->term_taxonomy_id)) {
                $out[] = (int) $term->term_taxonomy_id;
            }
        }
        return $out;
    }

    private function covered(string $type): bool
    {
        return in_array($type, $this->registry->covered_post_types(), true);
    }

    private function store(): Store
    {
        $store = ($this->store_factory)();
        if (! $store instanceof Store) {
            throw new \UnexpectedValueException('store factory did not return a Store.');
        }
        return $store;
    }

    /**
     * @return list<int>
     */
    private static function int_list(mixed $values): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $out[] = (int) $value;
            }
        }
        return $out;
    }

    /**
     * Invalidation must never break a save. On a failure the whole store
     * is flushed, because a missed invalidation could leave a stale
     * "latest" list in place. Failures are logged once.
     */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->logger->error('invalidate', sprintf('%s during invalidation: %s; flushing the store.', $e::class, $e->getMessage()));
            try {
                $this->store()->delete_all();
            } catch (Throwable $ignored) {
                // Nothing else to do: the entries expire with their time to live.
            }
        }
    }
}
