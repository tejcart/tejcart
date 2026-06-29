<?php
/**
 * Front-end controller — handles currency-switch submissions and the
 * first-visit geo cookie write.
 *
 * @package TejCart\Currency_Switcher\Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Switcher;

use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Three switching entry points (all set the manual cookie and reload):
 *
 *   - POST form    — `tejcart_csw_selected_currency` + nonce.
 *   - Query string — `?tejcart_csw_currency=EUR&_tejcart_csw_nonce=...`.
 *   - AJAX         — `wp_ajax_tejcart_csw_switch_currency`
 *                    + `wp_ajax_nopriv_tejcart_csw_switch_currency`.
 *
 * In addition the controller writes the first-visit geo cookie when
 * `tejcart_csw_enable_geolocation` is on and the user hasn't already
 * picked a currency.
 */
final class Switcher_Controller {
    public function register(): void {
        // Switch handling runs on `template_redirect` rather than `init`
        // so we never short-circuit AJAX / REST / cron entry points
        // before they reach their own handlers — `template_redirect`
        // only fires for full front-end page loads, which is the only
        // place a manual switch can plausibly originate. Geo-cookie
        // priming still needs to run on every front-end request before
        // the resolver reads cookies, so it stays on `init`.
        add_action( 'template_redirect', array( $this, 'handle_post' ), 5 );
        add_action( 'template_redirect', array( $this, 'handle_query_string' ), 5 );
        add_action( 'init', array( $this, 'handle_geo_first_visit' ), 9 );

        add_action( 'wp_ajax_tejcart_csw_switch_currency',        array( $this, 'handle_ajax' ) );
        add_action( 'wp_ajax_nopriv_tejcart_csw_switch_currency', array( $this, 'handle_ajax' ) );
    }

    public function handle_post(): void {
        if ( empty( $_POST['tejcart_csw_selected_currency'] ) ) {
            return;
        }
        $nonce = isset( $_POST['tejcart_csw_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['tejcart_csw_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, Options::NONCE_ACTION ) ) {
            return;
        }
        $candidate = sanitize_text_field( wp_unslash( (string) $_POST['tejcart_csw_selected_currency'] ) );
        $code      = ( new Currency_Resolver() )->normalise( $candidate );
        if ( null === $code ) {
            return;
        }
        ( new Cookies() )->set_currency( $code );
        $this->safe_redirect();
    }

    public function handle_query_string(): void {
        if ( empty( $_GET['tejcart_csw_currency'] ) ) {
            return;
        }
        $nonce = isset( $_GET['_tejcart_csw_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['_tejcart_csw_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, Options::NONCE_SWITCH ) ) {
            return;
        }
        $candidate = sanitize_text_field( wp_unslash( (string) $_GET['tejcart_csw_currency'] ) );
        $code      = ( new Currency_Resolver() )->normalise( $candidate );
        if ( null === $code ) {
            return;
        }
        ( new Cookies() )->set_currency( $code );
        $this->safe_redirect();
    }

    public function handle_ajax(): void {
        // Audit #10 / 04 H-2 — this is a state-mutating endpoint
        // (writes the display-currency cookie). Reading nonce + value
        // from $_REQUEST (= $_GET + $_POST + $_COOKIE) accepted CSRF
        // via a top-level navigation under SameSite=Lax, e.g.
        //   <img src="/wp-admin/admin-ajax.php?action=...&nonce=...">
        // because the rendered page already embeds NONCE_ACTION for
        // the dropdown form. Restrict to POST and read body fields
        // directly, mirroring handle_post().
        if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
            wp_send_json_error( array( 'message' => 'method_not_allowed' ), 405 );
        }
        $nonce = isset( $_POST['tejcart_csw_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['tejcart_csw_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, Options::NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
        }
        $candidate = isset( $_POST['currency'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['currency'] ) )
            : '';
        $code = ( new Currency_Resolver() )->normalise( $candidate );
        if ( null === $code ) {
            wp_send_json_error( array( 'message' => 'invalid_currency' ), 400 );
        }
        ( new Cookies() )->set_currency( $code );
        wp_send_json_success( array( 'currency' => $code ) );
    }

    /**
     * Write the geo-detected currency cookie on the visitor's first
     * request when geolocation is enabled and no manual cookie exists
     * yet. The resolver itself reads the cookies — this method only
     * primes them.
     */
    public function handle_geo_first_visit(): void {
        if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }
        if ( ! empty( $_COOKIE[ Options::COOKIE_CURRENCY ] ) ) {
            return;
        }
        if ( ! empty( $_COOKIE[ Options::COOKIE_CURRENCY_GEO ] ) ) {
            return;
        }
        $detected = ( new Currency_Resolver() )->detect_from_geolocation();
        if ( null === $detected ) {
            return;
        }
        ( new Cookies() )->set_geo_currency( $detected );
    }

    /**
     * Redirect back to the same URL minus any switch-related query
     * vars. Falls through to the homepage if we can't determine one.
     *
     * Order of preference:
     *   1. `REQUEST_URI` — the page the visitor was on when they picked
     *      a new currency. The dropdown form posts to `action=""` (the
     *      same URL), so this is the canonical "stay on this page"
     *      target. `wp_get_referer()` returns false when the referer
     *      equals the current URL, so a same-page POST from a product
     *      page would otherwise fall through to `home_url('/')` and
     *      bounce the visitor off their product detail page.
     *   2. `wp_get_referer()` — covers query-string switching from
     *      another page (e.g. the sidebar's `?tejcart_csw_currency=…`
     *      link) when REQUEST_URI is somehow empty.
     *   3. `home_url('/')` — last-resort fallback so we never emit an
     *      empty Location header.
     */
    private function safe_redirect(): void {
        $target = '';

        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
            : '';
        if ( '' !== $request_uri ) {
            $target = home_url( $request_uri );
        }

        if ( '' === $target ) {
            $referer = wp_get_referer();
            if ( is_string( $referer ) && '' !== $referer ) {
                $target = $referer;
            }
        }

        if ( '' === $target ) {
            $target = home_url( '/' );
        }

        $target = remove_query_arg(
            array( 'tejcart_csw_currency', '_tejcart_csw_nonce' ),
            $target
        );
        wp_safe_redirect( $target );
        // exit() under PHP-FPM / Apache; under PHPUnit and WP-CLI the
        // `wp_csw_skip_exit` filter lets the harness stop the process
        // here without aborting the test process. Without the exit, a
        // poorly-written theme could continue to echo output AFTER the
        // Location header — wasting CPU and risking content leakage
        // into the redirect response body.
        if ( apply_filters( 'tejcart_csw_skip_exit_after_redirect', false ) ) {
            return;
        }
        exit;
    }
}
