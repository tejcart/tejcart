<?php
/**
 * Main Cart Manager
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Coupon\Coupon;

/**
 * Main shopping cart manager.
 *
 * Coordinates cart items, session persistence, coupon handling,
 * and total calculations through the Cart_Calculator.
 */
class Cart {
    /**
     * Cart items indexed by cart item key.
     *
     * @var Cart_Item[]
     */
    private $items = array();

    /**
     * Session handler for cart persistence.
     *
     * @var Cart_Session
     */
    private $session;

    /**
     * Totals calculator.
     *
     * @var Cart_Calculator
     */
    private $calculator;

    /**
     * Applied coupons.
     *
     * Each coupon is an associative array with at least 'code', 'discount_type', and 'amount'.
     *
     * @var array
     */
    private $coupons = array();

    /**
     * Cached totals from the last calculation.
     *
     * @var array|null
     */
    private $totals = null;

    /**
     * Constructor.
     *
     * Initializes the session and calculator, then loads any
     * previously persisted cart data from the session.
     */
    public function __construct() {
        $this->session    = new Cart_Session();
        $this->calculator = new Cart_Calculator();

        $this->load_from_session();
    }

    /**
     * Add a product to the cart.
     *
     * @param int   $product_id   Product ID to add.
     * @param int   $quantity     Quantity to add (minimum 1).
     * @param array $data         Optional extra data (e.g. variation attributes).
     * @param int   $variation_id Variation ID when adding a child of a variable product, 0 otherwise.
     * @return string|\WP_Error|false The cart item key on success, WP_Error if rate-limited, false on validation failure.
     */
    public function add( $product_id, $quantity = 1, $data = array(), $variation_id = 0 ) {
        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();

        // Audit #4 / 02 H-3 — bucket the per-session ceiling on
        // (IP, user|session) so a single noisy buyer behind a shared
        // NAT (school, office, mobile carrier, flash-sale traffic
        // hitting the same egress IP) cannot lock out every other
        // legitimate buyer at the edge IP. A coarser per-IP ceiling
        // still catches botnets. Pattern mirrors `nonce_refresh` in
        // Cart_Ajax.php so the two cart-mutation gates stay aligned.
        $user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $session_id = '';
        if ( 0 === $user_id && function_exists( 'wp_get_session_token' ) ) {
            $session_id = (string) wp_get_session_token();
        }
        if ( '' === $session_id && isset( $_COOKIE['tejcart_session'] ) ) {
            $session_id = sanitize_text_field( wp_unslash( (string) $_COOKIE['tejcart_session'] ) );
        }
        $identity   = $user_id > 0 ? 'u' . $user_id : ( '' !== $session_id ? 's' . substr( hash( 'sha256', $session_id ), 0, 16 ) : 'anon' );
        $identifier = $ip . '|' . $identity;

        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'add_to_cart', $identifier, 30, 60 ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again shortly.', 'tejcart' ) );
        }

        // Global per-IP ceiling — catches botnets / scripted abuse
        // without DOS-ing legitimate buyers behind a shared NAT. The
        // ceiling is intentionally an order of magnitude above the
        // per-session bucket; filterable for sites with unusual
        // traffic shapes (e.g. concentrated B2B catalogues).
        $global_limit = (int) apply_filters( 'tejcart_cart_add_global_ip_limit', 500 );
        if ( $global_limit > 0
            && \TejCart\Security\Rate_Limiter::check_and_record( 'add_to_cart_ip', $ip, $global_limit, 60 ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again shortly.', 'tejcart' ) );
        }

        // L-2: cap distinct line count. Adding *to* an existing line still
        // works (the bounds_key collision below merges quantities). This
        // only blocks unbounded growth in distinct lines, which is what an
        // attacker (or runaway script) does to bloat the session payload.
        $max_lines = (int) apply_filters( 'tejcart_cart_max_line_count', 100 );
        if ( $max_lines > 0 && count( $this->items ) >= $max_lines ) {
            $bounds_key = hash( 'sha256', absint( $product_id ) . wp_json_encode( $data ) );
            if ( ! isset( $this->items[ $bounds_key ] ) ) {
                return new \WP_Error(
                    'cart_too_large',
                    sprintf(
                        /* translators: %d: maximum line count */
                        __( 'A cart can contain at most %d distinct items.', 'tejcart' ),
                        $max_lines
                    )
                );
            }
        }

        $parent_id    = absint( $product_id );
        $variation_id = absint( $variation_id );
        $quantity     = max( 1, (int) $quantity );

        $parent_product = \TejCart\Product\Product_Factory::get_product( $parent_id );

        if ( ! $parent_product ) {
            return new \WP_Error( 'product_not_found', __( 'This product could not be found.', 'tejcart' ) );
        }

        if ( $parent_product instanceof \TejCart\Product\Product_Types\Variable_Product ) {
            if ( ! $variation_id ) {
                return new \WP_Error( 'variation_required', __( 'Please choose product options before adding to cart.', 'tejcart' ) );
            }

            $variation = $parent_product->get_variation( $variation_id );
            if ( ! $variation ) {
                return new \WP_Error( 'invalid_variation', __( 'The selected variation is not available.', 'tejcart' ) );
            }

            $product           = $variation;
            $purchasable_id    = (int) $variation->get_id();
            $data['parent_id'] = $parent_id;
        } else {
            $product        = $parent_product;
            $purchasable_id = $parent_id;
            // Belt-and-braces (M-2): the cart-AJAX layer already strips
            // `parent_id` from caller data, but Cart::add is also reached
            // from non-AJAX paths (CLI, REST, integration tests). A
            // non-variation line must never carry parent_id, otherwise
            // Cart_Item::is_variation() reports true and the order line
            // ships with a forged variation_id.
            unset( $data['parent_id'] );
        }

        if ( ! $product->is_purchasable() ) {
            return new \WP_Error( 'not_purchasable', __( 'This product cannot be purchased.', 'tejcart' ) );
        }

        $product_id = $purchasable_id;

        if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
            foreach ( $this->items as $existing_item ) {
                if ( method_exists( $existing_item, 'get_product_id' )
                    && (int) $existing_item->get_product_id() === (int) $product_id ) {
                    return new \WP_Error(
                        'sold_individually',
                        sprintf(
                            /* translators: %s: product name */
                            __( 'You can only have one %s in your cart.', 'tejcart' ),
                            $product->get_name()
                        )
                    );
                }
            }

            $quantity = 1;
        }

        $existing_qty_for_bounds = 0;
        $bounds_key              = hash( 'sha256', $product_id . wp_json_encode( $data ) );
        if ( isset( $this->items[ $bounds_key ] ) ) {
            $existing_qty_for_bounds = (int) $this->items[ $bounds_key ]->get_quantity();
        }
        $resulting_qty = $existing_qty_for_bounds + (int) $quantity;

        if ( method_exists( $product, 'get_min_purchase_quantity' ) ) {
            $min_qty = (int) $product->get_min_purchase_quantity();
            if ( $min_qty > 1 && $resulting_qty < $min_qty ) {
                return new \WP_Error(
                    'min_purchase_quantity',
                    sprintf(
                        /* translators: 1: product name, 2: minimum quantity */
                        __( '%1$s must be ordered in quantities of at least %2$d.', 'tejcart' ),
                        $product->get_name(),
                        $min_qty
                    )
                );
            }
        }

