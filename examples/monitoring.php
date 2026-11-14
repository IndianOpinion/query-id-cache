<?php

/**
 * Example: count hits and misses per signature in the PHP error log, and
 * turn on the debug header for anonymous requests.
 */

declare(strict_types=1);

add_filter('query_id_cache_debug_header', '__return_true');

add_action('query_id_cache_hit', static function (string $key, string $signature, int $count): void {
    $GLOBALS['example_qic'][$signature]['hits'] = ($GLOBALS['example_qic'][$signature]['hits'] ?? 0) + 1;
}, 10, 3);

add_action('query_id_cache_miss', static function (string $key, string $signature): void {
    $GLOBALS['example_qic'][$signature]['misses'] = ($GLOBALS['example_qic'][$signature]['misses'] ?? 0) + 1;
}, 10, 2);

add_action('query_id_cache_invalidated', static function (string $reason, int $dropped): void {
    error_log(sprintf('query-id-cache example: %s dropped %d entries', $reason, $dropped));
}, 10, 2);

add_action('shutdown', static function (): void {
    if (! empty($GLOBALS['example_qic'])) {
        error_log('query-id-cache example: ' . wp_json_encode($GLOBALS['example_qic']));
    }
}, 5);
