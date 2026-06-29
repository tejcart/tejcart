<?php
/**
 * Schema migrator for the order-tracking shipments table.
 *
 * Owns the DDL and the version-gated upgrade path. dbDelta is idempotent,
 * but we still gate behind an option compare so steady-state requests
 * skip the work.
 *
 * Schema notes:
 *  - `tracking_url` is VARCHAR(2048) so it can be indexed and stays in
 *    the row format MySQL prefers; the previous TEXT column was
 *    pointlessly large for what is in practice a sub-200-char URL.
 *  - `(order_id, tracking_number)` is UNIQUE so admin double-submits
 *    cannot duplicate. We deduplicate existing rows during migration.
 *  - `(carrier, tracking_number(64))` indexes reverse lookups for
 *    webhook receivers ("which order does this AWB belong to?") without
 *    bloating storage on a 190-char column.
 *  - `created_by` and `updated_at` provide audit trail for high-compliance
 *    merchants. `deleted_at` enables soft-delete; reads filter it out.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Schema_Migrator {
    public const TABLE       = 'tejcart_shipments';
    public const AUDIT_TABLE = 'tejcart_shipment_audit';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function audit_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::AUDIT_TABLE;
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::deduplicate_pre_unique_index();

        $charset = $wpdb->get_charset_collate();
        $table   = self::table_name();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            carrier VARCHAR(40) NOT NULL DEFAULT '',
            service VARCHAR(80) NOT NULL DEFAULT '',
            tracking_number VARCHAR(190) NOT NULL DEFAULT '',
            tracking_url VARCHAR(2048) NOT NULL DEFAULT '',
            label_url VARCHAR(2048) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            cost DECIMAL(20,4) NOT NULL DEFAULT 0,
            shipped_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            meta LONGTEXT DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_tracking (order_id, tracking_number),
            KEY order_status (order_id, status),
            KEY status_shipped (status, shipped_at),
            KEY status_delivered (status, delivered_at),
            KEY carrier_status (carrier, status),
            KEY carrier_tracking (carrier, tracking_number(64)),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            KEY deleted_at (deleted_at)
        ) {$charset};";

        \dbDelta( $sql );

        $audit_table = self::audit_table_name();
        $audit_sql   = "CREATE TABLE {$audit_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            event VARCHAR(40) NOT NULL DEFAULT '',
            actor_id BIGINT(20) NOT NULL DEFAULT 0,
            actor_kind VARCHAR(20) NOT NULL DEFAULT 'user',
            payload LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY shipment_id (shipment_id),
            KEY order_id (order_id),
            KEY event_created (event, created_at)
        ) {$charset};";
        \dbDelta( $audit_sql );

        // Audit 08 #18 — sentinel read only on admin_init.
        update_option( TEJCART_ORDER_TRACKING_DB_VERSION_OPTION, TEJCART_ORDER_TRACKING_DB_VERSION, false );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( TEJCART_ORDER_TRACKING_DB_VERSION_OPTION ) === TEJCART_ORDER_TRACKING_DB_VERSION ) {
            return;
        }
        self::install();
    }

    /**
     * Drop duplicate `(order_id, tracking_number)` rows before adding the
     * UNIQUE index. Without this step, dbDelta will silently fail to add
     * the index on sites already running v0.1.0 with duplicates in place.
     *
     * Keeps the lowest-id row of each duplicate group (the original) and
     * deletes the rest. Idempotent — a no-op once the unique key exists.
     */
    private static function deduplicate_pre_unique_index(): void {
        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL/introspection on the module's own table; table identifier is a controlled internal value.
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $exists ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL on the module's own table; table identifier is a controlled internal value.
        $sql_dedupe = "DELETE t1 FROM {$table} t1 INNER JOIN {$table} t2 ON t1.order_id = t2.order_id AND t1.tracking_number = t2.tracking_number AND t1.id > t2.id WHERE t1.tracking_number <> ''";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL on the module's own table; table identifier is a controlled internal value.
        $wpdb->query( $sql_dedupe );
    }
}
