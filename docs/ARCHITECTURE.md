# Architecture

This document describes how query-id-cache is built and records every
decision taken while building it from [PRD.md](PRD.md), including the
answers to the PRD's open questions (section 13).

## Modules

| File | Role |
|---|---|
| `query-id-cache.php` | Plugin header. Loads the autoloader, boots the plugin, flushes on deactivation. |
| `uninstall.php` | Calls `Uninstaller::run()`. |
| `src/autoload.php` | PSR-4 autoloader for installs without Composer. Also loads `functions.php`. |
| `src/functions.php` | Public functions: `register_signature`, `unregister_signature`, `get_signatures`, `flush`. |
| `src/Plugin.php` | Builds the object graph once per request, registers every hook, picks the store. |
| `src/Registry.php`, `src/Signature.php`, `src/InvalidSignature.php` | Signature registration, validation and the signatures filter. |
| `src/Contexts.php` | Named request contexts and the contexts filter. |
| `src/RequestGate.php` | Request eligibility with the memoisation rules of PRD 4.2. |
| `src/QueryInspector.php`, `src/Candidate.php`, `src/Freshness.php` | Query eligibility, hard exclusions, post types, freshness class and term scope. |
| `src/Cache.php` | Serving hits, recording misses, the shutdown write. |
| `src/Entry.php` | Entry shape, validation and the staleness rule. |
| `src/Store.php`, `src/TransientStore.php`, `src/ObjectCacheStore.php` | Storage. |
| `src/Stats.php` | Per-signature write statistics for "keys never repeat". |
| `src/Invalidator.php` | Every invalidation hook. |
| `src/DebugHeader.php`, `src/Logger.php`, `src/Cli.php` | Observability. |
| `src/Config.php`, `src/Clock.php` | Settings through constants and filters; a clock seam for tests. |
| `src/Uninstaller.php` | Removes the transient on every site and the object cache group. |

## Data flow

Serving (front end, anonymous):

```
WP_Query::get_posts()
  posts_pre_query (20)  -> Cache::pre_query
      reused object? clear its pending/served state
      incoming not null, main query, switched blog, gate says no -> step aside
      QueryInspector::inspect -> Candidate or null
      key = sha256(format, blog, signature, fields, final SQL)
      first store access in the request: remember the generation
      hit  -> prime posts, safety net, set found_posts/max_num_pages, return IDs
      miss -> mark the object pending, return null
  [fields=ids] found_posts_query (MAX) -> on a served query, "SELECT <count>"
  [fields=ids] found_posts (MAX)       -> served: reassert count; pending: record
  posts_results (MIN)   -> Cache::snapshot: raw IDs of a pending query
  the_posts (MAX)       -> Cache::record: buffer the entry with the final count
shutdown (0)            -> record pending fields=ids queries, then one write
```

Invalidation (every context): an `Invalidator` handler reads the store
once, picks the entries its event can affect, and calls `Store::drop()`
once if there are any. `drop()` removes them and bumps the generation.

## Decisions

### Rulings on the open questions (PRD section 13)

**Q1. Does core recompute `found_posts` after the short-circuit?** Ruling:
verify against core source and set the count explicitly from the cache.
Checked against `WP_Query::get_posts()` in the 6.4 and 6.7 branches:

- `fields => all` (and the default): when `posts_pre_query` returns a value,
  core skips `set_found_posts()` entirely. `found_posts` and
  `max_num_pages` stay whatever the filter set, so the plugin sets both in
  `posts_pre_query`. Without that, pagination silently breaks (lesson 3).
- `fields => ids`: core always calls `set_found_posts()` after the
  short-circuit. With a `LIMIT` it runs `SELECT FOUND_ROWS()`, which then
  reads the row count of whatever query ran last, and passes that through
  the `found_posts` filter. So the plugin both replaces the
  `found_posts_query` with `SELECT <stored count>` and reasserts the stored
  count on `found_posts` at `PHP_INT_MAX`, for that query object only.
  `max_num_pages` is then computed by core from the right number.
