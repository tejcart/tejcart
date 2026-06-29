<?php
/**
 * Carrier credential settings page.
 *
 * Lives as the "Carriers" sub-section under TejCart → Settings → Shipping,
 * alongside the core Zones view (mirrors how Tax has Rates / Providers
 * sub-sections). The visual language matches `Payment_Methods_List` so
 * carriers feel like a sibling of payment gateways: a list of cards
 * grouped by region, each with a brand mark, status pill, enable toggle,
 * and "Set up" / "Manage" call-to-action that opens a focused
 * single-driver configuration view (Carrier_Configure_Page).
 *
 * Secret fields (API keys, account numbers) are encrypted via
 * Credentials_Vault on save; per-carrier on/off state lives in
 * Carrier_State and short-circuits Carrier_Driven_Method::quotes_for_cart.
 *
 * @package TejCart\Shipping_Plugin\Admin
 */

namespace TejCart\Shipping_Plugin\Admin;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Capabilities;
use TejCart\Shipping_Plugin\Core\Carrier_Registry;
use TejCart\Shipping_Plugin\Core\Credentials_Vault;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings_Page {
    public const SECTION_KEY      = 'carriers';
    public const PARENT_PAGE      = 'tejcart-settings';
    public const PARENT_TAB       = 'shipping';
    public const NONCE_NAME       = 'tejcart_shipping_carriers_save';
    public const SAVE_ACTION      = 'tejcart_shipping_carriers_save';
    public const TOGGLE_ACTION    = 'tejcart_shipping_toggle_carrier';
    public const TOGGLE_NONCE     = 'tejcart_shipping_toggle_carrier';
    public const TEST_ACTION      = 'tejcart_shipping_test_carrier';
    public const TEST_NONCE       = 'tejcart_shipping_test_carrier';
    public const LEGACY_PAGE_SLUG = 'tejcart-shipping-carriers';

    private Carrier_Registry $registry;
    private Credentials_Vault $vault;
    private Carrier_State $state;

    public function __construct( Carrier_Registry $registry, Credentials_Vault $vault, Carrier_State $state ) {
        $this->registry = $registry;
        $this->vault    = $vault;
        $this->state    = $state;
    }

    public function register(): void {
        add_filter( 'tejcart_settings_shipping_sub_nav_items', array( $this, 'register_sub_nav_item' ) );
        add_action( 'tejcart_settings_render_shipping_section_' . self::SECTION_KEY, array( $this, 'render_section' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
        add_action( 'wp_ajax_' . self::TOGGLE_ACTION, array( $this, 'ajax_toggle_carrier' ) );
        add_action( 'wp_ajax_' . self::TEST_ACTION, array( $this, 'ajax_test_connection' ) );
        add_filter( 'tejcart_admin_page_hooks', array( $this, 'register_admin_hook' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function register_sub_nav_item( array $items ): array {
        $items[ self::SECTION_KEY ] = __( 'Carriers', 'tejcart' );
        return $items;
    }

    /**
     * @param string[] $hooks
     * @return string[]
     */
    public function register_admin_hook( array $hooks ): array {
        $hooks[] = 'tejcart_page_' . self::PARENT_PAGE;
        return $hooks;
    }

    /**
     * Enqueue the carriers-specific CSS + JS on the Settings page only.
     * The base tejcart-admin bundle (cards, toggles, pills, tokens) is
     * already loaded via `tejcart_admin_page_hooks`; this layer only
     * adds the carriers-list and configure-form specifics on top.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'tejcart_page_' . self::PARENT_PAGE !== $hook ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( self::PARENT_TAB !== $tab ) {
            return;
        }
        $base_url = defined( 'TEJCART_SHIPPING_PLUGIN_URL' ) ? TEJCART_SHIPPING_PLUGIN_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/module.php' );
        $version  = defined( 'TEJCART_SHIPPING_VERSION' ) ? TEJCART_SHIPPING_VERSION : false;

        // Module assets are deliberately shipped un-minified — they're
        // tiny (<8 KB combined) and skipping the minified-sibling pair
        // keeps the module out of the root `bin/minify-assets.mjs` walk.
        wp_enqueue_style(
            'tejcart-shipping-admin-carriers',
            $base_url . 'assets/css/admin-carriers.css',
            array(),
            $version
        );
        wp_enqueue_script(
            'tejcart-shipping-admin-carriers',
            $base_url . 'assets/js/admin-carriers.js',
            array( 'jquery' ),
            $version,
            true
        );
        wp_localize_script(
            'tejcart-shipping-admin-carriers',
            'tejcartShippingCarriers',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'toggleAction'   => self::TOGGLE_ACTION,
                'testAction'     => self::TEST_ACTION,
                'i18n'           => array(
                    'showSecret'        => __( 'Show', 'tejcart' ),
                    'hideSecret'        => __( 'Hide', 'tejcart' ),
                    'enabled'           => __( 'Carrier enabled.', 'tejcart' ),
                    'disabled'          => __( 'Carrier disabled.', 'tejcart' ),
                    'toggleError'       => __( 'Could not update carrier. Please retry.', 'tejcart' ),
                    'testing'           => __( 'Testing connection…', 'tejcart' ),
                    'testNetwork'       => __( 'Network error during connection test.', 'tejcart' ),
                    /* translators: 1: carrier brand name, 2: comma-separated zone names. */
                    'disableConfirm'    => __( 'Disable %1$s? It is currently configured in: %2$s. Rates from this carrier will stop being quoted at checkout immediately.', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Backward-compatibility redirect for the legacy top-level
     * `admin.php?page=tejcart-shipping-carriers` URL.
     */
    public function maybe_redirect_legacy_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::LEGACY_PAGE_SLUG !== $page ) {
            return;
        }

        $args = array(
            'page'    => self::PARENT_PAGE,
            'tab'     => self::PARENT_TAB,
            'section' => self::SECTION_KEY,
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['updated'] ) ) {
            $args['updated'] = 1;
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 301 );
        exit;
    }

    /**
     * Dispatch between the list view and the per-driver configure view.
     *
     * No `<div class="wrap">` / page header here — the surrounding
     * Settings_Page owns the page chrome.
     */
    public function render_section(): void {
        if ( ! Capabilities::check() ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this section.', 'tejcart' ) . '</p>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $driver_id = isset( $_GET['driver'] ) ? sanitize_key( wp_unslash( $_GET['driver'] ) ) : '';
        $driver    = '' === $driver_id ? null : $this->registry->get( $driver_id );

        if ( null !== $driver ) {
            ( new Carrier_Configure_Page( $this->vault, $this->state ) )->render( $driver );
            return;
        }

        ( new Carriers_List( $this->registry, $this->vault, $this->state ) )->render();
    }

    /**
     * Persist credentials for a single driver (new flow) or, when a
     * legacy bulk POST is detected, every driver in the submitted map.
     */
    public function handle_save(): void {
        if ( ! Capabilities::check() ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        check_admin_referer( self::NONCE_NAME );

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- per-key sanitization happens in the loop below using each driver's credential_fields() schema; nonce verified by check_admin_referer() above
        $submitted = isset( $_POST['carriers'] ) && is_array( $_POST['carriers'] )
            ? wp_unslash( $_POST['carriers'] )
            : array();
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $driver_id = isset( $_POST['driver'] ) ? sanitize_key( wp_unslash( $_POST['driver'] ) ) : '';

        $drivers = ( '' !== $driver_id && $this->registry->has( $driver_id ) )
            ? array( $this->registry->get( $driver_id ) )
            : $this->registry->all();

        foreach ( $drivers as $driver ) {
            if ( ! $driver instanceof Abstract_Carrier_Driver ) {
                continue;
            }
            $values = isset( $submitted[ $driver->id() ] ) && is_array( $submitted[ $driver->id() ] )
                ? $submitted[ $driver->id() ]
                : array();

            $sanitised     = array();
            $secret_fields = array();
            $existing      = $this->vault->get( $driver->id() );

            foreach ( $driver->credential_fields() as $field_id => $field ) {
                $raw   = isset( $values[ $field_id ] ) ? (string) $values[ $field_id ] : '';
                $type  = (string) ( $field['type'] ?? 'text' );
                $clean = ( 'textarea' === $type )
                    ? sanitize_textarea_field( $raw )
                    : sanitize_text_field( $raw );

                if ( ! empty( $field['secret'] ) ) {
                    $secret_fields[] = $field_id;
                    // Empty submission with an existing stored value =
                    // "leave secret unchanged" (UI shows ••••••••).
                    if ( '' === $clean && isset( $existing[ $field_id ] ) ) {
                        $clean = $existing[ $field_id ];
                    }
                }

                $sanitised[ $field_id ] = $clean;
            }

            $this->vault->put( $driver->id(), $sanitised, $secret_fields );
        }

        $redirect_args = array(
            'page'    => self::PARENT_PAGE,
            'tab'     => self::PARENT_TAB,
            'section' => self::SECTION_KEY,
            'updated' => 1,
        );
        if ( '' !== $driver_id ) {
            $redirect_args['driver'] = $driver_id;
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * AJAX: flip the enable flag for a single carrier.
     */
    public function ajax_toggle_carrier(): void {
        if ( ! Capabilities::check() ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage carriers.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::TOGGLE_NONCE ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'tejcart' ) ),
                400
            );
        }

        $carrier_id = isset( $_POST['carrier_id'] ) ? sanitize_key( wp_unslash( $_POST['carrier_id'] ) ) : '';
        if ( '' === $carrier_id || ! $this->registry->has( $carrier_id ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Unknown carrier.', 'tejcart' ) ),
                404
            );
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );

        $this->state->set_enabled( $carrier_id, $enabled );

        wp_send_json_success(
            array(
                'carrier_id' => $carrier_id,
                'enabled'    => $enabled,
                'message'    => $enabled
                    ? __( 'Carrier enabled.', 'tejcart' )
                    : __( 'Carrier disabled.', 'tejcart' ),
            )
        );
    }

    /**
     * AJAX: ask a driver to probe its API with the currently-stored
     * credentials. Drivers that haven't implemented test_connection()
     * fall through to the abstract default, which surfaces a friendly
     * "not yet supported" message.
     */
    public function ajax_test_connection(): void {
        if ( ! Capabilities::check() ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage carriers.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::TEST_NONCE ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'tejcart' ) ),
                400
            );
        }

        $carrier_id = isset( $_POST['carrier_id'] ) ? sanitize_key( wp_unslash( $_POST['carrier_id'] ) ) : '';
        $driver     = '' === $carrier_id ? null : $this->registry->get( $carrier_id );
        if ( null === $driver ) {
            wp_send_json_error(
                array( 'message' => __( 'Unknown carrier.', 'tejcart' ) ),
                404
            );
        }

        $credentials = $this->vault->get( $carrier_id );
        try {
            $result = $driver->test_connection( $credentials );
        } catch ( \Throwable $e ) {
            wp_send_json_error(
                array( 'message' => $e->getMessage() ),
                500
            );
        }

        $ok      = ! empty( $result['ok'] );
        $message = isset( $result['message'] ) ? (string) $result['message'] : '';

        if ( $ok ) {
            wp_send_json_success( array( 'message' => $message ) );
        }

        wp_send_json_error( array( 'message' => $message ), 200 );
    }
}
