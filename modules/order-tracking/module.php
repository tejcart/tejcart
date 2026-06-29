<?php
/**
 * TejCart Order Tracking module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `order-tracking` module toggle is enabled. Adds shipment tracking
 * numbers and carrier deep-links to TejCart orders, admin AJAX + REST
 * management, customer-facing rate-limited lookup, WP-CLI bulk import,
 * and GDPR exporter/eraser integration.
 *
 * @package TejCart\Order_Tracking
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_ORDER_TRACKING_FILE' ) ) {
    define( 'TEJCART_ORDER_TRACKING_FILE',    __FILE__ );
    define( 'TEJCART_ORDER_TRACKING_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_ORDER_TRACKING_VERSION', '1.3.0' );
}

if ( ! defined( 'TEJCART_ORDER_TRACKING_DB_VERSION_OPTION' ) ) {
    define( 'TEJCART_ORDER_TRACKING_DB_VERSION_OPTION', 'tejcart_order_tracking_db_version' );
    define( 'TEJCART_ORDER_TRACKING_DB_VERSION',        '2.3.0' );
}

// F-MODL-002 (DEFERRED — NEEDS HUMAN REVIEW): The autoloader prefix and all 104
// class-level namespace declarations in src/ still use the legacy
// TejCart\Tier2\Order_Tracking\ prefix. The full rename to TejCart\Order_Tracking\
// requires updating every file in src/, the Module_Manager install entry, and
// tests/Unit/Modules/OrderTracking/ — a change too large to validate without PHPUnit.
// Tracked in the audit PR for a dedicated follow-up. Do NOT add new files under the
// Tier2\Order_Tracking namespace; any new code should target TejCart\Order_Tracking\.
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Tier2\\Order_Tracking\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_ORDER_TRACKING_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

// Register the module's tables for multisite drop on sub-site deletion.
// Mirrors modules/shipping/module.php and modules/search/module.php — must
// be added at include time (NOT inside tejcart_init) so the filter is
// present during wp_delete_site / wpmu_drop_tables, which runs without
// booting the module. The core handler (tejcart.php) passes unprefixed
// table names; the $prefix 2nd arg is unused here.
add_filter( 'tejcart_drop_tables', static function ( array $tables ): array {
    $tables[] = 'tejcart_shipments';
    $tables[] = 'tejcart_shipment_audit';
    return $tables;
} );

// Make the module's bundled templates resolvable through
// tejcart_get_template(). Without this, requests like
// `order-tracking/track-order.php` fall through to the CORE templates dir
// (where they don't exist), so the bundled files would never be found.
// Child/parent-theme overrides at `tejcart/<name>` are still checked first.
add_filter( 'tejcart_template_paths', static function ( array $paths, string $template_name ): array {
    $paths[] = TEJCART_ORDER_TRACKING_DIR . 'templates/' . $template_name;
    return $paths;
}, 10, 2 );

if ( ! function_exists( 'tejcart_order_tracking_install' ) ) {
    /**
     * Install callback invoked by Module_Manager on toggle-ON and on
     * plugin activation when the module is already enabled. Idempotent.
     *
     * F-MODL-006: Provides a named function so Module_Manager can reference
     * it consistently (matching the convention of every other module). Also
     * calls Capability::install() so the scoped cap is granted to
     * administrator and tejcart_shop_manager roles on first toggle-ON.
     */
    function tejcart_order_tracking_install(): void {
        if ( class_exists( '\\TejCart\\Tier2\\Order_Tracking\\Schema_Migrator' ) ) {
            \TejCart\Tier2\Order_Tracking\Schema_Migrator::install();
        }
        if ( class_exists( '\\TejCart\\Tier2\\Order_Tracking\\Capability' ) ) {
            \TejCart\Tier2\Order_Tracking\Capability::install();
        }
    }
}

if ( ! function_exists( 'tejcart_order_tracking_disable' ) ) {
    /**
     * Disable callback invoked when the toggle flips OFF.
     *
     * F-MODL-006: Without this, the retention cron (tejcart_order_tracking_retention)
     * and the carrier polling job (tejcart_order_tracking_poll) continue firing against
     * an un-booted module — the hook callbacks are never registered so the jobs fire
     * and immediately do nothing, but they still consume the Action Scheduler queue and
     * WP-Cron slots. Draining them on toggle-OFF is consistent with every other module.
     * Data is intentionally retained; only the scheduled jobs are cleared.
     */
    function tejcart_order_tracking_disable(): void {
        $hooks = array(
            'tejcart_order_tracking_retention',
            'tejcart_order_tracking_poll',
        );
        foreach ( $hooks as $hook ) {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( $hook, array(), 'tejcart' );
            }
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( $hook );
            }
        }
    }
}

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Tier2\\Order_Tracking\\Order_Tracking' ) ) {
        return;
    }
    \TejCart\Tier2\Order_Tracking\Schema_Migrator::maybe_upgrade();
    \TejCart\Tier2\Order_Tracking\Order_Tracking::init();
}, 20 );

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
    add_action( 'cli_init', static function (): void {
        if ( ! class_exists( '\\TejCart\\Tier2\\Order_Tracking\\CLI_Command' ) ) {
            return;
        }
        \WP_CLI::add_command( 'tejcart tracking', '\\TejCart\\Tier2\\Order_Tracking\\CLI_Command' );
    } );
}
