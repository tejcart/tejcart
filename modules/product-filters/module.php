<?php
/**
 * TejCart Product Filters module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the
 * `product-filters` module toggle is enabled. Provides faceted
 * navigation (categories, brands, tags, price, rating, stock,
 * custom attributes), AJAX filtering, clean filter URLs, SEO
 * canonical/noindex management, and Gutenberg filter blocks.
 *
 * @package TejCart\Product_Filters
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_PRODUCT_FILTERS_FILE' ) ) {
    define( 'TEJCART_PRODUCT_FILTERS_FILE',    __FILE__ );
    define( 'TEJCART_PRODUCT_FILTERS_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_PRODUCT_FILTERS_VERSION', '1.0.0' );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Product_Filters\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_PRODUCT_FILTERS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Product_Filters\\Product_Filter' ) ) {
        return;
    }

    $filter = new \TejCart\Product_Filters\Product_Filter();
    \TejCart\Product_Filters\Product_Filter::set_instance( $filter );
    $filter->init();

    // Hook into the shop page to render the faceted sidebar.
    add_filter( 'tejcart_shop_sidebar_html', static function ( string $html ) use ( $filter ): string {
        return $filter->render_sidebar();
    } );

    add_filter( 'tejcart_shop_active_filters_html', static function ( string $html ) use ( $filter ): string {
        return $filter->render_active_filters();
    } );

    // Enqueue module assets on shop surfaces.
    add_action( 'wp_enqueue_scripts', static function () use ( $filter ): void {
        if ( ! $filter->is_on_shop_page() ) {
            return;
        }

        $version = TEJCART_PRODUCT_FILTERS_VERSION;
        $base    = plugin_dir_url( TEJCART_PRODUCT_FILTERS_FILE );
        $debug   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

        wp_enqueue_style(
            'tejcart-filters',
            $base . 'assets/css/' . ( $debug ? 'filters.css' : 'filters.min.css' ),
            array( 'tejcart-public', 'tejcart-shop' ),
            $version
        );
        wp_enqueue_script(
            'tejcart-filters',
            $base . 'assets/js/' . ( $debug ? 'filters.js' : 'filters.min.js' ),
            array(),
            $version,
            true
        );
    } );

    // Register Gutenberg filter blocks.
    add_action( 'init', static function (): void {
        $version = TEJCART_PRODUCT_FILTERS_VERSION;
        $base    = plugin_dir_url( TEJCART_PRODUCT_FILTERS_FILE );

        wp_register_script(
            'tejcart-filter-blocks',
            $base . 'assets/js/blocks/filter-blocks.js',
            array(),
            $version,
            true
        );

        wp_register_style(
            'tejcart-filter-blocks',
            $base . 'assets/css/blocks/filter-blocks.css',
            array(),
            $version
        );

        $block_args = array(
            'editor_script' => 'tejcart-block-editor',
            'editor_style'  => 'tejcart-block-editor',
            'script'        => 'tejcart-filter-blocks',
            'style'         => 'tejcart-filter-blocks',
        );

        $price = new \TejCart\Product_Filters\Blocks\Filter_By_Price_Block();
        register_block_type( 'tejcart/filter-by-price', array_merge( $block_args, array(
            'render_callback' => array( $price, 'render' ),
            'attributes'      => $price->get_attributes(),
        ) ) );

        $attribute = new \TejCart\Product_Filters\Blocks\Filter_By_Attribute_Block();
        register_block_type( 'tejcart/filter-by-attribute', array_merge( $block_args, array(
            'render_callback' => array( $attribute, 'render' ),
            'attributes'      => $attribute->get_attributes(),
        ) ) );

        $rating = new \TejCart\Product_Filters\Blocks\Filter_By_Rating_Block();
        register_block_type( 'tejcart/filter-by-rating', array_merge( $block_args, array(
            'render_callback' => array( $rating, 'render' ),
            'attributes'      => $rating->get_attributes(),
        ) ) );

        $stock = new \TejCart\Product_Filters\Blocks\Filter_By_Stock_Block();
        register_block_type( 'tejcart/filter-by-stock', array_merge( $block_args, array(
            'render_callback' => array( $stock, 'render' ),
            'attributes'      => $stock->get_attributes(),
        ) ) );
    } );

    // Register module template path for tejcart_get_template() overrides.
    // The filter receives full file paths, not directories — append the
    // template name so the resolver can file_exists() a real file.
    add_filter( 'tejcart_template_paths', static function ( array $paths, string $template_name ): array {
        $candidate = TEJCART_PRODUCT_FILTERS_DIR . 'templates/' . $template_name;
        if ( is_file( $candidate ) ) {
            $paths[] = $candidate;
        }
        return $paths;
    }, 10, 2 );
}, 20 );
