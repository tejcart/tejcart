<?php
/**
 * Search query engine.
 *
 * Runs FULLTEXT searches with per-column weight multipliers, then
 * falls back to Levenshtein-based fuzzy matching when the FULLTEXT
 * pass returns fewer than the requested number of results.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Search_Service {
    public const WEIGHT_TITLE      = 10;
    public const WEIGHT_SKU        = 8;
    public const WEIGHT_SHORT_DESC = 5;
    public const WEIGHT_DESC       = 2;
    public const WEIGHT_ATTRS      = 3;

    public const MIN_QUERY_LENGTH  = 2;
    public const MAX_RESULTS       = 10;
    public const FUZZY_CANDIDATE_LIMIT = 10000;

    private const CACHE_GROUP = 'tejcart_search';
    private const CACHE_TTL   = 120;

    private Search_Index $index; // @phpstan-ignore property.onlyWritten

    private int $weight_title;
    private int $weight_sku;
    private int $weight_short_desc;
    private int $weight_desc;
    private int $weight_attrs;
    private int $min_query_length;
    private int $max_results;

    public function __construct( Search_Index $index ) {
        $this->index = $index;

        $opt = static fn( string $key, int $default ): int =>
            function_exists( 'get_option' ) ? (int) get_option( 'tejcart_' . $key, $default ) : $default;

        $this->weight_title      = $opt( 'search_weight_title', self::WEIGHT_TITLE );
        $this->weight_sku        = $opt( 'search_weight_sku', self::WEIGHT_SKU );
        $this->weight_short_desc = $opt( 'search_weight_short_desc', self::WEIGHT_SHORT_DESC );
        $this->weight_desc       = $opt( 'search_weight_desc', self::WEIGHT_DESC );
        $this->weight_attrs      = $opt( 'search_weight_attrs', self::WEIGHT_ATTRS );
        $this->min_query_length  = $opt( 'search_min_chars', self::MIN_QUERY_LENGTH );
        $this->max_results       = $opt( 'search_max_results', self::MAX_RESULTS );
    }

    /**
     * Search products with weighted scoring and fuzzy fallback.
     *
     * @param string $query   Raw user query.
     * @param int    $limit   Max results.
     * @param string $status  Product status filter (default 'publish').
     * @return list<array{id: int, name: string, sku: string, price: string, sale_price: ?string, image_id: ?int, slug: string, relevance: float}>
     */
    public function search( string $query, ?int $limit = null, string $status = 'publish' ): array {
        if ( null === $limit ) {
            $limit = $this->max_results;
        }
        $query = trim( $query );
        if ( mb_strlen( $query ) < $this->min_query_length ) {
            return array();
        }

        $hide_oos = class_exists( '\\TejCart\\Product\\Stock_Display' )
            && \TejCart\Product\Stock_Display::hide_out_of_stock();

        $cache_key = self::cache_prefix() . 'sq_' . md5( $query . '|' . $limit . '|' . $status . '|' . ( $hide_oos ? '1' : '0' ) );

        if ( function_exists( 'wp_cache_get' ) ) {
            $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
            if ( false !== $cached && is_array( $cached ) ) {
                return array_slice( $cached, 0, $limit );
            }
        }

        $results = $this->fulltext_search( $query, $limit, $status, $hide_oos );

        if ( count( $results ) < $limit ) {
            $existing_ids = array_column( $results, 'id' );
            $fuzzy        = $this->fuzzy_search( $query, $limit - count( $results ), $status, $existing_ids, $hide_oos );
            $results      = array_merge( $results, $fuzzy );
        }

        $results = array_slice( $results, 0, $limit );

        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );
        }

        return $results;
    }

    /**
     * Flush the search result cache.
     */
    public static function flush_cache(): void {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            $flushed = wp_cache_flush_group( self::CACHE_GROUP );
            if ( $flushed ) {
                return;
            }
        }

        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( 'last_changed', (string) microtime(), self::CACHE_GROUP );
        }
    }

    /**
     * Get the cache-key prefix (includes a generation counter so that
     * invalidation works even when the object-cache backend does not
     * support group flushing).
     */
    private static function cache_prefix(): string {
        if ( ! function_exists( 'wp_cache_get' ) ) {
            return '';
        }

        $last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );
        if ( false === $last_changed ) {
            $last_changed = (string) microtime();
            wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
        }

        return $last_changed . ':';
    }

    /**
     * FULLTEXT Boolean-mode search with per-column weights.
     *
     * @return list<array{id: int, name: string, sku: string, price: string, sale_price: ?string, image_id: ?int, slug: string, relevance: float}>
     */
    private function fulltext_search( string $query, int $limit, string $status, bool $hide_oos ): array {
        global $wpdb;

        $index_table   = $wpdb->prefix . Search_Index::TABLE;
        $product_table = $wpdb->prefix . 'tejcart_products';

        $boolean_term = $this->to_boolean_query( $query );

        $stock_clause      = $hide_oos ? "AND p.stock_status != 'outofstock'" : '';
        $visibility_clause = "AND p.catalog_visibility IN ('visible','search','')";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.image_id, p.slug,
                    (MATCH(si.title) AGAINST(%s IN BOOLEAN MODE) * %d +
                     MATCH(si.sku) AGAINST(%s IN BOOLEAN MODE) * %d +
                     MATCH(si.short_description) AGAINST(%s IN BOOLEAN MODE) * %d +
                     MATCH(si.description) AGAINST(%s IN BOOLEAN MODE) * %d +
                     MATCH(si.attributes) AGAINST(%s IN BOOLEAN MODE) * %d) AS relevance
                FROM {$product_table} p
                JOIN {$index_table} si ON p.id = si.product_id
                WHERE p.status = %s
                  {$stock_clause}
                  {$visibility_clause}
                  AND MATCH(si.title, si.sku, si.short_description, si.description, si.attributes)
                      AGAINST(%s IN BOOLEAN MODE)
                ORDER BY relevance DESC
                LIMIT %d",
                $boolean_term,
                $this->weight_title,
                $boolean_term,
                $this->weight_sku,
                $boolean_term,
                $this->weight_short_desc,
                $boolean_term,
                $this->weight_desc,
                $boolean_term,
                $this->weight_attrs,
                $status,
                $boolean_term,
                $limit
            )
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map( array( $this, 'format_row' ), $rows );
    }

    /**
     * Levenshtein-based fuzzy search over product titles.
     *
     * Loads indexed titles up to FUZZY_CANDIDATE_LIMIT and finds
     * near-matches for each query word. Automatically skipped when
     * the candidate pool exceeds the limit.
     *
     * @note PHP's native levenshtein() operates on bytes, not Unicode
     *       codepoints. For UTF-8 catalogs with non-ASCII product names
     *       (accented, CJK), edit distance calculations may be inaccurate.
     *
     * @param list<int> $exclude_ids Products already matched by FULLTEXT.
     * @return list<array{id: int, name: string, sku: string, price: string, sale_price: ?string, image_id: ?int, slug: string, relevance: float}>
     */
    private function fuzzy_search( string $query, int $limit, string $status, array $exclude_ids, bool $hide_oos ): array {
        global $wpdb;

        if ( $limit <= 0 ) {
            return array();
        }

        $index_table   = $wpdb->prefix . Search_Index::TABLE;
        $product_table = $wpdb->prefix . 'tejcart_products';

        $stock_clause      = $hide_oos ? "AND p.stock_status != 'outofstock'" : '';
        $visibility_clause = "AND p.catalog_visibility IN ('visible','search','')";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$index_table} si
                 JOIN {$product_table} p ON p.id = si.product_id
                 WHERE p.status = %s {$stock_clause} {$visibility_clause}",
                $status
            )
        );

        if ( $candidate_count > self::FUZZY_CANDIDATE_LIMIT ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT si.product_id, si.title, si.sku
                 FROM {$index_table} si
                 JOIN {$product_table} p ON p.id = si.product_id
                 WHERE p.status = %s {$stock_clause} {$visibility_clause}
                 ORDER BY si.product_id ASC
                 LIMIT %d",
                $status,
                self::FUZZY_CANDIDATE_LIMIT
            )
        );

        if ( ! is_array( $candidates ) || empty( $candidates ) ) {
            return array();
        }

        $query_words = preg_split( '/\s+/u', mb_strtolower( $query ) );
        $query_words = array_filter( $query_words, static fn( string $w ) => mb_strlen( $w ) >= 2 );

        if ( empty( $query_words ) ) {
            return array();
        }

        $scored = array();

        // F-MODS-014: On large catalogs (up to FUZZY_CANDIDATE_LIMIT=10,000
        // products) the O(n×m×k) levenshtein loop can exhaust FastCGI
        // timeouts on constrained shared-hosting. Cap wall-time at 300 ms
        // (filterable) so the endpoint degrades gracefully rather than
        // hanging. Candidates processed before the deadline still contribute
        // to the scored set; the remainder are simply skipped.
        //
        // The default 300 ms is deliberately conservative — a 10k-product
        // store on a shared box typically stays well under 100 ms; this cap
        // only bites on severely under-provisioned hosts where skipping some
        // candidates is safer than timing out the whole request.
        $fuzzy_deadline = microtime( true ) + (float) apply_filters( 'tejcart_search_fuzzy_time_budget_seconds', 0.3 );

        foreach ( $candidates as $candidate ) {
            // F-MODS-014: Bail out when we've exhausted the time budget.
            if ( microtime( true ) > $fuzzy_deadline ) {
                break;
            }

            $pid = (int) $candidate->product_id;
            if ( in_array( $pid, $exclude_ids, true ) ) {
                continue;
            }

            $title_lower = mb_strtolower( $candidate->title );
            $title_words = preg_split( '/[\s\-_]+/u', $title_lower );
            $sku_lower   = mb_strtolower( $candidate->sku ?? '' );

            $total_score = 0;
            $matched     = 0;

            foreach ( $query_words as $qw ) {
                if ( $sku_lower === $qw ) {
                    $total_score += $this->weight_sku;
                    ++$matched;
                    continue;
                }

                $best_dist = PHP_INT_MAX;
                foreach ( $title_words as $tw ) {
                    if ( $tw === '' ) {
                        continue;
                    }
                    $safe_qw = mb_substr( $qw, 0, 255 );
                    $safe_tw = mb_substr( $tw, 0, 255 );
                    $dist    = levenshtein( $safe_qw, $safe_tw );
                    if ( $dist < $best_dist ) {
                        $best_dist = $dist;
                    }
                }

                $max_len   = max( mb_strlen( $qw ), 1 );
                $threshold = $max_len <= 4 ? 1 : (int) ceil( $max_len * 0.4 );

                if ( $best_dist <= $threshold ) {
                    $total_score += max( 1, $this->weight_title - $best_dist * 2 );
                    ++$matched;
                }
            }

            if ( $matched > 0 && $matched >= count( $query_words ) * 0.5 ) {
                $scored[ $pid ] = $total_score * ( $matched / count( $query_words ) );
            }
        }

        if ( empty( $scored ) ) {
            return array();
        }

        arsort( $scored );
        $top_ids = array_slice( array_keys( $scored ), 0, $limit );

        if ( empty( $top_ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $top_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, sku, price, sale_price, image_id, slug
                 FROM {$product_table}
                 WHERE id IN ({$placeholders})",
                ...$top_ids
            )
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $by_id = array();
        foreach ( $rows as $row ) {
            $formatted             = $this->format_row( $row );
            $formatted['relevance'] = $scored[ (int) $row->id ] ?? 0.0;
            $by_id[ (int) $row->id ] = $formatted;
        }

        $result = array();
        foreach ( $top_ids as $id ) {
            if ( isset( $by_id[ $id ] ) ) {
                $result[] = $by_id[ $id ];
            }
        }

        return $result;
    }

    /**
     * Convert user query to a MySQL BOOLEAN MODE expression.
     *
     * Splits on whitespace and appends `*` to each term for prefix
     * matching. Special characters are stripped.
     */
    private function to_boolean_query( string $query ): string {
        $sanitised = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $query );
        $words     = preg_split( '/\s+/', trim( $sanitised ) );
        $words     = array_filter( $words, static fn( string $w ) => mb_strlen( $w ) >= 2 );

        if ( empty( $words ) ) {
            return '';
        }

        return '+' . implode( '* +', $words ) . '*';
    }

    /**
     * Format a product DB row for the API response.
     *
     * @return array{id: int, name: string, sku: string, price: string, sale_price: ?string, image_id: ?int, slug: string, relevance: float}
     */
    private function format_row( object $row ): array {
        return array(
            'id'         => (int) $row->id,
            'name'       => (string) $row->name,
            'sku'        => (string) ( $row->sku ?? '' ),
            'price'      => (string) ( $row->price ?? '0' ),
            'sale_price' => isset( $row->sale_price ) ? (string) $row->sale_price : null,
            'image_id'   => isset( $row->image_id ) ? (int) $row->image_id : null,
            'slug'       => (string) ( $row->slug ?? '' ),
            'relevance'  => (float) ( $row->relevance ?? 0.0 ),
        );
    }
}
