<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

use Throwable;
use WP_Query;

/**
 * Query eligibility (PRD 4.3), freshness class (4.7) and term scope.
 *
 * Everything is read from parsed query variables and the parsed tax query,
 * never from SQL text, except two belt-and-braces checks on the final SQL
 * (random order and the author column).
 */
final class QueryInspector
{
    public const QUERY_VAR = 'query_id_cache';

    /** @var array<string, true> Signatures whose callbacks threw in this request. */
    private array $broken = [];

    /** @var array<string, bool> Context answers memoised per request. */
    private array $contexts = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Match a secondary query to the first eligible signature, or null.
     * The caller has already checked request eligibility and the main query.
     */
    public function inspect(WP_Query $query): ?Candidate
    {
        $opt = $query->get(self::QUERY_VAR, null);
        if ($opt === false || $opt === 'false') {
            return null;
        }
        $named = is_string($opt) && $opt !== '' ? $opt : null;

        if (self::hard_exclusion($query) !== null) {
            return null;
        }

        $fields = self::fields_mode($query);
        if ($fields === null) {
            return null;
        }

        $types = self::post_types($query);
        if ($types === null) {
            return null;
        }

        $global_ttl = Config::ttl();
        foreach ($this->registry->all() as $name => $signature) {
            if ($named !== null && $name !== $named) {
                continue;
            }
            if ($named === null && $signature->opt_in_only) {
                continue;
            }
            if (isset($this->broken[$name])) {
                continue;
            }
            $ttl = $signature->effective_ttl($global_ttl);
            if ($ttl <= 0) {
                continue;
            }
            if (array_diff($types, $signature->post_types) !== []) {
                continue;
            }
            if (! $this->context_holds($signature)) {
                continue;
            }
            if ($named === null && $signature->match !== null && ! $this->call_match($signature, $query)) {
                continue;
            }

            if (! (bool) apply_filters('query_id_cache_query_eligible', true, $query, $name)) {
                return null;
            }

            $freshness = $signature->freshness === Freshness::AUTO ? self::auto_freshness($query) : $signature->freshness;
            /** @var mixed $filtered */
            $filtered  = apply_filters('query_id_cache_freshness', $freshness, $query, $name);
            $freshness = $filtered === Freshness::TTL ? Freshness::TTL : ($filtered === Freshness::FRESH ? Freshness::FRESH : $freshness);

            [$scope, $reads_all] = self::term_scope($query);

            return new Candidate($signature, $fields, $types, $freshness, $scope, $reads_all, $ttl);
        }

        return null;
    }

    /**
     * Name of the first hard exclusion that applies (case 11), or null.
     */
    public static function hard_exclusion(WP_Query $query): ?string
    {
        // posts_pre_query runs even for suppress_filters queries, but the
        // result filters do not, so such a query could be served and never
        // recorded correctly. It is left alone (case 9).
        if ((bool) $query->get('suppress_filters', false)) {
            return 'suppress_filters';
        }

        /** @var mixed $request Not always a string in practice, whatever core's docblock says. */
        $request = $query->request;
        if (! is_string($request) || trim($request) === '') {
            return 'no sql';
        }

        $orderby = $query->get('orderby', '');
        if (is_array($orderby)) {
            $orders = array_merge(array_keys($orderby), array_values($orderby));
        } else {
            $orders = preg_split('/[\s,]+/', strtolower(is_scalar($orderby) ? (string) $orderby : ''));
        }
        foreach ((array) $orders as $order) {
            if (is_string($order) && preg_match('/^rand(\(\d*\))?$/i', $order) === 1) {
                return 'random order';
            }
        }
        if (stripos($request, 'RAND(') !== false) {
            return 'random order';
        }

        foreach (['author', 'author_name', 'author__in', 'author__not_in'] as $var) {
            $value = $query->get($var, '');
            if ($value !== '' && $value !== [] && $value !== 0 && $value !== '0' && $value !== null) {
                return 'author';
            }
        }
        if (stripos($request, 'post_author') !== false) {
            return 'author';
        }

        $status = $query->get('post_status', '');
        if (is_array($status)) {
            $status = array_values(array_unique($status));
            if ($status !== ['publish'] && $status !== []) {
                return 'status';
            }
        } elseif ($status !== '' && $status !== 'publish') {
            return 'status';
        }
        if ($query->get('perm', '') !== '') {
            return 'status';
        }

        $search = $query->get('s', '');
        if ($search !== '' && $search !== null) {
            return 'search';
        }

        $post_in = $query->get('post__in', []);
        if (is_array($post_in) && $post_in !== []) {
            return 'post__in';
        }

        return null;
    }

