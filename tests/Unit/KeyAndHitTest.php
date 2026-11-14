<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\Cache;
use IndianOpinion\QueryIdCache\Clock;
use IndianOpinion\QueryIdCache\Stats;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use WpStub;

use function IndianOpinion\QueryIdCache\register_signature;

/**
 * Acceptance tests 11 to 19 (cases 14 to 23, 50).
 */
final class KeyAndHitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->posts([1, 2, 3, 4, 5]);
    }

    /**
     * Test 11: same variables, different final SQL, different keys; blog ID and format change the key (case 14).
     */
    public function test_key_depends_on_final_sql_blog_and_format(): void
    {
        $a = Cache::key('SELECT a', 'all', 'front-page', 1);
        $this->assertNotSame($a, Cache::key('SELECT b', 'all', 'front-page', 1));
        $this->assertNotSame($a, Cache::key('SELECT a', 'ids', 'front-page', 1));
        $this->assertNotSame($a, Cache::key('SELECT a', 'all', 'other', 1));
        $this->assertNotSame($a, Cache::key('SELECT a', 'all', 'front-page', 2));
        $this->assertSame(1, Cache::FORMAT, 'format version is part of the key material');

        // Same variables, a posts_request-style filter changed the SQL.
        $this->query(['posts_per_page' => 3], [1, 2, 3], 'SELECT x LIMIT 3')->query();
        $this->query(['posts_per_page' => 3], [3, 2, 1], 'SELECT x /* filtered */ LIMIT 3')->query();
        $this->shutdown();
        $this->assertCount(2, $this->blob_entries());
    }

    /**
     * Test 12: pages 1 and 2 of one list are separate entries (case 15).
     */
    public function test_pages_are_separate_entries(): void
    {
        $this->query(['paged' => 1], [1, 2], 'SELECT ... LIMIT 0, 2')->query();
        $this->query(['paged' => 2], [3, 4], 'SELECT ... LIMIT 2, 2')->query();
        $this->shutdown();
        $ids = array_map(static fn (array $e) => $e['ids'], array_values($this->blob_entries()));
        $this->assertSame([[1, 2], [3, 4]], $ids);
    }

    /**
     * Test 13: a timestamp in the SQL fills the store only to the cap and is reported (cases 16, 33).
     */
    public function test_never_repeating_keys_are_capped_and_reported(): void
    {
        $this->each(static fn () => add_filter('query_id_cache_max_entries', static fn (): int => 20));
        for ($i = 0; $i < 60; $i++) {
            $this->new_request();
            Clock::freeze(self::NOW + $i);
            $this->query([], [1], "SELECT ... WHERE post_date > '2026-11-0{$i}' LIMIT 5")->query();
            $this->shutdown();
            $this->assertLessThanOrEqual(20, count($this->blob_entries()));
        }
        $this->assertCount(20, $this->blob_entries());
        $stats = $this->plugin->store()->stats();
        $this->assertTrue(Stats::never_repeats($stats['front-page']));
        $this->assertSame(60, $stats['front-page']['stored']);
    }

    /**
     * Test 13: a healthy signature whose keys come back is not reported (case 16).
     */
    public function test_repeating_keys_are_not_reported(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->new_request();
            Clock::freeze(self::NOW + $i * 400); // Past the 300 s TTL each time.
            $this->query([], [1], 'SELECT stable LIMIT 5')->query();
            $this->shutdown();
        }
        $stats = $this->plugin->store()->stats();
        $this->assertFalse(Stats::never_repeats($stats['front-page']));
        $this->assertSame(59, $stats['front-page']['repeats']);
    }

    /**
     * Test 14: a hit returns IDs in order with found_posts and max_num_pages; -1 gives one page (cases 17, 19).
     */
    public function test_hit_restores_order_and_pagination(): void
    {
        $this->query(['posts_per_page' => 2], [5, 3], 'SELECT p LIMIT 0, 2', 5)->query();
        $this->shutdown();

        $this->new_request();
        $q = $this->query(['posts_per_page' => 2], [], 'SELECT p LIMIT 0, 2');
        $posts = $q->query();
        $this->assertSame(0, $q->queries_run, 'no ID query');
        $this->assertSame([5, 3], array_map(static fn ($p) => $p->ID, $posts));
        $this->assertSame(5, $q->found_posts);
        $this->assertSame(3, $q->max_num_pages);
        $this->assertSame([[[5, 3], true, true]], WpStub::$primed, 'primed once, honouring flags');

        $this->new_request();
        $this->query(['posts_per_page' => -1], [1, 2, 3], 'SELECT all')->query();
        $this->shutdown();
        $this->new_request();
        $q = $this->query(['posts_per_page' => -1], [], 'SELECT all');
        $q->query();
        $this->assertSame(0, $q->queries_run);
        $this->assertSame(1, $q->max_num_pages);
    }

    /**
     * Test 14: priming honours the query's cache flags (case 19).
     */
    public function test_priming_honours_flags(): void
    {
        $vars = ['update_post_term_cache' => false, 'update_post_meta_cache' => false];
        $this->query($vars, [1, 2])->query();
        $this->shutdown();
        $this->new_request();
        WpStub::$primed = [];
        $this->query($vars, [])->query();
        $this->assertSame([[[1, 2], false, false]], WpStub::$primed);
    }

    /**
     * Test 14: the hit and miss actions fire with key, signature and count (5.4).
     */
    public function test_hit_and_miss_actions(): void
    {
        $events = [];
        $this->each(static function () use (&$events): void {
            add_action('query_id_cache_miss', static function (string $key, string $sig) use (&$events): void {
                $events[] = ['miss', $sig];
            }, 10, 2);
            add_action('query_id_cache_hit', static function (string $key, string $sig, int $n) use (&$events): void {
                $events[] = ['hit', $sig, $n];
            }, 10, 3);
            add_action('query_id_cache_stored', static function (int $n) use (&$events): void {
                $events[] = ['stored', $n];
            });
        });
        $this->query()->query();
        $this->shutdown();
        $this->new_request();
        $this->query()->query();
        $this->shutdown();
        $this->assertSame([['miss', 'front-page'], ['stored', 1], ['hit', 'front-page', 3]], $events);
    }

    /**
     * Test 15: an expired entry, or one stamped more than 60 s ahead, is a miss (case 18).
     */
    public function test_stale_and_future_entries_miss(): void
    {
        $this->query()->query();
        $this->shutdown();

        $this->new_request();
        Clock::freeze(self::NOW + 299);
        $q = $this->query();
        $q->query();
        $this->assertSame(0, $q->queries_run, 'still fresh at 299 s');

        $this->new_request();
        Clock::freeze(self::NOW + 300);
        $q = $this->query();
        $q->query();
        $this->assertSame(1, $q->queries_run, 'expired at 300 s');

        $this->new_request();
        Clock::freeze(self::NOW - 61);
        $q = $this->query();
        $q->query();
        $this->assertSame(1, $q->queries_run, 'more than 60 s in the future');

        $this->new_request();
        Clock::freeze(self::NOW - 60);
        $q = $this->query();
        $q->query();
        $this->assertSame(0, $q->queries_run, 'exactly 60 s ahead is tolerated');
    }

    /**
     * Test 16: an empty cached list is served without priming (case 20).
     */
    public function test_empty_list_is_a_hit(): void
    {
        $this->query([], [])->query();
        $this->shutdown();
        $entry = array_values($this->blob_entries())[0];
        $this->assertSame([], $entry['ids']);

        $this->new_request();
        WpStub::$primed = [];
        $q = $this->query([], [7]);
        $this->assertSame([], $q->query());
        $this->assertSame(0, $q->queries_run);
        $this->assertSame([], WpStub::$primed);
        $this->assertSame(0, $q->found_posts);
    }

    /**
     * Test 17: a cached ID that is now private is dropped and the entry deleted (cases 21, 50).
     */
    public function test_safety_net_drops_unpublished_ids(): void
    {
        $this->query([], [1, 2, 3])->query();
        $this->shutdown();

        WpStub::$posts[2]->post_status = 'private';
        unset(WpStub::$posts[3]); // Deleted.
        $this->new_request();
        $q = $this->query();
        $posts = $q->query();
        $this->assertSame([1], array_map(static fn ($p) => $p->ID, $posts));
        $this->assertSame(1, $q->found_posts);
        $this->shutdown();
        $this->assertSame([], $this->blob_entries(), 'entry deleted so the next request rebuilds it');
    }

    /**
     * Test 17: entries recorded in the same request survive the safety net's own drop (case 21).
     */
    public function test_safety_net_does_not_discard_this_requests_entries(): void
    {
        $this->query([], [1, 2])->query();
        $this->shutdown();
        WpStub::$posts[2]->post_status = 'draft';

        $this->new_request();
        $this->query([], [1, 2])->query();
        $this->query([], [4, 5], 'SELECT other LIMIT 5')->query();
        $this->shutdown();
        $this->assertCount(1, $this->blob_entries());
        $this->assertSame([4, 5], array_values($this->blob_entries())[0]['ids']);
    }

    /**
     * Test 18: found_posts survives for fields all and ids (case 22).
     */
    public function test_found_posts_survives_for_all_and_ids(): void
    {
        foreach (['all' => '', 'ids' => 'ids'] as $label => $fields) {
            $this->new_request();
            $sql = "SELECT {$label} LIMIT 0, 2";
            $this->query(['fields' => $fields, 'posts_per_page' => 2], [4, 5], $sql, 40)->query();
            $this->shutdown();

            $this->new_request();
            $q = $this->query(['fields' => $fields, 'posts_per_page' => 2], [], $sql);
            $result = $q->query();
            $this->assertSame(0, $q->queries_run, $label);
            $this->assertSame(40, $q->found_posts, $label);
            $this->assertSame(20, $q->max_num_pages, $label);
            if ($fields === 'ids') {
                $this->assertSame([4, 5], $result);
            }
        }
    }

    /**
     * Test 18: with `ids`, core recounts after the short-circuit; the
     * reassertion beats a found_posts filter that ran on the stale count (case 22).
     */
    public function test_found_posts_reasserted_for_ids(): void
    {
        $sql = 'SELECT ids LIMIT 0, 2';
        $this->query(['fields' => 'ids', 'posts_per_page' => 2], [4, 5], $sql, 9)->query();
        $this->shutdown();

        $this->new_request();
        $this->each(static fn () => add_filter('found_posts_query', static fn () => 'SELECT FOUND_ROWS()', PHP_INT_MAX - 1));
        // Our found_posts_query filter still runs last, so pin a stale count through found_posts too.
        add_filter('found_posts', static fn () => 999, 50);
        $q = $this->query(['fields' => 'ids', 'posts_per_page' => 2], [], $sql);
        $q->query();
        $this->assertSame(9, $q->found_posts);
        $this->assertSame(5, $q->max_num_pages);
    }

    /**
     * Test 19: a hit records nothing at shutdown (case 23).
     */
    public function test_hit_writes_nothing(): void
    {
        $this->query()->query();
        $this->shutdown();
        $before = WpStub::$transients;

        $this->new_request();
        $this->query()->query();
        Clock::freeze(self::NOW + 5);
        $this->shutdown();
        $this->assertSame($before, WpStub::$transients);
    }

    /**
     * Test 14: per-signature TTL is honoured (5.1).
     */
    public function test_signature_ttl(): void
    {
        $this->each(static fn () => register_signature('front-page', ['ttl' => 30]));
        $this->query()->query();
        $this->shutdown();
        $this->new_request();
        Clock::freeze(self::NOW + 31);
        $q = $this->query();
        $q->query();
        $this->assertSame(1, $q->queries_run);
    }
}
