<?php
/**
 * Lightweight geolocation + multilingual compatibility layer.
 *
 * @package TejCart\I18n
 */

declare( strict_types=1 );

namespace TejCart\I18n;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides best-effort country detection for the current request and
 * declares compatibility with the two most common multilingual plugins
 * (WPML and Polylang).
 *
 * Country detection priority:
 *   1. CloudFlare / edge CDN headers (CF-IPCountry, X-Appengine-Country, X-Country-Code).
 *   2. Accept-Language preference when the header contains a region qualifier.
 *   3. The `tejcart_store_country` option as a final fallback.
 *
 * Results are cached per-request.
 */
class Geolocation {
    /**
     * Per-request cache of the detected country code.
     *
     * @var string|null
     */
    private static ?string $cached_country = null;

    /**
     * Register WPML/Polylang compat hooks.
     */
    public function init(): void {
        add_filter( 'wpml_custom_post_types', array( $this, 'declare_wpml_types' ) );
        add_filter( 'pll_get_post_types', array( $this, 'declare_polylang_types' ), 10, 2 );
        add_filter( 'tejcart_detected_country', array( $this, 'filter_store_country_fallback' ), 99 );
    }

    /**
     * Best-effort detection of the visitor's country (ISO-3166 alpha-2).
     *
     * @return string Two-letter upper-case code; empty string when unknown.
     */
    public static function get_country(): string {
        if ( null !== self::$cached_country ) {
            return self::$cached_country;
        }

        $country = self::from_edge_header();
        if ( '' === $country ) {
            $country = self::from_accept_language();
        }

        $country = strtoupper( $country );

        /**
         * Filter the detected country before it is cached.
         *
         * @param string $country Two-letter code, or empty string.
         */
        $country = (string) apply_filters( 'tejcart_detected_country', $country );

        self::$cached_country = $country;

        return self::$cached_country;
    }

    /**
     * Read a country code from a CDN / reverse-proxy header.
     *
     * @return string
     */
    private static function from_edge_header(): string {
        $candidates = array(
            'HTTP_CF_IPCOUNTRY',
            'HTTP_X_APPENGINE_COUNTRY',
            'HTTP_X_COUNTRY_CODE',
            'GEOIP_COUNTRY_CODE',
        );

        foreach ( $candidates as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) {
                continue;
            }
            $value = strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) );
            if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract a region from the Accept-Language header (e.g. en-GB → GB).
     *
     * @return string
     */
    private static function from_accept_language(): string {
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return '';
        }

        $header = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
        if ( preg_match( '/[a-z]{2,3}[-_]([A-Za-z]{2})/', $header, $match ) ) {
            return strtoupper( $match[1] );
        }

        return '';
    }

    /**
     * Default to the configured store country when detection fails.
     *
     * @param string $country Currently-detected country code.
     * @return string
     */
    public function filter_store_country_fallback( string $country ): string {
        if ( '' !== $country ) {
            return $country;
        }

        return strtoupper( (string) get_option( 'tejcart_store_country', '' ) );
    }

    /**
     * Declare TejCart's CPT-adjacent types as translatable under WPML.
     *
     * TejCart products live in a custom table rather than a CPT, so
     * WPML won't discover them automatically — this filter signals
     * that the review comment-type and any tejcart-managed post types
     * should be offered for translation.
     *
     * @param array $types WPML-registered translatable types.
     * @return array
     */
    public function declare_wpml_types( $types ): array {
        $types = is_array( $types ) ? $types : array();

        // WPML may apply this filter while WordPress is still on
        // `plugins_loaded`. Calling __() before the `init` action triggers
        // WP 6.7+'s "translation loading too early" notice, so fall back to
        // the raw label until init has fired — the WPML settings UI that
        // surfaces this name only renders post-init.
        $types['tejcart_review'] = array(
            'slug' => 'tejcart_review',
            'name' => did_action( 'init' ) ? __( 'TejCart review', 'tejcart' ) : 'TejCart review',
        );

        return $types;
    }

    /**
     * Opt TejCart comment-types into Polylang's translatable list.
     *
     * @param array  $post_types Current list.
     * @param bool   $is_settings Whether Polylang is rendering its settings UI.
     * @return array
     */
    public function declare_polylang_types( $post_types, $is_settings = false ): array {
        $post_types = is_array( $post_types ) ? $post_types : array();

        $post_types[] = 'tejcart_review';

        return $post_types;
    }
}
