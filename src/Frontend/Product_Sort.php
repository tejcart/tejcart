<?php
/**
 * Product Sorting dropdown and query modifier.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides a dropdown shortcode for sorting products and modifies the
 * product query accordingly via the tejcart_product_query_args filter.
 */
class Product_Sort {
    /**
     * Cached sort option labels, keyed by sort value.
     *
     * Populated lazily on first access — populating during init() would
     * fire `__()` while WordPress is still on `plugins_loaded`, which
     * since WP 6.7 emits a "translation loading too early" notice.
     *
     * @var array<string, string>|null
     */
    private $options = null;

    /**
     * Register shortcode and hook into the product query.
     *
     * @return void
     */
    public function init(): void {
        add_shortcode( 'tejcart_product_sort', array( $this, 'shortcode' ) );
        add_filter( 'tejcart_product_query_args', array( $this, 'modify_query' ) );
    }

    /**
     * Lazily resolve the localized sort options.
     *
     * @return array<string, string>
     */
    private function get_options(): array {
        if ( null === $this->options ) {
            $this->options = array(
                'default'    => __( 'Default sorting', 'tejcart' ),
                'popularity' => __( 'Sort by popularity', 'tejcart' ),
                'rating'     => __( 'Sort by average rating', 'tejcart' ),
                'newest'     => __( 'Sort by latest', 'tejcart' ),
                'price_asc'  => __( 'Sort by price: low to high', 'tejcart' ),
                'price_desc' => __( 'Sort by price: high to low', 'tejcart' ),
            );
        }

        return $this->options;
    }

    /**
     * Shortcode callback for [tejcart_product_sort].
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Sort dropdown HTML.
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts(
            array(
                'class' => '',
            ),
            $atts,
            'tejcart_product_sort'
        );

        $css_class = $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '';
        $options   = $this->get_options();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, shareable archive sort param (read-only, no state change); value is validated against the allow-list below.
        $current = isset( $_GET['tejcart_sort'] ) ? sanitize_key( wp_unslash( $_GET['tejcart_sort'] ) ) : 'default';
        if ( ! array_key_exists( $current, $options ) ) {
            $current = 'default';
        }

        ob_start();
        ?>
        <div class="tejcart-product-sort<?php echo esc_attr( $css_class ); ?>">
            <label for="tejcart-sort-select" class="screen-reader-text">
                <?php esc_html_e( 'Sort products', 'tejcart' ); ?>
            </label>
            <select id="tejcart-sort-select" class="tejcart-sort-select" name="tejcart_sort">
                <?php foreach ( $options as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Modify the product query args based on the selected sort order.
     *
     * Hooked to the `tejcart_product_query_args` filter so it works with
     * Product_Filter AJAX queries and any other product listing.
     *
     * @param array $args Query arguments array containing 'where_sql', 'join_sql', 'values', etc.
     * @return array Modified query arguments.
     */
    public function modify_query( array $args ): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Public, shareable archive sort param (read-only, no state change); value is validated against the allow-list below.
        $sort = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : ( isset( $_GET['tejcart_sort'] ) ? sanitize_key( wp_unslash( $_GET['tejcart_sort'] ) ) : 'default' );

        if ( ! array_key_exists( $sort, $this->get_options() ) ) {
            $sort = 'default';
        }

        global $wpdb;
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';

        switch ( $sort ) {
            case 'price_asc':
                $args['order_by'] = 'CAST(p.price AS DECIMAL(10,2)) ASC';
                break;

            case 'price_desc':
                $args['order_by'] = 'CAST(p.price AS DECIMAL(10,2)) DESC';
                break;

            case 'newest':
                $args['order_by'] = 'p.created_at DESC';
                break;

            case 'rating':
                $alias = 'meta_sort_rating';
                $args['join_sql'] .= " LEFT JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = '_average_rating'";
                $args['order_by']  = "CAST({$alias}.meta_value AS DECIMAL(3,2)) DESC";
                break;

            case 'popularity':
                $alias = 'meta_sort_sales';
                $args['join_sql'] .= " LEFT JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = '_total_sales'";
                $args['order_by']  = "CAST({$alias}.meta_value AS UNSIGNED) DESC";
                break;

            case 'default':
            default:
                $args['order_by'] = 'p.created_at DESC';
                break;
        }

        return $args;
    }
}
