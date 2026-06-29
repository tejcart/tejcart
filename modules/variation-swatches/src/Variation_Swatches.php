<?php
/**
 * Variation Swatches — main module class.
 *
 * Coordinates admin attribute-editor hooks (swatch type/value per term)
 * and frontend rendering that replaces default dropdowns with visual
 * color, image, and label swatches.
 *
 * @package TejCart\Variation_Swatches
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Variation_Swatches\Admin\Attribute_Editor;
use TejCart\Variation_Swatches\Admin\Product_Swatch_Editor;
use TejCart\Variation_Swatches\Frontend\Swatch_Renderer;
use TejCart\Variation_Swatches\Frontend\Shop_Swatches;

/**
 * Central orchestrator for the Variation Swatches module.
 */
class Variation_Swatches {

    /**
     * Swatch type: solid colour circle/square.
     */
    public const TYPE_COLOR = 'color';

    /**
     * Swatch type: thumbnail image from the media library.
     */
    public const TYPE_IMAGE = 'image';

    /**
     * Swatch type: text label rendered as a styled button.
     */
    public const TYPE_LABEL = 'label';

    /**
     * Allowed swatch types.
     */
    public const TYPES = array( self::TYPE_COLOR, self::TYPE_IMAGE, self::TYPE_LABEL );

    /**
     * Term meta key for the swatch type (color|image|label).
     */
    public const META_TYPE = 'tejcart_swatch_type';

    /**
     * Term meta key for the swatch value (hex|attachment_id|text).
     */
    public const META_VALUE = 'tejcart_swatch_value';

    /**
     * Option key for module settings.
     */
    public const SETTINGS_OPTION = 'tejcart_variation_swatches_settings';

    /**
     * Product meta key for per-product swatch configuration.
     *
     * Stores a JSON map keyed by sanitized attribute key:
     * `{ "<attr_key>": { "mode": "auto|color|label", "colors": { "<value>": "#hex" } } }`.
     * Lets merchants assign swatch colours to custom (per-product)
     * attributes right on the product edit page — no global attribute
     * taxonomy or per-term setup required.
     */
    public const META_CONFIG = '_tejcart_swatch_config';

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Frontend renderer (lazy-built).
     */
    private ?Swatch_Renderer $renderer = null;

    /**
     * Shop-page swatch renderer (lazy-built).
     */
    private ?Shop_Swatches $shop_swatches = null;

    /**
     * Admin attribute editor (lazy-built).
     */
    private ?Attribute_Editor $editor = null;

    /**
     * Admin product-edit swatch editor (lazy-built).
     */
    private ?Product_Swatch_Editor $product_editor = null;

    /**
     * Cached settings array.
     *
     * @var array<string, mixed>|null
     */
    private ?array $settings = null;

    /**
     * Return the singleton.
     */
    public static function get_instance(): ?self {
        return self::$instance;
    }

