<?php
/**
 * Frontend swatch renderer — replaces default <select> dropdowns
 * with visual swatches on single product pages.
 *
 * @package TejCart\Variation_Swatches\Frontend
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Variation_Swatches\Variation_Swatches;
use TejCart\Variation_Swatches\Color_Names;

/**
 * Renders color, image, and label swatches in place of the default
 * variation attribute dropdown selects.
 */
class Swatch_Renderer {

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
     * Register frontend hooks.
     */
    public function init(): void {
        // The main rendering is driven by the tejcart_variation_attribute_input
        // filter hooked in module.php. This init() registers supporting
        // hooks for out-of-stock tracking and product-page data attributes.
        add_filter( 'tejcart_product_data_attributes', array( $this, 'add_swatch_data' ), 10, 2 );
    }

    /**
     * Replace a dropdown HTML block with swatch HTML when the attribute
     * terms have swatch meta configured.
     *
     * This is the callback for the `tejcart_variation_attribute_input` filter.
     *
     * @param string               $html Existing dropdown HTML.
     * @param array<string, mixed> $args Dropdown arguments:
     *   - attribute: string     — attribute slug (e.g. 'pa_color')
     *   - options:   string[]   — available term slugs
     *   - product:   object     — product instance
     *   - selected:  string     — currently selected slug
     *   - name:      string     — form field name
     *   - id:        string     — DOM id for the <select>
     * @return string Swatch HTML or original dropdown if no swatch meta.
     */
    public function render_swatches( string $html, array $args ): string {
        $attribute = $args['attribute'] ?? '';
        $options   = (array) ( $args['options'] ?? array() );
        $selected  = (string) ( $args['selected'] ?? '' );
        $name      = (string) ( $args['name'] ?? '' );
        $id        = (string) ( $args['id'] ?? '' );
        $product   = $args['product'] ?? null;

        // Per-product swatch configuration set on the product edit page.
        $mode   = (string) ( $args['mode'] ?? 'auto' );
        $colors = is_array( $args['colors'] ?? null ) ? $args['colors'] : array();

        if ( empty( $attribute ) || empty( $options ) ) {
            return $html;
        }

        $terms = $this->resolve_terms( $attribute, $options, $mode, $colors );

        if ( empty( $terms ) ) {
            return $html;
        }

        // Determine out-of-stock variations if the product exposes them.
        $out_of_stock = $this->get_out_of_stock_values( $product, $attribute );

        $settings    = $this->module->get_settings();
        $swatch_style = $settings['swatch_style'] ?? 'circle';
        $show_tooltip = ! empty( $settings['show_tooltip'] );
        $disabled_style = $settings['disabled_style'] ?? 'cross';

        // Build the swatch wrapper with the hidden <select> preserved
        // for form submission and JS variation matching.
        $output = '<div class="tejcart-swatches-wrapper" data-attribute="' . esc_attr( $attribute ) . '">';

        // Keep the original select hidden — JS updates it on swatch click.
        $output .= str_replace( '<select', '<select style="display:none !important;position:absolute;" aria-hidden="true"', $html );

        // Swatch items.
        $output .= '<div class="tejcart-swatches tejcart-swatches--' . esc_attr( $swatch_style ) . '"'
                 . ' role="radiogroup"'
                 . ' aria-label="' . esc_attr( $this->get_attribute_label( $attribute ) ) . '">';

        foreach ( $terms as $term_data ) {
            $output .= $this->render_single_swatch(
                $term_data,
                $selected,
                $out_of_stock,
                $swatch_style,
                $show_tooltip,
                $disabled_style
            );
        }

        $output .= '</div>'; // .tejcart-swatches
        $output .= '</div>'; // .tejcart-swatches-wrapper

        return $output;
    }

    /**
     * Add swatch data attributes to the product wrapper.
     *
     * @param array<string, string> $attrs   Existing data attributes.
     * @param object                $product Product instance.
     * @return array<string, string>
     */
    public function add_swatch_data( array $attrs, $product ): array {
        if ( method_exists( $product, 'get_type' ) && 'variable' === $product->get_type() ) {
            $attrs['data-has-swatches'] = 'true';
        }
        return $attrs;
    }

