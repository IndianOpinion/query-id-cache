# query-id-cache: product requirements

Status: draft for build. Version target: 0.1.0.

This document is the whole specification. A builder should be able to write
the plugin from it without any other source. Names in section 5 are the
public API and are binding unless section 13 reopens them.

## 1. Problem

Some WordPress pages run dozens of secondary `WP_Query` calls to fill card
lists, widgets and blocks: "latest in category X", "most viewed",
"most commented". On a site with no persistent object cache, nothing keeps
the results of those queries between requests. Every page-cache miss pays
for every query again.

The expensive part is almost never loading the posts. It is finding which
post IDs belong in each list: ordering every published post by a meta value
or by comment count, or counting a large category for pagination. The posts
themselves load quickly by primary key.

Measured on a production news front page built from many card-list widgets: a
cache-miss render took 2.2 to 2.5 s against 0.6 to 0.7 s for an article
page, and almost all of it was SQL: 639 queries, the slowest ordering every
published post by a view-count meta value at 164 to 169 ms each.

Full-page caches hide this for most anonymous visitors, but every miss
(expiry, purge after a publish, a new query string, a crawler on a cold
edge) pays the full cost. On a news site the page cache is purged often, so
misses are common and slow.

## 2. Goals and non-goals

Goals:

1. Remember, for a short time, the ordered post ID list and the found-rows
   count of each eligible secondary query, and hand them back on the next
   matching request so the ID lookup and the count are skipped.
2. Let the site owner declare which queries are eligible, as a list of
   named query signatures, instead of hard-coding one page.
3. Never let a "latest" list lag a publish, unpublish, delete or term
   change that can alter it.
4. Serve cached lists to anonymous, non-preview front-end requests only.
5. Keep pagination fields (`found_posts`, `max_num_pages`) correct.
6. Work with database transients (no object cache) and with a persistent
   object cache, choosing the right storage shape for each.
7. Fail open: any doubt means the real query runs.

Non-goals:

- Caching whole pages or rendered HTML. A page cache does that.
- Caching the main query of a request.
- Caching post objects, meta or terms. Posts are loaded fresh on every hit
  through the normal WordPress caches.
- Caching for signed-in users, previews, the admin, AJAX, REST, cron or
  WP-CLI.
- Caching random-order lists or lists scoped to an author or to the
  current user.
- Keeping popularity lists (views, comment count) exactly current. They
  live out their time to live by design.
- A settings screen. Configuration is code: constants, filters and a
  registration function.

## 3. Users and setting

- Who installs it: a site operator or developer who has measured slow
  page-cache misses caused by repeated list queries. They can write a few
  lines of PHP in a theme, a plugin or a must-use plugin.
- Where it runs: a WordPress site, single site or multisite, usually behind
  a full-page cache (server cache or CDN). The plugin works without a page
  cache too, but its benefit is largest on cache misses.
- Install forms: a normal plugin, or a must-use plugin through a one-line
  loader file. Both must behave the same.
- Typical page: a front page or a section landing page built from many
  query-driven widgets, blocks or page-builder elements.

## 4. Behaviour

### 4.1 Terms used here

- **Signature**: a named rule that says which secondary queries, on which
  requests, may be cached, with what time to live and what freshness class.
- **Entry**: one cached result: the ordered ID list, the found-rows count,
  when it was stored, and what it depends on.
- **Store**: where entries live (section 6).
- **Fresh class**: lists that must change the moment a publish can change
  them (date-ordered or unordered lists).
- **TTL class**: lists that may stay stale until their time to live ends
  (popularity, comment count, title or menu order).

### 4.2 Request eligibility (may this request read or write entries?)

1. A request is eligible only when all of these hold: not the admin, not
   AJAX, not cron, not REST, not WP-CLI, not XML-RPC, the visitor is not
   signed in, the request is not a preview, not a Customizer preview, not
   an embed of a draft, the site is not switched to another blog
   (multisite), the plugin is enabled, and the eligibility filter returns
   true.
