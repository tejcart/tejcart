<?php
/**
 * Bootstraps checkout address autocomplete.
 *
 * @package TejCart\Address_Autocomplete
 */

declare( strict_types=1 );

namespace TejCart\Address_Autocomplete;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires a merchant-configured address-lookup provider into the checkout.
 *
 * Core exposes two seams this class plugs into:
 *
 *   - `tejcart_address_autocomplete_config` — the provider config the core
 *     checkout JS consumes ({@see \TejCart\Frontend\Frontend}). Returning a
 *     non-empty `provider` switches the inert driver on.
 *   - `tejcart_get_settings_checkout` — the Settings → Checkout field list.
 *     Appending fields here gives us save / sanitize / render for free via
 *     the standard settings framework, so the module needs no admin UI of
 *     its own.
 *
 * Supported provider: Google Places (Maps JavaScript API). The config is
 * only emitted once a provider is selected AND an API key is present, so an
 * enabled-but-unconfigured module is a no-op.
 */
class Address_Autocomplete_Bootstrap {
    /**
     * Option holding the selected provider id ('none' | 'google').
     */
    public const PROVIDER_OPTION = 'tejcart_address_autocomplete_provider';

    /**
     * Option holding the Google Places / Maps JS API key.
     */
    public const GOOGLE_KEY_OPTION = 'tejcart_google_places_api_key';

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register hooks. Called from the module bootstrap on `tejcart_init`.
     */
    public function init(): void {
        add_filter( 'tejcart_address_autocomplete_config', array( $this, 'supply_config' ) );
        add_filter( 'tejcart_csp_directives', array( $this, 'extend_csp' ) );

        if ( is_admin() ) {
            add_filter( 'tejcart_get_settings_checkout', array( $this, 'register_settings_fields' ) );
        }
    }

    /**
     * The configured Google Places key, or '' when Google isn't the active
     * provider / no key is set. Single source of truth for "is Google live".
     *
     * @return string
     */
    private function google_api_key(): string {
        if ( 'google' !== (string) get_option( self::PROVIDER_OPTION, 'none' ) ) {
            return '';
        }
        return trim( (string) get_option( self::GOOGLE_KEY_OPTION, '' ) );
    }

    /**
     * Supply the provider config consumed by the core checkout JS.
     *
     * @param mixed $config Existing config (array; empty by default).
     * @return array<string, string>
     */
    public function supply_config( $config ): array {
        if ( ! is_array( $config ) ) {
            $config = array();
        }

        // Respect a config another listener already supplied.
        if ( ! empty( $config['provider'] ) ) {
            return $config;
        }

        $api_key = $this->google_api_key();
        if ( '' !== $api_key ) {
            $config = array(
                'provider' => 'google',
                'apiKey'   => $api_key,
            );
        }

        return $config;
    }

    /**
     * Declare Google Maps' origins on the storefront Content-Security-Policy.
     *
     * Core's CSP ({@see \TejCart\Frontend\Security_Headers}) is deliberately
     * strict for PCI SAQ-A scope, so it is never loosened globally — instead
     * each feature that loads third-party JS adds its own origins through this
     * filter (the same seam the Stripe / Authorize.Net gateways use). That is
     * why adding an integration never means editing core CSP: the integration
     * carries its own allowance, scoped to when it is actually active.
     *
     * The Maps JS SDK is fetched from maps.googleapis.com (`script-src`). The
     * Places API (New) then issues its prediction / details XHRs to
     * places.googleapis.com, with some supporting requests to
     * maps.googleapis.com / maps.gstatic.com — so `connect-src` needs all
     * three hosts. `img-src` already permits `https:`, so map glyphs need no
     * change.
     *
     * @param mixed $directives Per-directive CSP map.
     * @return array<string, string>
     */
    public function extend_csp( $directives ): array {
        if ( ! is_array( $directives ) ) {
            $directives = array();
        }

        // Drive the policy off the SAME resolved config the checkout JS
        // consumes, not just this module's own options. The loader fetches the
        // Maps SDK precisely when the config is provider=google with an API key
        // (see assets/js/tejcart-checkout.js initAddressAutocomplete()) — and
        // that config may be supplied by another `tejcart_address_autocomplete_config`
        // listener rather than this module's settings. Reading the resolved
        // config here keeps the CSP and the script loader from ever diverging
        // (which would otherwise leave the Maps SDK reported under default-src).
        $config = (array) apply_filters( 'tejcart_address_autocomplete_config', array() );
        if ( 'google' !== (string) ( $config['provider'] ?? '' ) || empty( $config['apiKey'] ) ) {
            return $directives;
        }

        $script_hosts  = 'https://maps.googleapis.com https://maps.gstatic.com';
        $connect_hosts = 'https://maps.googleapis.com https://maps.gstatic.com https://places.googleapis.com';

        $directives['script-src']  = $this->append_csp_sources(
            isset( $directives['script-src'] ) ? (string) $directives['script-src'] : "'self'",
            $script_hosts
        );
        $directives['connect-src'] = $this->append_csp_sources(
            isset( $directives['connect-src'] ) ? (string) $directives['connect-src'] : "'self'",
            $connect_hosts
        );

        return $directives;
    }

    /**
     * Append space-separated CSP sources to a directive, skipping duplicates.
     *
     * @param string $directive Existing directive value.
     * @param string $sources   Space-separated sources to add.
     * @return string
     */
    private function append_csp_sources( string $directive, string $sources ): string {
        $existing = preg_split( '/\s+/', trim( $directive ) ) ?: array();
        foreach ( preg_split( '/\s+/', trim( $sources ) ) ?: array() as $src ) {
            if ( '' !== $src && ! in_array( $src, $existing, true ) ) {
                $existing[] = $src;
            }
        }
        return implode( ' ', $existing );
    }

    /**
     * Append the provider + API-key fields to the Checkout settings tab.
     *
     * The settings framework ({@see \TejCart\Settings\Settings_Page}) reads
     * this filter's output, registers each field with the WordPress Settings
     * API, and persists it as `tejcart_<name>` — so the field names here map
     * directly to {@see self::PROVIDER_OPTION} / {@see self::GOOGLE_KEY_OPTION}.
     *
     * @param mixed $fields Existing checkout settings fields.
     * @return array<int, array<string, mixed>>
     */
    public function register_settings_fields( $fields ): array {
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }

        $fields[] = array(
            'name'    => 'address_autocomplete_provider',
            'label'   => __( 'Address Autocomplete', 'tejcart' ),
            'type'    => 'select',
            'default' => 'none',
            'options' => array(
                'none'   => __( 'Off', 'tejcart' ),
                'google' => __( 'Google Places', 'tejcart' ),
            ),
            'desc'    => __( 'Let shoppers pick their street address from a dropdown that fills in city, state and postcode automatically — a top conversion lever on checkout.', 'tejcart' ),
        );

        $fields[] = array(
            'name'    => 'google_places_api_key',
            'label'   => __( 'Google Places API key', 'tejcart' ),
            'type'    => 'text',
            'default' => '',
            'desc'    => __( 'Required when the provider is Google Places. Create a browser key in Google Cloud with the Maps JavaScript API and Places API enabled, and restrict it to your store domain.', 'tejcart' ),
        );

        return $fields;
    }
}
