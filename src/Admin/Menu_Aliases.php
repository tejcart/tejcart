<?php
/**
 * Redirect aliases for legacy TejCart admin slugs.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Preserves old top-level TejCart admin URLs after the sidebar IA refactor.
 *
 * Each legacy slug (Tax Rates, Shipping Zones, API Keys, Webhooks, Tools,
 * Import / Export, Logs, System Status) is registered as a hidden submenu
 * page whose render callback performs a GET redirect to the canonical new
 * location — typically a Settings → {tab}[&section=…] URL.
 *
 * Registering a hidden submenu (rather than just hooking admin_init) keeps
 * WordPress's own capability check (`user_can_access_admin_page()`) happy
 * for any URL a bookmark or external link still points at, while
 * `remove_submenu_page()` on `admin_head` hides the entry from the sidebar
 * without breaking that access check.
 *
 * @see Menu::hide_payment_method_settings_submenu() for the same pattern.
 */
class Menu_Aliases {
    /**
     * Legacy slug → new query-args map.
     *
     * @var array<string, array<string, string>>
     */
    private const MAP = array(
        'tejcart-tax-rates'       => array( 'page' => 'tejcart-settings', 'tab' => 'tax' ),
        'tejcart-shipping-zones'  => array( 'page' => 'tejcart-settings', 'tab' => 'shipping', 'section' => 'zones' ),
        'tejcart-api-keys'        => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'api-keys' ),
        'tejcart-webhooks'        => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'webhooks' ),
        'tejcart-tools'           => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'tools' ),
        'tejcart-import-export'   => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'import-export' ),
        'tejcart-logs'            => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'logs' ),
        'tejcart-system-status'   => array( 'page' => 'tejcart-settings', 'tab' => 'advanced', 'section' => 'system-status' ),
    );

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'register_aliases' ), 100 );
        add_action( 'admin_head', array( $this, 'hide_aliases' ) );
    }

    /**
     * Register each legacy slug as a hidden submenu with a redirect callback.
     *
     * Priority 100 so this runs after every submodule has registered its own
     * pages; combined with `remove_submenu_page()` on `admin_head` the entries
     * never appear in the sidebar but remain valid admin URLs.
     *
     * @return void
     */
    public function register_aliases(): void {
        foreach ( array_keys( self::MAP ) as $slug ) {
            add_submenu_page(
                'tejcart',
                '',
                '',
                'manage_options',
                $slug,
                array( $this, 'redirect' )
            );
        }
    }

    /**
     * Hide each alias from the sidebar.
     *
     * Runs on `admin_head` (after `user_can_access_admin_page()` has validated
     * the request) so the capability check passes for direct-URL access even
     * though the entry is stripped from the menu tree.
     *
     * @return void
     */
    public function hide_aliases(): void {
        foreach ( array_keys( self::MAP ) as $slug ) {
            remove_submenu_page( 'tejcart', $slug );
        }
    }

    /**
     * Render callback: redirect to the canonical new URL, preserving any
     * pass-through query args (e.g. `saved=1`, `deleted=1`).
     *
     * @return void
     */
    public function redirect(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! isset( self::MAP[ $page ] ) ) {
            return;
        }

        $target = self::MAP[ $page ];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $incoming = wp_unslash( $_GET );
        foreach ( array( 'page', 'tab', 'section' ) as $reserved ) {
            unset( $incoming[ $reserved ] );
        }

        $args = array_merge( $target, array_map( 'sanitize_text_field', $incoming ) );
        $url  = add_query_arg( $args, admin_url( 'admin.php' ) );

        wp_safe_redirect( $url );
        exit;
    }
}
