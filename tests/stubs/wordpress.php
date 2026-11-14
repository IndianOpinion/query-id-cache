<?php

/**
 * A small stand-in for the parts of WordPress the plugin touches, so the
 * tests run without a WordPress install. Behaviour mirrors WordPress 6.4
 * where it matters (WP_Query::get_posts() hook order, set_found_posts()).
 */

declare(strict_types=1);

// phpcs:disable

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

final class WpStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $did = [];
    /** @var array<string, bool> */
    public static array $flags = [];
    /** @var array<int, array<string, mixed>> blog => transient => value */
    public static array $transients = [];
    /** @var array<int, array<string, int>> */
    public static array $transient_expiry = [];
    /** @var array<string, array<string, mixed>> group => key => value */
    public static array $cache = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, list<int>> post id => tt ids */
    public static array $post_terms = [];
    /** @var array<int, list<int>> term id => ancestor term ids */
    public static array $ancestors = [];
    /** @var array<int, int> term id => tt id */
    public static array $term_tt = [];
    /**
     * Terms WP_Tax_Query::transform_query() and get_term_children() see.
     *
     * @var list<array{id: int, tt: int, tax: string, slug: string, parent: int}>
     */
    public static array $tax_terms = [];
    public static int $blog_id = 1;
    /** @var list<int> */
    public static array $blog_stack = [];
    /** @var list<int> */
    public static array $sites = [1];
    public static bool $ext_cache = false;
    public static bool $fail_transient_write = false;
    public static bool $terms_error = false;
    public static int $db_queries = 0;
    public static int $transient_reads = 0;
    /** @var list<array{0: list<int>, 1: bool, 2: bool}> */
    public static array $primed = [];
    public static mixed $queried_object = null;
    /** @var list<string> */
    public static array $cli_commands = [];

    public static function reset(): void
    {
        self::$filters = [];
        self::$did = [];
        self::$flags = [];
        self::$transients = [];
        self::$transient_expiry = [];
        self::$cache = [];
        self::$posts = [];
        self::$post_terms = [];
        self::$ancestors = [];
        self::$term_tt = [];
        self::$tax_terms = self::default_terms();
        self::$blog_id = 1;
        self::$blog_stack = [];
        self::$sites = [1];
        self::$ext_cache = false;
        self::$fail_transient_write = false;
        self::$terms_error = false;
        self::$db_queries = 0;
        self::$transient_reads = 0;
        self::$primed = [];
        self::$queried_object = null;
        self::$cli_commands = [];
        $_GET = [];
        $_POST = [];
        unset($GLOBALS['wp_the_query']);
    }

    /**
     * A small category tree (news > india > delhi, sport, opinion) and two tags.
     *
     * @return list<array{id: int, tt: int, tax: string, slug: string, parent: int}>
     */
    public static function default_terms(): array
    {
        return [
            ['id' => 1, 'tt' => 10, 'tax' => 'category', 'slug' => 'news', 'parent' => 0],
            ['id' => 2, 'tt' => 11, 'tax' => 'category', 'slug' => 'india', 'parent' => 1],
            ['id' => 3, 'tt' => 12, 'tax' => 'category', 'slug' => 'delhi', 'parent' => 2],
            ['id' => 4, 'tt' => 20, 'tax' => 'category', 'slug' => 'sport', 'parent' => 0],
            ['id' => 5, 'tt' => 30, 'tax' => 'category', 'slug' => 'opinion', 'parent' => 0],
            ['id' => 6, 'tt' => 40, 'tax' => 'post_tag', 'slug' => 'budget', 'parent' => 0],
            ['id' => 7, 'tt' => 41, 'tax' => 'post_tag', 'slug' => 'monsoon', 'parent' => 0],
        ];
    }

    public static function flag(string $name): bool
    {
        return self::$flags[$name] ?? false;
    }

    public static function add_post(int $id, string $status = 'publish', string $type = 'post', array $tt_ids = [], array $extra = []): WP_Post
    {
        $post = new WP_Post();
        $post->ID = $id;
        $post->post_status = $status;
        $post->post_type = $type;
        foreach ($extra as $k => $v) {
            $post->$k = $v;
        }
        self::$posts[$id] = $post;
        self::$post_terms[$id] = $tt_ids;
        return $post;
    }
}

final class WP_Post
{
    public int $ID = 0;
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_date = '2026-01-01 00:00:00';
    public string $post_date_gmt = '2026-01-01 00:00:00';
    public int $menu_order = 0;
    public int $post_parent = 0;
    public string $post_title = '';
}

final class WP_Error
{
}