    /**
     * `ids` or `all`; null for `id=>parent` (not supported).
     */
    public static function fields_mode(WP_Query $query): ?string
    {
        $fields = $query->get('fields', '');
        if ($fields === 'ids') {
            return 'ids';
        }
        if ($fields === 'id=>parent') {
            return null;
        }
        return 'all';
    }

    /**
     * Post types the query reads, resolved like WP_Query does for the
     * simple cases, or null when the set is `any` or cannot be known
     * without core's own resolution (an empty type on a custom taxonomy).
     *
     * @return list<string>|null
     */
    public static function post_types(WP_Query $query): ?array
    {
        $type = $query->get('post_type', '');
        if ($type === '' || $type === [] || $type === null) {
            if (! empty($query->is_tax)) {
                return null;
            }
            if (! empty($query->is_attachment)) {
                return ['attachment'];
            }
            if (! empty($query->is_page)) {
                return ['page'];
            }
            return ['post'];
        }
        $types = is_array($type) ? $type : [$type];
        $out   = [];
        foreach ($types as $one) {
            if (! is_string($one) || $one === '' || $one === 'any') {
                return null;
            }
            $out[$one] = true;
        }
        return array_keys($out);
    }

    /**
     * Freshness for `auto` (case 29): date, modified, ID or no order is
     * fresh; anything else is TTL.
     */
    public static function auto_freshness(WP_Query $query): string
    {
        $orderby = $query->get('orderby', '');
        if (is_array($orderby)) {
            $keys = array_map(static fn ($k): string => strtolower((string) $k), array_keys($orderby));
        } else {
            $keys = preg_split('/[\s,]+/', strtolower(trim(is_scalar($orderby) ? (string) $orderby : '')));
            $keys = $keys === false ? [''] : $keys;
        }
        foreach ($keys as $key) {
            if (! in_array($key, Freshness::FRESH_ORDERS, true)) {
                return Freshness::TTL;
            }
        }
        return Freshness::FRESH;
    }

    /**
     * Term scope (cases 30 to 32): term taxonomy IDs of the IN and AND
     * clauses of the parsed tax query, with child terms expanded the way
     * WordPress expands them when it builds the SQL. Returns
     * [scope, reads_all]. No narrowing clause means it reads everything;
     * so does any clause that is only an exclusion, and any clause whose
     * terms cannot be resolved.
     *
     * `WP_Tax_Query::$queries` keeps the clauses as the caller wrote them
     * (core transforms a copy when it builds the SQL), so each clause is
     * resolved here with the same steps as `WP_Tax_Query::clean_query()`.
     *
     * @return array{0: list<int>, 1: bool}
     */
    public static function term_scope(WP_Query $query): array
    {
        $tax_query = $query->tax_query;
        if (! $tax_query instanceof \WP_Tax_Query || $tax_query->queries === []) {
            return [[], true];
        }
        $scope = self::scope_of_group($tax_query, $tax_query->queries, $tax_query->relation);
        if ($scope === null) {
            return [[], true];
        }
        sort($scope);
        return [array_values(array_unique($scope)), false];
    }

    /**
     * Scope of a nested group, or null when the group reads everything.
     *
     * @param array<mixed> $group
     *
     * @return list<int>|null
     */
    private static function scope_of_group(\WP_Tax_Query $tax_query, array $group, string $relation): ?array
    {
        $relation = strtoupper($group['relation'] ?? $relation) === 'OR' ? 'OR' : 'AND';
        $narrowed = [];
        $any_all  = false;
        $children = 0;

        foreach ($group as $key => $clause) {
            if ($key === 'relation' || ! is_array($clause)) {
                continue;
            }
            $children++;
            $child = self::is_first_order_clause($clause) ? self::scope_of_clause($tax_query, $clause) : self::scope_of_group($tax_query, $clause, 'AND');
            if ($child === null) {
                $any_all = true;
            } else {
                $narrowed[] = $child;
            }
        }

        if ($children === 0 || $narrowed === []) {
            return null;
        }
        if ($relation === 'OR' && $any_all) {
            return null;
        }
        // AND: the union of the narrowing children is a safe superset.
        return array_merge(...$narrowed);
    }

