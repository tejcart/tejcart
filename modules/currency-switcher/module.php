<?php
/**
 * TejCart Currency Switcher module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `currency-switcher` module toggle is enabled. Adds multi-currency
 * display with hourly auto-refreshed exchange rates, IP geolocation,
 * psychological-pricing rounding, per-currency payment-gateway
 * filtering, and order-level FX metadata for refund accuracy.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_CSW_FILE' ) ) {
    define( 'TEJCART_CSW_FILE',    __FILE__ );
    define( 'TEJCART_CSW_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_CSW_URL',     plugin_dir_url( __FILE__ ) );
    define( 'TEJCART_CSW_VERSION', '1.0.0' );
}

/**
 * Scoped autoloader for `TejCart\Currency_Switcher\*`. Falls through
 * for any unknown class so core's autoloader still gets a look.
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Currency_Switcher\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_CSW_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

/**
 * Module installer — runs on first toggle-ON via Module_Manager.
 *
 * Bumping `tejcart_csw_db_version` is the cue for future installer
 * tasks (option migrations etc.); we keep the install footprint
 * deliberately small because everything is stored in WP options.
 */
function tejcart_currency_switcher_install(): void {
    if ( false === get_option( 'tejcart_csw_activation_time', false ) ) {
        add_option( 'tejcart_csw_activation_time', time(), '', false );
    }
    update_option( 'tejcart_csw_db_version', TEJCART_CSW_VERSION, false );
}

/**
 * Module disable callback — runs when Module_Manager flips the toggle
 * OFF. Mirrors the deactivation hook so the hourly rate-refresh cron is
 * not left scheduled (and silently firing against the un-booted
 * module) after the merchant disables currency switching.
 */
function tejcart_currency_switcher_disable(): void {
    $timestamp = wp_next_scheduled( 'tejcart_csw_update_rates_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'tejcart_csw_update_rates_cron' );
    }
    // Belt and braces — clear every queued occurrence in case the
    // schedule has drifted across multiple events.
    wp_clear_scheduled_hook( 'tejcart_csw_update_rates_cron' );
}

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Currency_Switcher\\Plugin' ) ) {
        return;
    }
    \TejCart\Currency_Switcher\Plugin::instance()->boot();
}, 20 );

// Cron must be registered at WP init even before tejcart_init so the
// schedule is wired during the first request after enabling the module.
add_action( 'init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Currency_Switcher\\API\\Cron' ) ) {
        return;
    }
    \TejCart\Currency_Switcher\API\Cron::schedule();
} );

add_action( 'tejcart_csw_update_rates_cron', static function (): void {
    if ( ! class_exists( '\\TejCart\\Currency_Switcher\\API\\Cron' ) ) {
        return;
    }
    \TejCart\Currency_Switcher\API\Cron::run();
} );