2. A request that carries a `preview` query variable, a `preview_id`, a
   `preview_nonce` or a Customizer changeset is never eligible, even when
   the visitor appears anonymous.
3. Eligibility needs conditional tags such as "is this the front page". These
   are only reliable once the main query has run. Before the main query has
   produced its posts, the plugin answers "not eligible for now" and asks
   again on the next query. It must not remember a negative answer made
   before the main query existed.
4. Once the main query has run, the answer for the request is computed once
   and memoised for the rest of the request.
5. A negative answer for a reason that cannot change during the request
   (admin, signed in, REST, cron and so on) may be memoised at once.
6. Signed-in requests are never served from the cache and never write to
   it. They bypass page caches, and their lists can include private or
   per-user posts.

### 4.3 Query eligibility (may this query use an entry?)

7. Only secondary queries are considered. The main query is never cached.
8. A query is skipped when another component has already answered it
   (the incoming value of the short-circuit filter is not null). The
   plugin steps aside and does not record anything.
9. A query is skipped when it asked for `suppress_filters`. WordPress does
   not run the filters for it, so the plugin never sees it; this needs no
   code but must be documented.
10. A query is eligible only when it matches at least one registered
    signature whose context predicate is true for this request. When
    several signatures match, the first in registration order wins and its
    settings apply.
11. Hard exclusions, regardless of any signature:
    - random ordering (`orderby` of `rand` or a `RAND(` in the SQL);
    - any author constraint (`author`, `author_name`, `author__in`,
      `author__not_in`, or the author column in the SQL);
    - any post status other than exactly `publish` (including `any`,
      `private`, `future`, `draft`, `inherit`, or a `perm` setting);
    - a search (`s` not empty);
    - `fields` of `id=>parent` (only full posts and `ids` are supported);
    - a query whose request SQL is empty or not a string.
12. A signature matches on the parsed query variables, not on SQL text:
    post types (default: `post` only), and an optional match callback that
    receives the query object and returns a boolean. A query may also opt
    in by name with the query variable `query_id_cache` set to a signature
    name, or opt out with that variable set to `false`. An opt-out always
    wins.
13. A query whose post type is `any` or a list that includes a type the
    signature does not allow is not eligible.

### 4.4 Keys

14. The key of an entry is a hash of: the final request SQL as WordPress
    is about to run it (after every query filter), the `fields` mode, the
    signature name, the blog ID and the plugin's data-format version.
    Using the final SQL means two queries with the same variables but
    different SQL filters never share an entry, and any change to a query
    produces a new key.
15. Each page of a paginated list has different SQL (a different `LIMIT`
    offset) and therefore its own entry.
16. Relative date queries ("after one week ago") produce SQL with a literal
    timestamp that changes every second. Such keys never repeat. The entry
    cap (case 33) stops them from filling the store, and the plugin must
    detect a signature whose keys never hit (section 11).

### 4.5 Serving a hit

17. On an eligible query, the plugin loads the store once per request
    (lazily, on the first eligible query) and looks up the key.
18. An entry is a hit when it exists, its shape is valid, and its age is
    below the signature's time to live. An entry stored in the future by
    more than 60 seconds (clock skew between web nodes) counts as stale.
19. On a hit the plugin:
    - returns the cached ID list from the short-circuit filter, as
      integers, in the stored order;
    - sets `found_posts` to the stored count;
    - sets `max_num_pages` to the count divided by `posts_per_page`,
      rounded up, or 1 when `posts_per_page` is zero or negative;
    - for `fields` of `all`, primes the post cache for those IDs in one
      query, honouring the query's `update_post_term_cache`,
      `update_post_meta_cache` and `cache_results` flags.
20. An empty cached list is a valid hit: it returns an empty list and
    primes nothing.
