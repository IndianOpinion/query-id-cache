<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\RequestGate;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use WpStub;

/**
 * Acceptance tests 1 to 4 (cases 1 to 6).
 */
final class RequestEligibilityTest extends TestCase
{
    /**
     * Test 1: admin, AJAX, cron, REST, CLI and XML-RPC are not eligible (case 1).
     *
     * @dataProvider contexts
     */
    public function test_non_front_end_contexts_are_not_eligible(string $flag): void
    {
        WpStub::$flags[$flag] = true;
        $this->assertFalse((new RequestGate())->eligible());
    }

    /** @return iterable<string, array{string}> */
    public static function contexts(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'ajax' => ['ajax'];
        yield 'cron' => ['cron'];
        yield 'rest' => ['rest'];
        yield 'customizer' => ['customize'];
        yield 'preview' => ['preview'];
    }

    /**
     * Test 1: WP-CLI and XML-RPC are detected through their constants (case 1).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cli_constant_is_not_eligible(): void
    {
        define('WP_CLI', true);
        $gate = new RequestGate();
        $this->assertFalse($gate->eligible());
        $this->assertSame('cli', $gate->reason());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_xmlrpc_constant_is_not_eligible(): void
    {
        define('XMLRPC_REQUEST', true);
        $gate = new RequestGate();
        $this->assertFalse($gate->eligible());
        $this->assertSame('xmlrpc', $gate->reason());
    }

    /**
     * Test 1: the master switch and the final-say filter (case 1).
     */
    public function test_enabled_and_eligible_filters(): void
    {
        add_filter('query_id_cache_enabled', '__return_false');
        $this->assertFalse((new RequestGate())->eligible());

        WpStub::$filters = [];
        add_filter('query_id_cache_request_eligible', static fn (): bool => false);
        $this->assertFalse((new RequestGate())->eligible());
    }

    /**
     * Test 2: preview variables or a Customizer changeset disqualify an anonymous request (case 2).
     *
     * @dataProvider preview_vars
     */
    public function test_preview_variables_are_not_eligible(string $var): void
    {
        $_GET[$var] = '1';
        $gate = new RequestGate();
        $this->assertFalse($gate->eligible());
        $this->assertSame('preview', $gate->reason());
    }

    /** @return iterable<string, array{string}> */
    public static function preview_vars(): iterable
    {
        foreach (['preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid'] as $var) {
            yield $var => [$var];
        }
    }

    /**
     * Test 2: an embed of a draft is not eligible (case 1).
     */
    public function test_embed_of_a_draft_is_not_eligible(): void
    {
        WpStub::$flags['embed'] = true;
        WpStub::$queried_object = WpStub::add_post(9, 'draft');
        $this->assertFalse((new RequestGate())->eligible());

        WpStub::$queried_object = WpStub::add_post(10, 'publish');
        $this->assertTrue((new RequestGate())->eligible());
    }

    /**
     * Test 3: before the main query, the answer is "not now" and is not memoised (cases 3, 4).
     */
    public function test_not_memoised_before_main_query(): void
    {
        WpStub::$did = [];
        $gate = new RequestGate();
        $this->assertFalse($gate->eligible());
        $this->assertSame('main query not run', $gate->reason());

        do_action('wp');
        $this->assertTrue($gate->eligible());

        // Memoised now: a later change to the conditional state is not re-read.
        WpStub::$flags['preview'] = true;
        $this->assertTrue($gate->eligible());
    }

    /**
     * Test 3: a main query with posts also counts as "ready" (case 3).
     */
    public function test_main_query_with_posts_is_ready(): void
    {
        WpStub::$did = [];
        $main = new \WP_Query();
        $main->posts = [];
        $GLOBALS['wp_the_query'] = $main;
        $this->assertTrue(RequestGate::main_query_ready());
    }

    /**
     * Test 3: an early secondary query does not switch the cache off for the request (lesson 1).
     */
    public function test_early_secondary_query_does_not_disable_request(): void
    {
        $this->posts([1, 2, 3]);
        WpStub::$did = [];
        $early = $this->query();
        $early->query();
        $this->assertSame(1, $early->queries_run);

        do_action('wp');
        $this->query()->query();
        $this->shutdown();
        $this->assertCount(1, $this->blob_entries());
    }

    /**
     * Test 4: a signed-in request neither reads nor writes the store (cases 5, 6).
     */
    public function test_signed_in_request_never_touches_store(): void
    {
        $this->posts([1, 2, 3]);
        $this->query()->query();
        $this->shutdown();
        $this->assertCount(1, $this->blob_entries());
        $before = WpStub::$transients;

        WpStub::$flags['logged_in'] = true;
        $this->new_request();
        $reads = WpStub::$transient_reads;
        $q = $this->query([], [1, 2, 3]);
        $q->query();
        $this->assertSame(1, $q->queries_run, 'real query ran');
        $this->query(['posts_per_page' => 5], [1])->query();
        $this->shutdown();

        $this->assertSame($before, WpStub::$transients, 'nothing written');
        $this->assertSame($reads, WpStub::$transient_reads, 'store never read');
    }
}
