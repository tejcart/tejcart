<?php
/**
 * TejCart Variation Swatches module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `variation-swatches` module toggle is enabled. Replaces default
 * dropdown selects for variable product attributes with visual
 * color, image, and label/button swatches on product pages and
 * shop archive pages.
 *
 * @package TejCart\Variation_Swatches
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_VARIATION_SWATCHES_FILE' ) ) {
    define( 'TEJCART_VARIATION_SWATCHES_FILE', __FILE__ );
    define( 'TEJCART_VARIATION_SWATCHES_DIR',  plugin_dir_path( __FILE__ ) );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Variation_Swatches\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_VARIATION_SWATCHES_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Variation_Swatches\\Variation_Swatches' ) ) {
        return;
    }

    $swatches = new \TejCart\Variation_Swatches\Variation_Swatches();
    \TejCart\Variation_Swatches\Variation_Swatches::set_instance( $swatches );
    $swatches->init();

    // Replace default variation dropdown HTML with swatches.
    add_filter( 'tejcart_variation_attribute_input', static function ( string $html, array $attr, string $field_id, string $attr_key, $product ) use ( $swatches ): string {
        $renderer = $swatches->get_renderer();
        if ( null === $renderer ) {
            return $html;
        }

        // Per-product swatch configuration set on the product edit page.
        $config = \TejCart\Variation_Swatches\Variation_Swatches::get_product_config( $product );
        $entry  = $config[ sanitize_key( $attr_key ) ] ?? array();
        $mode   = isset( $entry['mode'] ) ? (string) $entry['mode'] : 'auto';
        $colors = isset( $entry['colors'] ) && is_array( $entry['colors'] ) ? $entry['colors'] : array();

        $args = array(
            'attribute' => \TejCart\Product\Global_Attributes::TAXONOMY_PREFIX . $attr_key,
            'options'   => (array) ( $attr['values'] ?? array() ),
            'product'   => $product,
            'selected'  => '',
            'name'      => 'attribute_' . $attr_key,
            'id'        => $field_id,
            'mode'      => $mode,
            'colors'    => $colors,
        );
        return $renderer->render_swatches( $html, $args );
    }, 10, 5 );

    // Register module assets early; enqueue on any frontend page so
    // shortcode-rendered products, shop archives, and quick-view
    // modals all pick up the CSS and JS. The combined payload is
    // ~6 KB gzipped — negligible on non-product pages.
    add_action( 'wp_enqueue_scripts', static function () use ( $swatches ): void {
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : false;
        $base    = plugin_dir_url( TEJCART_VARIATION_SWATCHES_FILE );
        $debug   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

        wp_enqueue_style(
            'tejcart-swatches',
            $base . 'assets/css/' . ( $debug ? 'swatches.css' : 'swatches.min.css' ),
            array(),
            $version
        );
        wp_enqueue_script(
            'tejcart-swatches',
            $base . 'assets/js/' . ( $debug ? 'swatches.js' : 'swatches.min.js' ),
            array(),
            $version,
            true
        );

        $settings = $swatches->get_settings();

        wp_localize_script( 'tejcart-swatches', 'tejcartSwatchesConfig', array(
            'changeImageOnHover' => ! empty( $settings['change_image_on_hover'] ),
            'swatchStyle'        => $settings['swatch_style'] ?? 'rounded',
            'showTooltip'        => ! empty( $settings['show_tooltip'] ),
        ) );
    } );

    // Enqueue admin assets on attribute term edit screens.
    add_action( 'admin_enqueue_scripts', static function ( string $hook ) use ( $swatches ): void {
        if ( ! $swatches->is_attribute_term_screen( $hook ) ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    } );
}, 20 );
