<?php

/**
 * Plugin Name:       Query ID Cache
 * Plugin URI:        https://github.com/IndianOpinion/query-id-cache
 * Description:       Caches the post ID lists and found-rows counts of slow secondary queries for anonymous visitors; drops them when a publish can change them.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       query-id-cache
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('IndianOpinion\\QueryIdCache\\register_signature')) {
    require_once __DIR__ . '/src/autoload.php';
}

IndianOpinion\QueryIdCache\Plugin::instance()->boot();

if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, static function (): void {
        IndianOpinion\QueryIdCache\Plugin::instance()->flush();
    });
}
