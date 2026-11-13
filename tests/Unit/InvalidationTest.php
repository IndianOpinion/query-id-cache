<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\Tests\TestCase;
use WpStub;

use function IndianOpinion\QueryIdCache\flush;

/**
 * Acceptance tests 29 to 41 and 57 (cases 36 to 46, 49).
 *
 * Seeded entries:
 * - fresh_a: fresh, scope [10] (term A)
 * - fresh_b: fresh, scope [20] (term B)
 * - fresh_all: fresh, reads everything
 * - ttl_a: TTL, scope [10], contains post 1
 * - ttl_all: TTL, reads everything, contains post 99
 * - page_a: fresh, scope [10], post type page
 */
final class InvalidationTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed('fresh_a', ['class' => 'fresh', 'scope' => [10], 'all' => false, 'ids' => [2]]);
        $this->seed('fresh_b', ['class' => 'fresh', 'scope' => [20], 'all' => false, 'ids' => [3]]);
        $this->seed('fresh_all', ['class' => 'fresh', 'scope' => [], 'all' => true, 'ids' => [4]]);
        $this->seed('ttl_a', ['class' => 'ttl', 'scope' => [10], 'all' => false, 'ids' => [1, 5]]);
        $this->seed('ttl_all', ['class' => 'ttl', 'scope' => [], 'all' => true, 'ids' => [99]]);
        $this->seed('page_a', ['class' => 'fresh', 'scope' => [10], 'all' => false, 'ids' => [6], 'types' => ['page']]);
        $this->events = [];
        add_action('query_id_cache_invalidated', function (string $reason, int $n): void {
            $this->events[] = $reason . ':' . $n;
        }, 10, 2);
    }

    /** @return list<string> */
    private function keys(): array
    {
        $keys = array_keys($this->blob_entries());
        sort($keys);
        return $keys;
    }

    /**
     * Test 29: publish drops fresh entries in its terms and fresh entries reading everything (case 36).
     */
    public function test_publish(): void
    {
        $post = WpStub::add_post(1, 'publish', 'post', [10]);
        do_action('transition_post_status', 'publish', 'draft', $post);
        $this->assertSame(['fresh_b', 'page_a', 'ttl_a', 'ttl_all'], $this->keys());
        $this->assertSame(1, $this->blob_generation());
        $this->assertSame(['publish:2'], $this->events);
    }

    /**
     * Test 30: a scheduled post published from cron triggers the same invalidation (case 36, 4.9).
     */
    public function test_cron_publish_of_scheduled_post(): void
    {
        WpStub::$flags['cron'] = true;
        $post = WpStub::add_post(1, 'publish', 'post', [20]);
        do_action('transition_post_status', 'publish', 'future', $post);
        $this->assertSame(['fresh_a', 'page_a', 'ttl_a', 'ttl_all'], $this->keys());
    }

    /**
     * Test 30: REST and admin publishes invalidate too (lesson 7).
     */
    public function test_rest_and_admin_publish(): void
    {
        WpStub::$flags['rest'] = true;
        WpStub::$flags['admin'] = true;
        WpStub::$flags['logged_in'] = true;
        do_action('transition_post_status', 'publish', 'pending', WpStub::add_post(1, 'publish', 'post', [20]));
        $this->assertNotContains('fresh_b', $this->keys());
    }

    /**
     * Test 31: unpublish drops fresh entries in scope and every entry containing the ID (case 37).
     */
    public function test_unpublish(): void
    {
        $post = WpStub::add_post(1, 'draft', 'post', [20]);
        do_action('transition_post_status', 'draft', 'publish', $post);
        // fresh_b (scope), fresh_all (reads all), ttl_a (contains 1).
        $this->assertSame(['fresh_a', 'page_a', 'ttl_all'], $this->keys());
    }

    /**
     * Test 31: trash is an unpublish (case 37).
     */
    public function test_trash(): void
    {
        do_action('transition_post_status', 'trash', 'publish', WpStub::add_post(99, 'trash', 'post', []));
        $this->assertNotContains('ttl_all', $this->keys());
    }

    /**
     * Test 32: a title edit drops nothing; a date change drops fresh entries in scope (case 38).
     */
    public function test_update_while_published(): void
    {
        $before = WpStub::add_post(1, 'publish', 'post', [20]);
        $after = clone $before;
        $after->post_title = 'New title';
        do_action('post_updated', 1, $after, $before);
        $this->assertCount(6, $this->keys());
        $this->assertSame(0, $this->blob_generation(), 'nothing written');

        $after->post_date = '2026-02-02 00:00:00';
        do_action('post_updated', 1, $after, $before);
        $this->assertSame(['fresh_a', 'page_a', 'ttl_a', 'ttl_all'], $this->keys());
    }

    /**
     * Test 32: menu order and parent changes count too (case 38).
     */
    public function test_menu_order_change(): void
    {
        $before = WpStub::add_post(1, 'publish', 'post', [10]);
        $after = clone $before;
        $after->menu_order = 3;
        do_action('post_updated', 1, $after, $before);
        $this->assertNotContains('fresh_a', $this->keys());
    }

    /**
     * Test 33: force-deleting a published post drops entries as for an unpublish (case 39).
     */
    public function test_force_delete(): void
    {
        $post = WpStub::add_post(1, 'publish', 'post', [20]);
        do_action('before_delete_post', 1, $post);
        WpStub::$post_terms[1] = []; // Terms go before the row does.
        unset(WpStub::$posts[1]);
        do_action('deleted_post', 1, $post);
        $this->assertSame(['fresh_a', 'page_a', 'ttl_all'], $this->keys());
        $this->assertSame(['delete:3'], $this->events);
    }

    /**
     * Test 33: deleting a post from the trash changes nothing (case 39).
     */
    public function test_delete_from_trash_is_ignored(): void
    {
        $post = WpStub::add_post(1, 'trash', 'post', [20]);
        do_action('before_delete_post', 1, $post);
        do_action('deleted_post', 1, $post);
        $this->assertCount(6, $this->keys());
    }

    /**
     * Test 34: moving a published post from A to B drops fresh entries for both (case 40).
     */
    public function test_term_move(): void
    {
        WpStub::add_post(5, 'publish', 'post', [20]);
        do_action('set_object_terms', 5, ['b'], [20], 'category', false, [10]);
        // fresh_a, fresh_b, fresh_all; ttl_a contains 5 and its scope was touched.
        $this->assertSame(['page_a', 'ttl_all'], $this->keys());
    }

    /**
     * Test 35: publishing first and assigning terms after still drops the right entries (case 40).
     */
    public function test_publish_then_assign_terms(): void
    {
        $post = WpStub::add_post(7, 'publish', 'post', []);
        do_action('transition_post_status', 'publish', 'draft', $post);
        $this->assertContains('fresh_b', $this->keys(), 'no terms yet at publish time');

        do_action('set_object_terms', 7, ['b'], [20], 'category', false, []);
        $this->assertNotContains('fresh_b', $this->keys());
    }

    /**
     * Test 35: unchanged terms, or a draft, drop nothing (case 40).
     */
    public function test_term_assignment_no_change(): void
    {
        WpStub::add_post(8, 'publish', 'post', [10]);
        do_action('set_object_terms', 8, ['a'], [10], 'category', false, [10]);
        WpStub::add_post(9, 'draft', 'post', [10]);
        do_action('set_object_terms', 9, ['b'], [20], 'category', false, [10]);
        $this->assertCount(6, $this->keys());
    }

    /**
     * Test 36: deleting a term drops every entry whose scope contains it (case 41).
     */
    public function test_term_delete(): void
    {
        do_action('delete_term', 3, 10, 'category', null, []);
        $this->assertSame(['fresh_all', 'fresh_b', 'ttl_all'], $this->keys());
    }

    /**
     * Test 36: edits and splits drop entries scoped to the term or its ancestors (case 41).
     */
    public function test_term_edit_and_split(): void
    {
        WpStub::$ancestors[30] = [2];
        WpStub::$term_tt[2] = 20;
        do_action('edit_term', 30, 31, 'category');
        do_action('edited_term', 30, 31, 'category');
        $this->assertNotContains('fresh_b', $this->keys(), 'parent B expands to this term');

        do_action('split_shared_term', 4, 40, 10, 'category');
        $this->assertNotContains('ttl_a', $this->keys());
    }

    /**
     * Tests 37 and 57: a sticky change drops every entry, including a theme's sticky emulation (cases 42, 49).
     */
    public function test_sticky_change(): void
    {
        do_action('update_option_sticky_posts');
        $this->assertSame([], $this->keys());
        $this->assertSame(['sticky:6'], $this->events);
    }

    /**
     * Test 57: the main home query is never served, so core's sticky prepend is untouched (case 49).
     */
    public function test_main_home_query_never_served(): void
    {
        $this->posts([1, 2]);
        $main = $this->query([], [1, 2]);
        $main->main = true;
        $main->query();
        $main->query();
        $this->assertSame(2, $main->queries_run);
    }

    /**
     * Test 38: flush() empties the store and bumps the generation (case 43).
     */
    public function test_flush_function(): void
    {
        $fired = 0;
        add_action('query_id_cache_flushed', static function () use (&$fired): void {
            $fired++;
        });
        flush();
        $this->assertSame([], $this->keys());
        $this->assertSame(1, $this->blob_generation());
        $this->assertSame(1, $fired);
    }

    /**
     * Test 39: an invalidation on an empty store writes nothing (case 44).
     */
    public function test_empty_store_writes_nothing(): void
    {
        WpStub::$transients = [];
        do_action('transition_post_status', 'publish', 'draft', WpStub::add_post(1, 'publish', 'post', [10]));
        do_action('update_option_sticky_posts');
        $this->assertSame([], WpStub::$transients);
        $this->assertSame([], $this->events);
    }

    /**
     * Test 39: an invalidation that matches nothing writes nothing (case 44).
     */
    public function test_no_match_writes_nothing(): void
    {
        $before = WpStub::$transients;
        do_action('delete_term', 3, 777, 'category');
        $this->assertSame($before, WpStub::$transients);
    }

    /**
     * Test 40: a post type no signature covers changes nothing (case 45).
     */
    public function test_uncovered_post_type(): void
    {
        $page = WpStub::add_post(1, 'publish', 'page', [10]);
        do_action('transition_post_status', 'publish', 'draft', $page);
        do_action('set_object_terms', 1, [], [20], 'category', false, [10]);
        $this->assertCount(6, $this->keys());
    }

    /**
     * Test 41: a view-count meta update changes nothing (case 46).
     */
    public function test_meta_update_changes_nothing(): void
    {
        $before = WpStub::$transients;
        do_action('updated_post_meta', 1, 1, 'views', 1234);
        do_action('wp_insert_comment', 1, null);
        $this->assertSame($before, WpStub::$transients);
    }

    /**
     * Unreadable terms drop every fresh entry of the type (fail safe).
     */
    public function test_unreadable_terms_drop_every_fresh_entry(): void
    {
        WpStub::$terms_error = true;
        do_action('transition_post_status', 'publish', 'draft', WpStub::add_post(1, 'publish', 'post', [10]));
        $this->assertSame(['page_a', 'ttl_a', 'ttl_all'], $this->keys());
    }
}