    /**
     * Render a single swatch item.
     *
     * @param array<string, mixed> $term_data      Term data (term_id, slug, name, swatch).
     * @param string               $selected       Currently selected slug.
     * @param array<int, string>   $out_of_stock   Slugs that are out of stock.
     * @param string               $swatch_style   circle | square | rounded.
     * @param bool                 $show_tooltip   Whether to show tooltip.
     * @param string               $disabled_style cross | blur | hide.
     * @return string HTML for a single swatch.
     */
    private function render_single_swatch(
        array $term_data,
        string $selected,
        array $out_of_stock,
        string $swatch_style,
        bool $show_tooltip,
        string $disabled_style
    ): string {
        $swatch  = $term_data['swatch'];
        $slug    = $term_data['slug'];
        $name    = $term_data['name'];
        $term_id = $term_data['term_id'];

        $is_selected    = $slug === $selected;
        $is_out_of_stock = in_array( $slug, $out_of_stock, true );

        // If disabled_style is 'hide', skip out-of-stock items entirely.
        if ( $is_out_of_stock && 'hide' === $disabled_style ) {
            return '';
        }

        $swatch_type = '' !== $swatch['type'] ? $swatch['type'] : Variation_Swatches::TYPE_LABEL;

        // Visual styling (size, shape, selected/hover/disabled states) is
        // owned by the stylesheet — see assets/css/swatches.css. We emit
        // only structural classes here plus the one genuinely dynamic
        // inline value: a color swatch's background fill.
        $classes = array( 'tejcart-swatch' );
        $classes[] = 'tejcart-swatch--' . $swatch_type;
        if ( $is_selected ) {
            $classes[] = 'tejcart-swatch--selected';
        }
        if ( $is_out_of_stock ) {
            $classes[] = 'tejcart-swatch--disabled';
            $classes[] = 'tejcart-swatch--disabled-' . $disabled_style;
        }

        $tooltip_attr = $show_tooltip
            ? ' data-tooltip="' . esc_attr( $name ) . '"'
            : '';

        $aria_label = sprintf(
            /* translators: %s: attribute value name */
            esc_attr__( 'Select %s', 'tejcart' ),
            $name
        );

        $output = '<button type="button"'
                . ' class="' . esc_attr( implode( ' ', $classes ) ) . '"'
                . ' data-value="' . esc_attr( $slug ) . '"'
                . $tooltip_attr
                . ' role="radio"'
                . ' aria-checked="' . ( $is_selected ? 'true' : 'false' ) . '"'
                . ' aria-label="' . $aria_label . '"'
                . ( $is_out_of_stock ? ' aria-disabled="true"' : '' )
                . '>';

        switch ( $swatch_type ) {
            case Variation_Swatches::TYPE_COLOR:
                $output .= '<span class="tejcart-swatch__color" style="background-color:' . esc_attr( $swatch['value'] ) . ';"></span>';
                break;

            case Variation_Swatches::TYPE_IMAGE:
                $img_url = wp_get_attachment_image_url( (int) $swatch['value'], 'thumbnail' );
                if ( $img_url ) {
                    $output .= '<img class="tejcart-swatch__image" src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $name ) . '" loading="lazy" />';
                }
                break;

            case Variation_Swatches::TYPE_LABEL:
            default:
                // Label swatch — show the value text. Fall back to the term
                // name when no explicit label value was configured.
                $text = '' !== (string) $swatch['value'] ? (string) $swatch['value'] : $name;
                $output .= '<span class="tejcart-swatch__label">' . esc_html( $text ) . '</span>';
                break;
        }

        $output .= '</button>';

        return $output;
    }

    /**
     * Resolve option values to term data arrays with a swatch each.
     *
     * Swatch resolution priority for each value:
     *   1. An explicit color set on the product edit page (`$colors`).
     *   2. Swatch meta on a matching global-attribute taxonomy term.
     *   3. Auto-detection from the value text (CSS color name / hex).
     *   4. Label fallback (the value rendered as a button).
     *
     * When `$mode` is 'label' the value is always rendered as a label,
     * and when it is 'color' steps 1–3 apply with a label fallback only
     * for values that resolve to no color.
     *
     * @param string                 $attribute Taxonomy slug (e.g. tejcart_pa_color).
     * @param string[]               $options   Attribute values.
     * @param string                 $mode      auto | color | label.
     * @param array<string, string>  $colors    value => hex map from product meta.
     * @return array<int, array{term_id: int, slug: string, name: string, swatch: array{type: string, value: string}}>
     */
    private function resolve_terms( string $attribute, array $options, string $mode = 'auto', array $colors = array() ): array {
        $terms = array();

        foreach ( $options as $value ) {
            $value = (string) $value;
            $term  = get_term_by( 'slug', $value, $attribute );
            $name  = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;

            $swatch = $this->resolve_swatch( $value, $name, $term, $mode, $colors );

            $terms[] = array(
                'term_id' => ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0,
                'slug'    => $value,
                'name'    => $name,
                'swatch'  => $swatch,
            );
        }

        return $terms;
    }

