<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Optional `X-Query-Id-Cache: hits=<n>; misses=<n>; stored=<n>` header.
 *
 * Counts are only known after rendering, so when the header is enabled on
 * an eligible request the plugin buffers output and adds the header just
 * before the buffer is flushed. Counts only: never keys, SQL or IDs.
 */
final class DebugHeader
{
    public const NAME = 'X-Query-Id-Cache';

    /** @var callable(string): void */
    private $emit;

    private bool $started = false;

    /**
     * @param (callable(string): void)|null $emit Sends one header line. Defaults to header().
     */
    public function __construct(
        private readonly RequestGate $gate,
        private readonly Cache $cache,
        ?callable $emit = null,
    ) {
        $this->emit = $emit ?? static function (string $line): void {
            if (! headers_sent()) {
                header($line);
            }
        };
    }

    /**
     * Action: template_redirect. Starts the buffer on eligible requests
     * when the header is enabled.
     */
    public function start(): void
    {
        if ($this->started || ! $this->should_send()) {
            return;
        }
        $this->started = true;
        ob_start([$this, 'finish']);
    }

    /**
     * Output buffer callback: send the header, return the body unchanged.
     */
    public function finish(string $buffer): string
    {
        $this->send();
        return $buffer;
    }

    public function should_send(): bool
    {
        return Config::debug_header() && $this->gate->eligible();
    }

    /**
     * The header line for the current counters.
     */
    public function line(): string
    {
        $c = $this->cache->counters();
        return sprintf('%s: hits=%d; misses=%d; stored=%d', self::NAME, $c['hits'], $c['misses'], $c['stored']);
    }

    public function send(): void
    {
        if ($this->should_send()) {
            ($this->emit)($this->line());
        }
    }
}
