<?php
/**
 * Bootstraps the bundled live tax providers and their admin UI.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

use TejCart\Tax_Providers\Admin\Tax_Provider_Configure_Page;
use TejCart\Tax_Providers\Admin\Tax_Providers_List;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires the bundled drivers (Stripe Tax, TaxJar, Avalara) onto the
 * `tejcart_tax_providers` filter consumed by {@see \TejCart\Tax\Tax_Provider_Registry},
 * and renders the Settings → Tax → Providers screen so merchants can
 * pick one and enter credentials without writing code.
 *
 * The admin UI mirrors the Shipping Carriers list pattern: a card of
 * provider rows on the index, a focused per-provider configure page
 * reached via "Set up" / "Manage", AJAX toggle for the enabled flag,
 * and a one-click "Make active" action to select the live calculator.
 *
 * Third-party drivers can still register their own classes through the
 * same filter — this class only adds entries, it does not replace them.
 */
class Tax_Providers_Bootstrap {
    /**
     * Settings sub-section slug under the Tax tab.
     *
     * Kept as `PAGE_SLUG` for backward compatibility with callers that still
     * reference the old top-level menu identifier.
     */
    public const PAGE_SLUG = 'tejcart';

    /**
     * Option key holding the id of the active live tax provider, mirrored
     * here so callers can avoid the magic string.
     */
    public const ACTIVE_OPTION = 'tejcart_active_tax_provider';

    /**
     * Option holding pending "live provider fell back to manual rates"
     * admin warnings, keyed by provider id. Mirrors the decryption-notice
     * store: written (deduplicated) from the front-end checkout request,
     * rendered once and cleared on the next admin page load.
     */
    public const FALLBACK_NOTICE_OPTION = 'tejcart_tax_provider_fallback_notices';

    /**
     * AJAX action + nonce for flipping the per-provider enabled flag.
     */
    public const TOGGLE_ACTION = 'tejcart_tax_provider_toggle';
    public const TOGGLE_NONCE  = 'tejcart_tax_provider_toggle';

    /**
     * AJAX action + nonce for promoting a provider to the active calculator.
     */
    public const SET_ACTIVE_ACTION = 'tejcart_tax_provider_set_active';
    public const SET_ACTIVE_NONCE  = 'tejcart_tax_provider_set_active';