- Empty cached lists: `set_found_posts()` bails on an empty array, so the
  values set in `posts_pre_query` stand.

Tests 14 and 18 cover both modes; the test stub models `FOUND_ROWS()` after
a short-circuit as a stale 999.

**Q2. Capture priority.** Ruling: capture a miss on `the_posts` at a very
late priority, not on the short-circuit path. Core source shows
`posts_results` and `the_posts` both run on the short-circuit path too (for
`fields => all`, when filters are not suppressed), so on a hit every result
filter sees the cached IDs again. To keep a later hit identical to a miss
the stored list must be the raw database order. Implementation: the IDs
are snapshotted at `posts_results` with priority `PHP_INT_MIN` (before any
other result filter can reorder them), and the entry is recorded at
`the_posts` with priority `PHP_INT_MAX`, when `found_posts` is final.
Recording the output of `the_posts` itself would apply filters twice on a
hit (a filter that prepends a post would prepend it again), which is what
test 20 guards against. Nothing is captured inside `posts_pre_query`.

`fields => ids` returns from `get_posts()` before either result filter, so
those queries are recorded at `found_posts` (`PHP_INT_MAX`) when it fires,
and otherwise (`no_found_rows`, or an empty result) at shutdown, reading
`posts` and `found_posts` from the query object.

**Q3. Hooks for term and delete invalidation.** Ruling: `set_object_terms`,
`transition_post_status`, `before_delete_post` (terms still attached) and
`deleted_post`.

- `set_object_terms` passes both the new and the old term taxonomy IDs, so a
  move from A to B drops entries for both, and a publish-then-assign flow
  is caught when the terms arrive.
- `transition_post_status` covers publish and unpublish from every context,
  including `future` to `publish` through cron.
- `before_delete_post` fires before `wp_delete_object_term_relationships()`,
  so status and terms are read there and stashed by post ID;
  `deleted_post` performs the unpublish-style drop once the row is gone. A
  delete from the trash is ignored: the trash transition already dropped
  the entries.
- Case 38 (update while published) uses `post_updated`, which passes the
  before and after objects.
- Case 41 uses `edit_term` (stash old ancestors), `edited_term`,
  `delete_term` and `split_shared_term`. An edit also drops entries scoped
  to the term's old or new ancestors, because a parent change alters which
  lists expand to the term.

**Q4. `post__in`.** Ruling: exclude `post__in` queries from caching; the
caller already has the IDs. Any non-empty `post__in` is a hard exclusion,
so the `auto` class never meets a `post__in` order.

**Q5. Generation granularity.** Ruling: one generation number per store,
not per entry. In the transient store the generation is a write guard
(case 34) and selective invalidation keeps unrelated entries. In the object
cache store the generation is part of every item key, so any invalidation
that would drop an entry bumps it and orphans every entry. That trades a
few rebuilds for no read-merge-write race and no enumeration.

**Q6. Contexts.** Ruling: ship `home`, `category`, `tag`, `author` and
`date` beside `front_page`, `archive`, `singular` and `any`; searches
excluded; list filterable. Every named context is false when `is_search()`
is true. The list passes through `query_id_cache_contexts` (name =>
callable) and registration validates names against it. Callables are not
wrapped: a site that wants a search context can write one, but search
queries themselves (`s` set) are always excluded.

### Builder decisions

**`suppress_filters` needs code (correction to case 9).** The PRD says a
`suppress_filters` query never reaches the plugin. Core source shows
`posts_pre_query` runs regardless of `suppress_filters`; only
`posts_request`, `posts_results` and `the_posts` are skipped. Left alone,
such a query could be served but never recorded correctly. So
`suppress_filters` is a hard exclusion, and the README says so, noting that
`get_posts()` sets it by default (test 56).

