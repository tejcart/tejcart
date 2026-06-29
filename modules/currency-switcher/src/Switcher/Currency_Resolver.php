<?php
/**
 * Resolves which currency the current request should display.
 *
 * @package TejCart\Currency_Switcher\Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Switcher;

use TejCart\Currency_Switcher\Currency_Config;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Geo\Country_Currency_Map;
use TejCart\Currency_Switcher\Options;
use TejCart\I18n\Geolocation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Priority order for the display currency on the front-end:
 *
 *   1. User cookie `tejcart_csw_currency` (manual selection).
 *   2. Geo cookie  `tejcart_csw_currency_geo` (first-visit IP detection).
 *   3. Store base currency (option `tejcart_currency`).
 *
 * Unknown / not-configured codes fall through to the next priority.
 *
 * In admin context (wp-admin, non-AJAX, non-REST, non-cron) the
 * front-end cookies are ignored unconditionally — reports, dashboards,
 * orders list, customer screens, and other accounting surfaces must
 * resolve to the store's base currency so totals reconcile to the
 * books of record. The `tejcart_csw_admin_display_currency` filter
 * lets a single admin screen (e.g. the order detail page, via
 * Admin_Order_Context) opt back in to a specific currency — typically
 * the order's own transacted currency for that one render. See
 * docs/money-representation.md and Aelia / CURCY for the industry
 * baseline this implements.
 *
 * The resolved code is memoised in a static cache keyed on the cookies
 * + base currency + admin-render flag + admin override so every price
 * filter on a busy page hits the cache after the first call. Tests /
 * hook handlers that flip cookies or the admin override mid-request
 * can call {@see self::flush_shared()} to drop the memo.
 */
final class Currency_Resolver {
    private static ?string $shared_resolved = null;
    private static ?string $shared_key      = null;

    /** Reset memoised state — used by tests and on cookie writes. */
    public function flush(): void {
        self::$shared_resolved = null;
        self::$shared_key      = null;
    }

    /** Static variant for callers that don't hold an instance. */
    public static function flush_shared(): void {
        self::$shared_resolved = null;
        self::$shared_key      = null;
    }

    /**
     * The active display currency code (upper-case ISO-4217).
     */
    public function current(): string {
        $key = $this->memo_key();
        if ( null !== self::$shared_resolved && self::$shared_key === $key ) {
            return self::$shared_resolved;
        }

        $repo = new Currency_Repository();
        $base = $repo->base_currency();

        if ( $this->is_admin_render() ) {
            return self::cache( $key, $this->resolve_admin( $base ) );
        }

        $cookie = $this->read_cookie( Options::COOKIE_CURRENCY );
        if ( '' !== $cookie && ( $cookie === $base || $repo->is_known( $cookie ) ) ) {
            return self::cache( $key, $cookie );
        }

        $geo_cookie = $this->read_cookie( Options::COOKIE_CURRENCY_GEO );
        if ( '' !== $geo_cookie && ( $geo_cookie === $base || $repo->is_known( $geo_cookie ) ) ) {
            return self::cache( $key, $geo_cookie );
        }

        return self::cache( $key, $base );
    }

    /**
     * Admin-context resolution: base currency by default, with an
     * opt-in override via the `tejcart_csw_admin_display_currency`
     * filter (must return a configured currency code or the base
     * code; anything else is rejected and we fall back to base).
     */
    private function resolve_admin( string $base ): string {
        $override = apply_filters( 'tejcart_csw_admin_display_currency', null );
        if ( ! is_string( $override ) || '' === $override ) {
            return $base;
        }
        $normalised = $this->normalise( $override );
        return null !== $normalised ? $normalised : $base;
    }

    /**
     * True when the current request is a server-rendered wp-admin
     * page (not admin-AJAX, not REST, not cron, not CLI). Those
     * background contexts deliberately keep cookie-driven resolution
     * so the storefront's AJAX calls (cart updates, fragments,
     * price refresh) still honour the visitor's currency selection.
     */
    public function is_admin_render(): bool {
        if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        return true;
    }

    /**
     * Active currency's config object, or null if the active currency
     * is the store base (which has no row in the repository).
     */
    public function current_config(): ?Currency_Config {
        return ( new Currency_Repository() )->get( $this->current() );
    }

    /**
     * First-visit geo detection. Returns a configured currency code or
     * null when we can't / shouldn't auto-pick one.
     */
    public function detect_from_geolocation(): ?string {
        $enabled = (int) get_option( Options::ENABLE_GEOLOCATION, 1 );
        if ( 1 !== $enabled ) {
            return null;
        }

        $country = '';
        if ( class_exists( Geolocation::class ) ) {
            $country = Geolocation::get_country();
        }

        if ( '' === $country ) {
            return null;
        }

        $code = Country_Currency_Map::currency_for( $country );
        if ( null === $code ) {
            return null;
        }

        $repo = new Currency_Repository();
        if ( $code === $repo->base_currency() ) {
            return $code;
        }
        return null !== $repo->get( $code ) ? $code : null;
    }

    /**
     * Validate a candidate code and return it if usable.
     */
    public function normalise( string $code ): ?string {
        $code = strtoupper( trim( $code ) );
        if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return null;
        }
        $repo = new Currency_Repository();
        return ( $code === $repo->base_currency() || $repo->is_known( $code ) ) ? $code : null;
    }

    private function read_cookie( string $name ): string {
        if ( empty( $_COOKIE[ $name ] ) ) {
            return '';
        }
        $value = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) );
        return strtoupper( $value );
    }

    /**
     * Memo key — invalidates the cache whenever the inputs that
     * influence resolution change (cookies, base currency, admin
     * render flag, and the admin display-currency override).
     *
     * The admin-render flag is included because the same PHP process
     * can serve both an admin request and a subsequent storefront
     * unit test; if the flag were absent the second call would reuse
     * the first call's cached resolution. The admin override is
     * included so an admin screen that installs the filter mid-render
     * (Admin_Order_Context) sees the override on its very next
     * resolver call without an explicit flush.
     */
    private function memo_key(): string {
        $admin    = $this->is_admin_render();
        $override = $admin
            ? (string) apply_filters( 'tejcart_csw_admin_display_currency', '' )
            : '';
        return implode(
            '|',
            array(
                isset( $_COOKIE[ Options::COOKIE_CURRENCY ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Options::COOKIE_CURRENCY ] ) ) : '',
                isset( $_COOKIE[ Options::COOKIE_CURRENCY_GEO ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Options::COOKIE_CURRENCY_GEO ] ) ) : '',
                (string) get_option( 'tejcart_currency', 'USD' ),
                $admin ? 'admin' : 'front',
                $override,
            )
        );
    }

    private static function cache( string $key, string $value ): string {
        self::$shared_key      = $key;
        self::$shared_resolved = $value;
        return $value;
    }
}