        $effective_max = 0;
        if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
            $effective_max = (int) $product->get_max_purchase_quantity();
        }
        if ( $product !== $parent_product && method_exists( $parent_product, 'get_max_purchase_quantity' ) ) {
            $parent_max = (int) $parent_product->get_max_purchase_quantity();
            if ( $parent_max > 0 && ( 0 === $effective_max || $parent_max < $effective_max ) ) {
                $effective_max = $parent_max;
            }
        }
        if ( $effective_max > 0 && $resulting_qty > $effective_max ) {
            return new \WP_Error(
                'max_purchase_quantity',
                sprintf(
                    /* translators: 1: product name, 2: maximum quantity */
                    __( 'You can order at most %2$d of %1$s per order.', 'tejcart' ),
                    $product->get_name(),
                    $effective_max
                )
            );
        }

        if ( $product->get_manage_stock() ) {
            $stock_qty = (int) $product->get_stock_quantity();

            // Mirror update_quantity(): net out units already reserved by OTHER
            // shoppers so the add path can't oversell stock that is held in
            // another active cart. Previously add() compared against raw stock
            // while update_quantity() subtracted reservations — the two
            // diverged, letting add() admit quantities update_quantity() would
            // reject (audit #15).
            if ( class_exists( '\\TejCart\\Cart\\Stock_Reservation' ) ) {
                $reservations = new Stock_Reservation();
                $stock_qty    = max( 0, $stock_qty - $reservations->reserved_by_others( (int) $product_id ) );
            }

            $cart_item_key_check = hash( 'sha256', $product_id . wp_json_encode( $data ) );
            $existing_qty       = 0;

            if ( isset( $this->items[ $cart_item_key_check ] ) ) {
                $existing_qty = $this->items[ $cart_item_key_check ]->get_quantity();
            }

            $total_qty = $existing_qty + $quantity;

            if ( $stock_qty < $total_qty ) {
                $allows_backorder = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();

                if ( ! $allows_backorder ) {
                    return new \WP_Error(
                        'insufficient_stock',
                        sprintf(
                            /* translators: 1: product name, 2: available stock */
                            __( '%1$s only has %2$d items in stock.', 'tejcart' ),
                            $product->get_name(),
                            $stock_qty
                        )
                    );
                }

                if ( method_exists( $product, 'backorders_require_notification' ) && $product->backorders_require_notification() ) {
                    /**
                     * Fires when an item exceeding stock is added under a
                     * notify-backorder policy. Themes/notices can listen here.
                     *
                     * @param int    $product_id Product ID.
                     * @param int    $shortfall  Units beyond available stock.
                     * @param object $product    Product instance.
                     */
                    do_action( 'tejcart_cart_backorder_notice', $product_id, $total_qty - max( 0, $stock_qty ), $product );
                }
            }
        }

        /**
         * Pre-add gate (bot/abuse filter). Listeners may return a
         * WP_Error to abort the add. Bot_Gate hooks this for residential-
         * proxy mitigation; before the hook was fired the protection was
         * silently inert. See F-C1 / issue #923.
         *
         * @param true|\WP_Error $proceed    Pass-through, or WP_Error to block.
         * @param int            $product_id Product ID.
         * @param int            $quantity   Quantity.
         * @param array          $data       Extra data.
         */
        $gate = apply_filters( 'tejcart_cart_pre_add', true, $product_id, $quantity, $data );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }

        /**
         * Fires before an item is added to the cart.
         *
         * @param int   $product_id Product ID.
         * @param int   $quantity   Quantity.
         * @param array $data       Extra data.
         */
        do_action( 'tejcart_before_add_to_cart', $product_id, $quantity, $data );

        /**
         * Filters whether the add-to-cart request is valid.
         *
         * Listeners may return:
         *   - true (default) to allow the add;
         *   - false to block with the generic "could not be added" message;
         *   - a WP_Error to block AND surface a specific reason to the
         *     buyer. Preferred — the AJAX layer relays the WP_Error
         *     message verbatim so the customer sees why they were
         *     blocked instead of a generic notice.
         *
         * @param true|false|\WP_Error $valid      Whether the request is valid. Default true.
         * @param int                  $product_id Product ID.
         * @param int                  $quantity   Quantity.
         * @param array                $data       Extra data.
         */
        $valid = apply_filters( 'tejcart_add_to_cart_validation', true, $product_id, $quantity, $data );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( ! $valid ) {
            return false;
        }

        $cart_item_key = hash( 'sha256', $product_id . wp_json_encode( $data ) );

        /**
         * Filters the extra data stored with the cart item.
         *
         * @param array  $data           Extra item data.
         * @param int    $product_id     Product ID.
         * @param string $cart_item_key  The generated cart item key.
         */
        $data = (array) apply_filters( 'tejcart_cart_item_data', $data, $product_id, $cart_item_key );

        // Snapshot the price at the moment the customer agrees
        // to it. Without this the line item silently re-prices itself if
        // the merchant tweaks the product row between add and checkout,
        // and the buyer ends up charged something other than the figure
        // they saw in the cart drawer. Cart_Item::get_price() prefers
        // this snapshot when present.
        //
        // ALWAYS overwrite the snapshot from the live product price.
        // The previous `if ( ! isset( … ) )` gate let an attacker pre-seed
        // `data[_price_at_add]` via the public AJAX/REST endpoints and pay
        // an arbitrary unit price (CVE-class price manipulation). Treating
        // every `_*` key in `$data` as plugin-internal is enforced here as
        // the last line of defence — the AJAX and REST entry points strip
        // them too, but Cart::add() is reached by Tier-2 and sibling-plugin
        // callers we cannot vet ahead of time.
        $snapshot              = $product ? (float) $product->get_price() : 0.0;
        $data['_price_at_add'] = $snapshot < 0 ? 0.0 : $snapshot;

        // F-M16 / #950: reject a zero-price snapshot unless the
        // merchant explicitly declares the product as free. Without
        // this guard a misconfigured product (price field left blank)
        // silently lands in cart at $0 and the buyer commits a $0
        // order. The filter lets merchants flag "$0 is intentional"
        // (e.g. free samples, lead-gen tripwires).
        if ( $snapshot <= 0.0 ) {
            /**
             * Whether a product with a zero/missing price is allowed
             * in cart. Default false — opt-in for free-product flows.
             *
             * @param bool   $allowed  Default false.
             * @param object $product  Product instance.
             * @param array  $data     Cart-item data array.
             */
            $is_free = (bool) apply_filters( 'tejcart_product_is_free', false, $product, $data );
            if ( ! $is_free ) {
                return new \WP_Error(
                    'tejcart_zero_price',
                    __( 'This product has no price set and cannot be added to the cart. Please contact the store owner.', 'tejcart' )
                );
            }
        }

        if ( isset( $this->items[ $cart_item_key ] ) ) {
            $existing_qty = $this->items[ $cart_item_key ]->get_quantity();
            $this->items[ $cart_item_key ]->set_quantity( $existing_qty + $quantity );
        } else {
            $this->items[ $cart_item_key ] = new Cart_Item( $cart_item_key, $product_id, $quantity, $data );
        }

        $this->totals = null;

        /**
         * Fires when an item has been added to the cart.
         *
         * @param string    $cart_item_key The cart item key.
         * @param int       $product_id    Product ID.
         * @param int       $quantity      Quantity added.
         * @param array     $data          Extra data.
         * @param Cart      $cart          The cart instance.
         */
        do_action( 'tejcart_add_to_cart', $cart_item_key, $product_id, $quantity, $data, $this );

        /**
         * Fires after an item has been added to the cart.
         *
         * @param string $cart_item_key The cart item key.
         * @param Cart   $cart          The cart instance.
         */
        do_action( 'tejcart_after_add_to_cart', $cart_item_key, $this );

        $this->maybe_auto_apply_coupons();

        $this->save();
        $this->session->force_save();

        return $cart_item_key;
    }

    /**
     * Apply any coupons configured to auto-apply, silently skipping any
     * that are invalid for the current cart (so an admin can mark a
     * promo as auto-apply without it ever erroring at the customer).
     *
     * @return void
     */
    public function maybe_auto_apply_coupons() {
        $codes = (array) get_option( 'tejcart_auto_apply_coupons', array() );
        if ( empty( $codes ) ) {
            return;
        }

        foreach ( $codes as $code ) {
            $code = strtolower( trim( (string) $code ) );
            if ( '' === $code || isset( $this->coupons[ $code ] ) ) {
                continue;
            }
            $result = $this->apply_coupon( $code );
            if ( is_wp_error( $result ) ) {
                continue;
            }
        }
    }

    /**
     * Remove an item from the cart.
     *
     * @param string $cart_item_key The key of the item to remove.
     * @return bool True if removed, false if item not found.
     */
    public function remove( $cart_item_key ) {
        if ( ! isset( $this->items[ $cart_item_key ] ) ) {
            return false;
        }

        $item = $this->items[ $cart_item_key ];

        /**
         * Fires before a cart item is removed.
         *
         * @param string    $cart_item_key The cart item key.
         * @param Cart_Item $item          The item being removed.
         * @param Cart      $cart          The cart instance.
         */
        do_action( 'tejcart_before_remove_cart_item', $cart_item_key, $item, $this );

        unset( $this->items[ $cart_item_key ] );
        $this->totals = null;

        /**
         * Fires after the cart has been updated.
         *
         * @param Cart $cart The cart instance.
         */
        do_action( 'tejcart_cart_updated', $this );

        $this->save();
        $this->session->force_save();

        return true;
    }

    /**
     * Hard cap on a single cart line. Higher than any realistic order but low
     * enough to refuse obvious abuse (e.g. quantity = INT_MAX overflow probes).
     */
    const MAX_LINE_QUANTITY = 9999;

    /**
     * Update the quantity of a cart item.
     *
     * If quantity is zero or less, the item is removed. Otherwise the new
     * quantity is bounded between 1 and MAX_LINE_QUANTITY and re-checked
     * against current stock (including reservations from other shoppers)
     * before being persisted, so a customer can't bump a line past the
     * available inventory by intercepting an update request.
     *
     * @param string $cart_item_key The cart item key.
     * @param int    $quantity      New quantity.
     * @return true|false|\WP_Error True on success, false if item not found,
     *                              WP_Error when the requested quantity exceeds
     *                              what's available.
     */
    public function update_quantity( $cart_item_key, $quantity ) {
        if ( ! isset( $this->items[ $cart_item_key ] ) ) {
            return false;
        }

        $quantity = (int) $quantity;

        if ( $quantity <= 0 ) {
            return $this->remove( $cart_item_key );
        }

        if ( $quantity > self::MAX_LINE_QUANTITY ) {
            return new \WP_Error(
                'quantity_too_large',
                sprintf(
                    /* translators: %d: maximum allowed line quantity */
                    __( 'Quantity is capped at %d per line.', 'tejcart' ),
                    self::MAX_LINE_QUANTITY
                )
            );
        }

        $item    = $this->items[ $cart_item_key ];
        $product = $item->get_product();

        if ( $product && method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
            $quantity = 1;
        }

        if ( $product && method_exists( $product, 'get_min_purchase_quantity' ) ) {
            $min_qty = (int) $product->get_min_purchase_quantity();
            if ( $min_qty > 1 && $quantity < $min_qty ) {
                return new \WP_Error(
                    'min_purchase_quantity',
                    sprintf(
                        /* translators: 1: product name, 2: minimum quantity */
                        __( '%1$s must be ordered in quantities of at least %2$d.', 'tejcart' ),
                        $product->get_name(),
                        $min_qty
                    )
                );
            }
        }
        if ( $product && method_exists( $product, 'get_max_purchase_quantity' ) ) {
            $effective_max = (int) $product->get_max_purchase_quantity();

            if ( $item->is_variation() ) {
                $parent_product = \TejCart\Product\Product_Factory::get_product( $item->get_parent_product_id() );
                if ( $parent_product && method_exists( $parent_product, 'get_max_purchase_quantity' ) ) {
                    $parent_max = (int) $parent_product->get_max_purchase_quantity();
                    if ( $parent_max > 0 && ( 0 === $effective_max || $parent_max < $effective_max ) ) {
                        $effective_max = $parent_max;
                    }
                }
            }

            if ( $effective_max > 0 && $quantity > $effective_max ) {
                return new \WP_Error(
                    'max_purchase_quantity',
                    sprintf(
                        /* translators: 1: product name, 2: maximum quantity */
                        __( 'You can order at most %2$d of %1$s per order.', 'tejcart' ),
                        $product->get_name(),
                        $effective_max
                    )
                );
            }
        }

        if ( $product && $product->get_manage_stock() ) {
            $stock = (int) $product->get_stock_quantity();

            if ( class_exists( '\\TejCart\\Cart\\Stock_Reservation' ) ) {
                $reservations = new Stock_Reservation();
                $stock        = max( 0, $stock - $reservations->reserved_by_others( (int) $item->get_product_id() ) );
            }

            $allows_backorder = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();

            if ( ! $allows_backorder && $quantity > $stock ) {
                return new \WP_Error(
                    'insufficient_stock',
                    sprintf(
                        /* translators: 1: product name, 2: available stock */
                        __( '%1$s only has %2$d items in stock.', 'tejcart' ),
                        $product->get_name(),
                        $stock
                    )
                );
            }
        }

        $previous_quantity = (int) $item->get_quantity();
        $item->set_quantity( $quantity );
        $this->totals = null;

        /**
         * Fires when a cart line's quantity has been changed in place.
         *
         * Stock_Reservation listens for this so the held units track the
         * cart, otherwise a customer who sets 10 then drops to 2 would keep
         * 10 units locked away from other shoppers until checkout.
         *
         * @param string $cart_item_key     Cart line key.
         * @param int    $new_quantity      New quantity.
         * @param int    $previous_quantity Previous quantity.
         * @param Cart   $cart              Cart instance.
         */
        do_action( 'tejcart_cart_item_quantity_updated', $cart_item_key, $quantity, $previous_quantity, $this );

        /** This action is documented in Cart::remove */
        do_action( 'tejcart_cart_updated', $this );

        $this->save();
        $this->session->force_save();

        return true;
    }

    /**
     * Get all cart items.
     *
     * @return Cart_Item[]
     */
    public function get_items() {
        return $this->items;
    }

    /**
     * Get a single cart item by key.
     *
     * @param string $key Cart item key.
     * @return Cart_Item|null
     */
    public function get_item( $key ) {
        return isset( $this->items[ $key ] ) ? $this->items[ $key ] : null;
    }

    /**
     * Compute a deterministic hash of the cart's pricing state.
     *
     * The hash covers every line item (product id, qty, unit price,
     * line total) plus the recalculated subtotal/discount/shipping/
     * tax/total and applied coupon codes. Any silent change between
     * cart render and checkout submission — a price update, a coupon
     * expiry, an injected line — will produce a different digest.
     *
     * The shared secret (wp_salt) prevents the client from forging
     * a hash that matches a tampered payload.
     *
     * @return string 64-char hex sha256.
     */
    public function get_totals_hash() {
        // Sign over the canonical raw inputs that DRIVE the totals, not
        // the computed totals themselves. The previous payload included
        // subtotal / discount / shipping / tax / total — but
        // Cart_Calculator::calculate_tax mutates $totals['subtotal']
        // and $totals['discount_total'] in place when
        // `prices_include_tax=yes` is on (strips the tax to convert
        // gross→net mid-pipeline). The server-side hash was therefore
        // over post-mutation totals while the client-side hash —
        // computed off the values the buyer saw rendered — was over
        // whatever state existed at render time. Result was sporadic
        // "cart totals mismatch" rejections at checkout submit for
        // every tax-inclusive merchant (most EU/UK/AU/NZ/IN merchants).
        //
        // Items + their quantities + per-line price-at-add + applied
        // coupons + currency are the canonical inputs. The same shared
        // secret (wp_salt) prevents a client from forging a hash that
        // matches a tampered payload — tampering with any input field
        // produces a different digest. Server-side re-derivation of
        // the totals from these inputs is performed by
        // Cart::recalculate() and Checkout::validate_cart_totals; the
        // hash's job is to detect tampering of the inputs, not to
        // certify the computation.
        $lines = array();
        foreach ( $this->get_items() as $item ) {
            $lines[] = array(
                'pid'   => (int) $item->get_product_id(),
                'qty'   => (int) $item->get_quantity(),
                'price' => number_format( (float) $item->get_price(), 4, '.', '' ),
                // F-CCM-015: use the integer minor-unit value from get_line_total_money()
                // so a float-rounding difference between requests doesn't produce a
                // different hash for the same logical cart, causing spurious
                // cart_totals_mismatch rejections at checkout.
                'line'  => (string) $item->get_line_total_money()->as_minor_units(),
            );
        }

        usort(
            $lines,
            static function ( $a, $b ) {
                return $a['pid'] <=> $b['pid'];
            }
        );

        $payload = array(
            'items'    => $lines,
            'coupons'  => array_keys( method_exists( $this, 'get_coupons' ) ? (array) $this->get_coupons() : array() ),
            'currency' => function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : '',
        );

        sort( $payload['coupons'] );

        return hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'nonce' ) );
    }

    /**
     * Get the cart grand total.
     *
     * @return float
     */
    public function get_total() {
        $major = $this->calculated_total_as_float( 'total' );

        /**
         * Filters the cart grand total.
         *
         * @param float $total The calculated total.
         * @param Cart  $cart  The cart instance.
         */
        return (float) apply_filters( 'tejcart_cart_total', $major, $this );
    }

    /**
     * Get the cart subtotal (sum of all line totals before discounts, shipping, and tax).
     *
     * @return float
     */
    public function get_subtotal() {
        return $this->calculated_total_as_float( 'subtotal' );
    }

    /**
     * Get the total discount amount from applied coupons.
     *
     * @return float
     */
    public function get_discount_total() {
        return $this->calculated_total_as_float( 'discount_total' );
    }

    /**
     * Get the shipping total.
     *
     * @return float
     */
    public function get_shipping_total() {
        return $this->calculated_total_as_float( 'shipping_total' );
    }

    /**
     * Get the tax total.
     *
     * @return float
     */
    public function get_tax_total() {
        return $this->calculated_total_as_float( 'tax_total' );
    }

    /**
     * Get the total of cart-level fees (gift wrap, handling, …) in the
     * active currency, major units. Folded into {@see self::get_total()}.
     *
     * @return float
     */
    public function get_fees_total() {
        return $this->calculated_total_as_float( 'fees_total' );
    }

    /**
     * Get the itemised cart-level fee rows for display, each
     * {id, label, amount (minor units, active currency), taxable}.
     *
     * @return array<int, array{id:string,label:string,amount:int,taxable:bool}>
     */
    public function get_fees(): array {
        $this->get_calculated_totals();
        return $this->calculator->get_fee_lines();
    }

    /**
     * Provenance of the cart's tax figure — which engine actually priced it.
     *
     * Forces a totals pass first (so the calculator has run) then reads the
     * source recorded during {@see Cart_Calculator::calculate_tax()}. See
     * {@see Cart_Calculator::$tax_source} for the vocabulary
     * (`live:<id>`, `manual`, `manual_fallback`, `disabled`, `filter`).
     * Persisted onto the order at checkout for tax reconciliation.
     *
     * @return string
     */
    public function get_tax_source(): string {
        $this->get_calculated_totals();
        return $this->calculator->get_tax_source();
    }

    /**
     * Read one slot of the calculator's totals projection as a major-
     * unit float. Cart_Calculator::calculate() returns a currency-aware
     * float projection of its internal integer minor-units storage, so
     * the value is already a float by this point — this helper just
     * adds the type guard and the default-to-zero fallback.
     *
     * @param string $slot subtotal|discount_total|shipping_total|tax_total|total
     * @return float
     */
    private function calculated_total_as_float( string $slot ): float {
        $totals = $this->get_calculated_totals();
        return (float) ( $totals[ $slot ] ?? 0.0 );
    }

    /**
     * Resolve the active cart currency, falling back to USD if the
     * `tejcart_get_currency()` helper is unavailable (early-boot edge
     * case) or returns an empty value.
     *
     * @return string ISO 4217 code, uppercased.
     */
    private function resolve_currency(): string {
        $currency = function_exists( 'tejcart_get_currency' ) ? (string) tejcart_get_currency() : '';
        $currency = strtoupper( trim( $currency ) );
        if ( '' === $currency || ! \TejCart\Money\Currency::is_valid_shape( $currency ) ) {
            return 'USD';
        }
        return $currency;
    }

    /**
     * Build a Money value from a float total in the cart's currency.
     *
     * Bridges the legacy float `$totals` array to the Money domain
     * without losing precision: floats are converted to integer minor
     * units via {@see \TejCart\Money\Currency::to_minor_units()} (banker's
     * rounding, currency-aware multiplier) before being wrapped in a
     * Money. Three-decimal currencies (KWD, BHD, OMR) survive this trip
     * because the multiplier is taken from {@see \TejCart\Money\Currency},
     * not hard-coded to 100.
     *
     * Used as the fallback path when the calculator hasn't yet exposed
     * a Money mirror for the requested slot — the preferred path reads
     * directly from {@see Cart_Calculator::get_money_totals()} so the
     * exact integer minor units flow through with zero float hops.
     *
     * @param float|int|string $amount Total in major units.
     * @return \TejCart\Money\Money
     */
    private function to_money( $amount ): \TejCart\Money\Money {
        $currency = $this->resolve_currency();
        $minor    = \TejCart\Money\Currency::to_minor_units( (float) $amount, $currency );
        return \TejCart\Money\Money::from_minor_units( $minor, $currency );
    }

    /**
     * Read the Money mirror for a calculator slot, or null if the
     * calculator has not yet computed it (cart loaded but never
     * recalculated, or unit test bypassing the calculator entirely).
     *
     * @param string $slot One of subtotal|discount|shipping|tax|total.
     * @return \TejCart\Money\Money|null
     */
    private function money_mirror( string $slot ): ?\TejCart\Money\Money {
        $this->get_calculated_totals();
        if ( ! $this->calculator instanceof Cart_Calculator ) {
            return null;
        }
        $mirrors = $this->calculator->get_money_totals();
        $value   = $mirrors[ $slot ] ?? null;
        return $value instanceof \TejCart\Money\Money ? $value : null;
    }

    /**
     * Money-typed grand total. Preferred over {@see self::get_total()}
     * for new code — see `docs/money-representation.md`.
     *
     * Reads the integer-minor-units value directly from the calculator's
     * Money mirror when available so no float hop happens between
     * computation and consumption. Falls back to converting from the
     * float `$totals['total']` projection for the rare case that the
     * mirror is unavailable (e.g. a test that wrote $totals directly via
     * reflection).
     *
     * @since 1.4.0
     * @return \TejCart\Money\Money
     */
    public function get_total_money(): \TejCart\Money\Money {
        return $this->money_mirror( 'total' ) ?? $this->to_money( $this->get_total() );
    }

    /**
     * Money-typed subtotal. See {@see self::get_total_money()} for the
     * precision rationale.
     *
     * @since 1.4.0
     * @return \TejCart\Money\Money
     */
    public function get_subtotal_money(): \TejCart\Money\Money {
        return $this->money_mirror( 'subtotal' ) ?? $this->to_money( $this->get_subtotal() );
    }

    /**
     * Money-typed discount total.
     *
     * @since 1.4.0
     * @return \TejCart\Money\Money
     */
    public function get_discount_total_money(): \TejCart\Money\Money {
        return $this->money_mirror( 'discount' ) ?? $this->to_money( $this->get_discount_total() );
    }

    /**
     * Money-typed shipping total.
     *
     * @since 1.4.0
     * @return \TejCart\Money\Money
     */
    public function get_shipping_total_money(): \TejCart\Money\Money {
        return $this->money_mirror( 'shipping' ) ?? $this->to_money( $this->get_shipping_total() );
    }

    /**
     * Money-typed tax total.
     *
     * @since 1.4.0
     * @return \TejCart\Money\Money
     */
    public function get_tax_total_money(): \TejCart\Money\Money {
        return $this->money_mirror( 'tax' ) ?? $this->to_money( $this->get_tax_total() );
    }

    /**
     * Get the total number of items (sum of all quantities) in the cart.
     *
     * @return int
     */
    public function get_item_count() {
        $count = 0;

        foreach ( $this->items as $item ) {
            $count += $item->get_quantity();
        }

        return $count;
    }

    /**
     * Check if the cart is empty.
     *
     * @return bool
     */
    public function is_empty() {
        return empty( $this->items );
    }

    /**
     * Return the underlying session key, or '' when no session is active.
     *
     * Exposed for {@see \TejCart\Order\Order_Cart_Cleanup} so it can persist
     * the buyer's session reference on the order at create time and use it
     * to drain the persisted cart from an async webhook context.
     */
    public function get_session_key(): string {
        if ( ! $this->session || ! method_exists( $this->session, 'get_session_key' ) ) {
            return '';
        }
        return (string) $this->session->get_session_key();
    }

    /**
     * Read an arbitrary key from the cart session.
     *
     * Used by add-on features (Gift_Wrap, Save_For_Later) to persist
     * guest-facing state inside the existing cart session row rather
     * than requiring their own cookie or transient.
     *
     * @param string $key     Session data key.
     * @param mixed  $default Value returned when the key is absent.
     * @return mixed
     */
    public function get_session_data( string $key, $default = null ) {
        if ( ! $this->session ) {
            return $default;
        }
        return $this->session->get( $key, $default );
    }

    /**
     * Write an arbitrary key into the cart session.
     *
     * @param string $key   Session data key.
     * @param mixed  $value Value to store (must be serialisable).
     */
    public function set_session_data( string $key, $value ): void {
        if ( ! $this->session ) {
            return;
        }
        $this->session->set( $key, $value );
        $this->session->force_save();
    }

    /**
     * Empty the cart completely.
     *
     * Removes all items and coupons, clears the session data.
     */
    public function empty_cart() {
        $this->items   = array();
        $this->coupons = array();
        $this->totals  = null;

        /**
         * Fires after the cart has been emptied.
         *
         * @param Cart $cart The cart instance.
         */
        do_action( 'tejcart_cart_emptied', $this );

        $this->session->set( 'cart', array() );
        $this->session->set( 'coupons', array() );
        $this->session->set( '_tejcart_gift_wrap', '' );
        $this->session->set( '_tejcart_gift_message', '' );
        $this->session->set( '_tejcart_saved_for_later', array() );
        // Low: also drop stale notice / removed-coupon queues so an
        // emptied cart doesn't surface messages about items or coupons
        // that no longer exist.
        $this->session->set( 'removed_coupons', array() );
        $this->session->set( 'notices', array() );
        $this->session->force_save();
    }

    /**
     * Hard-delete the underlying session row and clear the cookie.
     *
     * Used by the post-payment cleanup so the persisted `wp_tejcart_sessions`
     * row does not linger for the 48h TTL after a successful checkout.
     * Falls back to {@see empty_cart()} when the session implementation does
     * not expose `destroy()` (custom session backends bound via DI).
     */
    public function destroy_session(): void {
        $this->items   = array();
        $this->coupons = array();
        $this->totals  = null;

        if ( $this->session && method_exists( $this->session, 'destroy' ) ) {
            $this->session->destroy();
            do_action( 'tejcart_cart_emptied', $this );
            return;
        }

        $this->empty_cart();
    }

    /**
     * Determine whether the cart needs shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        if ( 'yes' !== get_option( 'tejcart_enable_shipping', 'no' ) ) {
            return (bool) apply_filters( 'tejcart_cart_needs_shipping', false, $this );
        }

        $needs_shipping = false;

        foreach ( $this->items as $item ) {
            $product = $item->get_product();

            if ( $product && method_exists( $product, 'needs_shipping' ) && $product->needs_shipping() ) {
                $needs_shipping = true;
                break;
            }
        }

        /**
         * Filters whether the cart needs shipping.
         *
         * @param bool $needs_shipping Whether shipping is needed.
         * @param Cart $cart           The cart instance.
         */
        return (bool) apply_filters( 'tejcart_cart_needs_shipping', $needs_shipping, $this );
    }

    /**
     * Apply a coupon to the cart.
     *
     * Loads the coupon from the database, validates it (including per-user
     * usage limits based on the current customer email), and adds it to
     * the cart when valid.
     *
     * @param string $code Coupon code.
     * @return true|\WP_Error True if the coupon was applied, WP_Error on failure.
     */
    public function apply_coupon( $code ) {
        $code = sanitize_text_field( strtolower( trim( $code ) ) );

        if ( empty( $code ) ) {
            return new \WP_Error( 'empty_coupon', __( 'Please enter a coupon code.', 'tejcart' ) );
        }

        /**
         * Pre-apply gate (bot/abuse filter). Listeners may return a
         * WP_Error to abort. Bot_Gate hooks this to require a CAPTCHA
         * token after repeated invalid-coupon attempts. See F-C1 / #923.
         *
         * @param true|\WP_Error $proceed Pass-through, or WP_Error to block.
         * @param string         $code    Coupon code (lower-cased).
         */
        $gate = apply_filters( 'tejcart_apply_coupon_pre', true, $code );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }

        if ( isset( $this->coupons[ $code ] ) ) {
            return new \WP_Error( 'coupon_already_applied', __( 'This coupon has already been applied.', 'tejcart' ) );
        }

        $coupon_id = $this->resolve_coupon_id( $code );

        if ( $coupon_id <= 0 ) {
            return new \WP_Error( 'invalid_coupon', __( 'This coupon does not exist.', 'tejcart' ) );
        }

        $coupon = new Coupon( $coupon_id );

        $email = $this->get_customer_email();

        $valid = $coupon->is_valid( $email, $this->get_subtotal() );

        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( $coupon->is_individual_use() && ! empty( $this->coupons ) ) {
            return new \WP_Error(
                'coupon_individual_use',
                __( 'This coupon cannot be combined with other coupons.', 'tejcart' )
            );
        }

        foreach ( $this->coupons as $existing ) {
            if ( ! empty( $existing['individual_use'] ) ) {
                return new \WP_Error(
                    'coupon_individual_use_existing',
                    __( 'An existing coupon cannot be combined with this one.', 'tejcart' )
                );
            }
        }

        $type_map = array(
            'percentage'    => 'percent',
            'fixed'         => 'fixed_cart',
            'fixed_product' => 'fixed_product',
            'free_shipping' => 'free_shipping',
        );

        $coupon_data = array(
            'code'               => $coupon->get_code(),
            'discount_type'      => isset( $type_map[ $coupon->get_type() ] ) ? $type_map[ $coupon->get_type() ] : 'fixed_cart',
            'amount'             => $coupon->get_amount(),
            'coupon_id'          => $coupon->get_id(),
            'individual_use'     => $coupon->is_individual_use(),
            'exclude_sale_items' => $coupon->excludes_sale_items(),
        );

        /**
         * Filters the coupon data for validation.
         *
         * Return false to reject the coupon. Otherwise return an associative
         * array with at least 'code', 'discount_type', and 'amount'.
         *
         * @param array|false $coupon_data Coupon data array or false.
         * @param string      $code        The coupon code.
         * @param Cart        $cart        The cart instance.
         */
        $coupon_data = apply_filters( 'tejcart_validate_coupon', $coupon_data, $code, $this );

        if ( empty( $coupon_data ) || ! is_array( $coupon_data ) ) {
            return new \WP_Error( 'coupon_rejected', __( 'This coupon is not valid.', 'tejcart' ) );
        }

        $this->coupons[ $code ] = $coupon_data;
        $this->totals           = $this->calculator->calculate( $this );

        $this->save();
        $this->session->force_save();

        return true;
    }

    /**
     * Memoised `code → id` lookup for the coupon table.
     *
     * Cart hot paths (apply, revalidate, recalculate) all need the row id
     * for the same code repeatedly within a request; the underlying SELECT
     * hits a unique index so it's cheap, but issuing it 3-4 times per AJAX
     * call still adds up (audit 08 #24). Cache per-cart for the request.
     *
     * @var array<string,int>
     */
    private $coupon_code_to_id = array();

    /**
     * Resolve a coupon code to its database id, memoising the result
     * for the rest of the request.
     *
     * @param string $code Coupon code (case-sensitive, as stored).
     * @return int Row id or 0 when not found.
     */
    private function resolve_coupon_id( string $code ): int {
        if ( '' === $code ) {
            return 0;
        }
        if ( array_key_exists( $code, $this->coupon_code_to_id ) ) {
            return (int) $this->coupon_code_to_id[ $code ];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $id = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code )
        );
        $id = (int) $id;
        $this->coupon_code_to_id[ $code ] = $id;
        return $id;
    }

    /**
     * Get the current customer email from the session or logged-in user.
     *
     * @return string Customer email address, or empty string if unavailable.
     */
    private function get_customer_email(): string {
        // Low: the previous `method_exists( $this, 'get_customer' )` branch
        // was dead code — Cart has no get_customer() method, so it never
        // ran. Removed for clarity.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user && ! empty( $user->user_email ) ) {
                return sanitize_email( $user->user_email );
            }
        }

        return '';
    }

    /**
     * Remove a coupon from the cart.
     *
     * @param string $code Coupon code.
     * @return bool True if removed, false if coupon was not applied.
     */
    public function remove_coupon( $code ) {
        $code = sanitize_text_field( strtolower( trim( $code ) ) );

        if ( ! isset( $this->coupons[ $code ] ) ) {
            return false;
        }

        unset( $this->coupons[ $code ] );
        $this->totals = $this->calculator->calculate( $this );

        $this->save();
        $this->session->force_save();

        return true;
    }

    /**
     * Get all applied coupons.
     *
     * @return array
     */
    public function get_coupons() {
        return $this->coupons;
    }

    /**
     * Re-validate every applied coupon against a specific email address.
     *
     * Used at checkout time to enforce per-user usage limits and
     * allowed-email restrictions against the *posted* billing email,
     * since guest carts have no stored customer email until the
     * checkout form is submitted.
     *
     * Returns the first error encountered so the caller can abort the
     * transaction with a specific message. The coupon is not removed
     * here — the customer must explicitly drop it on the cart page.
     *
     * @param string $email Billing email to validate against.
     * @return true|\WP_Error True if all coupons remain valid, WP_Error on the first failure.
     */
    public function validate_coupons_against_email( $email ) {
        if ( empty( $this->coupons ) ) {
            return true;
        }

        $email = sanitize_email( (string) $email );

        foreach ( $this->coupons as $code => $coupon_data ) {
            $coupon_id = isset( $coupon_data['coupon_id'] ) ? (int) $coupon_data['coupon_id'] : 0;

            if ( ! $coupon_id ) {
                $coupon_id = $this->resolve_coupon_id( (string) $code );
                if ( $coupon_id <= 0 ) {
                    return new \WP_Error(
                        'invalid_coupon',
                        sprintf(
                            /* translators: %s: coupon code */
                            __( 'Coupon "%s" is no longer available.', 'tejcart' ),
                            $code
                        )
                    );
                }
            }

            $coupon = new Coupon( $coupon_id );
            $valid  = $coupon->is_valid( $email, $this->get_subtotal() );

            if ( is_wp_error( $valid ) ) {
                return $valid;
            }
        }

        return true;
    }

    /**
     * Get the chosen shipping method identifier.
     *
     * @return string
     */
    public function get_chosen_shipping_method() {
        return $this->session->get( 'chosen_shipping_method', '' );
    }

    /**
     * Set the chosen shipping method identifier.
     *
     * @param string $method Shipping method ID.
     * @return void
     */
    public function set_chosen_shipping_method( $method ) {
        $this->session->set( 'chosen_shipping_method', sanitize_key( $method ) );
        $this->totals = null;
        $this->save();
        $this->session->force_save();
    }

    /**
     * Persist the customer's shipping destination on the cart session so
     * the calculator can pick it up on the next totals computation.
     *
     * Without this, AJAX endpoints (Checkout_AJAX::refresh_shipping_methods,
     * the cart-totals partial recalc) had no surface to write the address
     * to, and Cart_Calculator::get_customer_shipping_*() fell back to the
     * store default for country and empty for state/postcode — which makes
     * the live tax-provider address-completeness gate reject the call and
     * tax silently stays at zero.
     *
     * @param string $country  Two-letter ISO country code.
     * @param string $state    State / province code.
     * @param string $postcode Postcode / ZIP.
     */
    public function set_shipping_destination( string $country, string $state = '', string $postcode = '', string $city = '', string $line1 = '' ): void {
        if ( ! is_object( $this->session ) ) {
            return;
        }

        $this->session->set(
            'shipping_destination',
            array(
                'country'  => sanitize_text_field( $country ),
                'state'    => sanitize_text_field( $state ),
                'postcode' => sanitize_text_field( $postcode ),
                'city'     => sanitize_text_field( $city ),
                'line1'    => sanitize_text_field( $line1 ),
            )
        );
        $this->totals = null;
        $this->save();
        if ( method_exists( $this->session, 'force_save' ) ) {
            $this->session->force_save();
        }
    }

    /**
     * Read the cart's shipping destination triple. Always returns an array
     * with the three keys (defaults to empty strings) so callers don't
     * need to defend against partial state.
     *
     * @return array{country:string,state:string,postcode:string}
     */
    public function get_shipping_destination(): array {
        $stored = is_object( $this->session ) ? $this->session->get( 'shipping_destination', array() ) : array();

        $country = is_array( $stored ) ? (string) ( $stored['country'] ?? '' ) : '';
        if ( '' === $country ) {
            // F-H6 / #929: when the cart has no destination yet (first
            // checkout paint for a fresh guest), fall back to the store
            // default country so the tax provider has SOMETHING to
            // resolve on. Without this the initial order summary shows
            // tax=0 and the buyer is rejected at submit with
            // cart_totals_changed. The filter lets the merchant or a
            // geolocation extension override.
            $store_country = (string) get_option( 'tejcart_store_country', '' );
            /**
             * Default shipping country used for the FIRST checkout
             * paint when the buyer has not yet entered an address.
             *
             * @param string $country ISO-2 country code. Empty string
             *                        disables the fallback (legacy behaviour).
             */
            $country = (string) apply_filters( 'tejcart_default_shipping_country', $store_country );
        }

        return array(
            'country'  => $country,
            'state'    => is_array( $stored ) ? (string) ( $stored['state'] ?? '' ) : '',
            'postcode' => is_array( $stored ) ? (string) ( $stored['postcode'] ?? '' ) : '',
            'city'     => is_array( $stored ) ? (string) ( $stored['city'] ?? '' ) : '',
            'line1'    => is_array( $stored ) ? (string) ( $stored['line1'] ?? '' ) : '',
        );
    }

    /**
     * Persist the current cart state to the session.
     */
    public function save() {
        $items_data = array();

        foreach ( $this->items as $key => $item ) {
            $items_data[ $key ] = array(
                'key'        => $item->get_key(),
                'product_id' => $item->get_product_id(),
                'quantity'   => $item->get_quantity(),
                'data'       => $item->get_data(),
            );
        }

        $this->session->set( 'cart', $items_data );
        $this->session->set( 'coupons', $this->coupons );
        $this->session->save();
    }

    /**
     * Load cart data from the session.
     *
     * Validates that each product still exists and is purchasable before
     * restoring it. Items that fail validation are silently removed and logged.
     */
    private function load_from_session() {
        $items_data    = $this->session->get( 'cart', array() );
        $items_changed = false;

        if ( is_array( $items_data ) ) {
            $product_ids = array_column( $items_data, 'product_id' );
            $product_ids = array_map( 'absint', array_filter( $product_ids ) );

            if ( ! empty( $product_ids ) ) {
                \TejCart\Product\Product_Factory::get_products( $product_ids );
            }

            foreach ( $items_data as $key => $item_data ) {
                if ( ! is_array( $item_data ) ) {
                    continue;
                }

                $product_id = isset( $item_data['product_id'] ) ? (int) $item_data['product_id'] : 0;
                $quantity   = isset( $item_data['quantity'] ) ? (int) $item_data['quantity'] : 1;
                $data       = isset( $item_data['data'] ) ? (array) $item_data['data'] : array();
                $item_key   = isset( $item_data['key'] ) ? $item_data['key'] : $key;

                if ( $product_id <= 0 ) {
                    continue;
                }

                $product = \TejCart\Product\Product_Factory::get_product( $product_id );

                if ( ! $product ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf( 'Cart session load: removed product #%d (no longer exists).', $product_id ),
                            'info'
                        );
                    }
                    $items_changed = true;
                    continue;
                }

                if ( ! $product->is_purchasable() ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf( 'Cart session load: removed product #%d (no longer purchasable).', $product_id ),
                            'info'
                        );
                    }
                    $items_changed = true;
                    continue;
                }

                $this->items[ $item_key ] = new Cart_Item( $item_key, $product_id, $quantity, $data );
            }
        }

        $coupons = $this->session->get( 'coupons', array() );

        if ( is_array( $coupons ) ) {
            $this->coupons = $coupons;
        }

        if ( $items_changed ) {
            $this->save();
        }
    }

    /**
     * Get or compute the calculated totals array.
     *
     * @return array
     */
    private function get_calculated_totals() {
        if ( null === $this->totals ) {
            $this->totals = $this->calculator->calculate( $this );
        }

        return $this->totals;
    }

    /**
     * Drop the cached totals so the next get_*_total() / get_*_money() /
     * get_calculated_totals() call lazy-recomputes via the calculator.
     *
     * Use this — not recalculate() — whenever you've changed something the
     * calculator already reads from the live cart state on its next pass:
     *
     *  - shipping destination set via set_shipping_destination()
     *  - chosen shipping method set via set_chosen_shipping_method()
     *  - billing / shipping country, state, or postcode on the customer
     *  - applied / removed coupons (those already drop totals on mutation)
     *
     * What invalidate_totals() intentionally does NOT do:
     *
     *  - It does NOT re-fetch products. If a product price may have
     *    changed in the database mid-session and you need to honour the
     *    new price, call recalculate() instead.
     *  - It does NOT re-validate applied coupons against the live coupons
     *    table. If a coupon may have expired between requests, the cart
     *    page entry point (which already calls recalculate()) is where
     *    that check belongs.
     *
     * Cost: a single property assignment. recalculate() by contrast does a
     * batched product fetch, resets every Cart_Item's product cache, and
     * revalidates every applied coupon. That is the right thing on a cart
     * page load and the wrong thing on a postcode keystroke — the AJAX
     * address-refresh and shipping-method-pick handlers should use
     * invalidate_totals() instead.
     */
    public function invalidate_totals(): void {
        $this->totals = null;
    }

    /**
     * Force a full recalculation of cart totals from fresh DB state.
     *
     * Clears cached totals, batch-warms Product_Factory for every product
     * the cart references so per-item lookups are O(1) array reads on the
     * warmed cache, drops each Cart_Item's lazy product cache so it picks
     * up the fresh data on its next get_product() call, re-validates all
     * applied coupons against the live coupons table, then runs the four
     * calculator stages (subtotal, discount, shipping, tax) in one pass.
     *
     * For lighter "the destination or method changed, but everything else
     * is the same" invalidation use {@see self::invalidate_totals()} — it
     * keeps Cart_Items, the coupon set, and the calculator's memoised
     * managers intact and just drops the totals cache.
     */
    public function recalculate() {
        $this->totals = null;

        $product_ids = array();
        foreach ( $this->items as $item ) {
            $pid = (int) $item->get_product_id();
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
        if ( ! empty( $product_ids ) ) {
            \TejCart\Product\Product_Factory::get_products( $product_ids );
        }

        foreach ( $this->items as $item ) {
            if ( method_exists( $item, 'reset_product_cache' ) ) {
                $item->reset_product_cache();
            }
        }

        if ( is_object( $this->calculator ) && method_exists( $this->calculator, 'invalidate_caches' ) ) {
            $this->calculator->invalidate_caches();
        }

        $this->revalidate_coupons();

        $this->totals = $this->calculator->calculate( $this );
    }

    /**
     * Re-validate all applied coupons and remove invalid ones.
     *
     * Checks each coupon against the database to ensure it is still active,
     * not expired, and within usage limits. Removed coupons are queued via
     * record_removed_coupon() so the cart page can render a notice instead
     * of silently dropping the discount between the cart and checkout.
     */
    private function revalidate_coupons() {
        if ( empty( $this->coupons ) ) {
            return;
        }

        $email           = $this->get_customer_email();
        $coupons_changed = false;

        foreach ( $this->coupons as $code => $coupon_data ) {
            $coupon_id = isset( $coupon_data['coupon_id'] ) ? (int) $coupon_data['coupon_id'] : 0;

            if ( ! $coupon_id ) {
                $coupon_id = $this->resolve_coupon_id( (string) $code );

                if ( $coupon_id <= 0 ) {
                    unset( $this->coupons[ $code ] );
                    $coupons_changed = true;

                    $this->record_removed_coupon(
                        $code,
                        __( 'This coupon is no longer available.', 'tejcart' )
                    );

                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( sprintf( 'Coupon "%s" removed during recalculation (not found).', $code ), 'info' );
                    }
                    continue;
                }
            }

            $coupon = new Coupon( $coupon_id );
            $valid  = $coupon->is_valid( $email, $this->get_subtotal() );

            if ( is_wp_error( $valid ) ) {
                unset( $this->coupons[ $code ] );
                $coupons_changed = true;

                $this->record_removed_coupon( $code, $valid->get_error_message() );

                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( 'Coupon "%s" removed during recalculation: %s', $code, $valid->get_error_message() ),
                        'info'
                    );
                }
            }
        }

        if ( $coupons_changed ) {
            $this->save();
        }
    }

    /**
     * Push a "coupon was just removed" entry into the session-backed queue
     * read by get_removed_coupons() (consumed by the cart page template,
     * the cart AJAX response, and the checkout review screen).
     *
     * Same code only ever queued once per session — repeat removes won't
     * pile up.
     *
     * @param string $code   Coupon code that was removed.
     * @param string $reason Human-readable reason.
     */
    private function record_removed_coupon( string $code, string $reason ): void {
        $code = sanitize_text_field( strtolower( $code ) );
        if ( '' === $code ) {
            return;
        }

        $queue = (array) $this->session->get( 'removed_coupons', array() );
        $queue[ $code ] = array(
            'code'    => $code,
            'reason'  => sanitize_text_field( $reason ),
            'removed' => time(),
        );

        $this->session->set( 'removed_coupons', $queue );

        /**
         * Fires when a previously-applied coupon is dropped during cart
         * recalculation. Listeners can route the message to a notice
         * queue or push it to the AJAX response.
         *
         * @param string $code   Coupon code.
         * @param string $reason Reason text.
         * @param Cart   $cart   Cart instance.
         */
        do_action( 'tejcart_coupon_removed_during_recalculation', $code, $reason, $this );
    }

    /**
     * Return — and atomically clear — the queue of coupons removed during
     * the most recent recalculation pass. Caller is expected to surface
     * them as notices (cart page) or include them in the AJAX response
     * (drawer / checkout).
     *
     * @return array<string, array{code:string, reason:string, removed:int}>
     */
    public function get_removed_coupons(): array {
        $queue = (array) $this->session->get( 'removed_coupons', array() );
        if ( empty( $queue ) ) {
            return array();
        }
        $this->session->set( 'removed_coupons', array() );
        return $queue;
    }

    /**
     * Push a one-shot notice into the cart's session queue. Read-and-cleared
     * by get_pending_notices(). Used by Stock_Reservation to tell the
     * customer why a quantity bump didn't take when stock ran out under
     * concurrent shoppers.
     *
     * @param string $message Plain-text customer-facing message.
     * @param string $type    'info' | 'success' | 'warning' | 'error'.
     */
    public function add_notice( string $message, string $type = 'info' ): void {
        $message = sanitize_text_field( $message );
        if ( '' === $message ) {
            return;
        }
        $allowed_types = array( 'info', 'success', 'warning', 'error' );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'info';
        }

        $queue   = (array) $this->session->get( 'notices', array() );
        $queue[] = array( 'message' => $message, 'type' => $type );
        $this->session->set( 'notices', $queue );
    }

    /**
     * Drain and return the cart's pending notices.
     *
     * @return array<int, array{message:string, type:string}>
     */
    public function get_pending_notices(): array {
        $queue = (array) $this->session->get( 'notices', array() );
        if ( empty( $queue ) ) {
            return array();
        }
        $this->session->set( 'notices', array() );
        return $queue;
    }
}
