<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Per-signature write statistics used to spot keys that never repeat.
 *
 * Hits are never written (a hit must not cause a write), so repetition is
 * measured on the write side: a signature whose new keys were seen before
 * is healthy. A signature that stored many entries and never repeated a
 * key usually has a relative date or a per-request value in its SQL.
 *
 * @phpstan-type SignatureStats array{stored: int, repeats: int, recent: list<string>}
 */
final class Stats
{
    public const RECENT_CAP         = 256;
    public const NEVER_REPEAT_AFTER = 50;

    /**
     * Fold a batch of new entries into the stats.
     *
     * @param array<mixed>               $stats    Previous stats (validated here).
     * @param array<string, array<mixed>> $new      Key => entry.
     * @param array<string, mixed>        $existing Keys already present in the store before the merge.
     *
     * @return array<string, SignatureStats>
     */
    public static function record(array $stats, array $new, array $existing): array
    {
        $clean = self::clean($stats);
        foreach ($new as $key => $entry) {
            $sig = isset($entry['sig']) && is_string($entry['sig']) ? $entry['sig'] : '';
            if ($sig === '') {
                continue;
            }
            $row   = $clean[$sig] ?? ['stored' => 0, 'repeats' => 0, 'recent' => []];
            $short = substr((string) $key, 0, 10);
            if (isset($existing[$key]) || in_array($short, $row['recent'], true)) {
                $row['repeats']++;
            } else {
                $row['recent'][] = $short;
                if (count($row['recent']) > self::RECENT_CAP) {
                    $row['recent'] = array_slice($row['recent'], -self::RECENT_CAP);
                }
            }
            $row['stored']++;
            $clean[$sig] = $row;
        }
        return $clean;
    }

    /**
     * @param array<mixed> $stats
     *
     * @return array<string, SignatureStats>
     */
    public static function clean(array $stats): array
    {
        $out = [];
        foreach ($stats as $sig => $row) {
            if (! is_string($sig) || ! is_array($row)) {
                continue;
            }
            $recent = isset($row['recent']) && is_array($row['recent']) ? array_values(array_filter($row['recent'], 'is_string')) : [];
            $out[$sig] = [
                'stored'  => isset($row['stored']) && is_int($row['stored']) ? $row['stored'] : 0,
                'repeats' => isset($row['repeats']) && is_int($row['repeats']) ? $row['repeats'] : 0,
                'recent'  => $recent,
            ];
        }
        return $out;
    }

    /**
     * @param array<mixed> $row
     */
    public static function never_repeats(array $row): bool
    {
        $stored  = isset($row['stored']) && is_int($row['stored']) ? $row['stored'] : 0;
        $repeats = isset($row['repeats']) && is_int($row['repeats']) ? $row['repeats'] : 0;
        return $stored >= self::NEVER_REPEAT_AFTER && $repeats === 0;
    }
}
