<?php

/**
 * Example site configuration. Put this in a theme's functions.php, a small
 * plugin, or a must-use plugin. It runs on every request.
 */

declare(strict_types=1);

use IndianOpinion\QueryIdCache\InvalidSignature;

use function IndianOpinion\QueryIdCache\register_signature;

add_action('init', static function (): void {
    if (! function_exists('IndianOpinion\\QueryIdCache\\register_signature')) {
        return; // Plugin not active.
    }

    try {
        // Section landing pages: category, tag and date archives.
        register_signature('sections', [
            'context'    => 'archive',
            'post_types' => ['post'],
        ]);

        // A "most viewed" sidebar block. `auto` puts a meta-value order in
        // the TTL class, so it lives out ten minutes and is never dropped
        // by a publish.
        register_signature('most-viewed', [
            'context'     => 'any',
            'ttl'         => 600,
            'opt_in_only' => true,
        ]);

        // Only the big card grids on the front page, not every small list.
        register_signature('front-grids', [
            'context' => 'front_page',
            'match'   => static fn (WP_Query $q): bool => (int) $q->get('posts_per_page') >= 6,
        ]);
    } catch (InvalidSignature $e) {
        error_log('query-id-cache config: ' . $e->getMessage());
    }
}, 20);

// A widget query that opts in to the "most-viewed" signature by name.
// Note `suppress_filters => false`: get_posts() suppresses filters by
// default, and such queries are never cached.
function example_most_viewed(): WP_Query
{
    return new WP_Query([
        'posts_per_page'   => 5,
        'meta_key'         => 'views',
        'orderby'          => 'meta_value_num',
        'no_found_rows'    => true,
        'suppress_filters' => false,
        'query_id_cache'   => 'most-viewed',
    ]);
}

// Keep one query out of the cache no matter what matches it.
function example_never_cached(): WP_Query
{
    return new WP_Query([
        'posts_per_page' => 3,
        'query_id_cache' => false,
    ]);
}