21. After priming, the plugin drops any ID whose post no longer exists or
    is no longer published, using only data already in memory. This is a
    safety net for a missed invalidation, not the main mechanism. When it
    drops an ID it also deletes the entry, so the next request rebuilds it.
22. The restored `found_posts` must reach the caller for every supported
    `fields` mode. If the WordPress version in use recomputes the count
    after the short-circuit (for example with a found-rows query), the
    plugin must reassert its value through the found-posts filter for that
    query object only.
23. On a hit, nothing is recorded for that query (no write at shutdown).

### 4.6 Recording a miss

24. On a miss the plugin lets the real query run and marks the query
    object as pending, keyed by the object's identity for this request.
25. When the query's results arrive, the plugin records the ordered IDs as
    the database returned them, before other plugins' result filters
    change them, so that on a later hit those filters see the same input
    they would have seen. Post objects are reduced to their IDs.
26. With `no_found_rows` set, the recorded count is the number of IDs.
    Otherwise it is the query's `found_posts` after WordPress computed it.
    If the count is not yet known when the results arrive, the plugin
    reads it when the query completes, before writing.
27. The entry records: time stored, IDs, count, freshness class, the term
    taxonomy IDs it reads (case 30), whether its term filter is an
    exclusion or reads everything, its post types, the signature name and
    the store generation seen at the start of the request.
28. New entries are buffered in memory and written once, at shutdown.
    Nothing is written during rendering.

### 4.7 Freshness class and term scope

29. The class comes from the signature: `fresh`, `ttl` or `auto` (the
    default). `auto` means: ordered by post date, by modified date, by ID,
    or with no explicit order (WordPress orders by date by default) is
    `fresh`; any other order (meta value, comment count, title, menu
    order, `post__in`) is `ttl`.
30. A list's term scope is the set of term taxonomy IDs its tax query
    includes with the `IN` or `AND` operators, after WordPress has
    expanded child terms. It is read from the parsed tax query on the
    query object, not from SQL text.
31. A list that has no term filter, or whose only term filter is an
    exclusion (`NOT IN`, `NOT EXISTS`, `category__not_in`, `tag__not_in`),
    reads everything: any publish change can alter it.
32. Meta queries do not narrow the term scope. A fresh list with a meta
    query is treated as reading everything in its term scope.

### 4.8 Writing at shutdown

33. At shutdown, if there are new entries, the plugin re-reads the store
    (another request may have written since this request loaded it),
    drops entries older than their time to live, merges in the new
    entries, enforces the entry cap by dropping the oldest entries first,
    and saves.
34. If the store's generation changed since this request first read it (an
    invalidation happened during this request), the new entries are
    discarded, not written. They may have been computed before the
    publish and would otherwise survive it.
35. If the serialised blob would exceed the size cap, the plugin drops the
    oldest entries until it fits, and logs once per request.

### 4.9 Invalidation

Invalidation runs in every context: admin, REST, cron (scheduled posts),
WP-CLI, AJAX and imports. Serving is front-end only; invalidation is
everywhere.

36. **Publish**: when a post of a type any signature covers moves into
    `publish` from any other status (including `future` through cron),
    every fresh entry for that post type whose term scope intersects the
    post's terms, or which reads everything, is dropped.
37. **Unpublish**: when such a post leaves `publish` (to draft, pending,
    private, future, trash), the same fresh entries are dropped, and every
    entry of any class whose ID list contains the post is dropped.
38. **Update while published**: when a published post is saved and stays
    published, fresh entries in its term scope are dropped if its post
    date, menu order, post type or parent changed. Title, content and
    excerpt edits drop nothing, because the IDs do not change and posts
    are loaded fresh.
39. **Delete**: when a post is deleted permanently without passing through
    the trash, the plugin reads its status and terms before deletion. If
    it was published, cases 36 and 37 apply as for an unpublish.
