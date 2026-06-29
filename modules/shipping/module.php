<?php
/**
 * TejCart Shipping module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the merchant
 * flips the `shipping` module toggle ON (OFF by default, in line with
 * every other bundled module). Real-time shipping rates with drivers
 * for FedEx, UPS, USPS, DHL, Royal Mail, Australia Post, Canada Post,
 * EasyPost, Shippo and many more — individual drivers stay disabled
 * until credentials are entered.
 *
 * @package TejCart\Shipping_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_SHIPPING_VERSION' ) ) {
    define( 'TEJCART_SHIPPING_VERSION', '0.5.0' );
    define( 'TEJCART_SHIPPING_PLUGIN_FILE', __FILE__ );
    define( 'TEJCART_SHIPPING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_SHIPPING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    define( 'TEJCART_SHIPPING_MIN_CORE', '1.0.0' );
}

require_once TEJCART_SHIPPING_PLUGIN_DIR . 'src/Core/Autoloader.php';
\TejCart\Shipping_Plugin\Core\Autoloader::register();

// F-CORE-011: register the module's own table on the multisite drop list so
// a deleted sub-site has its tejcart_shipments table cleaned up. The
// wpmu_drop_tables list in tejcart.php is explicit (no prefix wildcard), so
// modules must self-append here or their tables leak on sub-site deletion.
// Registered at include time (not inside tejcart_init) so the filter is
// present during wp_delete_site even when tejcart_init has not fired.
add_filter( 'tejcart_drop_tables', static function ( array $tables ): array {
    $tables[] = 'tejcart_shipments';
    return $tables;
} );

add_action( 'tejcart_init', static function ( $core ): void {
    \TejCart\Shipping_Plugin\Core\Plugin::instance()->boot( $core );
}, 20 );

/**
 * Module installer — runs on first toggle-ON via Module_Manager (and
 * again on plugin activation if the merchant had already enabled this
 * module on a previous install). Creates the shipments table AND grants
 * the manage_tejcart_shipping capability so the "Carriers" admin
 * submenu is visible to administrators / shop managers.
 */
function tejcart_shipping_module_install(): void {
    if ( class_exists( '\\TejCart\\Shipping_Plugin\\Core\\Schema' ) ) {
        ( new \TejCart\Shipping_Plugin\Core\Schema() )->install();
    }
    if ( class_exists( '\\TejCart\\Shipping_Plugin\\Core\\Capabilities' ) ) {
        \TejCart\Shipping_Plugin\Core\Capabilities::install();
    }
}

/**
 * Module disable callback — runs when the merchant flips the `shipping`
 * toggle OFF via Module_Manager. Without this, the recurring
 * `tejcart_shipping_poll_tracking` action keeps firing from the
 * Action Scheduler store after the module's classes are no longer
 * booted, doing surprise work (and erroring) on every tick. Stored
 * credentials and the shipments table are intentionally preserved so a
 * later re-enable picks up where the merchant left off; only the
 * background cron is torn down here.
 */
function tejcart_shipping_module_disable(): void {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( \TejCart\Shipping_Plugin\Core\Tracking_Poller::HOOK, array(), 'tejcart' );
    }
}

add_action( 'admin_init', static function (): void {
    if ( class_exists( '\\TejCart\\Shipping_Plugin\\Core\\Schema' ) ) {
        ( new \TejCart\Shipping_Plugin\Core\Schema() )->maybe_install();
    }
    // Self-heal for sites that picked up shipping before Capabilities::install()
    // was wired into the module installer; without this the Carriers submenu
    // is invisible because admins lack the manage_tejcart_shipping cap.
    if ( class_exists( '\\TejCart\\Shipping_Plugin\\Core\\Capabilities' ) ) {
        \TejCart\Shipping_Plugin\Core\Capabilities::install();
    }
} );

if ( defined( 'WP_CLI' ) && \WP_CLI && class_exists( '\\WP_CLI' ) ) {
    add_action( 'cli_init', static function (): void {
        if ( class_exists( '\\TejCart\\Shipping_Plugin\\Core\\Shipping_CLI' ) ) {
            // Namespaced under `tejcart-shipping` so it does not collide with
            // core's top-level `wp tejcart` command (registered in
            // tejcart.php). Registering the same name twice makes WP-CLI drop
            // one of the two command trees; the Shipping_CLI docblocks all
            // document the `wp tejcart-shipping <subcommand>` form.
            \WP_CLI::add_command( 'tejcart-shipping', \TejCart\Shipping_Plugin\Core\Shipping_CLI::class );
        }
    } );
}
