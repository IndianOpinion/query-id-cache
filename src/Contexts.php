<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Named request contexts a signature can use instead of a callable.
 *
 * Every named context is false on a search results page: searches are
 * never cached. Sites add or replace names through the
 * `query_id_cache_contexts` filter.
 */
final class Contexts
{
    /**
     * @return array<string, callable(): bool> Name to predicate, after the filter.
     */
    public static function all(): array
    {
        $tag = static fn (string $fn): callable => static fn (): bool => function_exists($fn) && (bool) $fn();

        $builtin = [
            'any'        => static fn (): bool => true,
            'front_page' => $tag('is_front_page'),
            'home'       => $tag('is_home'),
            'archive'    => $tag('is_archive'),
            'singular'   => $tag('is_singular'),
            'category'   => $tag('is_category'),
            'tag'        => $tag('is_tag'),
            'author'     => $tag('is_author'),
            'date'       => $tag('is_date'),
        ];

        /** @var mixed $filtered */
        $filtered = apply_filters('query_id_cache_contexts', $builtin);
        if (! is_array($filtered)) {
            return $builtin;
        }

        $out = [];
        foreach ($filtered as $name => $predicate) {
            if (is_string($name) && is_callable($predicate)) {
                $out[$name] = $predicate;
            }
        }
        return $out;
    }

    /**
     * Evaluate a named context for the current request. Unknown names are false.
     */
    public static function evaluate(string $name): bool
    {
        if (function_exists('is_search') && is_search()) {
            return false;
        }
        $all = self::all();
        if (! isset($all[$name])) {
            return false;
        }
        return (bool) ($all[$name])();
    }
}
