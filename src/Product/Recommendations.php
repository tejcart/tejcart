<?php
/**
 * Product recommendations engine.
 *
 * Provides "Customers also bought" and "Frequently bought together"
 * data with a pluggable strategy pattern. Falls back to category +
 * attribute matching when co-occurrence data is thin.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Recommendations {

    private const COOCCURRENCE_MINIMUM = 2;

    private ?Co_Occurrence_Index $index = null;

    public function init(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets(): void {
        if ( ! function_exists( 'tejcart_is_single_product' ) || ! tejcart_is_single_product() ) {
            return;
        }

        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_enqueue_style(
            'tejcart-recommendations',
            tejcart_asset_url( 'assets/css/product/recommendations.css' ),
            array( 'tejcart-public' ),
            $version
        );

        wp_enqueue_script(
            'tejcart-recommendations',
            tejcart_asset_url( 'assets/js/product/recommendations.js' ),
            array(),
            $version,
            true
        );

        wp_localize_script(
            'tejcart-recommendations',
            'TejCartRecommendations',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'tejcart_add_to_cart' ),
                'i18n'      => array(
                    'adding'   => __( 'Adding...', 'tejcart' ),
                    'added'    => __( 'Added to cart!', 'tejcart' ),
                    'addAll'   => __( 'Add selected to cart', 'tejcart' ),
                    'totalFor' => __( 'Total for selected items:', 'tejcart' ),
                ),
                'currency'  => function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : 'USD',
            )
        );
    }

    /**
     * Get "Customers also bought" product IDs for the given product.
     *
     * Strategy resolution order:
     *  1. `tejcart_recommendation_strategy` filter (drop-in for AI engine).
     *  2. Co-occurrence index when data is sufficient.
     *  3. Fallback: same-category + same-attribute matching.
     *
     * @param int $product_id The product to find recommendations for.
     * @param int $limit      Maximum results.
     * @return int[] Product IDs ordered by relevance.
     */
    public function get_also_bought( int $product_id, int $limit = 4 ): array {
        /**
         * Replace the built-in recommendation strategy entirely.
         *
         * Return a non-null array of product IDs to short-circuit the
         * co-occurrence lookup and category fallback. This is the
         * integration point for AI-driven recommendation engines (W44).
         *
         * @param int[]|null $ids        Return non-null to override. Default null.
         * @param int        $product_id The product being viewed.
         * @param int        $limit      Requested result count.
         */
        $override = apply_filters( 'tejcart_recommendation_strategy', null, $product_id, $limit );

        if ( is_array( $override ) ) {
            return array_slice( array_map( 'intval', $override ), 0, $limit );
        }

        // Try co-occurrence index.
        $cooccurrences = $this->get_index()->get_cooccurrences( $product_id, $limit * 2 );

        $ids = array();
        foreach ( $cooccurrences as $related_id => $count ) {
            if ( $count >= self::COOCCURRENCE_MINIMUM ) {
                $ids[] = $related_id;
            }
        }

        // Filter out unpublished / out-of-stock.
        $ids = $this->filter_valid_products( $ids );

        if ( count( $ids ) >= $limit ) {
            return array_slice( $ids, 0, $limit );
        }

        // Fallback: fill remaining slots with category + attribute matches.
        $fallback_ids = $this->get_category_attribute_matches( $product_id, $limit * 2 );
        $fallback_ids = array_diff( $fallback_ids, $ids, array( $product_id ) );
        $fallback_ids = $this->filter_valid_products( $fallback_ids );

        $ids = array_merge( $ids, $fallback_ids );
        $ids = array_unique( $ids );
        $ids = array_diff( $ids, array( $product_id ) );

        return array_slice( array_values( $ids ), 0, $limit );
    }

    /**
     * Get "Frequently bought together" products — the top 2-3 products
     * most often purchased alongside the given product.
     *
     * @param int $product_id The product being viewed.
     * @param int $limit      Maximum companion products (default 2).
     * @return int[] Product IDs ordered by co-occurrence count.
     */
    public function get_frequently_bought_together( int $product_id, int $limit = 2 ): array {
        /** This filter is documented in self::get_also_bought(). */
        $override = apply_filters( 'tejcart_fbt_strategy', null, $product_id, $limit );

        if ( is_array( $override ) ) {
            return array_slice( array_map( 'intval', $override ), 0, $limit );
        }

        $cooccurrences = $this->get_index()->get_cooccurrences( $product_id, $limit );

        $ids = array();
        foreach ( $cooccurrences as $related_id => $count ) {
            if ( $count >= self::COOCCURRENCE_MINIMUM ) {
                $ids[] = $related_id;
            }
        }

        $ids = $this->filter_valid_products( $ids );

        return array_slice( $ids, 0, $limit );
    }

    /**
     * Category + attribute fallback for new stores with thin co-occurrence data.
     *
     * @param int $product_id The product to match against.
     * @param int $limit      Maximum results.
     * @return int[]
     */
    private function get_category_attribute_matches( int $product_id, int $limit ): array {
        $ids = array();

        // Same-category products.
        if ( class_exists( '\\TejCart\\Product\\Product_Taxonomy' ) ) {
            $categories = Product_Taxonomy::get_product_categories( $product_id );
            foreach ( $categories as $category ) {
                $cat_products = Product_Taxonomy::get_products_by_category(
                    $category->term_id,
                    array( 'limit' => $limit )
                );
                $ids = array_merge( $ids, $cat_products );
            }
        }

        $ids = array_unique( array_map( 'intval', $ids ) );
        $ids = array_diff( $ids, array( $product_id ) );

        shuffle( $ids );

        return array_slice( $ids, 0, $limit );
    }

    /**
     * Filter out unpublished and optionally out-of-stock products.
     *
     * @param int[] $ids Product IDs.
     * @return int[]
     */
    private function filter_valid_products( array $ids ): array {
        if ( empty( $ids ) ) {
            return array();
        }

        if ( class_exists( '\\TejCart\\Product\\Stock_Display' ) ) {
            $ids = Stock_Display::filter_in_stock_ids( $ids );
        }

        return array_values( $ids );
    }

    private function get_index(): Co_Occurrence_Index {
        if ( null === $this->index ) {
            $this->index = new Co_Occurrence_Index();
        }
        return $this->index;
    }
}
