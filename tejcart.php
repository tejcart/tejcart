<?php

declare( strict_types=1 );

/**
 * Plugin Name: TejCart
 * Plugin URI:  https://tejcart.com
 * Description: TejCart is a shopping cart plugin for WordPress. Sell physical goods, digital downloads, or services with products, cart, checkout, orders, customers, coupons, taxes, and shipping all in one place.
 * Version: 1.0.6
 * Author: TejCart
 * Author URI: https://profiles.wordpress.org/tejcart/
 * Text Domain: tejcart
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TejCart
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_VERSION' ) ) {
    define( 'TEJCART_VERSION', '1.0.6' );
}

define( 'TEJCART_PLUGIN_FILE', __FILE__ );
define( 'TEJCART_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEJCART_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TEJCART_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'TEJCART_TEMPLATE_DIR', TEJCART_PLUGIN_DIR . 'templates/' );
define( 'TEJCART_MIN_PHP', '8.2' );
define( 'TEJCART_MIN_WP', '6.3' );
define( 'TEJCART_FEEDBACK_ENDPOINT', 'https://tejcart.com/feedback/deactivationfeedback.php' );

require_once TEJCART_PLUGIN_DIR . 'src/Core/Autoloader.php';
\TejCart\Core\Autoloader::register();
require_once TEJCART_PLUGIN_DIR . 'src/functions.php';

require_once TEJCART_PLUGIN_DIR . 'src/Tier2/bootstrap.php';

// Load the textdomain at `init` — WordPress 6.7+ emits a
// _doing_it_wrong notice when _load_textdomain_just_in_time() fires
// before `init`. All user-visible strings (admin menus, notices, page
// content) render after `init`, so translations are ready when needed.
add_action( 'init', static function (): void {
    load_plugin_textdomain(
        'tejcart',
        false,
        dirname( TEJCART_PLUGIN_BASENAME ) . '/languages'
    );
}, 0 );

// Load enabled optional modules from modules/ before TejCart core boots
// so each module's `add_action( 'tejcart_init', ..., 20 )` listener is
// registered before the action fires. Priority 5 places this ahead of
// the core's own plugins_loaded callback at priority 10.
add_action( 'plugins_loaded', static function (): void {
    \TejCart\Modules\Module_Manager::instance()->load_enabled();
}, 5 );

/**
 * Check if the minimum PHP version requirement is met.
 *
 * @return bool True if the PHP version is sufficient, false otherwise.
 */
