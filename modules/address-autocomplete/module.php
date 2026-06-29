<?php
/**
 * TejCart Address Autocomplete module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `address-autocomplete` module toggle is enabled. Supplies a provider
 * config to the core `tejcart_address_autocomplete_config` filter and adds
 * the provider + API-key fields to Settings → Checkout. The actual
 * client-side autocomplete driver lives in core's checkout JS — this module
 * only opts that inert driver in and carries the merchant credentials, the
 * same way the `tax-providers` module owns its calculator credentials while
 * the registry lives in core.
 *
 * Nothing here contacts a third party until the merchant selects a provider
 * and enters an API key.
 *
 * @package TejCart\Address_Autocomplete
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_ADDRESS_AUTOCOMPLETE_FILE' ) ) {
    define( 'TEJCART_ADDRESS_AUTOCOMPLETE_FILE',    __FILE__ );
    define( 'TEJCART_ADDRESS_AUTOCOMPLETE_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_ADDRESS_AUTOCOMPLETE_URL',     plugin_dir_url( __FILE__ ) );
}

/**
 * Scoped autoloader for the module. Only resolves classes under the
 * `TejCart\Address_Autocomplete\` namespace; everything else is left to
 * TejCart core's autoloader (or any other registered handler).
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Address_Autocomplete\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_ADDRESS_AUTOCOMPLETE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Address_Autocomplete\\Address_Autocomplete_Bootstrap' ) ) {
        return;
    }
    \TejCart\Address_Autocomplete\Address_Autocomplete_Bootstrap::instance()->init();
}, 20 );
