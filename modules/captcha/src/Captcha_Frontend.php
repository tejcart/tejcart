<?php
/**
 * Captcha front-end loader.
 *
 * Enqueues the client-side bot-gate token provider ({@see assets/js/tejcart-captcha.js})
 * and hands it the active provider + public site key via a localized config
 * object. Without this layer the server-side {@see Bot_Gate} verifier reads a
 * `tejcart_bot_token` that nothing on the page ever produces — so a configured
 * provider would block every gated request (checkout especially) instead of
 * challenging suspected bots. This class supplies the missing half on both the
 * storefront (cart / checkout / coupon surfaces) and the wp-login.php screen.
 *
 * Only the public site key is exposed to the browser; the provider secret
 * stays server-side and is never localized.
 *
 * @package TejCart\Captcha
 */

declare( strict_types=1 );

namespace TejCart\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end enqueue + config for the bot-gate token provider.
 */
class Captcha_Frontend {

    /**
     * Script handle for the front-end token provider.
     */
    private const HANDLE = 'tejcart-captcha';

    /**
     * Plugin-root-relative path to the source asset (min variant is
     * resolved automatically by tejcart_asset_url()).
     */
    private const ASSET = 'assets/js/tejcart-captcha.js';

    /**
     * Register enqueue hooks for the storefront and the login screen.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Enqueue the token provider script and localize its config, but only
     * when a real provider is configured — otherwise the storefront pays
     * no cost and `window.tejcartCaptcha` stays absent (gate = pass).
     *
     * @return void
     */
    public function enqueue(): void {
        $provider = Bot_Gate::active_provider();
        if ( 'none' === $provider ) {
            return;
        }

        $sitekey = $this->public_sitekey( $provider );
        if ( '' === $sitekey ) {
            // Misconfigured (provider chosen but no site key saved). The
            // server gate already fails closed on the missing secret; with
            // no site key there is nothing the browser can render, so skip
            // the enqueue rather than ship a non-functional widget.
            return;
        }

        if ( ! function_exists( 'tejcart_asset_url' ) ) {
            return;
        }

        $version = function_exists( 'tejcart_asset_version' )
            ? tejcart_asset_version( self::ASSET )
            : ( defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0' );

        wp_enqueue_script(
            self::HANDLE,
            tejcart_asset_url( self::ASSET ),
            array(),
            $version,
            true
        );

        wp_localize_script(
            self::HANDLE,
            'tejcartCaptchaConfig',
            array(
                'provider' => $provider,
                'sitekey'  => $sitekey,
            )
        );
    }

    /**
     * Resolve the public site key for the active provider. Site keys are
     * stored in clear (they are rendered to every visitor); only secrets
     * are encrypted at rest, so no decryption is needed here.
     *
     * @param string $provider Active provider key.
     * @return string Site key, or '' when unset / unknown provider.
     */
    private function public_sitekey( string $provider ): string {
        switch ( $provider ) {
            case 'turnstile':
                return (string) get_option( Bot_Gate::OPTION_TURNSTILE_SITEKEY, '' );
            case 'hcaptcha':
                return (string) get_option( Bot_Gate::OPTION_HCAPTCHA_SITEKEY, '' );
            case 'recaptcha_v3':
                return (string) get_option( Bot_Gate::OPTION_RECAPTCHA_SITEKEY, '' );
            default:
                return '';
        }
    }
}
