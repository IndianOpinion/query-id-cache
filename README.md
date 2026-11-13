# query-id-cache

A WordPress plugin that remembers, for a few minutes, which post IDs each slow secondary `WP_Query` returned and how many rows it counted, and hands them back on the next anonymous request so the expensive ID lookup and count are skipped. Posts still load fresh by primary key, and "latest" lists are dropped the moment a publish, unpublish, delete or term change can alter them.

Measured on a production news front page built from many card-list widgets: a cache-miss render took 2.2 to 2.5 s against 0.6 to 0.7 s for an article page, with 639 queries and the slowest (ordering every published post by a view-count meta value) at 164 to 169 ms each. This plugin removes those ID and count queries on repeat misses.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [What is never cached](#what-is-never-cached)
- [Invalidation](#invalidation)
- [WP-CLI](#wp-cli)
- [Observability](#observability)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later. No runtime dependencies.

**Without Composer.** Download the release, unpack it to `wp-content/plugins/query-id-cache/` and activate it. The plugin carries its own autoloader.

**With Composer.**

```sh
composer require indianopinion/query-id-cache
```

The package type is `wordpress-plugin`, so `composer/installers` places it in your plugins directory if your project uses it.

**As a must-use plugin.** Keep the plugin in `wp-content/plugins/query-id-cache/` (no need to activate it) and copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/`. It is one line:

```php
require_once WP_CONTENT_DIR . '/plugins/query-id-cache/query-id-cache.php';
```

Behaviour is identical in both forms. A must-use install has no deactivation hook, so run `wp query-id-cache flush` if you remove it.

## Quick start

Out of the box the plugin registers one signature, `front-page`: secondary queries for posts, on the front page, with the freshness class worked out from the order. For many sites that is enough. Activate, purge your page cache, and load the front page twice while signed out.

To add section landing pages, put this where it runs on every request (a theme, a plugin or a must-use plugin):

```php
use function IndianOpinion\QueryIdCache\register_signature;

add_action('init', static function (): void {
    register_signature('sections', ['context' => 'archive']);
}, 20);
```

To opt one query in or out by name:

```php
new WP_Query(['posts_per_page' => 5, 'query_id_cache' => 'sections']); // use that signature
new WP_Query(['posts_per_page' => 5, 'query_id_cache' => false]);      // never cache this one
```

More in [`examples/`](examples).

## Configuration

Configuration is code: a registration function, constants and filters. There is no settings screen.

### Signatures

`register_signature( string $name, array $args = [] )` registers or replaces a signature. The name is a slug (lower case letters, digits, single hyphens). Invalid arguments throw `IndianOpinion\QueryIdCache\InvalidSignature` at registration time, never during a render.

| Argument | Default | Meaning |
|---|---|---|
| `context` | `front_page` | A callable returning bool, or a name: `front_page`, `home`, `archive`, `singular`, `category`, `tag`, `author`, `date`, `any`. Every name is false on a search results page. Add names with the `query_id_cache_contexts` filter. |
| `post_types` | `['post']` | Post types a query may read. A query reading any other type, or `any`, does not match. |
| `freshness` | `auto` | `fresh`, `ttl` or `auto`. See [How it works](#how-it-works). |
| `ttl` | global TTL (300) | Seconds. Zero or less disables the signature. |
| `match` | none | Callable receiving the `WP_Query` and returning bool. If it throws, the signature is off for the rest of that request and the error is logged once. |
| `opt_in_only` | `false` | Only queries naming this signature through `query_id_cache` match. |

When several signatures match, the first registered wins. A query that names a signature uses that one (its context and post types must still hold; its `match` callback is skipped).

Other functions, all in the `IndianOpinion\QueryIdCache` namespace: `unregister_signature( $name )` (works for `front-page` too), `get_signatures()`, `flush()`.

### Constants

| Constant | Effect |
|---|---|
| `QUERY_ID_CACHE_DISABLE` | When true, the plugin registers no hooks at all. |
| `QUERY_ID_CACHE_TTL` | Global time to live in seconds. The `query_id_cache_ttl` filter still runs after it. |
| `QUERY_ID_CACHE_DEBUG` | Enables the debug header and one log line per invalidation. |

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `query_id_cache_enabled` | `true` | Master switch per request. |
| `query_id_cache_request_eligible` | computed | Final say on request eligibility. |
| `query_id_cache_query_eligible` | `true` | Final say per query. Receives the query and the signature name. |
| `query_id_cache_signatures` | registered list | Name => argument array. Add, remove or edit signatures. Invalid ones are skipped and logged. |
| `query_id_cache_contexts` | built-in names | Name => callable. Add or replace named contexts. |
| `query_id_cache_ttl` | `300` | Global time to live in seconds. |
| `query_id_cache_freshness` | computed | Override the class of one query (`fresh` or `ttl`). Receives the query and signature name. |
| `query_id_cache_max_entries` | `300` | Entry cap. Oldest go first. |
| `query_id_cache_max_bytes` | `524288` | Size cap for the single-blob store. |
| `query_id_cache_store` | auto | Return a `Store` implementation to replace the default. |
| `query_id_cache_debug_header` | `false` | Emit the debug header. |

### Actions

| Action | Arguments | When |
|---|---|---|
| `query_id_cache_hit` | key, signature, ID count | An entry was served. |
| `query_id_cache_miss` | key, signature | An eligible query ran for real. |
| `query_id_cache_stored` | entries written | The shutdown write succeeded. |
| `query_id_cache_invalidated` | reason, entries dropped | An invalidation dropped entries. |
| `query_id_cache_flushed` | none | Manual flush. |

## How it works

1. **Is this request eligible?** Only anonymous front-end requests: not admin, AJAX, cron, REST, WP-CLI or XML-RPC, not signed in, not a preview or Customizer request, not an embed of a draft. Conditional tags only work after the main query has run, so before that the answer is "not yet", never a remembered "no".
2. **Is this query eligible?** Secondary queries only, matched to a signature on their parsed variables. A query another plugin already answered through `posts_pre_query` is left alone.
3. **Key.** A hash of the final SQL WordPress is about to run (after every query filter), the `fields` mode, the signature, the blog ID and the data-format version. Each page of a list is its own entry.
4. **Hit.** The plugin returns the stored IDs from `posts_pre_query` (priority 20), sets `found_posts` and `max_num_pages`, and primes the post cache in one query. It then drops any ID that is no longer published, using only what is in memory, and deletes the entry if it did.
5. **Miss.** The real query runs. The plugin takes the IDs in raw database order at `posts_results` (earliest priority) and records them with the final count at `the_posts` (latest priority). `fields => ids` queries, which skip those filters, are recorded at `found_posts` or at shutdown.
6. **Write.** New entries are buffered and written once, at shutdown. The write re-reads the store, drops expired entries, merges, applies the caps and saves. If an invalidation ran during the request, the new entries are discarded instead.

**Freshness classes.** `fresh` lists (ordered by date, modified date, ID, or not ordered) are dropped by any publish change that could alter them. `ttl` lists (meta value, comment count, title, menu order) live out their time to live: popularity lists may lag by design. `auto` picks by the order.

**Term scope.** Read from the parsed tax query after WordPress expanded child terms: the term taxonomy IDs of `IN` and `AND` clauses. A list with no term filter, or only an exclusion (`NOT IN`, `NOT EXISTS`, `category__not_in`), reads everything.

**Storage.** Without a persistent object cache, one transient holds a single blob: one database read per request, not one per query. Its expiry is twice the largest time to live and each entry's age is checked on read. With a persistent object cache (Redis, Memcached), each entry is its own cache item under a generation number; any invalidation bumps the generation and orphans every entry at once.

## What is never cached

- The main query of a request.
- Queries with `suppress_filters` set. WordPress still runs `posts_pre_query` for them, but not the result filters the plugin records from, so the plugin skips them explicitly. Note that `get_posts()` sets `suppress_filters => true` by default: pass `'suppress_filters' => false` if you want such a list cached.
- Random order (`orderby => rand`, or `RAND(` in the SQL).
- Any author constraint (`author`, `author_name`, `author__in`, `author__not_in`, or `post_author` in the SQL).
- Any status other than exactly `publish`, and any `perm` setting.
- Searches (`s` not empty), and named contexts on search results pages.
- `post__in` lists: the caller already has the IDs.
- `fields => id=>parent`.
- Post type `any`, or an empty post type on a custom taxonomy query.
- Anything inside `switch_to_blog()`.

## Invalidation

Invalidation runs in every context (admin, REST, cron, WP-CLI, AJAX, imports), because scheduled posts publish from cron and editors publish through REST. Serving is front-end only.

| Event | Hook | Drops |
|---|---|---|
| Publish (including scheduled) | `transition_post_status` | Fresh entries of that type whose scope meets the post's terms, or that read everything |
| Unpublish, trash | `transition_post_status` | The same, plus every entry containing the post |
| Date, menu order, type or parent change | `post_updated` | Fresh entries in scope. Title, content and excerpt edits drop nothing |
| Permanent delete | `before_delete_post` reads status and terms, `deleted_post` acts | As for an unpublish |
| Terms change on a published post | `set_object_terms` (old and new term taxonomy IDs) | Fresh entries meeting either set or reading everything, plus entries in scope containing the post |
| Term edit, delete or split | `edit_term`, `edited_term`, `delete_term`, `split_shared_term` | Every entry whose scope holds the term (or, for an edit, its old or new ancestors) |
| Sticky list change | `add_option_sticky_posts`, `update_option_sticky_posts` | Everything |
| Manual | `flush()`, `wp query-id-cache flush` | Everything, and bumps the generation |

Post types no signature covers are ignored. View counters and new comments never invalidate. An invalidation that drops nothing writes nothing.

## WP-CLI

```sh
wp query-id-cache status                      # store, entries, generation, size, ages, per signature
wp query-id-cache list [--signature=<name>]   # key, signature, class, age, IDs, found, scope
wp query-id-cache flush                       # drop everything, bump the generation
```

`status` flags a signature as "keys never repeat" when it has stored 50 or more entries and never written the same key twice. That usually means a relative date ("after one week ago") or another per-request value in its SQL, so its entries can never be hit.

## Observability

- **Debug header.** With `query_id_cache_debug_header` (or `QUERY_ID_CACHE_DEBUG`), eligible anonymous responses carry `X-Query-Id-Cache: hits=<n>; misses=<n>; stored=<n>`. Counts only, never keys, SQL or IDs. The plugin buffers output on those requests to add the header after rendering.
- **Actions.** Every action above fires, so a few lines of code can count hits and misses. See [`examples/monitoring.php`](examples/monitoring.php).
- **Logs.** Failures go to `error_log` with the prefix `query-id-cache:`, at most once per kind per request. `QUERY_ID_CACHE_DEBUG` adds one line per invalidation with its reason and count.
- **By hand.** Purge the page cache, load the page twice while signed out, and compare the query count or render time. `wp query-id-cache status` should show entries for the signature.

## Limitations

- The cache serves anonymous requests only. Signed-in users always get the real queries.
- With an object cache, any invalidation that matches orphans every entry, not only the matching ones. Entries are cheap to rebuild there.
- A `fields => ids` query with `no_found_rows` that reuses its query object for a second run loses the first run's recording (WordPress fires no hook it could be read from). The page is still correct.
- Popularity lists lag by up to their time to live.
- Hits record nothing, so "keys never repeat" is measured from writes, not from hits.
- On a hit for `fields => ids`, WordPress still issues one trivial `SELECT <count>` for the found-rows step; the plugin hands it the stored count so no stale `FOUND_ROWS()` is read.

## FAQ

**Will it ever show a draft or private post?** Only queries for status `publish` are cached, entries hold IDs only, and every hit re-checks the status of each ID in memory. Signed-in users and previews are never served.

**Does it cache post content?** No. Posts are loaded fresh through the normal WordPress caches on every hit, so editing a title or image needs no invalidation.

**Why is my widget not cached?** Check, in order: is it the main query, does it use `get_posts()` (which suppresses filters), random order, an author, a search, `post__in`, or a post type the signature does not list? Is the signature's context true on that page? `QUERY_ID_CACHE_DEBUG` and the `query_id_cache_miss` action help.

**Does it work with a full-page cache or CDN?** Yes, that is the setting it was built for: it speeds up page-cache misses.

**Another plugin also uses `posts_pre_query`.** This plugin runs at priority 20 and steps aside whenever a value is already set.

## Licence

MIT. See [LICENSE](LICENSE).