    /**
     * Replace the singleton — test seam gated behind TEJCART_TESTING.
     *
     * @internal Use in tests and DI overrides only.
     */
    public static function set_instance( ?self $instance ): void {
        if ( ! defined( 'TEJCART_TESTING' ) || ! TEJCART_TESTING ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Variation_Swatches::set_instance() called outside test context — ignored.', 'warning' );
            }
            return;
        }
        self::$instance = $instance;
    }

    /**
     * Boot all hooks — called from module.php at tejcart_init priority 20.
     */
    public function init(): void {
        // Admin hooks — attribute term edit screens + product edit page.
        if ( is_admin() ) {
            $this->get_editor()->init();
            $this->get_product_editor()->init();
        }

        // Frontend — single product page swatches.
        $this->get_renderer()->init();

        // Frontend — shop/archive page compact swatches.
        $settings = $this->get_settings();
        if ( ! empty( $settings['show_on_shop'] ) ) {
            $this->get_shop_swatches()->init();
        }
    }

    /**
     * Return the frontend swatch renderer.
     */
    public function get_renderer(): Swatch_Renderer {
        if ( null === $this->renderer ) {
            $this->renderer = new Swatch_Renderer( $this );
        }
        return $this->renderer;
    }

    /**
     * Return the shop-page swatch renderer.
     */
    public function get_shop_swatches(): Shop_Swatches {
        if ( null === $this->shop_swatches ) {
            $this->shop_swatches = new Shop_Swatches( $this );
        }
        return $this->shop_swatches;
    }

    /**
     * Return the admin attribute editor.
     */
    public function get_editor(): Attribute_Editor {
        if ( null === $this->editor ) {
            $this->editor = new Attribute_Editor();
        }
        return $this->editor;
    }

    /**
     * Return the admin product-edit swatch editor.
     */
    public function get_product_editor(): Product_Swatch_Editor {
        if ( null === $this->product_editor ) {
            $this->product_editor = new Product_Swatch_Editor();
        }
        return $this->product_editor;
    }

    /**
     * Read the per-product swatch configuration for one product.
     *
     * @param object|null $product Product instance exposing get_meta().
     * @return array<string, array{mode:string, colors:array<string,string>}>
     */
    public static function get_product_config( $product ): array {
        if ( null === $product || ! method_exists( $product, 'get_meta' ) ) {
            return array();
        }

        $raw = $product->get_meta( self::META_CONFIG );
        if ( empty( $raw ) || ! is_string( $raw ) ) {
            return array();
        }

        $data = json_decode( $raw, true );

        return is_array( $data ) ? $data : array();
    }

    /**
     * Whether assets should be enqueued on the current page.
     *
     * Returns true on single product pages and, when the shop-page
     * toggle is on, on shop/archive pages as well.
     */
    public function should_enqueue(): bool {
        if ( function_exists( 'tejcart_is_product_page' ) && tejcart_is_product_page() ) {
            return true;
        }

        $settings = $this->get_settings();
        if ( ! empty( $settings['show_on_shop'] ) ) {
            if ( function_exists( 'tejcart_is_shop' ) && tejcart_is_shop() ) {
                return true;
            }
            if ( function_exists( 'tejcart_is_product_category' ) && tejcart_is_product_category() ) {
                return true;
            }
            if ( function_exists( 'tejcart_is_product_tag' ) && tejcart_is_product_tag() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current admin screen is an attribute term edit page.
     */
    public function is_attribute_term_screen( string $hook ): bool {
        if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
            return false;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( null === $screen ) {
            return false;
        }

        // Global attribute taxonomies use the tejcart_pa_ prefix.
        return str_starts_with( (string) ( $screen->taxonomy ?? '' ), \TejCart\Product\Global_Attributes::TAXONOMY_PREFIX );
    }

    /**
     * Get module settings with defaults.
     *
     * @return array<string, mixed>
     */
    public function get_settings(): array {
        if ( null === $this->settings ) {
            $stored = get_option( self::SETTINGS_OPTION, array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $this->settings = wp_parse_args( $stored, self::defaults() );
        }

        return $this->settings;
    }

    /**
     * Default settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return array(
            'swatch_style'          => 'circle',   // circle | square | rounded
            'show_tooltip'          => true,
            'show_on_shop'          => false,
            'shop_max_visible'      => 5,
            'change_image_on_hover' => false,
            'disabled_style'        => 'cross',    // cross | blur | hide
        );
    }

    /**
     * Retrieve swatch data for a given term.
     *
     * @param int $term_id Term ID.
     * @return array{type: string, value: string}
     */
    public static function get_term_swatch( int $term_id ): array {
        $type  = get_term_meta( $term_id, self::META_TYPE, true );
        $value = get_term_meta( $term_id, self::META_VALUE, true );

        if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
            $type = '';
        }
        if ( ! is_string( $value ) ) {
            $value = '';
        }

        return array(
            'type'  => $type,
            'value' => $value,
        );
    }

    /**
     * Check whether a term has swatch data configured.
     */
    public static function term_has_swatch( int $term_id ): bool {
        $swatch = self::get_term_swatch( $term_id );
        return '' !== $swatch['type'] && '' !== $swatch['value'];
    }
}
