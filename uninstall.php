<?php

/**
 * Uninstall: remove the transient on every site and the object cache group.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (! class_exists('IndianOpinion\\QueryIdCache\\Uninstaller')) {
    require_once __DIR__ . '/src/autoload.php';
}

IndianOpinion\QueryIdCache\Uninstaller::run();
