<?php
/**
 * Admin product edit screen (industry-standard tabbed layout).
 *
 * Two-column layout: the main column hosts tab panels (Overview, Pricing,
 * Inventory, Shipping, Files, Components, Links, External), the sidebar
 * hosts the publish card, organization (taxonomies), and images. Built
 * with native WP admin styles.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Factory;
use TejCart\Product\Product_Taxonomy;
use TejCart\Product\Product_Type_Registry;
use TejCart\Product\Product_Types\Abstract_Product;
use TejCart\Product\Product_Types\Bundle_Product;
use TejCart\Product\Product_Types\Digital_Product;
use TejCart\Product\Product_Types\External_Product;
use TejCart\Product\Product_Types\Grouped_Product;
use TejCart\Product\Product_Types\Physical_Product;
use TejCart\Product\Product_Types\Variable_Product;
use TejCart\Product\Product_Types\Virtual_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders and persists the admin product edit form.
 */
class Product_Form {
    /**
     * Static flag so the admin_init handler registers exactly once even if
     * the class is instantiated multiple times (render path also news one up).
     *
     * @var bool
     */
    private static bool $hooks_registered = false;

    /**
     * Register the save / generate-variations POST handlers on `admin_init`.
     *
     * Must run before `admin-header.php` is emitted — otherwise
     * wp_safe_redirect() fails silently (headers already sent) and the user
     * lands on a blank admin page instead of the edit screen.
     *
     * Called only for real admin pageloads (not admin-ajax). The AJAX
     * picker callback lives in {@see register_ajax_handlers()} which the
     * bootstrap wires unconditionally in admin context.
     *
     * @return void
     */
    public function init(): void {
        if ( self::$hooks_registered ) {
            return;
        }
        self::$hooks_registered = true;
        add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
        $this->register_ajax_handlers();
    }

    /**
     * Register admin-AJAX action callbacks owned by the product edit screen.
     *
     * MUST be called on every admin-ajax.php request, not just on real
     * admin pageloads. The TejCart bootstrap gates the heavy admin boot
     * (menus, settings UIs, etc.) behind `is_admin() && ! wp_doing_ajax()`
     * for performance, which means classes only initialised inside
     * Admin::init() are *not* present during AJAX. Without this method,
     * the `tejcart_admin_search_products` callback would never be
     * registered during the picker's XHR and admin-ajax would fall
     * through to `wp_die( '0' )` (HTTP 400). The handler itself enforces
     * nonce + EDIT_PRODUCTS, so wiring it unconditionally in admin context
     * does not widen the attack surface.
     *
     * Idempotent: calling it twice is a no-op because WordPress
     * deduplicates identical (hook, callback, priority) tuples.
     *
     * @return void
     */
    public function register_ajax_handlers(): void {
        add_action( 'wp_ajax_tejcart_admin_search_products', array( $this, 'ajax_search_products' ) );
    }

    /**
     * Nonce action used by the upsell/cross-sell/related/grouped product picker
     * for its admin-AJAX search call. Kept distinct from the generic
     * `wp_rest` action so the picker keeps working when the public REST API
     * is disabled (`tejcart_api_enabled = no`).
     */
    public const PICKER_NONCE_ACTION = 'tejcart_admin_product_picker';

