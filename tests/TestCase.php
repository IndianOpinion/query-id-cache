<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests;

use IndianOpinion\QueryIdCache\Clock;
use IndianOpinion\QueryIdCache\Logger;
use IndianOpinion\QueryIdCache\Plugin;
use IndianOpinion\QueryIdCache\TransientStore;
use PHPUnit\Framework\TestCase as Base;
use WP_Query;
use WpStub;

abstract class TestCase extends Base
{
    public const NOW = 1_800_000_000;

    public const SQL = "SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 AND wp_posts.post_type = 'post'"
        . " AND wp_posts.post_status = 'publish' ORDER BY wp_posts.post_date DESC LIMIT 0, 10";

    /** @var list<string> */
    protected array $log = [];

    /** @var list<callable(): void> Site configuration replayed on every request. */
    private array $each = [];

    protected Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        WpStub::reset();
        $this->each = [];
        Clock::freeze(self::NOW);
        $this->boot();
        $this->front_page_request();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        Plugin::reset();
        parent::tearDown();
    }

    /**
     * Start a fresh plugin instance with fresh hooks, as a new request
     * would. Storage (transients, object cache, posts) survives.
     *
     * @param callable(): void|null $before_init Runs after boot, before `init`.
     */
    protected function boot(?callable $before_init = null, bool $debug = false): void
    {
        WpStub::$filters = [];
        WpStub::$did = [];
        $this->log = [];
        $this->plugin = new Plugin(new Logger(function (string $line): void {
            $this->log[] = $line;
        }, $debug));
        Plugin::reset($this->plugin);
        $this->plugin->boot();
        foreach ($this->each as $setup) {
            $setup();
        }
        if ($before_init !== null) {
            $before_init();
        }
        do_action('init');
    }

    /**
     * Site configuration (signatures, filters) that a theme would run on
     * every request: runs now and again after each simulated boot.
     *
     * @param callable(): void $setup
     */
    protected function each(callable $setup): void
    {
        $this->each[] = $setup;
        $setup();
    }

    /**
     * A new anonymous front-page request after the main query has run.
     */
    protected function new_request(): void
    {
        $flags = WpStub::$flags;
        $this->boot();
        WpStub::$flags = $flags;
        $this->front_page_request();
    }

    protected function front_page_request(): void
    {
        WpStub::$flags['front_page'] = true;
        WpStub::$flags['home'] = true;
        do_action('wp');
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<int>            $db_ids
     */
    protected function query(array $vars = [], array $db_ids = [1, 2, 3], ?string $sql = null, ?int $db_found = null): WP_Query
    {
        $q = new WP_Query($vars, $sql ?? self::SQL, $db_ids, $db_found);
        return $q;
    }

    /**
     * @param list<int> $ids
     */
    protected function posts(array $ids, string $status = 'publish', array $tt_ids = []): void
    {
        foreach ($ids as $id) {
            WpStub::add_post($id, $status, 'post', $tt_ids);
        }
    }

    protected function shutdown(): void
    {
        do_action('shutdown');
    }

    /**
     * Entries in the transient blob, key => entry.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function blob_entries(): array
    {
        $blob = WpStub::$transients[WpStub::$blog_id][TransientStore::TRANSIENT] ?? null;
        return is_array($blob) ? $blob['entries'] : [];
    }

    protected function blob_generation(): int
    {
        $blob = WpStub::$transients[WpStub::$blog_id][TransientStore::TRANSIENT] ?? null;
        return is_array($blob) ? (int) $blob['gen'] : 0;
    }

    /**
     * Run one query, shut down, and return its single stored entry.
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    protected function record_one(array $vars = [], array $db_ids = [1, 2, 3], ?string $sql = null): array
    {
        $this->query($vars, $db_ids, $sql)->query();
        $this->shutdown();
        $entries = $this->blob_entries();
        $this->assertCount(1, $entries);
        return array_values($entries)[0];
    }

    /**
     * Seed the store with one entry per spec, through the real code path.
     *
     * @param array<string, mixed> $entry
     */
    protected function seed(string $key, array $entry): void
    {
        $base = [
            't' => self::NOW, 'ids' => [1], 'found' => 1, 'class' => 'fresh', 'scope' => [], 'all' => true,
            'types' => ['post'], 'sig' => 'front-page', 'gen' => 0, 'ttl' => 300,
        ];
        $blob = WpStub::$transients[WpStub::$blog_id][TransientStore::TRANSIENT] ?? ['v' => TransientStore::FORMAT, 'gen' => 0, 'entries' => [], 'stats' => []];
        $blob['entries'][$key] = $entry + $base;
        WpStub::$transients[WpStub::$blog_id][TransientStore::TRANSIENT] = $blob;
    }
}
