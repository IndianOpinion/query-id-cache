<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Ordered set of registered signatures.
 */
final class Registry
{
    /** @var array<string, Signature> In registration order. */
    private array $signatures = [];

    /** @var array<string, true> Names a site removed on purpose. */
    private array $removed = [];

    /** @var array<string, Signature>|null Memoised filtered list. */
    private ?array $filtered = null;

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @throws InvalidSignature
     */
    public function register(string $name, array $args = []): void
    {
        $signature = Signature::from_args($name, $args);
        // Replacing keeps the original position, as the PRD's "registers or replaces" implies.
        $this->signatures[$name] = $signature;
        unset($this->removed[$name]);
        $this->filtered = null;
    }

    public function unregister(string $name): void
    {
        unset($this->signatures[$name]);
        $this->removed[$name] = true;
        $this->filtered = null;
    }

    /**
     * Register the built-in `front-page` signature unless the site already
     * registered or removed that name.
     */
    public function register_default(): void
    {
        if (! isset($this->signatures['front-page']) && ! isset($this->removed['front-page'])) {
            $this->register('front-page');
        }
    }

    /**
     * Signatures after the `query_id_cache_signatures` filter, in order.
     *
     * The filter receives and returns an array of name => argument array.
     * Entries the filter makes invalid are skipped and logged once, never
     * thrown during a render.
     *
     * @return array<string, Signature>
     */
    public function all(): array
    {
        if ($this->filtered !== null) {
            return $this->filtered;
        }

        $as_args = [];
        foreach ($this->signatures as $name => $signature) {
            $as_args[$name] = $signature->to_args();
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('query_id_cache_signatures', $as_args);
        if (! is_array($filtered)) {
            $this->logger->error('signatures-filter', 'the query_id_cache_signatures filter did not return an array; using the registered list.');
            $filtered = $as_args;
        }

        $out = [];
        foreach ($filtered as $name => $args) {
            try {
                if ($args instanceof Signature) {
                    $out[$args->name] = $args;
                    continue;
                }
                if (! is_string($name) || ! is_array($args)) {
                    throw new InvalidSignature('each filtered signature must be name => argument array.');
                }
                /** @var array<string, mixed> $args */
                $out[$name] = Signature::from_args($name, $args);
            } catch (InvalidSignature $e) {
                $this->logger->error('signatures-filter', $e->getMessage());
            }
        }

        return $this->filtered = $out;
    }

    /**
     * Post types any signature covers. Invalidation ignores the rest.
     *
     * @return list<string>
     */
    public function covered_post_types(): array
    {
        $types = [];
        foreach ($this->all() as $signature) {
            foreach ($signature->post_types as $type) {
                $types[$type] = true;
            }
        }
        return array_keys($types);
    }

    public function reset_memo(): void
    {
        $this->filtered = null;
    }
}
