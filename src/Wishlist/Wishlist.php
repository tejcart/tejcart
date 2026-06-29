<?php
/**
 * Customer wishlist storage and helpers.
 *
 * @package TejCart\Wishlist
 */

declare( strict_types=1 );

namespace TejCart\Wishlist;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persistent per-customer wishlist.
 *
 * Logged-in users → stored in user meta.
 * Guests          → stored in a 30-day cookie.
 */
class Wishlist {
    const USER_META_KEY = '_tejcart_wishlist';
    const COOKIE_KEY    = 'tejcart_wishlist';
    const COOKIE_TTL    = 2592000;

    /**
     * Maximum number of product IDs stored in the guest cookie.
     *
     * Bounds the JSON payload well under the 4 KB cookie cap so adding
     * to a long wishlist doesn't silently overflow and drop later items
     * (audit 04 L-4). Filterable via `tejcart_wishlist_max_cookie_items`.
     */
    const COOKIE_MAX_ITEMS = 50;

    /**
     * Wire up shortcodes, AJAX endpoints and the My Account tab link.
     */
    public function init(): void {
        // Respect the "Enable Wishlist" admin toggle (Cart settings). When
        // disabled we register no shortcode, AJAX endpoint or login-merge hook
        // so the feature is fully off, not just hidden on the cart item.
        if ( function_exists( 'tejcart_wishlist_enabled' ) && ! tejcart_wishlist_enabled() ) {
            return;
        }

        add_shortcode( 'tejcart_wishlist', array( $this, 'render_shortcode' ) );

        add_action( 'wp_ajax_tejcart_wishlist_toggle',        array( $this, 'ajax_toggle' ) );
        add_action( 'wp_ajax_nopriv_tejcart_wishlist_toggle', array( $this, 'ajax_toggle' ) );

        // F-PCA-006: Merge the guest cookie wishlist into the user's meta
        // wishlist on login, mirroring the Cart_Session::merge_saved_cart_on_login
        // pattern. Without this a guest's wishlist items are silently lost
        // when they log in or create an account during a shopping session.
        add_action( 'wp_login', array( $this, 'merge_guest_wishlist_on_login' ), 10, 2 );
    }

