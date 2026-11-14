=== Query ID Cache ===
Contributors: indianopinion
Tags: performance, cache, wp_query, front page, database
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Caches the post ID lists and found-rows counts of slow secondary queries for anonymous visitors, and drops them when a publish can change them.

== Description ==

Front pages built from many card lists and widgets run dozens of secondary queries. The slow part is finding which post IDs belong in each list (ordering every post by a meta value, or counting a large category). Without a persistent object cache, every page-cache miss pays for every one of those queries again.

Query ID Cache remembers, for a few minutes, the ordered ID list and the count of each eligible query, and hands them back on the next anonymous request. Posts still load fresh, so edits to titles or images need no invalidation. Lists ordered by date are dropped the moment a publish, unpublish, delete or term change can alter them; popularity lists live out their time to live.

* Anonymous front-end requests only. Never signed-in users, previews, admin, REST, cron or WP-CLI.
* Only published posts. Never random order, author lists, searches or post__in lists.
* One database row per request without an object cache; per-entry items with Redis or Memcached.
* Configuration is code: signatures, constants and filters. No settings screen.
* WP-CLI: `wp query-id-cache status`, `list` and `flush`.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin to `wp-content/plugins/query-id-cache/` and activate it.
2. Purge your page cache and load the front page twice while signed out.
3. Optional: register more signatures from a theme or plugin, for example `IndianOpinion\QueryIdCache\register_signature( 'sections', [ 'context' => 'archive' ] );` on `init`.

To use it as a must-use plugin, keep it in `wp-content/plugins/query-id-cache/` and copy `examples/mu-plugin-loader.php` into `wp-content/mu-plugins/`.

== Frequently Asked Questions ==

= Can it show a draft or private post? =

No. Only queries for published posts are cached, entries hold IDs only, and every hit re-checks each post's status in memory.

= Why is my widget not cached? =

Lists built with get_posts() suppress filters by default and are never cached; pass 'suppress_filters' => false. Random order, author constraints, searches and post__in lists are never cached either.

= Does it need an object cache? =

No. It is designed for sites without one, and uses one when present.

== Changelog ==

= 0.1.0 =
* First release.
