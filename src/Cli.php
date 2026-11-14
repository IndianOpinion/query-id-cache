<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

/**
 * Inspect and flush the query ID cache.
 *
 * Available only under WP-CLI.
 */
final class Cli
{
    /** @var callable(string): void */
    private $out;

    /**
     * @param (callable(string): void)|null $out Receives each output line. Defaults to WP_CLI::line().
     */
    public function __construct(private readonly Plugin $plugin, ?callable $out = null)
    {
        $this->out = $out ?? static function (string $line): void {
            \WP_CLI::line($line);
        };
    }

    /**
     * Show store type, entry count, generation, size, ages and entries per signature.
     *
     * ## EXAMPLES
     *
     *     wp query-id-cache status
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $store   = $this->plugin->store();
        $info    = $store->describe();
        $entries = $this->valid_entries($store);
        $now     = Clock::now();

        $this->line('Store:      ' . (string) ($info['type'] ?? 'custom'));
        $this->line('Entries:    ' . count($entries));
        $this->line('Generation: ' . $store->generation());
        if (isset($info['bytes'])) {
            $this->line('Blob size:  ' . (int) $info['bytes'] . ' bytes');
        }
        if ($entries !== []) {
            $times = array_map(static fn (array $e): int => $e['t'], $entries);
            $this->line('Oldest:     ' . ($now - min($times)) . ' s');
            $this->line('Newest:     ' . ($now - max($times)) . ' s');
        }

        $per   = [];
        foreach ($entries as $entry) {
            $per[$entry['sig']] = ($per[$entry['sig']] ?? 0) + 1;
        }
        $stats = $store->stats();
        $names = array_unique(array_merge(array_keys($this->plugin->registry->all()), array_keys($per), array_keys($stats)));
        sort($names);
        $this->line('Signatures:');
        foreach ($names as $name) {
            $row  = $stats[$name] ?? ['stored' => 0, 'repeats' => 0, 'recent' => []];
            $note = Stats::never_repeats($row) ? '  keys never repeat: check for a relative date or a per-request value in its SQL' : '';
            $this->line(sprintf('  %s: %d entries, %d stored, %d repeated%s', $name, $per[$name] ?? 0, $row['stored'], $row['repeats'], $note));
        }
    }

    /**
     * List entries.
     *
     * ## OPTIONS
     *
     * [--signature=<name>]
     * : Only entries of this signature.
     *
     * ## EXAMPLES
     *
     *     wp query-id-cache list --signature=front-page
     *
     * @subcommand list
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args = [], array $assoc = []): void
    {
        $only = isset($assoc['signature']) ? (string) $assoc['signature'] : null;
        $now  = Clock::now();
        $this->line("key\tsignature\tclass\tage\tids\tfound\tscope");
        foreach ($this->valid_entries($this->plugin->store()) as $key => $e) {
            if ($only !== null && $e['sig'] !== $only) {
                continue;
            }
            $scope = $e['all'] ? 'all' : implode(',', $e['scope']);
            $this->line(sprintf("%s\t%s\t%s\t%ds\t%d\t%d\t%s", substr($key, 0, 12), $e['sig'], $e['class'], $now - $e['t'], count($e['ids']), $e['found'], $scope));
        }
    }

    /**
     * Drop every entry and bump the generation.
     *
     * ## EXAMPLES
     *
     *     wp query-id-cache flush
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function flush(array $args = [], array $assoc = []): void
    {
        $this->plugin->flush();
        $this->line('Flushed. Generation is now ' . $this->plugin->store()->generation() . '.');
    }

    /**
     * @return array<string, array{t: int, ids: list<int>, found: int, class: string, scope: list<int>, all: bool, types: list<string>, sig: string, gen: int, ttl: int}>
     */
    private function valid_entries(Store $store): array
    {
        $out = [];
        foreach ($store->load() as $key => $raw) {
            $entry = Entry::validate($raw);
            if ($entry !== null) {
                $out[(string) $key] = $entry;
            }
        }
        return $out;
    }

    private function line(string $line): void
    {
        ($this->out)($line);
    }
}
