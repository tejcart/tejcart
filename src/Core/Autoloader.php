<?php
/**
 * TejCart PSR-4 Autoloader
 *
 * Replaces Composer autoloading for WordPress.org distribution.
 *
 * @package TejCart\Core
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PSR-4 autoloader for the TejCart namespace.
 *
 * Maps the TejCart\ namespace prefix to the src/ directory.
 */
class Autoloader {
    /**
     * Classes the autoloader could not resolve to a file under `src/`,
     * keyed by FQCN with the attempted path as the value. Bundled
     * modules (`modules/<slug>/`) and sibling addons register their own
     * autoloaders for `TejCart\Shipping_Plugin\…`, `TejCart\Returns\…`,
     * etc., so a miss in core is not a failure on its own — the next
     * autoloader in the chain may succeed. We therefore record misses
     * here and only emit a diagnostic at shutdown for classes that
     * remained unresolved after every registered autoloader had its
     * turn.
     *
     * @var array<string, string>
     */
    private static array $unresolved = array();

    /**
     * Whether the shutdown diagnostic flusher has been registered.
     */
    private static bool $shutdown_registered = false;

    /**
     * Register the autoloader with SPL.
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload a class file based on its fully qualified name.
     *
     * @param string $class The fully qualified class name.
     */
    public static function autoload( $class ) {
        $prefix = 'TejCart\\';

        // Audit M-32 (Core F-011): switched from strncasecmp to strncmp.
        // Case-insensitive matching let lowercase `\tejcart\core\...`
        // pass the prefix gate but the file path resolved to a
        // non-existent lowercase directory on Linux. PSR-4 conformant
        // autoloaders match case-sensitively.
        if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
            return;
        }

        $relative_class = substr( $class, strlen( $prefix ) );

        $file = TEJCART_PLUGIN_DIR . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;

            // Belt-and-braces: a corrupt clone (truncated download, FTP
            // upload that dropped the bottom of the file, mangled CRLF
            // conversion) can leave the file present but missing its
            // class declaration. PHP's autoload contract is satisfied
            // either way, so detect-and-log keeps the fatal that
            // follows ("Class X not found") triageable in WP_DEBUG.
            if ( ! class_exists( $class, false ) && ! interface_exists( $class, false ) && ! trait_exists( $class, false ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $msg = sprintf(
                        '[Autoloader] Required %s but %s was not declared — file may be truncated or stale (size=%d).',
                        $file,
                        $class,
                        (int) @filesize( $file )
                    );
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( $msg, 'error' );
                    } elseif ( function_exists( 'error_log' ) ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Bootstrap-time fallback: tejcart_log isn't available until plugin init runs.
                        error_log( $msg );
                    }
                }
            }
            return;
        }

        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        // Defer the "not loadable" diagnostic until shutdown: another
        // registered autoloader (the shipping / returns / disputes /
        // analytics / order-tracking module autoloaders, or an addon's)
        // may resolve the class on the next link in the chain. Logging
        // synchronously here would spam the debug log with one line per
        // sibling-module class on every request.
        self::$unresolved[ $class ] = $file;

        if ( self::$shutdown_registered || ! function_exists( 'register_shutdown_function' ) ) {
            return;
        }
        self::$shutdown_registered = true;
        register_shutdown_function( array( __CLASS__, 'flush_unresolved' ) );
    }

    /**
     * Emit one diagnostic per class that no autoloader managed to
     * resolve during this request. Called from a shutdown handler.
     */
    public static function flush_unresolved(): void {
        if ( empty( self::$unresolved ) ) {
            return;
        }

        foreach ( self::$unresolved as $class => $file ) {
            if ( class_exists( $class, false ) || interface_exists( $class, false ) || trait_exists( $class, false ) ) {
                continue;
            }
            $msg = sprintf(
                '[Autoloader] Class %s not loadable: file not found at %s',
                $class,
                $file
            );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( $msg, 'error' );
            } elseif ( function_exists( 'error_log' ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Shutdown-time fallback when tejcart_log was never built (very early fatal).
                error_log( $msg );
            }
        }
        self::$unresolved = array();
    }
}
