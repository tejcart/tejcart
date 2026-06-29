<?php
/**
 * Advanced Coupons.
 *
 * Adds product / category include & exclude restrictions and a simple
 * Buy-One-Get-One free mode on top of the existing Coupon model.
 *
 * Integration approach:
 *  - Restrictions are stored as rows in wp_tejcart_coupon_meta keyed by
 *    coupon_id, leaving the wp_tejcart_coupons table untouched.
 *  - Validation hooks into `tejcart_validate_coupon` (already fired by
 *    Cart::apply_coupon) so we never duplicate cart logic.
 *  - BOGO discounts are computed in `tejcart_after_calculate_totals` and
 *    folded into discount_total via `tejcart_cart_total` filter so the
 *    Cart_Calculator does not need to know about BOGO at all.
 *
 * @package TejCart\Tier2\Coupons
 */

declare( strict_types=1 );

namespace TejCart\Tier2\Coupons;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advanced_Coupons {
    /**
     * Idempotency guard so register_restriction_validator() can be safely
     * called from both the always-on fallback in `Tier2::boot()` and the
     * regular init() entry point without double-binding the filter.
     *
     * @var bool
     */
    private static bool $restriction_validator_registered = false;

    /**
     * Wire up filters / actions.
     */
    public static function init() {
        self::register_restriction_validator();
        add_filter( 'tejcart_cart_total', array( __CLASS__, 'apply_bogo_to_total' ), 10, 2 );
        add_action( 'tejcart_admin_init', array( __CLASS__, 'register_admin_save_hook' ) );
    }

    /**
     * Register ONLY the include/exclude product + category validator on
     * `tejcart_validate_coupon`. Split out from init() so the Tier-2
     * loader can keep these restrictions always-on even when the rest of
     * the Advanced_Coupons module (BOGO + admin save) has been filtered
     * out via `tejcart_tier2_modules`.
     *
     * Audit #99 / 01 #9 — a merchant who disables the module via the
     * filter previously got a SILENT loss of restriction enforcement,
     * which means a percentage-off coupon scoped to category "Hats"
     * would suddenly discount the whole cart.
     */
    public static function register_restriction_validator(): void {
        if ( self::$restriction_validator_registered ) {
            return;
        }
        add_filter( 'tejcart_validate_coupon', array( __CLASS__, 'validate_coupon' ), 10, 3 );
        // Confine percent / fixed_product discounts to the lines a restricted
        // coupon actually applies to. Without this the validator gate lets the
        // coupon in (cart contains a matching product) but the calculator still
        // discounts every line. Kept always-on for the same reason the
        // validator is — disabling the module must not silently widen a
        // scoped coupon to the whole cart.
        add_filter( 'tejcart_coupon_line_applies', array( __CLASS__, 'coupon_line_applies' ), 10, 4 );
        self::$restriction_validator_registered = true;
    }

