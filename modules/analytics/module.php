<?php
/**
 * TejCart Tracking & Pixels module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `analytics` module toggle is enabled. Server-side analytics +
 * marketing dispatcher with drivers for Google Analytics 4, Meta
 * Conversions API, Klaviyo, and Mailchimp. All drivers are disabled
 * by default and only contact a third party once the merchant enters
 * credentials.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_ANALYTICS_FILE' ) ) {
    define( 'TEJCART_ANALYTICS_FILE',    __FILE__ );
    define( 'TEJCART_ANALYTICS_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_ANALYTICS_URL',     plugin_dir_url( __FILE__ ) );
    define( 'TEJCART_ANALYTICS_VERSION', '1.0.0' );
}

/**
 * Scoped autoloader for the module. Only resolves classes under the
 * `TejCart\Analytics\` namespace; everything else is left to TejCart
 * core's autoloader (or any other registered handler).
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Analytics\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_ANALYTICS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Analytics\\Analytics_Bootstrap' ) ) {
        return;
    }
    \TejCart\Analytics\Analytics_Bootstrap::instance()->init();
}, 20 );
