<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Removes everything the plugin created: the transient (with its timeout
 * row) on every site, and the object cache group where the cache supports
 * group flushing, otherwise a generation bump. The plugin creates no
 * options, tables, files or cron events.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite() && function_exists('get_sites')) {
            $ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($ids) ? $ids : [] as $blog_id) {
                switch_to_blog((int) $blog_id);
                delete_transient(TransientStore::TRANSIENT);
                restore_current_blog();
            }
        } else {
            delete_transient(TransientStore::TRANSIENT);
        }

        if (wp_using_ext_object_cache()) {
            if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
                wp_cache_flush_group(ObjectCacheStore::GROUP);
            } else {
                (new ObjectCacheStore(new Logger()))->bump_generation();
            }
        }
    }
}
