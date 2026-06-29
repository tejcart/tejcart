<?php
/**
 * Custom-table schema for TejCart Shipping.
 *
 * Stores purchased shipments — one row per label bought from a carrier.
 * Idempotent on version bump via dbDelta(); the migrator runs on every
 * `admin_init` (cheap when version matches) so file-level deploys
 * self-heal even when register_activation_hook didn't fire.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Schema {
    public const VERSION = '0.4.0';
    public const OPTION  = 'tejcart_shipping_db_version';

    public function table_name(): string {
        global $wpdb;
        return ( isset( $wpdb ) && property_exists( $wpdb, 'prefix' ) ? $wpdb->prefix : '' ) . 'tejcart_shipments';
    }

    public function maybe_install(): void {
        $current = get_option( self::OPTION, '' );
        if ( $current === self::VERSION ) {
            return;
        }
        $this->install();
        update_option( self::OPTION, self::VERSION, false );
    }

    public function install(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }

        if ( ! function_exists( 'dbDelta' ) ) {
            $maybe = ABSPATH . 'wp-admin/includes/upgrade.php';
            if ( is_readable( $maybe ) ) {
                require_once $maybe;
            }
        }

        $charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
        $table           = $this->table_name();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            carrier_id VARCHAR(64) NOT NULL,
            service_code VARCHAR(64) NOT NULL DEFAULT '',
            rate_id VARCHAR(128) NOT NULL DEFAULT '',
            tracking_number VARCHAR(128) NOT NULL DEFAULT '',
            label_url TEXT NULL,
            label_format VARCHAR(16) NOT NULL DEFAULT 'PDF',
            cost_cents INT NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            status VARCHAR(32) NOT NULL DEFAULT 'purchased',
            idempotency_key VARCHAR(128) NOT NULL DEFAULT '',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            voided_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_idem (idempotency_key),
            KEY idx_order (order_id),
            KEY idx_tracking (tracking_number),
            KEY idx_carrier (carrier_id, service_code)
        ) {$charset_collate};";

        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $sql );
        }
    }
}
