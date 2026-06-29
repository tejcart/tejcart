<?php
/**
 * TejCart Captcha / Bot Protection module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the `captcha`
 * module toggle is enabled. Supplies the pluggable bot-mitigation gate
 * (Cloudflare Turnstile / hCaptcha / Google reCAPTCHA v3) in front of the
 * four high-friction surfaces card-testing botnets hammer — login,
 * checkout, cart-add and coupon-apply — plus the operator-facing settings
 * UI that mounts under Settings → Advanced → Captcha.
 *
 * This used to ship in core as the always-on `features.bot_gate` feature
 * binding. It is now an opt-in module so a store only pays the boot cost
 * (and the dependency on a third-party captcha service) when the merchant
 * actually wants it. Stores that had already configured a provider are
 * auto-enabled on upgrade by {@see \TejCart\Core\Installer::preserve_legacy_modules()}
 * so live bot protection is never silently dropped.
 *
 * Nothing here contacts a third party until the merchant selects a provider
 * and enters its credentials — the gate short-circuits to "pass" while the
 * provider is `none`.
 *
 * @package TejCart\Captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_CAPTCHA_FILE' ) ) {
    define( 'TEJCART_CAPTCHA_FILE',    __FILE__ );
    define( 'TEJCART_CAPTCHA_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_CAPTCHA_URL',     plugin_dir_url( __FILE__ ) );
}

/**
 * Scoped autoloader for the module. Only resolves classes under the
 * `TejCart\Captcha\` namespace; everything else is left to TejCart core's
 * autoloader (or any other registered handler).
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Captcha\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_CAPTCHA_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    // The verifier attaches to the four core-fired gating filters (and the
    // WP `wp_authenticate` action) so the enforcement points stay in core
    // while the captcha logic lives here.
    if ( class_exists( '\\TejCart\\Captcha\\Bot_Gate' ) ) {
        ( new \TejCart\Captcha\Bot_Gate() )->init();
    }

    // Front-end token provider: enqueues the client-side widget/script that
    // produces the `tejcart_bot_token` the verifier checks. Without it a
    // configured provider would block every gated request instead of
    // challenging bots. Runs on the storefront and the wp-login.php screen.
    if ( ! is_admin() && class_exists( '\\TejCart\\Captcha\\Captcha_Frontend' ) ) {
        ( new \TejCart\Captcha\Captcha_Frontend() )->init();
    }

    // Admin settings UI: registers the POST save handler and injects the
    // "Captcha" section into Settings → Advanced via the core seam filters.
    if ( is_admin() && class_exists( '\\TejCart\\Captcha\\Captcha_Settings_Page' ) ) {
        ( new \TejCart\Captcha\Captcha_Settings_Page() )->init();
    }
}, 20 );