40. **Term assignment**: when the terms of a published post change, fresh
    entries whose scope intersects the old terms or the new terms, or
    which read everything, are dropped. Entries of any class whose scope
    was intersected and whose ID list contains the post are also dropped.
    This also catches editors and APIs that publish first and assign terms
    a moment later.
41. **Term edit, delete or merge**: when a term is edited, deleted or
    split, every entry whose scope contains that term, in either class, is
    dropped.
42. **Sticky posts**: when the sticky post list changes, every entry is
    dropped.
43. **Manual flush**: a public function and a WP-CLI command drop every
    entry and bump the generation.
44. Every invalidation that drops at least one entry bumps the store
    generation (case 34). An invalidation that finds an empty store does
    nothing and writes nothing.
45. Post types no signature covers are ignored by every invalidation hook.
46. Popularity changes (a view counter, a new comment) never invalidate.
    TTL-class lists catch up when their time to live ends.

### 4.10 Edge cases

47. Many queries on one page with identical SQL share one entry; the first
    miss records it, later identical queries in the same request still run
    (the entry is not written until shutdown) and record the same key
    once.
48. A query object reused for a second query: the pending marker is keyed
    by object identity and cleared when consumed, so the second run is
    treated on its own.
49. Sticky handling: WordPress only prepends sticky posts for the main
    home query, which is never cached. Secondary queries that emulate
    stickiness in a theme are covered by case 42.
50. An entry naming an ID that is now private or deleted is caught by
    case 21 even if an invalidation was missed.
51. A query run inside `switch_to_blog` is not served and not recorded
    (case 1).
52. A signature with a time to live of zero or less is disabled.
53. Two web nodes with a shared object cache: generation and clock skew
    rules (cases 18 and 34) apply.

### 4.11 Failure modes

All failures are fail open: the real query runs and the page is correct,
only slower. The single fail-closed rule is that the plugin never serves an
entry when it cannot be sure the request is anonymous and not a preview.

54. Store read fails, returns the wrong type, or is corrupt: treated as
    empty. One log line per request.
55. An entry with the wrong shape (missing fields, non-integer IDs): that
    entry is ignored and removed at the next write.
56. Store write fails: logged once; nothing else happens.
57. A signature callback throws: that signature is treated as not matching
    for the rest of the request, and the error is logged once.
58. The plugin's own code throws inside a query filter: the exception is
    caught, logged, and the incoming value is returned unchanged.
59. Unserialise of a blob written by an older data-format version: the
    blob is treated as empty and overwritten.

## 5. Public interface

Namespace: `IndianOpinion\QueryIdCache`. Text domain and slug:
`query-id-cache`. The hook and constant names below are the plugin's own and
use the `query_id_cache` prefix.

### 5.1 Registration

- `register_signature( string $name, array $args = [] ): void` registers or
  replaces a signature. `$name` is a slug (lower case, digits, hyphens).
  Arguments and defaults:
  - `context`: a callable taking no arguments and returning bool, or one of
    the strings `front_page`, `home`, `archive`, `singular`, `any`.
    Default `front_page`.
  - `post_types`: list of post type names. Default `['post']`.
  - `freshness`: `auto`, `fresh` or `ttl`. Default `auto`.
  - `ttl`: seconds. Default: the global time to live.
  - `match`: optional callable receiving the `WP_Query` and returning bool.
    Default: none (every query that passes the other rules matches).
  - `opt_in_only`: when true, only queries that name this signature
    through the `query_id_cache` query variable match. Default false.
- `unregister_signature( string $name ): void`.
- `get_signatures(): array` returns the registered signatures after the
  `query_id_cache_signatures` filter.
- `flush(): void` drops every entry and bumps the generation.
- A default signature named `front-page` is registered on `init` with all
  defaults. A site removes it with `unregister_signature( 'front-page' )`
  or the signatures filter.
- Invalid arguments raise a typed exception (`InvalidSignature`) at
  registration time, never later during a page render.

### 5.2 Query variable

