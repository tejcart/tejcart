<?php
/**
 * Product-edit swatch editor.
 *
 * Adds an inline "Swatch style" select plus a per-value colour picker to
 * each attribute in the product edit page's Variations tab, and persists
 * the result as product meta. This lets non-technical merchants assign
 * colour swatches to custom (per-product) attributes without touching the
 * global attribute taxonomy or per-term meta screens.
 *
 * @package TejCart\Variation_Swatches\Admin
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Variation_Swatches\Variation_Swatches;
use TejCart\Variation_Swatches\Color_Names;

/**
 * Enqueues the product-edit swatch UI and saves its configuration.
 */
class Product_Swatch_Editor {

    /**
     * Admin page hook suffix for the TejCart Products screen.
     */
    private const SCREEN_HOOK = 'tejcart_page_tejcart-products';

    /**
     * POST field carrying the JSON swatch configuration.
     */
    private const POST_FIELD = 'tejcart_swatch_config';

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'tejcart_product_form_apply_type_specific', array( $this, 'save' ) );
    }

    /**
     * Enqueue the swatch editor assets on the product add/edit screen.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue( string $hook ): void {
        if ( self::SCREEN_HOOK !== $hook ) {
            return;
        }

        // Only the add/edit form needs the editor (not the list table).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'add' !== $action && 'edit' !== $action ) {
            return;
        }

        $base    = plugin_dir_url( TEJCART_VARIATION_SWATCHES_FILE );
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : false;
        $debug   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

        wp_enqueue_style(
            'tejcart-swatches-editor',
            $base . 'assets/css/' . ( $debug ? 'product-swatches.css' : 'product-swatches.min.css' ),
            array(),
            $version
        );
        wp_enqueue_script(
            'tejcart-swatches-editor',
            $base . 'assets/js/' . ( $debug ? 'product-swatches.js' : 'product-swatches.min.js' ),
            array(),
            $version,
            true
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
        $config     = array();
        if ( $product_id > 0 && function_exists( 'tejcart_get_product' ) ) {
            $product = tejcart_get_product( $product_id );
            $config  = Variation_Swatches::get_product_config( $product );
        }

        wp_localize_script(
            'tejcart-swatches-editor',
            'tejcartSwatchEditor',
            array(
                'config' => (object) $config,
                'names'  => (object) Color_Names::map(),
                'i18n'   => array(
                    'heading'   => __( 'Swatch style', 'tejcart' ),
                    'auto'      => __( 'Auto (detect colours)', 'tejcart' ),
                    'color'     => __( 'Colour swatches', 'tejcart' ),
                    'label'     => __( 'Text buttons', 'tejcart' ),
                    'pickColor' => __( 'Pick colour', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Persist the posted swatch configuration as product meta.
     *
     * Nonce + capability are verified by the core save handler before this
     * action fires, so reading `$_POST` here is safe.
     *
     * @param object $product Saved product instance.
     */
    public function save( $product ): void {
        if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified upstream by handle_save().
        if ( ! isset( $_POST[ self::POST_FIELD ] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
        $raw  = (string) wp_unslash( $_POST[ self::POST_FIELD ] );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            $product->update_meta( Variation_Swatches::META_CONFIG, '' );
            return;
        }

        $clean = array();
        foreach ( $data as $attr_key => $conf ) {
            $key = sanitize_key( (string) $attr_key );
            if ( '' === $key || ! is_array( $conf ) ) {
                continue;
            }

            $mode = isset( $conf['mode'] ) ? (string) $conf['mode'] : 'auto';
            if ( ! in_array( $mode, array( 'auto', 'color', 'label' ), true ) ) {
                $mode = 'auto';
            }

            $colors = array();
            if ( isset( $conf['colors'] ) && is_array( $conf['colors'] ) ) {
                foreach ( $conf['colors'] as $value => $hex ) {
                    $hex_clean = sanitize_hex_color( (string) $hex );
                    if ( $hex_clean ) {
                        $colors[ sanitize_text_field( (string) $value ) ] = $hex_clean;
                    }
                }
            }

            // Skip empty entries to keep the meta tidy.
            if ( 'auto' === $mode && empty( $colors ) ) {
                continue;
            }

            $clean[ $key ] = array(
                'mode'   => $mode,
                'colors' => $colors,
            );
        }

        if ( empty( $clean ) ) {
            $product->update_meta( Variation_Swatches::META_CONFIG, '' );
            return;
        }

        $product->update_meta( Variation_Swatches::META_CONFIG, (string) wp_json_encode( $clean ) );
    }
}
