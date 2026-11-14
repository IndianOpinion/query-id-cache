<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Register or replace a signature.
 *
 * @param array<string, mixed> $args See README: context, post_types, freshness, ttl, match, opt_in_only.
 *
 * @throws InvalidSignature When the name or an argument is invalid.
 */
function register_signature(string $name, array $args = []): void
{
    Plugin::instance()->registry->register($name, $args);
}

/**
 * Remove a signature, including the built-in `front-page`.
 */
function unregister_signature(string $name): void
{
    Plugin::instance()->registry->unregister($name);
}

/**
 * Registered signatures after the `query_id_cache_signatures` filter.
 *
 * @return array<string, Signature>
 */
function get_signatures(): array
{
    return Plugin::instance()->registry->all();
}

/**
 * Drop every entry and bump the store generation.
 */
function flush(): void
{
    Plugin::instance()->flush();
}