    /**
     * AJAX: search products by name or SKU for the admin product picker
     * (Linked products tab — upsells, cross-sells, related — and the
     * Grouped/children tab).
     *
     * Decoupled from the public `/tejcart/v1/products` REST endpoint so the
     * picker is not affected by:
     *   - The `tejcart_api_enabled` site option being toggled off.
     *   - The 60 req/min per-IP rate limit shared with public product reads.
     *   - REST cookie-auth quirks across hostname mismatches.
     *
     * Auth: cookie + admin nonce + `EDIT_PRODUCTS` capability. Returns a
     * compact JSON array of `{ id, name, sku }` matching the shape the
     * picker JS already consumes.
     *
     * @return void
     */
    public function ajax_search_products(): void {
        check_ajax_referer( self::PICKER_NONCE_ACTION, 'nonce' );

        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce already verified above.
        $search_raw  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $exclude_raw = isset( $_GET['exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['exclude'] ) ) : '';
        $per_page    = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 8;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $search   = is_string( $search_raw ) ? sanitize_text_field( $search_raw ) : '';
        $per_page = max( 1, min( 50, $per_page ) );

        $exclude_ids = array();
        if ( is_string( $exclude_raw ) && '' !== $exclude_raw ) {
            foreach ( explode( ',', $exclude_raw ) as $piece ) {
                $id = absint( $piece );
                if ( $id > 0 ) {
                    $exclude_ids[] = $id;
                }
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $where  = array( "type <> 'variation'" );
        $values = array();

        if ( '' !== $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(name LIKE %s OR sku LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        if ( ! empty( $exclude_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
            $where[]      = "id NOT IN ({$placeholders})";
            foreach ( $exclude_ids as $eid ) {
                $values[] = $eid;
            }
        }

        $values[] = $per_page;

        $where_clause = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, sku FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d",
                $values
            ),
            ARRAY_A
        );
        // phpcs:enable

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array(
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'sku'  => (string) ( $row['sku'] ?? '' ),
            );
        }

        wp_send_json( $out );
    }

    /**
     * Dispatch save / generate-variations POSTs on admin_init so redirects
     * land before any output is emitted.
     *
     * @return void
     */
    public function maybe_handle_post(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        // phpcs:enable

        if ( 'tejcart-products' !== $page ) {
            return;
        }

        if ( 'save' === $action ) {
            $this->handle_save();
            return;
        }

        if ( 'generate_variations' === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
            if ( $product_id ) {
                $this->handle_generate_variations( $product_id );
            }
            return;
        }

        if ( 'add' === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
            if ( 'variable' === $requested_type && tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
                $this->create_auto_draft_and_redirect( 'variable' );
            }
        }
    }

    /**
     * Render the product form for a given product ID (0 = create).
     *
     * @param int $product_id Product ID, 0 when creating a new product.
     * @return void
     */
    public function render( int $product_id ): void {
        $product = $product_id ? Product_Factory::get_product( $product_id ) : null;
        $is_edit = $product && $product->get_id() > 0;

        // Read-only render path; values are immediately allow-listed/sanitized.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( $is_edit ) {
            $type = $product->get_type();
        } else {
            $requested_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
            $allowed_types  = Product_Type_Registry::get_admin_types();
            $type           = in_array( $requested_type, $allowed_types, true ) ? $requested_type : 'physical';
        }

        $save_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        ?>
        <div class="wrap tejcart-admin-wrap tejcart-product-form-wrap" data-tejcart-type="<?php echo esc_attr( $type ); ?>">
            <?php $this->render_header( $product, $is_edit, $save_error ); ?>

            <form method="post"
                  action="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-products&action=save' ) ); ?>"
                  class="tejcart-product-form"
                  id="tejcart-product-form"
                  data-tejcart-product-form="1"
                  data-tejcart-product-id="<?php echo esc_attr( $is_edit ? (int) $product->get_id() : 0 ); ?>"
                  novalidate>
                <?php wp_nonce_field( 'tejcart_save_product', 'tejcart_product_nonce' ); ?>
                <?php if ( $is_edit ) : ?>
                    <input type="hidden" name="product_id" value="<?php echo esc_attr( (int) $product->get_id() ); ?>" />
                <?php endif; ?>

                <div class="tejcart-product-grid">
                    <div class="tejcart-product-main">
                        <?php

                        if ( ! $is_edit ) {
                            $this->render_type_picker_hero( $type );
                        }

                        $visible_sections = $this->get_tabs( $type );
                        $section_renderers = array(
                            'overview'   => 'render_tab_overview',
                            'pricing'    => 'render_tab_pricing',
                            'inventory'  => 'render_tab_inventory',
                            'shipping'   => 'render_tab_shipping',
                            'files'      => 'render_tab_files',
                            'components' => 'render_tab_components',
                            'variations' => 'render_tab_variations',
                            'children'   => 'render_tab_children',
                            'links'      => 'render_tab_links',
                            'external'   => 'render_tab_external',
                        );

                        foreach ( $visible_sections as $sid => $section ) {
                            $method = $section_renderers[ $sid ] ?? null;
                            if ( $method && method_exists( $this, $method ) ) {
                                $this->{$method}( $product, $is_edit );
                                continue;
                            }

                            /**
                             * Render an addon-supplied tab on the admin
                             * product edit screen.
                             *
                             * Fires for any tab slug that doesn't have a
                             * built-in renderer. Addons that registered a
                             * custom tab on a custom product type listen
                             * here and emit the tab's HTML directly.
                             *
                             * @param string                $sid     Tab slug being rendered.
                             * @param Abstract_Product|null $product Product being edited (null when creating).
                             * @param bool                  $is_edit Whether we're editing an existing product.
                             * @param string                $type    Current product type.
                             */
                            do_action( 'tejcart_product_form_render_tab', $sid, $product, $is_edit, $type );
                        }
                        ?>
                    </div>
                    <aside class="tejcart-product-sidebar">
                        <?php
                        $this->render_publish_card( $product, $is_edit );

                        if ( $is_edit ) {
                            $this->render_type_card( $product, $is_edit );
                        }
                        $this->render_organization_card( $product, $is_edit );
                        $this->render_images_card( $product, $is_edit );
                        ?>
                    </aside>
                </div>
            </form>
            <?php $this->render_confirm_modal(); ?>
        </div>
        <?php
    }

    /**
     * Shared confirmation modal. Any `<a data-tejcart-confirm>` click is
     * intercepted by JS, which reads the link's data-confirm-title/message/
     * button/tone attrs into this modal and on confirm navigates to the
     * link's href. Replaces `onclick="return confirm(…)"` with a modal
     * that can actually be styled + themed.
     */
    private function render_confirm_modal(): void {
        ?>
        <div class="tejcart-modal" data-tejcart-modal hidden role="dialog" aria-modal="true" aria-labelledby="tejcart-modal-title">
            <div class="tejcart-modal-backdrop" data-modal-close></div>
            <div class="tejcart-modal-dialog">
                <div class="tejcart-modal-icon" data-modal-icon aria-hidden="true">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <h2 class="tejcart-modal-title" id="tejcart-modal-title" data-modal-title></h2>
                <p class="tejcart-modal-message" data-modal-message></p>
                <div class="tejcart-modal-actions">
                    <button type="button" class="button tejcart-btn-ghost" data-modal-close>
                        <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                    </button>
                    <a href="#" class="button button-primary tejcart-modal-confirm" data-modal-confirm>
                        <?php esc_html_e( 'Confirm', 'tejcart' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Parse the variation_attr_* POST arrays into the attribute shape
     * expected by Variable_Product::set_attributes() and persist them.
     *
     * Extracted so the generate-variations handler can commit the
     * attributes the merchant just typed in without forcing a prior
     * full-form Save.
     *
     * @param Abstract_Product $product Product to apply attributes to.
     */
    private function apply_attributes_from_post( Abstract_Product $product ): void {
        if ( ! method_exists( $product, 'set_attributes' ) ) {
            return;
        }
        // Nonce verified upstream by handle_generate_variations() / handle_save() before this private helper runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['variation_attr_name'] ) || ! is_array( $_POST['variation_attr_name'] ) ) {
            return;
        }

        // Nonce handled upstream. Each entry is sanitized in the loop below.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $names    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['variation_attr_name'] ) );
        $values_r = isset( $_POST['variation_attr_values'] ) ? (array) wp_unslash( $_POST['variation_attr_values'] ) : array();
        $vis_r    = isset( $_POST['variation_attr_visible'] ) ? (array) wp_unslash( $_POST['variation_attr_visible'] ) : array();
        $used_r   = isset( $_POST['variation_attr_used'] ) ? (array) wp_unslash( $_POST['variation_attr_used'] ) : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $attrs = array();
        foreach ( $names as $i => $name ) {
            if ( '' === trim( (string) $name ) ) {
                continue;
            }
            $raw_values = isset( $values_r[ $i ] ) ? (string) $values_r[ $i ] : '';

            $parts = preg_split( '/[\r\n\|]+/', $raw_values );
            $parts = array_values( array_filter( array_map( 'trim', (array) $parts ) ) );

            $attrs[] = array(
                'name'                => $name,
                'values'              => array_map( 'sanitize_text_field', $parts ),
                'visible'             => ! empty( $vis_r[ $i ] ),
                'used_for_variations' => ! empty( $used_r[ $i ] ),
            );
        }

        $product->set_attributes( $attrs );
    }

    /**
     * Generate variation child products from a variable parent's attribute
     * matrix. Idempotent — combinations that already have a matching
     * variation row (by the variation_attributes meta) are skipped.
     *
     * @param int $product_id Parent variable product ID.
     * @return void
     */
    public function handle_generate_variations( int $product_id ): void {
        // Verify the request authenticity (nonce) before checking the
        // capability, per the handler convention used throughout TejCart.
        check_admin_referer( 'tejcart_generate_variations_' . $product_id );

        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            if ( $this->wants_json() ) {
                wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'tejcart' ) ), 403 );
            }
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $parent = Product_Factory::get_product( $product_id );
        if ( ! $parent || ! method_exists( $parent, 'get_attributes' ) || 'variable' !== $parent->get_type() ) {
            $this->generate_variations_exit( $product_id, 'error', 'variation_parent_missing' );
        }

        $is_full_form_post = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' )
            && isset( $_POST['variation_attr_name'] );

        if ( $is_full_form_post ) {
            $this->apply_core_fields( $parent );

            $saved_ok = $parent->save();
            if ( $saved_ok ) {
                $this->apply_meta_fields( $parent );
                $this->apply_taxonomies( $parent );

                $this->apply_type_specific( $parent );

                if ( method_exists( $parent, 'get_meta' ) && $parent->get_meta( '_tejcart_auto_draft' ) ) {
                    $parent->update_meta( '_tejcart_auto_draft', '' );
                }

                $parent = Product_Factory::get_product( $product_id );
            }
        }

        $attrs = (array) $parent->get_attributes();
        $variation_attrs = array_values( array_filter( $attrs, static function ( $a ) {
            return ! empty( $a['used_for_variations'] ) && ! empty( $a['name'] ) && ! empty( $a['values'] );
        } ) );

        if ( empty( $variation_attrs ) ) {
            $this->generate_variations_exit( $product_id, 'error', 'no_variation_attrs' );
        }

        $combinations = array( array() );
        foreach ( $variation_attrs as $attr ) {
            $next = array();
            foreach ( $combinations as $combo ) {
                foreach ( (array) $attr['values'] as $value ) {
                    $next[] = $combo + array( (string) $attr['name'] => (string) $value );
                }
            }
            $combinations = $next;
        }

        if ( count( $combinations ) > 200 ) {
            $this->generate_variations_exit( $product_id, 'error', 'too_many_variations' );
        }

        $existing = array();
        foreach ( (array) $parent->get_variations() as $variation ) {
            $vattr = (array) $variation->get_attributes();
            ksort( $vattr );
            $existing[ wp_json_encode( $vattr ) ] = (int) $variation->get_id();
        }

        $created  = 0;
        $skipped  = 0;
        $template_sku  = (string) $parent->get_sku();
        $base_price    = (string) $parent->get_regular_price();

        foreach ( $combinations as $combo ) {
            $key = $combo;
            ksort( $key );
            if ( isset( $existing[ wp_json_encode( $key ) ] ) ) {
                $skipped++;
                continue;
            }

            $variation = new \TejCart\Product\Product_Types\Variation();
            $suffix    = strtoupper( implode( '-', array_map( 'sanitize_title', $combo ) ) );
            $variation->set_name( $parent->get_name() );
            $variation->set_status( 'publish' );
            if ( '' !== $template_sku ) {
                $base_sku  = $template_sku . '-' . $suffix;
                $candidate = $base_sku;
                $i         = 2;
                while ( \TejCart\Product\Product_Factory::sku_exists( $candidate ) > 0 ) {
                    $candidate = $base_sku . '-' . $i++;
                }
                $variation->set_sku( $candidate );
            }
            if ( '' !== $base_price ) {
                $variation->set_price( $base_price );
            }
            $variation->set_stock_status( 'instock' );

            $vid = $variation->save();
            if ( $vid ) {
                $variation->update_meta( '_variation_parent_id', (int) $product_id );
                $variation->update_meta( '_variation_attributes', wp_json_encode( $combo ) );
                $created++;
            }
        }

        wp_cache_delete( 'tejcart_product_' . $product_id, 'tejcart' );

        if ( $this->wants_json() ) {
            wp_send_json_success( array(
                'created' => $created,
                'skipped' => $skipped,
                /* translators: 1: created count, 2: skipped count */
                'message' => sprintf( __( 'Created %1$d new variation(s), skipped %2$d existing combination(s).', 'tejcart' ), $created, $skipped ),
            ) );
        }

        $args = array(
            'page'             => 'tejcart-products',
            'action'           => 'edit',
            'product_id'       => $product_id,
            'variations_done'  => 1,
            'created'          => $created,
            'skipped'          => $skipped,
        );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Did the caller ask for a JSON response? We honor `_tejcart_json=1`
     * in the query string (set by our fetch() client) plus the standard
     * `Accept: application/json` header. Keeps the classic redirect flow
     * available for non-JS clients.
     */
    private function wants_json(): bool {
        if ( ! empty( $_GET['_tejcart_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }
        $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
        return false !== stripos( $accept, 'application/json' );
    }

    /**
     * Exit from handle_generate_variations with either a JSON response
     * or a redirect to the edit page — depending on whether the caller
     * is using the classic form action or our AJAX client.
     *
     * @param int    $product_id
     * @param string $tone       'error' or 'success'
     * @param string $code       Error code for the redirect query arg.
     * @return void              Never returns.
     */
    private function generate_variations_exit( int $product_id, string $tone, string $code ): void {
        if ( $this->wants_json() ) {
            if ( 'error' === $tone ) {
                wp_send_json_error( array(
                    'code'    => $code,
                    'message' => $this->error_message( $code ),
                ), 400 );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . $product_id . '&error=' . $code ) );
        exit;
    }

    /**
     * Handle the product save POST request. Called from Menu.php's
     * admin-post router. Redirects back to the edit screen on success or
     * with an error code query var on failure.
     *
     * @return void
     */
    public function handle_save(): void {
        if ( ! isset( $_POST['tejcart_product_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_product_nonce'] ) ), 'tejcart_save_product' )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tejcart' ) );
        }

        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $posted_type = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : 'physical';

        if ( $product_id ) {
            $product = Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                wp_die( esc_html__( 'Product not found.', 'tejcart' ) );
            }
        } else {
            $product = $this->instantiate_by_type( $posted_type );
        }

        $this->apply_core_fields( $product );

        if ( '' === $product->get_name() ) {
            $this->redirect_with_error( $product_id, 'name_required' );
        }

        if ( $product instanceof \TejCart\Product\Product_Types\Variable_Product
            && 'publish' === $product->get_status()
            && empty( $product->get_variations() )
        ) {
            $product->set_status( 'draft' );
            set_transient(
                'tejcart_product_form_notice_' . get_current_user_id(),
                'variable_no_variations',
                30
            );
        }

        $saved_id = $product->save();
        if ( ! $saved_id ) {
            $error = method_exists( $product, 'get_last_save_error' ) ? $product->get_last_save_error() : null;
            $code  = ( $error && 'tejcart_duplicate_sku' === $error->get_error_code() ) ? 'duplicate_sku' : 'save_failed';
            $this->redirect_with_error( $product_id, $code );
        }

        $this->apply_meta_fields( $product );
        $this->apply_taxonomies( $product );
        $this->apply_type_specific( $product );

        if ( method_exists( $product, 'get_meta' ) && $product->get_meta( '_tejcart_auto_draft' ) ) {
            $product->update_meta( '_tejcart_auto_draft', '' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $saved_id . '&saved=1' ) );
        exit;
    }

    /**
     * Create a blank draft product of the given type and redirect to its
     * edit screen. Used for product types (currently just `variable`) whose
     * editor depends on a saved parent row before secondary UI (variations)
     * becomes usable.
     *
     * The draft is tagged with the `_tejcart_auto_draft` meta so orphaned
     * rows left behind by merchants who abandon the flow can be pruned by
     * future cleanup routines.
     */
    private function create_auto_draft_and_redirect( string $type ): void {
        $product = $this->instantiate_by_type( $type );
        $product->set_status( 'draft' );

        $saved_id = $product->save();
        if ( ! $saved_id ) {
            return;
        }

        $product->update_meta( '_tejcart_auto_draft', '1' );

        wp_safe_redirect(
            admin_url(
                'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $saved_id . '&auto_draft=1'
            )
        );
        exit;
    }

    /**
     * Instantiate the correct product-type class for a new product.
     *
     * Resolves the class via {@see Product_Type_Registry::get_class_map()}
     * so types registered by bundled modules (gift_card) or third-party
     * addons (subscription, etc.) instantiate correctly instead of silently
     * falling back to a physical product.
     */
    private function instantiate_by_type( string $type ): Abstract_Product {
        $types = Product_Type_Registry::get_types();
        $definition = $types[ $type ] ?? null;

        // Only honour types that are admin-creatable. An unknown or
        // admin=false slug (e.g. `variation`) falls back to physical so a
        // crafted POST cannot instantiate an arbitrary class.
        if ( is_array( $definition ) && ! empty( $definition['admin'] ) ) {
            $class_map = Product_Type_Registry::get_class_map( $type );
            $class     = $class_map[ $type ] ?? '';
            if ( '' !== $class && class_exists( $class ) ) {
                $instance = new $class();
                if ( $instance instanceof Abstract_Product ) {
                    return $instance;
                }
            }
        }

        return new Physical_Product();
    }

    /**
     * Apply the base product columns from POST data.
     */
    private function apply_core_fields( Abstract_Product $product ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce handled upstream.
        $product->set_name( isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '' );
        $product->set_slug( isset( $_POST['product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['product_slug'] ) ) : '' );
        $product->set_sku( isset( $_POST['product_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['product_sku'] ) ) : '' );
        $product->set_status( isset( $_POST['product_status'] ) ? sanitize_text_field( wp_unslash( $_POST['product_status'] ) ) : 'publish' );
        $product->set_description( isset( $_POST['product_description'] ) ? wp_kses_post( wp_unslash( $_POST['product_description'] ) ) : '' );
        $product->set_short_description( isset( $_POST['product_short_description'] ) ? wp_kses_post( wp_unslash( $_POST['product_short_description'] ) ) : '' );

        $price_is_derived = Product_Type_Registry::type_supports( $product->get_type(), 'derived_price' );
        if ( ! $price_is_derived ) {
            $product->set_price( isset( $_POST['product_price'] ) && '' !== $_POST['product_price'] ? sanitize_text_field( wp_unslash( $_POST['product_price'] ) ) : '' );
            $product->set_sale_price( isset( $_POST['product_sale_price'] ) && '' !== $_POST['product_sale_price'] ? sanitize_text_field( wp_unslash( $_POST['product_sale_price'] ) ) : '' );
        }
        $product->set_manage_stock( ! empty( $_POST['manage_stock'] ) );
        $product->set_stock_quantity( isset( $_POST['stock_quantity'] ) && '' !== $_POST['stock_quantity'] ? absint( $_POST['stock_quantity'] ) : null );
        $product->set_stock_status( isset( $_POST['stock_status'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_status'] ) ) : 'instock' );
        $product->set_weight( isset( $_POST['product_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['product_weight'] ) ) : '' );
        $product->set_dimensions( array(
            'length' => isset( $_POST['dimension_length'] ) ? sanitize_text_field( wp_unslash( $_POST['dimension_length'] ) ) : '',
            'width'  => isset( $_POST['dimension_width'] )  ? sanitize_text_field( wp_unslash( $_POST['dimension_width'] ) )  : '',
            'height' => isset( $_POST['dimension_height'] ) ? sanitize_text_field( wp_unslash( $_POST['dimension_height'] ) ) : '',
        ) );
        $product->set_image_id( isset( $_POST['product_image_id'] ) ? absint( $_POST['product_image_id'] ) : 0 );

        $image_id_posted = isset( $_POST['product_image_id'] ) ? absint( $_POST['product_image_id'] ) : 0;
        if ( $image_id_posted > 0 && isset( $_POST['product_image_alt'] ) ) {
            $alt = sanitize_text_field( wp_unslash( (string) $_POST['product_image_alt'] ) );
            update_post_meta( $image_id_posted, '_wp_attachment_image_alt', $alt );
        }

        $gallery_ids = array();
        if ( ! empty( $_POST['product_gallery_ids'] ) ) {
            $gallery_ids = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['product_gallery_ids'] ) ) ) );
            $gallery_ids = array_values( array_filter( $gallery_ids ) );
        }
        $product->set_gallery_ids( $gallery_ids );
        // phpcs:enable
    }

    /**
     * Persist the meta-backed fields (visibility, featured, tax class, etc.)
     * — must run after the row exists, i.e. after save().
     */
    private function apply_meta_fields( Abstract_Product $product ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( method_exists( $product, 'set_catalog_visibility' ) ) {
            $product->set_catalog_visibility( isset( $_POST['product_catalog_visibility'] ) ? sanitize_key( wp_unslash( $_POST['product_catalog_visibility'] ) ) : 'visible' );
        }

        if ( method_exists( $product, 'set_featured' ) ) {
            $product->set_featured( ! empty( $_POST['product_featured'] ) );
        }

        if ( method_exists( $product, 'set_backorders' ) ) {
            $product->set_backorders( isset( $_POST['product_backorders'] ) ? sanitize_key( wp_unslash( $_POST['product_backorders'] ) ) : 'no' );
        }

        if ( method_exists( $product, 'set_tax_class' ) ) {
            $product->set_tax_class( isset( $_POST['product_tax_class'] ) ? sanitize_text_field( wp_unslash( $_POST['product_tax_class'] ) ) : '' );
        }

        if ( method_exists( $product, 'set_shipping_class' ) ) {
            $product->set_shipping_class( isset( $_POST['product_shipping_class'] ) ? sanitize_key( wp_unslash( $_POST['product_shipping_class'] ) ) : '' );
        }

        if ( method_exists( $product, 'set_min_purchase_quantity' ) ) {
            $product->set_min_purchase_quantity( isset( $_POST['product_min_qty'] ) ? (int) $_POST['product_min_qty'] : 1 );
        }

        if ( method_exists( $product, 'set_max_purchase_quantity' ) ) {
            $product->set_max_purchase_quantity( isset( $_POST['product_max_qty'] ) ? (int) $_POST['product_max_qty'] : 0 );
        }

        if ( method_exists( $product, 'set_sold_individually' ) ) {
            $product->set_sold_individually( ! empty( $_POST['product_sold_individually'] ) );
        }

        $from_raw = isset( $_POST['product_sale_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['product_sale_date_from'] ) ) : '';
        $to_raw   = isset( $_POST['product_sale_date_to'] )   ? sanitize_text_field( wp_unslash( $_POST['product_sale_date_to'] ) )   : '';
        if ( method_exists( $product, 'set_sale_date_from' ) ) {
            $product->set_sale_date_from( $from_raw ? (int) strtotime( $from_raw . ':00 UTC' ) : 0 );
        }
        if ( method_exists( $product, 'set_sale_date_to' ) ) {
            $product->set_sale_date_to( $to_raw ? (int) strtotime( $to_raw . ':00 UTC' ) : 0 );
        }

        if ( method_exists( $product, 'set_upsell_ids' ) ) {
            $product->set_upsell_ids( $this->parse_id_list( isset( $_POST['product_upsell_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['product_upsell_ids'] ) ) : '' ) );
        }
        if ( method_exists( $product, 'set_crosssell_ids' ) ) {
            $product->set_crosssell_ids( $this->parse_id_list( isset( $_POST['product_crosssell_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['product_crosssell_ids'] ) ) : '' ) );
        }
        if ( method_exists( $product, 'set_related_ids' ) ) {
            $product->set_related_ids( $this->parse_id_list( isset( $_POST['product_related_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['product_related_ids'] ) ) : '' ) );
        }
        // phpcs:enable
    }

    /**
     * Assign categories, tags and brand terms to the product.
     */
    private function apply_taxonomies( Abstract_Product $product ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $pid = (int) $product->get_id();
        if ( $pid <= 0 ) {
            return;
        }

        $cats = isset( $_POST['product_categories'] ) ? array_map( 'intval', (array) $_POST['product_categories'] ) : array();
        $tags = isset( $_POST['product_tags'] )       ? array_map( 'intval', (array) $_POST['product_tags'] )       : array();
        $brds = isset( $_POST['product_brands'] )     ? array_map( 'intval', (array) $_POST['product_brands'] )     : array();

        Product_Taxonomy::set_product_categories( $pid, $cats );
        Product_Taxonomy::set_product_tags( $pid, $tags );
        Product_Taxonomy::set_product_brands( $pid, $brds );
        // phpcs:enable
    }

    /**
     * Persist fields that only apply to specific product types.
     */
    private function apply_type_specific( Abstract_Product $product ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( $product instanceof Bundle_Product && isset( $_POST['bundled_product_id'] ) && is_array( $_POST['bundled_product_id'] ) ) {
            $ids   = array_map( 'absint', (array) wp_unslash( $_POST['bundled_product_id'] ) );
            $qtys  = isset( $_POST['bundled_quantity'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bundled_quantity'] ) ) : array();
            $discs = isset( $_POST['bundled_discount'] ) ? array_map( 'floatval', (array) wp_unslash( $_POST['bundled_discount'] ) ) : array();

            $items = array();
            foreach ( $ids as $i => $pid ) {
                if ( $pid <= 0 ) {
                    continue;
                }
                $items[] = array(
                    'product_id' => $pid,
                    'quantity'   => isset( $qtys[ $i ] )  ? max( 1, (int) $qtys[ $i ] )                 : 1,
                    'discount'   => isset( $discs[ $i ] ) ? max( 0.0, min( 100.0, (float) $discs[ $i ] ) ) : 0.0,
                );
            }
            $product->set_bundled_items( $items );
        }

        if ( $product instanceof Digital_Product && isset( $_POST['download_file_name'] ) && is_array( $_POST['download_file_name'] ) ) {
            $names = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['download_file_name'] ) );
            $urls  = isset( $_POST['download_file_url'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['download_file_url'] ) ) : array();

            $files = array();
            foreach ( $names as $i => $name ) {
                $url = $urls[ $i ] ?? '';
                if ( '' === $name && '' === $url ) {
                    continue;
                }
                $files[] = array( 'name' => $name, 'file' => $url );
            }
            $product->update_meta( '_download_files', $files );
        }

        if ( $product instanceof External_Product ) {
            $product->update_meta(
                '_product_url',
                isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : ''
            );
            $product->update_meta(
                '_button_text',
                isset( $_POST['product_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['product_button_text'] ) ) : ''
            );
        }

        if ( isset( $_POST['grouped_product_ids'] ) ) {
            $ids = $this->parse_id_list( sanitize_text_field( wp_unslash( $_POST['grouped_product_ids'] ) ) );
            $product->update_meta( '_grouped_products', wp_json_encode( $ids ) );
        }

        if ( method_exists( $product, 'set_attributes' ) && isset( $_POST['variation_attr_name'] ) && is_array( $_POST['variation_attr_name'] ) ) {
            $this->apply_attributes_from_post( $product );
        }

        if ( isset( $_POST['variation_id'] ) && is_array( $_POST['variation_id'] ) ) {
            $v_ids       = array_map( 'absint', (array) wp_unslash( $_POST['variation_id'] ) );
            $v_skus      = isset( $_POST['variation_sku'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['variation_sku'] ) ) : array();
            // Numeric arrays — coerced to floats/ints downstream.
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $v_prices    = isset( $_POST['variation_price'] ) ? (array) wp_unslash( $_POST['variation_price'] ) : array();
            $v_sales     = isset( $_POST['variation_sale_price'] ) ? (array) wp_unslash( $_POST['variation_sale_price'] ) : array();
            $v_stocks    = isset( $_POST['variation_stock_quantity'] ) ? (array) wp_unslash( $_POST['variation_stock_quantity'] ) : array();
            $v_statuses  = isset( $_POST['variation_status'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['variation_status'] ) ) : array();
            $v_min_qtys  = isset( $_POST['variation_min_qty'] ) ? (array) wp_unslash( $_POST['variation_min_qty'] ) : array();
            $v_max_qtys  = isset( $_POST['variation_max_qty'] ) ? (array) wp_unslash( $_POST['variation_max_qty'] ) : array();
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $v_img_ids   = isset( $_POST['variation_image_id'] )
                ? array_map( 'absint', (array) wp_unslash( $_POST['variation_image_id'] ) )
                : array();

            $v_sold_one_ids = isset( $_POST['variation_sold_individually'] )
                ? array_map( 'absint', (array) wp_unslash( $_POST['variation_sold_individually'] ) )
                : array();
            $v_sold_one_set = array_fill_keys( array_filter( $v_sold_one_ids ), true );

            foreach ( $v_ids as $i => $vid ) {
                if ( $vid <= 0 ) {
                    continue;
                }

                $variation = Product_Factory::get_product( $vid );
                if ( ! $variation || 'variation' !== $variation->get_type() ) {
                    continue;
                }

                $variation->set_sku( (string) ( $v_skus[ $i ] ?? '' ) );
                $variation->set_price( '' === ( $v_prices[ $i ] ?? '' ) ? '' : sanitize_text_field( (string) $v_prices[ $i ] ) );
                $variation->set_sale_price( '' === ( $v_sales[ $i ] ?? '' ) ? '' : sanitize_text_field( (string) $v_sales[ $i ] ) );

                $stock_raw = $v_stocks[ $i ] ?? '';
                $variation->set_stock_quantity( '' === $stock_raw ? null : max( 0, (int) $stock_raw ) );
                $variation->set_manage_stock( '' !== $stock_raw );

                $status = in_array( $v_statuses[ $i ] ?? '', array( 'publish', 'draft' ), true ) ? $v_statuses[ $i ] : 'publish';
                $variation->set_status( $status );

                if ( method_exists( $variation, 'set_min_purchase_quantity' ) ) {
                    $variation->set_min_purchase_quantity( isset( $v_min_qtys[ $i ] ) ? (int) $v_min_qtys[ $i ] : 1 );
                }
                if ( method_exists( $variation, 'set_max_purchase_quantity' ) ) {
                    $variation->set_max_purchase_quantity( isset( $v_max_qtys[ $i ] ) && '' !== $v_max_qtys[ $i ] ? (int) $v_max_qtys[ $i ] : 0 );
                }
                if ( method_exists( $variation, 'set_sold_individually' ) ) {
                    $variation->set_sold_individually( isset( $v_sold_one_set[ $vid ] ) );
                }

                if ( array_key_exists( $i, $v_img_ids ) ) {
                    $variation->set_image_id( (int) $v_img_ids[ $i ] );
                }

                $variation->save();
            }

            wp_cache_delete( 'tejcart_product_' . (int) $product->get_id(), 'tejcart' );
        }
        // phpcs:enable

        /**
         * Fires after the core type-specific POST handling has run, so
         * module-supplied product types (e.g. the bundled Gift Cards
         * module) can persist their own POSTed fields against the same
         * product instance without forking the core save path.
         *
         * Implementations should bail unless `$product` is an instance
         * of their concrete class. Nonce + capability checks have
         * already been performed by {@see handle_save()}, so the action
         * is safe to consume `$_POST` directly.
         *
         * @param Abstract_Product $product Saved product instance.
         */
        do_action( 'tejcart_product_form_apply_type_specific', $product );
    }

    /**
     * Parse a comma-separated ID list into an array of positive integers.
     *
     * @param mixed $raw
     * @return int[]
     */
    private function parse_id_list( $raw ): array {
        if ( ! is_string( $raw ) ) {
            return array();
        }
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $ids   = array();
        foreach ( $parts as $part ) {
            $id = absint( $part );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Redirect back to the edit form with an error code. Never returns.
     */
    private function redirect_with_error( int $product_id, string $code ): void {
        $args = array(
            'page'       => 'tejcart-products',
            'action'     => $product_id ? 'edit' : 'add',
            'product_id' => $product_id ?: null,
            'error'      => $code,
        );
        $args = array_filter( $args, static fn( $v ) => null !== $v );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Page header: breadcrumb, title, status badge, save button.
     *
     * @param Abstract_Product|null $product    Loaded product or null on create.
     * @param bool                  $is_edit    True when editing an existing product.
     * @param string                $save_error Error code from a prior save attempt.
     * @return void
     */
    private function render_header( $product, bool $is_edit, string $save_error ): void {
        $title       = $is_edit ? $product->get_name() : __( 'Add New Product', 'tejcart' );
        $status      = $is_edit ? $product->get_status() : '';
        $back_url    = admin_url( 'admin.php?page=tejcart-products' );
        $permalink   = ( $is_edit && 'publish' === $status ) ? $product->get_permalink() : '';
        $status_meta = $is_edit ? $this->status_meta( $status ) : array( 'label' => __( 'New', 'tejcart' ), 'class' => 'new' );
        $saved_flag  = ! empty( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $low_stock   = $is_edit ? $this->low_stock_state( $product ) : null;
        $is_featured = $is_edit && method_exists( $product, 'is_featured' ) && $product->is_featured();

        ?>
        <div class="tejcart-product-header" data-tejcart-header>
            <div class="tejcart-product-header-bar">
                <div class="tejcart-product-header-left">
                    <a href="<?php echo esc_url( $back_url ); ?>" class="tejcart-back-link" aria-label="<?php esc_attr_e( 'Back to products', 'tejcart' ); ?>">
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                        <span class="tejcart-back-link-text"><?php esc_html_e( 'Products', 'tejcart' ); ?></span>
                    </a>
                    <span class="tejcart-header-divider" aria-hidden="true"></span>
                    <div class="tejcart-product-header-title">
                        <h1><?php echo esc_html( $title ?: __( 'Untitled product', 'tejcart' ) ); ?></h1>
                        <span class="tejcart-status-badge tejcart-status-<?php echo esc_attr( $status_meta['class'] ); ?>">
                            <?php echo esc_html( $status_meta['label'] ); ?>
                        </span>
                        <?php if ( $is_featured ) : ?>
                            <span class="tejcart-featured-badge" title="<?php esc_attr_e( 'This product is featured', 'tejcart' ); ?>">
                                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                                <?php esc_html_e( 'Featured', 'tejcart' ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $low_stock ) : ?>
                            <a href="#tejcart-panel-inventory" class="tejcart-stock-pill tejcart-stock-pill-<?php echo esc_attr( $low_stock['tone'] ); ?>">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <?php echo esc_html( $low_stock['label'] ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tejcart-product-header-actions">
                    <span class="tejcart-save-state" data-tejcart-save-state hidden>
                        <span class="tejcart-save-state-dot" aria-hidden="true"></span>
                        <span class="tejcart-save-state-text"><?php esc_html_e( 'Unsaved changes', 'tejcart' ); ?></span>
                    </span>
                    <?php if ( $permalink ) : ?>
                        <button type="button" class="button tejcart-btn-ghost tejcart-btn-copy"
                                data-tejcart-copy
                                data-copy-value="<?php echo esc_attr( $permalink ); ?>"
                                data-copied-label="<?php esc_attr_e( 'Copied!', 'tejcart' ); ?>"
                                aria-label="<?php esc_attr_e( 'Copy product link', 'tejcart' ); ?>"
                                title="<?php esc_attr_e( 'Copy product link', 'tejcart' ); ?>">
                            <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                            <span class="tejcart-btn-copy-text"><?php esc_html_e( 'Copy link', 'tejcart' ); ?></span>
                        </button>
                        <a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener" class="button tejcart-btn-ghost">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            <?php esc_html_e( 'View', 'tejcart' ); ?>
                        </a>
                    <?php endif; ?>
                    <button type="submit" form="tejcart-product-form" class="button button-primary tejcart-btn-save" data-tejcart-save-button>
                        <span class="tejcart-btn-save-text">
                            <?php echo $is_edit ? esc_html__( 'Save changes', 'tejcart' ) : esc_html__( 'Create product', 'tejcart' ); ?>
                        </span>
                        <kbd class="tejcart-kbd" data-tejcart-kbd-save aria-hidden="true">⌘S</kbd>
                    </button>
                </div>
            </div>
        </div>

        <?php

        ?>
        <span class="wp-header-end"></span>

        <div class="tejcart-product-notices">
            <?php if ( $saved_flag ) : ?>
                <div class="notice notice-success tejcart-inline-notice is-dismissible" data-tejcart-auto-dismiss>
                    <p>
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <?php esc_html_e( 'Product saved.', 'tejcart' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( '' !== $save_error ) : ?>
                <div class="notice notice-error tejcart-inline-notice">
                    <p>
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <?php echo esc_html( $this->error_message( $save_error ) ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $_GET['variations_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php
                // Read-only success-notice display; no state change.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no state change.
                $created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no state change.
                $skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
                ?>
                <div class="notice notice-success tejcart-inline-notice is-dismissible">
                    <p>
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: number of created variations, 2: number skipped */
                            __( 'Done. Created %1$d new variation(s), skipped %2$d existing combination(s).', 'tejcart' ),
                            $created,
                            $skipped
                        ) );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $nx_pf_notice = get_transient( 'tejcart_product_form_notice_' . get_current_user_id() );
            if ( 'variable_no_variations' === $nx_pf_notice ) :
                delete_transient( 'tejcart_product_form_notice_' . get_current_user_id() );
                ?>
                <div class="notice notice-warning tejcart-inline-notice">
                    <p>
                        <span class="dashicons dashicons-info" aria-hidden="true"></span>
                        <?php esc_html_e( 'Variable products need at least one variation before they can be published. Status was set to Draft. Generate variations from attributes in the Variations section, then publish.', 'tejcart' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Build the section definition list for a given product type. Addons
     * can extend the map via the `tejcart_product_form_tabs` filter (name
     * kept for backwards compatibility with third-party code).
     *
     * @param string $type Product type slug.
     * @return array<string, array{label:string, icon:string}>
     */
    private function get_tabs( string $type ): array {
        $tabs = array(
            'overview'   => array( 'label' => __( 'Overview', 'tejcart' ),   'icon' => 'admin-page' ),
            'pricing'    => array( 'label' => __( 'Pricing', 'tejcart' ),    'icon' => 'money-alt' ),
            'inventory'  => array( 'label' => __( 'Inventory', 'tejcart' ),  'icon' => 'archive' ),
            'shipping'   => array( 'label' => __( 'Shipping', 'tejcart' ),   'icon' => 'airplane' ),
            'files'      => array( 'label' => __( 'Files', 'tejcart' ),      'icon' => 'download' ),
            'components' => array( 'label' => __( 'Bundle', 'tejcart' ),     'icon' => 'screenoptions' ),
            'variations' => array( 'label' => __( 'Variations', 'tejcart' ), 'icon' => 'editor-table' ),
            'children'   => array( 'label' => __( 'Children', 'tejcart' ),   'icon' => 'networking' ),
            'links'      => array( 'label' => __( 'Linked', 'tejcart' ),     'icon' => 'admin-links' ),
            'external'   => array( 'label' => __( 'External', 'tejcart' ),   'icon' => 'admin-site-alt3' ),
        );

        /**
         * Filter the master tab catalogue. Addons add a new tab here and a
         * matching renderer via `tejcart_product_form_render_tab` (or by
         * adding their own slug to a type's `tabs` list and hooking in via
         * the existing render pipeline).
         *
         * @param array<string, array{label:string,icon:string}> $tabs Tab catalogue.
         */
        $tabs = (array) apply_filters( 'tejcart_product_form_tab_catalog', $tabs );

        $allowed = Product_Type_Registry::get_tabs( $type );
        if ( empty( $allowed ) ) {
            $allowed = array( 'overview', 'links' );
        }

        $filtered = array();
        foreach ( $allowed as $tab_id ) {
            if ( isset( $tabs[ $tab_id ] ) ) {
                $filtered[ $tab_id ] = $tabs[ $tab_id ];
            }
        }

        /**
         * Filter the tabs shown on the product edit screen.
         *
         * @param array  $tabs Associative array of id => [label, icon].
         * @param string $type Current product type.
         */
        return (array) apply_filters( 'tejcart_product_form_tabs', $filtered, $type );
    }

    /**
     * Overview tab — name, slug, descriptions.
     */
    private function render_tab_overview( $product, bool $is_edit ): void {
        $name              = $is_edit ? $product->get_name() : '';
        $slug              = $is_edit ? $product->get_slug() : '';
        $description       = $is_edit ? $product->get_description() : '';
        $short_description = $is_edit ? $product->get_short_description() : '';
        $permalink         = $is_edit ? $product->get_permalink() : '';

        ?>
        <section class="tejcart-tab-panel is-active tejcart-section" data-panel="overview" id="tejcart-panel-overview">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Details', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Product name, URL, and descriptions shown to customers.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-field">
                        <label for="product_name"><?php esc_html_e( 'Product name', 'tejcart' ); ?> <span class="tejcart-required" aria-hidden="true">*</span></label>
                        <input type="text" id="product_name" name="product_name"
                               class="tejcart-input tejcart-input-lg" required
                               placeholder="<?php esc_attr_e( 'e.g. Premium wireless headphones', 'tejcart' ); ?>"
                               value="<?php echo esc_attr( $name ); ?>" />
                    </div>

                    <div class="tejcart-field">
                        <label for="product_slug"><?php esc_html_e( 'Permalink slug', 'tejcart' ); ?></label>
                        <input type="text" id="product_slug" name="product_slug"
                               class="tejcart-input"
                               placeholder="<?php esc_attr_e( 'auto-generated from the name', 'tejcart' ); ?>"
                               value="<?php echo esc_attr( $slug ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Used in the product URL. Leave blank to auto-generate; a numeric suffix is added if the slug is already taken.', 'tejcart' ); ?>
                            <?php if ( $permalink ) : ?>
                                <br />
                                <a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html( $permalink ); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="tejcart-field">
                        <label for="product_short_description"><?php esc_html_e( 'Short description', 'tejcart' ); ?></label>
                        <textarea id="product_short_description" name="product_short_description"
                                  rows="3" class="tejcart-input tejcart-textarea"
                                  placeholder="<?php esc_attr_e( 'Shown on the product listing and in previews.', 'tejcart' ); ?>"><?php echo esc_textarea( $short_description ); ?></textarea>
                    </div>

                    <div class="tejcart-field">
                        <label for="product_description"><?php esc_html_e( 'Full description', 'tejcart' ); ?></label>
                        <?php
                        wp_editor(
                            $description,
                            'product_description',
                            array(
                                'textarea_name' => 'product_description',
                                'media_buttons' => true,
                                'textarea_rows' => 12,
                                'teeny'         => false,
                                'editor_height' => 300,
                            )
                        );
                        ?>
                        <p class="description"><?php esc_html_e( 'Shown on the product detail page. Supports rich formatting.', 'tejcart' ); ?></p>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Pricing tab — regular, sale, scheduled sale, tax class.
     */
    private function render_tab_pricing( $product, bool $is_edit ): void {
        $regular       = $is_edit ? $product->get_regular_price() : '';
        $sale          = $is_edit ? $product->get_sale_price() : '';
        $sale_from_ts  = $is_edit ? (int) $product->get_sale_date_from() : 0;
        $sale_to_ts    = $is_edit ? (int) $product->get_sale_date_to()   : 0;
        $sale_from_in  = $sale_from_ts ? gmdate( 'Y-m-d\TH:i', $sale_from_ts ) : '';
        $sale_to_in    = $sale_to_ts   ? gmdate( 'Y-m-d\TH:i', $sale_to_ts )   : '';
        $tax_class     = $is_edit && method_exists( $product, 'get_tax_class' ) ? (string) $product->get_tax_class() : '';
        $currency      = get_option( 'tejcart_currency_symbol', '$' );
        $type          = $is_edit ? $product->get_type() : 'physical';

        $tax_classes = array();
        if ( class_exists( '\TejCart\Tax\Tax_Manager' ) ) {
            $tm          = new \TejCart\Tax\Tax_Manager();
            $tax_classes = (array) $tm->get_tax_classes();
        }

        $price_is_derived  = Product_Type_Registry::type_supports( $type, 'derived_price' );
        $price_input_attrs = $price_is_derived ? 'readonly tabindex="-1"' : '';
        $price_helper_text = '';
        if ( 'bundle' === $type ) {
            $price_helper_text = __( "Bundle price is calculated automatically from the bundled items' prices and per-item discounts.", 'tejcart' );
        } elseif ( 'variable' === $type ) {
            $price_helper_text = __( 'Variable products price from the cheapest variation. Set per-variation prices on the Variations tab.', 'tejcart' );

            if ( $is_edit && method_exists( $product, 'get_regular_price_range' ) ) {
                $format_range = function ( $range ) use ( $currency ) {
                    $min = $currency . number_format_i18n( (float) $range[0], 2 );
                    $max = $currency . number_format_i18n( (float) $range[1], 2 );
                    return ( (float) $range[0] === (float) $range[1] ) ? $min : $min . ' – ' . $max;
                };

                $reg_range  = $product->get_regular_price_range();
                $sale_range = method_exists( $product, 'get_sale_price_range' ) ? $product->get_sale_price_range() : null;

                if ( is_array( $reg_range ) ) {
                    $price_helper_text .= ' ' . sprintf(
                        /* translators: %s: regular price or range, already formatted with currency symbol. */
                        __( 'Regular: %s.', 'tejcart' ),
                        $format_range( $reg_range )
                    );
                }
                if ( is_array( $sale_range ) ) {
                    $price_helper_text .= ' ' . sprintf(
                        /* translators: %s: sale price or range, already formatted with currency symbol. */
                        __( 'Sale: %s.', 'tejcart' ),
                        $format_range( $sale_range )
                    );
                }
            }
        }
        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="pricing" id="tejcart-panel-pricing">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Pricing', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Set the regular price and an optional sale price.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <?php if ( $price_helper_text ) : ?>
                        <p class="tejcart-pricing-helper">
                            <?php echo esc_html( $price_helper_text ); ?>
                        </p>
                    <?php endif; ?>

                    <div class="tejcart-field-row">
                        <div class="tejcart-field">
                            <label for="product_price"><?php esc_html_e( 'Regular price', 'tejcart' ); ?></label>
                            <div class="tejcart-input-with-prefix">
                                <span class="tejcart-input-prefix"><?php echo esc_html( $currency ); ?></span>
                                <input type="number" id="product_price" name="product_price"
                                       class="tejcart-input" step="0.01" min="0" placeholder="0.00"
                                       value="<?php echo esc_attr( $regular ); ?>"
                                       <?php echo $price_input_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute literals only ?> />
                            </div>
                        </div>
                        <div class="tejcart-field">
                            <label for="product_sale_price"><?php esc_html_e( 'Sale price', 'tejcart' ); ?></label>
                            <div class="tejcart-input-with-prefix">
                                <span class="tejcart-input-prefix"><?php echo esc_html( $currency ); ?></span>
                                <input type="number" id="product_sale_price" name="product_sale_price"
                                       class="tejcart-input" step="0.01" min="0" placeholder="0.00"
                                       value="<?php echo esc_attr( $sale ); ?>"
                                       <?php echo $price_input_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute literals only ?> />
                            </div>
                        </div>
                    </div>

                    <details class="tejcart-disclosure" <?php echo ( $sale_from_ts || $sale_to_ts ) ? 'open' : ''; ?>>
                        <summary><?php esc_html_e( 'Schedule the sale', 'tejcart' ); ?></summary>
                        <div class="tejcart-field-row">
                            <div class="tejcart-field">
                                <label for="product_sale_date_from"><?php esc_html_e( 'Starts', 'tejcart' ); ?></label>
                                <input type="datetime-local" id="product_sale_date_from" name="product_sale_date_from"
                                       class="tejcart-input" value="<?php echo esc_attr( $sale_from_in ); ?>" />
                            </div>
                            <div class="tejcart-field">
                                <label for="product_sale_date_to"><?php esc_html_e( 'Ends', 'tejcart' ); ?></label>
                                <input type="datetime-local" id="product_sale_date_to" name="product_sale_date_to"
                                       class="tejcart-input" value="<?php echo esc_attr( $sale_to_in ); ?>" />
                            </div>
                        </div>
                        <p class="description"><?php esc_html_e( 'Dates are stored as UTC. Leave both blank for an always-on sale once a sale price is set.', 'tejcart' ); ?></p>
                    </details>

                    <div class="tejcart-field">
                        <label for="product_tax_class"><?php esc_html_e( 'Tax class', 'tejcart' ); ?></label>
                        <select id="product_tax_class" name="product_tax_class" class="tejcart-input">
                            <option value=""><?php esc_html_e( 'Standard', 'tejcart' ); ?></option>
                            <?php foreach ( $tax_classes as $tc ) :
                                $tc_name = isset( $tc['name'] ) ? (string) $tc['name'] : '';
                                if ( '' === $tc_name ) { continue; }
                                ?>
                                <option value="<?php echo esc_attr( $tc_name ); ?>" <?php selected( $tax_class, $tc_name ); ?>>
                                    <?php echo esc_html( $tc_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Determines which tax rate applies to this product. Manage tax classes under TejCart → Settings → Tax.', 'tejcart' ); ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Inventory tab — SKU, stock tracking, backorders, min/max qty, sold individually.
     */
    private function render_tab_inventory( $product, bool $is_edit ): void {
        $sku              = $is_edit ? $product->get_sku() : '';
        $manage_stock     = $is_edit ? (bool) $product->get_manage_stock() : false;
        $stock_qty        = $is_edit ? $product->get_stock_quantity() : '';
        $stock_status     = $is_edit ? $product->get_stock_status() : 'instock';
        $backorders       = $is_edit && method_exists( $product, 'get_backorders' ) ? $product->get_backorders() : 'no';
        $min_qty          = $is_edit && method_exists( $product, 'get_min_purchase_quantity' ) ? (int) $product->get_min_purchase_quantity() : 1;
        $max_qty          = $is_edit && method_exists( $product, 'get_max_purchase_quantity' ) ? (int) $product->get_max_purchase_quantity() : 0;
        $sold_individually = $is_edit && method_exists( $product, 'is_sold_individually' ) ? (bool) $product->is_sold_individually() : false;
        $type              = $is_edit ? $product->get_type() : 'physical';

        $inventory_helper = '';
        if ( 'bundle' === $type ) {
            $inventory_helper = __( "Bundles inherit availability from their bundled items — you generally don't need to track stock on the bundle row itself.", 'tejcart' );
        } elseif ( 'digital' === $type || 'virtual' === $type ) {
            $inventory_helper = __( 'Stock tracking is optional for non-physical products. Leave it off to allow unlimited purchases.', 'tejcart' );
        }
        $low_stock = $is_edit ? $this->low_stock_state( $product ) : null;
        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="inventory" id="tejcart-panel-inventory">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Inventory', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Track stock levels and purchase constraints.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <?php if ( $low_stock ) : ?>
                        <div class="tejcart-stock-banner tejcart-stock-banner-<?php echo esc_attr( $low_stock['tone'] ); ?>">
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <div>
                                <strong><?php echo esc_html( $low_stock['label'] ); ?></strong>
                                <p><?php echo esc_html( $low_stock['message'] ); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ( $inventory_helper ) : ?>
                        <p class="notice notice-info inline" style="margin:0 0 12px;padding:8px 12px;">
                            <?php echo esc_html( $inventory_helper ); ?>
                        </p>
                    <?php endif; ?>
                    <div class="tejcart-field">
                        <label for="product_sku"><?php esc_html_e( 'SKU', 'tejcart' ); ?></label>
                        <input type="text" id="product_sku" name="product_sku"
                               class="tejcart-input"
                               pattern="[A-Za-z0-9_\-]+"
                               placeholder="<?php esc_attr_e( 'e.g. HDPH-001', 'tejcart' ); ?>"
                               value="<?php echo esc_attr( $sku ); ?>" />
                        <p class="description"><?php esc_html_e( 'Stock Keeping Unit — must be unique across all products. Letters, numbers, hyphens and underscores only.', 'tejcart' ); ?></p>
                    </div>

                    <div class="tejcart-field">
                        <label class="tejcart-toggle">
                            <input type="checkbox" id="manage_stock" name="manage_stock" value="1" <?php checked( $manage_stock ); ?> />
                            <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                            <span class="tejcart-toggle-label"><?php esc_html_e( 'Track stock quantity for this product', 'tejcart' ); ?></span>
                        </label>
                    </div>

                    <div class="tejcart-field-row">
                        <div class="tejcart-field">
                            <label for="stock_quantity"><?php esc_html_e( 'Quantity on hand', 'tejcart' ); ?></label>
                            <input type="number" id="stock_quantity" name="stock_quantity"
                                   class="tejcart-input" min="0" step="1" placeholder="0"
                                   value="<?php echo esc_attr( null !== $stock_qty ? $stock_qty : '' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Only used when stock tracking is on.', 'tejcart' ); ?></p>
                        </div>
                        <div class="tejcart-field">
                            <label for="stock_status"><?php esc_html_e( 'Availability', 'tejcart' ); ?></label>
                            <select id="stock_status" name="stock_status" class="tejcart-input">
                                <option value="instock"     <?php selected( $stock_status, 'instock' ); ?>><?php esc_html_e( 'In stock', 'tejcart' ); ?></option>
                                <option value="outofstock"  <?php selected( $stock_status, 'outofstock' ); ?>><?php esc_html_e( 'Out of stock', 'tejcart' ); ?></option>
                                <option value="onbackorder" <?php selected( $stock_status, 'onbackorder' ); ?>><?php esc_html_e( 'On backorder', 'tejcart' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="tejcart-field">
                        <label for="product_backorders"><?php esc_html_e( 'Backorders', 'tejcart' ); ?></label>
                        <select id="product_backorders" name="product_backorders" class="tejcart-input">
                            <option value="no"     <?php selected( $backorders, 'no' ); ?>><?php esc_html_e( 'Do not allow', 'tejcart' ); ?></option>
                            <option value="notify" <?php selected( $backorders, 'notify' ); ?>><?php esc_html_e( 'Allow, notify customer at checkout', 'tejcart' ); ?></option>
                            <option value="yes"    <?php selected( $backorders, 'yes' ); ?>><?php esc_html_e( 'Allow silently', 'tejcart' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Lets customers purchase when the on-hand quantity reaches zero.', 'tejcart' ); ?></p>
                    </div>

                    <div class="tejcart-field-row">
                        <div class="tejcart-field">
                            <label for="product_min_qty"><?php esc_html_e( 'Minimum per order', 'tejcart' ); ?></label>
                            <input type="number" id="product_min_qty" name="product_min_qty"
                                   class="tejcart-input" min="1" step="1" value="<?php echo esc_attr( $min_qty ); ?>" />
                        </div>
                        <div class="tejcart-field">
                            <label for="product_max_qty"><?php esc_html_e( 'Maximum per order', 'tejcart' ); ?></label>
                            <input type="number" id="product_max_qty" name="product_max_qty"
                                   class="tejcart-input" min="0" step="1"
                                   placeholder="<?php esc_attr_e( 'No limit', 'tejcart' ); ?>"
                                   value="<?php echo $max_qty > 0 ? esc_attr( $max_qty ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Leave blank for no maximum.', 'tejcart' ); ?></p>
                        </div>
                    </div>

                    <div class="tejcart-field">
                        <label class="tejcart-toggle">
                            <input type="checkbox" id="product_sold_individually" name="product_sold_individually" value="1" <?php checked( $sold_individually ); ?> />
                            <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                            <span class="tejcart-toggle-label"><?php esc_html_e( 'Only one of this product can be in a cart at a time', 'tejcart' ); ?></span>
                        </label>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Shipping tab — weight, dimensions, shipping class (physical only).
     */
    private function render_tab_shipping( $product, bool $is_edit ): void {
        $weight         = $is_edit ? $product->get_weight() : '';
        $dimensions     = $is_edit ? (array) $product->get_dimensions() : array( 'length' => '', 'width' => '', 'height' => '' );
        $shipping_class = $is_edit && method_exists( $product, 'get_shipping_class' ) ? (string) $product->get_shipping_class() : '';
        $weight_unit    = get_option( 'tejcart_weight_unit', 'kg' );
        $dim_unit       = get_option( 'tejcart_dimension_unit', 'cm' );

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="shipping" id="tejcart-panel-shipping">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-airplane" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Shipping', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Weight and dimensions feed the shipping calculator.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-field">
                        <label for="product_weight">
                            <?php
                            /* translators: %s: weight unit, e.g. kg */
                            printf( esc_html__( 'Weight (%s)', 'tejcart' ), esc_html( $weight_unit ) );
                            ?>
                        </label>
                        <input type="text" id="product_weight" name="product_weight"
                               class="tejcart-input tejcart-input-narrow"
                               placeholder="0" value="<?php echo esc_attr( $weight ); ?>" />
                    </div>

                    <div class="tejcart-field">
                        <label class="tejcart-field-label">
                            <?php
                            /* translators: %s: dimension unit, e.g. cm */
                            printf( esc_html__( 'Dimensions (%s)', 'tejcart' ), esc_html( $dim_unit ) );
                            ?>
                        </label>
                        <div class="tejcart-dimensions">
                            <input type="text" name="dimension_length" class="tejcart-input"
                                   placeholder="<?php esc_attr_e( 'Length', 'tejcart' ); ?>"
                                   value="<?php echo esc_attr( $dimensions['length'] ?? '' ); ?>" />
                            <span class="tejcart-dimensions-sep">&times;</span>
                            <input type="text" name="dimension_width" class="tejcart-input"
                                   placeholder="<?php esc_attr_e( 'Width', 'tejcart' ); ?>"
                                   value="<?php echo esc_attr( $dimensions['width'] ?? '' ); ?>" />
                            <span class="tejcart-dimensions-sep">&times;</span>
                            <input type="text" name="dimension_height" class="tejcart-input"
                                   placeholder="<?php esc_attr_e( 'Height', 'tejcart' ); ?>"
                                   value="<?php echo esc_attr( $dimensions['height'] ?? '' ); ?>" />
                        </div>
                    </div>

                    <div class="tejcart-field">
                        <label for="product_shipping_class"><?php esc_html_e( 'Shipping class', 'tejcart' ); ?></label>
                        <?php
                        $shipping_terms = get_terms( array(
                            'taxonomy'   => Product_Taxonomy::SHIPPING_CLASS_TAXONOMY,
                            'hide_empty' => false,
                        ) );
                        if ( is_wp_error( $shipping_terms ) ) {
                            $shipping_terms = array();
                        }
                        ?>
                        <select id="product_shipping_class" name="product_shipping_class" class="tejcart-input">
                            <option value=""><?php esc_html_e( '— No class —', 'tejcart' ); ?></option>
                            <?php foreach ( $shipping_terms as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $shipping_class, $term->slug ); ?>>
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Groups products so a shipping zone can charge different rates per class.', 'tejcart' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . Product_Taxonomy::SHIPPING_CLASS_TAXONOMY ) ); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e( 'Manage classes', 'tejcart' ); ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Files tab — downloadable files (digital products).
     */
    private function render_tab_files( $product, bool $is_edit ): void {
        $files = ( $is_edit && $product instanceof Digital_Product ) ? $product->get_download_files() : array();

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="files" id="tejcart-panel-files">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Downloadable files', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Files delivered to the customer via signed download URLs after purchase.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <table class="widefat striped tejcart-repeater" id="tejcart-download-files">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'File name', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'File URL', 'tejcart' ); ?></th>
                                <th style="width:70px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( (array) $files as $df ) :
                                if ( ! is_array( $df ) ) { continue; } ?>
                                <tr class="tejcart-download-row">
                                    <td><input type="text" name="download_file_name[]"
                                               class="tejcart-input"
                                               value="<?php echo esc_attr( $df['name'] ?? '' ); ?>"
                                               placeholder="<?php esc_attr_e( 'e.g. Main file', 'tejcart' ); ?>" /></td>
                                    <td>
                                        <span class="tejcart-download-url-wrap">
                                            <input type="text" name="download_file_url[]"
                                                   class="tejcart-input"
                                                   value="<?php echo esc_url( $df['file'] ?? '' ); ?>"
                                                   placeholder="https://..." />
                                            <button type="button" class="button tejcart-upload-download-btn"><?php esc_html_e( 'Upload', 'tejcart' ); ?></button>
                                        </span>
                                    </td>
                                    <td><button type="button" class="button-link-delete tejcart-remove-download-row"><?php esc_html_e( 'Remove', 'tejcart' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="tejcart-add-download-row">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e( 'Add file', 'tejcart' ); ?>
                        </button>
                    </p>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Components tab — bundle items (bundle products).
     */
    private function render_tab_components( $product, bool $is_edit ): void {
        $items = ( $is_edit && $product instanceof Bundle_Product ) ? $product->get_bundled_items() : array();

        $resolved = array();
        $ids      = array_values( array_filter( array_map(
            static fn( $i ) => is_array( $i ) ? (int) ( $i['product_id'] ?? 0 ) : 0,
            (array) $items
        ) ) );
        if ( ! empty( $ids ) ) {
            $rows = Product_Factory::get_products( $ids );
            foreach ( $ids as $pid ) {
                if ( isset( $rows[ $pid ] ) ) {
                    $img_id = (int) $rows[ $pid ]->get_image_id();
                    $resolved[ $pid ] = array(
                        'name'  => $rows[ $pid ]->get_name(),
                        'sku'   => $rows[ $pid ]->get_sku(),
                        'thumb' => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
                    );
                }
            }
        }

        $current_pid = $is_edit ? (int) $product->get_id() : 0;

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="components" id="tejcart-panel-components">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Bundled products', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Pick the products included in this bundle. Bundle price is calculated from item prices with the per-item discount applied.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-bundle-builder"
                         data-tejcart-bundle-builder
                         data-rest-root="<?php echo esc_url( rest_url( 'tejcart/v1/products' ) ); ?>"
                         data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
                         data-current-id="<?php echo esc_attr( $current_pid ); ?>">

                        <div class="tejcart-bundle-search">
                            <span class="dashicons dashicons-search tejcart-bundle-search-icon" aria-hidden="true"></span>
                            <input type="search"
                                   class="tejcart-input tejcart-bundle-search-input"
                                   data-bundle-search
                                   placeholder="<?php esc_attr_e( 'Search products to add to this bundle…', 'tejcart' ); ?>"
                                   autocomplete="off" />
                            <ul class="tejcart-bundle-results" data-bundle-results hidden></ul>
                        </div>

                        <div class="tejcart-bundle-items-wrap <?php echo empty( $items ) ? 'is-empty' : ''; ?>">
                            <table class="widefat tejcart-bundle-items" id="tejcart-bundle-items">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                                        <th style="width:90px;"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                                        <th style="width:120px;"><?php esc_html_e( 'Discount', 'tejcart' ); ?></th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody data-bundle-tbody>
                                    <?php foreach ( (array) $items as $bi ) :
                                        if ( ! is_array( $bi ) ) { continue; }
                                        $bid    = (int) ( $bi['product_id'] ?? 0 );
                                        $meta   = $resolved[ $bid ] ?? null;
                                        /* translators: %d: product ID */
                                        $label  = $meta ? $meta['name'] : sprintf( __( 'Product #%d (missing)', 'tejcart' ), $bid );
                                        $sku    = $meta ? (string) $meta['sku'] : '';
                                        $thumb  = $meta ? (string) ( $meta['thumb'] ?? '' ) : '';
                                        ?>
                                        <tr class="tejcart-bundle-row" data-bundle-row data-product-id="<?php echo esc_attr( $bid ); ?>">
                                            <td class="tejcart-bundle-product-cell">
                                                <input type="hidden" name="bundled_product_id[]" value="<?php echo esc_attr( $bid ); ?>" />
                                                <div class="tejcart-bundle-product-info">
                                                    <?php if ( $thumb ) : ?>
                                                        <img class="tejcart-bundle-product-thumb is-img" src="<?php echo esc_url( $thumb ); ?>" alt="" />
                                                    <?php else : ?>
                                                        <span class="tejcart-bundle-product-thumb dashicons dashicons-products" aria-hidden="true"></span>
                                                    <?php endif; ?>
                                                    <span class="tejcart-bundle-product-text">
                                                        <strong><?php echo esc_html( $label ); ?></strong>
                                                        <?php if ( $sku ) : ?>
                                                            <span class="tejcart-muted"><?php echo esc_html( $sku ); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="number" name="bundled_quantity[]"
                                                       class="tejcart-input" min="1" step="1"
                                                       value="<?php echo esc_attr( $bi['quantity'] ?? 1 ); ?>" />
                                            </td>
                                            <td>
                                                <div class="tejcart-input-with-suffix">
                                                    <input type="number" name="bundled_discount[]"
                                                           class="tejcart-input" min="0" max="100" step="0.01"
                                                           value="<?php echo esc_attr( $bi['discount'] ?? 0 ); ?>" />
                                                    <span class="tejcart-input-suffix">%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <button type="button" class="tejcart-icon-btn tejcart-remove-bundle-row"
                                                        aria-label="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>"
                                                        title="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>">
                                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="tejcart-bundle-empty" data-bundle-empty>
                                <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                                <?php esc_html_e( 'No products in this bundle yet. Use the search above to add products.', 'tejcart' ); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Variations tab — for variable products. Lets merchants flag which
     * attributes drive variations (and supply pipe-separated values), and
     * lists existing variation children with quick edit/manage links.
     */
    private function render_tab_variations( $product, bool $is_edit ): void {
        $is_variable = $is_edit && method_exists( $product, 'get_variations' );

        $attributes = ( $is_edit && method_exists( $product, 'get_attributes' ) )
            ? (array) $product->get_attributes()
            : array();

        $variations = $is_variable ? (array) $product->get_variations() : array();

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="variations" id="tejcart-panel-variations">
            <?php if ( ! $is_edit ) : ?>
                <div class="tejcart-card">
                    <div class="tejcart-card-header">
                        <div class="tejcart-card-header-icon">
                            <span class="dashicons dashicons-editor-table" aria-hidden="true"></span>
                        </div>
                        <div class="tejcart-card-header-text">
                            <h2><?php esc_html_e( 'Variations', 'tejcart' ); ?></h2>
                            <p class="description">
                                <?php esc_html_e( 'Save the product first, then return here to define attributes and variations.', 'tejcart' ); ?>
                            </p>
                        </div>
                    </div>
                </div>
        </section>
                <?php return; ?>
            <?php endif; ?>

            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-tag" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Attributes', 'tejcart' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Add the attributes (e.g. Size, Colour) and the values customers can pick. Mark which attributes drive variations.', 'tejcart' ); ?>
                        </p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-attr-list" id="tejcart-variation-attrs">
                        <?php foreach ( $attributes as $idx => $attr ) :
                            $name      = (string) ( $attr['name'] ?? '' );
                            $values    = is_array( $attr['values'] ?? null ) ? $attr['values'] : array();
                            $visible   = ! empty( $attr['visible'] );
                            $used_var  = ! empty( $attr['used_for_variations'] );

                            ?>
                            <div class="tejcart-attr-card tejcart-variation-attr-row" data-attr-index="<?php echo esc_attr( (string) $idx ); ?>">
                                <div class="tejcart-attr-card-head">
                                    <div class="tejcart-field tejcart-attr-name-field">
                                        <label><?php esc_html_e( 'Attribute name', 'tejcart' ); ?></label>
                                        <input type="text" name="variation_attr_name[<?php echo esc_attr( (string) $idx ); ?>]" class="tejcart-input"
                                               placeholder="<?php esc_attr_e( 'e.g. Size, Colour', 'tejcart' ); ?>"
                                               value="<?php echo esc_attr( $name ); ?>" />
                                    </div>
                                    <button type="button" class="tejcart-icon-btn tejcart-remove-attr-row"
                                            aria-label="<?php esc_attr_e( 'Remove attribute', 'tejcart' ); ?>"
                                            title="<?php esc_attr_e( 'Remove attribute', 'tejcart' ); ?>">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                    </button>
                                </div>
                                <div class="tejcart-field">
                                    <label><?php esc_html_e( 'Values', 'tejcart' ); ?></label>
                                    <div class="tejcart-chip-editor" data-tejcart-chip-editor>
                                        <div class="tejcart-chip-editor-input-wrap">
                                            <div class="tejcart-chip-list" data-chip-list>
                                                <?php foreach ( $values as $val ) : ?>
                                                    <span class="tejcart-chip" data-chip-val="<?php echo esc_attr( $val ); ?>">
                                                        <span class="tejcart-chip-text"><?php echo esc_html( $val ); ?></span>
                                                        <button type="button" class="tejcart-chip-x" data-chip-remove aria-label="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>">&times;</button>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <input type="text" class="tejcart-chip-input" data-chip-input
                                                   placeholder="<?php esc_attr_e( 'Type a value and press Enter', 'tejcart' ); ?>" />
                                        </div>
                                        <textarea name="variation_attr_values[<?php echo esc_attr( (string) $idx ); ?>]" data-chip-source hidden><?php echo esc_textarea( implode( "\n", $values ) ); ?></textarea>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'Press Enter or comma to add each value. Click the × on a chip to remove it.', 'tejcart' ); ?></p>
                                </div>
                                <div class="tejcart-attr-card-toggles">
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" name="variation_attr_visible[<?php echo esc_attr( (string) $idx ); ?>]" value="1" <?php checked( $visible ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Show on product page', 'tejcart' ); ?></span>
                                    </label>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" name="variation_attr_used[<?php echo esc_attr( (string) $idx ); ?>]" value="1" <?php checked( $used_var ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Use for variations', 'tejcart' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p>
                        <button type="button" class="button" id="tejcart-add-attr-row">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e( 'Add attribute', 'tejcart' ); ?>
                        </button>
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Mark an attribute "Use for variations" to let customers pick it at checkout. Order here is the order shown on the product page.', 'tejcart' ); ?>
                    </p>
                </div>
            </div>

            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-editor-table" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Variations', 'tejcart' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Edit price, sale price, SKU, stock, and status inline. Save the parent to persist changes.', 'tejcart' ); ?>
                        </p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <?php

                    $generate_url = wp_nonce_url(
                        admin_url( 'admin.php?page=tejcart-products&action=generate_variations&product_id=' . (int) $product->get_id() ),
                        'tejcart_generate_variations_' . (int) $product->get_id()
                    );
                    ?>
                    <p class="tejcart-generate-variations-row">
                        <a href="<?php echo esc_url( $generate_url ); ?>"
                           class="button tejcart-generate-btn"
                           data-tejcart-generate-variations>
                            <span class="dashicons dashicons-grid-view" aria-hidden="true"></span>
                            <span class="tejcart-generate-btn-label"><?php esc_html_e( 'Generate variations from attributes', 'tejcart' ); ?></span>
                        </a>
                        <span class="description">
                            <?php esc_html_e( 'Creates a child variation for every combination of the attributes you marked "Used for variations". Existing combinations are skipped.', 'tejcart' ); ?>
                        </span>
                        <span class="tejcart-generate-feedback" data-generate-feedback hidden></span>
                    </p>

                    <?php if ( empty( $variations ) ) : ?>
                        <p>
                            <em><?php esc_html_e( 'No variations yet. Add attributes above with values, mark them "Used for variations", then click the button.', 'tejcart' ); ?></em>
                        </p>
                    <?php else : ?>
                        <?php $currency = get_option( 'tejcart_currency_symbol', '$' ); ?>
                        <div class="tejcart-variations-bulk" data-tejcart-variations-bulk>
                            <div class="tejcart-variations-bulk-head">
                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                <strong data-bulk-title><?php esc_html_e( 'Bulk edit all variations', 'tejcart' ); ?></strong>
                                <span class="description" data-bulk-hint><?php esc_html_e( 'Fill only the fields you want to apply. Tick rows below to limit the change to specific variations.', 'tejcart' ); ?></span>
                            </div>
                            <div class="tejcart-variations-bulk-fields">
                                <label class="tejcart-variations-bulk-field">
                                    <span><?php esc_html_e( 'Price', 'tejcart' ); ?></span>
                                    <div class="tejcart-input-with-prefix">
                                        <span class="tejcart-input-prefix"><?php echo esc_html( $currency ); ?></span>
                                        <input type="number" class="tejcart-input" step="0.01" min="0"
                                               placeholder="—" data-bulk-field="price" />
                                    </div>
                                </label>
                                <label class="tejcart-variations-bulk-field">
                                    <span><?php esc_html_e( 'Sale price', 'tejcart' ); ?></span>
                                    <div class="tejcart-input-with-prefix">
                                        <span class="tejcart-input-prefix"><?php echo esc_html( $currency ); ?></span>
                                        <input type="number" class="tejcart-input" step="0.01" min="0"
                                               placeholder="—" data-bulk-field="sale" />
                                    </div>
                                </label>
                                <label class="tejcart-variations-bulk-field">
                                    <span><?php esc_html_e( 'Stock', 'tejcart' ); ?></span>
                                    <input type="number" class="tejcart-input" min="0" step="1"
                                           placeholder="—" data-bulk-field="stock" />
                                </label>
                                <label class="tejcart-variations-bulk-field">
                                    <span><?php esc_html_e( 'Status', 'tejcart' ); ?></span>
                                    <select class="tejcart-input" data-bulk-field="status">
                                        <option value=""><?php esc_html_e( '— Keep —', 'tejcart' ); ?></option>
                                        <option value="publish"><?php esc_html_e( 'Published', 'tejcart' ); ?></option>
                                        <option value="draft"><?php esc_html_e( 'Draft', 'tejcart' ); ?></option>
                                    </select>
                                </label>
                                <button type="button" class="button button-primary tejcart-variations-bulk-apply"
                                        data-bulk-apply
                                        <?php /* translators: %d: number of variations the bulk edit will apply to. Used as a JS template; the placeholder is replaced client-side. */ ?>
                                        data-count-label="<?php esc_attr_e( 'Apply to %d variations', 'tejcart' ); ?>">
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                    <span data-bulk-apply-label><?php
                                        /* translators: %d: number of variations the bulk edit will apply to */
                                        printf( esc_html( _n( 'Apply to %d variation', 'Apply to %d variations', count( $variations ), 'tejcart' ) ), count( $variations ) );
                                    ?></span>
                                </button>
                            </div>
                            <p class="tejcart-variations-bulk-feedback" data-bulk-feedback hidden></p>
                        </div>

                        <table class="widefat striped tejcart-variations-table">
                            <thead>
                                <tr>
                                    <th style="width:36px;" class="tejcart-variations-check-col">
                                        <label class="tejcart-check" title="<?php esc_attr_e( 'Select all variations', 'tejcart' ); ?>">
                                            <input type="checkbox" data-bulk-check-all
                                                   aria-label="<?php esc_attr_e( 'Select all variations', 'tejcart' ); ?>" />
                                        </label>
                                    </th>
                                    <th style="width:60px;"><?php esc_html_e( 'Image', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Variation', 'tejcart' ); ?></th>
                                    <th style="width:140px;"><?php esc_html_e( 'SKU', 'tejcart' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'Price', 'tejcart' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'Sale price', 'tejcart' ); ?></th>
                                    <th style="width:90px;"><?php esc_html_e( 'Stock', 'tejcart' ); ?></th>
                                    <th style="width:130px;"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                    <th style="width:110px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $variations as $variation ) :
                                    $vid          = (int) $variation->get_id();
                                    $v_label      = (string) $variation->get_name();
                                    $v_sku        = (string) $variation->get_sku();
                                    $v_price      = (string) $variation->get_regular_price();
                                    $v_sale       = (string) $variation->get_sale_price();
                                    $v_stock      = $variation->get_stock_quantity();
                                    $v_status     = $variation->get_status();
                                    $v_min        = method_exists( $variation, 'get_min_purchase_quantity' ) ? (int) $variation->get_min_purchase_quantity() : 1;
                                    $v_max        = method_exists( $variation, 'get_max_purchase_quantity' ) ? (int) $variation->get_max_purchase_quantity() : 0;
                                    $v_sold_one   = method_exists( $variation, 'is_sold_individually' ) && $variation->is_sold_individually();
                                    $v_image_id   = (int) $variation->get_image_id();
                                    $v_thumb      = $v_image_id ? wp_get_attachment_image_url( $v_image_id, 'thumbnail' ) : '';
                                    $del_url      = wp_nonce_url(
                                        admin_url( 'admin.php?page=tejcart-products&action=delete&product_id=' . $vid ),
                                        'tejcart_delete_product_' . $vid
                                    );
                                    $has_limits   = $v_min > 1 || $v_max > 0 || $v_sold_one;
                                    ?>
                                    <tr data-variation-row>
                                        <td class="tejcart-variations-check-col">
                                            <label class="tejcart-check">
                                                <input type="checkbox" data-bulk-check-row
                                                       aria-label="<?php echo esc_attr( sprintf( /* translators: %s: variation label */ __( 'Select %s', 'tejcart' ), $v_label ?: ( '#' . $vid ) ) ); ?>" />
                                            </label>
                                        </td>
                                        <td>
                                            <button type="button"
                                                    class="tejcart-variation-thumb <?php echo $v_thumb ? 'has-image' : ''; ?>"
                                                    data-tejcart-variation-thumb
                                                    aria-label="<?php esc_attr_e( 'Change variation image', 'tejcart' ); ?>"
                                                    title="<?php esc_attr_e( 'Click to change image', 'tejcart' ); ?>">
                                                <?php if ( $v_thumb ) : ?>
                                                    <img src="<?php echo esc_url( $v_thumb ); ?>" alt="" />
                                                <?php else : ?>
                                                    <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                                                <?php endif; ?>
                                            </button>
                                            <input type="hidden" name="variation_image_id[]" value="<?php echo esc_attr( $v_image_id ); ?>" />
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html( $v_label ?: ( '#' . $vid ) ); ?></strong>
                                            <input type="hidden" name="variation_id[]" value="<?php echo esc_attr( $vid ); ?>" />
                                        </td>
                                        <td>
                                            <input type="text" name="variation_sku[]"
                                                   value="<?php echo esc_attr( $v_sku ); ?>"
                                                   class="tejcart-input" />
                                        </td>
                                        <td>
                                            <input type="number" name="variation_price[]"
                                                   value="<?php echo esc_attr( $v_price ); ?>"
                                                   step="0.01" min="0" placeholder="0.00"
                                                   class="tejcart-input" />
                                        </td>
                                        <td>
                                            <input type="number" name="variation_sale_price[]"
                                                   value="<?php echo esc_attr( $v_sale ); ?>"
                                                   step="0.01" min="0" placeholder="—"
                                                   class="tejcart-input" />
                                        </td>
                                        <td>
                                            <input type="number" name="variation_stock_quantity[]"
                                                   value="<?php echo esc_attr( null !== $v_stock ? (string) $v_stock : '' ); ?>"
                                                   step="1" min="0" placeholder="—"
                                                   class="tejcart-input" />
                                        </td>
                                        <td>
                                            <select name="variation_status[]" class="tejcart-input">
                                                <option value="publish" <?php selected( $v_status, 'publish' ); ?>><?php esc_html_e( 'Published', 'tejcart' ); ?></option>
                                                <option value="draft" <?php selected( $v_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'tejcart' ); ?></option>
                                            </select>
                                        </td>
                                        <td>
                                            <a class="button-link delete tejcart-variation-delete"
                                               href="<?php echo esc_url( $del_url ); ?>"
                                               data-tejcart-confirm
                                               data-confirm-title="<?php esc_attr_e( 'Delete variation?', 'tejcart' ); ?>"
                                               data-confirm-message="<?php echo esc_attr( sprintf( /* translators: %s: variation label like "#42" or attribute combo */ __( 'This permanently deletes the variation "%s". The parent product stays.', 'tejcart' ), $v_label ?: ( '#' . $vid ) ) ); ?>"
                                               data-confirm-button="<?php esc_attr_e( 'Delete variation', 'tejcart' ); ?>"
                                               data-confirm-tone="danger">
                                                <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr class="tejcart-variation-limits-row">
                                        <td colspan="9">
                                            <details<?php echo $has_limits ? ' open' : ''; ?>>
                                                <summary><?php esc_html_e( 'Purchase limits', 'tejcart' ); ?></summary>
                                                <div class="tejcart-variation-limits">
                                                    <label>
                                                        <span><?php esc_html_e( 'Min per order', 'tejcart' ); ?></span>
                                                        <input type="number" name="variation_min_qty[]"
                                                               value="<?php echo esc_attr( $v_min > 0 ? $v_min : 1 ); ?>"
                                                               step="1" min="1" class="tejcart-input tejcart-input-narrow" />
                                                    </label>
                                                    <label>
                                                        <span><?php esc_html_e( 'Max per order', 'tejcart' ); ?></span>
                                                        <input type="number" name="variation_max_qty[]"
                                                               value="<?php echo esc_attr( $v_max > 0 ? $v_max : '' ); ?>"
                                                               step="1" min="0" placeholder="<?php esc_attr_e( 'No limit', 'tejcart' ); ?>"
                                                               class="tejcart-input tejcart-input-narrow" />
                                                    </label>
                                                    <label class="tejcart-variation-sold-one">
                                                        <input type="checkbox" name="variation_sold_individually[]"
                                                               value="<?php echo esc_attr( $vid ); ?>"
                                                               <?php checked( $v_sold_one ); ?> />
                                                        <span><?php esc_html_e( 'Only one of this variation per cart', 'tejcart' ); ?></span>
                                                    </label>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Children tab — for grouped products. Lists current children (with
     * Edit links) and accepts a comma-separated list of product IDs to
     * (re)assign as children.
     */
    private function render_tab_children( $product, bool $is_edit ): void {
        $children = array();
        if ( $is_edit && method_exists( $product, 'get_grouped_product_ids' ) ) {
            $children = array_map( 'intval', (array) $product->get_grouped_product_ids() );
        }

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="children" id="tejcart-panel-children">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-networking" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Grouped children', 'tejcart' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'A grouped product is a display-only container — customers buy each child product individually from the group page.', 'tejcart' ); ?>
                        </p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <?php
                    $this->render_product_picker(
                        'grouped_product_ids',
                        __( 'Child products', 'tejcart' ),
                        __( 'Search and pick the products to include. Order is preserved on the storefront.', 'tejcart' ),
                        $children,
                        $is_edit ? (int) $product->get_id() : 0
                    );
                    ?>

                    <?php if ( ! empty( $children ) ) :
                        $rows = \TejCart\Product\Product_Factory::get_products( $children );
                        if ( ! empty( $rows ) ) :
                        ?>
                        <div class="tejcart-grouped-preview">
                            <h3 class="tejcart-grouped-preview-title"><?php esc_html_e( 'Current children', 'tejcart' ); ?></h3>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Child product', 'tejcart' ); ?></th>
                                        <th><?php esc_html_e( 'SKU', 'tejcart' ); ?></th>
                                        <th><?php esc_html_e( 'Price', 'tejcart' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $children as $child_id ) :
                                        $child = $rows[ $child_id ] ?? null;
                                        if ( ! $child ) {
                                            continue;
                                        }
                                        $edit_url = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $child_id );
                                        ?>
                                        <tr>
                                            <td><strong><?php echo esc_html( $child->get_name() ); ?></strong> <span class="description">#<?php echo esc_html( $child_id ); ?></span></td>
                                            <td><?php echo esc_html( $child->get_sku() ?: '—' ); ?></td>
                                            <td><?php echo esc_html( $child->get_price() ?: '—' ); ?></td>
                                            <td><?php echo esc_html( $child->get_status() ); ?></td>
                                            <td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'tejcart' ); ?></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        endif;
                    endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Links tab — upsells, cross-sells.
     */
    private function render_tab_links( $product, bool $is_edit ): void {
        $upsells   = $is_edit ? array_map( 'intval', (array) $product->get_upsell_ids() ) : array();
        $crosssels = $is_edit ? array_map( 'intval', (array) $product->get_crosssell_ids() ) : array();
        $related   = $is_edit && method_exists( $product, 'get_related_ids' ) ? array_map( 'intval', (array) $product->get_related_ids() ) : array();
        $self_id   = $is_edit ? (int) $product->get_id() : 0;

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="links" id="tejcart-panel-links">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'Linked products', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Suggest related products on the product page (upsells) and at checkout (cross-sells).', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <?php $this->render_product_picker( 'product_upsell_ids', __( 'Upsells', 'tejcart' ), __( 'Surfaced on this product\'s page as suggestions.', 'tejcart' ), $upsells, $self_id ); ?>
                    <?php $this->render_product_picker( 'product_crosssell_ids', __( 'Cross-sells', 'tejcart' ), __( 'Promoted at the cart as add-ons.', 'tejcart' ), $crosssels, $self_id ); ?>
                    <?php $this->render_product_picker( 'product_related_ids', __( 'Related products', 'tejcart' ), __( 'Override the auto-discovered list. Leave blank to fall back to category-based suggestions.', 'tejcart' ), $related, $self_id ); ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * External tab — affiliate / external URL + button text.
     */
    private function render_tab_external( $product, bool $is_edit ): void {
        $product_url = ( $is_edit && $product instanceof External_Product ) ? $product->get_product_url() : '';
        $button_text = ( $is_edit && $product instanceof External_Product ) ? $product->get_button_text() : '';

        ?>
        <section class="tejcart-tab-panel tejcart-section" data-panel="external" id="tejcart-panel-external">
            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'External product', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Redirect customers to an external URL to purchase this product.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-field">
                        <label for="product_url"><?php esc_html_e( 'Destination URL', 'tejcart' ); ?></label>
                        <input type="url" id="product_url" name="product_url"
                               class="tejcart-input" placeholder="https://..."
                               value="<?php echo esc_url( $product_url ); ?>" />
                    </div>
                    <div class="tejcart-field">
                        <label for="product_button_text"><?php esc_html_e( 'Button text', 'tejcart' ); ?></label>
                        <input type="text" id="product_button_text" name="product_button_text"
                               class="tejcart-input"
                               placeholder="<?php esc_attr_e( 'Buy product', 'tejcart' ); ?>"
                               value="<?php echo esc_attr( $button_text ); ?>" />
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Publish card — status, visibility, featured, delete.
     */
    private function render_publish_card( $product, bool $is_edit ): void {
        $status      = $is_edit ? $product->get_status() : 'draft';
        $visibility  = $is_edit ? $product->get_catalog_visibility() : 'visible';
        $is_featured = $is_edit && method_exists( $product, 'is_featured' ) ? $product->is_featured() : false;
        $delete_url  = $is_edit ? wp_nonce_url(
            admin_url( 'admin.php?page=tejcart-products&action=delete&product_id=' . (int) $product->get_id() ),
            'tejcart_delete_product_' . (int) $product->get_id()
        ) : '';

        ?>
        <div class="tejcart-card tejcart-sidebar-card">
            <div class="tejcart-card-header">
                <div class="tejcart-card-header-icon">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                </div>
                <div class="tejcart-card-header-text">
                    <h2><?php esc_html_e( 'Publish', 'tejcart' ); ?></h2>
                </div>
            </div>
            <div class="tejcart-card-body">
                <div class="tejcart-field">
                    <label for="product_status"><?php esc_html_e( 'Status', 'tejcart' ); ?></label>
                    <select id="product_status" name="product_status" class="tejcart-input">
                        <option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'Published', 'tejcart' ); ?></option>
                        <option value="draft"   <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'tejcart' ); ?></option>
                        <option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'tejcart' ); ?></option>
                        <option value="private" <?php selected( $status, 'private' ); ?>><?php esc_html_e( 'Private', 'tejcart' ); ?></option>
                    </select>
                </div>
                <div class="tejcart-field">
                    <label for="product_catalog_visibility"><?php esc_html_e( 'Visibility', 'tejcart' ); ?></label>
                    <select id="product_catalog_visibility" name="product_catalog_visibility" class="tejcart-input">
                        <option value="visible" <?php selected( $visibility, 'visible' ); ?>><?php esc_html_e( 'Shop and search', 'tejcart' ); ?></option>
                        <option value="catalog" <?php selected( $visibility, 'catalog' ); ?>><?php esc_html_e( 'Shop only', 'tejcart' ); ?></option>
                        <option value="search"  <?php selected( $visibility, 'search' ); ?>><?php esc_html_e( 'Search only', 'tejcart' ); ?></option>
                        <option value="hidden"  <?php selected( $visibility, 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'tejcart' ); ?></option>
                    </select>
                </div>
                <div class="tejcart-field">
                    <label class="tejcart-toggle">
                        <input type="checkbox" id="product_featured" name="product_featured" value="1" <?php checked( $is_featured ); ?> />
                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Feature this product', 'tejcart' ); ?></span>
                    </label>
                </div>
            </div>
            <?php if ( $is_edit ) : ?>
                <div class="tejcart-card-footer tejcart-card-footer-split">
                    <a href="<?php echo esc_url( $delete_url ); ?>"
                       class="tejcart-link-danger"
                       data-tejcart-confirm
                       data-confirm-title="<?php esc_attr_e( 'Delete product?', 'tejcart' ); ?>"
                       data-confirm-message="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'This permanently deletes "%s" and any linked data (orders retain their snapshot). This action cannot be undone.', 'tejcart' ), $product ? $product->get_name() : '' ) ); ?>"
                       data-confirm-button="<?php esc_attr_e( 'Delete product', 'tejcart' ); ?>"
                       data-confirm-tone="danger">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                    </a>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'tejcart' ); ?></button>
                </div>
            <?php else : ?>
                <div class="tejcart-card-footer">
                    <button type="submit" class="button button-primary tejcart-btn-block"><?php esc_html_e( 'Create product', 'tejcart' ); ?></button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Hero type picker shown above the main form for NEW products. Surfaces
     * the type decision as the first step with a visual grid — easier for
     * non-technical users than a sidebar dropdown. Once the product is
     * saved, the type is locked and this hero is replaced by a sidebar
     * badge (see render_type_card()).
     *
     * @param string $type Currently-selected type slug.
     */
    private function render_type_picker_hero( string $type ): void {
        $types = array();
        foreach ( Product_Type_Registry::get_types() as $slug => $definition ) {
            if ( empty( $definition['admin'] ) ) {
                continue;
            }
            $types[ $slug ] = array(
                'label' => (string) ( $definition['label'] ?? ucfirst( $slug ) ),
                'desc'  => (string) ( $definition['description'] ?? '' ),
                'icon'  => (string) ( $definition['icon'] ?? 'admin-generic' ),
            );
        }
        ?>
        <section class="tejcart-section tejcart-type-hero-section">
            <div class="tejcart-card tejcart-type-hero">
                <div class="tejcart-card-header">
                    <div class="tejcart-card-header-icon">
                        <span class="dashicons dashicons-products" aria-hidden="true"></span>
                    </div>
                    <div class="tejcart-card-header-text">
                        <h2><?php esc_html_e( 'What kind of product are you selling?', 'tejcart' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Pick a type to shape the rest of this form. You can\'t change it later, but you can always duplicate the product with a different type.', 'tejcart' ); ?></p>
                    </div>
                </div>
                <div class="tejcart-card-body">
                    <div class="tejcart-type-hero-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Product type', 'tejcart' ); ?>">
                        <?php foreach ( $types as $slug => $meta ) : ?>
                            <label class="tejcart-type-option <?php echo $type === $slug ? 'is-selected' : ''; ?>">
                                <input type="radio"
                                       name="product_type"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( $type, $slug ); ?>
                                       data-tejcart-type-switch />
                                <span class="tejcart-type-option-icon" aria-hidden="true">
                                    <span class="dashicons dashicons-<?php echo esc_attr( $meta['icon'] ); ?>"></span>
                                </span>
                                <span class="tejcart-type-option-text">
                                    <strong><?php echo esc_html( $meta['label'] ); ?></strong>
                                    <span class="tejcart-type-option-desc"><?php echo esc_html( $meta['desc'] ); ?></span>
                                </span>
                                <span class="tejcart-type-option-check dashicons dashicons-yes" aria-hidden="true"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Product type card in the sidebar. Only rendered for EXISTING products
     * (new products see render_type_picker_hero() above the main form).
     * Shows the locked-in type with a link to duplicate the product if the
     * merchant needs a different type.
     */
    private function render_type_card( $product, bool $is_edit ): void {
        if ( ! $is_edit ) {
            return;
        }

        $type  = $product->get_type();
        $types = array();
        foreach ( Product_Type_Registry::get_types() as $slug => $definition ) {
            $types[ $slug ] = array(
                'label' => (string) ( $definition['label'] ?? ucfirst( $slug ) ),
                'desc'  => (string) ( $definition['description'] ?? '' ),
                'icon'  => (string) ( $definition['icon'] ?? 'admin-generic' ),
            );
        }

        $duplicate_url = wp_nonce_url(
            admin_url( 'admin.php?page=tejcart-products&action=duplicate&product_id=' . (int) $product->get_id() ),
            'tejcart_duplicate_product_' . (int) $product->get_id()
        );

        ?>
        <div class="tejcart-card tejcart-sidebar-card">
            <div class="tejcart-card-header">
                <div class="tejcart-card-header-icon">
                    <span class="dashicons dashicons-products" aria-hidden="true"></span>
                </div>
                <div class="tejcart-card-header-text">
                    <h2><?php esc_html_e( 'Product type', 'tejcart' ); ?></h2>
                </div>
            </div>
            <div class="tejcart-card-body">
                <div class="tejcart-type-locked">
                    <div class="tejcart-type-locked-main">
                        <span class="tejcart-type-icon dashicons dashicons-<?php echo esc_attr( $types[ $type ]['icon'] ?? 'admin-generic' ); ?>" aria-hidden="true"></span>
                        <div class="tejcart-type-locked-text">
                            <strong><?php echo esc_html( $types[ $type ]['label'] ?? ucfirst( $type ) ); ?></strong>
                            <?php if ( isset( $types[ $type ]['desc'] ) ) : ?>
                                <p class="description"><?php echo esc_html( $types[ $type ]['desc'] ); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="dashicons dashicons-lock tejcart-type-lock-icon" aria-hidden="true" title="<?php esc_attr_e( 'Type is locked once a product is created.', 'tejcart' ); ?>"></span>
                    </div>
                    <p class="tejcart-type-locked-help">
                        <?php esc_html_e( 'Need a different type? Duplicate this product and pick a new type for the copy.', 'tejcart' ); ?>
                    </p>
                    <a href="<?php echo esc_url( $duplicate_url ); ?>" class="tejcart-type-duplicate-link">
                        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                        <?php esc_html_e( 'Duplicate this product', 'tejcart' ); ?>
                    </a>
                </div>
                <input type="hidden" name="product_type" value="<?php echo esc_attr( $type ); ?>" />
            </div>
        </div>
        <?php
    }

    /**
     * Organization card — categories, tags, brand checkboxes.
     */
    private function render_organization_card( $product, bool $is_edit ): void {
        $selected_cats = $is_edit ? wp_list_pluck( Product_Taxonomy::get_product_categories( (int) $product->get_id() ), 'term_id' ) : array();
        $selected_tags = $is_edit ? wp_list_pluck( Product_Taxonomy::get_product_tags( (int) $product->get_id() ), 'term_id' )       : array();
        $selected_brds = $is_edit ? wp_list_pluck( Product_Taxonomy::get_product_brands( (int) $product->get_id() ), 'term_id' )     : array();

        ?>
        <div class="tejcart-card tejcart-sidebar-card">
            <div class="tejcart-card-header">
                <div class="tejcart-card-header-icon">
                    <span class="dashicons dashicons-category" aria-hidden="true"></span>
                </div>
                <div class="tejcart-card-header-text">
                    <h2><?php esc_html_e( 'Organization', 'tejcart' ); ?></h2>
                </div>
            </div>
            <div class="tejcart-card-body">
                <?php
                $this->render_taxonomy_picker( __( 'Categories', 'tejcart' ), Product_Taxonomy::CATEGORY_TAXONOMY, 'product_categories', $selected_cats, true );
                $this->render_taxonomy_picker( __( 'Tags', 'tejcart' ),       Product_Taxonomy::TAG_TAXONOMY,      'product_tags',       $selected_tags, false );
                $this->render_taxonomy_picker( __( 'Brand', 'tejcart' ),      Product_Taxonomy::BRAND_TAXONOMY,    'product_brands',     $selected_brds, true );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a product picker — search-as-you-type input that resolves to
     * a hidden comma-separated list of product IDs. The JS in
     * tejcart-admin.js binds to [data-tejcart-product-picker] and calls the
     * admin-AJAX `tejcart_admin_search_products` action. Decoupled from the
     * public `/tejcart/v1/products` REST endpoint so the picker keeps
     * working when REST is disabled or rate-limited.
     *
     * @param string $field_name HTML name of the hidden ID-list input.
     * @param string $label
     * @param string $description
     * @param int[]  $selected
     */
    private function render_product_picker( string $field_name, string $label, string $description, array $selected, int $self_id = 0 ): void {
        $selected = array_values( array_filter( array_map( 'intval', $selected ) ) );

        $preloaded = array();
        if ( ! empty( $selected ) ) {
            $rows = Product_Factory::get_products( $selected );
            foreach ( $selected as $sel_id ) {
                $row = $rows[ $sel_id ] ?? null;
                if ( $row ) {
                    $preloaded[] = array(
                        'id'   => (int) $sel_id,
                        'name' => $row->get_name(),
                        'sku'  => $row->get_sku(),
                    );
                }
            }
        }

        ?>
        <div class="tejcart-field tejcart-product-picker"
             data-tejcart-product-picker
             data-self-id="<?php echo esc_attr( (string) $self_id ); ?>"
             data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-ajax-action="tejcart_admin_search_products"
             data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( self::PICKER_NONCE_ACTION ) ); ?>">
            <label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="tejcart-product-picker-chips" data-picker-chips>
                <?php foreach ( $preloaded as $entry ) : ?>
                    <span class="tejcart-product-chip" data-id="<?php echo esc_attr( $entry['id'] ); ?>">
                        <span class="tejcart-product-chip-label">
                            <?php echo esc_html( $entry['name'] ); ?>
                            <?php if ( $entry['sku'] ) : ?>
                                <span class="tejcart-muted"> · <?php echo esc_html( $entry['sku'] ); ?></span>
                            <?php endif; ?>
                        </span>
                        <button type="button" class="tejcart-product-chip-x" data-picker-remove
                                aria-label="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>">&times;</button>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="tejcart-product-picker-input-wrap">
                <input type="search" class="tejcart-input tejcart-product-picker-search"
                       data-picker-search
                       placeholder="<?php esc_attr_e( 'Search products by name or SKU…', 'tejcart' ); ?>"
                       autocomplete="off" />
                <ul class="tejcart-product-picker-results" data-picker-results hidden></ul>
            </div>
            <input type="hidden" id="<?php echo esc_attr( $field_name ); ?>" name="<?php echo esc_attr( $field_name ); ?>"
                   value="<?php echo esc_attr( implode( ',', $selected ) ); ?>"
                   data-picker-hidden />
            <p class="description"><?php echo esc_html( $description ); ?></p>
        </div>
        <?php
    }

    /**
     * Render one taxonomy-picker group (checkbox list + quick-add link).
     *
     * @param string $label
     * @param string $taxonomy
     * @param string $field_base  Name prefix for submitted term IDs.
     * @param int[]  $selected
     * @param bool   $hierarchical Render as a nested list when true.
     */
    private function render_taxonomy_picker( string $label, string $taxonomy, string $field_base, array $selected, bool $hierarchical ): void {
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 200,
        ) );

        $selected = array_map( 'intval', $selected );

        $show_search = is_array( $terms ) && count( $terms ) > 8;

        $quick_add_endpoints = array(
            \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY => 'products/categories',
            \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY      => 'products/tags',
        );
        $quick_add_route = ( isset( $quick_add_endpoints[ $taxonomy ] ) && current_user_can( 'manage_options' ) )
            ? $quick_add_endpoints[ $taxonomy ]
            : '';

        ?>
        <div class="tejcart-field">
            <label class="tejcart-field-label"><?php echo esc_html( $label ); ?></label>
            <?php if ( empty( $terms ) || is_wp_error( $terms ) ) : ?>
                <p class="description tejcart-muted"><?php esc_html_e( 'None created yet.', 'tejcart' ); ?></p>
            <?php else : ?>
                <div class="tejcart-term-list-wrap"
                     data-tejcart-term-filter
                     <?php if ( $quick_add_route ) : ?>
                     data-tejcart-term-quick-add
                     data-rest-root="<?php echo esc_url( rest_url( 'tejcart/v1/' . $quick_add_route ) ); ?>"
                     data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
                     data-field-base="<?php echo esc_attr( $field_base ); ?>"
                     <?php endif; ?>>
                    <?php if ( $show_search ) : ?>
                        <div class="tejcart-term-search">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input type="search"
                                   class="tejcart-term-search-input"
                                   placeholder="<?php esc_attr_e( 'Filter…', 'tejcart' ); ?>"
                                   data-term-filter-input
                                   aria-label="<?php echo esc_attr( sprintf( /* translators: %s: taxonomy label (e.g. Categories) */ __( 'Filter %s', 'tejcart' ), $label ) ); ?>" />
                        </div>
                    <?php endif; ?>
                    <div class="tejcart-term-list <?php echo $hierarchical ? 'is-hierarchical' : ''; ?>" data-term-list>
                        <?php foreach ( $terms as $term ) : ?>
                            <label class="tejcart-term-item" data-term-name="<?php echo esc_attr( strtolower( $term->name ) ); ?>">
                                <input type="checkbox" name="<?php echo esc_attr( $field_base ); ?>[]"
                                       value="<?php echo esc_attr( (int) $term->term_id ); ?>"
                                       <?php checked( in_array( (int) $term->term_id, $selected, true ) ); ?> />
                                <span><?php echo esc_html( $term->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="tejcart-term-empty" data-term-empty hidden>
                        <?php esc_html_e( 'No matching items.', 'tejcart' ); ?>
                    </p>
                    <?php if ( $quick_add_route ) : ?>
                        <div class="tejcart-term-quick-add" data-term-quick-add hidden>
                            <input type="text"
                                   class="tejcart-term-quick-add-input"
                                   data-quick-add-input
                                   placeholder="<?php esc_attr_e( 'New name…', 'tejcart' ); ?>" />
                            <button type="button" class="button button-primary button-small" data-quick-add-submit>
                                <?php esc_html_e( 'Add', 'tejcart' ); ?>
                            </button>
                            <button type="button" class="tejcart-icon-btn" data-quick-add-cancel aria-label="<?php esc_attr_e( 'Cancel', 'tejcart' ); ?>">
                                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                            </button>
                        </div>
                        <p class="tejcart-term-quick-add-error" data-quick-add-error hidden></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="tejcart-term-footer">
                <?php if ( $quick_add_route ) : ?>
                    <button type="button" class="tejcart-field-link" data-quick-add-toggle>
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <?php
                        printf(
                            /* translators: %s: singular label like "category" / "tag" */
                            esc_html__( 'Add new %s', 'tejcart' ),
                            esc_html( strtolower( rtrim( $label, 's' ) ) )
                        );
                        ?>
                    </button>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy ) ); ?>"
                   target="_blank" rel="noopener" class="tejcart-field-link">
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    <?php esc_html_e( 'Manage', 'tejcart' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Images card — featured image + gallery.
     */
    private function render_images_card( $product, bool $is_edit ): void {
        $image_id    = $is_edit ? (int) $product->get_image_id() : 0;
        $gallery_ids = $is_edit ? array_map( 'intval', (array) $product->get_gallery_ids() ) : array();

        $image_alt   = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

        ?>
        <div class="tejcart-card tejcart-sidebar-card">
            <div class="tejcart-card-header">
                <div class="tejcart-card-header-icon">
                    <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                </div>
                <div class="tejcart-card-header-text">
                    <h2><?php esc_html_e( 'Images', 'tejcart' ); ?></h2>
                </div>
            </div>
            <div class="tejcart-card-body">
                <div class="tejcart-field">
                    <label class="tejcart-field-label"><?php esc_html_e( 'Featured image', 'tejcart' ); ?></label>
                    <div class="tejcart-product-image-wrap">
                        <input type="hidden" name="product_image_id" id="product_image_id" class="tejcart-image-id" value="<?php echo esc_attr( $image_id ); ?>" />
                        <div class="tejcart-image-preview" id="tejcart-product-image-preview">
                            <?php if ( $image_id ) {
                                echo wp_get_attachment_image( $image_id, 'medium' );
                            } ?>
                        </div>
                        <div class="tejcart-image-actions">
                            <button type="button" class="button tejcart-upload-image-btn">
                                <?php echo $image_id ? esc_html__( 'Replace', 'tejcart' ) : esc_html__( 'Upload', 'tejcart' ); ?>
                            </button>
                            <button type="button" class="button-link tejcart-remove-image-btn"
                                    <?php echo $image_id ? '' : 'style="display:none;"'; ?>>
                                <?php esc_html_e( 'Remove', 'tejcart' ); ?>
                            </button>
                        </div>
                    </div>
                    <div class="tejcart-image-alt-row" <?php echo $image_id ? '' : 'hidden'; ?> data-tejcart-image-alt-row>
                        <label for="product_image_alt">
                            <?php esc_html_e( 'Alt text', 'tejcart' ); ?>
                            <span class="tejcart-muted"><?php esc_html_e( '(for screen readers & SEO)', 'tejcart' ); ?></span>
                        </label>
                        <input type="text"
                               id="product_image_alt"
                               name="product_image_alt"
                               class="tejcart-input"
                               maxlength="250"
                               placeholder="<?php esc_attr_e( 'Describe what\'s in the image', 'tejcart' ); ?>"
                               value="<?php echo esc_attr( $image_alt ); ?>" />
                    </div>
                </div>
                <div class="tejcart-field">
                    <label class="tejcart-field-label"><?php esc_html_e( 'Gallery', 'tejcart' ); ?></label>
                    <div class="tejcart-gallery-wrap">
                        <div class="tejcart-gallery-images" id="tejcart-gallery-images">
                            <?php foreach ( $gallery_ids as $gid ) :
                                $thumb_url = wp_get_attachment_image_url( $gid, 'thumbnail' );
                                if ( ! $thumb_url ) {
                                    continue;
                                }
                                ?>
                                <div class="tejcart-gallery-thumb" data-id="<?php echo esc_attr( $gid ); ?>">
                                    <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
                                    <button type="button" class="tejcart-remove-gallery-image" aria-label="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="product_gallery_ids" id="product_gallery_ids" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>" />
                        <button type="button" class="button" id="tejcart-add-gallery-btn">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e( 'Add images', 'tejcart' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Classify a product's current stock posture for the header pill and
     * inventory banner. Returns null when nothing needs to be shown (stock
     * isn't tracked, the product's a draft, or there's plenty on hand).
     *
     * @param Abstract_Product $product
     * @return array{tone:string, label:string, message:string}|null
     */
    private function low_stock_state( $product ): ?array {
        if ( ! $product || ! method_exists( $product, 'get_stock_quantity' ) ) {
            return null;
        }

        if ( 'publish' !== $product->get_status() ) {
            return null;
        }
        $manage = method_exists( $product, 'get_manage_stock' ) ? (bool) $product->get_manage_stock() : false;
        if ( ! $manage ) {
            return null;
        }
        $qty = $product->get_stock_quantity();
        if ( null === $qty ) {
            return null;
        }
        $qty = (int) $qty;

        if ( $qty <= 0 ) {
            return array(
                'tone'    => 'out',
                'label'   => __( 'Out of stock', 'tejcart' ),
                'message' => __( 'This product is marked as published but has zero stock. Restock it or switch to draft so customers don\'t see it in the catalog.', 'tejcart' ),
            );
        }

        /**
         * Filter the threshold at or below which a product counts as "low
         * stock". Merchants can tune this per product or by category via
         * the filter signature.
         *
         * @param int              $threshold Default 5.
         * @param Abstract_Product $product   The product being evaluated.
         */
        $threshold = (int) apply_filters( 'tejcart_low_stock_threshold', 5, $product );
        if ( $qty <= $threshold ) {
            return array(
                'tone'    => 'low',
                /* translators: %d: current stock quantity */
                'label'   => sprintf( __( 'Low stock: %d left', 'tejcart' ), $qty ),
                /* translators: 1: current stock qty, 2: threshold value */
                'message' => sprintf( __( 'Only %1$d left — at or below the low-stock threshold of %2$d. Consider restocking soon.', 'tejcart' ), $qty, $threshold ),
            );
        }

        return null;
    }

    /**
     * Map an internal status slug to a human label and CSS modifier.
     *
     * @param string $status
     * @return array{label:string, class:string}
     */
    private function status_meta( string $status ): array {
        $map = array(
            'publish' => array( 'label' => __( 'Published', 'tejcart' ), 'class' => 'publish' ),
            'draft'   => array( 'label' => __( 'Draft', 'tejcart' ),     'class' => 'draft' ),
            'pending' => array( 'label' => __( 'Pending', 'tejcart' ),   'class' => 'pending' ),
            'private' => array( 'label' => __( 'Private', 'tejcart' ),   'class' => 'private' ),
        );

        return $map[ $status ] ?? array( 'label' => ucfirst( $status ), 'class' => 'draft' );
    }

    /**
     * Resolve an error code from the save handler back into a localized message.
     *
     * @param string $code
     * @return string
     */
    private function error_message( string $code ): string {
        switch ( $code ) {
            case 'duplicate_sku':
                return __( 'Another product already uses that SKU. SKUs must be unique.', 'tejcart' );
            case 'name_required':
                return __( 'Product name is required.', 'tejcart' );
            case 'save_failed':
                return __( 'Could not save the product. Please try again.', 'tejcart' );
            case 'no_variation_attrs':
                return __( 'Mark at least one attribute "Used for variations" with a list of values, then try again.', 'tejcart' );
            case 'too_many_variations':
                return __( 'That attribute combination would create more than 200 variations. Reduce the value list and try again.', 'tejcart' );
            case 'variation_parent_missing':
                return __( 'Parent product not found or not a variable product.', 'tejcart' );
            default:
                return __( 'The product could not be saved. Please review the form and try again.', 'tejcart' );
        }
    }
}
