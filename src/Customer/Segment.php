<?php
/**
 * Customer segment model and repository.
 *
 * Manages auto-segments (derived from RFM scores) and admin-defined
 * custom segments (rule-based, stored in tejcart_customer_segments).
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Segment {

    /**
     * Auto-segment definitions — slug, label, description, color.
     *
     * These are assigned by {@see RFM_Scorer::assign_segment()} during
     * the nightly rebuild. They don't have rows in the segments table.
     */
    public const AUTO_SEGMENTS = array(
        'vip'     => array(
            'label'       => 'VIP',
            'description' => 'High recency, frequency, and spend (R≥4, F≥4, M≥4).',
            'color'       => '#7c3aed',
        ),
        'active'  => array(
            'label'       => 'Active',
            'description' => 'Regular purchasers with recent activity (R≥3, F≥2).',
            'color'       => '#059669',
        ),
        'new'     => array(
            'label'       => 'New',
            'description' => 'Recent first-time buyers or customers with no orders.',
            'color'       => '#2563eb',
        ),
        'at-risk' => array(
            'label'       => 'At Risk',
            'description' => 'Previously active but cooling off (R≤2, F≥3).',
            'color'       => '#d97706',
        ),
        'churned' => array(
            'label'       => 'Churned',
            'description' => 'Not seen in a long time despite repeat history (R=1, F≥2).',
            'color'       => '#dc2626',
        ),
    );

    /**
     * Get a single auto-segment definition by slug.
     *
     * @return array{label:string, description:string, color:string}|null
     */
    public static function get_auto_segment( string $slug ): ?array {
        return self::AUTO_SEGMENTS[ $slug ] ?? null;
    }

    /**
     * Get all custom segments from the database.
     *
     * @return array[] Rows from tejcart_customer_segments.
     */
    public static function get_custom_segments(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customer_segments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC, name ASC",
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Get a custom segment by ID.
     *
     * @return array|null
     */
    public static function get_custom_segment( int $id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customer_segments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * Create a new custom segment.
     *
     * @param string $name     Display name.
     * @param string $slug     URL-safe slug (unique).
     * @param array  $rules    Array of rule definitions.
     * @param int    $priority Sort priority (lower = first).
     * @return int|false Inserted ID or false on failure.
     */
    public static function create( string $name, string $slug, array $rules, int $priority = 10 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customer_segments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            $table,
            array(
                'name'     => $name,
                'slug'     => $slug,
                'type'     => 'custom',
                'rules'    => wp_json_encode( $rules ),
                'priority' => $priority,
                'status'   => 'active',
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        return false !== $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update a custom segment.
     *
     * @return bool True on success.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'tejcart_customer_segments';
        $allowed = array( 'name', 'slug', 'rules', 'priority', 'status' );
        $set     = array();
        $formats = array();

        foreach ( $allowed as $col ) {
            if ( ! array_key_exists( $col, $data ) ) {
                continue;
            }
            $val = $data[ $col ];
            if ( 'rules' === $col && is_array( $val ) ) {
                $val = wp_json_encode( $val );
            }
            $set[ $col ]  = $val;
            $formats[]    = 'priority' === $col ? '%d' : '%s';
        }

        if ( empty( $set ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update( $table, $set, array( 'id' => $id ), $formats, array( '%d' ) );

        return false !== $result;
    }

    /**
     * Delete a custom segment.
     *
     * @return bool True on success.
     */
    public static function delete( int $id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customer_segments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

        return false !== $result;
    }

    /**
     * Count customers matching an auto-segment slug.
     *
     * @return int
     */
    public static function count_auto_segment( string $slug ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE segment = %s", $slug )
        );
    }

    /**
     * Revenue breakdown per auto-segment (total LTV in minor units).
     *
     * @return array<string, array{count:int, revenue_minor:int}>
     */
    public static function segment_summary(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            "SELECT segment,
                    COUNT(*) AS customer_count,
                    COALESCE(SUM(ltv_minor_units), 0) AS total_revenue
             FROM {$table}
             WHERE segment IS NOT NULL AND segment != ''
             GROUP BY segment
             ORDER BY total_revenue DESC",
            ARRAY_A
        );

        $summary = array();
        foreach ( (array) $rows as $row ) {
            $summary[ $row['segment'] ] = array(
                'count'         => (int) $row['customer_count'],
                'revenue_minor' => (int) $row['total_revenue'],
            );
        }

        return $summary;
    }

    /**
     * Count customers matching a custom segment's rules.
     *
     * @param array $rules Rule definitions (decoded JSON).
     * @return int
     */
    public static function count_custom_segment( array $rules ): int {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_customers';
        $result = Segment_Rules::build_where_clause( $rules );

        if ( '' === $result['where'] ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE " . $result['where'],
                ...$result['params']
            )
        );
    }

    /**
     * Get customer IDs matching a custom segment's rules.
     *
     * @param array $rules Rule definitions.
     * @param int   $limit Max results.
     * @return int[]
     */
    public static function get_custom_segment_customer_ids( array $rules, int $limit = 1000 ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_customers';
        $result = Segment_Rules::build_where_clause( $rules );

        if ( '' === $result['where'] ) {
            return array();
        }

        $params   = $result['params'];
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE " . $result['where'] . ' LIMIT %d',
                ...$params
            )
        );

        return array_map( 'intval', $ids );
    }
}
