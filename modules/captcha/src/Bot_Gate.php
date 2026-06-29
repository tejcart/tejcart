<?php
/**
 * Bot mitigation gate (S-2).
 *
 * Pluggable CAPTCHA / Turnstile / hCaptcha / reCAPTCHA-v3 verifier in
 * front of the four high-friction surfaces that card-testing botnets
 * actually hammer:
 *
 *   - login (after a configurable number of failures)
 *   - cart velocity (same buyer + same product within a tight window)
 *   - checkout submit
 *   - coupon-apply (after a configurable number of invalid codes)
 *
 * Default provider is `none` — operators opt in via Settings → Advanced
 * → Captcha. Per-IP rate limits remain in front of every endpoint; the
 * bot gate is an additional layer that defeats residential-proxy botnets
 * which ride past per-IP limits.
 *
 * Shipped as the optional `captcha` module (was the core `features.bot_gate`
 * binding before 1.0.1). The four gating filters and the `wp_authenticate`
 * action it hooks are fired by core regardless of whether this module is
 * loaded, so the enforcement points are unaffected when it is disabled.
 *
 * @package TejCart\Captcha
 */

declare( strict_types=1 );

namespace TejCart\Captcha;

use TejCart\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bot_Gate {

    public const OPTION_PROVIDER          = 'tejcart_bot_gate_provider';
    public const OPTION_TURNSTILE_SITEKEY = 'tejcart_turnstile_sitekey';
    public const OPTION_TURNSTILE_SECRET  = 'tejcart_turnstile_secret';
    public const OPTION_HCAPTCHA_SITEKEY  = 'tejcart_hcaptcha_sitekey';
    public const OPTION_HCAPTCHA_SECRET   = 'tejcart_hcaptcha_secret';
    public const OPTION_RECAPTCHA_SITEKEY = 'tejcart_recaptcha_sitekey';
    public const OPTION_RECAPTCHA_SECRET  = 'tejcart_recaptcha_secret';
    public const OPTION_RECAPTCHA_THRESH  = 'tejcart_recaptcha_threshold';

    public function init(): void {
        // Hook into the four surfaces. cart_pre_add / checkout_pre_validate /
        // apply_coupon_pre are fired by Cart and Checkout (see F-C1 / #923).
        // The login gate self-attaches to WP's `wp_authenticate` action so
        // it does not depend on a TejCart-fired hook for the login surface.
        add_filter( 'tejcart_checkout_pre_validate', array( $this, 'gate_checkout' ), 5, 1 );
        add_filter( 'tejcart_cart_pre_add',          array( $this, 'gate_cart' ),     5, 1 );
        add_filter( 'tejcart_apply_coupon_pre',      array( $this, 'gate_coupon' ),   5, 2 );
        add_action( 'wp_authenticate',               array( $this, 'gate_login_action' ), 5, 1 );
        // Back-compat listener for any consumer firing the documented
        // `tejcart_login_pre_authenticate` filter directly.
        add_filter( 'tejcart_login_pre_authenticate', array( $this, 'gate_login' ),   5, 2 );
    }

    /**
     * `wp_authenticate` action handler. Runs the documented
     * `tejcart_login_pre_authenticate` filter so any external listener
     * still gets a chance, then issues a `wp_die` on Bot_Gate failure
     * (the only safe abort point for the login flow — wp_authenticate
     * is called via login_form_login before the wp-login.php response
     * is built; throwing returns the user to the login form).
     *
     * @param string $username Username typed into the login form.
     * @return void
     */
    public function gate_login_action( $username ): void {
        // First, surface the filter to any other listener.
        $filtered = apply_filters( 'tejcart_login_pre_authenticate', true, (string) $username );
        if ( is_wp_error( $filtered ) ) {
            wp_die(
                esc_html( $filtered->get_error_message() ),
                esc_html__( 'Login blocked', 'tejcart' ),
                array( 'response' => 403, 'back_link' => true )
            );
        }
    }

    /**
     * Public entry: assert the request carried a valid bot-gate token.
     *
     * @param string $action Operator-readable action label
     *                       ('checkout' | 'cart_add' | 'login' | 'coupon_apply').
     * @return true|\WP_Error true on pass, WP_Error on fail.
     */
    public static function require_pass( string $action ) {
        $provider = self::active_provider();
        if ( 'none' === $provider ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The bot token is a separate verification primitive from nonces.
        $token = isset( $_POST['tejcart_bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_bot_token'] ) ) : '';
        if ( '' === $token ) {
            self::log(
                'Request blocked: bot-protection token missing on submission',
                array(
                    'surface'  => $action,
                    'provider' => $provider,
                    'reason'   => 'token_missing',
                )
            );
            return new \WP_Error( 'tejcart_bot_token_missing', __( 'Bot-protection token missing.', 'tejcart' ) );
        }

        $verified = false;
        switch ( $provider ) {
            case 'turnstile':
                $verified = self::verify_turnstile( $token, $action );
                break;
            case 'hcaptcha':
                $verified = self::verify_hcaptcha( $token, $action );
                break;
            case 'recaptcha_v3':
                $verified = self::verify_recaptcha_v3( $token, $action );
                break;
            default:
                $verified = true;
        }

        if ( ! $verified ) {
            self::log(
                'Request blocked: bot-protection verification failed',
                array(
                    'surface'  => $action,
                    'provider' => $provider,
                    'reason'   => 'verification_failed',
                )
            );
            return new \WP_Error(
                'tejcart_bot_token_invalid',
                __( 'Bot-protection check failed. Please refresh and try again.', 'tejcart' )
            );
        }

        return true;
    }

    public static function active_provider(): string {
        $stored  = (string) get_option( self::OPTION_PROVIDER, 'none' );
        $allowed = array( 'none', 'turnstile', 'hcaptcha', 'recaptcha_v3' );
        if ( ! in_array( $stored, $allowed, true ) ) {
            return 'none';
        }
        /**
         * Filter the active bot-mitigation provider.
         *
         * @param string $provider one of: none, turnstile, hcaptcha, recaptcha_v3
         */
        return (string) apply_filters( 'tejcart_bot_gate_provider', $stored );
    }

    /**
     * Cloudflare Turnstile siteverify.
     */
    private static function verify_turnstile( string $token, string $action ): bool {
        $secret = self::read_secret( self::OPTION_TURNSTILE_SECRET );
        if ( '' === $secret ) {
            self::log_secret_missing( 'turnstile', $action );
            return false;
        }
        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            array(
                'timeout' => 5,
                'body'    => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => Rate_Limiter::get_client_ip(),
                ),
            )
        );
        return self::evaluate_siteverify( 'turnstile', $action, $response );
    }

    /**
     * hCaptcha siteverify.
     */
    private static function verify_hcaptcha( string $token, string $action ): bool {
        $secret = self::read_secret( self::OPTION_HCAPTCHA_SECRET );
        if ( '' === $secret ) {
            self::log_secret_missing( 'hcaptcha', $action );
            return false;
        }
        $response = wp_remote_post(
            'https://hcaptcha.com/siteverify',
            array(
                'timeout' => 5,
                'body'    => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => Rate_Limiter::get_client_ip(),
                ),
            )
        );
        return self::evaluate_siteverify( 'hcaptcha', $action, $response );
    }

    /**
     * Google reCAPTCHA v3 siteverify with score threshold.
     */
    private static function verify_recaptcha_v3( string $token, string $action ): bool {
        $secret = self::read_secret( self::OPTION_RECAPTCHA_SECRET );
        if ( '' === $secret ) {
            self::log_secret_missing( 'recaptcha_v3', $action );
            return false;
        }
        $threshold = (float) get_option( self::OPTION_RECAPTCHA_THRESH, 0.5 );
        $threshold = max( 0.0, min( 1.0, $threshold ) );

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            array(
                'timeout' => 5,
                'body'    => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => Rate_Limiter::get_client_ip(),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::log(
                'reCAPTCHA siteverify request failed (provider unreachable)',
                array(
                    'surface'  => $action,
                    'provider' => 'recaptcha_v3',
                    'reason'   => 'http_error',
                    'detail'   => $response->get_error_message(),
                )
            );
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            self::log(
                'reCAPTCHA siteverify returned an unparseable response',
                array( 'surface' => $action, 'provider' => 'recaptcha_v3', 'reason' => 'bad_response' )
            );
            return false;
        }
        $ok    = ! empty( $body['success'] );
        $score = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
        if ( ! $ok ) {
            self::log(
                'reCAPTCHA rejected the token',
                array(
                    'surface'     => $action,
                    'provider'    => 'recaptcha_v3',
                    'reason'      => 'rejected',
                    'error_codes' => self::error_codes( $body ),
                )
            );
            return false;
        }
        if ( $score < $threshold ) {
            self::log(
                'reCAPTCHA score below configured threshold',
                array(
                    'surface'   => $action,
                    'provider'  => 'recaptcha_v3',
                    'reason'    => 'low_score',
                    'score'     => $score,
                    'threshold' => $threshold,
                )
            );
            return false;
        }
        return true;
    }

    /**
     * Decode a Turnstile / hCaptcha siteverify response, logging the
     * provider-supplied failure reason so a merchant can tell a wrong
     * secret key (`invalid-input-secret`) from an expired/duplicate token
     * from a provider outage.
     *
     * @param string                 $provider Provider key for the log line.
     * @param string                 $action   Gate surface label.
     * @param array|\WP_Error|mixed  $response wp_remote_post result.
     * @return bool True only when the provider reports success.
     */
    private static function evaluate_siteverify( string $provider, string $action, $response ): bool {
        if ( is_wp_error( $response ) ) {
            self::log(
                'Captcha siteverify request failed (provider unreachable)',
                array(
                    'surface'  => $action,
                    'provider' => $provider,
                    'reason'   => 'http_error',
                    'detail'   => $response->get_error_message(),
                )
            );
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            self::log(
                'Captcha siteverify returned an unparseable response',
                array( 'surface' => $action, 'provider' => $provider, 'reason' => 'bad_response' )
            );
            return false;
        }
        if ( empty( $body['success'] ) ) {
            self::log(
                'Captcha provider rejected the token',
                array(
                    'surface'     => $action,
                    'provider'    => $provider,
                    'reason'      => 'rejected',
                    'error_codes' => self::error_codes( $body ),
                )
            );
            return false;
        }
        return true;
    }

    /**
     * Pull the provider's `error-codes` array (Turnstile / hCaptcha /
     * reCAPTCHA all use this key) into a flat, log-safe string.
     *
     * @param array<string,mixed> $body Decoded siteverify body.
     * @return string Comma-joined codes, or '(none)'.
     */
    private static function error_codes( array $body ): string {
        if ( empty( $body['error-codes'] ) || ! is_array( $body['error-codes'] ) ) {
            return '(none)';
        }
        $codes = array_map( static fn ( $c ): string => sanitize_text_field( (string) $c ), $body['error-codes'] );
        return implode( ', ', $codes );
    }

    private static function log_secret_missing( string $provider, string $action ): void {
        self::log(
            'Request blocked: provider selected but its secret key is not configured (failing closed)',
            array(
                'surface'  => $action,
                'provider' => $provider,
                'reason'   => 'secret_missing',
            )
        );
    }

    /**
     * Write a captcha diagnostic line to the always-on `captcha` channel,
     * tagging it with the redacted client IP so bursts from one source are
     * still correlatable without storing a raw (PII) address.
     *
     * @param string               $event   Human-readable description.
     * @param array<string, mixed> $context Structured fields.
     * @return void
     */
    private static function log( string $event, array $context = array() ): void {
        if ( ! function_exists( 'tejcart_captcha_log' ) ) {
            return;
        }
        if ( ! isset( $context['ip'] ) ) {
            $ip = Rate_Limiter::get_client_ip();
            $context['ip'] = class_exists( '\\TejCart\\Security\\Log_Redactor' )
                ? \TejCart\Security\Log_Redactor::ip( (string) $ip )
                : '(redacted)';
        }
        tejcart_captcha_log( $event, $context );
    }

    // Filter callbacks — each surface decides whether the gate is
    // active for THIS request, and short-circuits with a WP_Error when
    // the token check fails.

    public function gate_checkout( $passthrough ) {
        $result = self::require_pass( 'checkout' );
        return is_wp_error( $result ) ? $result : $passthrough;
    }

    public function gate_cart( $passthrough ) {
        // Cart-add is high-frequency; only gate when the per-IP velocity
        // limiter has fired (= probable bot). Read the `add_to_cart_ip`
        // bucket — the one Cart::add() records keyed by bare IP
        // (see Cart.php) — rather than `add_to_cart`, which is keyed by
        // IP + session identity and so is never written under the plain
        // IP this gate reads.
        $ip = Rate_Limiter::get_client_ip();
        if ( Rate_Limiter::get_attempts( 'add_to_cart_ip', $ip ) < 20 ) {
            return $passthrough;
        }
        $result = self::require_pass( 'cart_add' );
        return is_wp_error( $result ) ? $result : $passthrough;
    }

    public function gate_login( $passthrough, $username = '' ) {
        // Read the same bucket Login_Rate_Limiter records failures into:
        // action `tejcart_login`, keyed by IP + lower-cased/trimmed
        // username. The previous `login_failed` action was never written
        // anywhere, so the gate could never engage. The captcha threshold
        // (3) sits below Login_Rate_Limiter's hard lockout (5) so the
        // CAPTCHA challenge appears before the account is fully locked.
        $ip = Rate_Limiter::get_client_ip();
        $id = $ip . '|' . strtolower( trim( (string) $username ) );
        if ( Rate_Limiter::get_attempts( 'tejcart_login', $id ) < 3 ) {
            return $passthrough;
        }
        $result = self::require_pass( 'login' );
        return is_wp_error( $result ) ? $result : $passthrough;
    }

    public function gate_coupon( $passthrough, $code = '' ) {
        // Read the per-IP cross-code failure counter that Cart_Ajax
        // records on every failed coupon apply (`coupon_apply_cross_code`,
        // keyed by bare IP). The previous `coupon_failed` action keyed by
        // IP + code was never written, so the gate never engaged.
        $ip = Rate_Limiter::get_client_ip();
        if ( Rate_Limiter::get_attempts( 'coupon_apply_cross_code', $ip ) < 3 ) {
            return $passthrough;
        }
        $result = self::require_pass( 'coupon_apply' );
        return is_wp_error( $result ) ? $result : $passthrough;
    }

    /**
     * Read a captcha provider secret with transparent ciphertext
     * fallback. New writes are encrypted via Crypto::encrypt_required
     * (see Captcha_Settings_Page); legacy plaintext rows pre-migration
     * still decrypt as-is via Crypto::decrypt()'s passthrough path.
     */
    private static function read_secret( string $option ): string {
        $stored = (string) get_option( $option, '' );
        if ( '' === $stored ) {
            return '';
        }
        if ( ! class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            return $stored;
        }
        // Crypto::decrypt is safe to call on plaintext — it returns the
        // input unchanged when the prefix marker is absent. That lets
        // pre-migration installs keep working until the operator saves
        // the form once, at which point Captcha_Settings_Page persists
        // the ciphertext version.
        return \TejCart\Security\Crypto::decrypt( $stored );
    }
}
