<?php
/**
 * Abstract Product class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for all product types.
 */
abstract class Abstract_Product {
    /**
     * Product ID.
     *
     * @var int
     */
    protected $id = 0;

    /**
     * Last error from save(), when save returned false.
     *
     * @var \WP_Error|null
     */
    protected $last_save_error = null;

    /**
     * Product data.
     *
     * @var array
     */
    protected $data = array(
        'name'                  => '',
        'slug'                  => '',
        'type'                  => 'physical',
        'status'                => 'publish',
        'description'           => '',
        'short_description'     => '',
        'sku'                   => '',
        'price'                 => '',
        'sale_price'            => '',
        'stock_quantity'        => null,
        'stock_status'          => 'instock',
        'manage_stock'          => false,
        'weight'                => '',
        'dimensions'            => array(
            'length' => '',
            'width'  => '',
            'height' => '',
        ),
        'image_id'              => 0,
        'gallery_ids'           => array(),
        'downloadable'          => false,
        'virtual'               => false,

        'backorders'            => '',
        'sold_individually'     => false,
        'min_purchase_quantity' => 0,
        'max_purchase_quantity' => 0,
        'tax_class'             => '',
        'shipping_class'        => '',
        'catalog_visibility'    => '',
        'featured'              => false,
        'total_sales'           => 0,
    );

    /**
     * Constructor.
     *
     * @param int        $id   Optional product ID. If provided and no $data given, loads from DB.
     * @param array|null $data Optional pre-fetched row data to avoid a second query.
     */
    public function __construct( $id = 0, $data = null ) {
        if ( $data !== null && is_array( $data ) ) {
            $this->id = (int) ( $data['id'] ?? 0 );
            $this->populate( $data );
        } elseif ( $id > 0 ) {
            $this->id = absint( $id );
            $this->read();
        }
    }

    /**
     * Map a raw database row into $this->data.
     *
     * Single authoritative implementation used by both populate() and read().
     *
     * @param array $row Database row as associative array.
     * @return void
     */
    private function map_row( array $row ): void {
        $this->data['name']                  = $row['name'] ?? '';
        $this->data['slug']                  = $row['slug'] ?? '';
        $this->data['type']                  = $row['type'] ?? 'physical';
        $this->data['status']                = $row['status'] ?? 'publish';
        $this->data['description']           = $row['description'] ?? '';
        $this->data['short_description']     = $row['short_description'] ?? '';
        $this->data['sku']                   = $row['sku'] ?? '';
        $this->data['price']                 = $row['price'] ?? '';
        $this->data['sale_price']            = $row['sale_price'] ?? '';
        $this->data['stock_quantity']        = isset( $row['stock_quantity'] ) ? (int) $row['stock_quantity'] : null;
        $this->data['stock_status']          = $row['stock_status'] ?? 'instock';
        $this->data['manage_stock']          = ! empty( $row['manage_stock'] );
        $this->data['weight']                = $row['weight'] ?? '';
        $this->data['image_id']              = isset( $row['image_id'] ) ? absint( $row['image_id'] ) : 0;
        $this->data['downloadable']          = ! empty( $row['downloadable'] );
        $this->data['virtual']               = ! empty( $row['virtual'] );

        if ( array_key_exists( 'backorders', $row ) ) {
            $this->data['backorders'] = (string) $row['backorders'];
        }
        if ( array_key_exists( 'sold_individually', $row ) ) {
            $this->data['sold_individually'] = ! empty( $row['sold_individually'] );
        }
        if ( array_key_exists( 'min_purchase_quantity', $row ) ) {
            $this->data['min_purchase_quantity'] = max( 1, (int) $row['min_purchase_quantity'] );
        }
        if ( array_key_exists( 'max_purchase_quantity', $row ) ) {
            $this->data['max_purchase_quantity'] = max( 0, (int) $row['max_purchase_quantity'] );
        }
        if ( array_key_exists( 'tax_class', $row ) ) {
            $this->data['tax_class'] = (string) $row['tax_class'];
        }
        if ( array_key_exists( 'shipping_class', $row ) ) {
            $this->data['shipping_class'] = (string) $row['shipping_class'];
        }
        if ( array_key_exists( 'catalog_visibility', $row ) ) {
            $this->data['catalog_visibility'] = (string) $row['catalog_visibility'];
        }
        if ( array_key_exists( 'featured', $row ) ) {
            $this->data['featured'] = ! empty( $row['featured'] );
        }
        if ( array_key_exists( 'total_sales', $row ) ) {
            $this->data['total_sales'] = max( 0, (int) $row['total_sales'] );
        }

        if ( ! empty( $row['dimensions'] ) ) {
            $dims = json_decode( $row['dimensions'], true );
            if ( is_array( $dims ) ) {
                $this->data['dimensions'] = wp_parse_args( $dims, $this->data['dimensions'] );
            }
        }

        if ( ! empty( $row['gallery_ids'] ) ) {
            $gallery = json_decode( $row['gallery_ids'], true );
            if ( is_array( $gallery ) ) {
                $this->data['gallery_ids'] = array_map( 'absint', $gallery );
            }
        }
    }

    /**
     * Populate product data from a pre-fetched database row.
     *
     * @param array $row Database row as associative array.
     * @return void
     */
    private function populate( array $row ): void {
        $this->map_row( $row );
    }