function tejcart_check_php_version(): bool {
    if ( version_compare( PHP_VERSION, TEJCART_MIN_PHP, '<' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo sprintf(
                /* translators: 1: Required PHP version, 2: Current PHP version. */
                esc_html__( 'TejCart requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade your PHP version.', 'tejcart' ),
                esc_html( TEJCART_MIN_PHP ),
                esc_html( PHP_VERSION )
            );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Check if the minimum WordPress version requirement is met.
 *
 * @return bool True if the WordPress version is sufficient, false otherwise.
 */
function tejcart_check_wp_version(): bool {
    global $wp_version;

    if ( version_compare( $wp_version, TEJCART_MIN_WP, '<' ) ) {
        add_action( 'admin_notices', function () {
            global $wp_version;
            echo '<div class="notice notice-error"><p>';
            echo sprintf(
                /* translators: 1: Required WordPress version, 2: Current WordPress version. */
                esc_html__( 'TejCart requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress.', 'tejcart' ),
                esc_html( TEJCART_MIN_WP ),
                esc_html( $wp_version )
            );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

add_action( 'plugins_loaded', function () {
    if ( ! tejcart_check_php_version() ) {
        return;
    }

    if ( ! tejcart_check_wp_version() ) {
        return;
    }

    // WordPress does not fire the activation hook on an in-place update
    // (wp.org auto-update / "Update Now" / re-uploading the zip), so core
    // schema added in a new release would otherwise never be created on an
    // existing store — breaking it with "Table doesn't exist" / "Unknown
    // column" errors. Run the version-gated migration here, before the
    // plugin boots and its query paths execute. No-op once the stored
    // version already matches TEJCART_VERSION.
    \TejCart\Core\Installer::maybe_upgrade();

    tejcart();
} );

// Textdomain is loaded at `init` priority 0 (above). WP 6.7+'s JIT
// auto-loader handles any __() call that fires before `init` using
// the `Text Domain:` header, and the `init` hook prevents the
// "triggered too early" notice.

register_activation_hook( __FILE__, function ( $network_wide ) {
    global $wp_version;

    // Textdomain is loaded at `init` priority 0 — activation fires
    // during plugin loading, so WP's JIT auto-loader handles these
    // esc_html__() calls via the `Text Domain:` header.

    if ( version_compare( PHP_VERSION, TEJCART_MIN_PHP, '<' ) ) {
        wp_die(
            sprintf(
                /* translators: 1: Required PHP version, 2: Current PHP version. */
                esc_html__( 'TejCart requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade your PHP version before activating the plugin.', 'tejcart' ),
                esc_html( TEJCART_MIN_PHP ),
                esc_html( PHP_VERSION )
            ),
            esc_html__( 'Plugin activation failed', 'tejcart' ),
            array( 'back_link' => true, 'response' => 200 )
        );
    }

    if ( version_compare( $wp_version, TEJCART_MIN_WP, '<' ) ) {
        wp_die(
            sprintf(
                /* translators: 1: Required WordPress version, 2: Current WordPress version. */
                esc_html__( 'TejCart requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress before activating the plugin.', 'tejcart' ),
                esc_html( TEJCART_MIN_WP ),
                esc_html( $wp_version )
            ),
            esc_html__( 'Plugin activation failed', 'tejcart' ),
            array( 'back_link' => true, 'response' => 200 )
        );
    }

    // Audit C-6: on multisite network-activation WordPress fires this
    // hook once but with `$network_wide = true` — the callback runs in
    // the context of the network admin's current blog only. Without
    // looping every site, all blogs past the active one are left
    // without tables / options / capabilities, and the plugin silently
    // breaks on those stores. Loop and run the per-site installer for
    // each blog when the activation is network-wide.
    if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
        $site_ids = function_exists( 'get_sites' )
            ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) )
            : array();
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( (int) $site_id );
            try {
                \TejCart\Core\Installer::activate();
                \TejCart\Modules\Module_Manager::instance()->install_enabled_modules();
            } finally {
                restore_current_blog();
            }
        }
        return;
    }

    \TejCart\Core\Installer::activate();
    \TejCart\Modules\Module_Manager::instance()->install_enabled_modules();
} );

// Audit C-6: when a new site is added to a network that already has
// TejCart network-activated, the new blog inherits the active-plugin
// list but never had `Installer::activate()` run inside its context —
// so its tables / options / capabilities are missing on first use.
// `wp_initialize_site` fires AFTER the new site exists and the new
// blog's prefix is queryable; run the installer in that context.
add_action(
    'wp_initialize_site',
    function ( $new_site ) {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active_for_network( TEJCART_PLUGIN_BASENAME ) ) {
            return;
        }
        $site_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;
        if ( $site_id <= 0 ) {
            return;
        }
        switch_to_blog( $site_id );
        try {
            load_plugin_textdomain(
                'tejcart',
                false,
                dirname( TEJCART_PLUGIN_BASENAME ) . '/languages'
            );
            \TejCart\Core\Installer::activate();
            \TejCart\Modules\Module_Manager::instance()->install_enabled_modules();
        } finally {
            restore_current_blog();
        }
    },
    20
);

register_deactivation_hook( __FILE__, function () {
    \TejCart\Core\Installer::deactivate();
} );

// Audit M-10 (Core F-007): when a multisite sub-site is deleted, WP
// fires `wpmu_drop_tables` to let plugins append their own tables to
// the DROP list. Without this filter TejCart's custom tables survive
// the site deletion and orphan forever.
add_filter(
    'wpmu_drop_tables',
    function ( array $tables ): array {
        global $wpdb;

        // Core tables (owned by src/Core/Installer.php::create_tables()).
        $core_tables = array(
            'tejcart_products',
            'tejcart_product_meta',
            'tejcart_orders',
            'tejcart_order_items',
            'tejcart_order_meta',
            'tejcart_customers',
            'tejcart_coupons',
            'tejcart_coupon_usage',
            'tejcart_sessions',
            'tejcart_term_relationships',
            'tejcart_webhook_deliveries',
            'tejcart_stock_reservations',
            'tejcart_order_refunds',
            'tejcart_api_keys',
            'tejcart_download_permissions',
            'tejcart_stock_notifications',
            'tejcart_order_status_log',
            'tejcart_locks',
            'tejcart_paypal_events',
            'tejcart_daily_summary',
            // Added in 1.0.1 — were previously omitted, so they orphaned on
            // multisite sub-site deletion. Keep in sync with create_tables().
            'tejcart_product_daily',
            'tejcart_request_log',
            'tejcart_admin_audit',
            'tejcart_customer_segments',
            'tejcart_product_cooccurrence',
            'tejcart_product_reviews',
            'tejcart_review_media',
            'tejcart_review_votes',
        );

        // Tier-2 tables (owned by src/Tier2/Schema.php).
        // F-CORE-011: these were previously omitted and orphaned on site deletion.
        // Note: migration state is tracked in the `tejcart_version` option, not a
        // `tejcart_migrations` table — there is no such table to drop.
        $tier2_tables = array(
            'tejcart_coupon_meta',
            'tejcart_addresses',
            'tejcart_email_templates',
            'tejcart_email_log',
            'tejcart_abandoned_carts',
        );

        $all_unprefixed = array_merge( $core_tables, $tier2_tables );

        /**
         * Allow bundled modules and add-ons to append their own table names
         * (unprefixed) to the multisite drop list.
         *
         * F-CORE-011: modules must hook this filter in their module.php
         * bootstrap so their tables are cleaned up when a sub-site is deleted.
         * The filter receives an array of unprefixed table names; return the
         * array with any additional names appended.
         *
         * @param string[] $unprefixed_tables Unprefixed table names.
         * @param string   $prefix            The site's $wpdb->prefix.
         */
        $all_unprefixed = (array) apply_filters( 'tejcart_drop_tables', $all_unprefixed, $wpdb->prefix );

        foreach ( $all_unprefixed as $t ) {
            $tables[] = $wpdb->prefix . $t;
        }
        return $tables;
    }
);

// Audit H-21 (Core F-005): moved CLI registration into a
// `plugins_loaded` callback so it runs AFTER the PHP 8.2+ version
// gate. Previously the add_command ran at file-include time — the
// class reference is a string so PHP doesn't autoload immediately,
// but the moment `wp tejcart ...` fires on PHP 8.0/8.1 the
// autoloader loads TejCart_CLI (which uses typed properties /
// readonly / never / etc.) and fatals.
add_action(
    'plugins_loaded',
    function () {
        if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'tejcart_check_php_version' ) && tejcart_check_php_version() ) {
            \WP_CLI::add_command( 'tejcart', \TejCart\CLI\TejCart_CLI::class );
        }
    },
    15
);
