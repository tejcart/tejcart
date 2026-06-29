<?php
/**
 * Shop page compact swatches — renders swatch previews on product
 * cards in shop/category archive pages.
 *
 * @package TejCart\Variation_Swatches\Frontend
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Variation_Swatches\Variation_Swatches;

/**
 * Renders compact swatch strips on product archive cards for variable
 * products, with a configurable maximum visible count and "+N more"
 * overflow indicator.
 */
class Shop_Swatches {

    /**
     * Parent module instance.
     */
    private Variation_Swatches $module;

    /**
     * @param Variation_Swatches $module Parent module instance.
     */
    public function __construct( Variation_Swatches $module ) {
        $this->module = $module;
    }

    /**
     * Register frontend hooks for shop page swatches.
     */
    public function init(): void {
        add_action( 'tejcart_after_shop_loop_item_title', array( $this, 'render' ), 25 );
        add_filter( 'tejcart_product_card_classes', array( $this, 'add_card_class' ), 10, 2 );
    }

    /**
     * Render compact swatches for the current product in the shop loop.
     */
    public function render(): void {
        $product = function_exists( 'tejcart_get_current_product' )
            ? tejcart_get_current_product()
            : null;

        if ( null === $product ) {
            return;
        }

        if ( ! method_exists( $product, 'get_type' ) || 'variable' !== $product->get_type() ) {
            return;
        }

        if ( ! method_exists( $product, 'get_attributes' ) ) {
            return;
        }

        $attributes = $product->get_attributes();
        if ( empty( $attributes ) ) {
            return;
        }

        // Find the first attribute that has swatch data.
        $swatch_attribute = null;
        foreach ( $attributes as $attr ) {
            if ( empty( $attr['used_for_variations'] ) || empty( $attr['visible'] ) ) {
                continue;
            }
            $taxonomy = \TejCart\Product\Global_Attributes::TAXONOMY_PREFIX . ( $attr['slug'] ?? $attr['name'] ?? '' );
            $values   = (array) ( $attr['values'] ?? array() );
            if ( empty( $values ) ) {
                continue;
            }

            // Check if any term in this attribute has swatch data.
            foreach ( $values as $slug ) {
                $term = get_term_by( 'slug', $slug, $taxonomy );
                if ( $term && ! is_wp_error( $term ) && Variation_Swatches::term_has_swatch( (int) $term->term_id ) ) {
                    $swatch_attribute = array(
                        'taxonomy' => $taxonomy,
                        'values'   => $values,
                    );
                    break 2;
                }
            }
        }

        if ( null === $swatch_attribute ) {
            return;
        }

        $this->render_compact_swatches( $swatch_attribute['taxonomy'], $swatch_attribute['values'] );
    }

    /**
     * Add a CSS class to product cards that have swatches.
     *
     * @param string[] $classes Existing classes.
     * @param object   $product Product instance.
     * @return string[]
     */
    public function add_card_class( array $classes, $product ): array {
        if ( method_exists( $product, 'get_type' ) && 'variable' === $product->get_type() ) {
            $classes[] = 'tejcart-has-swatches';
        }
        return $classes;
    }

    /**
     * Render the compact swatch strip for a single attribute.
     *
     * @param string   $taxonomy Attribute taxonomy slug (e.g. pa_color).
     * @param string[] $values   Term slugs.
     */
    private function render_compact_swatches( string $taxonomy, array $values ): void {
        $settings    = $this->module->get_settings();
        $max_visible = (int) ( $settings['shop_max_visible'] ?? 5 );
        $swatch_style = $settings['swatch_style'] ?? 'circle';

        $term_data = array();
        foreach ( $values as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $swatch = Variation_Swatches::get_term_swatch( (int) $term->term_id );
            if ( '' === $swatch['type'] || '' === $swatch['value'] ) {
                continue;
            }
            $term_data[] = array(
                'term'   => $term,
                'swatch' => $swatch,
            );
        }

        if ( empty( $term_data ) ) {
            return;
        }

        $total      = count( $term_data );
        $visible    = array_slice( $term_data, 0, $max_visible );
        $overflow   = $total - count( $visible );

        echo '<div class="tejcart-shop-swatches tejcart-swatches--' . esc_attr( $swatch_style ) . '">';

        foreach ( $visible as $item ) {
            $this->render_compact_swatch( $item['term'], $item['swatch'] );
        }

        if ( $overflow > 0 ) {
            echo '<span class="tejcart-shop-swatches__overflow">+'
                . esc_html( (string) $overflow )
                . ' ' . esc_html__( 'more', 'tejcart' )
                . '</span>';
        }

        echo '</div>';
    }

    /**
     * Render a single compact swatch item for the shop page.
     *
     * @param \WP_Term             $term   Term object.
     * @param array{type: string, value: string} $swatch Swatch data.
     */
    private function render_compact_swatch( $term, array $swatch ): void {
        $settings     = $this->module->get_settings();
        $show_tooltip = ! empty( $settings['show_tooltip'] );

        $tooltip = $show_tooltip
            ? ' data-tooltip="' . esc_attr( $term->name ) . '"'
            : '';

        echo '<span class="tejcart-swatch tejcart-swatch--compact tejcart-swatch--' . esc_attr( $swatch['type'] ) . '"'
           . $tooltip
           . ' aria-label="' . esc_attr( $term->name ) . '">';

        switch ( $swatch['type'] ) {
            case Variation_Swatches::TYPE_COLOR:
                echo '<span class="tejcart-swatch__color" style="background-color:' . esc_attr( $swatch['value'] ) . ';"></span>';
                break;

            case Variation_Swatches::TYPE_IMAGE:
                $img_url = wp_get_attachment_image_url( (int) $swatch['value'], 'thumbnail' );
                if ( $img_url ) {
                    echo '<img class="tejcart-swatch__image" src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $term->name ) . '" loading="lazy" />';
                }
                break;

            case Variation_Swatches::TYPE_LABEL:
                echo '<span class="tejcart-swatch__label">' . esc_html( $swatch['value'] ) . '</span>';
                break;
        }

        echo '</span>';
    }
}
