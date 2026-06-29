<?php
/**
 * TejCart Disputes module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `disputes` module toggle is enabled. Centralised chargeback /
 * dispute queue. Normalises PayPal CUSTOMER.DISPUTE.* webhooks (and
 * addon-supplied Stripe events) into one admin view, with manual
 * resolve actions, internal notes, evidence-due reminders, REST + CLI
 * surfaces, and CSV export.
 *
 * @package TejCart\Disputes
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_DISPUTES_FILE' ) ) {
    define( 'TEJCART_DISPUTES_FILE',    __FILE__ );
    define( 'TEJCART_DISPUTES_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_DISPUTES_VERSION', '0.2.0' );
}

if ( ! defined( 'TEJCART_DISPUTES_DB_VERSION_OPTION' ) ) {
    define( 'TEJCART_DISPUTES_DB_VERSION_OPTION', 'tejcart_disputes_db_version' );
    // 1.4.0: adds `tejcart_dispute_events` table for per-event audit log.
    // 1.3.0: backfill rows where outcome=RESOLVED_WITH_PAYOUT was
    //        previously mapped to status=closed; PayPal disbursed funds
    //        to the buyer, so the merchant-side status must be 'lost'.
    // 1.2.0: adds `notes`, `notes_updated_at`, `resolved_at`.
    // 1.1.0 added an index on `evidence_due` for the reminder cron.
    define( 'TEJCART_DISPUTES_DB_VERSION', '1.4.0' );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Tier2\\Disputes\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_DISPUTES_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

// Register this module's tables on the multisite drop list so a deleted
// sub-site has its dispute tables cleaned up. The wpmu_drop_tables list in
// tejcart.php is explicit (no prefix wildcard), so modules must self-append
// here or their tables leak on sub-site deletion. Registered at include time
// (not inside tejcart_init) so the filter is present during wp_delete_site
// even when tejcart_init has not fired.
add_filter( 'tejcart_drop_tables', static function ( array $tables ): array {
    $tables[] = 'tejcart_disputes';
    $tables[] = 'tejcart_dispute_events';
    return $tables;
} );

if ( ! function_exists( 'tejcart_disputes_install' ) ) {
    function tejcart_disputes_install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $sql = "CREATE TABLE {$prefix}tejcart_disputes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            gateway VARCHAR(40) NOT NULL DEFAULT '',
            external_id VARCHAR(190) NOT NULL,
            transaction_ref VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'open',
            reason VARCHAR(80) NOT NULL DEFAULT '',
            outcome VARCHAR(40) NOT NULL DEFAULT '',
            amount DECIMAL(20,4) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            evidence_due DATETIME DEFAULT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            notes LONGTEXT,
            notes_updated_at DATETIME DEFAULT NULL,
            payload LONGTEXT,
            PRIMARY KEY  (id),
            UNIQUE KEY gateway_external (gateway, external_id),
            KEY order_id (order_id),
            KEY status (status),
            KEY opened_at (opened_at),
            KEY evidence_due (evidence_due)
        ) {$charset};";

        dbDelta( $sql );

        $events_sql = "CREATE TABLE {$prefix}tejcart_dispute_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dispute_id BIGINT(20) UNSIGNED NOT NULL,
            event_type VARCHAR(60) NOT NULL DEFAULT '',
            source_event_id VARCHAR(190) NOT NULL DEFAULT '',
            status_before VARCHAR(40) NOT NULL DEFAULT '',
            status_after VARCHAR(40) NOT NULL DEFAULT '',
            occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ingested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor VARCHAR(100) NOT NULL DEFAULT '',
            payload LONGTEXT,
            PRIMARY KEY  (id),
            KEY dispute_id (dispute_id),
            KEY occurred_at (occurred_at)
        ) {$charset};";

        dbDelta( $events_sql );

        // Audit 08 #18 — module DB-version sentinel only read on
        // admin_init for schema upgrades. No reason to autoload.
        update_option( TEJCART_DISPUTES_DB_VERSION_OPTION, TEJCART_DISPUTES_DB_VERSION, false );

        if ( class_exists( '\\TejCart\\Tier2\\Disputes\\Capabilities' ) ) {
            \TejCart\Tier2\Disputes\Capabilities::install();
        }
    }

    function tejcart_disputes_maybe_upgrade(): void {
        $stored = (string) get_option( TEJCART_DISPUTES_DB_VERSION_OPTION, '0.0.0' );
        if ( $stored === TEJCART_DISPUTES_DB_VERSION ) {
            if ( class_exists( '\\TejCart\\Tier2\\Disputes\\Capabilities' ) ) {
                \TejCart\Tier2\Disputes\Capabilities::install();
            }
            return;
        }

        // Backfill mis-mapped RESOLVED_WITH_PAYOUT rows once when
        // crossing the 1.3.0 boundary. PayPal disbursed funds to the
        // buyer; the merchant-side status must be 'lost' so liability
        // dashboards and chargeback-rate metrics accurately count it.
        if ( version_compare( $stored, '1.3.0', '<' ) ) {
            global $wpdb;
            if ( isset( $wpdb ) && is_object( $wpdb ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}tejcart_disputes
                        SET status = %s
                      WHERE outcome = %s AND status = %s",
                    'lost',
                    'RESOLVED_WITH_PAYOUT',
                    'closed'
                ) );
            }
        }

        tejcart_disputes_install();
    }

    // Toggle-off hook: clear the daily evidence reminder so it doesn't
    // wake against an un-booted module and silently misfire.
    function tejcart_disputes_disable(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( \TejCart\Tier2\Disputes\Evidence_Reminder::HOOK );
        }
    }
}

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Tier2\\Disputes\\Disputes' ) ) {
        return;
    }
    tejcart_disputes_maybe_upgrade();
    \TejCart\Tier2\Disputes\Disputes::init();
}, 20 );