**Store interface.** The PRD names five methods. The interface keeps them
and adds four: `get()` for the per-query hit path (one cache get per query
on the object cache store, an in-memory lookup on the blob store), `drop()`
so an invalidation is one read and at most one write (removing keys and
bumping the generation together), `stats()` and `describe()` for WP-CLI.
`save()` takes only the new entries; the store does the re-read, expiry
pruning, merge and caps itself, because each store does them differently.
The generation guard (case 34) stays in `Cache`, which compares a fresh
`generation()` with the one it saw at first access.

**Fresh reads of the blob.** Without an object cache, WordPress keeps
option values in its in-request cache. Before the shutdown re-read and
every invalidation read, the transient store deletes its two keys from the
`options` group so another request's write is seen. The first read of the
request (for the hit path) is ordinary and memoised.

**Object cache index.** Per-entry items cannot be enumerated, so the object
cache store keeps one index item per generation (key => time stored, time
to live). The hit path trusts an entry only when the index lists it, so an
evicted index can never leave an entry that invalidation cannot see. The
index is read once per request.

**Evicted generation.** If the object cache loses the generation item it
restarts from the current time in milliseconds, which is above every
generation reached by increments, so orphaned items can never come back.

**"Keys never repeat".** Hits must not write (case 23), so hit counts cannot
be persisted without breaking that rule. Repetition is measured on writes
instead: each signature keeps a ring of the last 256 short key prefixes it
stored. A new entry whose key is in the ring, or still in the store, counts
as a repeat. `status` flags a signature with 50 or more stores and no
repeat. A healthy signature repeats as soon as its first entries expire and
are rebuilt.

**Entries carry their time to live.** Case 27 lists the fields; `ttl` is
added so either store can prune expired entries at write time without
knowing the signatures. On the hit path the signature's current time to
live is used, so lowering a TTL takes effect at once.

**Safety net (case 21).** For `fields => all` the IDs are primed first, so an
ID missing from the post cache afterwards no longer exists. For
`fields => ids` nothing is primed; only posts already in memory are
checked, the rest are kept. When an ID is dropped, `found_posts` is reduced
by the same number and the entry is deleted through `drop()`. That bump is
the plugin's own, so the request's own new entries are not discarded for it.

**Post types.** An empty `post_type` resolves the way core does for simple
queries (`post`, or `page`/`attachment` on those requests). On a custom
taxonomy query core resolves it from registered types at SQL time; the
plugin does not guess and leaves the query alone.

**Term scope combination.** For an `AND` group the scope is the union of the
narrowing children (a safe superset); for an `OR` group any child that
reads everything makes the whole group read everything. A clause whose
field is not `term_taxonomy_id` has not been through core's rewrite, so it
reads everything.

**Opt-in by name.** A query that names a signature selects it directly. The
signature's context and post types must still hold and every hard
exclusion still applies; its `match` callback is skipped, since naming it
is the stronger statement. `false` (or the string `false`) opts out and
always wins.

**Failure handling.** Every filter and action callback catches `Throwable`,
logs once per kind per request, and returns its input unchanged (case 58).
A throwing context or `match` callback disables only that signature for the
request (case 57). If an invalidation handler throws, the whole store is
flushed: a missed invalidation could leave a stale "latest" list.

**Debug header timing.** Counts are known only after rendering. When the
header is enabled and the request is eligible, the plugin starts an output
buffer at `template_redirect` and adds the header in the buffer callback.
The shutdown write runs at `shutdown` priority 0, before WordPress flushes
buffers at priority 1, so `stored` is counted.

**Default signature.** Registered on `init` at priority 1, and only when the
site has not registered or removed `front-page` itself, so a site can
unregister it at any point.

**Key hash.** SHA-256 over the format version, blog ID, signature, fields
mode and final SQL, separated by NUL bytes. Keys are never echoed; WP-CLI
`list` shows the first 12 characters only.

## Testing

PHPUnit 10 against a stub layer in `tests/stubs/wordpress.php`. The stub
`WP_Query::get_posts()` reproduces the 6.4 hook order around the
short-circuit, including `set_found_posts()` and the `fields => ids` early
return, so the tests exercise the same paths core takes. Every acceptance
test in PRD section 12 maps to at least one test method; each method's
docblock names its test number and cases.
