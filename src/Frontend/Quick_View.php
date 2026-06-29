<?php
/**
 * AJAX-rendered quick-view modal for product cards.
 *
 * Audit 01 #7 / #1480 — quick view was previously only a stub comment
 * in `templates/product/product-box.php`. This class wires the missing
 * pieces:
 *
 *   - A `wp_ajax(_nopriv)?_tejcart_quick_view` endpoint that returns the
 *     rendered quick-view template for a single product id.
 *   - A `tejcart_product_card_actions` listener that injects the trigger
 *     button into every product card.
 *   - The shared `tejcart-quick-view` script that opens the modal,
 *     fetches the HTML, and hands off "Add to cart" to the existing
 *     `tejcart-cart` script.
 *
 * The template (`product/quick-view.php`) is themable via the normal
 * `tejcart_get_template()` override chain so themes can re-style the
 * modal without forking this class.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Types\Abstract_Product;

/**
 * Quick view bootstrap and AJAX endpoint.
 */
class Quick_View {

    /**
     * Maximum products that can be quick-viewed per minute per IP, to
     * keep an unauthenticated AJAX HTML render from becoming a cheap
     * cache-buster against the catalogue.
     */
    private const RATE_LIMIT_PER_MIN = 60;

    /**
     * Wire AJAX endpoints + the trigger-button injection. Idempotent.
     */
    public function init(): void {
        add_action( 'wp_ajax_tejcart_quick_view',        array( $this, 'ajax_render' ) );
        add_action( 'wp_ajax_nopriv_tejcart_quick_view', array( $this, 'ajax_render' ) );
        add_action( 'tejcart_product_card_actions',      array( $this, 'render_trigger' ), 20 );
    }

    /**
     * Render the trigger button inside a product card.
     *
     * Fires via the documented `tejcart_product_card_actions` action;
     * priority 20 keeps wishlist (priority 10, by convention) to its
     * left in source order.
     *
     * @param Abstract_Product|null $product
     */
    public function render_trigger( $product = null ): void {
        if ( ! $product instanceof Abstract_Product ) {
            return;
        }

        // Themes can opt-out per-product (e.g. hide on external products).
        if ( ! (bool) apply_filters( 'tejcart_quick_view_enabled', true, $product ) ) {
            return;
        }

        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            return;
        }

        $label = (string) apply_filters( 'tejcart_quick_view_button_label', __( 'Quick view', 'tejcart' ), $product );

        printf(
            '<button type="button" class="tejcart-quick-view-trigger" data-product-id="%1$d" data-testid="tejcart-quick-view-trigger" aria-label="%2$s"><span class="tejcart-quick-view-trigger__icon" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></button>',
            $product_id,
            esc_attr( sprintf(
                /* translators: %s: product title */
                __( 'Quick view: %s', 'tejcart' ),
                $product->get_name()
            ) ),
            esc_html( $label )
        );
    }

    /**
     * AJAX handler — return the rendered quick-view HTML for one product.
     *
     * Public endpoint (guests must be able to quick-view too), but
     * rate-limited per-IP and gated by a public nonce so a single random
     * fetcher can't burn the cache.
     */
    public function ajax_render(): void {
        check_ajax_referer( 'tejcart_quick_view', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? (int) wp_unslash( $_POST['product_id'] ) : 0;
        if ( $product_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing product id.', 'tejcart' ) ), 400 );
        }

        // Per-IP rate limit. The proxy-aware client_ip helper lives in
        // Security/Rate_Limiter; falls back to REMOTE_ADDR on hosts that
        // don't expose XFF.
        if ( class_exists( \TejCart\Security\Rate_Limiter::class ) ) {
            $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
            if ( \TejCart\Security\Rate_Limiter::check_and_record(
                'quick_view',
                (string) $ip,
                self::RATE_LIMIT_PER_MIN,
                MINUTE_IN_SECONDS
            ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'Too many quick-view requests, please slow down.', 'tejcart' ) ),
                    429
                );
            }
        }

        $product = tejcart_get_product( $product_id );
        if ( ! $product instanceof Abstract_Product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'tejcart' ) ), 404 );
        }

        // Skip hidden / unpublished products.
        if ( method_exists( $product, 'get_status' ) && 'publish' !== (string) $product->get_status() ) {
            wp_send_json_error( array( 'message' => __( 'Product not available.', 'tejcart' ) ), 404 );
        }

        ob_start();
        tejcart_get_template(
            'product/quick-view.php',
            array(
                'product' => $product,
            )
        );
        $html = (string) ob_get_clean();

        wp_send_json_success( array(
            'product_id' => $product_id,
            'title'      => $product->get_name(),
            'html'       => $html,
        ) );
    }

    /**
     * Localised payload for the JS client. Called by Frontend::enqueue_assets
     * when the quick-view trigger is going to be present on the page.
     *
     * @return array<string,mixed>
     */
    public static function js_payload(): array {
        return array(
            'ajaxUrl' => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
            'nonce'   => wp_create_nonce( 'tejcart_quick_view' ),
            'i18n'    => array(
                'loading'  => __( 'Loading…',          'tejcart' ),
                'error'    => __( 'Could not load this product.', 'tejcart' ),
                'close'    => __( 'Close',             'tejcart' ),
                'dialog'   => __( 'Quick product view', 'tejcart' ),
            ),
        );
    }
}
