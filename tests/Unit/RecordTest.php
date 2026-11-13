<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\Clock;
use IndianOpinion\QueryIdCache\Freshness;
use IndianOpinion\QueryIdCache\QueryInspector;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use IndianOpinion\QueryIdCache\TransientStore;
use WP_Tax_Query;
use WpStub;

/**
 * Acceptance tests 20 to 28, 42 and 43 (cases 24 to 35, 47, 48).
 */
final class RecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->posts([1, 2, 3, 4, 5, 6]);
    }

    /**
     * Test 20: the raw database order is recorded, not a later result filter's (cases 24, 25).
     */
    public function test_records_raw_order_before_result_filters(): void
    {
        $reverse = static fn ($posts) => array_reverse($posts);
        $this->each(static function () use ($reverse): void {
            add_filter('posts_results', $reverse, 10);
            add_filter('the_posts', $reverse, 10);
        });

        $first = $this->query([], [1, 2, 3])->query();
        $this->shutdown();
        $this->assertSame([1, 2, 3], array_values($this->blob_entries())[0]['ids']);

        $this->new_request();
        $q = $this->query([], []);
        $second = $q->query();
        $this->assertSame(0, $q->queries_run);
        $this->assertSame(
            array_map(static fn ($p) => $p->ID, $first),
            array_map(static fn ($p) => $p->ID, $second),
            'the filters see the same input on a hit, so the output matches'
        );
    }

    /**
     * Test 20: `ids` queries, which skip the result filters, are recorded too (cases 24, 25).
     */
    public function test_records_ids_queries(): void
    {
        $this->query(['fields' => 'ids'], [3, 1])->query();
        $this->query(['fields' => 'ids', 'no_found_rows' => true], [6, 5], 'SELECT nf LIMIT 2')->query();
        $this->shutdown();
        $ids = array_map(static fn ($e) => $e['ids'], array_values($this->blob_entries()));
        sort($ids);
        $this->assertSame([[3, 1], [6, 5]], $ids);
    }

    /**
     * Test 21: with no_found_rows the stored count equals the ID count (case 26).
     */
    public function test_no_found_rows_count(): void
    {
        $entry = $this->record_one(['no_found_rows' => true], [1, 2], null);
        $this->assertSame(2, $entry['found']);

        $this->new_request();
        WpStub::$transients = [];
        $q = $this->query([], [1, 2], null, 50);
        $q->query();
        $this->shutdown();
        $this->assertSame(50, array_values($this->blob_entries())[0]['found']);
    }

    /**
     * Test 22: the stored entry has every field in case 27.
     */
    public function test_entry_fields(): void
    {
        $q = $this->query(['cat' => 5], [1, 2]);
        $q->tax_query = new WP_Tax_Query([['taxonomy' => 'category', 'terms' => [11, 12], 'field' => 'term_taxonomy_id', 'operator' => 'IN']]);
        $q->query();
        $this->shutdown();
        $entry = array_values($this->blob_entries())[0];
        $this->assertSame(['t', 'ids', 'found', 'class', 'scope', 'all', 'types', 'sig', 'gen', 'ttl'], array_keys($entry));
        $this->assertSame(self::NOW, $entry['t']);
        $this->assertSame([1, 2], $entry['ids']);
        $this->assertSame(2, $entry['found']);
        $this->assertSame('fresh', $entry['class']);
        $this->assertSame([11, 12], $entry['scope']);
        $this->assertFalse($entry['all']);
        $this->assertSame(['post'], $entry['types']);
        $this->assertSame('front-page', $entry['sig']);
        $this->assertSame(0, $entry['gen']);
    }

    /**
     * Test 23: nothing is written before shutdown (case 28).
     */
    public function test_nothing_written_before_shutdown(): void
    {
        $this->query()->query();
        $this->query([], [4], 'SELECT b LIMIT 1')->query();
        $this->assertSame([], WpStub::$transients);
        $this->shutdown();
        $this->assertCount(2, $this->blob_entries());
    }

    /**
     * Test 24: auto class from the order (case 29).
     *
     * @dataProvider orders
     */
    public function test_auto_freshness(mixed $orderby, string $class): void
    {
        $this->assertSame($class, QueryInspector::auto_freshness($this->query(['orderby' => $orderby])));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function orders(): iterable
    {
        yield 'none given' => ['', Freshness::FRESH];
        yield 'date' => ['date', Freshness::FRESH];
        yield 'post_date' => ['post_date', Freshness::FRESH];
        yield 'modified' => ['modified', Freshness::FRESH];
        yield 'ID' => ['ID', Freshness::FRESH];
        yield 'date ID' => ['date ID', Freshness::FRESH];
        yield 'array date' => [['date' => 'DESC', 'ID' => 'DESC'], Freshness::FRESH];
        yield 'meta_value_num' => ['meta_value_num', Freshness::TTL];
        yield 'meta_value' => ['meta_value', Freshness::TTL];
        yield 'comment_count' => ['comment_count', Freshness::TTL];
        yield 'title' => ['title', Freshness::TTL];
        yield 'menu_order' => ['menu_order', Freshness::TTL];
        yield 'named meta clause' => [['views_clause' => 'DESC'], Freshness::TTL];
        yield 'mixed' => [['date' => 'DESC', 'comment_count' => 'DESC'], Freshness::TTL];
    }

    /**
     * Test 24: the class can be forced by the signature or the filter (5.1, 5.3).
     */
    public function test_freshness_override(): void
    {
        $this->each(static fn () => add_filter('query_id_cache_freshness', static fn () => 'ttl'));
        $this->assertSame('ttl', $this->record_one(['orderby' => 'date'])['class']);
    }

    /**
     * Test 25: term scope comes from the parsed tax query with children expanded;
     * exclusions read everything; meta queries do not narrow (cases 30, 31, 32).
     *
     * @dataProvider scopes
     *
     * @param array<mixed> $queries
     * @param list<int>    $scope
     */
    public function test_term_scope(array $queries, array $scope, bool $all): void
    {
        $q = $this->query(['meta_query' => [['key' => 'k', 'value' => 'v']]]);
        $q->tax_query = new WP_Tax_Query($queries);
        $this->assertSame([$scope, $all], QueryInspector::term_scope($q));
    }

    /** @return iterable<string, array{array<mixed>, list<int>, bool}> */
    public static function scopes(): iterable
    {
        // Clauses as WP_Tax_Query::$queries holds them: as written, with
        // sanitize_query() defaults. Fixture: news(10) > india(11) > delhi(12),
        // sport(20), opinion(30); tags budget(40), monsoon(41).
        $cat = static fn (array $t, string $field = 'term_id', string $op = 'IN', bool $kids = true) => [
            'taxonomy' => 'category', 'terms' => $t, 'field' => $field, 'operator' => $op, 'include_children' => $kids,
        ];
        $tag = static fn (array $t, string $op = 'IN') => ['taxonomy' => 'post_tag', 'terms' => $t, 'field' => 'slug', 'operator' => $op, 'include_children' => true];
        yield 'no tax query' => [[], [], true];
        yield 'term ID with children expanded' => [[$cat([1])], [10, 11, 12], false];
        yield 'slug with children expanded' => [[$cat(['india'], 'slug')], [11, 12], false];
        yield 'include_children off' => [[$cat(['news'], 'slug', 'IN', false)], [10], false];
        yield 'term_taxonomy_id still expands children' => [[$cat([10], 'term_taxonomy_id')], [10, 11, 12], false];
        $no_tax = ['taxonomy' => '', 'terms' => [10], 'field' => 'term_taxonomy_id', 'operator' => 'IN', 'include_children' => true];
        yield 'no taxonomy, term_taxonomy_id' => [[$no_tax], [10], false];
        yield 'flat taxonomy' => [[$tag(['budget'])], [40], false];
        yield 'AND operator' => [[$tag(['budget', 'monsoon'], 'AND')], [40, 41], false];
        yield 'AND with a missing term' => [[$tag(['budget', 'nope'], 'AND')], [], true];
        yield 'unknown slug' => [[$cat(['nope'], 'slug')], [], true];
        yield 'unknown taxonomy' => [[['taxonomy' => 'genre', 'terms' => [1], 'field' => 'term_id', 'operator' => 'IN', 'include_children' => true]], [], true];
        yield 'NOT IN only' => [[$cat([4], 'term_id', 'NOT IN')], [], true];
        yield 'NOT EXISTS only' => [[$cat([], 'term_id', 'NOT EXISTS')], [], true];
        yield 'IN and NOT IN' => [['relation' => 'AND', $cat([4]), $cat([5], 'term_id', 'NOT IN')], [20], false];
        yield 'OR with exclusion' => [['relation' => 'OR', $cat([4]), $cat([5], 'term_id', 'NOT IN')], [], true];
        yield 'OR of two IN' => [['relation' => 'OR', $cat([4]), $cat([5])], [20, 30], false];
        yield 'nested' => [['relation' => 'AND', ['relation' => 'OR', $cat([3]), $tag(['monsoon'])], $cat([4], 'term_id', 'NOT IN')], [12, 41], false];
    }

    /**
     * Test 25, end to end: a list of a parent category queried by slug is
     * dropped when a post is published into a grandchild (cases 30, 36).
     */
    public function test_parent_slug_list_dropped_by_grandchild_publish(): void
    {
        $q = $this->query(['category_name' => 'news'], [1, 2]);
        $q->tax_query = new WP_Tax_Query([['taxonomy' => 'category', 'terms' => ['news'], 'field' => 'slug', 'operator' => 'IN', 'include_children' => true]]);
        $q->query();
        $this->shutdown();
        $this->assertSame([10, 11, 12], array_values($this->blob_entries())[0]['scope']);

        do_action('transition_post_status', 'publish', 'future', WpStub::add_post(70, 'publish', 'post', [12]));
        $this->assertSame([], $this->blob_entries());
    }

    /**
     * Test 26: shutdown merges with entries another request wrote and drops expired ones (case 33).
     */
    public function test_shutdown_merges_and_prunes(): void
    {
        $this->seed('old', ['t' => self::NOW - 1000]);
        $this->query()->query();

        // Another request writes while this one renders.
        $this->seed('other', ['t' => self::NOW - 10]);
        $this->shutdown();

        $keys = array_keys($this->blob_entries());
        $this->assertContains('other', $keys);
        $this->assertNotContains('old', $keys);
        $this->assertCount(2, $keys);
        $this->assertSame(600, WpStub::$transient_expiry[1][TransientStore::TRANSIENT], 'blob outlives entries: twice the largest TTL');
    }

    /**
     * Test 27: an invalidation during the request discards new entries (cases 34, 44).
     */
    public function test_invalidation_during_request_discards_new_entries(): void
    {
        $this->seed('stale', ['all' => true, 'class' => 'fresh']);
        $this->query()->query();
        do_action('transition_post_status', 'publish', 'draft', WpStub::add_post(50, 'publish'));
        $this->shutdown();
        $this->assertSame([], $this->blob_entries());
        $this->assertSame(1, $this->blob_generation());
    }

    /**
     * Test 28: an oversized blob is trimmed oldest first and logged once (case 35).
     */
    public function test_oversized_blob_is_trimmed(): void
    {
        $this->each(static fn () => add_filter('query_id_cache_max_bytes', static fn () => 4096));
        for ($i = 0; $i < 40; $i++) {
            $this->seed('seed' . $i, ['t' => self::NOW - 100 + $i, 'ids' => range(1, 10)]);
        }
        $this->query([], range(1, 6))->query();
        $this->query([], [1], 'SELECT second LIMIT 1')->query();
        $this->shutdown();

        $blob = WpStub::$transients[1][TransientStore::TRANSIENT];
        $this->assertLessThanOrEqual(4096, strlen(serialize($blob)));
        $this->assertNotContains('seed0', array_keys($blob['entries']), 'oldest gone');
        $this->assertCount(2, array_filter(array_keys($blob['entries']), static fn ($k) => ! str_starts_with($k, 'seed')), 'new entries kept');
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'size:')));
    }

    /**
     * Test 42: identical queries in one request record one entry (case 47).
     */
    public function test_identical_queries_record_once(): void
    {
        $a = $this->query();
        $b = $this->query();
        $a->query();
        $b->query();
        $this->assertSame(1, $b->queries_run, 'still runs: nothing written until shutdown');
        $this->shutdown();
        $this->assertCount(1, $this->blob_entries());
        $this->assertSame(1, $this->plugin->cache->counters()['stored']);
    }

    /**
     * Test 43: a reused query object is handled as two separate runs (case 48).
     */
    public function test_reused_query_object(): void
    {
        $q = $this->query([], [1, 2], 'SELECT first LIMIT 2');
        $q->query();
        $q->request = 'SELECT second LIMIT 2';
        $q->db_ids = [5, 6];
        $q->query();
        $this->shutdown();
        $ids = array_map(static fn ($e) => $e['ids'], array_values($this->blob_entries()));
        $this->assertSame([[1, 2], [5, 6]], $ids);

        $this->new_request();
        $q = $this->query([], [], 'SELECT first LIMIT 2');
        $this->assertSame([1, 2], array_map(static fn ($p) => $p->ID, $q->query()));
        $q->request = 'SELECT second LIMIT 2';
        $this->assertSame([5, 6], array_map(static fn ($p) => $p->ID, $q->query()));
        $this->assertSame(0, $q->queries_run);
    }

    /**
     * Test 43: a reused `ids` object with no found rows loses only its first, unrecordable run (case 48).
     */
    public function test_reused_ids_object_records_second_run(): void
    {
        $q = $this->query(['fields' => 'ids', 'no_found_rows' => true], [1], 'SELECT a LIMIT 1');
        $q->query();
        $q->request = 'SELECT b LIMIT 1';
        $q->db_ids = [2];
        $q->query();
        $this->shutdown();
        $this->assertSame([[2]], array_map(static fn ($e) => $e['ids'], array_values($this->blob_entries())));
    }

    /**
     * Clock skew: entries are stamped with the request clock (case 53).
     */
    public function test_entries_use_request_clock(): void
    {
        Clock::freeze(self::NOW + 7);
        $this->assertSame(self::NOW + 7, $this->record_one()['t']);
    }
}