- `query_id_cache`: a signature name (opt in to that signature) or `false`
  (opt out of all caching for this query).

### 5.3 Filters

| Filter | Default | Purpose |
|---|---|---|
| `query_id_cache_enabled` | true | Master switch per request |
| `query_id_cache_request_eligible` | computed | Final say on request eligibility (case 1) |
| `query_id_cache_query_eligible` | computed | Final say per query; receives the query and signature name |
| `query_id_cache_signatures` | registered list | Add, remove or edit signatures |
| `query_id_cache_ttl` | 300 | Global time to live in seconds |
| `query_id_cache_freshness` | computed | Override the class of one query |
| `query_id_cache_max_entries` | 300 | Entry cap |
| `query_id_cache_max_bytes` | 524288 | Serialised size cap for the single-blob store |
| `query_id_cache_store` | auto | Return a `Store` implementation to replace the default |
| `query_id_cache_debug_header` | false | Emit the debug header (section 11) |

### 5.4 Actions

| Action | Arguments | When |
|---|---|---|
| `query_id_cache_hit` | key, signature name, ID count | An entry was served |
| `query_id_cache_miss` | key, signature name | An eligible query ran for real |
| `query_id_cache_stored` | number of entries written | Shutdown write succeeded |
| `query_id_cache_invalidated` | reason, number dropped | Any invalidation dropped entries |
| `query_id_cache_flushed` | none | Manual flush |

### 5.5 Constants

- `QUERY_ID_CACHE_DISABLE`: when true, the plugin registers no hooks.
- `QUERY_ID_CACHE_TTL`: global time to live in seconds; the filter still
  runs after it.
- `QUERY_ID_CACHE_DEBUG`: when true, enables the debug header and verbose
  logging.

### 5.6 Store interface

`Store` with `load(): array`, `save( array $data ): bool`,
`delete_all(): void`, `generation(): int`, `bump_generation(): int`.
Two implementations ship: `TransientStore` (one blob) and
`ObjectCacheStore` (per entry). See section 6.

### 5.7 WP-CLI

- `wp query-id-cache status`: store type, entry count, generation, blob
  size, oldest and newest entry age, entries per signature.
- `wp query-id-cache list [--signature=<name>]`: key, signature, class,
  age, ID count, found count, term scope.
- `wp query-id-cache flush`: same as `flush()`.

## 6. Data

- **Without a persistent object cache** (`TransientStore`): one transient
  holds a blob with a format version, a generation number and a map of
  key to entry. Its expiry is twice the largest time to live, so the blob
  outlives its newest entry; each entry carries its own time and is
  checked on read. Transients with an expiry are not autoloaded, so the
  blob costs nothing on requests that never touch an eligible query.
- **With a persistent object cache** (`ObjectCacheStore`): each entry is
  its own cache item in a dedicated group, with its own expiry, plus one
  generation item. The generation number is part of every item key, so a
  bump orphans every old item at once without enumerating them. This
  avoids the read-merge-write race of a single blob. Eviction by the cache
  is harmless: it is a miss.
- The store is chosen automatically: object cache store when WordPress
  reports an external object cache, transient store otherwise. The
  `query_id_cache_store` filter can override it.
- An entry holds only post IDs, a count, a time, a class, term taxonomy
  IDs, post type names, a signature name and a generation. No content, no
  user data, no request data.
- Retention: entries expire after their time to live. The blob expires
  after twice the largest time to live.
- Deactivation: flush the store.
- Uninstall: delete the transient and its timeout row on every site of a
  multisite network, and flush the object cache group where the cache
  supports group flushing (otherwise bump the generation). The plugin
  creates no options, tables, files or cron events.

## 7. Configuration and sensible defaults

