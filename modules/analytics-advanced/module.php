<?php
/**
 * TejCart Store Insights module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `analytics-advanced` toggle is enabled. Provides cohort analysis,
 * customer lifetime value calculation, segment-level revenue dashboards,
 * trend charts, and CSV/PDF export for all reports.
 *
 * @package TejCart\Analytics_Advanced
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_ANALYTICS_ADVANCED_FILE' ) ) {
    define( 'TEJCART_ANALYTICS_ADVANCED_FILE',    __FILE__ );
    define( 'TEJCART_ANALYTICS_ADVANCED_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_ANALYTICS_ADVANCED_URL',     plugin_dir_url( __FILE__ ) );
    define( 'TEJCART_ANALYTICS_ADVANCED_VERSION', '0.1.0' );
}

if ( ! defined( 'TEJCART_ANALYTICS_ADVANCED_DB_VERSION_OPTION' ) ) {
    define( 'TEJCART_ANALYTICS_ADVANCED_DB_VERSION_OPTION', 'tejcart_analytics_advanced_db_version' );
    define( 'TEJCART_ANALYTICS_ADVANCED_DB_VERSION',        '1.0.0' );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Analytics_Advanced\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_ANALYTICS_ADVANCED_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

// Register this module's tables on the multisite drop list so a deleted
// sub-site has its analytics tables cleaned up. The wpmu_drop_tables list in
// tejcart.php is explicit (no prefix wildcard), so modules must self-append
// here or their tables leak on sub-site deletion. Registered at include time
// (not inside tejcart_init) so the filter is present during wp_delete_site
// even when tejcart_init has not fired.
add_filter( 'tejcart_drop_tables', static function ( array $tables ): array {
    $tables[] = 'tejcart_cohorts';
    $tables[] = 'tejcart_cohort_retention';
    $tables[] = 'tejcart_customer_ltv';
    return $tables;
} );

if ( ! function_exists( 'tejcart_analytics_advanced_install' ) ) {
    function tejcart_analytics_advanced_install(): void {
        \TejCart\Analytics_Advanced\Schema::install();
    }
}

if ( ! function_exists( 'tejcart_analytics_advanced_disable' ) ) {
    function tejcart_analytics_advanced_disable(): void {
        $hooks = array(
            'tejcart_analytics_advanced_rebuild_cohorts',
            'tejcart_analytics_advanced_incremental',
            'tejcart_analytics_advanced_rebuild_chunk',
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
    if ( ! class_exists( '\\TejCart\\Analytics_Advanced\\Analytics_Advanced' ) ) {
        return;
    }
    \TejCart\Analytics_Advanced\Schema::maybe_upgrade();
    \TejCart\Analytics_Advanced\Analytics_Advanced::init();
}, 20 );