    /**
     * Persist a meta value for a coupon.
     */
    public static function update_meta( $coupon_id, $key, $value ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupon_meta';
        $coupon_id = (int) $coupon_id;
        $value     = is_array( $value ) || is_object( $value ) ? wp_json_encode( $value ) : (string) $value;

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT meta_id FROM {$table} WHERE coupon_id = %d AND meta_key = %s", $coupon_id, $key )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $existing ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; cache is invalidated alongside writes.
            return false !== $wpdb->update(
                $table,
                array( 'meta_value' => $value ),
                array( 'meta_id' => (int) $existing ),
                array( '%s' ),
                array( '%d' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; cache is invalidated alongside writes.
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; cache is invalidated alongside writes.
        return false !== $wpdb->insert(
            $table,
            array( 'coupon_id' => $coupon_id, 'meta_key' => $key, 'meta_value' => $value ),
            array( '%d', '%s', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom plugin table; cache is invalidated alongside writes.
    }

    /**
     * Get a meta value, optionally JSON-decoded.
     */
    public static function get_meta( $coupon_id, $key, $decode = true ) {
        static $cache = array();
        $coupon_id = (int) $coupon_id;
        $cache_key = $coupon_id . ':' . $key;

        if ( ! isset( $cache[ $cache_key ] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tejcart_coupon_meta';
            // $table is composed from $wpdb->prefix and a constant suffix.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $cache[ $cache_key ] = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE coupon_id = %d AND meta_key = %s", $coupon_id, $key )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        $value = $cache[ $cache_key ];

        if ( null === $value ) {
            return null;
        }

        if ( $decode ) {
            $decoded = json_decode( $value, true );
            return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $value;
        }

        return $value;
    }

    /**
     * Hooked to tejcart_validate_coupon. Enforces include/exclude product
     * and category restrictions before the coupon is applied.
     *
     * @param array|false $coupon_data Coupon data array as built by Cart::apply_coupon.
     * @param string      $code        Coupon code.
     * @param mixed       $cart        Cart instance.
     * @return array|false|\WP_Error
     */
    public static function validate_coupon( $coupon_data, $code, $cart ) {
        if ( empty( $coupon_data ) || ! is_array( $coupon_data ) || empty( $coupon_data['coupon_id'] ) ) {
            return $coupon_data;
        }

        $coupon_id = (int) $coupon_data['coupon_id'];
        $rules     = array(
            'include_products'   => self::get_meta( $coupon_id, 'include_products' ),
            'exclude_products'   => self::get_meta( $coupon_id, 'exclude_products' ),
            'include_categories' => self::get_meta( $coupon_id, 'include_categories' ),
            'exclude_categories' => self::get_meta( $coupon_id, 'exclude_categories' ),
            'bogo'               => self::get_meta( $coupon_id, 'bogo' ),
        );

        if ( empty( array_filter( $rules ) ) ) {
            return $coupon_data;
        }

        $items = is_object( $cart ) && method_exists( $cart, 'get_items' ) ? $cart->get_items() : array();

        if ( empty( $items ) ) {
            return $coupon_data;
        }

        $product_ids = array();
        foreach ( $items as $item ) {
            $pid = is_object( $item ) && method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
            if ( $pid ) {
                $product_ids[] = $pid;
            }
        }

        if ( ! empty( $rules['include_products'] ) && is_array( $rules['include_products'] ) ) {
            if ( ! array_intersect( $product_ids, array_map( 'intval', $rules['include_products'] ) ) ) {
                return new \WP_Error( 'coupon_product_required', __( 'This coupon requires specific products in your cart.', 'tejcart' ) );
            }
        }

        if ( ! empty( $rules['exclude_products'] ) && is_array( $rules['exclude_products'] ) ) {
            if ( array_intersect( $product_ids, array_map( 'intval', $rules['exclude_products'] ) ) ) {
                return new \WP_Error( 'coupon_product_excluded', __( 'This coupon cannot be used with one of the products in your cart.', 'tejcart' ) );
            }
        }

        if ( ! empty( $product_ids ) && ( ! empty( $rules['include_categories'] ) || ! empty( $rules['exclude_categories'] ) ) ) {
            $cart_terms = self::get_term_ids_for_products( $product_ids );

            if ( ! empty( $rules['include_categories'] ) && is_array( $rules['include_categories'] ) ) {
                if ( ! array_intersect( $cart_terms, array_map( 'intval', $rules['include_categories'] ) ) ) {
                    return new \WP_Error( 'coupon_category_required', __( 'This coupon requires products from a specific category.', 'tejcart' ) );
                }
            }

            if ( ! empty( $rules['exclude_categories'] ) && is_array( $rules['exclude_categories'] ) ) {
                if ( array_intersect( $cart_terms, array_map( 'intval', $rules['exclude_categories'] ) ) ) {
                    return new \WP_Error( 'coupon_category_excluded', __( 'This coupon cannot be used with one of the categories in your cart.', 'tejcart' ) );
                }
            }
        }

        if ( ! empty( $rules['bogo'] ) ) {
            $coupon_data['bogo'] = $rules['bogo'];
        }

        return $coupon_data;
    }

    /**
     * Hooked to `tejcart_coupon_line_applies`. Decides whether a coupon's
     * discount may touch a single cart line, honouring the same include /
     * exclude product + category rules the validator enforces at cart level.
     *
     * The validator gate is all-or-nothing (it lets the coupon in when the
     * cart contains any matching product); this confines the resulting
     * discount to the matching lines so non-matching products are not
     * silently discounted.
     *
     * @param bool  $applies Whether the coupon discounts this line so far.
     * @param mixed $item    Cart line item.
     * @param mixed $coupon  Coupon data array (carries 'coupon_id').
     * @param mixed $cart    Cart instance.
     * @return bool
     */
    public static function coupon_line_applies( $applies, $item, $coupon, $cart ) {
        // A prior callback already excluded the line — respect that.
        if ( ! $applies ) {
            return $applies;
        }

        $coupon_id = is_array( $coupon ) && ! empty( $coupon['coupon_id'] ) ? (int) $coupon['coupon_id'] : 0;
        if ( ! $coupon_id ) {
            return $applies;
        }

        $include_products   = self::get_meta( $coupon_id, 'include_products' );
        $exclude_products   = self::get_meta( $coupon_id, 'exclude_products' );
        $include_categories = self::get_meta( $coupon_id, 'include_categories' );
        $exclude_categories = self::get_meta( $coupon_id, 'exclude_categories' );

        $has_rules = ! empty( $include_products ) || ! empty( $exclude_products )
            || ! empty( $include_categories ) || ! empty( $exclude_categories );
        if ( ! $has_rules ) {
            return $applies;
        }

        $pid = is_object( $item ) && method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        if ( ! $pid ) {
            return $applies;
        }

        // Resolve this line's category terms only when category rules exist.
        $terms = ( ! empty( $include_categories ) || ! empty( $exclude_categories ) )
            ? self::term_ids_for_product( $pid )
            : array();

        // Exclusions always remove the line from scope.
        if ( ! empty( $exclude_products ) && is_array( $exclude_products )
            && in_array( $pid, array_map( 'intval', $exclude_products ), true ) ) {
            return false;
        }
        if ( ! empty( $exclude_categories ) && is_array( $exclude_categories )
            && array_intersect( $terms, array_map( 'intval', $exclude_categories ) ) ) {
            return false;
        }

        // When any positive rule is set, the line must match at least one of
        // them (product OR category) to receive the discount.
        $has_includes = ( ! empty( $include_products ) && is_array( $include_products ) )
            || ( ! empty( $include_categories ) && is_array( $include_categories ) );
        if ( $has_includes ) {
            $in_scope = ! empty( $include_products ) && is_array( $include_products )
                && in_array( $pid, array_map( 'intval', $include_products ), true );

            if ( ! $in_scope && ! empty( $include_categories ) && is_array( $include_categories )
                && array_intersect( $terms, array_map( 'intval', $include_categories ) ) ) {
                $in_scope = true;
            }

            if ( ! $in_scope ) {
                return false;
            }
        }

        return $applies;
    }

    /**
     * Cached single-product term lookup. Memoised per request so a
     * category-restricted coupon recalculated across a cart of N lines does
     * not issue N identical queries.
     *
     * @param int $product_id
     * @return int[]
     */
    private static function term_ids_for_product( int $product_id ) {
        static $cache = array();
        if ( ! isset( $cache[ $product_id ] ) ) {
            $cache[ $product_id ] = self::get_term_ids_for_products( array( $product_id ) );
        }
        return $cache[ $product_id ];
    }

    /**
     * Look up term ids for a batch of product ids using the bridge table.
     *
     * One query, no per-product N+1.
     *
     * @param int[] $product_ids
     * @return int[]
     */
    private static function get_term_ids_for_products( array $product_ids ) {
        global $wpdb;
        if ( empty( $product_ids ) ) {
            return array();
        }
        $product_ids = array_map( 'absint', $product_ids );
        $placeholder = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        $bridge      = $wpdb->prefix . 'tejcart_term_relationships';
        $tt          = $wpdb->term_taxonomy;

        /**
         * Filter which taxonomies the category-restriction rules look at.
         *
         * Defaults to product categories; a store that sells across multiple
         * custom taxonomies can add them here.
         *
         * @since 1.0.0
         *
         * @param string[] $taxonomies
         */
        $taxonomies = (array) apply_filters(
            'tejcart_advanced_coupon_category_taxonomies',
            array( 'product_cat' )
        );
        $taxonomies = array_values( array_filter( array_map( 'sanitize_key', $taxonomies ) ) );
        if ( empty( $taxonomies ) ) {
            return array();
        }

        $tax_placeholder = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

        $sql = "SELECT DISTINCT tt.term_id
                FROM {$bridge} br
                INNER JOIN {$tt} tt ON tt.term_taxonomy_id = br.term_taxonomy_id
                WHERE br.product_id IN ({$placeholder})
                  AND tt.taxonomy IN ({$tax_placeholder})";

        // $sql is composed from validated table names and fixed-shape placeholder lists.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( $product_ids, $taxonomies ) ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Apply BOGO discounts after the calculator has produced its total.
     *
     * For each applied coupon that carries a `bogo` config, find matching
     * items in the cart and add their cheapest unit prices as additional
     * discount, capped at the configured number of free items.
     *
     * BOGO config shape:
     *   {
     *     "buy_qty": 1,
     *     "get_qty": 1,
     *     "max_sets": 0,        // 0 = unlimited
     *     "products": [12, 34]  // optional product whitelist
     *   }
     */
    public static function apply_bogo_to_total( $total, $cart ) {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_coupons' ) ) {
            return $total;
        }
        $coupons = $cart->get_coupons();
        if ( empty( $coupons ) ) {
            return $total;
        }

        $bogo_discount = 0.0;

        foreach ( $coupons as $coupon ) {
            if ( empty( $coupon['bogo'] ) || ! is_array( $coupon['bogo'] ) ) {
                continue;
            }
            $cfg      = $coupon['bogo'];
            $buy_qty  = max( 1, (int) ( $cfg['buy_qty']  ?? 1 ) );
            $get_qty  = max( 1, (int) ( $cfg['get_qty']  ?? 1 ) );
            $max_sets = max( 0, (int) ( $cfg['max_sets'] ?? 0 ) );
            $allowed  = isset( $cfg['products'] ) && is_array( $cfg['products'] )
                ? array_map( 'intval', $cfg['products'] )
                : array();

            $unit_prices = array();
            foreach ( $cart->get_items() as $item ) {
                if ( ! is_object( $item ) ) {
                    continue;
                }
                $pid = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
                if ( ! empty( $allowed ) && ! in_array( $pid, $allowed, true ) ) {
                    continue;
                }
                $qty   = method_exists( $item, 'get_quantity' )   ? (int)   $item->get_quantity()   : 1;
                $line  = method_exists( $item, 'get_line_total' ) ? (float) $item->get_line_total() : 0.0;
                $unit  = $qty > 0 ? $line / $qty : 0.0;
                for ( $i = 0; $i < $qty; $i++ ) {
                    $unit_prices[] = $unit;
                }
            }

            if ( count( $unit_prices ) < ( $buy_qty + $get_qty ) ) {
                continue;
            }

            sort( $unit_prices );

            $set_size  = $buy_qty + $get_qty;
            $sets      = (int) floor( count( $unit_prices ) / $set_size );
            if ( $max_sets > 0 ) {
                $sets = min( $sets, $max_sets );
            }
            if ( $sets <= 0 ) {
                continue;
            }

            $free_units = $sets * $get_qty;
            for ( $i = 0; $i < $free_units; $i++ ) {
                $bogo_discount += $unit_prices[ $i ];
            }
        }

        if ( $bogo_discount <= 0 ) {
            return $total;
        }

        return max( 0.0, round( (float) $total - $bogo_discount, 2 ) );
    }

    /**
     * Hook into the existing admin coupon save flow.
     *
     * Listens on `tejcart_admin_coupon_saved` if it exists, otherwise on
     * generic admin POST submissions for the coupon edit screen. Both
     * paths funnel into the same persist routine so admins can adopt
     * either pattern.
     */
    public static function register_admin_save_hook() {
        add_action( 'tejcart_admin_coupon_saved', array( __CLASS__, 'persist_admin_rules' ), 10, 1 );
    }

    /**
     * Persist advanced rules submitted on the coupon edit screen.
     *
     * Defense-in-depth: even though this fires on tejcart_admin_coupon_saved
     * (which itself runs after the admin form has been authenticated), we
     * re-verify capability and a dedicated nonce here so any custom caller
     * that reuses the hook without its own CSRF check stays safe.
     *
     * @param int $coupon_id
     */
    public static function persist_admin_rules( $coupon_id ) {
        if ( empty( $coupon_id ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_advanced_coupon_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_advanced_coupon_nonce'] ) )
            : (
                isset( $_POST['_wpnonce'] )
                    ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
                    : ''
            );

        if (
            ! wp_verify_nonce( $nonce, 'tejcart_save_advanced_coupon_' . (int) $coupon_id )
            && ! wp_verify_nonce( $nonce, 'tejcart_save_coupon' )
        ) {
            return;
        }
        $fields = array(
            'include_products',
            'exclude_products',
            'include_categories',
            'exclude_categories',
        );

        foreach ( $fields as $field ) {
            // Nonce verified above. Each entry is coerced through absint() below.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw = isset( $_POST[ 'tejcart_' . $field ] ) ? wp_unslash( $_POST[ 'tejcart_' . $field ] ) : '';
            if ( is_string( $raw ) ) {
                $raw = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
            } elseif ( is_array( $raw ) ) {
                $raw = array_filter( array_map( 'absint', $raw ) );
            } else {
                $raw = array();
            }
            self::update_meta( (int) $coupon_id, $field, array_values( $raw ) );
        }

        if ( ! empty( $_POST['tejcart_bogo_enabled'] ) ) {
            $bogo = array(
                'buy_qty'  => isset( $_POST['tejcart_bogo_buy_qty'] )  ? max( 1, (int) $_POST['tejcart_bogo_buy_qty'] )  : 1,
                'get_qty'  => isset( $_POST['tejcart_bogo_get_qty'] )  ? max( 1, (int) $_POST['tejcart_bogo_get_qty'] )  : 1,
                'max_sets' => isset( $_POST['tejcart_bogo_max_sets'] ) ? max( 0, (int) $_POST['tejcart_bogo_max_sets'] ) : 0,
                'products' => array(),
            );
            if ( ! empty( $_POST['tejcart_bogo_products'] ) ) {
                $bogo['products'] = array_filter( array_map( 'absint', (array) $_POST['tejcart_bogo_products'] ) );
            }
            self::update_meta( (int) $coupon_id, 'bogo', $bogo );
        } else {
            self::update_meta( (int) $coupon_id, 'bogo', null );
        }
    }
}
