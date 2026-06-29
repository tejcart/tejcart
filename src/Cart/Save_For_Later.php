<?php
/**
 * Save-for-later: move cart items to a persistent "saved" list.
 *
 * Logged-in users → user_meta `_tejcart_saved_for_later`.
 * Guests         → cart session data.
 *
 * Each saved entry stores: product_id, quantity, variation_id, data
 * (variation attributes / line-item meta), and saved_at timestamp.
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Save_For_Later {

    private const USER_META_KEY = '_tejcart_saved_for_later';
    private const SESSION_KEY   = '_tejcart_saved_for_later';
    private const MAX_ITEMS     = 30;

    public function init(): void {
        // Respect the "Enable Save for Later" admin toggle (Cart settings).
        // When disabled we register no AJAX endpoints so the feature is fully
        // off, not just hidden in the templates.
        if ( function_exists( 'tejcart_save_for_later_enabled' ) && ! tejcart_save_for_later_enabled() ) {
            return;
        }

        $actions = array(
            'tejcart_save_for_later'          => 'ajax_save',
            'tejcart_restore_from_saved'      => 'ajax_restore',
            'tejcart_remove_saved_item'       => 'ajax_remove',
        );
        foreach ( $actions as $hook => $method ) {
            add_action( "wp_ajax_{$hook}",        array( $this, $method ) );
            add_action( "wp_ajax_nopriv_{$hook}", array( $this, $method ) );
        }
    }

    /**
     * Get all saved items for the current customer.
     *
     * @return array<int,array{product_id:int,quantity:int,variation_id:int,data:array,saved_at:int}>
     */
    public function get_items(): array {
        if ( is_user_logged_in() ) {
            $items = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
            return is_array( $items ) ? array_values( $items ) : array();
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'get_session_data' ) ) {
            $raw = $cart->get_session_data( self::SESSION_KEY );
            return is_array( $raw ) ? array_values( $raw ) : array();
        }

        return array();
    }

    /**
     * Save the items list.
     *
     * @param array $items
     */
    private function save_items( array $items ): void {
        $items = array_slice( array_values( $items ), 0, self::MAX_ITEMS );

        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::USER_META_KEY, $items );
            return;
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'set_session_data' ) ) {
            $cart->set_session_data( self::SESSION_KEY, $items );
        }
    }

    /**
     * Move a cart item to the saved list.
     */
    public function ajax_save(): void {
        if ( ! Cart_Ajax::verify_origin() ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
        }
        check_ajax_referer( 'tejcart_nonce', '_wpnonce' );

        $this->enforce_rate_limit( 'save_for_later', 30, 60 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $cart_item_key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
        if ( ! $cart_item_key || ! preg_match( '/^[a-f0-9]{32,128}$/', $cart_item_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid item.', 'tejcart' ) ), 400 );
        }

        $cart = tejcart_get_cart();
        $items = $cart->get_items();

        if ( ! isset( $items[ $cart_item_key ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Item not found in cart.', 'tejcart' ) ), 404 );
        }

        $cart_item = $items[ $cart_item_key ];
        $product   = $cart_item->get_product();

        $saved_entry = array(
            'product_id'   => (int) $cart_item->get_product_id(),
            'quantity'     => (int) $cart_item->get_quantity(),
            'variation_id' => method_exists( $cart_item, 'get_variation_id' ) ? (int) $cart_item->get_variation_id() : 0,
            'data'         => method_exists( $cart_item, 'get_variation_attributes' ) ? (array) $cart_item->get_variation_attributes() : array(),
            'saved_at'     => time(),
            'name'         => $product ? (string) $product->get_name() : '',
            'price'        => $product ? (float) $product->get_price() : 0.0,
            'image_id'     => $product ? (int) $product->get_image_id() : 0,
        );

        $saved = $this->get_items();
        $saved[] = $saved_entry;
        $this->save_items( $saved );

        $cart->remove( $cart_item_key );

        $this->send_success_response( $cart, __( 'Item saved for later.', 'tejcart' ) );
    }

    /**
     * Restore a saved item back to the cart.
     */
    public function ajax_restore(): void {
        if ( ! Cart_Ajax::verify_origin() ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
        }
        check_ajax_referer( 'tejcart_nonce', '_wpnonce' );

        $this->enforce_rate_limit( 'restore_from_saved', 30, 60 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $index = isset( $_POST['saved_index'] ) ? (int) $_POST['saved_index'] : -1;

        $saved = $this->get_items();
        if ( $index < 0 || ! isset( $saved[ $index ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Saved item not found.', 'tejcart' ) ), 404 );
        }

        $entry = $saved[ $index ];
        $cart  = tejcart_get_cart();

        $product_id   = (int) ( $entry['product_id'] ?? 0 );
        $quantity     = max( 1, (int) ( $entry['quantity'] ?? 1 ) );
        $variation_id = (int) ( $entry['variation_id'] ?? 0 );
        $data         = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();

        if ( $variation_id > 0 ) {
            $data['variation_id'] = $variation_id;
        }

        $result = $cart->add( $product_id, $quantity, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        array_splice( $saved, $index, 1 );
        $this->save_items( $saved );

        $this->send_success_response( $cart, __( 'Item restored to cart.', 'tejcart' ) );
    }

    /**
     * Remove a saved item without restoring.
     */
    public function ajax_remove(): void {
        if ( ! Cart_Ajax::verify_origin() ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
        }
        check_ajax_referer( 'tejcart_nonce', '_wpnonce' );

        $this->enforce_rate_limit( 'remove_saved', 30, 60 );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $index = isset( $_POST['saved_index'] ) ? (int) $_POST['saved_index'] : -1;

        $saved = $this->get_items();
        if ( $index < 0 || ! isset( $saved[ $index ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Saved item not found.', 'tejcart' ) ), 404 );
        }

        array_splice( $saved, $index, 1 );
        $this->save_items( $saved );

        $cart = tejcart_get_cart();
        $this->send_success_response( $cart, __( 'Saved item removed.', 'tejcart' ) );
    }

    /**
     * @return int Number of saved items.
     */
    public function count(): int {
        return count( $this->get_items() );
    }

    private function send_success_response( Cart $cart, string $message ): void {
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
            }
        }

        wp_send_json_success( array(
            'message'      => $message,
            'cart_count'   => (int) $cart->get_item_count(),
            'cart_total'   => (float) $cart->get_total(),
            'total_html'   => wp_kses_post( tejcart_price( (float) $cart->get_total() ) ),
            'drawer_html'  => $drawer_html,
            'cart_empty'   => $cart->is_empty(),
            'saved_count'  => $this->count(),
        ) );
    }

    private function enforce_rate_limit( string $bucket, int $max, int $window ): void {
        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return;
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
        if ( \TejCart\Security\Rate_Limiter::check_and_record( $bucket, $ip, $max, $window ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Too many requests. Please slow down.', 'tejcart' ) ),
                429
            );
        }
    }
}