    /**
     * Merge any product IDs stored in the guest cookie into the newly-logged-in
     * user's persisted wishlist, then clear the cookie.
     *
     * Called on the `wp_login` action. The merge is a union — existing user-meta
     * items are preserved and the guest items are appended, duplicates removed.
     *
     * F-PCA-006: Mirrors the Cart_Session::merge_saved_cart_on_login() pattern
     * (src/Cart/Cart_Session.php:92).
     *
     * @param string   $user_login Unused — WP passes it as the first parameter.
     * @param \WP_User $user       The user object for the account just signed in.
     * @return void
     */
    public function merge_guest_wishlist_on_login( string $user_login, \WP_User $user ): void {
        if ( empty( $_COOKIE[ self::COOKIE_KEY ] ) ) {
            return;
        }

        $raw        = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) );
        $cookie_ids = json_decode( $raw, true );

        if ( ! is_array( $cookie_ids ) || empty( $cookie_ids ) ) {
            // Invalid or empty cookie — clear it and return.
            $this->clear_cookie();
            return;
        }

        $cookie_ids = array_values( array_filter( array_map( 'intval', $cookie_ids ), static fn( int $id ) => $id > 0 ) );
        if ( empty( $cookie_ids ) ) {
            $this->clear_cookie();
            return;
        }

        $user_id    = (int) $user->ID;
        $meta_items = get_user_meta( $user_id, self::USER_META_KEY, true );
        $meta_items = is_array( $meta_items ) ? array_map( 'intval', $meta_items ) : array();

        // Union: user-meta items first (preserve order), then guest additions.
        $merged = array_values( array_unique( array_merge( $meta_items, $cookie_ids ) ) );
        $merged = array_values( array_filter( $merged, static fn( int $id ) => $id > 0 ) );

        update_user_meta( $user_id, self::USER_META_KEY, $merged );

        $this->clear_cookie();
    }

    /**
     * Expire the guest wishlist cookie immediately.
     *
     * @return void
     */
    private function clear_cookie(): void {
        if ( headers_sent() ) {
            return;
        }
        setcookie( self::COOKIE_KEY, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
        unset( $_COOKIE[ self::COOKIE_KEY ] );
    }

    /**
     * Return the current customer's wishlist as an array of product IDs.
     */
    public function get_items(): array {
        if ( is_user_logged_in() ) {
            $items = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
            return is_array( $items ) ? array_map( 'intval', $items ) : array();
        }

        if ( ! empty( $_COOKIE[ self::COOKIE_KEY ] ) ) {
            $raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) );
            $items = json_decode( $raw, true );
            if ( ! is_array( $items ) ) {
                return array();
            }
            // Audit M-21: bound the array to prevent a buyer-crafted
            // cookie from inflating memory. 200 is generous for a
            // real wishlist; anything larger is abuse or corruption.
            $items = array_slice( array_map( 'intval', $items ), 0, 200 );
            return array_values( array_filter( $items, static fn ( int $id ) => $id > 0 ) );
        }

        return array();
    }

    /**
     * Persist the wishlist for the current customer.
     *
     * @param int[] $items Product IDs.
     */
    private function save_items( array $items ): void {
        $items = array_values( array_unique( array_filter( array_map( 'intval', $items ) ) ) );

        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::USER_META_KEY, $items );
            return;
        }

        if ( function_exists( 'tejcart_has_cookie_consent' ) && ! tejcart_has_cookie_consent() ) {
            return;
        }

        $max = (int) apply_filters( 'tejcart_wishlist_max_cookie_items', self::COOKIE_MAX_ITEMS );
        if ( $max > 0 && count( $items ) > $max ) {
            $items = array_slice( $items, -1 * $max );
        }

        if ( ! headers_sent() ) {
            $options = array(
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            );
            if ( PHP_VERSION_ID >= 70300 ) {
                setcookie( self::COOKIE_KEY, wp_json_encode( $items ), $options );
            } else {
                setcookie(
                    self::COOKIE_KEY,
                    wp_json_encode( $items ),
                    $options['expires'],
                    $options['path'] . '; samesite=Lax',
                    $options['domain'],
                    $options['secure'],
                    true
                );
            }
        }

        $_COOKIE[ self::COOKIE_KEY ] = wp_json_encode( $items );
    }

    /**
     * Add a product to the wishlist.
     */
    public function add( int $product_id ): void {
        $items   = $this->get_items();
        $items[] = $product_id;
        $this->save_items( $items );
    }

    /**
     * Remove a product from the wishlist.
     */
    public function remove( int $product_id ): void {
        $items = array_diff( $this->get_items(), array( $product_id ) );
        $this->save_items( $items );
    }

    /**
     * Whether a product is in the wishlist.
     */
    public function contains( int $product_id ): bool {
        return in_array( $product_id, $this->get_items(), true );
    }

    /**
     * AJAX endpoint - toggle a product in the wishlist.
     */
    public function ajax_toggle(): void {
        check_ajax_referer( 'tejcart_wishlist', 'nonce' );

        $this->enforce_rate_limit();

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'tejcart' ) ) );
        }

        if ( $this->contains( $product_id ) ) {
            $this->remove( $product_id );
            wp_send_json_success( array( 'in_wishlist' => false ) );
        }

        $this->add( $product_id );
        wp_send_json_success( array( 'in_wishlist' => true ) );
    }

    /**
     * L-05: Per-IP rate limit on wishlist toggle.
     *
     * Mirrors the throttle pattern used by Cart_Ajax — defaults to 60
     * toggles per 60 seconds per client IP. Both the limit and the
     * window are filterable. Returning <= 0 from the filter disables
     * the limit (e.g. for synthetic monitoring or load tests).
     */
    private function enforce_rate_limit(): void {
        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return;
        }

        $max_attempts = (int) apply_filters( 'tejcart_rate_limit_wishlist_toggle', 60 );
        $window       = (int) apply_filters( 'tejcart_rate_limit_wishlist_toggle_window', 60 );
        if ( $max_attempts <= 0 || $window <= 0 ) {
            return;
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'wishlist_toggle', $ip, $max_attempts, $window ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Too many wishlist updates. Please slow down.', 'tejcart' ) ),
                429
            );
        }
    }

    /**
     * [tejcart_wishlist] shortcode renderer.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts = array() ): string {
        $items = $this->get_items();

        ob_start();
        echo '<div class="tejcart-wishlist">';
        echo '<h2>' . esc_html__( 'My Wishlist', 'tejcart' ) . '</h2>';

        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'Your wishlist is empty.', 'tejcart' ) . '</p>';
            echo '</div>';
            return ob_get_clean();
        }

        echo '<ul class="tejcart-wishlist-items">';
        foreach ( $items as $product_id ) {
            $product = class_exists( '\\TejCart\\Product\\Product_Factory' )
                ? \TejCart\Product\Product_Factory::get_product( $product_id )
                : null;

            if ( ! $product ) {
                continue;
            }

            $name  = method_exists( $product, 'get_name' ) ? $product->get_name() : '';
            $price = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
            $link  = get_permalink( $product_id );

            echo '<li>';
            echo '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a> ';
            if ( '' !== $price && function_exists( 'tejcart_price' ) ) {
                echo '<span class="price">' . wp_kses_post( tejcart_price( (float) $price ) ) . '</span> ';
            }
            echo '<a href="#" class="tejcart-wishlist-remove" data-product-id="' . esc_attr( $product_id ) . '">' . esc_html__( 'Remove', 'tejcart' ) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';

        return ob_get_clean();
    }
}
