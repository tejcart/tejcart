<?php
/**
 * AJAX mini-cart updates.
 *
 * Provides AJAX endpoints used by a mini-cart fragment so the cart drawer
 * can update without a full page reload. Also returns updated cart
 * fragments (count, subtotal, drawer HTML) on every mutation so the
 * frontend can refresh in a single round trip.
 *
 * Integration:
 *  - Hooks `wp_ajax_*` and `wp_ajax_nopriv_*` actions only; the existing
 *    Cart class is used as-is via tejcart_get_cart().
 *  - Re-uses the existing cart-drawer template via output buffering.
 *  - Uses the existing `tejcart_nonce` (registered in Frontend) for CSRF.
 *  - The frontend script is enqueued via `wp_enqueue_scripts` so it
 *    coexists with the existing tejcart-cart bundle.
 *
 * @package TejCart\Tier2\Cart_Ajax
 */

declare( strict_types=1 );

namespace TejCart\Tier2\Cart_Ajax;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mini_Cart {
    public static function init() {
        $actions = array(
            'tejcart_mini_cart_get'    => 'ajax_get',
            'tejcart_mini_cart_add'    => 'ajax_add',
            'tejcart_mini_cart_update' => 'ajax_update',
            'tejcart_mini_cart_remove' => 'ajax_remove',
        );
        foreach ( $actions as $hook => $method ) {
            add_action( "wp_ajax_{$hook}",        array( __CLASS__, $method ) );
            add_action( "wp_ajax_nopriv_{$hook}", array( __CLASS__, $method ) );
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
    }

    /**
     * Enqueue the mini-cart JS bundle. The script binds handlers for
     * [data-tejcart-add|update|remove] attributes; no first-party
     * template emits those today, so by default we don't ship the
     * bundle. Themes / extensions that opt into the data-attribute API
     * can re-enable it via the `tejcart_enqueue_mini_cart_js` filter.
     */
    public static function enqueue() {
        if ( ! apply_filters( 'tejcart_enqueue_mini_cart_js', false ) ) {
            return;
        }
        $url = tejcart_asset_url( 'assets/js/tejcart-tier2.js' );
        $ver = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        if ( wp_script_is( 'tejcart-cart', 'registered' ) ) {
            wp_enqueue_script( 'tejcart-tier2', $url, array( 'tejcart-cart' ), $ver, true );
        } else {
            wp_enqueue_script( 'tejcart-tier2', $url, array(), $ver, true );
        }
    }

    /**
     * CSRF guard. Cart::add() already enforces per-session rate limits,
     * and Cart_Session is the canonical serialization point for
     * concurrent mutations on the same cart, so this layer doesn't add
     * its own lock — the previous transient-based lock had a TOCTOU
     * window between get_transient() and set_transient() that two
     * parallel requests could slip through, providing no real
     * protection while still 423-ing legitimate retries.
     *
     * Defence-in-depth: pair the nonce with the same Origin/Referer
     * check that Cart_Ajax / Checkout_AJAX use. A leaked nonce alone
     * can't be cross-origin replayed to mutate the victim's cart
     * (audit H — REST API security).
     */
    private static function check_request() {
        if ( ! \TejCart\Cart\Cart_Ajax::verify_origin() ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }
        check_ajax_referer( 'tejcart_nonce', 'nonce' );
    }

    /**
     * Build the response payload (cart fragments).
     */
    private static function build_response() {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! $cart ) {
            return array( 'item_count' => 0, 'subtotal' => 0, 'fragments' => array() );
        }

        $drawer_html = '';
        if ( function_exists( 'tejcart_get_template' ) ) {
            ob_start();
            tejcart_get_template( 'cart/cart-drawer.php', array( 'cart' => $cart ) );
            $drawer_html = ob_get_clean();
        }

        $item_count = (int) $cart->get_item_count();
        $total      = (float) $cart->get_total();
        $count_attr = 0 === $item_count ? ' class="is-empty"' : '';
        $total_attr = 0 === $item_count ? ' class="is-empty"' : '';
        $price_html = function_exists( 'tejcart_price' ) ? tejcart_price( $total ) : (string) $total;

        $count_fragment =
            '<span class="tejcart-mini-cart-count" aria-live="polite" aria-atomic="true"' . $count_attr . '>'
            . esc_html( $item_count )
            . '</span>';
        $total_fragment =
            '<span class="tejcart-mini-cart-total"' . $total_attr . '>'
            . ( $item_count > 0 ? wp_kses_post( $price_html ) : '' )
            . '</span>';

        return array(
            'item_count' => $item_count,
            'subtotal'   => $cart->get_subtotal(),
            'total'      => $total,
            'fragments'  => apply_filters( 'tejcart_mini_cart_fragments', array(
                'div.tejcart-cart-drawer'   => $drawer_html,
                '.tejcart-mini-cart-count'  => $count_fragment,
                '.tejcart-mini-cart-total'  => $total_fragment,
            ), $cart ),
        );
    }

    public static function ajax_get() {
        self::check_request();
        wp_send_json_success( self::build_response() );
    }

    public static function ajax_add() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        $quantity   = isset( $_POST['quantity'] )   ? (int) $_POST['quantity']   : 1;
        $data       = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? map_deep( wp_unslash( $_POST['data'] ), 'sanitize_text_field' ) : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $cart       = tejcart_get_cart();
        $result     = $cart->add( $product_id, $quantity, $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( 'Could not add to cart.', 'tejcart' ) ), 400 );
        }
        wp_send_json_success( self::build_response() );
    }

    public static function ajax_update() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $key = isset( $_POST['key'] )      ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $qty = isset( $_POST['quantity'] ) ? (int) $_POST['quantity'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! $key ) {
            wp_send_json_error( array( 'message' => __( 'Missing key.', 'tejcart' ) ), 400 );
        }
        tejcart_get_cart()->update_quantity( $key, $qty );
        wp_send_json_success( self::build_response() );
    }

    public static function ajax_remove() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( ! $key ) {
            wp_send_json_error( array( 'message' => __( 'Missing key.', 'tejcart' ) ), 400 );
        }
        tejcart_get_cart()->remove( $key );
        wp_send_json_success( self::build_response() );
    }
}
