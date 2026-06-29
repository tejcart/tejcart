<?php
/**
 * Minimalist cookie-consent banner.
 *
 * Audit #92 / 09 F-013 — the plugin already exposes the
 * `tejcart_has_cookie_consent()` helper and the
 * `tejcart_cookie_consent_given` filter, but ships no UI for the visitor
 * to actually grant or decline consent. This class renders an opt-in
 * banner in `wp_footer` when the `tejcart_require_cookie_consent` option
 * is `'yes'` and no `tejcart_consent` cookie is present. Clicking
 * Accept or Decline sets the cookie via a tiny inline script (no AJAX,
 * no jQuery, no extra HTTP round-trip).
 *
 * Merchants who use a dedicated consent-management plugin
 * (Cookiebot / OneTrust / Iubenda / Complianz / …) should leave
 * `tejcart_require_cookie_consent` at `'no'` (the default) and let
 * the consent plugin handle the UI; the existing
 * `tejcart_cookie_consent_given` filter is the integration point.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render an opt-in cookie-consent banner in wp_footer.
 */
class Cookie_Consent {
    const COOKIE_KEY = 'tejcart_consent';
    const COOKIE_TTL = 31536000; // 1 year, mirrors typical CMP defaults.

    /**
     * Script/style handle for the consent banner assets.
     */
    const HANDLE = 'tejcart-cookie-consent';

    /**
     * Register the asset enqueue (proper hook) and the footer markup.
     *
     * The CSS/JS are enqueued as registered assets — never printed inline —
     * so the plugin passes WordPress.org Plugin Check's no-inline-script/style
     * front-end rule. The dynamic cookie attributes are handed to the script
     * via wp_localize_script instead of interpolating PHP into a <script> tag.
     */
    public function init(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
        // Priority 5 keeps the markup ahead of core's wp_print_footer_scripts
        // (priority 20), so the banner element exists before the enqueued
        // script tag and the handle is still 'enqueued' for the guard below.
        add_action( 'wp_footer', array( $this, 'maybe_render_banner' ), 5 );
    }

    /**
     * Decide whether the banner should be shown on this request. Shared by
     * the asset-enqueue and markup paths so they can never disagree.
     */
    private function should_render(): bool {
        if ( is_admin() || wp_doing_ajax() ) {
            return false;
        }

        if ( 'yes' !== get_option( 'tejcart_require_cookie_consent', 'no' ) ) {
            return false;
        }

        // Use isset(), not !empty(). The decline path writes '0', which
        // `empty()` treats as falsy — so a declined-cookie visitor would
        // keep seeing the banner forever. The helper
        // `tejcart_has_cookie_consent()` still uses !empty() to evaluate
        // the *consent grant*, so '0' correctly means "no consent given".
        if ( isset( $_COOKIE[ self::COOKIE_KEY ] ) ) {
            return false;
        }

        /**
         * Short-circuit the built-in banner so themes / CMPs can render
         * their own UI without `display: none`-ing this one.
         *
         * @param bool $render Whether to render the built-in banner.
         */
        return (bool) apply_filters( 'tejcart_render_cookie_consent_banner', true );
    }

    /**
     * Register and enqueue the banner CSS/JS, and localise the cookie
     * attributes the script needs. Runs on wp_enqueue_scripts (front-end only).
     */
    public function maybe_enqueue_assets(): void {
        if ( ! $this->should_render() ) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            tejcart_asset_url( 'assets/css/tejcart-cookie-consent.css' ),
            array(),
            tejcart_asset_version( 'assets/css/tejcart-cookie-consent.css' )
        );

        wp_enqueue_script(
            self::HANDLE,
            tejcart_asset_url( 'assets/js/tejcart-cookie-consent.js' ),
            array(),
            tejcart_asset_version( 'assets/js/tejcart-cookie-consent.js' ),
            true
        );

        $cookie_path = defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '';
        if ( '' === $cookie_path ) {
            $cookie_path = '/';
        }
        $cookie_domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';

        wp_localize_script(
            self::HANDLE,
            'tejcartCookieConsent',
            array(
                'key'    => self::COOKIE_KEY,
                'ttl'    => (int) self::COOKIE_TTL,
                'path'   => $cookie_path,
                'domain' => $cookie_domain,
                'secure' => is_ssl() ? 1 : 0,
            )
        );
    }

    /**
     * Render the banner markup in wp_footer. Markup only — the behaviour and
     * styling live in the enqueued assets above.
     */
    public function maybe_render_banner(): void {
        if ( ! $this->should_render() ) {
            return;
        }

        // If the assets failed to register for any reason, don't emit an inert
        // banner the visitor can't dismiss.
        if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
            return;
        }

        $privacy_id  = (int) get_option( 'wp_page_for_privacy_policy', 0 );
        $privacy_url = $privacy_id > 0 ? get_permalink( $privacy_id ) : '';

        $message = apply_filters(
            'tejcart_cookie_consent_message',
            __( 'We use cookies to power your cart, save your preferences, and analyse site traffic. You can accept all cookies or decline non-essential cookies.', 'tejcart' )
        );

        $accept_label  = apply_filters( 'tejcart_cookie_consent_accept_label', __( 'Accept', 'tejcart' ) );
        $decline_label = apply_filters( 'tejcart_cookie_consent_decline_label', __( 'Decline', 'tejcart' ) );

        ?>
        <div
            class="tejcart-cookie-banner"
            role="region"
            aria-label="<?php esc_attr_e( 'Cookie consent', 'tejcart' ); ?>"
            data-tejcart-cookie-banner
        >
            <div class="tejcart-cookie-banner__inner">
                <p class="tejcart-cookie-banner__message">
                    <?php echo esc_html( $message ); ?>
                    <?php if ( '' !== $privacy_url ) : ?>
                        <a class="tejcart-cookie-banner__link" href="<?php echo esc_url( $privacy_url ); ?>" rel="noopener">
                            <?php esc_html_e( 'Learn more', 'tejcart' ); ?>
                        </a>
                    <?php endif; ?>
                </p>
                <div class="tejcart-cookie-banner__actions">
                    <button
                        type="button"
                        class="tejcart-button tejcart-button--secondary tejcart-cookie-banner__decline"
                        data-tejcart-cookie-decline
                    >
                        <?php echo esc_html( $decline_label ); ?>
                    </button>
                    <button
                        type="button"
                        class="tejcart-button tejcart-button--primary tejcart-cookie-banner__accept"
                        data-tejcart-cookie-accept
                    >
                        <?php echo esc_html( $accept_label ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
