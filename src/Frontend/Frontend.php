<?php
/**
 * Frontend bootstrap class.
 *
 * Enqueues public-facing assets and initialises shortcodes and blocks.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Download\Download_Manager;
use TejCart\Frontend\Schema;
use TejCart\Frontend\Product_SEO;
use TejCart\Frontend\HTTP_Cache;
use TejCart\Frontend\Breadcrumbs;
use TejCart\Frontend\Product_Sort;
use TejCart\Frontend\Recently_Viewed;
use TejCart\Wishlist\Wishlist;
use TejCart\Cart\Stock_Reservation;
use TejCart\Checkout\Shipping_Method_Capture;
use TejCart\Order\Invoice;

/**
 * Hooks into WordPress to load all front-end functionality.
 */
class Frontend {
    /**
     * Shortcodes handler instance.
     *
     * @var Shortcodes
     */
    private $shortcodes;

    /**
     * Download manager instance.
     *
     * @var Download_Manager
     */
    private $download_manager;

    /**
     * Schema.org structured data handler.
     *
     * @var Schema
     */
    private $schema;

    /**
     * Breadcrumbs handler.
     *
     * @var Breadcrumbs
     */
    private $breadcrumbs;

    /**
     * Product sort handler.
     *
     * @var Product_Sort
     */
    private $product_sort;

    /**
     * Per-request memo of has_block() lookups, keyed "{post_id}:{block}".
     *
     * @var array<string, bool>
     */
    private array $block_presence_cache = array();

    /**
     * Wire up WordPress hooks and child components.
     */
    public function init(): void {
        // Register the shared base stylesheet unconditionally (priority 5,
        // before the gated enqueue at 10). Registration ships no bytes —
        // nothing is printed until the handle is enqueued — but it makes
        // `tejcart-public` a valid dependency target on *every* front-end
        // request. Optional features that can render outside a TejCart
        // surface (e.g. a header search box on the homepage via the search
        // module) depend on `tejcart-public` for its design tokens; without
        // a global registration WP 6.9.1+ emits a "dependencies that are not
        // registered" _doing_it_wrong notice when they enqueue on a
        // non-TejCart page. The per-surface *enqueue* stays gated below.
        add_action( 'wp_enqueue_scripts', array( $this, 'register_base_styles' ), 5 );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_action( 'wp_footer', array( $this, 'maybe_render_cart_drawer' ) );

        $this->shortcodes = new Shortcodes();
        $this->shortcodes->init();

        $this->download_manager = new Download_Manager();
        $this->download_manager->init();

        $this->schema = new Schema();
        $this->schema->init();

        ( new Product_SEO() )->init();
        ( new HTTP_Cache() )->init();

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs->init();

        $this->product_sort = new Product_Sort();
        $this->product_sort->init();

        ( new Recently_Viewed() )->init();
        ( new Wishlist() )->init();
        ( new Stock_Reservation() )->init();
        ( new Shipping_Method_Capture() )->init();
        ( new Invoice() )->init();
        // Audit #92 / 09 F-013 — built-in cookie-consent banner. Renders
        // only when the merchant has set `tejcart_require_cookie_consent`
        // to 'yes' and the visitor hasn't yet decided.
        ( new Cookie_Consent() )->init();
        // Audit 01 #7 / #1480 — AJAX quick-view modal.
        ( new Quick_View() )->init();
    }

