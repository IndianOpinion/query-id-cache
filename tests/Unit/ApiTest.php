<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\Cli;
use IndianOpinion\QueryIdCache\DebugHeader;
use IndianOpinion\QueryIdCache\InvalidSignature;
use IndianOpinion\QueryIdCache\Plugin;
use IndianOpinion\QueryIdCache\RequestGate;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use WpStub;

use function IndianOpinion\QueryIdCache\get_signatures;
use function IndianOpinion\QueryIdCache\register_signature;
use function IndianOpinion\QueryIdCache\unregister_signature;

/**
 * Acceptance tests 38, 49, 50, 54 and 55, plus the public API (5.1 to 5.7).
 */
final class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->posts([1, 2, 3]);
    }

    /**
     * Test 54: an invalid name or class throws InvalidSignature at registration (5.1).
     *
     * @dataProvider invalid
     *
     * @param array<string, mixed> $args
     */
    public function test_invalid_signature_throws(string $name, array $args): void
    {
        $this->expectException(InvalidSignature::class);
        register_signature($name, $args);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'upper case name' => ['Front', []];
        yield 'spaces in name' => ['front page', []];
        yield 'trailing hyphen' => ['front-', []];
        yield 'empty name' => ['', []];
        yield 'bad class' => ['ok', ['freshness' => 'sometimes']];
        yield 'unknown context' => ['ok', ['context' => 'search']];
        yield 'context not callable' => ['ok', ['context' => 42]];
        yield 'post type any' => ['ok', ['post_types' => ['any']]];
        yield 'empty post types' => ['ok', ['post_types' => []]];
        yield 'ttl not int' => ['ok', ['ttl' => '300']];
        yield 'match not callable' => ['ok', ['match' => 'no_such_function_here']];
        yield 'opt_in_only not bool' => ['ok', ['opt_in_only' => 1]];
        yield 'unknown argument' => ['ok', ['ttls' => 5]];
    }

    /**
     * 5.1: defaults, replace, unregister and the signatures filter.
     */
    public function test_registration_api(): void
    {
        $sigs = get_signatures();
        $this->assertSame(['front-page'], array_keys($sigs));
        $fp = $sigs['front-page'];
        $this->assertSame('front_page', $fp->context);
        $this->assertSame(['post'], $fp->post_types);
        $this->assertSame('auto', $fp->freshness);
        $this->assertNull($fp->ttl);
        $this->assertFalse($fp->opt_in_only);

        register_signature('sections', ['context' => 'archive', 'post_types' => 'post', 'ttl' => 120]);
        register_signature('front-page', ['freshness' => 'ttl']);
        $this->assertSame(['front-page', 'sections'], array_keys(get_signatures()), 'replace keeps position');
        $this->assertSame('ttl', get_signatures()['front-page']->freshness);

        unregister_signature('front-page');
        do_action('init');
        $this->assertSame(['sections'], array_keys(get_signatures()), 'the default does not come back');

        add_filter('query_id_cache_signatures', static function (array $sigs): array {
            $sigs['extra'] = ['context' => 'any'];
            $sigs['Bad Name'] = [];
            return $sigs;
        });
        $this->plugin->registry->reset_memo();
        $this->assertSame(['sections', 'extra'], array_keys(get_signatures()));
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'signatures-filter')));
    }

    /**
     * Test 49: a throwing match callback disables the signature for the request, logged once (case 57).
     */
    public function test_throwing_callback_disables_signature(): void
    {
        $calls = 0;
        $this->each(static function () use (&$calls): void {
            unregister_signature('front-page');
            register_signature('boom', ['context' => 'any', 'match' => static function () use (&$calls): bool {
                $calls++;
                throw new \RuntimeException('bad');
            }]);
            register_signature('fallback', ['context' => 'any', 'ttl' => 77]);
        });
        $this->query()->query();
        $this->query([], [1], 'SELECT two LIMIT 1')->query();
        $this->shutdown();
        $this->assertSame(1, $calls, 'not called again in this request');
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'callback:boom')));
        foreach ($this->blob_entries() as $entry) {
            $this->assertSame('fallback', $entry['sig']);
        }
    }

    /**
     * Test 50: an exception inside the query filter returns the incoming value unchanged (case 58).
     */
    public function test_internal_exception_returns_incoming(): void
    {
        $this->each(static fn () => add_filter('query_id_cache_store', static function () {
            throw new \LogicException('store exploded');
        }));
        $q = $this->query();
        $this->assertNull($this->plugin->cache->pre_query(null, $q));
        $this->assertSame(['x'], $this->plugin->cache->pre_query(['x'], $q));
        $posts = $q->query();
        $this->assertCount(3, $posts, 'the real query ran');
        $this->assertCount(1, array_filter($this->log, static fn ($l) => str_contains($l, 'internal')));
    }

    /**
     * Test 55: the debug header appears only when enabled and on eligible anonymous responses, with counts only (11, 8).
     */
    public function test_debug_header(): void
    {
        $sent = [];
        $emit = static function (string $line) use (&$sent): void {
            $sent[] = $line;
        };

        $header = new DebugHeader($this->plugin->gate, $this->plugin->cache, $emit);
        $this->query()->query();
        $this->shutdown();
        $header->send();
        $this->assertSame([], $sent, 'off by default');

        $this->each(static fn () => add_filter('query_id_cache_debug_header', '__return_true'));
        $this->new_request();
        $header = new DebugHeader($this->plugin->gate, $this->plugin->cache, $emit);
        $this->query()->query();
        $this->query([], [1], 'SELECT new LIMIT 1')->query();
        $this->shutdown();
        $header->send();
        $this->assertSame(['X-Query-Id-Cache: hits=1; misses=1; stored=1'], $sent);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{12}|SELECT/', $sent[0]);

        $sent = [];
        WpStub::$flags['logged_in'] = true;
        $header = new DebugHeader(new RequestGate(), $this->plugin->cache, $emit);
        $header->send();
        $this->assertSame([], $sent, 'never on signed-in responses');
    }

    /**
     * Test 55: the buffer callback passes the body through untouched.
     */
    public function test_debug_header_buffer_callback(): void
    {
        $sent = [];
        add_filter('query_id_cache_debug_header', '__return_true');
        $header = new DebugHeader($this->plugin->gate, $this->plugin->cache, static function (string $l) use (&$sent): void {
            $sent[] = $l;
        });
        $this->assertSame('<html>', $header->finish('<html>'));
        $this->assertCount(1, $sent);
    }

    /**
     * Test 38: `wp query-id-cache flush` empties the store and bumps the generation (case 43).
     * Test 13: `status` reports entries per signature and "keys never repeat" (case 16).
     */
    public function test_cli(): void
    {
        $out = [];
        $cli = new Cli($this->plugin, static function (string $l) use (&$out): void {
            $out[] = $l;
        });
        $this->query()->query();
        $this->shutdown();

        $cli->status();
        $text = implode("\n", $out);
        $this->assertStringContainsString('Store:      transient', $text);
        $this->assertStringContainsString('Entries:    1', $text);
        $this->assertStringContainsString('Generation: 0', $text);
        $this->assertStringContainsString('front-page: 1 entries, 1 stored', $text);
        $this->assertStringNotContainsString('keys never repeat', $text);

        $out = [];
        $cli->list_([], ['signature' => 'front-page']);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("front-page\tfresh\t0s\t3\t3\tall", $out[1]);
        $out = [];
        $cli->list_([], ['signature' => 'nope']);
        $this->assertCount(1, $out);

        $out = [];
        $cli->flush();
        $this->assertSame(['Flushed. Generation is now 1.'], $out);
        $this->assertSame([], $this->blob_entries());

        $blob = WpStub::$transients[1]['query_id_cache'];
        $blob['stats']['front-page'] = ['stored' => 50, 'repeats' => 0, 'recent' => []];
        WpStub::$transients[1]['query_id_cache'] = $blob;
        $out = [];
        $cli->status();
        $this->assertStringContainsString('keys never repeat', implode("\n", $out));
    }

    /**
     * 5.7: the command is registered under WP-CLI only.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cli_registration(): void
    {
        define('WP_CLI', true);
        WpStub::$cli_commands = [];
        (new Plugin())->boot();
        $this->assertSame(['query-id-cache'], WpStub::$cli_commands);
    }

    /**
     * 5.5: QUERY_ID_CACHE_DISABLE registers no hooks.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_disable_constant(): void
    {
        define('QUERY_ID_CACHE_DISABLE', true);
        WpStub::$filters = [];
        (new Plugin())->boot();
        $this->assertSame([], WpStub::$filters);
    }

    /**
     * 5.5: QUERY_ID_CACHE_TTL sets the global TTL; the filter still runs after it.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ttl_constant(): void
    {
        define('QUERY_ID_CACHE_TTL', 60);
        $this->assertSame(60, \IndianOpinion\QueryIdCache\Config::ttl());
        add_filter('query_id_cache_ttl', static fn (int $t): int => $t * 2);
        $this->assertSame(120, \IndianOpinion\QueryIdCache\Config::ttl());
    }

    /**
     * 11: QUERY_ID_CACHE_DEBUG adds one log line per invalidation.
     */
    public function test_verbose_invalidation_logging(): void
    {
        $this->boot(null, true);
        $this->front_page_request();
        $this->query()->query();
        $this->shutdown();
        do_action('update_option_sticky_posts');
        $this->assertContains('query-id-cache: invalidated: reason=sticky dropped=1', $this->log);
    }

    /**
     * 10: the plugin and mu-plugin entry points load the same bootstrap.
     */
    public function test_entry_points(): void
    {
        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/query-id-cache.php');
        $this->assertStringContainsString('Plugin Name:       Query ID Cache', $main);
        $this->assertStringContainsString('Requires at least: 6.4', $main);
        $loader = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/mu-plugin-loader.php');
        $this->assertStringContainsString("query-id-cache/query-id-cache.php", $loader);
    }
}
