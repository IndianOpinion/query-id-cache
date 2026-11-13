<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Wires everything to WordPress. One instance per request.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    public readonly Logger $logger;

    public readonly Registry $registry;

    public readonly RequestGate $gate;

    public readonly QueryInspector $inspector;

    public readonly Cache $cache;

    public readonly Invalidator $invalidator;

    private ?Store $store = null;

    private bool $hooked = false;

    public function __construct(?Logger $logger = null)
    {
        $this->logger      = $logger ?? new Logger();
        $this->registry    = new Registry($this->logger);
        $this->gate        = new RequestGate();
        $this->inspector   = new QueryInspector($this->registry, $this->logger);
        $factory           = fn (): Store => $this->store();
        $this->cache       = new Cache($this->gate, $this->inspector, $this->logger, $factory);
        $this->invalidator = new Invalidator($this->registry, $this->logger, $factory);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace the shared instance. Tests only.
     */
    public static function reset(?self $plugin = null): void
    {
        self::$instance = $plugin;
    }

    /**
     * Register every hook, unless QUERY_ID_CACHE_DISABLE is true.
     */
    public function boot(): void
    {
        if ($this->hooked || (defined('QUERY_ID_CACHE_DISABLE') && constant('QUERY_ID_CACHE_DISABLE'))) {
            return;
        }
        $this->hooked = true;

        add_action('init', [$this->registry, 'register_default'], 1, 0);

        // Serving and recording.
        add_filter('posts_pre_query', [$this->cache, 'pre_query'], 20, 2);
        add_filter('posts_pre_query', [$this->cache, 'pre_query_settled'], PHP_INT_MAX, 2);
        add_filter('posts_results', [$this->cache, 'snapshot'], PHP_INT_MIN, 2);
        add_filter('the_posts', [$this->cache, 'record'], PHP_INT_MAX, 2);
        add_filter('found_posts_query', [$this->cache, 'found_posts_query'], PHP_INT_MAX, 2);
        add_filter('found_posts', [$this->cache, 'found_posts'], PHP_INT_MAX, 2);
        add_action('shutdown', [$this->cache, 'shutdown'], 0, 0);

        // Invalidation, in every context.
        add_action('transition_post_status', [$this->invalidator, 'on_transition'], 10, 3);
        add_action('post_updated', [$this->invalidator, 'on_updated'], 10, 3);
        add_action('set_object_terms', [$this->invalidator, 'on_set_terms'], 10, 6);
        add_action('before_delete_post', [$this->invalidator, 'on_before_delete'], 10, 2);
        add_action('deleted_post', [$this->invalidator, 'on_deleted'], 10, 2);
        add_action('created_term', [$this->invalidator, 'on_created_term'], 10, 3);
        add_action('edit_term', [$this->invalidator, 'on_edit_term'], 10, 3);
        add_action('edited_term', [$this->invalidator, 'on_edited_term'], 10, 3);
        add_action('delete_term', [$this->invalidator, 'on_delete_term'], 10, 5);
        add_action('split_shared_term', [$this->invalidator, 'on_split_term'], 10, 4);
        add_action('add_option_sticky_posts', [$this->invalidator, 'on_sticky'], 10, 0);
        add_action('update_option_sticky_posts', [$this->invalidator, 'on_sticky'], 10, 0);

        // Observability.
        $header = new DebugHeader($this->gate, $this->cache);
        add_action('template_redirect', [$header, 'start'], PHP_INT_MAX, 0);

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('query-id-cache', new Cli($this));
        }
    }

    /**
     * The store for this request: the `query_id_cache_store` filter, else
     * the object cache store when WordPress reports an external object
     * cache, else the transient store.
     */
    public function store(): Store
    {
        if ($this->store !== null) {
            return $this->store;
        }
        /** @var mixed $custom */
        $custom = apply_filters('query_id_cache_store', null);
        if ($custom instanceof Store) {
            return $this->store = $custom;
        }
        if ($custom !== null) {
            $this->logger->error('store-filter', 'the query_id_cache_store filter returned something that is not a Store; using the default.');
        }
        $expiry = 2 * Config::max_ttl($this->registry);
        if (wp_using_ext_object_cache()) {
            return $this->store = new ObjectCacheStore($this->logger, Config::max_entries(), $expiry);
        }
        return $this->store = new TransientStore($this->logger, Config::max_entries(), Config::max_bytes(), $expiry);
    }

    /**
     * Drop every entry and bump the generation (case 43).
     */
    public function flush(): void
    {
        $this->store()->delete_all();
        do_action('query_id_cache_flushed');
    }
}