    /**
     * Read product data from the database.
     *
     * @return void
     */
    protected function read() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $this->id ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            $this->id = 0;
            return;
        }

        $this->map_row( $row );
    }

    /**
     * Get product ID.
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get product name.
     *
     * @return string
     */
    public function get_name() {
        return $this->data['name'];
    }

    /**
     * Get product slug.
     *
     * @return string
     */
    public function get_slug() {
        return $this->data['slug'];
    }

    /**
     * Get product type. Must be implemented by subclasses.
     *
     * @return string
     */
    abstract public function get_type();

    /**
     * Get product status.
     *
     * @return string
     */
    public function get_status() {
        return $this->data['status'];
    }

    /**
     * Get product description.
     *
     * @return string
     */
    public function get_description() {
        return $this->data['description'];
    }

    /**
     * Get product short description.
     *
     * @return string
     */
    public function get_short_description() {
        return $this->data['short_description'];
    }

    /**
     * Get product SKU.
     *
     * @return string
     */
    public function get_sku() {
        return $this->data['sku'];
    }

    /**
     * Get product price (filtered).
     *
     * Honours the scheduled sale window when set: the sale price only
     * applies inside [_sale_price_dates_from, _sale_price_dates_to].
     *
     * A numeric-zero sale_price (e.g. "0" or "0.0000") is treated as
     * unset so the product is not silently rendered as free.
     *
     * @return string
     */
    public function get_price() {
        $sale          = $this->data['sale_price'];
        $has_sale_price = ( '' !== $sale ) && ( ! is_numeric( $sale ) || (float) $sale > 0 );

        $price = ( $has_sale_price && $this->is_sale_active() )
            ? $sale
            : $this->data['price'];

        return apply_filters( 'tejcart_product_get_price', $price, $this );
    }

    /**
     * Whether the sale window (if any) currently covers the given timestamp.
     *
     * Returns true when no window is configured — i.e., an always-on sale.
     *
     * @param int|null $now Unix timestamp. Defaults to current time.
     * @return bool
     */
    public function is_sale_active( ?int $now = null ): bool {
        if ( null === $now ) {
            $now = time();
        }

        $from = $this->id ? (int) $this->get_meta( '_sale_price_dates_from' ) : 0;
        $to   = $this->id ? (int) $this->get_meta( '_sale_price_dates_to' ) : 0;

        if ( $from > 0 && $now < $from ) {
            return false;
        }
        if ( $to > 0 && $now > $to ) {
            return false;
        }

        return true;
    }

    /**
     * Get the scheduled sale start timestamp (0 when unset).
     *
     * @return int
     */
    public function get_sale_date_from(): int {
        return $this->id ? (int) $this->get_meta( '_sale_price_dates_from' ) : 0;
    }

    /**
     * Get the scheduled sale end timestamp (0 when unset).
     *
     * @return int
     */
    public function get_sale_date_to(): int {
        return $this->id ? (int) $this->get_meta( '_sale_price_dates_to' ) : 0;
    }

    /**
     * Get sale price.
     *
     * Legacy rows may contain a numeric-zero sale_price (e.g. "0.0000")
     * that should be surfaced as "no sale price" — otherwise admin forms
     * and API consumers treat the product as free.
     *
     * @return string
     */
    public function get_sale_price() {
        $sale = $this->data['sale_price'];

        if ( '' !== $sale && is_numeric( $sale ) && 0.0 === (float) $sale ) {
            return '';
        }

        /**
         * Filter the product sale price.
         *
         * Mirrors `tejcart_product_get_price` so a multi-currency switcher
         * can convert the struck-through sale figure shown in the catalog
         * into the active display currency. Receives the raw base-currency
         * value (numeric string or '' when no sale price is set); a hook
         * that converts must preserve the "empty means no sale" contract by
         * returning '' (or a non-numeric value) unchanged.
         *
         * @since 1.0.0
         * @param string                                  $sale Base-currency sale price (numeric string or '').
         * @param \TejCart\Product\Product_Types\Abstract_Product $this Product instance.
         */
        return apply_filters( 'tejcart_product_get_sale_price', $sale, $this );
    }

    /**
     * Get regular price.
     *
     * @return string
     */
    public function get_regular_price() {
        /**
         * Filter the product regular (non-sale) price.
         *
         * Mirrors `tejcart_product_get_price` so a multi-currency switcher
         * can convert the regular figure (used for the struck-through
         * "was" price and sale-percentage badges) into the active display
         * currency. Receives the raw base-currency value.
         *
         * @since 1.0.0
         * @param string                                  $price Base-currency regular price.
         * @param \TejCart\Product\Product_Types\Abstract_Product $this  Product instance.
         */
        return apply_filters( 'tejcart_product_get_regular_price', $this->data['price'], $this );
    }

    /**
     * Whether the product is currently on sale.
     *
     * TejCart treats a product as on-sale when it has a non-empty
     * sale_price that is strictly less than the regular price. Stores
     * with scheduled sale windows can layer their own logic on top via
     * the `tejcart_product_is_on_sale` filter.
     *
     * @return bool
     */
    public function is_on_sale() {
        // C-H4: read through the price accessors rather than the raw
        // `data` columns. Variable_Product / Bundle_Product derive their
        // regular and sale prices from their children and leave the
        // parent's `data['price']`/`data['sale_price']` columns empty, so
        // the previous raw-column comparison always reported
        // `false` for those types (broken sale badges + the JSON-LD
        // priceValidUntil branch). Simple products are unaffected:
        // their accessors return the same `data` columns.
        $sale    = $this->get_sale_price();
        $regular = $this->get_regular_price();

        $on_sale = ( '' !== $sale )
            && is_numeric( $sale )
            && is_numeric( $regular )
            && (float) $sale > 0
            && (float) $sale < (float) $regular
            && $this->is_sale_active();

        /**
         * Filter whether a product is currently on sale.
         *
         * @param bool              $on_sale Whether the product is on sale.
         * @param Abstract_Product  $product The product instance.
         */
        return (bool) apply_filters( 'tejcart_product_is_on_sale', $on_sale, $this );
    }

    /**
     * Whether this product is "sold individually" — only one unit per cart.
     *
     * Backed by the `_sold_individually` meta toggle. Cart logic should
     * cap the quantity at 1 and reject adding a second unit.
     *
     * @return bool
     */
    public function is_sold_individually(): bool {
        $sold = ! empty( $this->data['sold_individually'] );
        if ( ! $sold && $this->id ) {
            $flag = (string) $this->get_meta( '_sold_individually' );
            $sold = in_array( $flag, array( 'yes', '1', 'true' ), true );
        }

        /**
         * Filter whether a product is sold individually.
         *
         * @param bool             $sold    Whether only one unit may be bought.
         * @param Abstract_Product $product The product instance.
         */
        return (bool) apply_filters( 'tejcart_product_is_sold_individually', $sold, $this );
    }

    /**
     * Catalog visibility: one of visible (catalog + search), catalog,
     * search, or hidden. Defaults to `visible` when no value is stored.
     *
     * @return string
     */
    public function get_catalog_visibility(): string {
        $value   = (string) ( $this->data['catalog_visibility'] ?? '' );
        if ( '' === $value && $this->id ) {
            $value = (string) $this->get_meta( '_catalog_visibility' );
        }
        $allowed = array( 'visible', 'catalog', 'search', 'hidden' );
        if ( ! in_array( $value, $allowed, true ) ) {
            $value = 'visible';
        }

        /**
         * Filter a product's catalog visibility.
         *
         * @param string           $value   Visibility: visible|catalog|search|hidden.
         * @param Abstract_Product $product The product instance.
         */
        return (string) apply_filters( 'tejcart_product_catalog_visibility', $value, $this );
    }

    /**
     * Whether the product should appear in catalog (shop / category) listings.
     *
     * @return bool
     */
    public function is_visible_in_catalog(): bool {
        $visibility = $this->get_catalog_visibility();
        return in_array( $visibility, array( 'visible', 'catalog' ), true );
    }

    /**
     * Whether the product should appear in search results.
     *
     * @return bool
     */
    public function is_visible_in_search(): bool {
        $visibility = $this->get_catalog_visibility();
        return in_array( $visibility, array( 'visible', 'search' ), true );
    }

    /**
     * Build a public URL that points at this product.
     *
     * TejCart products live in a custom table (not a CPT), so there's
     * no native WP permalink. Instead we append ?product=<id> to the
     * configured Shop page — the same pattern used by the breadcrumbs
     * and schema.org renderers — falling back to the site home when no
     * Shop page is configured yet.
     *
     * @return string
     */
    public function get_permalink() {
        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        $shop_base    = $shop_page_id ? get_permalink( $shop_page_id ) : '';
        $base         = ( '' !== $shop_base && false !== $shop_base ) ? $shop_base : home_url( '/' );

        $slug = (string) $this->data['slug'];

        $shop_is_front_page = $shop_page_id > 0
            && (int) get_option( 'page_on_front', 0 ) === $shop_page_id;

        $use_pretty = '' !== (string) get_option( 'permalink_structure', '' )
            && '' !== $slug
            && $shop_page_id > 0
            && '' !== $shop_base
            && false !== $shop_base;

        if ( $use_pretty && $shop_is_front_page ) {
            $url = home_url( '/' . \TejCart\Frontend\Product_Permalinks::get_front_page_prefix() . '/' . $slug . '/' );
        } elseif ( $use_pretty ) {
            $url = trailingslashit( $base ) . $slug . '/';
        } else {
            $url = add_query_arg( 'product', $this->get_id(), $base );
        }

        /**
         * Filter the public-facing URL for a product.
         *
         * @param string            $url     Resolved product URL.
         * @param Abstract_Product  $product The product instance.
         */
        return (string) apply_filters( 'tejcart_product_permalink', $url, $this );
    }

    /**
     * Get stock quantity.
     *
     * @return int|null
     */
    public function get_stock_quantity() {
        return $this->data['stock_quantity'];
    }

    /**
     * Get stock status.
     *
     * @return string
     */
    public function get_stock_status() {
        return $this->data['stock_status'];
    }

    /**
     * Check if the product is in stock.
     *
     * @return bool
     */
    public function is_in_stock() {
        return 'instock' === $this->data['stock_status'];
    }

    /**
     * Check if the product is purchasable (filtered).
     *
     * @return bool
     */
    public function is_purchasable() {
        $purchasable = $this->get_price() !== '' && 'publish' === $this->get_status() && $this->is_in_stock();

        return apply_filters( 'tejcart_product_is_purchasable', $purchasable, $this );
    }

    /**
     * Get manage stock flag.
     *
     * @return bool
     */
    public function get_manage_stock() {
        return (bool) $this->data['manage_stock'];
    }

    /**
     * Check if the product is virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        return (bool) $this->data['virtual'];
    }

    /**
     * Whether this product needs to be shipped.
     *
     * Concrete physical types override this to hard-code `true`. The default
     * here returns the registry's per-type default (with the per-row
     * `virtual` column inverting it for digital/virtual rows). Third-party
     * product types (subscription, pre-order, deposit) get sensible
     * shipping behaviour without needing to override.
     *
     * @return bool
     */
    public function needs_shipping() {
        $type   = $this->get_type();
        $needs  = \TejCart\Product\Product_Type_Registry::type_needs_shipping( $type );
        $result = $needs && ! $this->is_virtual();

        /**
         * Filter whether a product needs shipping.
         *
         * Useful for addons that flag a normally-physical product as
         * non-shipping (gift card, donation, deposit).
         *
         * @param bool             $result  Whether the product needs shipping.
         * @param Abstract_Product $product Product instance.
         */
        return (bool) apply_filters( 'tejcart_product_needs_shipping', $result, $this );
    }

    /**
     * Whether this product type advertises a feature.
     *
     * Templates and integrations can branch on capability rather than type
     * slug, e.g. `if ( $product->supports( 'recurring' ) )` instead of
     * `if ( 'subscription' === $product->get_type() )`.
     *
     * @param string $feature Feature key (e.g. `inventory`, `recurring`,
     *                        `downloads`, `derived_price`).
     * @return bool
     */
    public function supports( string $feature ): bool {
        return \TejCart\Product\Product_Type_Registry::type_supports( $this->get_type(), $feature );
    }

    /**
     * Check if the product is downloadable.
     *
     * @return bool
     */
    public function is_downloadable() {
        return (bool) $this->data['downloadable'];
    }

    /**
     * Get main image ID.
     *
     * @return int
     */
    public function get_image_id() {
        return $this->data['image_id'];
    }

    /**
     * Get gallery image IDs.
     *
     * @return int[]
     */
    public function get_gallery_ids() {
        return $this->data['gallery_ids'];
    }

    /**
     * Get product weight.
     *
     * @return string
     */
    public function get_weight() {
        return $this->data['weight'];
    }

    /**
     * Get product dimensions.
     *
     * @return array{length: string, width: string, height: string}
     */
    public function get_dimensions() {
        return $this->data['dimensions'];
    }

    /**
     * Set product name.
     *
     * @param string $name Product name.
     * @return void
     */
    public function set_name( $name ) {
        $this->data['name'] = sanitize_text_field( $name );
    }

    /**
     * Set product slug.
     *
     * @param string $slug Product slug.
     * @return void
     */
    public function set_slug( $slug ) {
        $this->data['slug'] = sanitize_title( $slug );
    }

    /**
     * Set product status.
     *
     * @param string $status Product status.
     * @return void
     */
    public function set_status( $status ) {
        $allowed = array( 'publish', 'draft', 'pending', 'private', 'trash' );
        $status  = sanitize_text_field( $status );
        if ( in_array( $status, $allowed, true ) ) {
            $this->data['status'] = $status;
        }
    }

    /**
     * Set product description.
     *
     * @param string $description Product description.
     * @return void
     */
    public function set_description( $description ) {
        $this->data['description'] = wp_kses_post( $description );
    }

    /**
     * Set product short description.
     *
     * @param string $short_description Product short description.
     * @return void
     */
    public function set_short_description( $short_description ) {
        $this->data['short_description'] = wp_kses_post( $short_description );
    }

    /**
     * Set product SKU.
     *
     * @param string $sku Product SKU.
     * @return void
     */
    public function set_sku( $sku ) {
        $this->data['sku'] = sanitize_text_field( $sku );
    }

    /**
     * Set the regular price.
     *
     * Sanitises the value, rejects negatives / non-numeric input, and — as
     * a secondary invariant — clears `sale_price` if the new regular price
     * would invalidate it (i.e. `sale_price >= price`). Keeps the sale-on
     * / sale-off state self-consistent without a second round-trip through
     * the admin form.
     *
     * @param string $price Regular price.
     * @return void
     */
    public function set_price( $price ) {
        if ( '' !== $price && ( ! is_numeric( $price ) || (float) $price < 0 ) ) {
            return;
        }
        $this->data['price'] = sanitize_text_field( $price );

        // F-PCA-014: Use integer minor-unit comparison so currencies with
        // non-2-decimal precision (JPY, KWD, BHD, OMR) are handled correctly.
        // (float) comparison could silently misclassify a valid sale price
        // for those currencies. The shop currency is used as a best-effort
        // proxy; price strings stored on the product carry no currency metadata.
        $sale = (string) ( $this->data['sale_price'] ?? '' );
        if ( '' !== $sale && is_numeric( $sale ) && is_numeric( $this->data['price'] ) ) {
            $currency     = (string) get_option( 'tejcart_currency', 'USD' );
            $sale_minor   = Currency::to_minor_units( $sale, $currency );
            $price_minor  = Currency::to_minor_units( (string) $this->data['price'], $currency );
            if ( $sale_minor >= $price_minor ) {
                $this->data['sale_price'] = '';
            }
        }
    }

    /**
     * Set the sale price.
     *
     * Enforces:
     *   - Empty string clears the sale.
     *   - Must be numeric and non-negative, otherwise ignored.
     *   - Must be strictly less than the current regular price. A value
     *     that isn't is coerced to '' (sale cleared) and — under WP_DEBUG —
     *     _doing_it_wrong() flags the call so the admin / API caller can
     *     correct their input.
     *
     * @param string $sale_price Sale price.
     * @return void
     */
    public function set_sale_price( $sale_price ) {
        if ( '' !== $sale_price && ( ! is_numeric( $sale_price ) || (float) $sale_price < 0 ) ) {
            return;
        }

        $sale_price = sanitize_text_field( $sale_price );

        if ( '' !== $sale_price && is_numeric( $sale_price ) && 0.0 === (float) $sale_price ) {
            $this->data['sale_price'] = '';
            return;
        }

        $regular = (string) ( $this->data['price'] ?? '' );
        if ( '' !== $sale_price && '' !== $regular && is_numeric( $regular ) ) {
            // F-PCA-014: Compare as integer minor units to handle JPY (×1),
            // standard currencies (×100), and three-decimal currencies (KWD/BHD/OMR ×1000).
            $currency    = (string) get_option( 'tejcart_currency', 'USD' );
            $sale_minor  = Currency::to_minor_units( $sale_price, $currency );
            $reg_minor   = Currency::to_minor_units( $regular, $currency );
            if ( $sale_minor >= $reg_minor ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    _doing_it_wrong(
                        __METHOD__,
                        sprintf(
                            /* translators: 1: sale price, 2: regular price */
                            esc_html__( 'Sale price (%1$s) must be less than the regular price (%2$s). Sale price cleared.', 'tejcart' ),
                            esc_html( (string) $sale_price ),
                            esc_html( $regular )
                        ),
                        '1.0.0'
                    );
                }
                $this->data['sale_price'] = '';
                return;
            }
        }

        $this->data['sale_price'] = $sale_price;
    }

    /**
     * Set stock quantity.
     *
     * @param int|null $quantity Stock quantity.
     * @return void
     */
    public function set_stock_quantity( $quantity ) {
        if ( is_null( $quantity ) ) {
            $this->data['stock_quantity'] = null;
            return;
        }

        $this->data['stock_quantity'] = min( absint( $quantity ), 999999 );
    }

    /**
     * Set stock status.
     *
     * @param string $status Stock status (instock, outofstock, onbackorder).
     * @return void
     */
    public function set_stock_status( $status ) {
        $allowed = array( 'instock', 'outofstock', 'onbackorder' );
        $status  = sanitize_text_field( $status );
        if ( in_array( $status, $allowed, true ) ) {
            $this->data['stock_status'] = $status;
        }
    }

    /**
     * Get the backorder mode for this product.
     *
     * Stored in product meta as `_backorders`. Possible values:
     *   - 'no'     : backorders not allowed (default)
     *   - 'notify' : backorders allowed, customer is notified at cart/checkout
     *   - 'yes'    : backorders allowed silently
     *
     * @return string
     */
    public function get_backorders() {
        $value = (string) ( $this->data['backorders'] ?? '' );
        if ( '' === $value && $this->id ) {
            $value = (string) $this->get_meta( '_backorders' );
        }
        return in_array( $value, array( 'no', 'notify', 'yes' ), true ) ? $value : 'no';
    }

    /**
     * Whether this product accepts backorders (notify or yes).
     *
     * @return bool
     */
    public function backorders_allowed() {
        return 'no' !== $this->get_backorders();
    }

    /**
     * Whether the customer should be notified that an item is on backorder.
     *
     * @return bool
     */
    public function backorders_require_notification() {
        return 'notify' === $this->get_backorders();
    }

    /**
     * Persist the backorder mode for this product.
     *
     * @param string $mode no|notify|yes
     * @return bool
     */
    public function set_backorders( $mode ) {
        $mode = in_array( $mode, array( 'no', 'notify', 'yes' ), true ) ? $mode : 'no';
        $this->data['backorders'] = $mode;

        return $this->update_meta( '_backorders', $mode );
    }

    /**
     * Get tax class slug.
     *
     * Returns the empty string for the default (standard) class. Consumers
     * that need the label should look it up via Tax_Manager::get_tax_classes().
     *
     * @return string
     */
    public function get_tax_class() {
        $value = (string) ( $this->data['tax_class'] ?? '' );
        if ( '' === $value && $this->id ) {
            $value = (string) $this->get_meta( '_tax_class' );
        }

        /**
         * Filter a product's tax class slug.
         *
         * @param string           $value   Tax class slug ('' = standard).
         * @param Abstract_Product $product The product instance.
         */
        return (string) apply_filters( 'tejcart_product_tax_class', $value, $this );
    }

    /**
     * Set tax class slug.
     *
     * Accepts any string; validation against configured tax classes is the
     * caller's responsibility so addons can pre-register classes.
     *
     * @param string $tax_class Tax class slug.
     * @return bool
     */
    public function set_tax_class( $tax_class ) {
        $tax_class = sanitize_text_field( (string) $tax_class );
        $this->data['tax_class'] = $tax_class;
        return $this->update_meta( '_tax_class', $tax_class );
    }

    /**
     * Get shipping class slug, or empty string when unset.
     *
     * @return string
     */
    public function get_shipping_class() {
        $value = (string) ( $this->data['shipping_class'] ?? '' );
        if ( '' === $value && $this->id ) {
            $value = (string) $this->get_meta( '_shipping_class' );
        }
        return (string) apply_filters( 'tejcart_product_shipping_class', $value, $this );
    }

    /**
     * Set shipping class slug (must be a term slug in the shipping class taxonomy).
     *
     * @param string $shipping_class
     * @return bool
     */
    public function set_shipping_class( $shipping_class ) {
        $clean = sanitize_key( (string) $shipping_class );
        $this->data['shipping_class'] = $clean;
        return $this->update_meta( '_shipping_class', $clean );
    }

    /**
     * Minimum purchase quantity for this product. 1 when unset.
     *
     * @return int
     */
    public function get_min_purchase_quantity() {
        $value = (int) ( $this->data['min_purchase_quantity'] ?? 0 );
        if ( $value <= 0 && $this->id ) {
            $value = (int) $this->get_meta( '_min_purchase_quantity' );
        }
        return $value > 0 ? $value : 1;
    }

    /**
     * Persist the minimum purchase quantity.
     *
     * @param int $qty
     * @return bool
     */
    public function set_min_purchase_quantity( $qty ) {
        $qty = max( 1, (int) $qty );
        $this->data['min_purchase_quantity'] = $qty;
        return $this->update_meta( '_min_purchase_quantity', $qty );
    }

    /**
     * Maximum purchase quantity. 0 means no limit.
     *
     * @return int
     */
    public function get_max_purchase_quantity() {
        if ( array_key_exists( 'max_purchase_quantity', $this->data ) ) {
            return max( 0, (int) $this->data['max_purchase_quantity'] );
        }
        $value = $this->id ? (int) $this->get_meta( '_max_purchase_quantity' ) : 0;
        return max( 0, $value );
    }

    /**
     * Persist the maximum purchase quantity. Pass 0 for "no limit".
     *
     * @param int $qty
     * @return bool
     */
    public function set_max_purchase_quantity( $qty ) {
        $qty = max( 0, (int) $qty );
        $this->data['max_purchase_quantity'] = $qty;
        return $this->update_meta( '_max_purchase_quantity', $qty );
    }

    /**
     * Persist the sold-individually flag.
     *
     * @param bool $sold
     * @return bool
     */
    public function set_sold_individually( $sold ) {
        $this->data['sold_individually'] = (bool) $sold;
        return $this->update_meta( '_sold_individually', $sold ? 'yes' : 'no' );
    }

    /**
     * Whether this product is flagged as featured.
     *
     * @return bool
     */
    public function is_featured() {
        $val = ! empty( $this->data['featured'] );
        if ( ! $val && $this->id ) {
            $value = (string) $this->get_meta( '_featured' );
            $val   = in_array( $value, array( '1', 'yes', 'true' ), true );
        }
        return (bool) apply_filters( 'tejcart_product_is_featured', $val, $this );
    }

    /**
     * Persist the featured flag.
     *
     * @param bool $featured
     * @return bool
     */
    public function set_featured( $featured ) {
        if ( 'variation' === $this->get_type() ) {
            $featured = false;
        }
        $featured               = (bool) $featured;
        $this->data['featured'] = $featured;

        if ( $this->id > 0 ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'tejcart_products',
                array( 'featured' => $featured ? 1 : 0 ),
                array( 'id' => $this->id ),
                array( '%d' ),
                array( '%d' )
            );
        }

        return $this->update_meta( '_featured', $featured ? '1' : '0' );
    }

    /**
     * Persist the catalog visibility mode.
     *
     * @param string $visibility visible|catalog|search|hidden
     * @return bool
     */
    public function set_catalog_visibility( $visibility ) {
        $allowed = array( 'visible', 'catalog', 'search', 'hidden' );
        $value   = in_array( $visibility, $allowed, true ) ? $visibility : 'visible';
        $this->data['catalog_visibility'] = $value;
        return $this->update_meta( '_catalog_visibility', $value );
    }

    /**
     * Total recorded sales for this product. Surfaced for "best sellers"
     * sorting and reports.
     *
     * @return int
     */
    public function get_total_sales(): int {
        return max( 0, (int) ( $this->data['total_sales'] ?? 0 ) );
    }

    /**
     * Persist the sale start timestamp (0 clears).
     *
     * @param int $timestamp
     * @return bool
     */
    public function set_sale_date_from( $timestamp ) {
        return $this->update_meta( '_sale_price_dates_from', max( 0, (int) $timestamp ) );
    }

    /**
     * Persist the sale end timestamp (0 clears).
     *
     * @param int $timestamp
     * @return bool
     */
    public function set_sale_date_to( $timestamp ) {
        return $this->update_meta( '_sale_price_dates_to', max( 0, (int) $timestamp ) );
    }

    /**
     * Set manage stock flag.
     *
     * @param bool $manage Whether to manage stock.
     * @return void
     */
    public function set_manage_stock( $manage ) {
        $this->data['manage_stock'] = (bool) $manage;
    }

    /**
     * Set product weight.
     *
     * @param string $weight Product weight.
     * @return void
     */
    public function set_weight( $weight ) {
        $this->data['weight'] = sanitize_text_field( $weight );
    }

    /**
     * Set product dimensions.
     *
     * @param array $dimensions Associative array with length, width, height.
     * @return void
     */
    public function set_dimensions( $dimensions ) {
        $this->data['dimensions'] = wp_parse_args( $dimensions, array(
            'length' => '',
            'width'  => '',
            'height' => '',
        ) );
    }

    /**
     * Set main image ID.
     *
     * @param int $image_id Image attachment ID.
     * @return void
     */
    public function set_image_id( $image_id ) {
        $this->data['image_id'] = absint( $image_id );
    }

    /**
     * Set gallery image IDs.
     *
     * @param int[] $gallery_ids Array of attachment IDs.
     * @return void
     */
    public function set_gallery_ids( $gallery_ids ) {
        $this->data['gallery_ids'] = array_map( 'absint', (array) $gallery_ids );
    }

    /**
     * Set downloadable flag.
     *
     * @param bool $downloadable Whether product is downloadable.
     * @return void
     */
    public function set_downloadable( $downloadable ) {
        $this->data['downloadable'] = (bool) $downloadable;
    }

    /**
     * Set virtual flag.
     *
     * @param bool $virtual Whether product is virtual.
     * @return void
     */
    public function set_virtual( $virtual ) {
        $this->data['virtual'] = (bool) $virtual;
    }

    /**
     * Save (insert or update) the product to the database.
     *
     * @return int|false Product ID on success, false on failure.
     */
    public function save() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        $this->coerce_stock_status();

        $desired_slug              = $this->data['slug'] ?: $this->data['name'];
        $this->data['slug']        = \TejCart\Product\Product_Factory::generate_unique_slug( (string) $desired_slug, (int) $this->id );

        $enforce_unique_sku = (bool) apply_filters( 'tejcart_product_enforce_unique_sku', true, $this );
        if ( $enforce_unique_sku && ! empty( $this->data['sku'] ) ) {
            $conflict_id = \TejCart\Product\Product_Factory::sku_exists( (string) $this->data['sku'], (int) $this->id );
            if ( $conflict_id > 0 ) {
                $this->last_save_error = new \WP_Error(
                    'tejcart_duplicate_sku',
                    sprintf(
                        /* translators: %s: SKU value */
                        __( 'SKU "%s" is already used by another product.', 'tejcart' ),
                        $this->data['sku']
                    ),
                    array( 'status' => 409, 'conflict_product_id' => $conflict_id )
                );
                return false;
            }
        }

        $sku_for_db = ( null === $this->data['sku'] || '' === (string) $this->data['sku'] )
            ? null
            : $this->data['sku'];

        $db_data = array(
            'name'                  => $this->data['name'],
            'slug'                  => $this->data['slug'],
            'type'                  => $this->get_type(),
            'status'                => $this->data['status'],
            'description'           => $this->data['description'],
            'short_description'     => $this->data['short_description'],
            'sku'                   => $sku_for_db,
            'price'                 => $this->data['price'],
            'sale_price'            => $this->data['sale_price'],
            'stock_quantity'        => $this->data['stock_quantity'],
            'stock_status'          => $this->data['stock_status'],
            'manage_stock'          => $this->data['manage_stock'] ? 1 : 0,
            'backorders'            => (string) ( $this->data['backorders'] ?? 'no' ),
            'sold_individually'     => ! empty( $this->data['sold_individually'] ) ? 1 : 0,
            'min_purchase_quantity' => max( 1, (int) ( $this->data['min_purchase_quantity'] ?? 1 ) ),
            'max_purchase_quantity' => max( 0, (int) ( $this->data['max_purchase_quantity'] ?? 0 ) ),
            'weight'                => $this->data['weight'],
            'dimensions'            => wp_json_encode( $this->data['dimensions'] ),
            'tax_class'             => (string) ( $this->data['tax_class'] ?? '' ),
            'shipping_class'        => (string) ( $this->data['shipping_class'] ?? '' ),
            'catalog_visibility'    => (string) ( $this->data['catalog_visibility'] ?? 'visible' ),
            'featured'              => ! empty( $this->data['featured'] ) ? 1 : 0,
            'image_id'              => $this->data['image_id'],
            'gallery_ids'           => wp_json_encode( $this->data['gallery_ids'] ),
            'downloadable'          => $this->data['downloadable'] ? 1 : 0,
            'virtual'               => $this->data['virtual'] ? 1 : 0,
        );

        $format = array(
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%d', '%s', '%d',
            '%s', '%d', '%d', '%d',
            '%s', '%s',
            '%s', '%s', '%s', '%d',
            '%d', '%s', '%d', '%d',
        );

        if ( $this->id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->update( $table, $db_data, array( 'id' => $this->id ), $format, array( '%d' ) );

            if ( false !== $result ) {
                wp_cache_delete( 'tejcart_product_' . $this->id, 'tejcart' );
                wp_cache_delete( 'tejcart_product_summary_counts', 'tejcart' );

                /**
                 * Fires after an existing product row has been updated.
                 * Listeners (webhooks, search re-index) hook into this.
                 *
                 * @param int               $product_id The product ID.
                 * @param Abstract_Product  $product    The product instance.
                 */
                do_action( 'tejcart_product_updated', (int) $this->id, $this );

                /**
                 * Fires after a product row is persisted, on both create
                 * and update. Listeners that need a single "the product
                 * changed" seam (search re-index, cache invalidation)
                 * hook here instead of subscribing to both the
                 * create and update actions separately.
                 *
                 * @param int               $product_id The product ID.
                 * @param Abstract_Product  $product    The product instance.
                 * @param bool              $is_update  True when an existing row was updated, false on insert.
                 */
                do_action( 'tejcart_product_saved', (int) $this->id, $this, true );

                return $this->id;
            }

            return false;
        }

        $db_data['created_at'] = current_time( 'mysql' );
        $format[]              = '%s';

        $original_slug    = (string) $db_data['slug'];
        $slug_attempt     = 1;
        $max_slug_retries = 10;

        do {
            $previous_show = $wpdb->show_errors( false );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result        = $wpdb->insert( $table, $db_data, $format );
            $wpdb->show_errors( $previous_show );

            if ( false !== $result ) {
                break;
            }

            $is_dup_slug = false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' )
                && false !== stripos( (string) $wpdb->last_error, "key 'slug" );

            if ( ! $is_dup_slug || $slug_attempt >= $max_slug_retries ) {
                return false;
            }

            $slug_attempt++;
            $db_data['slug']    = $original_slug . '-' . $slug_attempt;
            $this->data['slug'] = $db_data['slug'];
        } while ( $slug_attempt <= $max_slug_retries );

        $this->id = (int) $wpdb->insert_id;

        wp_cache_delete( 'tejcart_product_' . $this->id, 'tejcart' );

        /**
         * Fires immediately after a brand-new product row has been inserted.
         *
         * @param int               $product_id The new product ID.
         * @param Abstract_Product  $product    The product instance.
         */
        do_action( 'tejcart_product_created', (int) $this->id, $this );

        /**
         * Fires after a product row is persisted, on both create and
         * update. See the matching call in the update branch above for
         * the contract — listeners needing a single "saved" seam (search
         * re-index, cache invalidation) hook here.
         *
         * @param int               $product_id The product ID.
         * @param Abstract_Product  $product    The product instance.
         * @param bool              $is_update  True when an existing row was updated, false on insert.
         */
        do_action( 'tejcart_product_saved', (int) $this->id, $this, false );

        return $this->id;
    }

    /**
     * Get the WP_Error produced by the most recent failed save(), if any.
     *
     * @return \WP_Error|null
     */
    public function get_last_save_error() {
        return $this->last_save_error;
    }

    /**
     * Keep stock_status consistent with stock_quantity on save.
     *
     * Only runs when stock is being managed. Two rules, both symmetric:
     *   - qty <= 0 AND backorders disabled → outofstock.
     *   - qty > 0  AND currently outofstock → instock.
     *
     * Storefronts with bespoke workflows can disable this coercion by
     * returning false from the tejcart_auto_stock_status_coercion filter.
     *
     * @return void
     */
    protected function coerce_stock_status(): void {
        /**
         * Disable the automatic stock_status coercion.
         *
         * @param bool             $enabled Default true.
         * @param Abstract_Product $product Product being saved.
         */
        $enabled = (bool) apply_filters( 'tejcart_auto_stock_status_coercion', true, $this );
        if ( ! $enabled ) {
            return;
        }

        if ( empty( $this->data['manage_stock'] ) ) {
            return;
        }

        $qty             = $this->data['stock_quantity'];
        $has_qty         = null !== $qty;
        $current_status  = (string) ( $this->data['stock_status'] ?? 'instock' );
        $backorders_off  = 'no' === $this->get_backorders();

        if ( $has_qty && (int) $qty <= 0 && $backorders_off ) {
            $this->data['stock_status'] = 'outofstock';
            return;
        }

        if ( $has_qty && (int) $qty > 0 && 'outofstock' === $current_status ) {
            $this->data['stock_status'] = 'instock';
        }
    }

    /**
     * Delete the product from the database.
     *
     * @return bool True on success, false on failure.
     */
    public function delete() {
        global $wpdb;

        if ( ! $this->id ) {
            return false;
        }

        $table      = $wpdb->prefix . 'tejcart_products';
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';
        $deleted_id = $this->id;
        $is_variable_parent = ( 'variable' === $this->get_type() );

        $variation_ids = array();
        if ( $is_variable_parent ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $variation_ids = (array) $wpdb->get_col(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT product_id FROM {$meta_table}
                     WHERE meta_key = '_variation_parent_id' AND meta_value = %s",
                    (string) $deleted_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            foreach ( $variation_ids as $variation_id ) {
                $variation = \TejCart\Product\Product_Factory::get_product( (int) $variation_id );
                if ( $variation ) {
                    $variation->delete();
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete( $meta_table, array( 'product_id' => $this->id ), array( '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->delete( $table, array( 'id' => $this->id ), array( '%d' ) );

        if ( false !== $result ) {
            wp_cache_delete( 'tejcart_product_' . $deleted_id, 'tejcart' );
            wp_cache_delete( 'tejcart_product_summary_counts', 'tejcart' );
            $this->id = 0;

            /**
             * Fires after a product row + its meta have been deleted.
             * Webhook subscribers receive the product.deleted event.
             *
             * @param int $product_id The deleted product's ID.
             */
            do_action( 'tejcart_product_deleted', (int) $deleted_id );

            return true;
        }

        return false;
    }

    /**
     * Get upsell product IDs.
     *
     * @return int[] Array of product IDs.
     */
    public function get_upsell_ids() {
        $ids = $this->get_meta( '_upsell_ids' );

        if ( is_string( $ids ) ) {
            $ids = json_decode( $ids, true );
        }

        return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
    }

    /**
     * Set upsell product IDs.
     *
     * @param int[] $ids Array of product IDs.
     * @return bool True on success.
     */
    public function set_upsell_ids( $ids ) {
        $ids = array_map( 'absint', (array) $ids );
        $ids = array_filter( $ids );

        return $this->update_meta( '_upsell_ids', wp_json_encode( array_values( $ids ) ) );
    }

    /**
     * Get cross-sell product IDs.
     *
     * @return int[] Array of product IDs.
     */
    public function get_crosssell_ids() {
        $ids = $this->get_meta( '_crosssell_ids' );

        if ( is_string( $ids ) ) {
            $ids = json_decode( $ids, true );
        }

        return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
    }

    /**
     * Set cross-sell product IDs.
     *
     * @param int[] $ids Array of product IDs.
     * @return bool True on success.
     */
    public function set_crosssell_ids( $ids ) {
        $ids = array_map( 'absint', (array) $ids );
        $ids = array_filter( $ids );

        return $this->update_meta( '_crosssell_ids', wp_json_encode( array_values( $ids ) ) );
    }

    /**
     * Get the manually-curated related product IDs (if any).
     *
     * @return int[]
     */
    public function get_related_ids() {
        $ids = $this->get_meta( '_related_ids' );

        if ( is_string( $ids ) ) {
            $ids = json_decode( $ids, true );
        }

        return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
    }

    /**
     * Persist a manual related-product list. Pass an empty array to clear
     * and fall back to category-based auto-discovery.
     *
     * @param int[] $ids
     * @return bool
     */
    public function set_related_ids( $ids ) {
        $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        return $this->update_meta( '_related_ids', wp_json_encode( $ids ) );
    }

    /**
     * Get related products.
     *
     * Prefers a manually-curated list (`_related_ids` meta) when set;
     * otherwise auto-discovers products that share at least one category.
     * In both cases self is excluded, out-of-stock items are dropped when
     * `tejcart_hide_out_of_stock` is on, and the result is randomised.
     *
     * @param int $limit Maximum number of related products. Default 4.
     * @return int[] Array of product IDs.
     */
    public function get_related_products( $limit = 4 ) {
        if ( ! $this->id ) {
            return array();
        }

        $manual_ids  = $this->get_related_ids();
        $related_ids = array();

        if ( ! empty( $manual_ids ) ) {
            $related_ids = $manual_ids;
        } else {
            $categories = \TejCart\Product\Product_Taxonomy::get_product_categories( $this->id );

            if ( ! empty( $categories ) ) {
                foreach ( $categories as $category ) {
                    $product_ids = \TejCart\Product\Product_Taxonomy::get_products_by_category(
                        $category->term_id,
                        array( 'limit' => 50 )
                    );
                    $related_ids = array_merge( $related_ids, $product_ids );
                }
            }
        }

        if ( empty( $related_ids ) ) {
            return array();
        }

        $related_ids = array_unique( array_map( 'intval', $related_ids ) );
        $related_ids = array_diff( $related_ids, array( $this->id ) );

        $related_ids = \TejCart\Product\Stock_Display::filter_in_stock_ids( array_values( $related_ids ) );

        shuffle( $related_ids );

        /**
         * Filter the related product list before slicing to $limit.
         *
         * @param int[]            $related_ids
         * @param Abstract_Product $product
         * @param bool             $is_manual  True when sourced from `_related_ids` meta.
         */
        $related_ids = (array) apply_filters( 'tejcart_product_related_ids', $related_ids, $this, ! empty( $manual_ids ) );

        return array_slice( $related_ids, 0, absint( $limit ) );
    }

    /**
     * Build a schema.org Product JSON-LD payload for this product.
     *
     * Consumers (e.g. the single-product template or a SEO module) can
     * echo the result inside a `<script type="application/ld+json">` tag.
     * Prices, availability and aggregate rating are resolved lazily so
     * callers get a valid snippet even for products without reviews.
     *
     * @return array<string, mixed>
     */
    public function get_schema_jsonld(): array {
        $price         = $this->get_price();
        $regular_price = $this->get_regular_price();
        $currency      = apply_filters( 'tejcart_currency_code', (string) get_option( 'tejcart_currency', 'USD' ) );

        switch ( $this->get_stock_status() ) {
            case 'instock':
                $availability = 'https://schema.org/InStock';
                break;
            case 'onbackorder':
                $availability = 'https://schema.org/BackOrder';
                break;
            default:
                $availability = 'https://schema.org/OutOfStock';
        }

        $offer = array(
            '@type'         => 'Offer',
            'url'           => $this->get_permalink(),
            'priceCurrency' => $currency,
            'availability'  => $availability,
        );
        if ( '' !== (string) $price ) {
            $offer['price'] = (string) $price;
        }
        if ( $this->is_on_sale() && '' !== (string) $regular_price ) {
            $sale_to = $this->get_sale_date_to();
            if ( $sale_to > 0 ) {
                $offer['priceValidUntil'] = gmdate( 'Y-m-d', $sale_to );
            }
        }

        $data = array(
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => wp_strip_all_tags( (string) $this->get_name() ),
            'description' => wp_strip_all_tags( (string) ( $this->get_short_description() ?: $this->get_description() ) ),
            'sku'         => (string) $this->get_sku(),
            'url'         => $this->get_permalink(),
            'offers'      => $offer,
        );

        $image_id = (int) $this->get_image_id();
        if ( $image_id > 0 ) {
            $src = wp_get_attachment_image_url( $image_id, 'full' );
            if ( $src ) {
                $data['image'] = $src;
            }
        }

        $reviews_class = '\\TejCart\\Product\\Product_Reviews';
        if ( class_exists( $reviews_class ) ) {
            $count = (int) call_user_func( array( $reviews_class, 'get_review_count' ), $this->get_id() );
            if ( $count > 0 ) {
                $data['aggregateRating'] = array(
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) call_user_func( array( $reviews_class, 'get_average_rating' ), $this->get_id() ),
                    'reviewCount' => $count,
                );
            }
        }

        /**
         * Filter the JSON-LD schema payload for a product.
         *
         * @param array            $data    Schema.org Product structure.
         * @param Abstract_Product $product The product instance.
         */
        return (array) apply_filters( 'tejcart_product_schema_jsonld', $data, $this );
    }

    /**
     * Get product meta value.
     *
     * @param string $key   Meta key.
     * @return mixed Meta value or null if not found.
     */
    public function get_meta( $key ) {
        global $wpdb;

        $primed = wp_cache_get( 'tejcart_product_meta_all_' . $this->id, 'tejcart' );
        if ( is_array( $primed ) ) {
            if ( array_key_exists( $key, $primed ) ) {
                return maybe_unserialize( $primed[ $key ], array( 'allowed_classes' => false ) );
            }
            return null;
        }

        $table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $value = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE product_id = %d AND meta_key = %s LIMIT 1",
                $this->id,
                $key
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return $value !== null ? maybe_unserialize( $value, array( 'allowed_classes' => false ) ) : null;
    }

    /**
     * Update or insert product meta.
     *
     * @param string $key   Meta key.
     * @param mixed  $value Meta value.
     * @return bool True on success, false on failure.
     */
    public function update_meta( $key, $value ) {
        global $wpdb;

        wp_cache_delete( 'tejcart_product_meta_all_' . $this->id, 'tejcart' );

        $table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $existing = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_id FROM {$table} WHERE product_id = %d AND meta_key = %s LIMIT 1",
                $this->id,
                $key
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        $serialized = maybe_serialize( $value );

        if ( $existing ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $result = $wpdb->update(
                $table,
                array( 'meta_value' => $serialized ),
                array( 'product_id' => $this->id, 'meta_key' => $key ),
                array( '%s' ),
                array( '%d', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $result = $wpdb->insert(
                $table,
                array(
                    'product_id' => $this->id,
                    'meta_key'   => $key,
                    'meta_value' => $serialized,
                ),
                array( '%d', '%s', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        }

        return false !== $result;
    }

    /**
     * Delete product meta.
     *
     * @param string $key Meta key.
     * @return bool True on success, false on failure.
     */
    public function delete_meta( $key ) {
        global $wpdb;

        wp_cache_delete( 'tejcart_product_meta_all_' . $this->id, 'tejcart' );

        $table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $result = $wpdb->delete(
            $table,
            array(
                'product_id' => $this->id,
                'meta_key'   => $key,
            ),
            array( '%d', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return false !== $result;
    }
}