    /**
     * Gate wrapping {@see enqueue_assets()} — only proceed when the
     * current request actually needs TejCart's CSS/JS.
     *
     * Audit #80 / 08 #8: before this gate the four "always-on" enqueues
     * (`tejcart-public`, `tejcart-side-cart`, `tejcart-express-checkout`,
     * and the `tejcart-cart.js` script) shipped on every front-end
     * request — homepage, blog, contact pages, search results — even
     * when no TejCart shortcode/block/cart-drawer was in use. That meant
     * sites that put TejCart on a single `/shop/` page still paid the
     * stylesheet+script bytes on every other route they served.
     *
     * The gate {@see should_enqueue_assets()} encapsulates the decision
     * (TejCart surface? shortcode/block on the post? force-enqueue
     * filter?) so the same logic powers both the asset enqueue and the
     * cart-drawer render in {@see maybe_render_cart_drawer()}.
     *
     * @return void
     */
    public function maybe_enqueue_assets(): void {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }
        $this->enqueue_assets();
    }

    /**
     * Footer-render wrapper around {@see render_cart_drawer()} that
     * honours the same gate. Without this, the drawer template (and the
     * `tejcart-side-cart` CSS that styles it) leaked into every page
     * that wasn't a TejCart surface — and pulled in the public stylesheet
     * with it.
     *
     * Note we skip both the aria-live regions AND the drawer when gated
     * off — there is no `tejcart-cart.js` on this page to announce
     * into the regions, so emitting them would be dead markup.
     *
     * @return void
     */
    public function maybe_render_cart_drawer(): void {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }
        $this->render_cart_drawer();
    }

    /**
     * Should we enqueue the TejCart front-end stylesheet/JS bundle on
     * this request?
     *
     * Yes when ANY of:
     *  - The current page is a known TejCart surface (shop, product,
     *    cart, checkout, account — i.e. {@see is_shop_surface()},
     *    {@see is_cart_page()}, {@see is_checkout_page()}, or
     *    {@see is_account_page()}).
     *  - The current singular post's content carries a TejCart shortcode
     *    (`[tejcart_*]`) or a TejCart Gutenberg block (`tejcart/…`).
     *  - The `tejcart_force_enqueue_assets` filter returns true. That's
     *    the escape hatch for themes that render a header / footer
     *    mini-cart on every page and need the side-cart drawer JS
     *    sitewide regardless of the post content.
     *
     * @since 1.0.0 (audit #80)
     *
     * @return bool
     */
    public function should_enqueue_assets(): bool {
        /**
         * Force the TejCart frontend asset bundle to load regardless of
         * the surface checks below. Themes that mount a mini-cart in
         * the header or footer on every page should return true here.
         *
         * Filter receives the current bool default (false) — return true
         * to short-circuit the surface-detection path entirely.
         *
         * @since 1.0.0
         *
         * @param bool $force Default false.
         */
        if ( apply_filters( 'tejcart_force_enqueue_assets', false ) ) {
            return true;
        }

        // Known TejCart surfaces — same predicates already used to gate
        // per-surface stylesheets inside enqueue_assets().
        if ( $this->is_cart_page() ) {
            return true;
        }
        if ( $this->is_checkout_page() ) {
            return true;
        }
        if ( $this->is_account_page() ) {
            return true;
        }
        if ( $this->is_shop_surface() ) {
            return true;
        }

        // Order-thankyou, mini-cart, and pay-for-order shortcodes are
        // typically dropped on dedicated pages — treat them as TejCart
        // surfaces. A literal "[tejcart_button" inside a code block
        // doesn't pass the has_shortcode() parser check.
        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $post = function_exists( 'get_post' ) ? get_post() : null;
            if ( $post ) {
                $content = (string) ( $post->post_content ?? '' );
                if ( '' !== $content ) {
                    // Fast pre-filter — skip the parser walk on posts
                    // that don't even mention TejCart.
                    if ( function_exists( 'has_shortcode' )
                         && false !== stripos( $content, '[tejcart_' )
                    ) {
                        foreach ( self::tejcart_shortcode_tags() as $tag ) {
                            if ( has_shortcode( $content, $tag ) ) {
                                return true;
                            }
                        }
                    }

                    // Gutenberg blocks — namespace is `tejcart/<name>`.
                    if ( function_exists( 'has_block' ) && has_block( 'tejcart', $post ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Enumerated TejCart shortcode tags used by {@see should_enqueue_assets()}.
     *
     * Pinned as a static method so unit tests and the gate share the
     * same list; the matching `add_shortcode()` calls live in
     * {@see Shortcodes::init()}.
     *
     * @return string[]
     */
    private static function tejcart_shortcode_tags(): array {
        return array(
            'tejcart_button',
            'tejcart_cart',
            'tejcart_product',
            'tejcart_products',
            'tejcart_checkout',
            'tejcart_account',
            'tejcart_thankyou',
            'tejcart_mini_cart',
            'tejcart_product_category',
            'tejcart_sale_products',
            'tejcart_best_selling_products',
            'tejcart_request_return',
            'tejcart_my_returns',
        );
    }

    /**
     * Cache-busting version for the core front-end asset bundle.
     *
     * TEJCART_VERSION suffixed with the plugin file's mtime so a deploy
     * that ships new minified bundles actually busts browser / CDN caches
     * keyed on the `?ver=` query string — without this a merchant on a
     * long-lived edge cache keeps serving the stale bundle after an update.
     *
     * @return string
     */
    private function asset_bundle_version(): string {
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        if ( defined( 'TEJCART_PLUGIN_FILE' ) && file_exists( TEJCART_PLUGIN_FILE ) ) {
            $version .= '.' . filemtime( TEJCART_PLUGIN_FILE );
        }

        return $version;
    }

    /**
     * Register the shared base stylesheet on every front-end request.
     *
     * This only *registers* the handle — it ships no bytes and prints
     * nothing until something enqueues `tejcart-public` (or a stylesheet
     * that depends on it). Keeping the registration ungated means optional
     * features that can surface outside a TejCart page (the search module's
     * autocomplete box, for example) can safely declare a `tejcart-public`
     * dependency without tripping WP 6.9.1+'s unregistered-dependency
     * notice. The per-surface *enqueue* remains gated in {@see enqueue_assets()}.
     *
     * @return void
     */
    public function register_base_styles(): void {
        if ( wp_style_is( 'tejcart-public', 'registered' ) ) {
            return;
        }

        wp_register_style(
            'tejcart-public',
            tejcart_asset_url( 'assets/css/tejcart-public.css' ),
            array(),
            $this->asset_bundle_version()
        );
    }

    /**
     * Enqueue public-facing styles and scripts.
     *
     * Stylesheets are split per surface so a page only ships the rules
     * it actually needs:
     *
     *   tejcart-public          → design tokens + shared base (every page).
     *   tejcart-side-cart       → drawer (every page — drawer is always rendered).
     *   tejcart-express-checkout→ wherever express buttons render.
     *   tejcart-shop            → shop / product-listing surfaces.
     *   tejcart-cart-page       → the cart page only.
     *   tejcart-checkout        → the checkout page only.
     */
    public function enqueue_assets(): void {
        $version = $this->asset_bundle_version();

        // `tejcart-public` is registered unconditionally in
        // {@see register_base_styles()}; enqueue it by handle so the inline
        // theme-color styles and dependent stylesheets resolve.
        wp_enqueue_style( 'tejcart-public' );

        wp_enqueue_style(
            'tejcart-side-cart',
            tejcart_asset_url( 'assets/css/tejcart-side-cart.css' ),
            array( 'tejcart-public' ),
            $version
        );

        $paypal_gateway   = tejcart()->gateways()->get_gateway( 'tejcart_paypal' );
        $load_paypal_sdk  = $this->should_load_paypal_sdk( $paypal_gateway );
        $paypal_lazy_load = ! $load_paypal_sdk && $this->needs_paypal_lazy_load( $paypal_gateway );

        if ( $load_paypal_sdk || $paypal_lazy_load ) {
            wp_enqueue_style(
                'tejcart-express-checkout',
                tejcart_asset_url( 'assets/css/tejcart-express-checkout.css' ),
                array( 'tejcart-public' ),
                $version
            );
        }

        $needs_shop_styles = $this->is_shop_surface() || $this->is_cart_page();
        if ( $needs_shop_styles ) {
            wp_enqueue_style(
                'tejcart-shop',
                tejcart_asset_url( 'assets/css/tejcart-shop.css' ),
                array( 'tejcart-public' ),
                $version
            );
        }

        if ( $this->is_shop_surface() ) {
            // Audit 01 #7 / #1480 — quick-view assets only ship on shop
            // surfaces (catalogue / category / single-product). The
            // triggers live on product cards, so other surfaces wouldn't
            // see them.
            wp_enqueue_style(
                'tejcart-quick-view',
                tejcart_asset_url( 'assets/css/tejcart-quick-view.css' ),
                array( 'tejcart-public' ),
                $version
            );
            wp_enqueue_script(
                'tejcart-quick-view',
                tejcart_asset_url( 'assets/js/tejcart-quick-view.js' ),
                array(),
                $version,
                true
            );
            wp_localize_script(
                'tejcart-quick-view',
                'TejCartQuickView',
                Quick_View::js_payload()
            );

            // Audit 08 #20 — only enqueue the single-product helpers on
            // pages that actually render tabs / reviews. Shop archives
            // ship neither component.
            $is_single_product = function_exists( 'tejcart_is_single_product' ) && tejcart_is_single_product();
            if ( $is_single_product ) {
                wp_enqueue_script(
                    'tejcart-product-tabs',
                    tejcart_asset_url( 'assets/js/tejcart-product-tabs.js' ),
                    array(),
                    $version,
                    true
                );

                if ( 'yes' === (string) get_option( 'tejcart_enable_reviews', 'yes' ) ) {
                    wp_enqueue_script(
                        'tejcart-product-reviews',
                        tejcart_asset_url( 'assets/js/tejcart-product-reviews.js' ),
                        array(),
                        $version,
                        true
                    );
                    wp_localize_script( 'tejcart-product-reviews', 'tejcart_reviews_params', array(
                        'ajax_url'   => admin_url( 'admin-ajax.php' ),
                        'vote_nonce' => wp_create_nonce( 'tejcart_review_vote' ),
                    ) );
                }
            }
        }

        if ( $this->is_cart_page() ) {
            wp_enqueue_style(
                'tejcart-cart-page',
                tejcart_asset_url( 'assets/css/tejcart-cart-page.css' ),
                array( 'tejcart-public', 'tejcart-shop' ),
                $version
            );
        }

        if ( $this->is_checkout_page() ) {
            wp_enqueue_style(
                'tejcart-checkout',
                tejcart_asset_url( 'assets/css/tejcart-checkout.css' ),
                array( 'tejcart-public' ),
                tejcart_asset_version( 'assets/css/tejcart-checkout.css' )
            );
        }

        if ( $this->is_account_page() ) {
            wp_enqueue_style(
                'tejcart-account',
                tejcart_asset_url( 'assets/css/tejcart-account.css' ),
                array( 'tejcart-public' ),
                $version
            );

            /** This filter is documented in templates/account/account.php */
            $brand_color = (string) apply_filters( 'tejcart_account_brand_color', '' );
            if ( '' !== $brand_color ) {
                wp_add_inline_style(
                    'tejcart-account',
                    '.tejcart-account{--tejcart-brand:' . esc_attr( $brand_color ) . ';}'
                );
            }

            wp_enqueue_script(
                'tejcart-account-nav',
                tejcart_asset_url( 'assets/js/tejcart-account-nav.js' ),
                array(),
                $version,
                true
            );

            wp_enqueue_script(
                'tejcart-account-addresses',
                tejcart_asset_url( 'assets/js/tejcart-account-addresses.js' ),
                array(),
                $version,
                true
            );

            wp_localize_script(
                'tejcart-account-addresses',
                'tejcart_account_address_params',
                array(
                    'states' => $this->get_states_for_script(),
                    'i18n'   => array(
                        'select_state'         => __( 'Select a state / province', 'tejcart' ),
                        'select_country'       => __( 'Select a country / region', 'tejcart' ),
                        /* translators: %s: country name (e.g. "United States"). */
                        'select_country_state' => __( 'Select %s state / province', 'tejcart' ),
                    ),
                )
            );
        }

        wp_enqueue_script(
            'tejcart-cart',
            tejcart_asset_url( 'assets/js/tejcart-cart.js' ),
            array(),
            $version,
            true
        );

        if ( $load_paypal_sdk ) {
            wp_enqueue_script(
                'tejcart-paypal',
                tejcart_asset_url( 'assets/js/tejcart-paypal.js' ),
                array( 'tejcart-cart' ),
                $version,
                true
            );

            if ( $paypal_gateway instanceof \TejCart\Gateways\PayPal\PayPal_Gateway
                 && $paypal_gateway->is_available()
            ) {
                wp_localize_script(
                    'tejcart-paypal',
                    'tejcart_paypal_params',
                    $paypal_gateway->get_script_params()
                );
            }

            if ( \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' ) ) {
                wp_enqueue_script(
                    'google-pay-sdk',
                    'https://pay.google.com/gp/p/js/pay.js',
                    array(),
                    null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                    true
                );
            }

            // Apple Pay renders via the <apple-pay-button> custom element
            // (see renderApplePayButton() in assets/js/tejcart-paypal.js),
            // which is defined by Apple's Apple Pay JS SDK — without this
            // script the element stays undefined and the button never
            // appears in Safari. Mirrors the Google Pay SDK enqueue above
            // and the v6 sample integration's Step 1 markup.
            if ( \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' ) ) {
                wp_enqueue_script(
                    'apple-pay-sdk',
                    'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js',
                    array(),
                    null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                    true
                );
            }
        }

        if ( $this->is_checkout_page() ) {
            wp_enqueue_script(
                'tejcart-checkout',
                tejcart_asset_url( 'assets/js/tejcart-checkout.js' ),
                array( 'tejcart-cart' ),
                tejcart_asset_version( 'assets/js/tejcart-checkout.js' ),
                true
            );

            /**
             * Whether the checkout JS should auto-detect the buyer's
             * country from the browser locale on first paint. Disable
             * to keep the server-rendered store-default country in
             * place even when the buyer's locale would override it
             * (useful for region-locked stores or enterprise
             * deployments with their own geo logic).
             *
             * @param bool $enabled Default true.
             */
            $tejcart_locale_country = (bool) apply_filters( 'tejcart_checkout_default_country_geo', true );

            // N-M5: surface the cookie-consent state to the checkout JS
            // so the F-M13 sessionStorage form persistence can gate
            // itself behind buyer consent. Same pattern as the server-
            // side abandoned-cart marker (F-M15) and the Wishlist /
            // Recently_Viewed cookie writers.
            $tejcart_persist_form = function_exists( 'tejcart_has_cookie_consent' ) ? tejcart_has_cookie_consent() : true;

            wp_localize_script(
                'tejcart-checkout',
                'tejcart_checkout_params',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'tejcart_nonce' ),
                    'states'   => $this->get_states_for_script(),
                    'disableLocaleCountry' => ! $tejcart_locale_country,
                    'persistForm'          => (bool) $tejcart_persist_form,
                    'addressAutocomplete'  => $this->get_address_autocomplete_config(),
                    'i18n'     => array(
                        'select_state'         => __( 'Select a state / province', 'tejcart' ),
                        'select_country'       => __( 'Select a country / region', 'tejcart' ),
                        // F-FE-008: Renamed from 'loading' to 'loading_shipping' to avoid the
                        // duplicate key at line 553 that silently overwrote this value. The JS
                        // shipping-options AJAX handler must reference checkoutI18n('loading_shipping').
                        'loading_shipping'     => __( 'Updating shipping options…', 'tejcart' ),
                        'coupon_missing'       => __( 'Please enter a discount code.', 'tejcart' ),
                        'coupon_applying'      => __( 'Working…', 'tejcart' ),
                        'coupon_error_generic' => __( 'Unable to apply that code.', 'tejcart' ),
                        'coupon_error_network' => __( 'A network error occurred. Please try again.', 'tejcart' ),
                        'coupon_applied_prefix' => __( 'Applied:', 'tejcart' ),
                        'coupon_remove'            => __( 'Remove', 'tejcart' ),
                        'coupon_remove_aria'       => __( 'Remove coupon', 'tejcart' ),
                        // Audit M-52: checkout validation + error strings.
                        'email_invalid'            => __( 'Please enter a valid email address.', 'tejcart' ),
                        'checkbox_required'        => __( 'Please check this box to continue.', 'tejcart' ),
                        'field_required_suffix'    => __( 'is required.', 'tejcart' ),
                        'shipping_method_required' => __( 'Please choose a shipping method.', 'tejcart' ),
                        'nonce_missing'            => __( 'Security token missing. Please reload the page.', 'tejcart' ),
                        'network_error'            => __( 'A network error occurred. Please try again.', 'tejcart' ),
                        'generic_error'            => __( 'An error occurred. Please try again.', 'tejcart' ),
                        'timeout_error'            => __( 'The request timed out. Please try again.', 'tejcart' ),
                        'loading'                  => __( 'Loading', 'tejcart' ),
                    ),
                )
            );
        }

        if ( $this->is_shop_surface() ) {
            wp_enqueue_script(
                'tejcart-gallery',
                tejcart_asset_url( 'assets/js/tejcart-gallery.js' ),
                array( 'tejcart-cart' ),
                $version,
                true
            );
        }

        $cart_params = $this->get_script_params();

        if ( $paypal_lazy_load
             && $paypal_gateway instanceof \TejCart\Gateways\PayPal\PayPal_Gateway
             && $paypal_gateway->is_available()
        ) {
            $cart_params['paypal_lazy'] = array(
                'script_url'     => tejcart_asset_url( 'assets/js/tejcart-paypal.js' ),
                'googlepay_sdk'  => \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' ),
                'params'         => $paypal_gateway->get_script_params(),
            );
        }

        wp_localize_script( 'tejcart-cart', 'tejcart_params', $cart_params );
    }

    /**
     * Build the address-autocomplete configuration handed to the checkout
     * JS, or null when the feature is disabled.
     *
     * Address autocomplete (street lookup that fills city / state / postcode
     * in one tap) is one of the largest remaining conversion levers Shopify
     * has over a stock storefront. Core ships only this provider-neutral
     * extension point and the inert JS driver that consumes it — it stays
     * OFF unless something supplies a config. The bundled
     * `address-autocomplete` optional module (or any third-party code) opts
     * in by hooking the filter below; that keeps the merchant API key,
     * provider choice and third-party SDK out of always-on core, the same
     * way the `tax-providers` module owns its live-calculator credentials.
     *
     * Provider currently understood by the JS driver: 'google' (Google
     * Places / Maps JavaScript API). A config for an unknown provider is
     * harmless — the driver simply ignores it.
     *
     * @return array<string, string>|null
     */
    private function get_address_autocomplete_config(): ?array {
        /**
         * Filter the checkout address-autocomplete configuration.
         *
         * Return an array with at least a non-empty `provider` key to
         * enable autocomplete, or an empty array to keep it disabled.
         * For Google: `array( 'provider' => 'google', 'apiKey' => '...' )`.
         *
         * @param array $config Provider config. Empty (disabled) by default.
         */
        $config = (array) apply_filters( 'tejcart_address_autocomplete_config', array() );

        return empty( $config['provider'] ) ? null : $config;
    }

    /**
     * Determine whether the current page is the TejCart cart page.
     *
     * Mirrors is_checkout_page() — uses the option-stored page ID and
     * falls back to a content scan for the [tejcart_cart] shortcode so
     * the cart-page CSS still loads when the shortcode is dropped on a
     * non-canonical page.
     *
     * @return bool
     */
    private function is_cart_page(): bool {
        $cart_page_id = absint( get_option( 'tejcart_cart_page_id', 0 ) );
        if ( $cart_page_id > 0 && is_page( $cart_page_id ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( (string) $post->post_content, 'tejcart_cart' ) ) {
                return true;
            }
        }

        // The block-built equivalent of the [tejcart_cart] shortcode. The
        // tejcart/cart block renders cart/cart.php, so a page embedding it
        // must ship the same cart-page stylesheet as the canonical cart page
        // (which itself pulls in tejcart-public + tejcart-shop) — otherwise
        // the cart renders with no styling at all.
        if ( $this->content_has_tejcart_block( array( 'tejcart/cart' ) ) ) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the current page renders any product-listing
     * surface — the shop page itself, the products grid shortcode, the
     * single-product shortcode, or a single product post type.
     *
     * @return bool
     */
    private function is_shop_surface(): bool {
        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id > 0 && is_page( $shop_page_id ) ) {
            return true;
        }

        if ( function_exists( 'tejcart_is_single_product' ) && tejcart_is_single_product() ) {
            return true;
        }

        if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'tejcart_product' ) ) {
            return true;
        }

        if ( function_exists( 'is_tax' ) && is_tax( array( 'tejcart_product_cat', 'tejcart_product_tag' ) ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post ) {
                $content = (string) $post->post_content;
                if ( has_shortcode( $content, 'tejcart_products' )
                    || has_shortcode( $content, 'tejcart_product' ) ) {
                    return true;
                }
            }
        }

        // Block-built product surfaces. Every TejCart block that renders a
        // product card reuses templates/product/product-box.php — which
        // depends on tejcart-shop.css for the card / grid layout and on the
        // quick-view assets for the per-card trigger injected via
        // `tejcart_product_card_actions`. Treating a block-built page as a
        // shop surface lights up the identical asset bundle the shop page
        // ships, so the cards render fully aligned with the product / cart /
        // side-cart / checkout design instead of as unstyled markup.
        //
        // The add-to-cart, featured-product and mini-cart blocks are
        // deliberately excluded: each is self-contained by always-on
        // stylesheets (tejcart-public.css for the button, tejcart-side-cart
        // for the drawer) or by its own registered block style
        // (discovery.css), so forcing the heavier shop bundle on a page that
        // only embeds one of them would ship CSS/JS it never uses.
        if ( $this->content_has_tejcart_block( self::product_card_block_names() ) ) {
            return true;
        }

        return false;
    }

    /**
     * Fully-qualified names of every TejCart block that renders
     * templates/product/product-box.php (directly or via the shared product
     * grid) and therefore needs the shop + quick-view stylesheets.
     *
     * @return string[]
     */
    private static function product_card_block_names(): array {
        return array(
            'tejcart/product-box',
            'tejcart/on-sale',
            'tejcart/best-sellers',
            'tejcart/top-rated',
            'tejcart/hand-picked',
            'tejcart/products-by-category',
        );
    }

    /**
     * Whether the current singular post embeds any of the given TejCart
     * blocks.
     *
     * Mirrors the has_shortcode() scans used by is_shop_surface() /
     * is_cart_page() so block-built pages light up the same per-surface
     * asset bundle as their shortcode equivalents. Results are memoised per
     * request because the enqueue path queries surface detection several
     * times and parse_blocks() (inside has_block()) is not free.
     *
     * @param string[] $block_names Fully-qualified block names to look for.
     * @return bool
     */
    private function content_has_tejcart_block( array $block_names ): bool {
        if ( ! is_singular() || ! function_exists( 'has_block' ) ) {
            return false;
        }

        $post = get_post();
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        $content = (string) $post->post_content;

        foreach ( $block_names as $block_name ) {
            $cache_key = $post->ID . ':' . $block_name;
            if ( ! array_key_exists( $cache_key, $this->block_presence_cache ) ) {
                $this->block_presence_cache[ $cache_key ] = has_block( $block_name, $content );
            }
            if ( $this->block_presence_cache[ $cache_key ] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append the "tejcart" CSS class to <body>.
     *
     * @param string[] $classes Existing body classes.
     * @return string[]
     */
    public function body_class( array $classes ): array {
        $classes[] = 'tejcart';

        if ( $this->is_checkout_page() ) {
            $classes[] = 'tejcart-checkout-page';

            $show_menu = ( 'yes' === get_option( 'tejcart_show_checkout_menu', 'yes' ) );

            /**
             * Whether to strip the active theme's primary nav and footer
             * on the checkout page. Defaults to following the
             * "Show Menu on Checkout" admin setting (under
             * TejCart → Settings → Checkout): when the menu is shown
             * (the default) chrome is left in place; when it is off the
             * minimal-chrome class is applied. CSS in
             * tejcart-checkout.css targets the body class set below to
             * hide common landmarks shipped by mainstream themes.
             *
             * @param bool $enabled Whether to hide site chrome on checkout.
             */
            if ( apply_filters( 'tejcart_minimal_checkout_chrome', ! $show_menu ) ) {
                $classes[] = 'tejcart-minimal-chrome';
            }
        }

        if ( $this->is_cart_page() ) {
            $classes[] = 'tejcart-cart-page';
        }

        if ( $this->is_shop_archive_page() ) {
            $classes[] = 'tejcart-shop-page';
        }

        return $classes;
    }

    /**
     * Whether the current request is a shop-archive surface (the shop
     * page itself, the products post-type archive, a product taxonomy
     * archive, or a singular page rendering the [tejcart_products]
     * shortcode). Single-product pages are intentionally excluded so
     * mobile title styling on the listing does not affect the PDP.
     */
    private function is_shop_archive_page(): bool {
        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id > 0 && is_page( $shop_page_id ) ) {
            return true;
        }

        if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'tejcart_product' ) ) {
            return true;
        }

        if ( function_exists( 'is_tax' ) && is_tax( array( 'tejcart_product_cat', 'tejcart_product_tag' ) ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( (string) $post->post_content, 'tejcart_products' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Output the cart drawer HTML in the footer so it is available on
     * every page, even when the cart is empty on the initial render.
     *
     * Previously this was gated behind a cookie check to avoid
     * instantiating the cart on every request, but that meant the
     * first add-to-cart click on a fresh visitor opened an empty
     * drawer that had been pre-rendered from nothing — or worse,
     * opened nothing at all because the drawer container wasn't in
     * the DOM yet. The drawer is a single render of whatever the
     * current session holds; the cart instance is already a
     * singleton cached in the container, so the extra cost is a
     * single template load per request.
     */
    public function render_cart_drawer(): void {
        // Audit #38 / 09 F-007 — the aria-live and toast regions
        // previously lived inside cart-drawer.php, so disabling the
        // drawer made every cart announcement (quantity change,
        // remove, undo, add-to-cart, wishlist move) silent for
        // assistive tech (WCAG 4.1.3 Status Messages failure).
        // Emit the regions unconditionally; only the drawer template
        // itself is gated.
        $this->render_a11y_regions();

        if ( 'yes' !== get_option( 'tejcart_enable_cart_drawer', 'yes' ) ) {
            return;
        }
        $cart = tejcart_get_cart();
        tejcart_get_template( 'cart/cart-drawer.php', array( 'cart' => $cart ) );
    }

    /**
     * Emit the global aria-live + toast regions the cart JS announces
     * into. Always rendered, even when the cart drawer is disabled.
     */
    private function render_a11y_regions(): void {
        echo '<div class="tejcart-live-region" aria-live="polite" aria-atomic="true" data-tejcart-live-region></div>';
        echo '<div class="tejcart-toast-region" role="region" aria-label="' . esc_attr__( 'Notifications', 'tejcart' ) . '" data-tejcart-toast-region></div>';
    }

    /**
     * Build the parameters array passed to JavaScript via wp_localize_script.
     *
     * @return array<string, mixed>
     */
    private function get_script_params(): array {
        return array(
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'nonce'              => wp_create_nonce( 'tejcart_nonce' ),
            'cart_url'           => tejcart_get_page_url( 'cart' ),
            'checkout_url'       => tejcart_get_page_url( 'checkout' ),

            'redirect_after_add' => 'yes' === tejcart_get_setting( 'redirect_after_add', 'no' ),

            'enable_cart_drawer' => 'yes' === tejcart_get_setting( 'enable_cart_drawer', 'yes' ),
            'shop_url'           => esc_url( apply_filters( 'tejcart_continue_shopping_url', home_url( '/shop/' ) ) ),
            'i18n_empty_cart'       => __( 'Your cart is empty', 'tejcart' ),
            'i18n_empty_cart_desc'  => __( 'Looks like you haven\'t added anything yet. Browse the shop to find something you\'ll love.', 'tejcart' ),
            'i18n_start_shopping'   => __( 'Start shopping', 'tejcart' ),
            // Audit #39 / 09 F-008 — translated strings for the cart
            // JS announcer / toaster. The keys are intentionally
            // short so the JS reads them with a `params.i18n_cart.<k>`
            // lookup. Fallback English copies remain inline in the JS
            // so a script loaded without tejcart_params (e.g. block
            // theme JSON island) still announces something.
            'i18n_cart'             => array(
                /* translators: %s: product name. */
                'item_restored'        => __( '%s restored.', 'tejcart' ),
                'undo_failed'          => __( 'Could not undo. Please try again.', 'tejcart' ),
                /* translators: %s: product name. */
                'item_removed'         => __( '%s removed from cart.', 'tejcart' ),
                /* translators: %s: product name. */
                'item_removed_short'   => __( '%s removed', 'tejcart' ),
                'cart_updated'         => __( 'Cart updated.', 'tejcart' ),
                'update_failed'        => __( 'Could not update quantity.', 'tejcart' ),
                'moved_to_wishlist'    => __( 'Moved to wishlist.', 'tejcart' ),
                'choose_options_first'    => __( 'Please choose product options before adding to cart.', 'tejcart' ),
                'variation_unavailable'  => __( 'Sorry, this combination is not available.', 'tejcart' ),
                'variation_out_of_stock' => __( 'Sorry, this variation is out of stock.', 'tejcart' ),
                'item_fallback_name'     => __( 'Item', 'tejcart' ),
            ),
            'currency'           => array(
                'code'               => get_option( 'tejcart_currency', 'USD' ),
                'symbol'             => tejcart_get_currency_symbol(),
                'decimal_separator'  => get_option( 'tejcart_decimal_separator', '.' ),
                'thousand_separator' => get_option( 'tejcart_thousand_separator', ',' ),
                'decimals'           => absint( get_option( 'tejcart_num_decimals', 2 ) ),
                'position'           => get_option( 'tejcart_currency_position', 'left' ),
            ),
        );
    }

    /**
     * Build a country-code => states map for every country the store
     * knows about, for use by the checkout's dynamic state widget.
     *
     * Countries without predefined subdivisions are included with an
     * empty array so the front-end can cheaply tell "no states" apart
     * from "not yet fetched".
     *
     * @return array<string, array<string, string>>
     */
    private function get_states_for_script(): array {
        if ( ! class_exists( \TejCart\Tax\Tax_Manager::class ) ) {
            return array();
        }

        $countries = \TejCart\Tax\Tax_Manager::get_countries();
        $map       = array();

        foreach ( array_keys( $countries ) as $code ) {
            $map[ $code ] = \TejCart\Tax\Tax_Manager::get_states( $code );
        }

        return $map;
    }

    /**
     * Determine whether the current page is the TejCart checkout page.
     *
     * Mirrors is_cart_page() — uses the option-stored page ID and falls
     * back to a content scan for the [tejcart_checkout] shortcode so the
     * checkout CSS/JS still load when the shortcode is dropped on a
     * non-canonical page.
     *
     * @return bool
     */
    private function is_checkout_page(): bool {
        $checkout_page_id = absint( get_option( 'tejcart_checkout_page_id', 0 ) );
        if ( $checkout_page_id > 0 && is_page( $checkout_page_id ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( (string) $post->post_content, 'tejcart_checkout' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the current page is the TejCart My Account page.
     *
     * Mirrors is_cart_page() / is_checkout_page() — uses the option-stored
     * page ID and falls back to a content scan for the [tejcart_account]
     * shortcode so the account stylesheet still loads when the shortcode
     * is dropped on a non-canonical page.
     *
     * @return bool
     */
    private function is_account_page(): bool {
        $account_page_id = absint( get_option( 'tejcart_account_page_id', 0 ) );
        if ( $account_page_id > 0 && is_page( $account_page_id ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( (string) $post->post_content, 'tejcart_account' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the PayPal SDK should be enqueued for the current request.
     *
     * @since 1.0.0
     *
     * @param object|null $paypal_gateway Resolved PayPal gateway instance or null.
     * @return bool
     */
    private function should_load_paypal_sdk( $paypal_gateway ): bool {
        if ( ! $paypal_gateway instanceof \TejCart\Gateways\PayPal\PayPal_Gateway ) {
            $decision = false;
        } else {
            $decision = $this->compute_should_load_paypal_sdk( $paypal_gateway );
        }

        /**
         * Filter whether the PayPal SDK should be enqueued on this request.
         *
         * Integrations that render their own PayPal button on additional
         * surfaces can opt-in here without duplicating the surface checks.
         *
         * @since 1.0.0
         *
         * @param bool   $decision        Current decision.
         * @param object $paypal_gateway  The resolved PayPal gateway (or null).
         */
        return (bool) apply_filters( 'tejcart_should_load_paypal_sdk', $decision, $paypal_gateway );
    }

    /**
     * Core surface-matching logic; isolated so the public entry point can
     * apply the filter without cluttering the branching.
     */
    private function compute_should_load_paypal_sdk( \TejCart\Gateways\PayPal\PayPal_Gateway $gateway ): bool {
        $options = get_option( 'tejcart_gateway_tejcart_paypal', array() );

        if ( $this->is_checkout_page() && $gateway->is_available() ) {
            return true;
        }

        if ( $this->is_cart_page()
             && 'yes' === (string) ( $options['button_cart_page'] ?? 'yes' )
        ) {
            return true;
        }

        if ( function_exists( 'tejcart_is_single_product' ) && tejcart_is_single_product()
             && 'yes' === (string) ( $options['button_product_page'] ?? 'yes' )
        ) {
            return true;
        }

        $account_page_id = absint( get_option( 'tejcart_account_page_id', 0 ) );
        if ( $account_page_id > 0 && is_page( $account_page_id )
             && 'yes' === (string) ( $options['save_payment_methods'] ?? 'yes' )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Whether the PayPal SDK should be lazy-loaded for the side cart
     * rather than eagerly enqueued.
     *
     * Returns true when the side-cart express button is enabled but the
     * current page doesn't need the SDK directly (i.e. shop archives,
     * category pages). The cart JS will inject the PayPal script on
     * demand when the drawer opens.
     */
    private function needs_paypal_lazy_load( ?object $paypal_gateway ): bool {
        if ( ! $paypal_gateway instanceof \TejCart\Gateways\PayPal\PayPal_Gateway ) {
            return false;
        }

        if ( is_admin() ) {
            return false;
        }

        $options = get_option( 'tejcart_gateway_tejcart_paypal', array() );

        return 'yes' === (string) ( $options['button_side_cart'] ?? 'yes' );
    }
}
