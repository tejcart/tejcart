<?php
/**
 * Tier-2 database schema.
 *
 * Owns the DDL for tables required by Tier-2 feature modules. All
 * operations run through dbDelta and are idempotent, so install() is
 * safe to call on every boot — dbDelta no-ops if the schema already
 * matches.
 *
 * @package TejCart\Tier2
 */

declare( strict_types=1 );

namespace TejCart\Tier2;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Schema {
    /**
     * Create / verify Tier-2 tables.
     *
     * Idempotent: dbDelta diffs the in-memory schema against what's on
     * disk and only issues ALTERs when they differ, so this can be
     * called on every plugin boot without a version cursor.
     */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $sql_coupon_meta = "CREATE TABLE {$prefix}tejcart_coupon_meta (
            meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            coupon_id BIGINT(20) UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL DEFAULT '',
            meta_value LONGTEXT,
            PRIMARY KEY  (meta_id),
            KEY coupon_id (coupon_id),
            KEY meta_key (meta_key)
        ) {$charset};";

        // `address` is LONGTEXT, NOT JSON: Address_Crypto encrypts the blob
        // at rest by default (Audit M-36) and the `tejc1:` ciphertext is not
        // valid JSON, so a JSON column would reject it via MariaDB's implicit
        // `CHECK (json_valid(address))`. Matches tejcart_orders / customers.
        $sql_addresses = "CREATE TABLE {$prefix}tejcart_addresses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            label VARCHAR(100) NOT NULL DEFAULT '',
            type VARCHAR(20) NOT NULL DEFAULT 'shipping',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            address LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY user_type (user_id, type)
        ) {$charset};";

        $sql_email_templates = "CREATE TABLE {$prefix}tejcart_email_templates (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email_id VARCHAR(64) NOT NULL,
            subject TEXT NOT NULL,
            heading TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY email_id (email_id)
        ) {$charset};";

        $sql_email_log = "CREATE TABLE {$prefix}tejcart_email_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email_id VARCHAR(64) NOT NULL,
            recipient VARCHAR(190) NOT NULL,
            subject TEXT NOT NULL,
            object_type VARCHAR(40) DEFAULT NULL,
            object_id BIGINT(20) UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error TEXT,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY email_id (email_id),
            KEY object (object_type, object_id),
            KEY sent_at (sent_at)
        ) {$charset};";

        $sql_abandoned_carts = "CREATE TABLE {$prefix}tejcart_abandoned_carts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total BIGINT NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            recovered_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            emails_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_email_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY email (email),
            KEY status_updated (status, updated_at),
            KEY emails_sent (emails_sent)
        ) {$charset};";

        dbDelta( $sql_coupon_meta );
        dbDelta( $sql_addresses );
        dbDelta( $sql_email_templates );
        dbDelta( $sql_email_log );
        dbDelta( $sql_abandoned_carts );
    }
}
