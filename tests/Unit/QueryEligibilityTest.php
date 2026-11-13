<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache\Tests\Unit;

use IndianOpinion\QueryIdCache\QueryInspector;
use IndianOpinion\QueryIdCache\Tests\TestCase;
use WpStub;

use function IndianOpinion\QueryIdCache\register_signature;
use function IndianOpinion\QueryIdCache\unregister_signature;

/**
 * Acceptance tests 5 to 10, 44, 45 and 56 (cases 7 to 13, 51, 52, 9).
 */
final class QueryEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->posts([1, 2, 3]);
    }

    /** Runs a query twice in two requests; true when the second was served. */
    private function served_twice(array $vars, ?string $sql = null): bool
    {
        $this->query($vars, [1, 2, 3], $sql)->query();
        $this->shutdown();
        $this->new_request();
        $q = $this->query($vars, [1, 2, 3], $sql);
        $q->query();
        $this->shutdown();
        return $q->queries_run === 0;
    }

    /**
     * Test 5: the main query is never served or recorded (case 7).
     */
    public function test_main_query_is_never_cached(): void
    {
        $q = $this->query();
        $q->main = true;
        $q->query();
        $this->shutdown();
        $this->assertSame([], $this->blob_entries());
        $this->assertSame(0, WpStub::$transient_reads);
    }

    /**
     * Test 6: a non-null incoming value is returned unchanged; nothing is recorded (case 8).
     */
    public function test_steps_aside_when_already_answered(): void
    {
        add_filter('posts_pre_query', static fn ($posts) => [3, 2], 10, 1);
        $q = $this->query();
        $result = $q->query();
        $this->shutdown();
        $this->assertSame([3, 2], array_map(static fn ($p) => $p->ID, $result));
        $this->assertSame([], $this->blob_entries());
    }

    /**
     * Test 7: no matching signature leaves the query alone; first registered wins (case 10).
     */
    public function test_signature_matching_order(): void
    {
        $this->each(static fn () => unregister_signature('front-page'));
        $q = $this->query();
        $q->query();
        $this->shutdown();
        $this->assertSame([], $this->blob_entries());

        $this->each(static function (): void {
            register_signature('first', ['context' => 'any', 'ttl' => 111]);
            register_signature('second', ['context' => 'any', 'ttl' => 222]);
        });
        $entry = $this->record_one();
        $this->assertSame('first', $entry['sig']);
        $this->assertSame(111, $entry['ttl']);
    }

    /**
     * Test 7: a signature whose context is false does not match (case 10).
     */
    public function test_context_must_hold(): void
    {
        $this->each(static function (): void {
            unregister_signature('front-page');
            register_signature('cats', ['context' => 'category']);
        });
        $this->query()->query();
        $this->shutdown();
        $this->assertSame([], $this->blob_entries());

        WpStub::$flags['category'] = true;
        $this->new_request();
        $this->assertSame('cats', $this->record_one()['sig']);
    }

    /**
     * Test 7: named contexts are false on a search results page (ruling 6).
     */
    public function test_named_contexts_exclude_search_pages(): void
    {
        WpStub::$flags['search'] = true;
        $this->query()->query();
        $this->shutdown();
        $this->assertSame([], $this->blob_entries());
    }

    /**
     * Test 7: the contexts list is filterable (ruling 6).
     */
    public function test_contexts_are_filterable(): void
    {
        $this->each(static function (): void {
            add_filter('query_id_cache_contexts', static function (array $contexts): array {
                $contexts['newsroom'] = static fn (): bool => true;
                return $contexts;
            });
            unregister_signature('front-page');
            register_signature('desk', ['context' => 'newsroom']);
        });
        $this->assertSame('desk', $this->record_one()['sig']);
    }

    /**
     * Test 8: random order, author, non-publish status, search, id=>parent, post__in are rejected (case 11, ruling 4).
     *
     * @dataProvider exclusions
     *
     * @param array<string, mixed> $vars
     */
    public function test_hard_exclusions(array $vars, ?string $sql = null): void
    {
        $this->assertFalse($this->served_twice($vars, $sql));
        $this->assertSame([], $this->blob_entries());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1?: string}> */
    public static function exclusions(): iterable
    {
        yield 'orderby rand' => [['orderby' => 'rand']];
        yield 'orderby rand array' => [['orderby' => ['rand' => 'ASC']]];
        yield 'RAND( in sql' => [[], 'SELECT wp_posts.ID FROM wp_posts ORDER BY RAND(42) LIMIT 5'];
        yield 'author' => [['author' => 3]];
        yield 'author_name' => [['author_name' => 'ann']];
        yield 'author__in' => [['author__in' => [3]]];
        yield 'author__not_in' => [['author__not_in' => [3]]];
        yield 'author column in sql' => [[], 'SELECT wp_posts.ID FROM wp_posts WHERE wp_posts.post_author IN (3) LIMIT 5'];
        yield 'status any' => [['post_status' => 'any']];
        yield 'status private' => [['post_status' => 'private']];
        yield 'status future' => [['post_status' => 'future']];
        yield 'status draft' => [['post_status' => 'draft']];
        yield 'status inherit' => [['post_status' => 'inherit']];
        yield 'status list' => [['post_status' => ['publish', 'private']]];
        yield 'perm' => [['perm' => 'readable']];
        yield 'search' => [['s' => 'budget']];
        yield 'id=>parent' => [['fields' => 'id=>parent']];
        yield 'post__in' => [['post__in' => [3, 1, 2], 'orderby' => 'post__in']];
        yield 'empty sql' => [[], ' '];
    }

    /**
     * Test 8: an explicit publish status is accepted (case 11).
     */
    public function test_publish_status_is_accepted(): void
    {
        $this->assertTrue($this->served_twice(['post_status' => 'publish']));
        $this->new_request();
        $this->assertTrue($this->served_twice(['post_status' => ['publish']]));
    }

    /**
     * Test 9: opt in by name, and opt out with false beats a matching signature (case 12).
     */
    public function test_opt_in_and_opt_out(): void
    {
        $this->each(static fn () => register_signature('sidebar', ['context' => 'any', 'opt_in_only' => true]));

        $entry = $this->record_one([QueryInspector::QUERY_VAR => 'sidebar'], [1, 2], 'SELECT 1 LIMIT 5');
        $this->assertSame('sidebar', $entry['sig']);

        $this->assertFalse($this->served_twice([QueryInspector::QUERY_VAR => false], 'SELECT 2 LIMIT 5'));
        $this->assertFalse($this->served_twice([QueryInspector::QUERY_VAR => 'false'], 'SELECT 3 LIMIT 5'));
    }

    /**
     * Test 9: an opt-in-only signature never matches a query that does not name it (case 12).
     */
    public function test_opt_in_only_requires_name(): void
    {
        $this->each(static function (): void {
            unregister_signature('front-page');
            register_signature('sidebar', ['context' => 'any', 'opt_in_only' => true]);
        });
        $this->assertFalse($this->served_twice([]));
    }

    /**
     * Test 9: a match callback filters queries (case 12).
     */
    public function test_match_callback(): void
    {
        $this->each(static function (): void {
            unregister_signature('front-page');
            register_signature('big', ['context' => 'any', 'match' => static fn (\WP_Query $q): bool => $q->get('posts_per_page') === 20]);
        });
        $this->assertFalse($this->served_twice(['posts_per_page' => 10]));
        $this->assertTrue($this->served_twice(['posts_per_page' => 20]));
    }

    /**
     * Test 10: a post type outside the signature, or `any`, is rejected (case 13).
     */
    public function test_post_types_must_be_covered(): void
    {
        $this->assertFalse($this->served_twice(['post_type' => ['post', 'page']]));
        $this->assertFalse($this->served_twice(['post_type' => 'any']));
        $this->assertTrue($this->served_twice(['post_type' => 'post']));
    }

    /**
     * Test 10: an empty post type on a custom taxonomy query is left alone (case 13).
     */
    public function test_empty_post_type_on_custom_taxonomy_is_rejected(): void
    {
        $q = $this->query();
        $q->is_tax = true;
        $this->assertNull(QueryInspector::post_types($q));
    }

    /**
     * Test 44: a query inside switch_to_blog is neither served nor recorded (case 51).
     */
    public function test_switched_blog_is_never_cached(): void
    {
        $this->query()->query();
        $this->shutdown();
        $this->assertCount(1, $this->blob_entries());

        $this->new_request();
        switch_to_blog(1);
        $q = $this->query([], [1, 2, 3], 'SELECT other LIMIT 1');
        $q->query();
        $again = $this->query();
        $again->query();
        restore_current_blog();
        $this->shutdown();

        $this->assertSame(1, $again->queries_run, 'not served while switched');
        $this->assertCount(1, $this->blob_entries(), 'not recorded while switched');
    }

    /**
     * Test 45: a signature with a time to live of zero is disabled (case 52).
     */
    public function test_zero_ttl_disables_signature(): void
    {
        $this->each(static fn () => register_signature('front-page', ['ttl' => 0]));
        $this->assertFalse($this->served_twice([]));
        $this->assertSame([], $this->blob_entries());
    }

    /**
     * Test 56: a suppress_filters query gets no cache behaviour (case 9).
     *
     * WordPress runs posts_pre_query even for these queries, but not the
     * result filters, so the plugin must leave them alone explicitly.
     */
    public function test_suppress_filters_query_is_left_alone(): void
    {
        $this->assertFalse($this->served_twice(['suppress_filters' => true]));
        $this->assertSame([], $this->blob_entries());
        $this->assertStringContainsString('suppress_filters', (string) file_get_contents(dirname(__DIR__, 2) . '/README.md'));
    }

    /**
     * Test 7: the per-query filter has the final say (5.3).
     */
    public function test_query_eligible_filter(): void
    {
        $seen = [];
        add_filter('query_id_cache_query_eligible', static function (bool $ok, $query, string $name) use (&$seen): bool {
            $seen[] = $name;
            return false;
        }, 10, 3);
        $this->query()->query();
        $this->shutdown();
        $this->assertSame(['front-page'], $seen);
        $this->assertSame([], $this->blob_entries());
    }
}
