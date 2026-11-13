# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-13

### Added

- Caching of ordered post ID lists and found-rows counts for eligible
  secondary `WP_Query` runs, served through `posts_pre_query` to anonymous
  front-end requests only.
- Signatures: `register_signature()`, `unregister_signature()`,
  `get_signatures()`, a default `front-page` signature, named contexts
  (`front_page`, `home`, `archive`, `singular`, `category`, `tag`,
  `author`, `date`, `any`) and the `query_id_cache` query variable for
  opt-in and opt-out.
- Fresh and TTL freshness classes, with `auto` choosing by order.
- Term-scoped invalidation on publish, unpublish, order-relevant updates,
  permanent delete, term assignment, term creation, edit, delete and split, and
  sticky changes, in every context.
- Transient (single blob) and object cache (per entry) stores, chosen
  automatically, replaceable through `query_id_cache_store`.
- WP-CLI commands `status`, `list` and `flush`.
- Optional `X-Query-Id-Cache` debug header, actions for hits, misses,
  writes, invalidations and flushes, and once-per-request failure logging.
- Uninstall cleanup on every site of a network.

[Unreleased]: https://github.com/IndianOpinion/query-id-cache/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/query-id-cache/releases/tag/v0.1.0
