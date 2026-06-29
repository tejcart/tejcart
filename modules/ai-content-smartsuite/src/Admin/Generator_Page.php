<?php
/**
 * Content Generator — standalone admin page that renders the
 * product-table UX with bulk operations. Lives under `TejCart →
 * AI Content` and inherits core admin chrome via the
 * `tejcart_admin_page_hooks` filter.
 *
 * @package TejCart\AI_Content_Smartsuite\Admin
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Admin;

use TejCart\AI_Content_Smartsuite\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Generator_Page {
    public const PAGE_SLUG  = 'tejcart-ai-content';
    /**
     * @deprecated Use {@see Capabilities::NONCE_AJAX}. This alias is
     * retained because external code (and the inline validate-key
     * script in Settings_Tab) historically referenced this constant.
     */
    public const NONCE_AJAX = Capabilities::NONCE_AJAX;

    public const FIELDS = array( 'name', 'shortdesc', 'description', 'tags', 'faqs' );

    private static ?string $page_hook = null;

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
        add_filter( 'tejcart_admin_page_hooks', array( __CLASS__, 'register_page_hook' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function add_menu(): void {
        self::$page_hook = add_submenu_page(
            'tejcart',
            __( 'AI Content', 'tejcart' ),
            __( 'AI Content', 'tejcart' ),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /**
     * Append our submenu hook so core's tejcart-admin.css loads here.
     *
     * @param string[] $hooks
     * @return string[]
     */
    public static function register_page_hook( array $hooks ): array {
        $hooks[] = 'tejcart_page_' . self::PAGE_SLUG;
        return $hooks;
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'tejcart_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        $debug    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
        $css_rel  = $debug ? 'assets/css/admin.css' : 'assets/css/admin.min.css';
        $js_rel   = $debug ? 'assets/js/admin.js'   : 'assets/js/admin.min.js';
        if ( ! file_exists( TEJCART_AI_CONTENT_DIR . $css_rel ) ) {
            $css_rel = 'assets/css/admin.css';
        }
        if ( ! file_exists( TEJCART_AI_CONTENT_DIR . $js_rel ) ) {
            $js_rel = 'assets/js/admin.js';
        }

        wp_enqueue_style(
            'tejcart-ai-content-admin',
            TEJCART_AI_CONTENT_URL . $css_rel,
            array( 'tejcart-admin' ),
            TEJCART_VERSION
        );
        wp_enqueue_script(
            'tejcart-ai-content-admin',
            TEJCART_AI_CONTENT_URL . $js_rel,
            array(),
            TEJCART_VERSION,
            true
        );

        wp_localize_script(
            'tejcart-ai-content-admin',
            'TejCartAIContent',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
                'i18n'    => array(
                    'generate'     => __( 'Generate', 'tejcart' ),
                    'regenerate'   => __( 'Regenerate', 'tejcart' ),
                    'save'         => __( 'Save', 'tejcart' ),
                    'cancel'       => __( 'Cancel', 'tejcart' ),
                    'apply'        => __( 'Apply', 'tejcart' ),
                    'edit'         => __( 'Edit', 'tejcart' ),
                    'addQA'        => __( 'Add Q&A', 'tejcart' ),
                    'remove'       => __( 'Remove', 'tejcart' ),
                    'noContent'    => __( 'No AI content yet — click Generate.', 'tejcart' ),
                    'loading'      => __( 'Loading products…', 'tejcart' ),
                    'error'        => __( 'Something went wrong.', 'tejcart' ),
                    /* translators: 1: current product index, 2: total product count */
                    'workingOn'    => __( 'Working on %1$s of %2$s products…', 'tejcart' ),
                    /* translators: 1: success count, 2: failure count */
                    'doneN'        => __( 'Done. %1$s succeeded, %2$s failed.', 'tejcart' ),
                    'confirmApply' => __( 'Apply AI content to the selected products? This overwrites the live field.', 'tejcart' ),
                    'confirmGen'   => __( 'Generate AI content for the selected products?', 'tejcart' ),
                    'noSelection'  => __( 'Select at least one product.', 'tejcart' ),
                    'confirmRevert' => __( 'Revert to the previous value before AI was applied?', 'tejcart' ),
                    'item'         => __( 'item', 'tejcart' ),
                    'items'        => __( 'items', 'tejcart' ),
                ),
                'fields'  => array(
                    'name'        => __( 'Name', 'tejcart' ),
                    'shortdesc'   => __( 'Short Description', 'tejcart' ),
                    'description' => __( 'Description', 'tejcart' ),
                    'tags'        => __( 'Tags', 'tejcart' ),
                    'faqs'        => __( 'FAQs', 'tejcart' ),
                ),
            )
        );
    }

    public static function current_field(): string {
        $field = isset( $_GET['field'] ) ? sanitize_key( (string) $_GET['field'] ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return in_array( $field, self::FIELDS, true ) ? $field : 'name';
    }

    public static function field_url( string $field ): string {
        return add_query_arg(
            array( 'page' => self::PAGE_SLUG, 'field' => $field ),
            admin_url( 'admin.php' )
        );
    }

    public static function render(): void {
        if ( ! Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tejcart' ), '', array( 'response' => 403 ) );
        }

        $field           = self::current_field();
        $settings_url    = Settings_Tab::settings_url( Settings_Tab::SECTION_API );
        $has_api_key     = \TejCart\AI_Content_Smartsuite\Settings::has_api_key();

        require __DIR__ . '/views/generator-page.php';
    }
}
