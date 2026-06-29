<?php
/**
 * Cart AJAX Handlers
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side handlers for the cart-mutation AJAX actions that
 * tejcart-cart.js fires from the shop, single-product, cart, and
 * drawer surfaces:
 *
 *   - tejcart_add_to_cart        POST product_id, quantity
 *   - tejcart_remove_cart_item   POST cart_item_key
 *   - tejcart_update_cart_item   POST cart_item_key, quantity
 *   - tejcart_apply_coupon       POST code
 *   - tejcart_remove_coupon      POST code
 *
 * All four actions are registered under both `wp_ajax_*` and
 * `wp_ajax_nopriv_*` so guest visitors can use the cart. Every
 * response is JSON with a consistent shape:
 *
 *   Success: { success: true, data: { message, cart_count, cart_total, ... } }
 *   Failure: { success: false, data: { message } }
 */
class Cart_Ajax {
    /**
     * Wire WordPress hooks. Safe to call multiple times — the same
     * callable on the same hook is a no-op after the first add_action.
     *
     * @return void
     */
    public function register(): void {
        $actions = array(
            'tejcart_add_to_cart'      => 'ajax_add_to_cart',
            'tejcart_remove_cart_item' => 'ajax_remove_cart_item',
            'tejcart_update_cart_item' => 'ajax_update_cart_item',
            'tejcart_apply_coupon'     => 'ajax_apply_coupon',
            'tejcart_remove_coupon'    => 'ajax_remove_coupon',
            'tejcart_refresh_nonce'    => 'ajax_refresh_nonce',
            // Audit #98 / 01 #8 — in-cart shipping calculator widget.
            'tejcart_calculate_shipping' => 'ajax_calculate_shipping',
        );

        foreach ( $actions as $action => $method ) {
            add_action( 'wp_ajax_' . $action,        array( $this, $method ) );
            add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
        }

        add_action( 'template_redirect', array( $this, 'maybe_handle_cart_form_post' ) );
    }

