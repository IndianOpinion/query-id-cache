<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * One validated signature: which secondary queries may be cached, where,
 * for how long and in which freshness class.
 */
final class Signature
{
    public const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var string|callable(): bool */
    public readonly mixed $context;

    /** @var (callable(\WP_Query): bool)|null */
    public readonly mixed $match;

    /**
     * @param string|callable(): bool                  $context
     * @param list<string>                             $post_types
     * @param (callable(\WP_Query): bool)|null         $match
     */
    public function __construct(
        public readonly string $name,
        string|callable $context,
        public readonly array $post_types,
        public readonly string $freshness,
        public readonly ?int $ttl,
        ?callable $match,
        public readonly bool $opt_in_only,
    ) {
        $this->context = $context;
        $this->match   = $match;
    }

    /**
     * Build a signature from registration arguments, validating each one.
     *
     * @param array<string, mixed> $args
     *
     * @throws InvalidSignature When the name or any argument is invalid.
     */
    public static function from_args(string $name, array $args): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidSignature(sprintf('Signature name "%s" must be a slug: lower case letters, digits and single hyphens.', $name));
        }

        $known   = ['context', 'post_types', 'freshness', 'ttl', 'match', 'opt_in_only'];
        $unknown = array_diff(array_keys($args), $known);
        if ($unknown !== []) {
            throw new InvalidSignature(sprintf('Signature "%s": unknown argument(s) %s.', $name, implode(', ', array_map('strval', $unknown))));
        }

        $context = $args['context'] ?? 'front_page';
        if (is_string($context)) {
            if (! array_key_exists($context, Contexts::all())) {
                throw new InvalidSignature(sprintf('Signature "%s": unknown context "%s".', $name, $context));
            }
        } elseif (! is_callable($context)) {
            throw new InvalidSignature(sprintf('Signature "%s": context must be a context name or a callable.', $name));
        }

        $post_types = $args['post_types'] ?? ['post'];
        if (is_string($post_types)) {
            $post_types = [$post_types];
        }
        if (! is_array($post_types) || $post_types === []) {
            throw new InvalidSignature(sprintf('Signature "%s": post_types must be a non-empty list of post type names.', $name));
        }
        $types = [];
        foreach ($post_types as $type) {
            if (! is_string($type) || $type === '' || $type === 'any' || preg_match('/^[a-z0-9_-]+$/', $type) !== 1) {
                throw new InvalidSignature(sprintf('Signature "%s": invalid post type in post_types.', $name));
            }
            $types[] = $type;
        }

        $freshness = $args['freshness'] ?? Freshness::AUTO;
        if (! is_string($freshness) || ! in_array($freshness, Freshness::ALL, true)) {
            throw new InvalidSignature(sprintf('Signature "%s": freshness must be one of auto, fresh, ttl.', $name));
        }

        $ttl = $args['ttl'] ?? null;
        if ($ttl !== null && ! is_int($ttl)) {
            throw new InvalidSignature(sprintf('Signature "%s": ttl must be an integer number of seconds.', $name));
        }

        $match = $args['match'] ?? null;
        if ($match !== null && ! is_callable($match)) {
            throw new InvalidSignature(sprintf('Signature "%s": match must be a callable.', $name));
        }

        $opt_in_only = $args['opt_in_only'] ?? false;
        if (! is_bool($opt_in_only)) {
            throw new InvalidSignature(sprintf('Signature "%s": opt_in_only must be a boolean.', $name));
        }

        return new self($name, $context, array_values(array_unique($types)), $freshness, $ttl, $match, $opt_in_only);
    }

    /**
     * Arguments array equal to what from_args() accepts, for the signatures filter.
     *
     * @return array<string, mixed>
     */
    public function to_args(): array
    {
        return [
            'context'     => $this->context,
            'post_types'  => $this->post_types,
            'freshness'   => $this->freshness,
            'ttl'         => $this->ttl,
            'match'       => $this->match,
            'opt_in_only' => $this->opt_in_only,
        ];
    }

    /**
     * Time to live in seconds, falling back to the global value.
     */
    public function effective_ttl(int $global): int
    {
        return $this->ttl ?? $global;
    }
}