| Setting | Default | Why |
|---|---|---|
| Time to live | 300 s | Long enough to absorb a burst of page-cache misses, short enough that popularity lists stay believable |
| Default signature | `front-page`, posts only, auto class | The most common slow page; easy to remove |
| Entry cap | 300 | A heavy front page needs about 100; leaves room for a few landing pages |
| Size cap (blob store) | 512 KiB | Keeps the one database row small enough to read in well under a millisecond |
| Debug header | off | Not every operator wants extra headers |
| Store | auto | Object cache when present, transient otherwise |

A typical setup adds one signature for section landing pages:
context `archive`, post types `post`, auto class. Popularity widgets need no
special setting: `auto` puts them in the TTL class.

## 8. Security and privacy

- Never serve or store for signed-in users, previews, Customizer previews,
  or switched blogs. Doubt means no.
- Only published posts can be cached (case 11). Private, draft and future
  posts can never enter an entry, so an entry cannot leak them.
- Case 21 re-checks status on every hit as a second line of defence.
- No user input reaches a cache key except through the final SQL, which is
  hashed. Keys are never echoed.
- The debug header shows counts only, never keys, SQL or IDs, and is sent
  only on eligible anonymous requests.
- The WP-CLI commands are available only to WP-CLI. There is no admin
  screen, so no nonces or capabilities are needed; if one is added later
  it needs `manage_options` and nonces.
- No `eval`, no shell, no unserialise of data that the plugin did not
  write (stored values go through the WordPress cache API).

## 9. Performance budget

- Hit path: one store read per request (one database row read for the
  blob store, one cache get per eligible query for the object cache store),
  then an in-memory lookup per eligible query. Budget: under 2 ms total
  for 100 entries on the blob store.
- Each hit saves the ID query and the found-rows query. Posts are loaded
  with one primed query per list.
- Miss path overhead: under 0.1 ms per eligible query plus one write at
  shutdown.
- Ineligible requests (signed in, admin, REST): one memoised check, no
  store access.
- Invalidation: one store read and at most one write per hook firing; one
  terms lookup per post.
- No extra queries on requests with no eligible query.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later (posts are built from IDs on
  both query paths in these versions; the builder must confirm the exact
  short-circuit behaviour in the oldest supported version).
- Single site and multisite. Network activation stores per site.
- Persistent object caches (Redis, Memcached) through the WordPress cache
  API. Without one, database transients.
- Full-page caches and CDNs: compatible and expected. The plugin does not
  touch response caching except the optional debug header.
- Page builders and block themes: work through `WP_Query` as normal.
- Other plugins that answer the same short-circuit filter (search
  backends, alternative query engines): the plugin runs at priority 20 and
  steps aside if a value is already set.
- Must-use plugin: a one-line loader in `mu-plugins` that requires the main
  file. Behaviour identical.

## 11. Observability

- Debug header, when enabled: `X-Query-Id-Cache: hits=<n>; misses=<n>;
  stored=<n>` on eligible anonymous responses.
- Every action in section 5.4 fires, so an operator can count hits and
  misses with a few lines of code or a monitoring plugin.
- Logging goes through `error_log` with a fixed `query-id-cache:` prefix,
  only for failures (section 4.11), and at most once per failure kind per
  request. Verbose logging under `QUERY_ID_CACHE_DEBUG` adds one line per
  invalidation with reason and count.
- A signature whose entries are stored but never hit across many writes
  (for example 50 stores and zero hits) is reported by
  `wp query-id-cache status` as "keys never repeat", which usually means
  a relative date or a per-request value in its SQL (case 16).
- How an operator sees it work: purge the page cache, load the page twice
  anonymously, and compare the query count or render time; the second load
  should show the slow list queries gone. `status` should show entries for
  the signature.

## 12. Acceptance tests

Tests run under PHPUnit against a stub layer of WordPress functions and a
small fake `WP_Query`. Each test names the cases it covers.

1. Admin, AJAX, cron, REST, CLI and XML-RPC requests are not eligible (1).
2. A request with a preview variable, preview nonce or Customizer
   changeset is not eligible even when anonymous (2).