/**
 * Fake tax query. Like core, `queries` holds the clauses as the caller
 * wrote them: core transforms a copy when it builds the SQL.
 */
final class WP_Tax_Query
{
    /**
     * Core's transform_query() over WpStub::$tax_terms.
     *
     * @param array<mixed>|WP_Error $query
     */
    public function transform_query(&$query, $resulting_field): void
    {
        if (empty($query['terms']) || $query['field'] === $resulting_field) {
            return;
        }
        $terms = array_filter((array) $query['terms']);
        if ($terms === []) {
            $query['terms'] = [];
            $query['field'] = $resulting_field;
            return;
        }
        $key  = ['term_id' => 'id', 'term_taxonomy_id' => 'tt', 'slug' => 'slug', 'name' => 'slug'];
        $from = $key[$query['field']] ?? 'id';
        $list = [];
        foreach (WpStub::$tax_terms as $row) {
            if (($query['taxonomy'] === '' || $row['tax'] === $query['taxonomy']) && in_array($row[$from], $terms, false)) {
                $list[] = $row;
            }
        }
        if ($query['operator'] === 'AND' && count($list) < count($query['terms'])) {
            $query = new WP_Error();
            return;
        }
        $query['terms'] = array_map(static fn (array $row) => $row[$key[$resulting_field]], $list);
        $query['field'] = $resulting_field;
    }

    public string $relation = 'AND';

    /** @param array<mixed> $queries */
    public function __construct(public array $queries = [])
    {
        if (isset($queries['relation']) && is_string($queries['relation'])) {
            $this->relation = $queries['relation'];
        }
    }
}

class WP_Query
{
    /** @var array<string, mixed> */
    public array $query_vars = [];
    public $request = '';
    public $posts;
    public int $post_count = 0;
    public int $found_posts = 0;
    public $max_num_pages = 0;
    public $tax_query = null;
    public bool $is_tax = false;
    public bool $is_page = false;
    public bool $is_attachment = false;
    public bool $main = false;
    /** @var list<int> What the "database" returns, in order. */
    public array $db_ids = [];
    /** @var int|null Rows without LIMIT; defaults to count($db_ids). */
    public ?int $db_found = null;
    /** Database queries this object ran. */
    public int $queries_run = 0;

    /**
     * @param array<string, mixed> $vars
     * @param list<int>            $db_ids
     */
    public function __construct(array $vars = [], string $sql = "SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND wp_posts.post_type = 'post' AND wp_posts.post_status = 'publish' ORDER BY wp_posts.post_date DESC LIMIT 0, 10", array $db_ids = [], ?int $db_found = null)
    {
        $this->query_vars = $vars;
        $this->request = $sql;
        $this->db_ids = $db_ids;
        $this->db_found = $db_found;
    }

    public function get($var, $default = '')
    {
        return array_key_exists($var, $this->query_vars) ? $this->query_vars[$var] : $default;
    }

    public function set($var, $value): void
    {
        $this->query_vars[$var] = $value;
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }

    /** Like WP_Query::query(): reset, then run. */
    public function query(): array
    {
        $this->posts = null;
        $this->post_count = 0;
        $this->found_posts = 0;
        $this->max_num_pages = 0;
        return $this->get_posts();
    }

    /** The tail of WP_Query::get_posts() in WordPress 6.4. */
    public function get_posts(): array
    {
        $q = $this->query_vars + [
            'fields' => '',
            'no_found_rows' => false,
            'suppress_filters' => false,
            'cache_results' => true,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'posts_per_page' => 10,
        ];
        $limits = (int) $q['posts_per_page'] > 0 ? 'LIMIT' : '';

        $this->posts = apply_filters_ref_array('posts_pre_query', [null, &$this]);

        if ('ids' === $q['fields']) {
            if (null === $this->posts) {
                $this->posts = $this->db();
            }
            $this->posts = array_map('intval', $this->posts);
            $this->post_count = count($this->posts);
            $this->set_found_posts($q, $limits);
            return $this->posts;
        }

        if (null === $this->posts) {
            $this->posts = $this->db();
            $this->set_found_posts($q, $limits);
        }

        if ($this->posts) {
            $this->posts = array_values(array_filter(array_map('get_post', $this->posts)));
        }

        if (! $q['suppress_filters']) {
            $this->posts = apply_filters_ref_array('posts_results', [$this->posts, &$this]);
        }
        if (! $q['suppress_filters']) {
            $this->posts = apply_filters_ref_array('the_posts', [$this->posts, &$this]);
        }
        if ($this->posts) {
            $this->post_count = count($this->posts);
        } else {
            $this->post_count = 0;
            $this->posts = [];
        }
        return $this->posts;
    }

