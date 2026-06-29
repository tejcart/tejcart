<?php
/**
 * AI Content SmartSuite module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `ai-content-smartsuite` toggle is enabled.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_AI_CONTENT_FILE' ) ) {
    define( 'TEJCART_AI_CONTENT_FILE',    __FILE__ );
    define( 'TEJCART_AI_CONTENT_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_AI_CONTENT_URL',     plugin_dir_url( __FILE__ ) );
    define( 'TEJCART_AI_CONTENT_TEXTDOMAIN', 'tejcart' );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\AI_Content_Smartsuite\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_AI_CONTENT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

if ( ! function_exists( 'tejcart_ai_content_smartsuite_install' ) ) {
    /**
     * Install callback invoked by Module_Manager on toggle-ON and on
     * plugin activation. Idempotent.
     */
    function tejcart_ai_content_smartsuite_install(): void {
        \TejCart\AI_Content_Smartsuite\Install\Installer::install();
    }
}

if ( ! function_exists( 'tejcart_ai_content_smartsuite_uninstall' ) ) {
    /**
     * Hard cleanup of all module-owned data. Invoked from the bundled
     * `modules/ai-content-smartsuite/uninstall.php` and from the core
     * plugin's uninstall path.
     */
    function tejcart_ai_content_smartsuite_uninstall(): void {
        \TejCart\AI_Content_Smartsuite\Uninstall\Cleanup::run();
    }
}

if ( ! function_exists( 'tejcart_ai_content_smartsuite_disable' ) ) {
    /**
     * Disable callback invoked by Module_Manager when the toggle flips
     * OFF. Cancels in-flight generation jobs so they don't fire later
     * against an un-booted module. Reuses Installer::deactivate() so
     * the toggle path mirrors the plugin-deactivation path.
     */
    function tejcart_ai_content_smartsuite_disable(): void {
        if ( class_exists( '\\TejCart\\AI_Content_Smartsuite\\Install\\Installer' ) ) {
            \TejCart\AI_Content_Smartsuite\Install\Installer::deactivate();
        }
    }
}

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\AI_Content_Smartsuite\\Plugin' ) ) {
        return;
    }
    \TejCart\AI_Content_Smartsuite\Plugin::instance()->boot();
}, 20 );
