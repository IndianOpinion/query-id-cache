<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * An eligible secondary query, matched to its signature.
 */
final class Candidate
{
    /**
     * @param list<string> $post_types
     * @param list<int>    $scope      Term taxonomy IDs the list reads.
     */
    public function __construct(
        public readonly Signature $signature,
        public readonly string $fields,
        public readonly array $post_types,
        public readonly string $freshness,
        public readonly array $scope,
        public readonly bool $reads_all,
        public readonly int $ttl,
    ) {
    }
}