    /** @return list<int> */
    private function db(): array
    {
        $this->queries_run++;
        WpStub::$db_queries++;
        return $this->db_ids;
    }

    private function set_found_posts(array $q, string $limits): void
    {
        if ($q['no_found_rows'] || (is_array($this->posts) && ! $this->posts)) {
            return;
        }
        if (! empty($limits)) {
            $found_query = apply_filters_ref_array('found_posts_query', ['SELECT FOUND_ROWS()', &$this]);
            WpStub::$db_queries++;
            if (preg_match('/^SELECT (\d+)$/', (string) $found_query, $m) === 1) {
                $this->found_posts = (int) $m[1];
            } else {
                // FOUND_ROWS() after a short-circuit reads a stale value; model it as 999.
                $this->found_posts = $this->queries_run > 0 ? ($this->db_found ?? count($this->db_ids)) : 999;
            }
        } else {
            $this->found_posts = is_array($this->posts) ? count($this->posts) : 0;
        }
        $this->found_posts = (int) apply_filters_ref_array('found_posts', [$this->found_posts, &$this]);
        if (! empty($limits)) {
            $this->max_num_pages = (int) ceil($this->found_posts / $q['posts_per_page']);
        }
    }
}

final class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];

    public static function add_command(string $name, object $handler): void
    {
        WpStub::$cli_commands[] = $name;
    }

    public static function line(string $line): void
    {
        self::$lines[] = $line;
    }
}

// Hooks.
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = [$cb, $args];
    return true;
}
function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}
function has_filter(string $hook): bool
{
    return ! empty(WpStub::$filters[$hook]);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return apply_filters_ref_array($hook, array_merge([$value], $args));
}
function apply_filters_ref_array(string $hook, array $args): mixed
{
    WpStub::$did[$hook] = (WpStub::$did[$hook] ?? 0) + 1;
    if (empty(WpStub::$filters[$hook])) {
        return $args[0] ?? null;
    }
    $prios = WpStub::$filters[$hook];
    ksort($prios);
    foreach ($prios as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $args[0] = $cb(...array_slice($args, 0, max(0, $n)));
        }
    }
    return $args[0];
}
function do_action(string $hook, mixed ...$args): void
{
    WpStub::$did[$hook] = (WpStub::$did[$hook] ?? 0) + 1;
    if (empty(WpStub::$filters[$hook])) {
        return;
    }
    $prios = WpStub::$filters[$hook];
    ksort($prios);
    foreach ($prios as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $cb(...array_slice($args, 0, max(0, $n)));
        }
    }
}
function did_action(string $hook): int
{
    return WpStub::$did[$hook] ?? 0;
}

function __return_false(): bool
{
    return false;
}
function __return_true(): bool
{
    return true;
}

// Request state.
function is_admin(): bool { return WpStub::flag('admin'); }
function wp_doing_ajax(): bool { return WpStub::flag('ajax'); }
function wp_doing_cron(): bool { return WpStub::flag('cron'); }
function wp_is_serving_rest_request(): bool { return WpStub::flag('rest'); }
function is_user_logged_in(): bool { return WpStub::flag('logged_in'); }
function is_preview(): bool { return WpStub::flag('preview'); }
function is_customize_preview(): bool { return WpStub::flag('customize'); }
function is_embed(): bool { return WpStub::flag('embed'); }
function is_search(): bool { return WpStub::flag('search'); }
function is_front_page(): bool { return WpStub::flag('front_page'); }
function is_home(): bool { return WpStub::flag('home'); }
function is_archive(): bool { return WpStub::flag('archive'); }
function is_singular(): bool { return WpStub::flag('singular'); }
function is_category(): bool { return WpStub::flag('category'); }
function is_tag(): bool { return WpStub::flag('tag'); }
function is_author(): bool { return WpStub::flag('author'); }
function is_date(): bool { return WpStub::flag('date'); }
function get_queried_object(): mixed { return WpStub::$queried_object; }

// Multisite.
function is_multisite(): bool { return count(WpStub::$sites) > 1; }
function get_current_blog_id(): int { return WpStub::$blog_id; }
function ms_is_switched(): bool { return WpStub::$blog_stack !== []; }
function get_sites(array $args = []): array { return WpStub::$sites; }
function switch_to_blog(int $id): bool
{
    WpStub::$blog_stack[] = WpStub::$blog_id;
    WpStub::$blog_id = $id;
    return true;
}
function restore_current_blog(): bool
{
    WpStub::$blog_id = array_pop(WpStub::$blog_stack) ?? 1;
    return true;
}

