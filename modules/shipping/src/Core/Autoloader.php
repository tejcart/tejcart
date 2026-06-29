<?php
/**
 * Hand-rolled PSR-4 autoloader.
 *
 * Mirrors the TejCart core autoloader pattern so the plugin can ship to
 * wordpress.org without a Composer runtime dependency.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Autoloader {
    private const PREFIX = 'TejCart\\Shipping_Plugin\\';

    public static function register(): void {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    public static function autoload( string $class ): void {
        if ( 0 !== strpos( $class, self::PREFIX ) ) {
            return;
        }

        $relative = substr( $class, strlen( self::PREFIX ) );
        $file     = TEJCART_SHIPPING_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