    /**
     * Return a freshly-minted `tejcart_nonce` for the current
     * cookie/session. The cart drawer caches the nonce from the initial
     * page render; after a login/logout the WordPress cookie changes
     * and that nonce becomes stale, 403-ing every subsequent
     * mutation. Clients call this endpoint (rate-limited by IP) on a
     * 403 to rotate the nonce in place and retry.
     *
     * The response also surfaces a fresh `tejcart_paypal` nonce so the
     * PayPal JS module (which mirrors the cart's 403-retry pattern)
     * can rotate its page-scoped nonce on the same round trip. Both
     * nonces share the same lifecycle — they go stale together
     * whenever the WP cookie rotates (login/logout, page cache, session
     * expiry) — so it is cheaper for the buyer's network and the
     * server's rate-limit budget to mint both in one call than to
     * have two parallel refresh endpoints.
     *
     * Same-origin check still applies — the endpoint is only useful
     * to a real browser session against this site.
     */
    public function ajax_refresh_nonce(): void {
        // Rate-limit BEFORE the origin check so failed-origin requests
        // still consume a token. Otherwise an attacker can spam
        // origin-rejected probes for free and use other observable
        // signals to deplete legitimate users' per-session budgets.
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
            // 30 nonce refreshes / 5 minutes is plenty of headroom for
            // a tab cycling through login/logout but tight enough that
            // a leaked nonce can't be cheaply rotated by an attacker.
            //
            // Bucket on IP+user_id (or session id for guests) so
            // a shared NAT (school, office, mobile carrier) can't have one
            // buyer starve every other genuine buyer behind the same edge IP.
            $user_id     = (int) get_current_user_id();
            $session_id  = '';
            if ( 0 === $user_id && function_exists( 'wp_get_session_token' ) ) {
                $session_id = (string) wp_get_session_token();
            }
            if ( '' === $session_id && isset( $_COOKIE[ 'tejcart_session' ] ) ) {
                $session_id = sanitize_text_field( wp_unslash( (string) $_COOKIE[ 'tejcart_session' ] ) );
            }
            $identity   = $user_id > 0 ? 'u' . $user_id : ( '' !== $session_id ? 's' . substr( hash( 'sha256', $session_id ), 0, 16 ) : 'anon' );
            $identifier = $ip . '|' . $identity;
            if ( \TejCart\Security\Rate_Limiter::check_and_record( 'nonce_refresh', $identifier, 30, 300 ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'Too many nonce refreshes. Please reload the page.', 'tejcart' ) ),
                    429
                );
            }
        }

        if ( ! self::verify_origin() ) {
            wp_send_json_error(
                array( 'message' => __( 'Origin check failed.', 'tejcart' ) ),
                403
            );
        }

        wp_send_json_success(
            array(
                'nonce'             => wp_create_nonce( 'tejcart_nonce' ),
                'paypal_nonce'      => wp_create_nonce( 'tejcart_paypal' ),
                'logged_in'         => is_user_logged_in(),
                'current_user_id'   => (int) get_current_user_id(),
            )
        );
    }

    /**
     * Server-side cart-form POST handler used when JS is unavailable.
     *
     * Reads the tejcart_cart_nonce + cart_item_qty[] inputs from the cart
     * page form, validates them, and updates the cart accordingly. We
     * deliberately PRG-redirect back to the same URL afterwards so a
     * refresh doesn't double-submit the same quantity changes.
     *
     * Bound to `template_redirect` (priority 10) so it runs after WP has
     * resolved the request to a page but before any template output.
     */
    public function maybe_handle_cart_form_post(): void {
        if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
            return;
        }
        if ( empty( $_POST['tejcart_cart_nonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( (string) $_POST['tejcart_cart_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'tejcart_update_cart' ) ) {
            return;
        }

        if ( ! self::verify_origin() ) {
            return;
        }

        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
            if ( \TejCart\Security\Rate_Limiter::check_and_record( 'cart_form_post', $ip, 30, 60 ) ) {
                return;
            }
        }

        $cart = $this->get_cart();

        if ( ! empty( $_POST['cart_item_qty'] ) && is_array( $_POST['cart_item_qty'] ) ) {
            // Audit #44 / 02 M-2 — cap the number of qty updates a
            // single form POST can carry. The AJAX path rate-limits
            // each `update_cart_item` call individually, but a non-
            // AJAX submit collapses N updates into one rate-limit
            // hit. Without a cap, a 1000-key POST burns server time
            // once per minute. The ceiling is filterable for unusual
            // catalogue shapes.
            $max_qty_updates = (int) apply_filters( 'tejcart_cart_form_max_qty_updates', 100 );
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $key is sanitized below; $qty is cast to int.
            $posted_qty = wp_unslash( $_POST['cart_item_qty'] );
            if ( $max_qty_updates > 0 && count( (array) $posted_qty ) > $max_qty_updates ) {
                $posted_qty = array_slice( (array) $posted_qty, 0, $max_qty_updates, true );
            }
            foreach ( (array) $posted_qty as $key => $qty ) {
                $key = is_string( $key ) ? sanitize_text_field( $key ) : '';
                if ( '' === $key || ! preg_match( '/^[a-f0-9]{32,128}$/', $key ) ) {
                    continue;
                }
                $cart->update_quantity( $key, (int) $qty );
            }
        }

        if ( ! empty( $_POST['cart_item_remove'] ) ) {
            $remove_key = sanitize_text_field( wp_unslash( (string) $_POST['cart_item_remove'] ) );
            if ( preg_match( '/^[a-f0-9]{32,128}$/', $remove_key ) ) {
                $cart->remove( $remove_key );
            }
        }

        $redirect = self::resolve_cart_form_redirect( wp_get_referer() );
        wp_safe_redirect( add_query_arg( 'cart_updated', '1', $redirect ) );
        exit;
    }

    /**
     * Pick a safe redirect target after a non-AJAX cart form POST.
     *
     * `wp_get_referer()` is client-controlled. `wp_safe_redirect` only
     * checks that the *host* is allowed; it does not constrain the
     * *path*. An attacker could craft a cart-form POST whose Referer
     * header is `https://victim.example/wp-admin/profile.php?action=delete-user`
     * and the user would land on a sensitive admin URL after a cart
     * update. The host check passes, the path is anywhere.
     *
     * This helper accepts the referer only when it points at one of
     * the known TejCart shopper-facing pages (cart / checkout /
     * myaccount / thankyou) AND lives on the same host as the site.
     * Anything else falls back to the configured cart page (or
     * `home_url('/')` as the final default). See review finding H-3.
     *
     * Public + static so the regression test can drive it without a
     * full POST cycle.
     *
     * @param string|false $referer Raw referer URL (typically the
     *                              return value of {@see wp_get_referer()}).
     * @return string An absolute URL safe to pass to wp_safe_redirect.
     */
    public static function resolve_cart_form_redirect( $referer ): string {
        $fallback = '';
        if ( function_exists( 'tejcart_get_page_url' ) ) {
            $fallback = (string) tejcart_get_page_url( 'cart' );
        }
        if ( '' === $fallback ) {
            $fallback = (string) home_url( '/' );
        }

        if ( ! is_string( $referer ) || '' === $referer ) {
            return $fallback;
        }

        // Strip stale nonces / single-use markers from any candidate
        // before we evaluate it.
        $candidate = remove_query_arg( array( '_wpnonce', 'cart_updated' ), $referer );

        if ( ! is_string( $candidate ) || '' === $candidate ) {
            return $fallback;
        }

        $cand_parts = wp_parse_url( $candidate );
        $site_parts = wp_parse_url( (string) home_url( '/' ) );
        if ( ! is_array( $cand_parts ) || ! is_array( $site_parts ) ) {
            return $fallback;
        }

        $cand_host = isset( $cand_parts['host'] ) ? strtolower( (string) $cand_parts['host'] ) : '';
        $site_host = isset( $site_parts['host'] ) ? strtolower( (string) $site_parts['host'] ) : '';
        if ( '' === $cand_host || $cand_host !== $site_host ) {
            return $fallback;
        }

        $cand_path = isset( $cand_parts['path'] ) ? (string) $cand_parts['path'] : '/';

        // Build the allow-list from the configured TejCart pages.
        $allowed_paths = array();
        if ( function_exists( 'tejcart_get_page_url' ) ) {
            foreach ( array( 'cart', 'checkout', 'myaccount', 'thankyou' ) as $page ) {
                $page_url = (string) tejcart_get_page_url( $page );
                if ( '' === $page_url ) {
                    continue;
                }
                $page_parts = wp_parse_url( $page_url );
                if ( is_array( $page_parts ) && isset( $page_parts['path'] ) ) {
                    $allowed_paths[] = (string) $page_parts['path'];
                }
            }
        }

        /**
         * Filter the allow-list of paths the cart-form POST may
         * redirect back to.
         *
         * Default is the configured cart / checkout / myaccount /
         * thankyou page paths. Stores that surface cart actions on
         * additional surfaces (a custom shop archive, a side-cart
         * route) can extend the list. Each entry is matched as a
         * prefix against the candidate path.
         *
         * @since 1.0.1
         *
         * @param string[] $allowed_paths Path prefixes safe to redirect to.
         */
        $allowed_paths = (array) apply_filters( 'tejcart_cart_form_redirect_paths', $allowed_paths );

        foreach ( $allowed_paths as $allowed ) {
            $allowed = (string) $allowed;
            if ( '' === $allowed ) {
                continue;
            }
            // Trailing-slash insensitive prefix match.
            $needle = rtrim( $allowed, '/' );
            $hay    = rtrim( $cand_path, '/' );
            if ( $hay === $needle || 0 === strpos( $hay . '/', $needle . '/' ) ) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Add a product to the cart.
     *
     * @return void
     */
    public function ajax_add_to_cart(): void {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        $quantity   = isset( $_POST['quantity'] )   ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        $variation  = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;

        if ( $product_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing product.', 'tejcart' ) ), 400 );
        }

        $data = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        if ( isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- $key and $value are sanitized below; nonce verified in verify_nonce() above.
            foreach ( wp_unslash( $_POST['data'] ) as $key => $value ) {
                $sk = sanitize_key( $key );
                // Reserve underscore-prefixed keys for plugin-internal
                // snapshots (`_price_at_add`, `_currency_at_add`, …) and
                // the `parent_id` slot which Cart::add() owns for variation
                // dispatch — letting the buyer seed it makes a non-variation
                // line report is_variation()===true and forges a
                // variation_id on the resulting order line (M-2).
                if ( '' === $sk || '_' === $sk[0] || 'parent_id' === $sk ) {
                    continue;
                }
                $data[ $sk ] = is_scalar( $value )
                    ? sanitize_text_field( (string) $value )
                    : '';
            }
        }

        $cart   = $this->get_cart();
        $result = $cart->add( $product_id, $quantity, $data, $variation );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ),
                400
            );
        }

        if ( false === $result || '' === $result ) {
            wp_send_json_error(
                array( 'message' => __( 'This product could not be added to your cart.', 'tejcart' ) ),
                400
            );
        }

        wp_send_json_success(
            array(
                'message'       => __( 'Item added to cart.', 'tejcart' ),
                'cart_item_key' => $result,
            ) + $this->cart_state( $cart )
        );
    }

    /**
     * Remove a single line item from the cart.
     *
     * @return void
     */
    public function ajax_remove_cart_item(): void {
        $this->verify_nonce();
        $this->enforce_rate_limit( 'remove' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        $key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';

        if ( '' === $key || ! preg_match( '/^[a-f0-9]{32,128}$/', $key ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid cart item.', 'tejcart' ) ), 400 );
        }

        $cart    = $this->get_cart();
        $removed = $cart->remove( $key );

        if ( ! $removed ) {
            wp_send_json_error(
                array( 'message' => __( 'That item is no longer in your cart.', 'tejcart' ) ),
                404
            );
        }

        wp_send_json_success(
            array( 'message' => __( 'Item removed from cart.', 'tejcart' ) )
            + $this->cart_state( $cart )
        );
    }

    /**
     * Update the quantity of an existing line item.
     *
     * @return void
     */
    public function ajax_update_cart_item(): void {
        $this->verify_nonce();
        $this->enforce_rate_limit( 'update' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        $key      = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- cast to (int) sanitizes the value; nonce verified in verify_nonce() above.
        $quantity = isset( $_POST['quantity'] ) ? (int) wp_unslash( $_POST['quantity'] ) : 0;

        if ( '' === $key || ! preg_match( '/^[a-f0-9]{32,128}$/', $key ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid cart item.', 'tejcart' ) ), 400 );
        }

        $cart    = $this->get_cart();
        $updated = $cart->update_quantity( $key, $quantity );

        if ( is_wp_error( $updated ) ) {
            wp_send_json_error(
                array(
                    'message' => $updated->get_error_message(),
                    'code'    => $updated->get_error_code(),
                ) + $this->cart_state( $cart ),
                400
            );
        }

        if ( ! $updated ) {
            wp_send_json_error(
                array( 'message' => __( 'That item is no longer in your cart.', 'tejcart' ) ),
                404
            );
        }

        wp_send_json_success(
            array( 'message' => __( 'Cart updated.', 'tejcart' ) )
            + $this->cart_state( $cart )
        );
    }

    /**
     * Apply a coupon code to the cart.
     *
     * @return void
     */
    public function ajax_apply_coupon(): void {
        $this->verify_nonce();
        $this->enforce_rate_limit( 'coupon_apply' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        if ( isset( $_POST['code'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
            $code = sanitize_text_field( wp_unslash( $_POST['code'] ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        } elseif ( isset( $_POST['coupon_code'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
            $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) );
        } else {
            $code = '';
        }

        if ( '' === $code ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a coupon code.', 'tejcart' ) ), 400 );
        }

        // Per-coupon-code aggregate ceiling on top of the per-IP
        // budget. A coupon code is 4–8 chars; 10/5min × N attacker
        // IPs would otherwise let a botnet brute-force the code-space
        // even with the per-IP guard. Once 200 failed attempts have
        // landed against THIS specific code in 24h (across all IPs),
        // we lock the code for the rest of the window. The legitimate
        // success path resets the counter so a real shopper retrying
        // a real code doesn't burn the budget for everyone else. See
        // review finding M-7.
        $code_lc = strtolower( $code );
        if ( $this->coupon_code_brute_force_locked( $code_lc ) ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Coupon apply suppressed: code="%s" — aggregate brute-force ceiling tripped.', $code_lc ),
                    'warning'
                );
            }
            wp_send_json_error(
                array(
                    'message' => __( 'This coupon code is temporarily unavailable. Please try again later.', 'tejcart' ),
                    'code'    => 'coupon_locked',
                ),
                429
            );
        }

        // Audit #48 / 02 M-7 — per-(IP, all-codes) cross-code ceiling.
        // The per-(code, IP) and per-code-aggregate buckets above
        // both miss an attacker who fans OUT across the code-space
        // from a single IP (or a small NAT) — each guess hits a
        // different aggregate bucket. This cross-code per-IP
        // counter catches that fan-out shape. Filterable both for
        // limit + window so merchants on niche traffic shapes can
        // tune.
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip          = \TejCart\Security\Rate_Limiter::get_client_ip();
            $xc_limit    = (int) apply_filters( 'tejcart_coupon_cross_code_limit', 100 );
            $xc_window   = (int) apply_filters( 'tejcart_coupon_cross_code_window', DAY_IN_SECONDS );
            if ( '' !== $ip && $xc_limit > 0 && $xc_window > 0 ) {
                if ( \TejCart\Security\Rate_Limiter::is_rate_limited(
                    'coupon_apply_cross_code',
                    $ip,
                    $xc_limit,
                    $xc_window
                ) ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            'Coupon apply suppressed: cross-code per-IP ceiling tripped.',
                            'warning'
                        );
                    }
                    wp_send_json_error(
                        array(
                            'message' => __( 'Too many coupon attempts from your network. Please try again later.', 'tejcart' ),
                            'code'    => 'coupon_locked',
                        ),
                        429
                    );
                }
            }
        }

        $cart   = $this->get_cart();
        $result = $cart->apply_coupon( $code );

        if ( is_wp_error( $result ) ) {
            $this->record_coupon_apply_failure( $code_lc );

            if ( function_exists( 'tejcart_log' ) ) {
                $ip = class_exists( '\\TejCart\\Security\\Rate_Limiter' )
                    ? \TejCart\Security\Rate_Limiter::get_client_ip()
                    : '';
                // Redact the coupon code and IP before they hit the
                // log file. Codes can carry merchant-typed PII (e.g.
                // an email-based promo code) and IPs are PII under
                // GDPR Art.4(1) — the merchant's log retention
                // policy is rarely the same as the privacy policy
                // so it's safer to log a truncated/hashed form that
                // is still useful for debugging without keeping
                // identifying information indefinitely. See review
                // finding L-2.
                tejcart_log(
                    sprintf(
                        'Coupon apply failed: code=%s reason="%s" ip=%s',
                        self::redact_coupon_code( $code ),
                        $result->get_error_code(),
                        self::redact_ip( $ip )
                    ),
                    'info'
                );
            }

            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ),
                400
            );
        }

        // Success — reset the brute-force counter for this code so
        // a real shopper retrying a valid code (e.g. they fixed their
        // cart minimum) doesn't stay penalised.
        $this->reset_coupon_apply_failure_counter( $code_lc );

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %s: coupon code */
                    __( 'Coupon "%s" applied.', 'tejcart' ),
                    strtoupper( $code )
                ),
            ) + $this->cart_state( $cart )
        );
    }

    /**
     * Remove an applied coupon from the cart.
     *
     * @return void
     */
    public function ajax_remove_coupon(): void {
        $this->verify_nonce();
        $this->enforce_rate_limit( 'coupon_remove' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        if ( isset( $_POST['code'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
            $code = sanitize_text_field( wp_unslash( $_POST['code'] ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
        } elseif ( isset( $_POST['coupon_code'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() above.
            $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) );
        } else {
            $code = '';
        }

        if ( '' === $code ) {
            wp_send_json_error( array( 'message' => __( 'Missing coupon code.', 'tejcart' ) ), 400 );
        }

        $cart    = $this->get_cart();
        $removed = $cart->remove_coupon( $code );

        if ( ! $removed ) {
            wp_send_json_error(
                array( 'message' => __( 'That coupon was not applied.', 'tejcart' ) ),
                404
            );
        }

        wp_send_json_success(
            array( 'message' => __( 'Coupon removed.', 'tejcart' ) )
            + $this->cart_state( $cart )
        );
    }

    /**
     * Estimate shipping for the current cart against a buyer-supplied
     * destination triple (country / state / postcode).
     *
     * Audit #98 / 01 #8 — the in-cart shipping calculator widget was
     * missing; shoppers couldn't see live shipping totals until they
     * landed on checkout. The endpoint sets the cart's shipping
     * destination (so subsequent renders reuse it), recalculates
     * totals, and returns the available methods + the chosen method's
     * cost so the cart-totals row can display a live number.
     *
     * @return void
     */
    public function ajax_calculate_shipping(): void {
        $this->verify_nonce();
        $this->enforce_rate_limit( 'shipping_calc' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $country  = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['country'] ) ) : '';
        $state    = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['state'] ) ) : '';
        $postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['postcode'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Light input validation — ISO-2 country, ≤32-char state/postcode.
        $country  = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $country ) ?? '', 0, 2 ) );
        $state    = substr( $state, 0, 32 );
        $postcode = substr( $postcode, 0, 32 );

        if ( '' === $country ) {
            wp_send_json_error(
                array( 'message' => __( 'Please select a country.', 'tejcart' ) ),
                400
            );
        }

        $cart = $this->get_cart();
        if ( ! $cart->needs_shipping() ) {
            wp_send_json_error(
                array( 'message' => __( 'Your cart does not require shipping.', 'tejcart' ) ),
                400
            );
        }

        $cart->set_shipping_destination( $country, $state, $postcode );

        $methods = array();
        if ( class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            $manager   = new \TejCart\Shipping\Shipping_Manager();
            $instances = $manager->get_available_methods( $country, $state, $cart, $postcode );

            foreach ( $instances as $instance ) {
                if ( ! is_object( $instance ) ) {
                    continue;
                }
                $cost = method_exists( $instance, 'calculate' ) ? (float) $instance->calculate( $cart ) : 0.0;
                // Mirror Cart_Calculator's FINAL shipping conversion seam so
                // multi-currency conversion applies once to both the numeric
                // cost and its formatted HTML below. We use
                // `tejcart_calculated_shipping_with_classes` (not the per-rate
                // filter) because the currency-switcher converts on the final
                // filter only — hooking both would double-convert. Passthrough
                // on a single-currency store.
                $cost = (float) apply_filters( 'tejcart_calculated_shipping_with_classes', $cost, $cart );
                $id   = method_exists( $instance, 'get_id' ) ? (string) $instance->get_id() : '';
                $title = method_exists( $instance, 'get_title' ) ? (string) $instance->get_title() : $id;
                $methods[] = array(
                    'id'         => $id,
                    'title'      => $title,
                    'cost'       => $cost,
                    'cost_html'  => function_exists( 'tejcart_price' ) ? tejcart_price( $cost ) : (string) $cost,
                );
            }
        }

        if ( empty( $methods ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No shipping options are available for that address.', 'tejcart' ) ),
                404
            );
        }

        // Pick the cheapest method as the default so the totals row
        // shows a concrete number rather than "calculated at checkout".
        usort( $methods, static function ( $a, $b ) {
            return ( $a['cost'] <=> $b['cost'] );
        } );
        if ( ! empty( $methods[0]['id'] ) ) {
            $cart->set_chosen_shipping_method( (string) $methods[0]['id'] );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Shipping options updated.', 'tejcart' ),
                'methods' => $methods,
            )
            + $this->cart_state( $cart )
        );
    }

    /**
     * Resolve the Cart singleton from the plugin container. Kept private
     * so every handler has a single instantiation path that matches
     * what the frontend Cart class sees on the rendered pages.
     *
     * @return Cart
     */
    private function get_cart(): Cart {
        return tejcart()->cart();
    }

    /**
     * Verify the shared cart nonce **and** that the request originates from
     * the same site. Mirrors what tejcart-cart.js sends in `_wpnonce` after
     * reading `params.nonce` from the localized script data
     * (Frontend::get_script_params() creates it as
     * `wp_create_nonce('tejcart_nonce')`).
     *
     * The same-origin check is layered on top of the nonce as
     * defense-in-depth for cart-state mutations — see issue #384. The cart
     * cookie is `SameSite=Lax`, which already blocks cross-site POSTs from
     * carrying it, but a nonce-leak via a sloppy embed or a misconfigured
     * caching layer would otherwise leave only the nonce as the gate.
     * Both must pass.
     *
     * @return void
     */
    private function verify_nonce(): void {
        if ( ! check_ajax_referer( 'tejcart_nonce', '_wpnonce', false )
            && ! check_ajax_referer( 'tejcart_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please reload the page and try again.', 'tejcart' ) ),
                403
            );
        }

        if ( ! $this->verify_origin() ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please reload the page and try again.', 'tejcart' ) ),
                403
            );
        }
    }

    /**
     * Confirm the current request was made from the same origin as the site.
     *
     * Reads `Origin` first (sent by browsers on every CORS-eligible request,
     * including same-origin POSTs from fetch/XHR) and falls back to
     * `Referer` for the rare case the browser strips Origin (e.g. some
     * privacy modes on top-level navigations). If neither header is
     * present, we accept the request — server-to-server clients calling
     * the AJAX endpoint legitimately (e.g. wp-cli, cron) won't have either
     * header, and the nonce remains the gate for those.
     *
     * Compared host-only against `home_url()` so subpath-installed sites
     * (`example.com/shop/`) still pass.
     *
     * @return bool True when origin matches the site or is absent.
     */

    /**
     * Reduce a coupon code to a debugging fingerprint that does not
     * leak the full plaintext into log files. We keep the first three
     * uppercase characters (almost always SKU-style prefixes are
     * unique enough to identify the campaign in admin reporting)
     * plus the length and a short SHA-256 fingerprint, so two failed
     * attempts at the same code correlate without the full code being
     * persisted. See review finding L-2.
     *
     * @internal
     */
    public static function redact_coupon_code( string $code ): string {
        // Delegate to the canonical Log_Redactor (L-5) so every log
        // call site uses the same redaction surface.
        if ( class_exists( '\\TejCart\\Security\\Log_Redactor' ) ) {
            return \TejCart\Security\Log_Redactor::coupon_code( $code );
        }
        $code = strtoupper( trim( $code ) );
        if ( '' === $code ) {
            return '(empty)';
        }
        $prefix = substr( $code, 0, 3 );
        $hash   = substr( hash( 'sha256', $code ), 0, 8 );
        return sprintf( '%s***[%d:%s]', $prefix, strlen( $code ), $hash );
    }

    /**
     * Reduce a client IP to a /24 (IPv4) or /48 (IPv6) prefix +
     * short HMAC fingerprint so log lines retain enough signal for
     * abuse correlation without storing the full address (PII under
     * GDPR Art.4(1)). See review finding L-2.
     *
     * @internal
     */
    public static function redact_ip( string $ip ): string {
        if ( class_exists( '\\TejCart\\Security\\Log_Redactor' ) ) {
            return \TejCart\Security\Log_Redactor::ip( $ip );
        }
        $ip = trim( $ip );
        if ( '' === $ip ) {
            return '(none)';
        }

        $hash_suffix = '|' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 8 );

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts = explode( '.', $ip );
            if ( 4 === count( $parts ) ) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0' . $hash_suffix;
            }
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ( false !== $packed ) {
                return bin2hex( substr( $packed, 0, 6 ) ) . '::/48' . $hash_suffix;
            }
        }

        return '(redacted)' . $hash_suffix;
    }

    public static function verify_origin(): bool {
        $origin_header = '';
        if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
            $origin_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $origin_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        }

        if ( '' === $origin_header ) {
            /**
             * Filter whether requests with NO `Origin` and NO
             * `Referer` header are accepted by the cart same-origin
             * check.
             *
             * Default (since M-03): `false` — reject. The nonce
             * + SameSite=Lax cookie pair still gates practical CSRF,
             * but "absent header → accept" is the kind of decision
             * that quietly degrades posture as browsers loosen their
             * Referer-Policy defaults. Hosts that legitimately have
             * no Origin/Referer header (wp-cli, cron, certain
             * server-to-server integration tooling) can opt back in
             * with a low-priority filter, e.g.:
             *
             *     add_filter( 'tejcart_cart_allow_missing_origin',
             *         fn() => defined( 'WP_CLI' ) && WP_CLI );
             *
             * See audit finding M-03 (was L-5).
             *
             * @since 1.0.1
             *
             * @param bool $allow_when_absent Default false.
             */
            return (bool) apply_filters( 'tejcart_cart_allow_missing_origin', false );
        }

        // Reject obviously malformed input before handing it to wp_parse_url.
        // wp_parse_url is forgiving and will return an empty host for inputs
        // like "http://\x00.example", which strcasecmp would then silently
        // accept.
        $origin_header = trim( $origin_header );
        if ( strlen( $origin_header ) > 2048 || preg_match( '/[\x00-\x1f\x7f]/', $origin_header ) ) {
            return false;
        }
        if ( ! filter_var( $origin_header, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $origin_parts = wp_parse_url( $origin_header );
        if ( ! is_array( $origin_parts ) ) {
            return false;
        }
        $origin_host   = isset( $origin_parts['host'] ) ? strtolower( (string) $origin_parts['host'] ) : '';
        $origin_scheme = isset( $origin_parts['scheme'] ) ? strtolower( (string) $origin_parts['scheme'] ) : '';
        if ( '' === $origin_host || '' === $origin_scheme ) {
            return false;
        }
        $origin_port = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : self::default_port_for_scheme( $origin_scheme );

        $site_parts    = wp_parse_url( (string) home_url( '/' ) );
        $site_host     = is_array( $site_parts ) && isset( $site_parts['host'] ) ? strtolower( (string) $site_parts['host'] ) : '';
        $site_scheme   = is_array( $site_parts ) && isset( $site_parts['scheme'] ) ? strtolower( (string) $site_parts['scheme'] ) : '';
        $site_port     = is_array( $site_parts ) && isset( $site_parts['port'] ) ? (int) $site_parts['port'] : self::default_port_for_scheme( $site_scheme );

        // The request's scheme must match the site's. A host-only check
        // would accept http://victim.com/... when the legitimate site
        // runs HTTPS — defeating CSRF protection if the cookie ever
        // travels in clear text (downgrade / local-network MITM).
        if ( $origin_scheme !== $site_scheme || $origin_port !== $site_port ) {
            return false;
        }

        // Build the trusted-host allowlist: site host + any extras returned
        // by the filter (e.g. an admin's CDN domain or a staging host).
        $allowed_hosts = array_filter(
            array_map(
                static function ( $host ) {
                    return is_string( $host ) ? strtolower( trim( $host ) ) : '';
                },
                (array) apply_filters( 'tejcart_allowed_ajax_origins', array( $site_host ) )
            )
        );

        return in_array( $origin_host, $allowed_hosts, true );
    }

    /**
     * Map a URL scheme to its default port for tuple comparison.
     */
    private static function default_port_for_scheme( string $scheme ): int {
        if ( 'https' === $scheme ) {
            return 443;
        }
        if ( 'http' === $scheme ) {
            return 80;
        }
        return 0;
    }

    /**
     * Per-IP rate-limit guard for the AJAX cart mutations.
     *
     * Cart::add() already throttles add_to_cart inside Cart::add(), but the
     * other mutations (update qty, remove line, apply coupon, remove coupon)
     * had no ceiling — a script could hammer them to brute-force coupon
     * codes or burn DB writes. Each action gets its own bucket with a
     * sensible per-minute cap; coupon attempts get the tightest budget
     * because they're the only one that reveals "valid vs invalid" data.
     *
     * @param string $action 'update' | 'remove' | 'coupon_apply' | 'coupon_remove'
     */
    private function enforce_rate_limit( string $action ): void {
        // The coupon_apply action gets the tightest budget because it
        // is the only mutation that reveals "valid vs invalid" data
        // and is therefore the only one that's useful to brute-force.
        // Window matches PayPal_AJAX (5 minutes) for consistency with
        // the rest of the gateway-touching surface.
        $budgets = array(
            'update'        => array( 60, 60 ),
            'remove'        => array( 30, 60 ),
            'coupon_apply'  => array( 10, 300 ),
            'coupon_remove' => array( 30, 60 ),
            // Audit #98 — the shipping calculator is a free-text input
            // that triggers a multi-zone scan. 30 calls / 5 min lets a
            // shopper retype a postcode a few times but slows abuse.
            'shipping_calc' => array( 30, 300 ),
        );
        if ( ! isset( $budgets[ $action ] ) ) {
            return;
        }

        list( $max, $window ) = $budgets[ $action ];

        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return;
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'cart_' . $action, $ip, $max, $window ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Too many requests. Please try again shortly.', 'tejcart' ) ),
                429
            );
        }
    }

    /**
     * Default budget for the per-coupon-code aggregate brute-force
     * ceiling. 200 failed attempts in 24h across the whole site
     * (regardless of source IP) is far above any realistic legitimate
     * retry pattern but well below what a botnet would need to
     * brute-force a 4–8 char dictionary code-space.
     *
     * Filterable via `tejcart_coupon_code_brute_force_limit`.
     */
    private const COUPON_BRUTE_FORCE_LIMIT  = 200;
    private const COUPON_BRUTE_FORCE_WINDOW = DAY_IN_SECONDS;

    /**
     * Site-aggregate tripwire — all-IP failure count per code per day.
     * Fires `tejcart_coupon_code_aggregate_anomaly` when crossed; does
     * NOT block legitimate users. Defaults to 5,000 (well above any
     * realistic retry pattern but well below what a botnet probing
     * 4-8 char code-space needs).
     */
    private const COUPON_AGGREGATE_LIMIT  = 5000;
    private const COUPON_AGGREGATE_WINDOW = DAY_IN_SECONDS;

    /**
     * Build the rate-limiter bucket for the per-code brute-force counter.
     *
     * M-5: bucket on `(code, IP)` rather than code alone. The previous
     * code-only key meant an attacker who knew or guessed a popular
     * promo code (`SUMMER25`, `BLACKFRIDAY`) could fire 200 wrong-secret
     * `apply_coupon` requests in 24h and lock that code site-wide for
     * every legitimate shopper — a trivial campaign-killer DoS. Mirrors
     * the per-(key, IP) bucket fix in API_Keys::authenticate.
     *
     * The 200/day site-aggregate tripwire is preserved as a separate
     * counter (`coupon_code_aggregate_failures`) at a much higher
     * threshold so a coordinated botnet still raises a flag.
     */
    private static function coupon_brute_force_bucket( string $code_lc ): string {
        // Hash the code so the bucket key never carries plaintext
        // (some object-cache backends log keys); 16 hex chars is
        // plenty given the 200/day per-IP budget.
        $code_hash = substr( hash( 'sha256', $code_lc ), 0, 16 );
        $ip        = class_exists( '\\TejCart\\Security\\Rate_Limiter' )
            ? (string) \TejCart\Security\Rate_Limiter::get_client_ip()
            : '';
        return 'cc:' . $code_hash . '|' . ( '' !== $ip ? $ip : 'anon' );
    }

    /**
     * Site-aggregate bucket — ALL IPs, one code. Used as a tripwire
     * counter that fires when total failed attempts against a code
     * exceed COUPON_AGGREGATE_LIMIT in COUPON_AGGREGATE_WINDOW. Does
     * NOT block apply_coupon — it just signals an action listeners
     * can use to alert the merchant. Replaces the per-code lock-out
     * that M-5 weaponised.
     */
    private static function coupon_aggregate_bucket( string $code_lc ): string {
        return 'cc_total:' . substr( hash( 'sha256', $code_lc ), 0, 16 );
    }

    /**
     * Whether the per-code aggregate brute-force ceiling has been
     * tripped for this coupon code.
     */
    private function coupon_code_brute_force_locked( string $code_lc ): bool {
        if ( '' === $code_lc || ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return false;
        }

        /**
         * Filter the per-coupon-code aggregate failure ceiling.
         *
         * @since 1.0.1
         *
         * @param int $limit Max failed attempts per code per window (default 200).
         */
        $limit = (int) apply_filters(
            'tejcart_coupon_code_brute_force_limit',
            self::COUPON_BRUTE_FORCE_LIMIT
        );
        if ( $limit <= 0 ) {
            return false;
        }

        return \TejCart\Security\Rate_Limiter::is_rate_limited(
            'coupon_code_failures',
            self::coupon_brute_force_bucket( $code_lc ),
            $limit,
            self::COUPON_BRUTE_FORCE_WINDOW
        );
    }

    /**
     * Increment the per-(code, IP) failure counter, AND the site-aggregate
     * tripwire counter. Called only when apply_coupon returns a WP_Error.
     */
    private function record_coupon_apply_failure( string $code_lc ): void {
        if ( '' === $code_lc || ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return;
        }

        \TejCart\Security\Rate_Limiter::record(
            'coupon_code_failures',
            self::coupon_brute_force_bucket( $code_lc ),
            self::COUPON_BRUTE_FORCE_WINDOW
        );

        // Audit #48 / 02 M-7 — record the cross-code per-IP counter
        // that the apply-side check above reads against. Filterable
        // window mirrors the read-side filter so a merchant who
        // tunes the limit also tunes the matching record-window.
        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
        if ( '' !== $ip ) {
            $xc_window = (int) apply_filters( 'tejcart_coupon_cross_code_window', DAY_IN_SECONDS );
            if ( $xc_window > 0 ) {
                \TejCart\Security\Rate_Limiter::record( 'coupon_apply_cross_code', $ip, $xc_window );
            }
        }

        // Site-aggregate tripwire (M-5): non-blocking anomaly signal so
        // a coordinated botnet across many IPs still surfaces. Listeners
        // can email/Slack the merchant; the legitimate per-(code, IP)
        // budget continues to govern actual rate limiting.
        $aggregate_count = (int) \TejCart\Security\Rate_Limiter::record(
            'coupon_code_aggregate_failures',
            self::coupon_aggregate_bucket( $code_lc ),
            self::COUPON_AGGREGATE_WINDOW
        );

        /**
         * Filter the site-aggregate failure ceiling above which the
         * `tejcart_coupon_code_aggregate_anomaly` action fires.
         *
         * @since 1.0.1
         *
         * @param int $limit Default 5000.
         */
        $aggregate_limit = (int) apply_filters(
            'tejcart_coupon_code_aggregate_limit',
            self::COUPON_AGGREGATE_LIMIT
        );
        if ( $aggregate_limit > 0 && $aggregate_count === $aggregate_limit + 1 ) {
            // Fire exactly once per window when the threshold is first
            // crossed (counter increments past limit + 1 are silenced).
            /**
             * Fires when a coupon code's aggregate (all-IP) failure count
             * crosses the configured threshold within the rolling window.
             * Treat as an anomaly signal: log to ops, optionally email
             * the merchant. Does NOT lock the code.
             *
             * @since 1.0.1
             *
             * @param string $code_lc Lower-cased coupon code (raw, not hashed).
             * @param int    $count   Current aggregate failure count.
             */
            do_action( 'tejcart_coupon_code_aggregate_anomaly', $code_lc, $aggregate_count );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'Coupon code aggregate anomaly: %s crossed %d failures in %ds.',
                        self::redact_coupon_code( $code_lc ),
                        $aggregate_count,
                        self::COUPON_AGGREGATE_WINDOW
                    ),
                    'warning'
                );
            }
        }
    }

    /**
     * Reset the per-code failure counter on a successful apply so a
     * real shopper retrying a real code (after fixing their cart
     * state) doesn't keep penalising the budget for everyone else.
     */
    private function reset_coupon_apply_failure_counter( string $code_lc ): void {
        if ( '' === $code_lc || ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return;
        }
        \TejCart\Security\Rate_Limiter::reset(
            'coupon_code_failures',
            self::coupon_brute_force_bucket( $code_lc )
        );
    }

    /**
     * Build the cart-state payload every handler includes in its
     * success response. Lets the frontend update the mini-cart badge,
     * drawer, and totals without needing a full cart reload.
     *
     * Includes a freshly-rendered drawer fragment (`drawer_html`) so
     * the JS can swap it straight into the DOM and open the drawer
     * with the new state in one tick — no stale empty drawer between
     * the add and the refresh.
     *
     * @param Cart $cart Current cart instance.
     * @return array<string,mixed>
     */
    private function cart_state( Cart $cart ): array {
        $subtotal       = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $tax_total      = (float) $cart->get_tax_total();
        $discount_total = (float) $cart->get_discount_total();
        $total          = (float) $cart->get_total();

        $allowed_price_html = array(
            'span' => array( 'class' => true ),
            'bdi'  => array(),
            'sup'  => array(),
            'sub'  => array(),
            'small' => array( 'class' => true ),
        );

        $sanitize_price = static function ( string $html ) use ( $allowed_price_html ): string {
            return wp_kses( $html, $allowed_price_html );
        };

        $line_totals = array();
        foreach ( $cart->get_items() as $item_key => $cart_item ) {
            $key_str = (string) $item_key;
            if ( ! preg_match( '/^[a-f0-9]{32,128}$/', $key_str ) ) {
                continue;
            }
            $line_totals[ $key_str ] = $sanitize_price( tejcart_price( (float) $cart_item->get_line_total() ) );
        }

        $discount_html = '';
        if ( $discount_total > 0 ) {
            $discount_html = '−' . $sanitize_price( tejcart_price( $discount_total ) );
        }

        $removed_coupons = array();
        if ( method_exists( $cart, 'get_removed_coupons' ) ) {
            foreach ( $cart->get_removed_coupons() as $entry ) {
                $code   = isset( $entry['code'] )   ? sanitize_text_field( (string) $entry['code'] )   : '';
                $reason = isset( $entry['reason'] ) ? sanitize_text_field( (string) $entry['reason'] ) : '';
                if ( '' === $code ) {
                    continue;
                }
                $removed_coupons[] = array(
                    'code'    => $code,
                    'reason'  => $reason,
                    'message' => sprintf(
                        /* translators: 1: coupon code, 2: reason */
                        __( 'Coupon "%1$s" was removed: %2$s', 'tejcart' ),
                        strtoupper( $code ),
                        $reason
                    ),
                );
            }
        }

        $pending_notices = method_exists( $cart, 'get_pending_notices' )
            ? $cart->get_pending_notices()
            : array();

        $notices = array();
        foreach ( $pending_notices as $entry ) {
            $message = isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
            $type    = isset( $entry['type'] )    ? sanitize_key( (string) $entry['type'] )           : 'info';
            if ( '' === $message ) {
                continue;
            }
            if ( ! in_array( $type, array( 'info', 'success', 'warning', 'error' ), true ) ) {
                $type = 'info';
            }
            $notices[] = array( 'message' => $message, 'type' => $type );
        }

        $applied_coupons = array();
        if ( method_exists( $cart, 'get_coupons' ) ) {
            foreach ( (array) $cart->get_coupons() as $coupon_code => $_coupon ) {
                $code = sanitize_text_field( (string) $coupon_code );
                if ( '' !== $code ) {
                    $applied_coupons[] = $code;
                }
            }
        }

        // Pre-fix this read filter `tejcart_free_shipping_threshold`
        // (default 0) while Cart_Calculator::calculate_shipping() uses
        // option `tejcart_shipping_free_threshold`. With the wrong
        // source the progress widget never rendered on a default
        // install (filter returned 0). Read the canonical option (same
        // source as the calculator), THEN apply the filter so
        // extensions can still override. Audit H-5.
        $free_ship_threshold = (float) get_option( 'tejcart_shipping_free_threshold', 0 );
        $free_ship_threshold = (float) apply_filters( 'tejcart_free_shipping_threshold', $free_ship_threshold );
        $free_ship_payload   = null;
        if ( $free_ship_threshold > 0 ) {
            // Match Cart_Calculator::calculate_shipping(): the eligibility amount
            // is the post-discount subtotal — never the grand total, which already
            // includes the shipping or tax we're trying to discount.
            //
            // F-CCM-005: use Money VO arithmetic so 3-decimal currencies (KWD/BHD/OMR)
            // and floating-point boundary amounts don't produce a spurious negative
            // $remaining or incorrect progress percentage.
            $cart_currency       = (string) get_option( 'tejcart_currency', 'USD' );
            $threshold_money     = \TejCart\Money\Money::from_decimal_string( (string) $free_ship_threshold, $cart_currency );
            $eligible_money      = $cart->get_subtotal_money()->subtract( $cart->get_discount_total_money() );
            if ( $eligible_money->as_minor_units() < 0 ) {
                $eligible_money = \TejCart\Money\Money::zero( $cart_currency );
            }
            $remaining_money     = $threshold_money->subtract( $eligible_money );
            if ( $remaining_money->as_minor_units() < 0 ) {
                $remaining_money = \TejCart\Money\Money::zero( $cart_currency );
            }
            $free_ship_remaining = (float) $remaining_money->as_decimal_string();
            $threshold_minor     = $threshold_money->as_minor_units();
            $free_ship_percent   = $threshold_minor > 0
                ? min( 100.0, ( $eligible_money->as_minor_units() / $threshold_minor ) * 100 )
                : 100.0;
            $free_ship_unlocked  = $remaining_money->as_minor_units() <= 0;
            $free_ship_payload   = array(
                'enabled'       => true,
                'unlocked'      => $free_ship_unlocked,
                'percent'       => $free_ship_percent,
                'remaining'     => $free_ship_remaining,
                'message_html'  => $free_ship_unlocked
                    ? wp_kses_post( __( "You've unlocked free shipping!", 'tejcart' ) )
                    : wp_kses(
                        sprintf(
                            /* translators: %s: formatted amount remaining */
                            __( 'Add <strong>%s</strong> more for free shipping', 'tejcart' ),
                            $sanitize_price( tejcart_price( $free_ship_remaining ) )
                        ),
                        $allowed_price_html + array( 'strong' => array() )
                    ),
            );
        }

        return array(
            'cart_count'      => (int) $cart->get_item_count(),
            'cart_empty'      => $cart->is_empty(),
            'cart_subtotal'   => $subtotal,
            'cart_total'      => $total,
            'cart_currency'   => (string) get_option( 'tejcart_currency', 'USD' ),
            'subtotal_html'   => $sanitize_price( tejcart_price( $subtotal ) ),
            'shipping_html'   => $shipping_total > 0
                ? $sanitize_price( tejcart_price( $shipping_total ) )
                : esc_html__( 'Calculated at checkout', 'tejcart' ),
            'tax_html'        => $tax_total > 0 ? $sanitize_price( tejcart_price( $tax_total ) ) : '',
            'discount_html'   => $discount_html,
            'total_html'      => $sanitize_price( tejcart_price( $total ) ),
            'has_tax'         => $tax_total > 0,
            'has_discount'    => $discount_total > 0,
            'savings_html'    => $discount_total > 0 ? $sanitize_price( tejcart_price( $discount_total ) ) : '',
            'line_totals'     => $line_totals,
            'drawer_html'     => $this->render_drawer_fragment( $cart ),
            'removed_coupons' => $removed_coupons,
            'applied_coupons' => $applied_coupons,
            'notices'         => $notices,
            'free_shipping'   => $free_ship_payload,
        );
    }

    /**
     * Render the cart drawer template into a string and return it so
     * the AJAX response can ship the latest markup alongside the
     * numeric cart state. Wrapped in try/catch so a broken template
     * can't crash an otherwise successful add-to-cart — the JS will
     * simply not swap in new markup and the drawer stays stale until
     * the next full page load.
     *
     * @param Cart $cart Current cart instance.
     * @return string
     */
    private function render_drawer_fragment( Cart $cart ): string {
        try {
            ob_start();
            tejcart_get_template( 'cart/cart-drawer.php', array( 'cart' => $cart ) );
            return (string) ob_get_clean();
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Cart drawer fragment render failed: ' . $e->getMessage(), 'error' );
            }
            return '';
        }
    }
}