// Transients and the object cache.
function get_transient(string $name): mixed
{
    WpStub::$db_queries++;
    WpStub::$transient_reads++;
    return WpStub::$transients[WpStub::$blog_id][$name] ?? false;
}
function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    if (WpStub::$fail_transient_write) {
        return false;
    }
    WpStub::$db_queries++;
    WpStub::$transients[WpStub::$blog_id][$name] = $value;
    WpStub::$transient_expiry[WpStub::$blog_id][$name] = $expiration;
    return true;
}
function delete_transient(string $name): bool
{
    $had = isset(WpStub::$transients[WpStub::$blog_id][$name]);
    unset(WpStub::$transients[WpStub::$blog_id][$name], WpStub::$transient_expiry[WpStub::$blog_id][$name]);
    return $had;
}
function wp_using_ext_object_cache(): bool { return WpStub::$ext_cache; }
function wp_cache_get($key, $group = '', $force = false, &$found = null): mixed
{
    $found = isset(WpStub::$cache[$group]) && array_key_exists((string) $key, WpStub::$cache[$group]);
    return $found ? WpStub::$cache[$group][(string) $key] : false;
}
function wp_cache_get_multiple(array $keys, $group = '', $force = false): array
{
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = wp_cache_get($key, $group);
    }
    return $out;
}
function wp_cache_set($key, $value, $group = '', $expire = 0): bool
{
    WpStub::$cache[$group][(string) $key] = $value;
    return true;
}
function wp_cache_add($key, $value, $group = '', $expire = 0): bool
{
    if (isset(WpStub::$cache[$group]) && array_key_exists((string) $key, WpStub::$cache[$group])) {
        return false;
    }
    return wp_cache_set($key, $value, $group, $expire);
}
function wp_cache_delete($key, $group = ''): bool
{
    unset(WpStub::$cache[$group][(string) $key]);
    return true;
}
function wp_cache_incr($key, $offset = 1, $group = ''): int|false
{
    $v = wp_cache_get($key, $group);
    if (! is_int($v)) {
        return false;
    }
    WpStub::$cache[$group][(string) $key] = $v + $offset;
    return $v + $offset;
}
function wp_cache_supports(string $feature): bool { return $feature === 'flush_group'; }
function wp_cache_flush_group(string $group): bool
{
    unset(WpStub::$cache[$group]);
    return true;
}

// Posts and terms.
function _prime_post_caches(array $ids, bool $update_term_cache = true, bool $update_meta_cache = true): void
{
    WpStub::$primed[] = [$ids, $update_term_cache, $update_meta_cache];
    WpStub::$db_queries++;
    foreach ($ids as $id) {
        if (isset(WpStub::$posts[$id])) {
            WpStub::$cache['posts'][(string) $id] = WpStub::$posts[$id];
        }
    }
}
function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return WpStub::$posts[(int) $post] ?? null;
}
function get_object_taxonomies(string $type): array
{
    return ['category', 'post_tag'];
}
function wp_get_object_terms(int $id, array $taxonomies, array $args = []): array|WP_Error
{
    if (WpStub::$terms_error) {
        return new WP_Error();
    }
    return WpStub::$post_terms[$id] ?? [];
}
function taxonomy_exists(string $taxonomy): bool
{
    return in_array($taxonomy, ['category', 'post_tag'], true);
}
function is_taxonomy_hierarchical(string $taxonomy): bool
{
    return $taxonomy === 'category';
}
/** @return list<int> */
function get_term_children(int $term_id, string $taxonomy): array
{
    $out = [];
    foreach (WpStub::$tax_terms as $row) {
        if ($row['tax'] === $taxonomy && $row['parent'] === $term_id) {
            $out[] = $row['id'];
            $out   = array_merge($out, get_term_children($row['id'], $taxonomy));
        }
    }
    return $out;
}
/** @return array<int> */
function wp_parse_id_list(mixed $list): array
{
    $list = is_array($list) ? $list : preg_split('/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY);
    return array_unique(array_map(static fn ($v): int => abs((int) $v), (array) $list));
}
function get_ancestors(int $id, string $object_type = '', string $resource_type = ''): array
{
    return WpStub::$ancestors[$id] ?? [];
}
function get_term(int $id, string $taxonomy = ''): ?object
{
    if (! isset(WpStub::$term_tt[$id])) {
        return null;
    }
    return (object) ['term_id' => $id, 'term_taxonomy_id' => WpStub::$term_tt[$id]];
}
