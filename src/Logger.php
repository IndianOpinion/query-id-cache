<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Failure and debug logging with a fixed prefix.
 *
 * Failures are logged at most once per kind per request. Debug lines are
 * written only when QUERY_ID_CACHE_DEBUG is true.
 */
final class Logger
{
    public const PREFIX = 'query-id-cache: ';

    /** @var callable(string): void */
    private $sink;

    /** @var array<string, true> */
    private array $seen = [];

    private bool $debug;

    /**
     * @param (callable(string): void)|null $sink Receives each finished line. Defaults to error_log().
     */
    public function __construct(?callable $sink = null, ?bool $debug = null)
    {
        $this->sink  = $sink ?? static function (string $line): void {
            error_log($line);
        };
        $this->debug = $debug ?? (defined('QUERY_ID_CACHE_DEBUG') && (bool) constant('QUERY_ID_CACHE_DEBUG'));
    }

    /**
     * Log a failure once per kind for this request.
     */
    public function error(string $kind, string $message): void
    {
        if (isset($this->seen[$kind])) {
            return;
        }
        $this->seen[$kind] = true;
        ($this->sink)(self::PREFIX . $kind . ': ' . $message);
    }

    /**
     * Verbose line, only under QUERY_ID_CACHE_DEBUG.
     */
    public function debug(string $message): void
    {
        if ($this->debug) {
            ($this->sink)(self::PREFIX . $message);
        }
    }

    public function is_debug(): bool
    {
        return $this->debug;
    }
}
