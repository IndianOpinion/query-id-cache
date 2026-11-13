<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Request eligibility (PRD 4.2): may this request read or write entries?
 *
 * Reasons that cannot change during a request (admin, AJAX, cron, REST,
 * WP-CLI, XML-RPC, signed in, preview variables, disabled) are memoised at
 * once. Conditional tags are only reliable after the main query has run,
 * so before that the gate answers "not now" without memoising, and
 * computes the final answer once afterwards.
 */
final class RequestGate
{
    /** Query variables that mark a preview or a Customizer request. */
    public const PREVIEW_VARS = ['preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_theme', 'customize_messenger_channel', 'wp_customize'];

    private ?bool $memo = null;

    private string $reason = '';

    public function eligible(): bool
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $permanent = $this->permanent_reason();
        if ($permanent !== null) {
            $this->reason = $permanent;
            return $this->memo = false;
        }

        if (! self::main_query_ready()) {
            // Not memoised: the answer may be yes once the main query has run.
            $this->reason = 'main query not run';
            return false;
        }

        $late = $this->late_reason();
        $ok   = $late === null;
        $this->reason = $late ?? '';

        $ok = (bool) apply_filters('query_id_cache_request_eligible', $ok);
        if (! $ok && $this->reason === '') {
            $this->reason = 'filter';
        }
        return $this->memo = $ok;
    }

    /**
     * Why the last answer was no, for diagnostics.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    private function permanent_reason(): ?string
    {
        if (! (bool) apply_filters('query_id_cache_enabled', true)) {
            return 'disabled';
        }
        if (is_admin()) {
            return 'admin';
        }
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if ((defined('REST_REQUEST') && constant('REST_REQUEST')) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return 'rest';
        }
        if (defined('WP_CLI') && constant('WP_CLI')) {
            return 'cli';
        }
        if (defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST')) {
            return 'xmlrpc';
        }
        if (is_user_logged_in()) {
            return 'signed in';
        }
        foreach (self::PREVIEW_VARS as $var) {
            // Presence alone disqualifies; the value is never read or used.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (isset($_GET[$var]) || isset($_POST[$var])) {
                return 'preview';
            }
        }
        return null;
    }

    private function late_reason(): ?string
    {
        if (is_preview()) {
            return 'preview';
        }
        if (is_customize_preview()) {
            return 'customizer';
        }
        if (is_embed()) {
            $post = get_queried_object();
            if (! $post instanceof \WP_Post || $post->post_status !== 'publish') {
                return 'embed of unpublished post';
            }
        }
        return null;
    }

    /**
     * True once the main query has produced its posts.
     */
    public static function main_query_ready(): bool
    {
        if (did_action('wp') > 0) {
            return true;
        }
        $main = $GLOBALS['wp_the_query'] ?? null;
        if (! $main instanceof \WP_Query) {
            return false;
        }
        // WP_Query::init() unsets $posts, so read it without a warning.
        $vars = get_object_vars($main);
        return is_array($vars['posts'] ?? null);
    }
}
