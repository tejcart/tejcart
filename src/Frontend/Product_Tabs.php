<?php
/**
 * Single-product tab registry.
 *
 * Builds the tab list shown on the single-product page (Description /
 * Additional information / Reviews) and exposes a filter so themes and
 * Tier-2 modules can add, remove, or reorder tabs without touching the
 * template.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Types\Abstract_Product;

/**
 * Assembles the tabs displayed on the single-product page.
 */
class Product_Tabs {
    /**
     * Build the ordered list of tabs to render for the given product.
     *
     * Each entry is keyed by tab id and carries:
     *
     *   - title    (string)   Visible label.
     *   - priority (int)      Sort order, ascending. Defaults: description=10,
     *                         additional_information=20, reviews=30.
     *   - callback (callable) Invoked with ( $tab_key, $product ) to print
     *                         the panel body.
     *
     * Themes filter the array via `tejcart_single_product_tabs`. Returning
     * an empty array suppresses the tab UI entirely (the template falls
     * back to no tabs).
     *
     * @param Abstract_Product $product The product being displayed.
     * @return array<string, array<string, mixed>>
     */
    public static function get( $product ): array {
        $tabs = array();

        $description = method_exists( $product, 'get_description' )
            ? (string) $product->get_description()
            : '';
        if ( '' !== trim( $description ) ) {
            $tabs['description'] = array(
                'title'    => __( 'Description', 'tejcart' ),
                'priority' => 10,
                'callback' => array( __CLASS__, 'render_description_panel' ),
            );
        }

        if ( self::has_additional_information( $product ) ) {
            $tabs['additional_information'] = array(
                'title'    => __( 'Additional information', 'tejcart' ),
                'priority' => 20,
                'callback' => array( __CLASS__, 'render_additional_information_panel' ),
            );
        }

        if ( self::reviews_enabled( $product ) ) {
            $tabs['reviews'] = array(
                'title'    => self::reviews_tab_title( $product ),
                'priority' => 30,
                'callback' => array( __CLASS__, 'render_reviews_panel' ),
            );
        }

        /**
         * Filter the single-product tab list.
         *
         * @param array<string, array<string, mixed>> $tabs    Tab descriptors keyed by id.
         * @param Abstract_Product                    $product The product being displayed.
         */
        $tabs = (array) apply_filters( 'tejcart_single_product_tabs', $tabs, $product );

        $tabs = array_filter(
            $tabs,
            static function ( $tab ) {
                return is_array( $tab )
                    && ! empty( $tab['title'] )
                    && isset( $tab['callback'] )
                    && is_callable( $tab['callback'] );
            }
        );

        uasort(
            $tabs,
            static function ( $a, $b ) {
                $pa = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
                $pb = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
                return $pa <=> $pb;
            }
        );

        return $tabs;
    }

    /**
     * Whether the product has any "Additional information" content (weight,
     * dimensions, or non-variation attributes flagged as visible).
     *
     * @param Abstract_Product $product The product being displayed.
     * @return bool
     */
    public static function has_additional_information( $product ): bool {
        $weight = method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';
        if ( '' !== $weight && (float) $weight > 0 ) {
            return true;
        }

        if ( method_exists( $product, 'get_dimensions' ) ) {
            $dimensions = (array) $product->get_dimensions();
            foreach ( $dimensions as $value ) {
                if ( '' !== (string) $value && (float) $value > 0 ) {
                    return true;
                }
            }
        }

        foreach ( self::visible_attributes( $product ) as $attr ) {
            if ( ! empty( $attr['values'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the subset of product attributes flagged as visible on the
     * front-end. Variation-only attributes are excluded (they already drive
     * the variation selectors in the summary column).
     *
     * @param Abstract_Product $product The product being displayed.
     * @return array<int, array<string, mixed>>
     */
    public static function visible_attributes( $product ): array {
        if ( ! method_exists( $product, 'get_attributes' ) ) {
            return array();
        }

        $visible = array();
        foreach ( (array) $product->get_attributes() as $attr ) {
            if ( ! is_array( $attr ) || empty( $attr['name'] ) ) {
                continue;
            }
            $is_visible       = ! array_key_exists( 'visible', $attr ) || ! empty( $attr['visible'] );
            $used_for_variant = ! empty( $attr['used_for_variations'] );
            if ( $is_visible && ! $used_for_variant ) {
                $visible[] = $attr;
            }
        }

        return $visible;
    }

    /**
     * Render the Description tab body.
     *
     * @param string           $tab_key Tab id.
     * @param Abstract_Product $product The product being displayed.
     * @return void
     */
    public static function render_description_panel( string $tab_key, $product ): void {
        tejcart_get_template(
            'product/tabs/description.php',
            array( 'product' => $product )
        );
    }

    /**
     * Render the Additional information tab body.
     *
     * @param string           $tab_key Tab id.
     * @param Abstract_Product $product The product being displayed.
     * @return void
     */
    public static function render_additional_information_panel( string $tab_key, $product ): void {
        tejcart_get_template(
            'product/tabs/additional-information.php',
            array(
                'product'    => $product,
                'attributes' => self::visible_attributes( $product ),
            )
        );
    }

    /**
     * Render the Reviews tab body.
     *
     * @param string           $tab_key Tab id.
     * @param Abstract_Product $product The product being displayed.
     * @return void
     */
    public static function render_reviews_panel( string $tab_key, $product ): void {
        tejcart_get_template(
            'product/tabs/reviews.php',
            array( 'product' => $product, 'product_id' => (int) $product->get_id() )
        );
    }

    /**
     * Whether reviews should appear as a tab. Honours the existing
     * `tejcart_enable_reviews` option and the `tejcart_show_single_product_reviews`
     * filter that the legacy inline reviews block already respected, so a
     * theme that opted out before keeps opting out.
     *
     * @param Abstract_Product $product The product being displayed.
     * @return bool
     */
    public static function reviews_enabled( $product ): bool {
        if ( 'yes' !== get_option( 'tejcart_enable_reviews', 'yes' ) ) {
            return false;
        }

        /** This filter is documented in templates/product/single-product.php */
        return (bool) apply_filters( 'tejcart_show_single_product_reviews', true, $product );
    }

    /**
     * Build the reviews tab label, including the count when available.
     *
     * @param Abstract_Product $product The product being displayed.
     * @return string
     */
    private static function reviews_tab_title( $product ): string {
        $count = 0;
        if ( class_exists( '\\TejCart\\Product\\Product_Reviews' ) && method_exists( $product, 'get_id' ) ) {
            $count = (int) \TejCart\Product\Product_Reviews::get_review_count( (int) $product->get_id() );
        }

        return sprintf(
            /* translators: %d: number of reviews */
            _n( 'Reviews (%d)', 'Reviews (%d)', max( $count, 1 ), 'tejcart' ),
            $count
        );
    }
}