    /**
     * Decide the swatch (type + value) for a single attribute value.
     *
     * @param string                $value  Raw attribute value.
     * @param string                $name   Display name.
     * @param \WP_Term|false|null   $term   Matching taxonomy term, if any.
     * @param string                $mode   auto | color | label.
     * @param array<string, string> $colors value => hex map from product meta.
     * @return array{type: string, value: string}
     */
    private function resolve_swatch( string $value, string $name, $term, string $mode, array $colors ): array {
        if ( 'label' === $mode ) {
            return array( 'type' => Variation_Swatches::TYPE_LABEL, 'value' => $value );
        }

        // 1. Explicit color chosen on the product edit page.
        if ( isset( $colors[ $value ] ) && '' !== (string) $colors[ $value ] ) {
            return array( 'type' => Variation_Swatches::TYPE_COLOR, 'value' => (string) $colors[ $value ] );
        }

        // 2. Swatch meta on a global-attribute taxonomy term.
        if ( $term && ! is_wp_error( $term ) ) {
            $term_swatch = Variation_Swatches::get_term_swatch( (int) $term->term_id );
            if ( '' !== $term_swatch['type'] && '' !== $term_swatch['value'] ) {
                return $term_swatch;
            }
        }

        // 3. Auto-detect a color from the value (or term slug/name).
        $auto = Color_Names::to_hex( $value );
        if ( '' === $auto && $term && ! is_wp_error( $term ) ) {
            $auto = Color_Names::to_hex( (string) $term->slug );
            if ( '' === $auto ) {
                $auto = Color_Names::to_hex( (string) $term->name );
            }
        }
        if ( '' !== $auto ) {
            return array( 'type' => Variation_Swatches::TYPE_COLOR, 'value' => $auto );
        }

        // 4. Label fallback.
        return array( 'type' => Variation_Swatches::TYPE_LABEL, 'value' => $name );
    }

    /**
     * Determine which attribute values are out of stock for a product.
     *
     * @param object|null $product   Product instance.
     * @param string      $attribute Attribute taxonomy slug.
     * @return string[] Slugs that are out of stock.
     */
    private function get_out_of_stock_values( $product, string $attribute ): array {
        if ( null === $product ) {
            return array();
        }

        if ( ! method_exists( $product, 'get_variations' ) ) {
            return array();
        }

        $out_of_stock = array();
        $variations   = $product->get_variations();

        if ( ! is_array( $variations ) ) {
            return array();
        }

        // Collect all values that appear ONLY in out-of-stock variations.
        $in_stock_values     = array();
        $out_of_stock_values = array();

        // Variation attributes may be keyed by the plain name ('size'),
        // the taxonomy name ('tejcart_pa_size'), or the form field name
        // ('attribute_size'). Try all of them.
        $tax_prefix    = \TejCart\Product\Global_Attributes::TAXONOMY_PREFIX;
        $plain         = str_starts_with( $attribute, $tax_prefix ) ? substr( $attribute, strlen( $tax_prefix ) ) : $attribute;
        $possible_keys = array( $plain, $attribute, 'attribute_' . $attribute, 'attribute_' . $plain );

        foreach ( $variations as $variation ) {
            $attrs = $variation->get_attributes();

            $value = '';
            foreach ( $possible_keys as $key ) {
                if ( isset( $attrs[ $key ] ) && '' !== (string) $attrs[ $key ] ) {
                    $value = (string) $attrs[ $key ];
                    break;
                }
            }
            if ( '' === $value ) {
                continue;
            }

            if ( $variation->is_in_stock() ) {
                $in_stock_values[ $value ] = true;
            } else {
                $out_of_stock_values[ $value ] = true;
            }
        }

        // A value is truly out of stock only if it never appears in an
        // in-stock variation (it might be in stock for other attribute
        // combinations).
        foreach ( $out_of_stock_values as $value => $_ ) {
            if ( ! isset( $in_stock_values[ $value ] ) ) {
                $out_of_stock[] = $value;
            }
        }

        return $out_of_stock;
    }

    /**
     * Get the human-readable label for an attribute taxonomy.
     *
     * @param string $attribute Taxonomy slug (e.g. pa_color).
     * @return string
     */
    private function get_attribute_label( string $attribute ): string {
        $taxonomy = get_taxonomy( $attribute );
        if ( $taxonomy && ! is_wp_error( $taxonomy ) ) {
            return $taxonomy->labels->singular_name ?? $taxonomy->label ?? $attribute;
        }

        // Strip the tejcart_pa_ prefix and ucfirst as a fallback.
        $label      = $attribute;
        $tax_prefix = \TejCart\Product\Global_Attributes::TAXONOMY_PREFIX;
        if ( str_starts_with( $label, $tax_prefix ) ) {
            $label = substr( $label, strlen( $tax_prefix ) );
        }
        return ucfirst( str_replace( array( '-', '_' ), ' ', $label ) );
    }
}
