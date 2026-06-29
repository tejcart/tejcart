<?php
/**
 * TejCart Tax Providers module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `tax-providers` module toggle is enabled. Registers the bundled
 * live tax calculators (Stripe Tax, TaxJar, Avalara) onto the
 * `tejcart_tax_providers` filter consumed by
 * {@see \TejCart\Tax\Tax_Provider_Registry} and renders the
 * Settings -> Tax -> Providers admin sub-section. All drivers are
 * disabled by default and only contact a third party once the
 * merchant enters credentials.
 *
 * @package TejCart\Tax_Providers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_TAX_PROVIDERS_FILE' ) ) {
    define( 'TEJCART_TAX_PROVIDERS_FILE',    __FILE__ );
    define( 'TEJCART_TAX_PROVIDERS_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_TAX_PROVIDERS_URL',     plugin_dir_url( __FILE__ ) );
}

/**
 * Scoped autoloader for the module. Only resolves classes under the
 * `TejCart\Tax_Providers\` namespace; everything else is left to TejCart
 * core's autoloader (or any other registered handler).
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Tax_Providers\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_TAX_PROVIDERS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Tax_Providers\\Tax_Providers_Bootstrap' ) ) {
        return;
    }
    \TejCart\Tax_Providers\Tax_Providers_Bootstrap::instance()->init();
}, 20 );