3. Before the main query has posts, eligibility returns false and is asked
   again; after it runs, the answer is true for an anonymous front page (3, 4).
4. A signed-in request neither reads nor writes the store (5, 6).
5. The main query is never served (7).
6. A non-null incoming short-circuit value is returned unchanged and
   nothing is recorded (8).
7. A query with no matching signature is untouched; with two matching
   signatures, the first registered wins (10).
8. Random order, an author constraint, a non-publish status, a search and
   `id=>parent` are each rejected (11).
9. A query opts in by name, and opt-out with `false` beats a matching
   signature (12).
10. A query including a post type outside the signature is rejected (13).
11. Two queries with the same variables but different final SQL get
    different keys; the key changes with blog ID and format version (14).
12. Pages 1 and 2 of one list are separate entries (15).
13. A signature with a timestamp in its SQL fills the store only up to the
    entry cap and is reported as "keys never repeat" (16, 33).
14. A fresh entry is served with IDs in order, `found_posts` and
    `max_num_pages` set; `posts_per_page` of -1 gives one page (17, 19).
15. An entry older than the time to live, or stored more than 60 s in the
    future, is a miss (18).
16. An empty cached list is served as an empty result without priming (20).
17. A cached ID that is now private is dropped from the result and the
    entry is deleted (21, 50).
18. `found_posts` survives for `fields` of `all` and `ids` (22).
19. A hit records nothing at shutdown (23).
20. A miss records the IDs as returned before a later result filter
    reorders them (24, 25).
21. With `no_found_rows`, the stored count equals the ID count (26).
22. The stored entry has every field in case 27.
23. Nothing is written before shutdown (28).
24. Auto class: date, modified, ID and unordered lists are fresh; meta,
    comment count, title and menu order lists are TTL (29).
25. Term scope comes from the parsed tax query, includes expanded child
    terms, and an exclusion-only filter reads everything (30, 31, 32).
26. Shutdown merges with entries another request wrote meanwhile and
    drops expired ones (33).
27. An invalidation during the request makes the shutdown write discard
    its new entries (34, 44).
28. An oversized blob is trimmed oldest first and logged once (35).
29. Publishing a post drops fresh entries in its terms and fresh entries
    that read everything, and keeps TTL entries and fresh entries in other
    terms (36).
30. A scheduled post published from cron triggers the same invalidation
    (36, section 4.9 preamble).
31. Unpublishing drops fresh entries in scope and every entry containing
    the ID (37).
32. Editing the title of a published post drops nothing; changing its date
    drops fresh entries in scope (38).
33. Force-deleting a published post drops entries as for an unpublish (39).
34. Moving a published post from term A to term B drops fresh entries for
    A and for B (40).
35. Publishing first and assigning terms after still drops the right
    entries (40).
36. Deleting a term drops every entry whose scope contains it (41).
37. Changing the sticky list drops every entry (42).
38. `flush()` and `wp query-id-cache flush` empty the store and bump the
    generation (43).
39. An invalidation on an empty store writes nothing (44).
40. Publishing a post of a type no signature covers changes nothing (45).
41. A view-count meta update changes nothing (46).
42. Identical queries in one request record one entry (47).
43. A reused query object is handled as two separate runs (48).
44. A query inside `switch_to_blog` is neither served nor recorded (51).
45. A signature with a time to live of 0 is disabled (52).
46. A corrupt or wrong-type store value is treated as empty and logged
    once (54, 59).
47. An entry with non-integer IDs is ignored (55).
48. A failing store write is logged and nothing else happens (56).
49. A throwing match callback disables that signature for the request and
    is logged once (57).
50. An exception inside the plugin's query filter returns the incoming
    value unchanged (58).
51. Store selection: object cache store when an external cache is
    reported, transient store otherwise, filter override honoured
    (section 6).
52. Object cache store: a generation bump makes every old entry a miss
    (section 6, 34).