    /**
     * Map of provider IDs that this module bundles.
     *
     * Order matters: this is the order shown in the admin list.
     *
     * @var array<string, class-string<Abstract_Live_Tax_Provider>>
     */
    public const BUNDLED = array(
        'stripe_tax' => Stripe_Tax_Provider::class,
        'taxjar'     => TaxJar_Tax_Provider::class,
        'avalara'    => Avalara_Tax_Provider::class,
    );

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register hooks. Called by the DI container during plugin bootstrap.
     */
    public function init(): void {
        add_filter( 'tejcart_tax_providers', array( $this, 'register_bundled_providers' ), 10, 1 );

        // Strict failover: when the merchant has opted in, refuse the
        // checkout if any active live-tax provider signalled
        // unhealthy during this request. The default (off) preserves
        // the silent-fall-through-to-manual-rates behaviour for
        // backwards compatibility, but US-nexus-collecting stores
        // should flip this on so under-collection during upstream
        // outages can't accrue silently and show up as a back-tax
        // bill at month-end.
        add_filter( 'tejcart_pre_place_order_validate', array( $this, 'enforce_strict_failover' ), 10, 2 );

        // Front-end signal: core fires this when an active live provider could
        // not price a cart and the manual rate table was used instead. Record
        // a deduplicated admin warning so the operator learns about silent
        // under/over-collection without trawling the tax logs. Registered
        // unconditionally because the action fires on the (front-end) checkout
        // request, not in wp-admin.
        add_action( 'tejcart_tax_provider_fell_back', array( $this, 'record_fallback_notice' ), 10, 2 );

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'handle_admin_save' ) );
            add_action( 'admin_init', array( $this, 'handle_reset_usage' ) );
            add_action( 'admin_notices', array( \TejCart\Tax_Providers\Abstract_Live_Tax_Provider::class, 'render_decrypt_admin_notices' ) );
            add_action( 'admin_notices', array( $this, 'render_fallback_admin_notices' ) );
            add_action( 'wp_ajax_' . self::TOGGLE_ACTION, array( $this, 'ajax_toggle_provider' ) );
            add_action( 'wp_ajax_' . self::SET_ACTIVE_ACTION, array( $this, 'ajax_set_active' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

            // Inject the "Tax Providers" sub-section under Settings -> Tax. The
            // tab entry and its renderer are only registered while this module
            // is enabled, so flipping the toggle OFF cleanly removes both.
            add_filter( 'tejcart_settings_tax_sub_nav_items', array( $this, 'register_settings_sub_nav' ) );
            add_action( 'tejcart_settings_render_tax_section_providers', array( $this, 'render_settings_section' ) );
        }
    }

    /**
     * Register the "Tax Providers" entry in the Tax settings sub-nav.
     *
     * @param array<string,string>|mixed $items Existing sub-nav items.
     * @return array<string,string>
     */
    public function register_settings_sub_nav( $items ): array {
        if ( ! is_array( $items ) ) {
            $items = array();
        }
        $items['providers'] = __( 'Tax Providers', 'tejcart' );
        return $items;
    }

    /**
     * Render the Tax -> Providers sub-section body.
     */
    public function render_settings_section(): void {
        $this->render_admin_page( true );
    }

    /**
     * Reset the daily/monthly counter and circuit-breaker state for a
     * provider. Useful after fixing a misconfiguration that tripped the
     * breaker, or for clearing dev-mode call counts before a launch.
     */
    public function handle_reset_usage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_tax_provider_reset_usage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below after sanitising
            return;
        }

        // F-MODS-015: Sanitise the provider_id from POST first, then
        // validate it against the known BUNDLED list before building the
        // nonce action string. This prevents an attacker from influencing
        // the nonce action via a crafted POST value. sanitize_key() already
        // strips slashes and limits to [a-z0-9_-], so path traversal is
        // impossible, but the explicit BUNDLED lookup closes the door on
        // any future sanitize_key() behavioural change.
        $provider_id = sanitize_key( wp_unslash( (string) $_POST['tejcart_tax_provider_reset_usage'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( self::BUNDLED[ $provider_id ] ) ) {
            return;
        }

        // Nonce is now checked with a provider_id that has been independently
        // validated against the hardcoded BUNDLED list — not the raw POST value.
        check_admin_referer( 'tejcart_tax_provider_reset_' . $provider_id );

        Tax_Provider_Usage_Tracker::instance()->reset( $provider_id );

        add_settings_error(
            'tejcart_tax_providers',
            'reset',
            __( 'Usage counter and circuit-breaker reset.', 'tejcart' ),
            'success'
        );
    }

    /**
     * Append bundled providers to the registry filter.
     *
     * Refuse the checkout when a live tax provider has signalled
     * unavailability AND the merchant has opted into strict failover.
     *
     * The default behaviour (when strict_failover is off — the
     * documented backwards-compat default) falls through to manual
     * rates, which can under-collect silently during an upstream
     * incident. US-nexus stores set the option ON via
     * Settings → Tax → Providers so customers see a transient
     * "Tax calculation is temporarily unavailable, please retry"
     * message instead of being silently charged at the manual rate
     * that doesn't account for their jurisdiction.
     *
     * Hooked to `tejcart_pre_place_order_validate` which fires from
     * Checkout::process_validate. Returning a WP_Error short-circuits
     * the order placement.
     *
     * @param true|\WP_Error $passthrough Existing validation result.
     * @param mixed          $checkout    Active checkout instance (unused).
     * @return true|\WP_Error
     */
    public function enforce_strict_failover( $passthrough, $checkout = null ) {
        if ( is_wp_error( $passthrough ) ) {
            return $passthrough;
        }
        if ( ! class_exists( '\\TejCart\\Tax_Providers\\Abstract_Live_Tax_Provider' ) ) {
            return $passthrough;
        }
        if ( ! Abstract_Live_Tax_Provider::strict_failover_enabled() ) {
            return $passthrough;
        }
        $signals = Abstract_Live_Tax_Provider::unhealthy_signals_this_request();
        if ( empty( $signals ) ) {
            return $passthrough;
        }

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf(
                    'Tax-providers strict failover engaged. Refusing checkout: %s',
                    implode( ', ', array_map(
                        static fn( $id, $reason ): string => $id . ':' . $reason,
                        array_keys( $signals ),
                        array_values( $signals )
                    ) )
                ),
                'warning'
            );
        }

        return new \WP_Error(
            'tejcart_tax_provider_unavailable',
            __( 'Tax calculation is temporarily unavailable. Please retry in a few moments. If the problem persists, contact support.', 'tejcart' ),
            array( 'status' => 503 )
        );
    }

    /**
     * Record a deduplicated admin warning when an active live tax provider
     * fell back to the manual rate table for a real cart.
     *
     * Hooked to the core `tejcart_tax_provider_fell_back` action, which
     * fires during checkout. To keep this cheap on a high-traffic checkout
     * we gate the option write behind a once-per-provider-per-UTC-day
     * transient — the same approach the decryption-failure and throttle
     * notices use — so a sustained outage costs one DB write per day, not
     * one per order.
     *
     * @param string $provider_id Active provider id (e.g. `taxjar`).
     * @param mixed  $cart        Cart being priced (unused; kept for hook parity).
     */
    public function record_fallback_notice( $provider_id, $cart = null ): void {
        $provider_id = sanitize_key( (string) $provider_id );
        if ( '' === $provider_id ) {
            return;
        }

        $marker = 'tejcart_tax_fallback_notice_' . $provider_id . '_' . gmdate( 'Ymd' );
        if ( false !== get_transient( $marker ) ) {
            return;
        }
        set_transient( $marker, 1, DAY_IN_SECONDS );

        $title = $provider_id;
        if ( isset( self::BUNDLED[ $provider_id ] ) ) {
            $class = self::BUNDLED[ $provider_id ];
            $title = ( new $class() )->get_title();
        }

        $message = sprintf(
            /* translators: %s: tax provider name, e.g. TaxJar. */
            __( 'Live tax provider “%s” could not calculate tax for one or more carts today, so TejCart fell back to the manual rate table. Tax may be under- or over-collected for affected orders (look for orders with tax source “manual_fallback”). Check Settings → Tax → Providers and the tax log, or enable strict failover to block checkout instead of falling back.', 'tejcart' ),
            $title
        );

        $notices                 = (array) get_option( self::FALLBACK_NOTICE_OPTION, array() );
        $notices[ $provider_id ] = array(
            'message' => $message,
            'time'    => time(),
        );
        update_option( self::FALLBACK_NOTICE_OPTION, $notices, false );

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'Live tax provider %s fell back to manual rates during checkout.', $provider_id ),
                'warning'
            );
        }
    }

    /**
     * Render and clear any pending fallback warnings on an admin page.
     *
     * Capability-gated to operators; clears the store after rendering so the
     * banner shows at most once per resolution (the transient dedup window
     * re-arms it if the outage continues into the next day).
     */
    public function render_fallback_admin_notices(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notices = (array) get_option( self::FALLBACK_NOTICE_OPTION, array() );
        if ( empty( $notices ) ) {
            return;
        }
        foreach ( $notices as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['message'] ) ) {
                continue;
            }
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html( (string) $entry['message'] )
            );
        }
        delete_option( self::FALLBACK_NOTICE_OPTION );
    }

    /**
     * @param mixed $providers Existing filter payload — normally an
     *                         `array<string,string>` map of id → class,
     *                         but third-party callers occasionally pass
     *                         garbage; we coerce to array defensively.
     * @return array<string, string>
     */
    public function register_bundled_providers( $providers ): array {
        if ( ! is_array( $providers ) ) {
            $providers = array();
        }
        foreach ( self::BUNDLED as $id => $class ) {
            if ( ! isset( $providers[ $id ] ) ) {
                $providers[ $id ] = $class;
            }
        }
        return $providers;
    }

    /**
     * Process the settings save POST. Each provider has its own form
     * with a unique nonce so credentials never leak between sections.
     */
    public function handle_admin_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['tejcart_tax_provider_save'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $provider_id = sanitize_key( wp_unslash( (string) $_POST['tejcart_tax_provider_save'] ) );
        if ( ! isset( self::BUNDLED[ $provider_id ] ) ) {
            return;
        }

        check_admin_referer( 'tejcart_tax_provider_save_' . $provider_id );

        $class    = self::BUNDLED[ $provider_id ];
        $provider = new $class();

        $settings = $provider->get_settings();
        foreach ( $class::setting_fields() as $field ) {
            $field_id = (string) ( $field['id'] ?? '' );
            if ( '' === $field_id || 'heading' === ( $field['type'] ?? '' ) ) {
                continue;
            }
            $type = (string) ( $field['type'] ?? 'text' );

            if ( 'checkbox' === $type ) {
                $settings[ $field_id ] = ! empty( $_POST[ $field_id ] ) ? 'yes' : 'no';
                continue;
            }

            // Nonce verified via check_admin_referer() above. Type-specific
            // sanitization (text/url/select/textarea/password) runs below.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw = isset( $_POST[ $field_id ] ) ? wp_unslash( (string) $_POST[ $field_id ] ) : '';

            // For password fields, keep the existing value when blank — the
            // admin form deliberately renders these empty so credentials
            // aren't echoed back into the DOM on every save.
            if ( 'password' === $type && '' === $raw ) {
                continue;
            }

            if ( 'number' === $type ) {
                $settings[ $field_id ] = (string) max( 0, (int) $raw );
                continue;
            }

            if ( 'select' === $type ) {
                // Defence in depth: even though only manage_options users
                // reach this code path, a crafted POST could otherwise drop
                // an arbitrary string into a setting that downstream code
                // (e.g. Stripe Tax `tax_behavior`) trusts. Whitelist against
                // the field's declared options, allow_empty when permitted.
                $allowed = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
                $value   = sanitize_text_field( $raw );
                if ( in_array( $value, $allowed, true ) ) {
                    $settings[ $field_id ] = $value;
                } elseif ( '' === $value && ! empty( $field['allow_empty'] ) ) {
                    $settings[ $field_id ] = '';
                }
                // Otherwise: silently drop the unknown value, leaving the
                // existing setting untouched.
                continue;
            }

            $settings[ $field_id ] = sanitize_text_field( $raw );
        }

        $provider->save_settings( $settings );

        if ( ! empty( $_POST['tejcart_tax_provider_set_active'] ) ) {
            update_option( self::ACTIVE_OPTION, $provider_id );
        } elseif ( (string) get_option( self::ACTIVE_OPTION, '' ) === $provider_id
            && 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
            // Provider was active but operator unticked Enabled — surrender
            // the active flag so the cart falls back to manual rates.
            update_option( self::ACTIVE_OPTION, '' );
        }

        \TejCart\Tax\Tax_Provider_Registry::reset();

        add_settings_error(
            'tejcart_tax_providers',
            'saved',
            sprintf(
                /* translators: %s: provider title */
                __( '%s settings saved.', 'tejcart' ),
                $provider->get_title()
            ),
            'success'
        );
    }

    /**
     * AJAX: flip the enabled flag for a provider.
     *
     * Mirrors the carriers toggle handler — capability + nonce gate, then
     * a single `update_option` against the provider's settings array.
     * Surrenders the active flag when the currently-active provider is
     * paused so manual rates take over cleanly.
     */
    public function ajax_toggle_provider(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage tax providers.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::TOGGLE_NONCE ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'tejcart' ) ),
                400
            );
        }

        $provider_id = isset( $_POST['provider_id'] )
            ? sanitize_key( wp_unslash( (string) $_POST['provider_id'] ) )
            : '';
        if ( '' === $provider_id || ! isset( self::BUNDLED[ $provider_id ] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Unknown tax provider.', 'tejcart' ) ),
                404
            );
        }

        $enabled = isset( $_POST['enabled'] )
            && '1' === sanitize_text_field( wp_unslash( (string) $_POST['enabled'] ) );

        $class = self::BUNDLED[ $provider_id ];

        /** @var Abstract_Live_Tax_Provider $provider */
        $provider = new $class();
        $settings = $provider->get_settings();

        if ( $enabled && ! $provider->is_configured() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Add credentials before enabling this provider.', 'tejcart' ),
                ),
                400
            );
        }

        $settings['enabled'] = $enabled ? 'yes' : 'no';
        $saved = $provider->save_settings( $settings );

        if ( ! $saved ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Could not save provider state. Check the server log.', 'tejcart' ),
                ),
                500
            );
        }

        // Disabling the currently-active provider surrenders the active flag
        // so the cart falls back to the manual rate table immediately.
        $active_id          = (string) get_option( self::ACTIVE_OPTION, '' );
        $surrendered_active = false;
        if ( ! $enabled && $active_id === $provider_id ) {
            update_option( self::ACTIVE_OPTION, '' );
            $surrendered_active = true;
        }

        \TejCart\Tax\Tax_Provider_Registry::reset();

        wp_send_json_success(
            array(
                'provider_id'        => $provider_id,
                'enabled'            => $enabled,
                'surrendered_active' => $surrendered_active,
                'message'            => $enabled
                    ? __( 'Tax provider enabled.', 'tejcart' )
                    : __( 'Tax provider paused.', 'tejcart' ),
            )
        );
    }

    /**
     * AJAX: promote a provider to the active calculator.
     *
     * Only enabled, configured providers can be promoted. The active flag is
     * a single option, so this is effectively a one-of-N radio selection
     * across the providers list — there is never a state where two
     * providers run live.
     */
    public function ajax_set_active(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage tax providers.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::SET_ACTIVE_NONCE ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'tejcart' ) ),
                400
            );
        }

        $provider_id = isset( $_POST['provider_id'] )
            ? sanitize_key( wp_unslash( (string) $_POST['provider_id'] ) )
            : '';
        if ( '' === $provider_id || ! isset( self::BUNDLED[ $provider_id ] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Unknown tax provider.', 'tejcart' ) ),
                404
            );
        }

        $class = self::BUNDLED[ $provider_id ];

        /** @var Abstract_Live_Tax_Provider $provider */
        $provider = new $class();
        if ( ! $provider->is_configured() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Add credentials before promoting this provider to active.', 'tejcart' ),
                ),
                400
            );
        }

        $settings = $provider->get_settings();
        if ( 'yes' !== (string) ( $settings['enabled'] ?? 'no' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Enable the provider before promoting it to active.', 'tejcart' ),
                ),
                400
            );
        }

        update_option( self::ACTIVE_OPTION, $provider_id );
        \TejCart\Tax\Tax_Provider_Registry::reset();

        wp_send_json_success(
            array(
                'provider_id' => $provider_id,
                'message'     => sprintf(
                    /* translators: %s: provider title */
                    __( '%s is now the active tax calculator.', 'tejcart' ),
                    $provider->get_title()
                ),
            )
        );
    }

    /**
     * Enqueue the providers-specific CSS + JS on the Settings page.
     *
     * Stays out of the way unless we're on the Tax → Providers sub-screen
     * so the bundle doesn't load on every wp-admin screen.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'tejcart_page_tejcart-settings' !== $hook && 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';

        $is_embedded = ( 'tax' === $tab && 'providers' === $section );
        $is_legacy   = ( 'toplevel_page_' . self::PAGE_SLUG === $hook );

        if ( ! $is_embedded && ! $is_legacy ) {
            return;
        }

        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : false;

        wp_enqueue_style(
            'tejcart-tax-providers-admin',
            tejcart_asset_url( 'assets/css/admin/tax-providers.css' ),
            array( 'tejcart-admin' ),
            $version
        );
        wp_enqueue_script(
            'tejcart-tax-providers-admin',
            tejcart_asset_url( 'assets/js/admin/tax-providers.js' ),
            array( 'jquery' ),
            $version,
            true
        );
        wp_localize_script(
            'tejcart-tax-providers-admin',
            'tejcartTaxProviders',
            array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'toggleAction'    => self::TOGGLE_ACTION,
                'setActiveAction' => self::SET_ACTIVE_ACTION,
                'i18n'            => array(
                    'enabled'           => __( 'Tax provider enabled.', 'tejcart' ),
                    'disabled'          => __( 'Tax provider paused.', 'tejcart' ),
                    'toggleError'       => __( 'Could not update tax provider. Please retry.', 'tejcart' ),
                    'activated'         => __( 'Tax provider is now active.', 'tejcart' ),
                    'activateError'     => __( 'Could not promote tax provider. Please retry.', 'tejcart' ),
                    'confirmActivate'   => __( 'Make this the active tax provider? The currently active provider (if any) will be replaced.', 'tejcart' ),
                    'networkError'      => __( 'Network error. Please retry.', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Render the admin page.
     *
     * Dispatches between the list view (no `?provider=`) and the per-provider
     * configure view, mirroring how the Shipping Carriers settings page is
     * structured. The list view is the default landing point.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">` and
     *                      page header so the body can be composed inside
     *                      another admin screen (Settings → Tax → Providers).
     */
    public function render_admin_page( bool $embedded = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active   = (string) get_option( self::ACTIVE_OPTION, '' );
        $base_url = $embedded
            ? admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=providers' )
            : admin_url( 'admin.php?page=' . self::PAGE_SLUG );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $requested = isset( $_GET['provider'] )
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key( wp_unslash( (string) $_GET['provider'] ) )
            : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( '' === $requested && isset( $_POST['tejcart_tax_provider_save'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $requested = sanitize_key( wp_unslash( (string) $_POST['tejcart_tax_provider_save'] ) );
        }

        $configure_id = ( '' !== $requested && isset( self::BUNDLED[ $requested ] ) )
            ? $requested
            : '';

        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap tejcart-tax-providers">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Tax Providers', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle">
                        <?php esc_html_e( 'Connect a live tax provider to take over from the manual rate table. Only one provider is consulted on each cart calculation; failures fall back automatically to the manual rates configured under Settings → Tax.', 'tejcart' ); ?>
                    </p>
                </div>
            </div>
        <?php else : ?>
        <div class="tejcart-tax-providers tejcart-tax-providers--embedded">
        <?php endif; ?>

            <?php if ( '' !== $configure_id ) : ?>
                <?php
                $is_active_provider = ( $active === $configure_id );
                ( new Tax_Provider_Configure_Page() )->render(
                    $configure_id,
                    self::BUNDLED[ $configure_id ],
                    $is_active_provider,
                    $base_url
                );
                ?>
            <?php else : ?>
                <?php settings_errors( 'tejcart_tax_providers' ); ?>
                <?php ( new Tax_Providers_List( self::BUNDLED ) )->render( $base_url, $active ); ?>
            <?php endif; ?>

        </div>
        <?php
    }
}
