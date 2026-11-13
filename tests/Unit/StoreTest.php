<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\Clock;
use IndianOpinion\QueryIdCache\Entry;
use IndianOpinion\QueryIdCache\Logger;
use IndianOpinion\QueryIdCache\ObjectCacheStore;
use IndianOpinion\QueryIdCache\Plugin;
use IndianOpinion\QueryIdCache\Store;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use IndianOpinion\QueryIdCache\TransientStore;
use IndianOpinion\QueryIdCache\Uninstaller;
use WpStub;

/**
 * Acceptance tests 46 to 48, 51 to 53 and 58 (cases 18, 34, 53 to 56, 59, section 6).
 */
final class StoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->posts([1, 2, 3]);
    }

    /**
     * Test 46: a corrupt or wrong-type store value is empty and logged once (cases 54, 59).
     *
     * @dataProvider corrupt
     */
    public function test_corrupt_store_is_empty_and_logged_once(mixed $value): void
    {
        WpStub::$transients[1][TransientStore::TRANSIENT] = $value;
        $q = $this->query();
        $q->query();
        $this->query([], [1], 'SELECT two LIMIT 1')->query();
        $this->assertSame(1, $q->queries_run);
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'store-read')));

        $this->shutdown();
        $this->assertCount(2, $this->blob_entries(), 'overwritten with the current format');
        $this->assertSame(TransientStore::FORMAT, WpStub::$transients[1][TransientStore::TRANSIENT]['v']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function corrupt(): iterable
    {
        yield 'string' => ['garbage'];
        yield 'integer' => [42];
        yield 'older format' => [['v' => 0, 'gen' => 3, 'entries' => ['k' => ['ids' => [1]]]]];
        yield 'entries not an array' => [['v' => 1, 'gen' => 0, 'entries' => 'x']];
    }

    /**
     * Test 47: an entry with non-integer IDs is ignored and removed at the next write (case 55).
     */
    public function test_malformed_entry_is_ignored(): void
    {
        $sql = self::SQL;
        $key = \IndianOpinion\QueryIdCache\Cache::key($sql, 'all', 'front-page', 1);
        $this->seed($key, ['ids' => ['1', 'two']]);
        $this->seed('broken', ['found' => 'many']);

        $q = $this->query();
        $q->query();
        $this->assertSame(1, $q->queries_run, 'malformed entry is a miss');
        $this->shutdown();
        $this->assertSame([$key], array_keys($this->blob_entries()));
        $this->assertSame([1, 2, 3], $this->blob_entries()[$key]['ids']);

        $this->assertNull(Entry::validate(['t' => 1]));
        $this->assertNull(Entry::validate('x'));
    }

    /**
     * Test 48: a failing store write is logged and nothing else happens (case 56).
     */
    public function test_failing_write_is_logged(): void
    {
        WpStub::$fail_transient_write = true;
        $stored = 0;
        add_action('query_id_cache_stored', static function () use (&$stored): void {
            $stored++;
        });
        $this->query()->query();
        $this->shutdown();
        $this->assertSame(0, $stored);
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'store-write')));
        $this->assertSame(0, $this->plugin->cache->counters()['stored']);
    }

    /**
     * Test 51: store selection by object cache presence; the filter wins (section 6).
     */
    public function test_store_selection(): void
    {
        $this->assertInstanceOf(TransientStore::class, (new Plugin())->store());

        WpStub::$ext_cache = true;
        $this->assertInstanceOf(ObjectCacheStore::class, (new Plugin())->store());

        $custom = new TransientStore(new Logger(static fn () => null));
        add_filter('query_id_cache_store', static fn () => $custom);
        $this->assertSame($custom, (new Plugin())->store());

        WpStub::$filters = [];
        add_filter('query_id_cache_store', static fn () => 'not a store');
        $plugin = new Plugin(new Logger(function (string $l): void {
            $this->log[] = $l;
        }));
        $this->assertInstanceOf(ObjectCacheStore::class, $plugin->store());
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'store-filter')));
    }

    /**
     * Test 51: the object cache store serves a full miss-then-hit cycle (section 6).
     */
    public function test_object_cache_store_round_trip(): void
    {
        WpStub::$ext_cache = true;
        $this->new_request();
        $this->query()->query();
        $this->shutdown();
        $this->assertSame([], WpStub::$transients, 'no transient with an object cache');

        $this->new_request();
        $q = $this->query();
        $q->query();
        $this->assertSame(0, $q->queries_run);
        $this->assertSame(3, $q->found_posts);

        // Invalidation orphans everything at once.
        do_action('transition_post_status', 'publish', 'draft', WpStub::add_post(9, 'publish'));
        $this->new_request();
        $q = $this->query();
        $q->query();
        $this->assertSame(1, $q->queries_run);
    }

    /**
     * Test 52: an object cache generation bump makes every old entry a miss (section 6, case 34).
     */
    public function test_object_cache_bump_orphans_entries(): void
    {
        $store = new ObjectCacheStore(new Logger(static fn () => null));
        $entry = Entry::make(self::NOW, [1], 1, 'fresh', [], true, ['post'], 'front-page', 0, 300);
        $this->assertTrue($store->save(['k' => $entry]));
        $this->assertSame($entry, $store->get('k'));
        $this->assertSame(['k' => $entry], $store->load());

        $store->bump_generation();
        $this->assertNull($store->get('k'));
        $this->assertSame([], $store->load());
    }

    /**
     * Test 52: an entry whose index row is gone is never served.
     */
    public function test_object_cache_entry_without_index_is_ignored(): void
    {
        $store = new ObjectCacheStore(new Logger(static fn () => null));
        $entry = Entry::make(self::NOW, [1], 1, 'fresh', [], true, ['post'], 'front-page', 0, 300);
        $store->save(['k' => $entry]);
        $gen = $store->generation();
        wp_cache_delete('index:' . $gen, ObjectCacheStore::GROUP);
        $this->assertNull((new ObjectCacheStore(new Logger(static fn () => null)))->get('k'));
    }

    /**
     * Test 52: the object cache store applies the entry cap oldest first.
     */
    public function test_object_cache_entry_cap(): void
    {
        $store = new ObjectCacheStore(new Logger(static fn () => null), 2);
        foreach ([1, 2, 3] as $i) {
            $store->save(['k' . $i => Entry::make(self::NOW + $i, [1], 1, 'fresh', [], true, ['post'], 'front-page', 0, 300)]);
        }
        $this->assertSame(['k2', 'k3'], array_keys($store->load()));
    }

    /**
     * Test 58: two instances sharing one object cache: a bump through one
     * makes the other miss; an entry stamped more than 60 s ahead is a miss (cases 18, 34, 53).
     */
    public function test_two_nodes_share_one_object_cache(): void
    {
        WpStub::$ext_cache = true;
        $node_a = new ObjectCacheStore(new Logger(static fn () => null));
        $node_b = new ObjectCacheStore(new Logger(static fn () => null));
        $entry = Entry::make(self::NOW, [1], 1, 'fresh', [], true, ['post'], 'front-page', 0, 300);
        $gen = $node_a->generation();
        $node_a->save(['k' => $entry]);
        $this->assertSame($entry, $node_b->get('k'));
        $this->assertSame($gen, $node_b->generation());

        $node_a->bump_generation();
        $this->assertSame($gen + 1, $node_b->generation());
        $this->assertNull($node_b->get('k'));

        // Node B's clock runs 61 s behind node A, which stamped the entry.
        $node_a->save(['k2' => $entry]);
        $this->assertTrue(Entry::is_stale($entry['t'], 300, self::NOW - 61));
        $this->assertFalse(Entry::is_stale($entry['t'], 300, self::NOW - 60));
    }

    /**
     * Test 58: a generation bumped by another node while a request runs discards its writes (case 34).
     */
    public function test_bump_from_other_node_discards_writes(): void
    {
        WpStub::$ext_cache = true;
        $this->new_request();
        $this->query()->query();
        (new ObjectCacheStore(new Logger(static fn () => null)))->bump_generation();
        $this->shutdown();
        $this->assertSame([], $this->plugin->store()->load());
    }

    /**
     * Test 58: an evicted generation item restarts above every older generation.
     */
    public function test_evicted_generation_restarts_from_clock(): void
    {
        $store = new ObjectCacheStore(new Logger(static fn () => null));
        $first = $store->generation();
        wp_cache_delete('generation', ObjectCacheStore::GROUP);
        $this->assertGreaterThanOrEqual($first, $store->generation());
        $this->assertGreaterThan(1_000_000_000_000, $first);
    }

    /**
     * Test 53: uninstall removes the transient on every site and leaves no options (section 6).
     */
    public function test_uninstall_every_site(): void
    {
        WpStub::$sites = [1, 2, 3];
        foreach ([1, 2, 3] as $blog) {
            WpStub::$transients[$blog][TransientStore::TRANSIENT] = ['v' => 1];
            WpStub::$transients[$blog]['unrelated'] = 'keep';
        }
        Uninstaller::run();
        foreach ([1, 2, 3] as $blog) {
            $this->assertSame(['unrelated' => 'keep'], WpStub::$transients[$blog]);
        }
        $this->assertSame(1, WpStub::$blog_id, 'restored');
    }

    /**
     * Test 53: uninstall flushes the object cache group.
     */
    public function test_uninstall_object_cache(): void
    {
        WpStub::$ext_cache = true;
        wp_cache_set('generation', 5, ObjectCacheStore::GROUP);
        wp_cache_set('keep', 1, 'other');
        Uninstaller::run();
        $this->assertArrayNotHasKey(ObjectCacheStore::GROUP, WpStub::$cache);
        $this->assertSame(1, wp_cache_get('keep', 'other'));
    }

    /**
     * Test 53: the uninstall entry point exists and guards direct access.
     */
    public function test_uninstall_file(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString("defined('WP_UNINSTALL_PLUGIN')", $code);
        $this->assertStringContainsString('Uninstaller::run()', $code);
    }

    /**
     * Blob store: drop removes keys and bumps the generation in one write.
     */
    public function test_transient_drop_is_one_write(): void
    {
        $this->seed('a', []);
        $this->seed('b', []);
        $store = new TransientStore(new Logger(static fn () => null));
        $writes = WpStub::$db_queries;
        $reads = WpStub::$transient_reads;
        $this->assertSame(1, $store->drop(['a']));
        $this->assertSame(['b'], array_keys($this->blob_entries()));
        $this->assertSame(2, WpStub::$db_queries - $writes, 'one read and one write');
        $this->assertSame(1, WpStub::$transient_reads - $reads);
    }

    /**
     * Store contract holds for any implementation.
     *
     * @dataProvider stores
     */
    public function test_store_contract(callable $make): void
    {
        /** @var Store $store */
        $store = $make();
        $gen = $store->generation();
        $entry = Entry::make(self::NOW, [1, 2], 2, 'ttl', [3], false, ['post'], 'front-page', $gen, 300);
        $this->assertTrue($store->save(['k' => $entry]));
        $this->assertSame($entry, Entry::validate($store->get('k')));
        $this->assertArrayHasKey('k', $store->load());
        $this->assertSame(1, $store->stats()['front-page']['stored']);
        $this->assertArrayHasKey('type', $store->describe());
        $this->assertSame($gen + 1, $store->bump_generation());
        $store->delete_all();
        $this->assertSame([], $store->load());
        $this->assertSame($gen + 2, $store->generation());

        Clock::freeze(self::NOW + 400);
        $store->save(['fresh' => Entry::make(self::NOW + 400, [1], 1, 'fresh', [], true, ['post'], 'front-page', 0, 300)]);
        $this->assertSame(['fresh'], array_keys($store->load()));
    }

    /** @return iterable<string, array{callable(): Store}> */
    public static function stores(): iterable
    {
        yield 'transient' => [static fn (): Store => new TransientStore(new Logger(static fn () => null))];
        yield 'object cache' => [static fn (): Store => new ObjectCacheStore(new Logger(static fn () => null))];
    }
}
