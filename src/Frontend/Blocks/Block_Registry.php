<?php
/**
 * Gutenberg Block Registry.
 *
 * Registers every TejCart editor block with WordPress.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralised registry for all TejCart Gutenberg blocks.
 */
class Block_Registry {
    /**
     * Block instances keyed by block name.
     *
     * @var array<string, object>
     */
    private array $blocks = array();

    /**
     * Hook into WordPress to register every block.
     */
    public function init(): void {
        add_action( 'init', array( $this, 'register_blocks' ) );
    }

    /**
     * Register all TejCart Gutenberg blocks.
     */
    public function register_blocks(): void {
        $version    = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_register_script(
            'tejcart-block-editor',
            tejcart_asset_url( 'assets/js/blocks/editor.js' ),
            array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
            $version,
            true
        );

        wp_register_style(
            'tejcart-block-editor',
            tejcart_asset_url( 'assets/css/blocks/editor.css' ),
            array( 'wp-edit-blocks' ),
            $version
        );

        $add_to_cart = new Add_To_Cart_Block();
        register_block_type( 'tejcart/add-to-cart', array(
            'editor_script'   => 'tejcart-block-editor',
            'editor_style'    => 'tejcart-block-editor',
            'render_callback' => array( $add_to_cart, 'render' ),
            'attributes'      => $add_to_cart->get_attributes(),
        ) );
        $this->blocks['tejcart/add-to-cart'] = $add_to_cart;

        $cart = new Cart_Block();
        register_block_type( 'tejcart/cart', array(
            'editor_script'   => 'tejcart-block-editor',
            'editor_style'    => 'tejcart-block-editor',
            'render_callback' => array( $cart, 'render' ),
            'attributes'      => $cart->get_attributes(),
        ) );
        $this->blocks['tejcart/cart'] = $cart;

        $product_box = new Product_Box_Block();
        register_block_type( 'tejcart/product-box', array(
            'editor_script'   => 'tejcart-block-editor',
            'editor_style'    => 'tejcart-block-editor',
            'render_callback' => array( $product_box, 'render' ),
            'attributes'      => $product_box->get_attributes(),
        ) );
        $this->blocks['tejcart/product-box'] = $product_box;

        $this->register_discovery_blocks();

        // Filter blocks (price, attribute, rating, stock) are registered
        // by the product-filters module when enabled.

        register_block_type( 'tejcart/mini-cart', array(
            'editor_script'   => 'tejcart-block-editor',
            'editor_style'    => 'tejcart-block-editor',
            'render_callback' => array( $this, 'render_mini_cart' ),
            'attributes'      => array(
                'show_count' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
                'show_total' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
            ),
        ) );

    }

    /**
     * Render callback for the mini-cart block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Inner block content.
     * @return string
     */
    public function render_mini_cart( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'show_count' => true,
            'show_total' => true,
        ) );

        ob_start();
        tejcart_get_template( 'cart/mini-cart.php', $attributes );
        return ob_get_clean();
    }

    /**
     * Register the six discovery blocks.
     */
    private function register_discovery_blocks(): void {
        $discovery_blocks = array(
            'tejcart/featured-product'      => new Featured_Product_Block(),
            'tejcart/on-sale'               => new On_Sale_Block(),
            'tejcart/best-sellers'          => new Best_Sellers_Block(),
            'tejcart/top-rated'             => new Top_Rated_Block(),
            'tejcart/hand-picked'           => new Hand_Picked_Block(),
            'tejcart/products-by-category'  => new Products_By_Category_Block(),
        );

        wp_register_style(
            'tejcart-block-discovery',
            tejcart_asset_url( 'assets/css/blocks/discovery.css' ),
            array(),
            defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0'
        );

        foreach ( $discovery_blocks as $name => $instance ) {
            register_block_type( $name, array(
                'editor_script'   => 'tejcart-block-editor',
                'editor_style'    => 'tejcart-block-editor',
                'style'           => 'tejcart-block-discovery',
                'render_callback' => array( $instance, 'render' ),
                'attributes'      => $instance->get_attributes(),
            ) );
            $this->blocks[ $name ] = $instance;
        }
    }

    /**
     * Return all registered block instances.
     *
     * @return array<string, object>
     */
    public function get_blocks(): array {
        return $this->blocks;
    }
}
