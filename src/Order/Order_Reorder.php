<?php
/**
 * Order "Reorder" (Order again) handler.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the My Account → Orders → "Order again" action, which repopulates
 * the current cart with items from a past order, skipping items that are no
 * longer available and surfacing per-line notices.
 */
class Order_Reorder {
    /**
     * Query-var key carrying the order ID on the GET request.
     */
    public const ACTION_NAME = 'tejcart_reorder';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
    }

    /**
     * Build a nonce-protected URL to reorder a given order.
     *
     * @param int $order_id Order ID.
     * @return string
     */
    public static function get_url( int $order_id ): string {
        $nonce = wp_create_nonce( 'tejcart_reorder_' . $order_id );

        $account_page_id = (int) get_option( 'tejcart_account_page_id', 0 );
        $base_url        = $account_page_id ? get_permalink( $account_page_id ) : home_url( '/' );

        return add_query_arg(
            array(
                self::ACTION_NAME => $order_id,
                '_wpnonce'        => $nonce,
            ),
            $base_url
        );
    }

    /**
     * Handle the reorder request when it lands on any page of the site.
     *
     * @return void
     */
    public function maybe_handle(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET[ self::ACTION_NAME ] ) ) {
            return;
        }

        $order_id = absint( $_GET[ self::ACTION_NAME ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $order_id ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_reorder_' . $order_id ) ) {
            wp_safe_redirect( remove_query_arg( array( self::ACTION_NAME, '_wpnonce' ) ) );
            exit;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $order = new Order( $order_id );
        if ( ! $order->get_id() || (int) $order->get_customer_id() !== get_current_user_id() ) {
            return;
        }

        $cart     = tejcart_get_cart();
        $items    = $order->get_items();
        $skipped  = array();
        $re_added = 0;

        // #1204: prime the request-memo / object cache in a single
        // WHERE id IN (...) round-trip so the per-item Product_Factory
        // ::get_product() calls below all hit the memo instead of
        // each issuing their own SELECT.
        $product_ids = array();
        foreach ( $items as $item ) {
            $pid = (int) ( $item->product_id ?? 0 );
            if ( $pid > 0 ) {
                $product_ids[ $pid ] = $pid;
            }
        }
        if ( ! empty( $product_ids ) ) {
            \TejCart\Product\Product_Factory::get_products( array_values( $product_ids ) );
        }

        foreach ( $items as $item ) {
            $product_id = (int) ( $item->product_id ?? 0 );
            $quantity   = max( 1, (int) ( $item->quantity ?? 1 ) );
            $name       = (string) ( $item->product_name ?? ( $item->name ?? '' ) );

            $meta = array();
            if ( ! empty( $item->meta ) && is_string( $item->meta ) ) {
                $decoded = json_decode( $item->meta, true );
                if ( is_array( $decoded ) ) {
                    $meta = $decoded;
                }
            }
            $variation_id     = isset( $meta['variation_id'] ) ? (int) $meta['variation_id'] : 0;
            $variation_attrs  = isset( $meta['variation_attributes'] ) && is_array( $meta['variation_attributes'] )
                ? $meta['variation_attributes']
                : array();

            if ( ! $product_id ) {
                continue;
            }

            $product = \TejCart\Product\Product_Factory::get_product( $product_id );
            if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
                $skipped[] = $name;
                continue;
            }

            // F-M4 / #938: pre-check stock so the customer sees the
            // skipped notice on the cart page right after reorder
            // rather than hitting a stock error at checkout. The cart
            // page is the right place to surface it because the
            // customer can edit / remove the line before proceeding.
            if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
                $available = method_exists( $product, 'get_stock_quantity' )
                    ? (int) $product->get_stock_quantity()
                    : 0;
                $backorders_allowed = method_exists( $product, 'backorders_allowed' )
                    ? (bool) $product->backorders_allowed()
                    : false;
                if ( ! $backorders_allowed && $available < (int) $quantity ) {
                    $skipped[] = $name;
                    continue;
                }
            }

            $result = $cart->add( $product_id, $quantity, $variation_attrs, $variation_id );
            if ( is_wp_error( $result ) ) {
                $skipped[] = $name;
                continue;
            }

            $re_added++;
        }

        if ( $re_added > 0 ) {
            $cart->add_notice( sprintf(
                /* translators: %1$d: re-added items, %2$d: original order number */
                _n(
                    '%1$d item from order #%2$d was added back to your cart.',
                    '%1$d items from order #%2$d were added back to your cart.',
                    $re_added,
                    'tejcart'
                ),
                $re_added,
                $order->get_order_number()
            ), 'success' );
        }

        if ( ! empty( $skipped ) ) {
            $cart->add_notice( sprintf(
                /* translators: %s: comma-separated product names */
                __( 'These items from the original order could not be re-added: %s', 'tejcart' ),
                implode( ', ', array_map( 'sanitize_text_field', $skipped ) )
            ), 'warning' );
        }

        $cart_page_id = (int) get_option( 'tejcart_cart_page_id', 0 );
        $target       = $cart_page_id ? get_permalink( $cart_page_id ) : home_url( '/' );

        wp_safe_redirect( $target );
        exit;
    }
}
