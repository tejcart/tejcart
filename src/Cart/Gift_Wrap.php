<?php
/**
 * Gift wrap option for cart items.
 *
 * Adds an opt-in gift wrap checkbox and optional message to the cart
 * drawer and cart page. When enabled by the merchant, a flat fee is
 * added to the cart total as a line-level surcharge.
 *
 * Admin settings:
 *  - tejcart_enable_gift_wrap  (yes/no)
 *  - tejcart_gift_wrap_fee     (float, store currency minor units)
 *  - tejcart_gift_wrap_label   (string shown to customers)
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Gift_Wrap {

    private const SESSION_KEY  = '_tejcart_gift_wrap';
    private const MESSAGE_KEY  = '_tejcart_gift_message';
    private const MAX_MSG_LEN  = 500;

    public function init(): void {
        add_action( 'wp_ajax_tejcart_toggle_gift_wrap',        array( $this, 'ajax_toggle' ) );
        add_action( 'wp_ajax_nopriv_tejcart_toggle_gift_wrap', array( $this, 'ajax_toggle' ) );

        add_filter( 'tejcart_cart_fees', array( $this, 'add_gift_wrap_fee' ), 10, 2 );

        add_action( 'tejcart_order_created', array( $this, 'save_to_order' ), 10, 2 );
    }

    public static function is_enabled(): bool {
        return 'yes' === get_option( 'tejcart_enable_gift_wrap', 'no' );
    }

    public static function get_fee(): float {
        return (float) get_option( 'tejcart_gift_wrap_fee', 5.00 );
    }

    public static function get_label(): string {
        $label = (string) get_option( 'tejcart_gift_wrap_label', '' );
        return '' !== $label ? $label : __( 'Gift wrap', 'tejcart' );
    }

    public static function is_active_in_cart(): bool {
        if ( ! self::is_enabled() ) {
            return false;
        }

        if ( is_user_logged_in() ) {
            return (bool) get_user_meta( get_current_user_id(), self::SESSION_KEY, true );
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'get_session_data' ) ) {
            return (bool) $cart->get_session_data( self::SESSION_KEY );
        }

        return false;
    }

    public static function get_message(): string {
        if ( is_user_logged_in() ) {
            return (string) get_user_meta( get_current_user_id(), self::MESSAGE_KEY, true );
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'get_session_data' ) ) {
            return (string) $cart->get_session_data( self::MESSAGE_KEY );
        }

        return '';
    }

    public function ajax_toggle(): void {
        if ( ! \TejCart\Cart\Cart_Ajax::verify_origin() ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
        }
        check_ajax_referer( 'tejcart_nonce', '_wpnonce' );

        if ( ! self::is_enabled() ) {
            wp_send_json_error( array( 'message' => __( 'Gift wrap is not available.', 'tejcart' ) ), 400 );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $enabled = isset( $_POST['enabled'] ) && in_array( $_POST['enabled'], array( '1', 'true', 'yes' ), true );
        $message = isset( $_POST['gift_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gift_message'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( mb_strlen( $message ) > self::MAX_MSG_LEN ) {
            $message = mb_substr( $message, 0, self::MAX_MSG_LEN );
        }

        $this->set_active( $enabled );
        $this->set_message( $message );

        $cart = tejcart_get_cart();
        $cart->recalculate();

        $ajax = new Cart_Ajax();
        $state = $this->call_cart_state( $ajax, $cart );

        wp_send_json_success( array_merge( $state, array(
            'gift_wrap_active' => $enabled,
            // Report the fee in the active currency so the client badge matches
            // what is charged. Passthrough on a single-currency store.
            'gift_wrap_fee'    => (float) apply_filters( 'tejcart_amount_to_currency', self::get_fee(), tejcart_get_currency() ),
        ) ) );
    }

    /**
     * Add gift wrap fee to cart totals via the tejcart_cart_fees filter.
     *
     * @param array $fees Existing fees.
     * @param Cart  $cart Cart instance.
     * @return array
     */
    public function add_gift_wrap_fee( array $fees, $cart ): array {
        if ( ! self::is_enabled() || ! self::is_active_in_cart() ) {
            return $fees;
        }

        $fee = self::get_fee();
        if ( $fee <= 0 ) {
            return $fees;
        }

        $fees[] = array(
            'id'     => 'gift_wrap',
            'label'  => self::get_label(),
            'amount' => $fee,
            'taxable' => (bool) apply_filters( 'tejcart_gift_wrap_taxable', false ),
        );

        return $fees;
    }

    /**
     * Save gift wrap meta onto the order at creation.
     *
     * @param object $order  The order object.
     * @param Cart   $cart   The cart instance.
     */
    public function save_to_order( $order, $cart ): void {
        if ( ! self::is_active_in_cart() ) {
            return;
        }

        if ( method_exists( $order, 'update_meta' ) ) {
            $order->update_meta( '_gift_wrap', '1' );
            $message = self::get_message();
            if ( '' !== $message ) {
                $order->update_meta( '_gift_message', $message );
            }
        }

        $this->set_active( false );
        $this->set_message( '' );
    }

    private function set_active( bool $active ): void {
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::SESSION_KEY, $active ? '1' : '' );
            return;
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'set_session_data' ) ) {
            $cart->set_session_data( self::SESSION_KEY, $active ? '1' : '' );
        }
    }

    private function set_message( string $message ): void {
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::MESSAGE_KEY, $message );
            return;
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'set_session_data' ) ) {
            $cart->set_session_data( self::MESSAGE_KEY, $message );
        }
    }

    /**
     * Access the private cart_state() method via a public-facing wrapper.
     * Cart_Ajax::cart_state() is private — we invoke it through the AJAX
     * response indirectly by re-rendering the drawer fragment.
     */
    private function call_cart_state( Cart_Ajax $ajax, Cart $cart ): array {
        $subtotal       = (float) $cart->get_subtotal();
        $total          = (float) $cart->get_total();
        $discount_total = (float) $cart->get_discount_total();

        $drawer_html = '';
        if ( function_exists( 'tejcart_get_template' ) ) {
            try {
                ob_start();
                tejcart_get_template( 'cart/cart-drawer.php', array( 'cart' => $cart ) );
                $drawer_html = (string) ob_get_clean();
            } catch ( \Throwable $e ) {
                if ( ob_get_level() > 0 ) {
                    ob_end_clean();
                }
                $drawer_html = '';
            }
        }

        return array(
            'cart_count'    => (int) $cart->get_item_count(),
            'cart_total'    => $total,
            'subtotal_html' => wp_kses_post( tejcart_price( $subtotal ) ),
            'total_html'    => wp_kses_post( tejcart_price( $total ) ),
            'drawer_html'   => $drawer_html,
        );
    }
}