    /**
     * @param array<mixed> $clause
     */
    private static function is_first_order_clause(array $clause): bool
    {
        return array_key_exists('terms', $clause) || array_key_exists('taxonomy', $clause) || array_key_exists('operator', $clause);
    }

    /**
     * Term taxonomy IDs one IN or AND clause reads, children included, or
     * null when it reads everything or cannot be resolved.
     *
     * @param array<mixed> $clause
     *
     * @return list<int>|null
     */
    private static function scope_of_clause(\WP_Tax_Query $tax_query, array $clause): ?array
    {
        $operator = strtoupper(is_string($clause['operator'] ?? null) ? $clause['operator'] : 'IN');
        if ($operator !== 'IN' && $operator !== 'AND') {
            return null;
        }
        $taxonomy = is_string($clause['taxonomy'] ?? null) ? $clause['taxonomy'] : '';
        $field    = is_string($clause['field'] ?? null) && $clause['field'] !== '' ? $clause['field'] : 'term_id';
        $clause['operator'] = $operator;
        $clause['field']    = $field;
        $clause['taxonomy'] = $taxonomy;

        if ($taxonomy === '') {
            if ($field !== 'term_taxonomy_id') {
                return null;
            }
            $clause['include_children'] = false;
        } elseif (! taxonomy_exists($taxonomy)) {
            return null;
        }

        $terms = $clause['terms'] ?? [];
        if ($field === 'slug' || $field === 'name') {
            $clause['terms'] = array_values(array_unique(array_map('strval', array_filter((array) $terms, 'is_scalar'))));
        } else {
            $clause['terms'] = wp_parse_id_list($terms);
        }
        if ($clause['terms'] === []) {
            return null;
        }

        // Same steps as WP_Tax_Query::clean_query(): expand children by
        // term ID, then convert everything to term taxonomy IDs.
        if ($taxonomy !== '' && is_taxonomy_hierarchical($taxonomy) && (bool) ($clause['include_children'] ?? true)) {
            $tax_query->transform_query($clause, 'term_id');
            if (! is_array($clause) || ($clause['field'] ?? '') !== 'term_id') {
                return null;
            }
            $expanded = [];
            foreach ((array) $clause['terms'] as $term_id) {
                $kids = get_term_children((int) $term_id, $taxonomy);
                if (! is_array($kids)) {
                    return null;
                }
                $expanded   = array_merge($expanded, $kids);
                $expanded[] = (int) $term_id;
            }
            $clause['terms'] = $expanded;
        }

        $tax_query->transform_query($clause, 'term_taxonomy_id');
        if (! is_array($clause) || ($clause['field'] ?? '') !== 'term_taxonomy_id') {
            return null;
        }
        $ids = [];
        foreach ((array) $clause['terms'] as $term) {
            if (is_numeric($term) && (int) $term > 0) {
                $ids[] = (int) $term;
            }
        }
        return $ids === [] ? null : $ids;
    }

    private function context_holds(Signature $signature): bool
    {
        if (is_string($signature->context)) {
            return $this->contexts[$signature->context] ??= Contexts::evaluate($signature->context);
        }
        try {
            return (bool) ($signature->context)();
        } catch (Throwable $e) {
            $this->broken[$signature->name] = true;
            $this->logger->error(
                'callback:' . $signature->name,
                sprintf('context callback of signature "%s" threw %s; signature disabled for this request.', $signature->name, $e::class)
            );
            return false;
        }
    }

    private function call_match(Signature $signature, WP_Query $query): bool
    {
        $match = $signature->match;
        if ($match === null) {
            return true;
        }
        try {
            return (bool) $match($query);
        } catch (Throwable $e) {
            $this->broken[$signature->name] = true;
            $this->logger->error(
                'callback:' . $signature->name,
                sprintf('match callback of signature "%s" threw %s; signature disabled for this request.', $signature->name, $e::class)
            );
            return false;
        }
    }
}