53. Uninstall removes the transient on every site and leaves no options
    (section 6).
54. Registering a signature with an invalid name or class throws
    `InvalidSignature` (5.1).
55. The debug header appears only when enabled and only on eligible
    anonymous responses, with counts only (11, section 8).
56. The README states that a query with `suppress_filters` is never seen
    by the plugin; a test confirms the plugin adds no cache behaviour to
    such a query (9).
57. A sticky-post change drops entries that a theme's own sticky emulation
    cached, and the main home query is never served (42, 49).
58. Two store instances sharing one object cache: a bump made through one
    makes the other miss, and an entry stamped more than 60 s ahead is a
    miss (18, 34, 53).

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. In WordPress 6.4 and later, does core recompute `found_posts` after the
   short-circuit filter for `fields` of `ids` or `all`? Decide whether the
   reassertion in case 22 is needed and test it either way.
2. Does the result filter run on the short-circuit path in every supported
   version? Choose the capture priority (case 25) so cached lists equal the
   raw database output and later result filters are not applied twice.
3. Which hooks give the old and new terms of a post reliably (case 40), and
   how to get a post's terms before a permanent delete (case 39)?
4. Should `auto` treat `post__in` order as TTL (current choice) or exclude
   it entirely, since the list is fixed by the caller?
5. Is a per-entry generation (rather than one per store) worth it for the
   object cache store, so an invalidation does not orphan unrelated
   entries?
6. Should the context strings in 5.1 grow (for example `category`, `tag`),
   or is the callable form enough?

## 14. Lessons from production

These are rules. Each one cost real time once.

1. Do not memoise "not eligible" before the main query has run. Conditional
   tags are false until then, and an early secondary query would switch
   the cache off for the whole request.
2. Cache IDs, not posts. Load posts fresh on every hit. An edit to a title
   or a featured image then needs no invalidation at all.
3. Hand back `found_posts` and `max_num_pages` yourself. When the query is
   short-circuited, WordPress has nothing to count from, and pagination
   breaks silently.
4. Key on the final SQL, not on the arguments. Plugins and themes rewrite
   queries through filters; two identical argument arrays can run
   different SQL.
5. Re-read the store before writing at shutdown. A single blob shared by
   all requests loses entries to the last writer otherwise.
6. Bump a generation on invalidation and refuse to write entries computed
   before it. Otherwise a slow request that started before a publish
   writes a stale "latest" list back after the publish cleared it.
7. Invalidate everywhere, serve only on the front end. Scheduled posts are
   published by cron, and editors publish through REST; a front-end-only
   invalidation misses both.
8. Invalidate on term assignment, not only on status change. Some editors
   publish first and set categories a moment later, so the terms read at
   publish time are the wrong ones.
9. An exclusion term filter ("not in category X") reads everything. Parsing
   the term list out of SQL text treats the excluded term as the scope,
   which is exactly backwards.
10. A permanent delete of a published post does not pass through a status
    change. Hook the delete itself and read the terms before they go.
11. Never cache random order, author lists or anything with a non-publish
    status. Random order defeats the cache; the others leak per-user or
    private data.
12. Signed-in requests bypass the page cache and can see private posts.
    Never serve them, and never let them write.
13. Popularity lists may lag. Say so in the docs and do not try to
    invalidate on every view or comment; that would rewrite the store on
    almost every request.
14. Without a persistent object cache, one transient read per request is
    cheap; one per query is not. Use one blob for database transients and
    per-entry items only where the object cache makes them free.
15. Give the blob an expiry longer than the entries' time to live and check
    each entry's age on read. Relying on the transient expiry alone
    expires everything at once and stampedes the database.
16. Cap the store. A relative date in one query creates a new key every
    second and grows the blob without limit.
17. Step aside when another plugin has already answered the short-circuit
    filter. Two answers to one query is a bug that looks like random wrong
    lists.
