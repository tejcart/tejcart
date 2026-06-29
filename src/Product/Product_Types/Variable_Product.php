<?php
/**
 * Variable Product class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

use TejCart\Product\Product_Factory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a variable product with multiple variations.
 */
class Variable_Product extends Abstract_Product {
    /**
     * Cached variation objects.
     *
     * @var Variation[]|null
     */
    protected $variations = null;

    /**
     * Product attributes.
     *
     * Each attribute is an array with keys: name, values, visible, used_for_variations.
     *
     * @var array|null
     */
    protected $attributes = null;

    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'variable';
    }

    /**
     * Get product attributes.
     *
     * Loads from product meta '_product_attributes' on first call. Each
     * attribute has name, values (array), visible, and used_for_variations.
     *
     * @return array
     */
    public function get_attributes() {
        if ( null === $this->attributes ) {
            $raw = $this->get_meta( '_product_attributes' );

            if ( is_string( $raw ) ) {
                $raw = json_decode( $raw, true );
            }

            if ( is_array( $raw ) ) {
                $this->attributes = array_map( function ( $attr ) {
                    return wp_parse_args( $attr, array(
                        'name'                => '',
                        'values'              => array(),
                        'visible'             => true,
                        'used_for_variations' => false,
                    ) );
                }, $raw );
            } else {
                $this->attributes = array();
            }
        }

        return $this->attributes;
    }

    /**
     * Set product attributes and persist to product meta.
     *
     * @param array $attributes Array of attribute arrays.
     * @return bool True on success, false on failure.
     */
    public function set_attributes( $attributes ) {
        $this->attributes = array_map( function ( $attr ) {
            return array(
                'name'                => sanitize_text_field( $attr['name'] ?? '' ),
                'values'              => array_map( 'sanitize_text_field', (array) ( $attr['values'] ?? array() ) ),
                'visible'             => ! empty( $attr['visible'] ),
                'used_for_variations' => ! empty( $attr['used_for_variations'] ),
            );
        }, (array) $attributes );

        $saved = $this->update_meta( '_product_attributes', wp_json_encode( $this->attributes ) );

        $this->cleanup_variation_attributes();

        return $saved;
    }

    /**
     * Strip any attribute key from a child variation's _variation_attributes
     * meta that is no longer declared on the parent.
     *
     * Returns the number of variations whose meta was rewritten. Works
     * even when get_variations() hasn't been hydrated — reads the meta
     * table directly so it's safe to call inside set_attributes() before
     * the ORM layer knows about the change.
     *
     * @return int
     */
    public function cleanup_variation_attributes(): int {
        if ( $this->get_id() <= 0 ) {
            return 0;
        }

        $valid_keys = array_values( array_filter( array_map(
            static fn ( $a ) => (string) ( $a['name'] ?? '' ),
            (array) $this->attributes
        ) ) );
        $valid_map  = array_flip( $valid_keys );

        global $wpdb;
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $variation_ids = (array) $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT product_id FROM {$meta_table} WHERE meta_key = '_variation_parent_id' AND meta_value = %s",
                (string) $this->get_id()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        $cleaned = 0;
        foreach ( $variation_ids as $variation_id ) {
            $variation = Product_Factory::get_product( (int) $variation_id );
            if ( ! $variation ) {
                continue;
            }

            $current = $variation->get_meta( '_variation_attributes' );
            if ( is_string( $current ) ) {
                $current = json_decode( $current, true );
            }
            if ( ! is_array( $current ) || empty( $current ) ) {
                continue;
            }

            $filtered = array_intersect_key( $current, $valid_map );
            if ( count( $filtered ) !== count( $current ) ) {
                $variation->update_meta( '_variation_attributes', wp_json_encode( $filtered ) );
                $cleaned++;
            }
        }

        /**
         * Fires after orphan variation attributes have been stripped.
         *
         * @param int               $cleaned_count Number of variations rewritten.
         * @param Variable_Product  $parent        The variable parent.
         */
        do_action( 'tejcart_variation_attributes_cleaned', $cleaned, $this );

        return $cleaned;
    }

    /**
     * Get all variations for this variable product.
     *
     * Queries the product meta table to find all products whose
     * '_variation_parent_id' matches this product's ID, then loads
     * each one via the Product_Factory.
     *
     * @return Variation[]
     */
    public function get_variations() {
        if ( null === $this->variations ) {
            global $wpdb;

            $meta_table    = $wpdb->prefix . 'tejcart_product_meta';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $variation_ids = $wpdb->get_col(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT product_id FROM {$meta_table} WHERE meta_key = '_variation_parent_id' AND meta_value = %s",
                    (string) $this->get_id()
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

            $products = Product_Factory::get_products( array_map( 'absint', $variation_ids ) );
            $this->variations = [];
            foreach ( $products as $product ) {
                if ( $product instanceof Variation ) {
                    $this->variations[] = $product;
                }
            }
        }

        return $this->variations;
    }

    /**
     * Get a specific variation by ID.
     *
     * @param int $variation_id Variation product ID.
     * @return Variation|false Variation instance or false if not found or not a child of this product.
     */
    public function get_variation( $variation_id ) {
        $variation = Product_Factory::get_product( absint( $variation_id ) );

        if ( ! $variation instanceof Variation ) {
            return false;
        }

        if ( $variation->get_parent_id() !== $this->get_id() ) {
            return false;
        }

        return $variation;
    }

    /**
     * Get the product price.
     *
     * Returns the lowest variation price for display purposes.
     *
     * @return string
     */
    public function get_price() {
        $range = $this->get_price_range();

        // NOTE: do NOT re-apply `tejcart_product_get_price` here. The range is
        // built from each Variation::get_price(), which already runs that
        // filter (via Abstract_Product::get_price()). Re-filtering the min
        // would double-convert under multi-currency (Converter::convert() is
        // not idempotent), yielding e.g. €84.64 instead of €92.00 for a $100
        // variation at rate 0.92. get_regular_price()/get_sale_price() below
        // already (correctly) return the range value without re-filtering.
        return $range ? (string) $range[0] : '';
    }

    /**
     * Get the regular price across all variations.
     *
     * Returns the lowest variation regular price — the parent row's
     * stored price column is unused for variable products, so reading
     * it (as Abstract_Product does) yields a misleading 0.00. The
     * admin form, product box, single-product template and JSON-LD
     * schema all rely on this to show a real parent-level price.
     *
     * @return string
     */
    public function get_regular_price() {
        $range = $this->get_regular_price_range();

        return $range ? (string) $range[0] : '';
    }

    /**
     * Get the regular price range [min, max] across all variations.
     *
     * Uncached — consumed by the admin pricing tab, which runs once per
     * edit screen. Unlike get_price_range() this ignores active sales so
     * the merchant sees what they actually typed into the variation rows.
     *
     * F-PCA-011: The returned float values are display-only; they must NOT
     * be used in cart arithmetic. Use integer minor-unit values (via
     * Currency::to_minor_units()) for any money calculation. These floats
     * exist purely so tejcart_price() can format them for output.
     *
     * @internal Display-only. Do not use for money arithmetic.
     * @return array{0: float, 1: float}|null Tuple of [min_display_price, max_display_price], or null when no variations have a price.
     */
    public function get_regular_price_range() {
        // C-H5: prefer in-stock variations so the displayed regular-price
        // range matches what's purchasable, falling back to all when none
        // are in stock.
        return $this->collect_price_range(
            $this->get_variations(),
            static function ( $variation ) {
                return $variation->get_regular_price();
            }
        );
    }

    /**
     * Get the sale price across all variations.
     *
     * Mirrors get_regular_price(): returns the lowest variation sale
     * price so the admin pricing tab (and anywhere else that reads the
     * parent's sale_price) reflects the variations instead of the
     * unused parent column — which otherwise surfaces as 0.00 even
     * after sales are set on individual variations.
     *
     * @return string
     */
    public function get_sale_price() {
        $range = $this->get_sale_price_range();

        return $range ? (string) $range[0] : '';
    }

    /**
     * Get the sale price range [min, max] across variations that have one.
     *
     * Returns null when no variation has a sale price set.
     *
     * F-PCA-011: The returned float values are display-only; they must NOT
     * be used in cart arithmetic. Use integer minor-unit values (via
     * Currency::to_minor_units()) for any money calculation.
     *
     * @internal Display-only. Do not use for money arithmetic.
     * @return array{0: float, 1: float}|null Tuple of [min_sale_price, max_sale_price], or null when no variations have a sale price.
     */
    public function get_sale_price_range() {
        // C-H5: prefer in-stock variations so the displayed sale-price
        // range matches what's purchasable, falling back to all when none
        // are in stock.
        return $this->collect_price_range(
            $this->get_variations(),
            static function ( $variation ) {
                return $variation->get_sale_price();
            }
        );
    }

    /**
     * Get the price range across all variations.
     *
     * Caches the computed [min, max] in product meta keyed by the
     * product's updated_at timestamp so subsequent shop / single-product
     * renders skip the per-variation Variation::get_price() round-trip.
     * The cache is automatically invalidated whenever updated_at moves
     * (which it does on any save() — parent or variation — because the
     * cascade in invalidate_price_cache() touches the parent row).
     *
     * F-PCA-011: The returned float values are display-only; they must NOT
     * be used in cart arithmetic. Use integer minor-unit values (via
     * Currency::to_minor_units()) for any money calculation.
     *
     * @internal Display-only. Do not use for money arithmetic.
     * @return array{0: float, 1: float}|null Tuple of [min_price, max_price], or null if no variations have a price.
     */
    public function get_price_range() {
        if ( $this->id > 0 ) {
            $cached = $this->get_meta( '_price_range_cache' );
            $stamp  = (string) $this->get_meta( '_price_range_stamp' );
            $token  = $this->price_cache_token();

            if ( is_string( $cached ) && $stamp === $token && '' !== $cached ) {
                $decoded = json_decode( $cached, true );
                if ( is_array( $decoded ) && isset( $decoded[0], $decoded[1] ) ) {
                    return array( (float) $decoded[0], (float) $decoded[1] );
                }
                if ( is_array( $decoded ) && empty( $decoded ) ) {
                    return null;
                }
            }
        }

        $variations = $this->get_variations();

        if ( empty( $variations ) ) {
            $this->store_price_cache( null );
            return null;
        }

        // C-H5: the advertised "From $X" must reflect what a buyer can
        // actually purchase. Prefer in-stock variations and only fall
        // back to the full set when every variation is out of stock (so
        // a fully sold-out product still shows a price rather than
        // nothing).
        $range = $this->collect_price_range(
            $variations,
            static function ( $variation ) {
                return $variation->get_price();
            }
        );

        $this->store_price_cache( $range );
        return $range;
    }

    /**
     * Build a [min, max] price range from a set of variations, preferring
     * in-stock variations and falling back to the full set when none are
     * in stock.
     *
     * @param Variation[] $variations Variation objects to scan.
     * @param callable     $accessor   Returns the price string for a variation.
     * @return array{0: float, 1: float}|null
     */
    protected function collect_price_range( array $variations, callable $accessor ) {
        $in_stock_prices = array();
        $all_prices      = array();

        foreach ( $variations as $variation ) {
            $price = $accessor( $variation );

            if ( '' === $price ) {
                continue;
            }

            $price        = (float) $price;
            $all_prices[] = $price;

            if ( $variation->is_in_stock() ) {
                $in_stock_prices[] = $price;
            }
        }

        $prices = ! empty( $in_stock_prices ) ? $in_stock_prices : $all_prices;

        if ( empty( $prices ) ) {
            return null;
        }

        return array( min( $prices ), max( $prices ) );
    }

    /**
     * Build the cache token used to gate the price-range cache.
     *
     * Combines the product's updated_at column with an optional bump
     * value so admin saves and explicit invalidate calls both
     * invalidate the cache without touching meta.
     *
     * @return string
     */
    protected function price_cache_token() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $updated = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT updated_at FROM {$table} WHERE id = %d", $this->id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // C-M6: updated_at only moves on an explicit save. A scheduled
        // sale window opening or closing changes the effective variation
        // price (Variation::get_price() honours _sale_price_dates_*)
        // without touching any row, so the cached range would stay stale
        // across the boundary. Fold a "current sale-window epoch" into
        // the token: it counts how many of the children's sale-window
        // boundaries lie in the past, so the token changes the moment a
        // boundary is crossed and the range recomputes on the next read.
        $now      = time();
        $crossed  = 0;
        foreach ( $this->get_variations() as $variation ) {
            $from = $variation->get_sale_date_from();
            $to   = $variation->get_sale_date_to();
            if ( $from > 0 && $now >= $from ) {
                $crossed++;
            }
            if ( $to > 0 && $now >= $to ) {
                $crossed++;
            }
        }

        // Multi-currency: collect_price_range() runs each Variation::get_price()
        // through the product-price filters, so the cached [min,max] is in the
        // ACTIVE currency at cache time. Without folding the currency into the
        // token, a EUR visitor's cached range would be served to a later USD/GBP
        // visitor (persisted in product meta). Append the active currency so a
        // currency switch invalidates and recomputes. On a single-currency store
        // the code is constant, so caching behaves exactly as before.
        $currency = function_exists( 'tejcart_get_currency' ) ? (string) tejcart_get_currency() : '';

        return (string) $updated . '|sw:' . $crossed . '|c:' . $currency;
    }

    /**
     * Persist the computed price range alongside the cache token.
     *
     * @param array|null $range [min, max] tuple, or null to record the
     *                          "no purchasable variations" outcome.
     * @return void
     */
    protected function store_price_cache( $range ) {
        if ( $this->id <= 0 ) {
            return;
        }

        $payload = is_array( $range ) ? wp_json_encode( $range ) : '[]';
        $this->update_meta( '_price_range_cache', $payload );
        $this->update_meta( '_price_range_stamp', $this->price_cache_token() );
    }

    /**
     * Drop the cached price range. Called from Variation::save() and the
     * variation/product delete paths so the next render recomputes.
     *
     * @return void
     */
    public function invalidate_price_cache() {
        if ( $this->id <= 0 ) {
            return;
        }

        $this->update_meta( '_price_range_stamp', '' );
    }

    /**
     * Get variations that are currently in stock.
     *
     * @return Variation[]
     */
    public function get_available_variations() {
        $available = array();

        foreach ( $this->get_variations() as $variation ) {
            if ( $variation->is_in_stock() ) {
                $available[] = $variation;
            }
        }

        return $available;
    }

    /**
     * Check if the variable product is purchasable.
     *
     * A variable product is purchasable if at least one variation is purchasable.
     *
     * @return bool
     */
    public function is_purchasable() {
        if ( 'publish' !== $this->get_status() ) {
            return apply_filters( 'tejcart_product_is_purchasable', false, $this );
        }

        foreach ( $this->get_variations() as $variation ) {
            if ( $variation->is_purchasable() ) {
                return apply_filters( 'tejcart_product_is_purchasable', true, $this );
            }
        }

        return apply_filters( 'tejcart_product_is_purchasable', false, $this );
    }

    /**
     * Check if the variable product needs shipping.
     *
     * Delegates to variations: true if any variation needs shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        foreach ( $this->get_variations() as $variation ) {
            if ( $variation->needs_shipping() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the variable product is virtual.
     *
     * A variable product is virtual only if ALL variations are virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        $variations = $this->get_variations();

        if ( empty( $variations ) ) {
            return false;
        }

        foreach ( $variations as $variation ) {
            if ( ! $variation->is_virtual() ) {
                return false;
            }
        }

        return true;
    }
}
