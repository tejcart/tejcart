<?php
/**
 * Product CSV Import/Export handler.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles CSV import and export of products.
 */
class Product_Import_Export {
    /**
     * CSV column headers in export order.
     *
     * @var string[]
     */
    private const CSV_COLUMNS = array(
        'id',
        'name',
        'slug',
        'type',
        'status',
        'description',
        'short_description',
        'sku',
        'price',
        'sale_price',
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'weight',
        'length',
        'width',
        'height',
        'tax_class',
        'shipping_class',
        'featured',
        'sold_individually',
        'min_purchase_quantity',
        'max_purchase_quantity',
        'backorders',
        'catalog_visibility',
        'categories',
        'tags',
        'brands',
        'image_url',
        'gallery_image_urls',

        'variation_parent_sku',
        'variation_attributes',
        'bundled_items_json',
        'grouped_children_skus',
        'upsell_skus',
        'cross_sell_skus',
        'related_skus',
        'downloadable_files_json',

        'external_url',
        'external_button_text',
    );

    /**
     * Return the canonical field catalog used by the import-mapping UI.
     *
     * Each entry carries a translated label (shown in the mapping dropdown),
     * a `required` flag for the two columns we cannot proceed without
     * (`name`, `price`), and a short description used both in the column
     * reference list and as a tooltip in the mapping table. Keep the order
     * aligned with {@see CSV_COLUMNS} so the export and mapping UIs read
     * the same.
     *
     * @return array<string, array{label:string, required:bool, description:string}>
     */
    public static function field_definitions(): array {
        return array(
            'id'                      => array( 'label' => __( 'Product ID', 'tejcart' ),               'required' => false, 'description' => __( 'Product ID (ignored on import, used for reference only).', 'tejcart' ) ),
            'name'                    => array( 'label' => __( 'Name', 'tejcart' ),                     'required' => true,  'description' => __( 'Product name.', 'tejcart' ) ),
            'slug'                    => array( 'label' => __( 'Slug', 'tejcart' ),                     'required' => false, 'description' => __( 'URL slug (auto-generated if empty).', 'tejcart' ) ),
            'type'                    => array( 'label' => __( 'Type', 'tejcart' ),                     'required' => false, 'description' => __( 'simple, variable, variation, grouped, bundle, external, digital, virtual.', 'tejcart' ) ),
            'status'                  => array( 'label' => __( 'Status', 'tejcart' ),                   'required' => false, 'description' => __( 'publish, draft, or pending.', 'tejcart' ) ),
            'description'             => array( 'label' => __( 'Description', 'tejcart' ),             'required' => false, 'description' => __( 'Full product description.', 'tejcart' ) ),
            'short_description'       => array( 'label' => __( 'Short description', 'tejcart' ),       'required' => false, 'description' => __( 'Short description / excerpt.', 'tejcart' ) ),
            'sku'                     => array( 'label' => __( 'SKU', 'tejcart' ),                      'required' => false, 'description' => __( 'Stock keeping unit (used to match existing products).', 'tejcart' ) ),
            'price'                   => array( 'label' => __( 'Regular price', 'tejcart' ),           'required' => true,  'description' => __( 'Regular price.', 'tejcart' ) ),
            'sale_price'              => array( 'label' => __( 'Sale price', 'tejcart' ),              'required' => false, 'description' => __( 'Sale price.', 'tejcart' ) ),
            'stock_quantity'          => array( 'label' => __( 'Stock quantity', 'tejcart' ),          'required' => false, 'description' => __( 'Stock quantity (integer).', 'tejcart' ) ),
            'stock_status'            => array( 'label' => __( 'Stock status', 'tejcart' ),            'required' => false, 'description' => __( 'instock, outofstock, or onbackorder.', 'tejcart' ) ),
            'manage_stock'            => array( 'label' => __( 'Manage stock', 'tejcart' ),            'required' => false, 'description' => __( '1 or 0.', 'tejcart' ) ),
            'weight'                  => array( 'label' => __( 'Weight', 'tejcart' ),                  'required' => false, 'description' => __( 'Product weight.', 'tejcart' ) ),
            'length'                  => array( 'label' => __( 'Length', 'tejcart' ),                  'required' => false, 'description' => __( 'Product length.', 'tejcart' ) ),
            'width'                   => array( 'label' => __( 'Width', 'tejcart' ),                   'required' => false, 'description' => __( 'Product width.', 'tejcart' ) ),
            'height'                  => array( 'label' => __( 'Height', 'tejcart' ),                  'required' => false, 'description' => __( 'Product height.', 'tejcart' ) ),
            'tax_class'               => array( 'label' => __( 'Tax class', 'tejcart' ),               'required' => false, 'description' => __( 'Tax class slug (empty = standard).', 'tejcart' ) ),
            'shipping_class'          => array( 'label' => __( 'Shipping class', 'tejcart' ),          'required' => false, 'description' => __( 'Shipping class slug.', 'tejcart' ) ),
            'featured'                => array( 'label' => __( 'Featured', 'tejcart' ),                'required' => false, 'description' => __( '1 / yes / true to mark as featured.', 'tejcart' ) ),
            'sold_individually'       => array( 'label' => __( 'Sold individually', 'tejcart' ),       'required' => false, 'description' => __( 'Limit cart to one of this product.', 'tejcart' ) ),
            'min_purchase_quantity'   => array( 'label' => __( 'Min purchase quantity', 'tejcart' ),   'required' => false, 'description' => __( 'Minimum order quantity (default 1).', 'tejcart' ) ),
            'max_purchase_quantity'   => array( 'label' => __( 'Max purchase quantity', 'tejcart' ),   'required' => false, 'description' => __( 'Maximum order quantity (0 = no limit).', 'tejcart' ) ),
            'backorders'              => array( 'label' => __( 'Backorders', 'tejcart' ),              'required' => false, 'description' => __( 'no | notify | yes (default no).', 'tejcart' ) ),
            'catalog_visibility'      => array( 'label' => __( 'Catalog visibility', 'tejcart' ),      'required' => false, 'description' => __( 'visible | catalog | search | hidden (default visible).', 'tejcart' ) ),
            'categories'              => array( 'label' => __( 'Categories', 'tejcart' ),              'required' => false, 'description' => __( 'Comma-separated category names.', 'tejcart' ) ),
            'tags'                    => array( 'label' => __( 'Tags', 'tejcart' ),                    'required' => false, 'description' => __( 'Comma-separated tag names.', 'tejcart' ) ),
            'brands'                  => array( 'label' => __( 'Brands', 'tejcart' ),                  'required' => false, 'description' => __( 'Comma-separated brand names.', 'tejcart' ) ),
            'image_url'               => array( 'label' => __( 'Featured image URL', 'tejcart' ),      'required' => false, 'description' => __( 'Featured product image URL.', 'tejcart' ) ),
            'gallery_image_urls'      => array( 'label' => __( 'Gallery image URLs', 'tejcart' ),      'required' => false, 'description' => __( 'Pipe-separated gallery image URLs.', 'tejcart' ) ),
            'variation_parent_sku'    => array( 'label' => __( 'Variation parent SKU', 'tejcart' ),    'required' => false, 'description' => __( 'Parent SKU (variation rows only).', 'tejcart' ) ),
            'variation_attributes'    => array( 'label' => __( 'Variation attributes (JSON)', 'tejcart' ), 'required' => false, 'description' => __( 'Variation attribute JSON, e.g. {"size":"M"}.', 'tejcart' ) ),
            'bundled_items_json'      => array( 'label' => __( 'Bundle items (JSON)', 'tejcart' ),     'required' => false, 'description' => __( 'Bundle contents JSON (bundle products only).', 'tejcart' ) ),
            'grouped_children_skus'   => array( 'label' => __( 'Grouped children SKUs', 'tejcart' ),   'required' => false, 'description' => __( 'Pipe-separated child SKUs (grouped products only).', 'tejcart' ) ),
            'upsell_skus'             => array( 'label' => __( 'Upsell SKUs', 'tejcart' ),             'required' => false, 'description' => __( 'Pipe-separated upsell SKUs.', 'tejcart' ) ),
            'cross_sell_skus'         => array( 'label' => __( 'Cross-sell SKUs', 'tejcart' ),         'required' => false, 'description' => __( 'Pipe-separated cross-sell SKUs.', 'tejcart' ) ),
            'related_skus'            => array( 'label' => __( 'Related SKUs', 'tejcart' ),            'required' => false, 'description' => __( 'Pipe-separated related-product SKUs.', 'tejcart' ) ),
            'downloadable_files_json' => array( 'label' => __( 'Downloadable files (JSON)', 'tejcart' ), 'required' => false, 'description' => __( 'Downloadable files JSON (digital products).', 'tejcart' ) ),
            'external_url'            => array( 'label' => __( 'External URL', 'tejcart' ),            'required' => false, 'description' => __( 'External/affiliate destination URL.', 'tejcart' ) ),
            'external_button_text'    => array( 'label' => __( 'External button text', 'tejcart' ),    'required' => false, 'description' => __( 'Custom "buy" button label.', 'tejcart' ) ),
        );
    }

    /**
     * Suggest a default canonical-field mapping for a raw header row.
     *
     * Used by the preview endpoint to seed the mapping UI so the operator
     * usually only has to confirm — and tweak the odd column out — rather
     * than touch every dropdown. We try three strategies in order:
     *
     *   1. Exact match on a canonical field name.
     *   2. {@see External_CSV_Compat::canonicalize_headers()} — picks up
     *      the classic "Regular price", "In stock?", etc. column headers. The compat shim
     *      expects lowercased headers per its contract, so we lowercase
     *      here before handing it the row.
     *   3. A loose normalisation pass: lowercase, strip non-alphanumerics,
     *      replace spaces/dashes with underscores. Useful for "Product Name"
     *      → `name`, "stock-qty" → `stock_quantity`, etc.
     *
     * Repeating-group ("attribute N name") and private (`__ext_*`) keys are
     * deliberately left unmapped — they're not in the user-facing field
     * catalog and would only confuse the dropdown.
     *
     * @param string[] $raw_headers Raw header row as read from the CSV
     *                              (case preserved). Lowercased and trimmed
     *                              internally before lookups.
     * @return string[] One entry per input column, '' for "do not import".
     */
    public static function suggest_mapping( array $raw_headers ): array {
        $fields  = self::field_definitions();
        $aliases = array(
            // Friendly variants we want to map without forcing a full
            // alt-format detection. Keys are lowercased; values are
            // canonical field names.
            'product name'    => 'name',
            'title'           => 'name',
            'product title'   => 'name',
            'product id'      => 'id',
            'product sku'     => 'sku',
            'sku code'        => 'sku',
            'price'           => 'price',
            'regular_price'   => 'price',
            'sale_price'      => 'sale_price',
            'qty'             => 'stock_quantity',
            'quantity'        => 'stock_quantity',
            'stock'           => 'stock_quantity',
            'image'           => 'image_url',
            'main image'      => 'image_url',
            'featured image'  => 'image_url',
            'gallery'         => 'gallery_image_urls',
            'category'        => 'categories',
            'tag'             => 'tags',
            'brand'           => 'brands',
        );

        // External_CSV_Compat::HEADER_MAP keys are lowercase. Pass the
        // lowercased + trimmed headers in so classic "Regular price",
        // "Is featured?", "Sold individually?", etc. are recognised even
        // when the source CSV preserves header casing.
        $lower_headers = array_map(
            static fn( $h ) => strtolower( trim( (string) $h ) ),
            $raw_headers
        );
        $external = External_CSV_Compat::canonicalize_headers( $lower_headers );

        $suggestions = array();
        foreach ( $raw_headers as $idx => $header ) {
            $h = $lower_headers[ $idx ] ?? '';

            if ( '' === $h ) {
                $suggestions[] = '';
                continue;
            }

            if ( isset( $fields[ $h ] ) ) {
                $suggestions[] = $h;
                continue;
            }

            if ( isset( $aliases[ $h ] ) ) {
                $suggestions[] = $aliases[ $h ];
                continue;
            }

            $ext = $external[ $idx ] ?? '';
            if ( $ext && isset( $fields[ $ext ] ) ) {
                $suggestions[] = $ext;
                continue;
            }

            $loose = preg_replace( '/[^a-z0-9]+/', '_', $h );
            $loose = trim( (string) $loose, '_' );
            if ( $loose && isset( $fields[ $loose ] ) ) {
                $suggestions[] = $loose;
                continue;
            }

            $suggestions[] = '';
        }

        return $suggestions;
    }

    /**
     * Hook into WordPress for the full admin pageload (settings page,
     * non-AJAX export download, etc.). Called only when not handling
     * an AJAX request — for AJAX, see {@see register_ajax_handlers()}
     * which is wired unconditionally so the admin-ajax dispatcher can
     * find our action callbacks.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'handle_export' ) );
        add_action( 'admin_init', array( $this, 'handle_import' ) );
        $this->register_ajax_handlers();
    }

    /**
     * Register the chunked-import AJAX action callbacks.
     *
     * MUST be called on every admin-ajax.php request, not just on real
     * admin pageloads. WordPress's admin-ajax dispatcher matches on the
     * `action` POST parameter against `wp_ajax_{action}` callbacks; if no
     * callback is registered it falls through to `die('0')`. The TejCart
     * bootstrap gates the heavy admin boot (menus, settings UIs, etc.)
     * behind `is_admin() && ! wp_doing_ajax()` for performance, which
     * means classes only initialised inside Admin::init() are *not*
     * present during AJAX. This method exists so the bootstrap can wire
     * just the AJAX action callbacks for both code paths without paying
     * for the full admin UI on every admin-ajax hit.
     *
     * Idempotent: calling it twice is a no-op because WordPress
     * deduplicates identical (hook, callback, priority) tuples.
     *
     * @return void
     */
    public function register_ajax_handlers(): void {
        add_action( 'wp_ajax_tejcart_import_preview', array( $this, 'ajax_preview_import' ) );
        add_action( 'wp_ajax_tejcart_import_start', array( $this, 'ajax_start_import' ) );
        add_action( 'wp_ajax_tejcart_import_chunk', array( $this, 'ajax_process_chunk' ) );
        add_action( 'wp_ajax_tejcart_import_cancel', array( $this, 'ajax_cancel_import' ) );
    }

    /**
     * Number of sample data rows captured during the preview step. The
     * mapping UI surfaces these so the operator can sanity-check that each
     * CSV column carries the data they expect before committing to an
     * import.
     */
    private const PREVIEW_SAMPLE_ROWS = 5;

    /**
     * Sentinel mapping value meaning "do not import this column". Stored as
     * an empty string in the effective headers array so {@see array_combine()}
     * collapses skipped columns into a single throwaway key instead of
     * leaking into the per-row data.
     */
    private const MAPPING_SKIP = '';

    /**
     * Option key prefix used for persisted import-job state.
     */
    private const JOB_OPTION_PREFIX = 'tejcart_import_job_';

    /**
     * Subdirectory under uploads/ where staged import CSVs are kept until the
     * background job that owns them finishes (or is cleaned up).
     */
    private const STAGING_DIR = 'tejcart-imports';

    /**
     * Handle the CSV export action.
     *
     * @return void
     */
    public function handle_export() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['action'] ) || 'tejcart_export_products' !== $_GET['action'] ) {
            return;
        }

        if ( ! tejcart_can( \TejCart\Core\Capabilities::MANAGE_PRODUCTS ) ) {
            wp_die( esc_html__( 'You do not have permission to export products.', 'tejcart' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_export_products' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'tejcart' ) );
        }

        $this->export_products();
    }

    /**
     * Handle the CSV import action.
     *
     * @return void
     */
    public function handle_import() {
        if ( ! isset( $_POST['action'] ) || 'tejcart_import_products' !== $_POST['action'] ) {
            return;
        }

        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_die( esc_html__( 'You do not have permission to import products.', 'tejcart' ) );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_import_products' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'tejcart' ) );
        }

        if ( ! isset( $_FILES['tejcart_import_file'] ) || empty( $_FILES['tejcart_import_file']['tmp_name'] ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Please select a CSV file to import.', 'tejcart' ) . '</p></div>';
            } );
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = $_FILES['tejcart_import_file'];

        $validation_error = $this->validate_import_file( $file );
        if ( is_wp_error( $validation_error ) ) {
            $message = $validation_error->get_error_message();
            add_action( 'admin_notices', function () use ( $message ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
            } );
            return;
        }

        $dry_run     = ! empty( $_POST['tejcart_import_dry_run'] );
        $skip_images = ! empty( $_POST['tejcart_import_skip_images'] );
        $summary     = $this->import_products(
            $file,
            $dry_run,
            array( 'skip_images' => $skip_images )
        );
        $summary['dry_run'] = $dry_run;

        set_transient( 'tejcart_import_summary', $summary, 60 );

        $redirect = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=import-export&imported=1' );
        if ( $dry_run ) {
            $redirect = add_query_arg( 'dry_run', '1', $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * AJAX: stage an uploaded CSV and return its header row + sample rows so
     * the operator can map foreign column names to TejCart canonical fields.
     *
     * A job row is created in `pass='preview'` so that
     * {@see ajax_cancel_import()} cleans up the staged file if the operator
     * walks away from the mapping screen. The job's effective `headers` are
     * left empty here — they're filled in by {@see ajax_start_import()} once
     * the operator submits the mapping.
     *
     * @return void Emits JSON via wp_send_json_*.
     */
    public function ajax_preview_import(): void {
        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to import products.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_import_products' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        if ( empty( $_FILES['tejcart_import_file']['tmp_name'] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Please select a CSV file to import.', 'tejcart' ) ),
                400
            );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = $_FILES['tejcart_import_file'];

        $validation = $this->validate_import_file( $file );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error(
                array( 'message' => $validation->get_error_message() ),
                400
            );
        }

        $staged = $this->stage_import_file( $file );
        if ( is_wp_error( $staged ) ) {
            wp_send_json_error(
                array( 'message' => $staged->get_error_message() ),
                500
            );
        }

        $sample = $this->read_preview_sample( $staged['path'] );
        if ( is_wp_error( $sample ) ) {
            $this->delete_staged_file( $staged['path'] );
            wp_send_json_error( array( 'message' => $sample->get_error_message() ), 400 );
        }

        $raw_headers = $sample['headers'];
        $suggestion  = self::suggest_mapping( $raw_headers );

        $token = $staged['token'];
        $job   = array(
            'token'         => $token,
            'path'          => $staged['path'],
            'filename'      => isset( $file['name'] ) ? (string) $file['name'] : 'products.csv',
            'raw_headers'   => $raw_headers,
            'headers'       => array(), // Populated by ajax_start_import().
            'alt_format'    => false,   // The mapping flow does not auto-translate values.
            'total_bytes'   => (int) filesize( $staged['path'] ),
            'pass'          => 'preview',
            'offset_bytes'  => 0,
            'row_number'    => 1,
            'dry_run'       => false,
            'skip_images'   => false,
            'batch_size'    => 200,
            'summary'       => array(
                'created'        => 0,
                'updated'        => 0,
                'skipped'        => 0,
                'errors'         => 0,
                'error_messages' => array(),
            ),
            'user_id'       => get_current_user_id(),
            'started_at'    => time(),
            'updated_at'    => time(),
        );

        update_option( self::JOB_OPTION_PREFIX . $token, $job, false );

        $fields  = self::field_definitions();
        $catalog = array();
        foreach ( $fields as $key => $def ) {
            $catalog[] = array(
                'key'         => $key,
                'label'       => (string) $def['label'],
                'required'    => (bool) $def['required'],
                'description' => (string) $def['description'],
            );
        }

        wp_send_json_success(
            array(
                'token'       => $token,
                'filename'    => (string) $job['filename'],
                'total_bytes' => $job['total_bytes'],
                'row_count'   => (int) $sample['row_count'],
                'headers'     => $raw_headers,
                'samples'     => $sample['samples'],
                'suggestion'  => $suggestion,
                'fields'      => $catalog,
                'required'    => array_values(
                    array_keys(
                        array_filter(
                            $fields,
                            static fn( $def ) => ! empty( $def['required'] )
                        )
                    )
                ),
            )
        );
    }

    /**
     * AJAX: commit the operator's column mapping and turn a preview job into
     * a runnable chunked import job.
     *
     * Companion to the chunked progress UI: after the mapping step the
     * browser POSTs `token` (from preview) plus a `mapping[]` array — one
     * entry per CSV column, each holding either a canonical field key or
     * the empty string for "do not import". On success the response carries
     * the same shape the chunk poller expects, so the browser can keep
     * polling `tejcart_import_chunk` without further changes.
     *
     * @return void Emits JSON via wp_send_json_*.
     */
    public function ajax_start_import(): void {
        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to import products.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_import_products' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        if ( '' === $token || 1 !== preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Invalid import token. Please re-upload the file.', 'tejcart' ) ),
                400
            );
        }

        $option_key = self::JOB_OPTION_PREFIX . $token;
        $job        = get_option( $option_key );
        if ( ! is_array( $job ) || 'preview' !== ( $job['pass'] ?? '' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'This import session has expired. Please re-upload the file.', 'tejcart' ) ),
                400
            );
        }
        if ( empty( $job['path'] ) || ! file_exists( (string) $job['path'] ) ) {
            delete_option( $option_key );
            wp_send_json_error(
                array( 'message' => __( 'The staged CSV file is no longer available. Please re-upload.', 'tejcart' ) ),
                400
            );
        }

        $raw_headers = isset( $job['raw_headers'] ) && is_array( $job['raw_headers'] )
            ? array_values( $job['raw_headers'] )
            : array();
        $column_count = count( $raw_headers );

        $posted_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] )
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ? wp_unslash( $_POST['mapping'] )
            : array();

        $mapping = $this->normalize_posted_mapping( $posted_mapping, $column_count );
        if ( is_wp_error( $mapping ) ) {
            wp_send_json_error( array( 'message' => $mapping->get_error_message() ), 400 );
        }

        $dry_run     = ! empty( $_POST['tejcart_import_dry_run'] );
        $skip_images = ! empty( $_POST['tejcart_import_skip_images'] );

        $batch_size = $this->resolve_chunk_batch_size( (bool) $skip_images );

        // Auto-enable parallel sideload for the AJAX path. The chunk's
        // batch cap (default 5 with images) still holds, so the only
        // change vs. pre-1.1 is that those 5 fetches now run in parallel
        // — turning a worst-case 75s chunk into a ~3s chunk on
        // unreachable URLs, with no impact on the timeout envelope.
        $image_concurrency = $skip_images ? 0 : Import\Image_Sideloader::default_concurrency();

        $job['headers']           = $mapping;
        $job['raw_headers']       = $raw_headers;
        $job['alt_format']        = false;
        $job['pass']              = 'parents';
        $job['offset_bytes']      = $this->csv_body_offset( (string) $job['path'] );
        $job['row_number']        = 1;
        $job['dry_run']           = (bool) $dry_run;
        $job['skip_images']       = (bool) $skip_images;
        $job['batch_size']        = $batch_size;
        $job['image_concurrency'] = $image_concurrency;
        $job['image_defer']       = false; // AJAX path needs in-flight results; defer is CLI-only.
        $job['updated_at']        = time();

        update_option( $option_key, $job, false );

        wp_send_json_success(
            array(
                'token'       => $token,
                'total_bytes' => (int) $job['total_bytes'],
                'pass'        => $job['pass'],
                'progress'    => 0.0,
            )
        );
    }

    /**
     * Validate and normalise a posted mapping array.
     *
     * Enforces that:
     *   - the mapping has exactly one entry per CSV column;
     *   - each entry is either '' (skip) or a known canonical field key;
     *   - the two required fields (`name`, `price`) are mapped exactly once;
     *   - no other canonical field is mapped twice.
     *
     * @param array $posted   Raw POSTed mapping (already wp_unslash()ed).
     * @param int   $expected Number of CSV columns.
     * @return string[]|\WP_Error Normalised mapping or a WP_Error.
     */
    private function normalize_posted_mapping( array $posted, int $expected ) {
        if ( $expected <= 0 ) {
            return new \WP_Error(
                'tejcart_import_no_columns',
                __( 'The uploaded file does not appear to have any columns.', 'tejcart' )
            );
        }

        $fields = self::field_definitions();

        // Re-key by position so a sparse POST array (`mapping[0]=name`, `mapping[2]=sku`)
        // still maps cleanly onto the CSV column order.
        $by_index = array();
        foreach ( $posted as $idx => $value ) {
            $by_index[ (int) $idx ] = is_string( $value ) ? sanitize_key( $value ) : '';
        }

        $mapping = array();
        $seen    = array();
        for ( $i = 0; $i < $expected; $i++ ) {
            $value = $by_index[ $i ] ?? '';

            if ( '' === $value ) {
                $mapping[] = self::MAPPING_SKIP;
                continue;
            }

            if ( ! isset( $fields[ $value ] ) ) {
                return new \WP_Error(
                    'tejcart_import_unknown_field',
                    sprintf(
                        /* translators: %s: invalid field key. */
                        __( 'Unknown destination field "%s" in mapping.', 'tejcart' ),
                        $value
                    )
                );
            }

            if ( isset( $seen[ $value ] ) ) {
                return new \WP_Error(
                    'tejcart_import_duplicate_field',
                    sprintf(
                        /* translators: %s: field label. */
                        __( 'Field "%s" is mapped more than once. Each TejCart field can only be mapped to a single CSV column.', 'tejcart' ),
                        (string) $fields[ $value ]['label']
                    )
                );
            }
            $seen[ $value ] = true;

            $mapping[] = $value;
        }

        foreach ( $fields as $key => $def ) {
            if ( ! empty( $def['required'] ) && ! isset( $seen[ $key ] ) ) {
                return new \WP_Error(
                    'tejcart_import_missing_required',
                    sprintf(
                        /* translators: %s: field label. */
                        __( 'The required field "%s" must be mapped to a CSV column.', 'tejcart' ),
                        (string) $def['label']
                    )
                );
            }
        }

        return $mapping;
    }

    /**
     * Read the header row and up to {@see PREVIEW_SAMPLE_ROWS} sample rows
     * from a staged CSV. Also estimates the total data-row count so the
     * mapping UI can show "Found N rows".
     *
     * @param string $path Filesystem path to the staged CSV.
     * @return array{headers:string[], samples:array<int,string[]>, row_count:int}|\WP_Error
     */
    private function read_preview_sample( string $path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new \WP_Error(
                'tejcart_import_unreadable',
                __( 'Unable to open the uploaded file.', 'tejcart' )
            );
        }

        $headers = fgetcsv( $handle, 0, ',', '"', '' );
        if ( ! $headers ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $handle );
            return new \WP_Error(
                'tejcart_import_empty',
                __( 'The CSV file is empty or has no header row.', 'tejcart' )
            );
        }

        if ( isset( $headers[0] ) && is_string( $headers[0] ) && 0 === strncmp( $headers[0], "\xEF\xBB\xBF", 3 ) ) {
            $headers[0] = substr( $headers[0], 3 );
        }
        $headers = array_map(
            static fn( $h ) => (string) ( is_string( $h ) ? trim( $h ) : '' ),
            $headers
        );

        $column_count = count( $headers );
        $samples      = array();
        $row_count    = 0;
        while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            if ( ! is_array( $row ) || empty( array_filter( $row, static fn( $cell ) => '' !== (string) $cell ) ) ) {
                continue;
            }
            $row_count++;
            if ( count( $samples ) < self::PREVIEW_SAMPLE_ROWS ) {
                // Pad/truncate so each sample has exactly $column_count cells
                // — the UI relies on this to align sample values under headers.
                $padded = array();
                for ( $i = 0; $i < $column_count; $i++ ) {
                    $cell = $row[ $i ] ?? '';
                    if ( ! is_string( $cell ) ) {
                        $cell = (string) $cell;
                    }
                    // Clip very long cells so the preview table stays scannable.
                    if ( strlen( $cell ) > 120 ) {
                        $cell = substr( $cell, 0, 117 ) . '…';
                    }
                    $padded[] = $cell;
                }
                $samples[] = $padded;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        return array(
            'headers'   => $headers,
            'samples'   => $samples,
            'row_count' => $row_count,
        );
    }

    /**
     * AJAX: process a single chunk against an open import job.
     *
     * Each invocation streams up to `batch_size` rows for the job's current
     * pass, advances the byte offset, and returns the new progress. When the
     * file's last pass (references) reaches EOF the staged file and option are
     * cleaned up and a final summary is returned.
     *
     * @return void Emits JSON via wp_send_json_*.
     */
    public function ajax_process_chunk(): void {
        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to import products.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_import_products' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        if ( '' === $token || 1 !== preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Invalid import token.', 'tejcart' ) ),
                400
            );
        }

        try {
            $result = $this->process_import_chunk( $token );
        } catch ( \Throwable $e ) {
            // Anything thrown from a row handler (DB error, image sideload,
            // taxonomy edge case) would otherwise bubble out as a bare 500
            // and the JS fetch would only see a non-JSON response — which
            // surfaces as the generic "Import failed." with no detail. Give
            // the client a structured message instead so the user can act on
            // it and the import job stays cleanable via Cancel.
            $result = new \WP_Error(
                'tejcart_import_chunk_throwable',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Import chunk failed: %s', 'tejcart' ),
                    $e->getMessage()
                )
            );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array( 'message' => $result->get_error_message() ),
                400
            );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: cancel an in-flight import job, deleting its staged file + state.
     *
     * @return void Emits JSON via wp_send_json_*.
     */
    public function ajax_cancel_import(): void {
        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_send_json_error( array(), 403 );
        }
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_import_products' ) ) {
            wp_send_json_error( array(), 403 );
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        if ( '' === $token || 1 !== preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
            wp_send_json_error( array(), 400 );
        }

        $job = get_option( self::JOB_OPTION_PREFIX . $token );
        if ( is_array( $job ) && ! empty( $job['path'] ) ) {
            $this->delete_staged_file( (string) $job['path'] );
        }
        delete_option( self::JOB_OPTION_PREFIX . $token );

        wp_send_json_success( array( 'cancelled' => true ) );
    }

    /**
     * Move the uploaded CSV from PHP's tmp dir into a private staging folder
     * under uploads/ so it survives across the AJAX chunk requests.
     *
     * @param array $file $_FILES entry.
     * @return array{token:string, path:string}|\WP_Error
     */
    private function stage_import_file( array $file ) {
        $upload = wp_get_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new \WP_Error( 'tejcart_import_upload_dir', (string) $upload['error'] );
        }

        $dir = trailingslashit( (string) $upload['basedir'] ) . self::STAGING_DIR;
        if ( ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error(
                'tejcart_import_mkdir_failed',
                __( 'Could not create the import staging directory.', 'tejcart' )
            );
        }

        // Belt-and-braces deny rules in case the uploads root isn't already private.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents( $htaccess, "Require all denied\n" );
        }
        $index = $dir . '/index.html';
        if ( ! file_exists( $index ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents( $index, '' );
        }

        $token = bin2hex( random_bytes( 16 ) );
        $path  = $dir . '/' . $token . '.csv';

        $tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        if ( '' === $tmp ) {
            return new \WP_Error( 'tejcart_import_unreadable', __( 'Could not read the uploaded file.', 'tejcart' ) );
        }

        // Real browser upload — funnel through wp_handle_upload() so the WP
        // filter chain (upload_mimes, wp_handle_upload_prefilter, mime check)
        // runs against the file. Nonce + capability were already verified by
        // the AJAX caller, so test_form=false is safe here.
        if ( ! function_exists( 'wp_handle_upload' ) && defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $moved = false;
        if ( function_exists( 'is_uploaded_file' ) && function_exists( 'wp_handle_upload' ) && is_uploaded_file( $tmp ) ) {
            $handled = wp_handle_upload(
                $file,
                array(
                    'test_form' => false,
                    'mimes'     => array(
                        'csv' => 'text/csv',
                        'txt' => 'text/plain',
                    ),
                )
            );
            if ( is_array( $handled ) && ! empty( $handled['file'] ) ) {
                // wp_handle_upload writes into uploads/ — relocate the result
                // into our private staging dir under the token filename so
                // the chunked importer can find it deterministically.
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
                $moved = @rename( (string) $handled['file'], $path );
                if ( ! $moved ) {
                    $moved = copy( (string) $handled['file'], $path );
                    if ( $moved ) {
                        wp_delete_file( (string) $handled['file'] );
                    }
                }
            } elseif ( is_array( $handled ) && ! empty( $handled['error'] ) ) {
                return new \WP_Error( 'tejcart_import_stage_failed', (string) $handled['error'] );
            }
        }

        // Synthetic / test path: $_FILES is not a real HTTP upload, so the
        // is_uploaded_file gate above doesn't apply. Fall back to rename/copy.
        if ( ! $moved ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
            $moved = @rename( $tmp, $path );
        }
        if ( ! $moved ) {
            $moved = copy( $tmp, $path );
        }
        if ( ! $moved ) {
            return new \WP_Error(
                'tejcart_import_stage_failed',
                __( 'Could not stage the uploaded file for processing.', 'tejcart' )
            );
        }

        // SEC-022 — chmod failures used to be silently swallowed,
        // which on a hostile host would leave the staged upload
        // world-readable. Check the return value and log so
        // operators can spot the broken-permissions case.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        if ( ! chmod( $path, 0640 ) && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'Product import: chmod(0640) failed on staged upload %s.', $path ),
                'warning'
            );
        }

        return array( 'token' => $token, 'path' => $path );
    }

    /**
     * Delete a staged import file, ignoring missing-file errors.
     */
    private function delete_staged_file( string $path ): void {
        if ( '' === $path ) {
            return;
        }
        $upload = wp_get_upload_dir();
        $base   = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . self::STAGING_DIR;
        $real   = realpath( $path );
        $base_r = realpath( $base );
        // Defence in depth — only ever unlink files inside the staging dir.
        if ( $real && $base_r && 0 === strpos( $real, $base_r ) && file_exists( $real ) ) {
            wp_delete_file( $real );
        }
    }

    /**
     * Return the byte offset of the first body row in $path (i.e. the position
     * after the header row + any UTF-8 BOM). Used to seed `offset_bytes` on a
     * fresh job so the first chunk picks up at row 2.
     */
    private function csv_body_offset( string $path ): int {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return 0;
        }
        // PHP 8.4 deprecated the implicit default $escape; pin to '' for forward compat.
        fgetcsv( $handle, 0, ',', '"', '' );
        $offset = (int) ftell( $handle );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );
        return $offset;
    }

    /**
     * Process a single chunk against the persisted job state.
     *
     * Picks up at the saved byte offset, processes up to batch_size rows, then
     * persists the new offset. On EOF for the current pass, advances:
     *   parents -> variations -> references -> done.
     * On 'done' the staged file and option row are deleted.
     *
     * @param string $token Job token.
     * @return array{token:string, pass:string, progress:float, complete:bool, summary:array}|\WP_Error
     */
    public function process_import_chunk( string $token ) {
        global $wpdb;

        $option_key = self::JOB_OPTION_PREFIX . $token;
        $job        = get_option( $option_key );
        if ( ! is_array( $job ) || empty( $job['path'] ) || empty( $job['headers'] ) ) {
            return new \WP_Error(
                'tejcart_import_unknown_job',
                __( 'This import job has expired or was already completed.', 'tejcart' )
            );
        }

        if ( ! file_exists( (string) $job['path'] ) ) {
            delete_option( $option_key );
            return new \WP_Error(
                'tejcart_import_missing_file',
                __( 'The staged import file is no longer available.', 'tejcart' )
            );
        }

        // Resource hardening on every chunk so we survive even if php.ini caps
        // are tight on the host's admin-ajax handler.
        $this->raise_resource_limits();
        if ( function_exists( 'wp_suspend_cache_addition' ) ) {
            wp_suspend_cache_addition( true );
        }

        $this->skip_images            = ! empty( $job['skip_images'] );
        $this->alt_format_mode        = ! empty( $job['alt_format'] );
        $this->image_concurrency      = isset( $job['image_concurrency'] ) ? max( 0, min( 64, (int) $job['image_concurrency'] ) ) : 0;
        $this->image_defer            = ! empty( $job['image_defer'] );
        $this->image_buffer           = array();
        $this->image_attachment_cache = array();
        $this->image_sideloader       = null;
        $this->term_cache             = array();

        $pass         = (string) $job['pass'];
        $headers      = (array) $job['headers'];
        $batch_size   = max( 1, (int) ( $job['batch_size'] ?? 200 ) );
        $offset_bytes = (int) $job['offset_bytes'];
        $row_number   = (int) $job['row_number'];
        $summary      = is_array( $job['summary'] ) ? $job['summary'] : array(
            'created'        => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'errors'         => 0,
            'error_messages' => array(),
        );

        // Done short-circuit (e.g. polled after completion).
        if ( 'done' === $pass ) {
            return array(
                'token'    => $token,
                'pass'     => 'done',
                'progress' => 1.0,
                'complete' => true,
                'summary'  => $summary,
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( (string) $job['path'], 'r' );
        if ( ! $handle ) {
            return new \WP_Error(
                'tejcart_import_open_failed',
                __( 'Could not reopen the staged import file.', 'tejcart' )
            );
        }
        fseek( $handle, $offset_bytes );

        $sku_to_id = array();
        $processed = 0;
        $eof       = false;

        $use_batch_tx = empty( $job['dry_run'] );
        if ( $use_batch_tx ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'START TRANSACTION' );
        }

        while ( $processed < $batch_size ) {
            $row = fgetcsv( $handle, 0, ',', '"', '' );
            if ( false === $row ) {
                $eof = true;
                break;
            }
            $row_number++;

            if ( null === $row || empty( array_filter( $row, static fn( $cell ) => '' !== (string) $cell ) ) ) {
                if ( 'parents' === $pass ) {
                    $summary['skipped']++;
                }
                $processed++;
                continue;
            }

            if ( count( $row ) !== count( $headers ) ) {
                if ( 'parents' === $pass ) {
                    $summary['errors']++;
                    $summary['error_messages'][] = sprintf(
                        /* translators: %d: row number */
                        __( 'Row %d: column count mismatch. Skipped.', 'tejcart' ),
                        $row_number
                    );
                }
                $processed++;
                continue;
            }

            $data = array_combine( $headers, $row );
            if ( $this->alt_format_mode ) {
                $data = External_CSV_Compat::translate_row( $data );
            }
            $data = apply_filters( 'tejcart_csv_import_row', $data, $row_number );
            if ( empty( $data ) || ! is_array( $data ) ) {
                if ( 'parents' === $pass ) {
                    $summary['skipped']++;
                }
                $processed++;
                continue;
            }

            $row_type = strtolower( (string) ( $data['type'] ?? '' ) );

            try {
                switch ( $pass ) {
                    case 'parents':
                        if ( 'variation' !== $row_type ) {
                            $this->import_product_row( $data, $row_number, $summary, $sku_to_id );
                        }
                        break;
                    case 'variations':
                        if ( 'variation' === $row_type ) {
                            $this->import_variation_row( $data, $row_number, $summary, $sku_to_id );
                        }
                        break;
                    case 'references':
                        $sku = isset( $data['sku'] ) ? trim( (string) $data['sku'] ) : '';
                        if ( '' !== $sku ) {
                            // Resolve target id either from the within-batch map or the DB.
                            $target_id = $sku_to_id[ $sku ] ?? 0;
                            if ( ! $target_id ) {
                                $table = $wpdb->prefix . 'tejcart_products';
                                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                                $target_id = (int) $wpdb->get_var( $wpdb->prepare(
                                    "SELECT id FROM {$table} WHERE sku = %s LIMIT 1",
                                    $sku
                                ) );
                                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                                if ( $target_id > 0 ) {
                                    $sku_to_id[ $sku ] = $target_id;
                                }
                            }
                            if ( $target_id > 0 ) {
                                $this->apply_sku_references( (int) $target_id, $data, $sku_to_id );
                            }
                        }
                        break;
                }
            } catch ( \Throwable $e ) {
                // One bad row (image sideload throwing, taxonomy edge case,
                // strict-mode SQL rejection) shouldn't take down the whole
                // chunk and force the whole import back to "Import failed."
                // Log it against the row and keep streaming.
                if ( 'parents' === $pass ) {
                    $summary['errors']++;
                    $summary['error_messages'][] = sprintf(
                        /* translators: 1: row number, 2: error message */
                        __( 'Row %1$d: %2$s', 'tejcart' ),
                        $row_number,
                        $e->getMessage()
                    );
                }
            }

            $processed++;
        }

        $offset_bytes = (int) ftell( $handle );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        // Flush parallel-sideload buffer (or enqueue deferred jobs) before
        // closing the chunk's transaction so attachments and product rows
        // commit together.
        $this->flush_image_buffer( $summary );

        if ( $use_batch_tx ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'COMMIT' );
        }

        // Pass transition on EOF.
        if ( $eof ) {
            switch ( $pass ) {
                case 'parents':
                    $pass         = 'variations';
                    $offset_bytes = $this->csv_body_offset( (string) $job['path'] );
                    $row_number   = 1;
                    break;
                case 'variations':
                    $pass         = 'references';
                    $offset_bytes = $this->csv_body_offset( (string) $job['path'] );
                    $row_number   = 1;
                    break;
                case 'references':
                    $pass = 'done';
                    break;
            }
        }

        $job['pass']         = $pass;
        $job['offset_bytes'] = $offset_bytes;
        $job['row_number']   = $row_number;
        $job['summary']      = $summary;
        $job['updated_at']   = time();

        $total_bytes = max( 1, (int) $job['total_bytes'] );

        if ( 'done' === $pass ) {
            // Drop the staged file + state on completion. Dry-run summary is
            // surfaced through the JSON response, then thrown away with the row.
            if ( empty( $job['dry_run'] ) ) {
                $summary['dry_run'] = false;
            } else {
                $summary['dry_run'] = true;
            }
            $this->delete_staged_file( (string) $job['path'] );
            delete_option( $option_key );

            // Stash for the redirect-fed admin notice path used by the
            // non-AJAX form flow, so the same UI element renders the result.
            set_transient( 'tejcart_import_summary', $summary, 60 );

            return array(
                'token'    => $token,
                'pass'     => 'done',
                'progress' => 1.0,
                'complete' => true,
                'summary'  => $summary,
            );
        }

        update_option( $option_key, $job, false );

        // Three passes: progress within each pass is offset/total, and we
        // weight each pass equally — UX-wise that's smoother than back-to-zero.
        $pass_order = array( 'parents' => 0, 'variations' => 1, 'references' => 2 );
        $pass_index = $pass_order[ $pass ] ?? 0;
        $within     = min( 1.0, $offset_bytes / $total_bytes );
        $progress   = ( $pass_index + $within ) / 3.0;

        return array(
            'token'    => $token,
            'pass'     => $pass,
            'progress' => $progress,
            'complete' => false,
            'summary'  => $summary,
        );
    }

    /**
     * Validate that the uploaded $_FILES entry is an acceptable CSV.
     *
     * Checks:
     *   - MIME / extension matches an allowlist of CSV content types.
     *   - File size is within `tejcart_import_max_file_size` (default 10 MiB).
     *   - Content decodes as valid UTF-8 once a leading BOM is stripped.
     *
     * @param array $file $_FILES entry.
     * @return true|\WP_Error True on success, WP_Error on the first violation.
     */
    private function validate_import_file( array $file ) {
        $name     = isset( $file['name'] ) ? (string) $file['name'] : '';
        $tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        $size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

        if ( '' === $tmp_name || ! is_readable( $tmp_name ) ) {
            return new \WP_Error(
                'tejcart_import_unreadable',
                __( 'Could not read the uploaded file.', 'tejcart' )
            );
        }

        /**
         * Filter the maximum allowed CSV upload size in bytes.
         *
         * Defaults to 100 MiB so a typical 20K-row catalog CSV (~40-80 MiB)
         * imports without a filter override. Hosts wanting a tighter ceiling
         * should drop this filter to a lower value.
         *
         * @param int $max_bytes Default 100 MiB.
         */
        $max_bytes = (int) apply_filters( 'tejcart_import_max_file_size', 100 * 1024 * 1024 );
        if ( $max_bytes > 0 && $size > $max_bytes ) {
            return new \WP_Error(
                'tejcart_import_too_large',
                sprintf(
                    /* translators: 1: file size, 2: maximum allowed size */
                    __( 'The uploaded file is too large (%1$s). Maximum allowed: %2$s.', 'tejcart' ),
                    size_format( $size ),
                    size_format( $max_bytes )
                )
            );
        }

        $mimes = array(
            'csv' => 'text/csv',
            'txt' => 'text/plain',
        );

        // Extension-based validation only. We deliberately do NOT use
        // wp_check_filetype_and_ext() here — its real-content sniffing via
        // finfo_file() routinely classifies CSVs as application/octet-stream
        // or application/csv on shared hosts, and security plugins (iThemes
        // Security "Strict File Type Check", Wordfence, etc.) hook the
        // filter to clamp ext/type to false. Both modes would reject a
        // perfectly valid CSV with a flat 400 — exactly the failure mode
        // users hit when feeding the bundled sample file. The real content
        // gates that follow (UTF-8 body check + required-column header
        // validation in read_csv_headers()) are what actually protect us
        // from someone uploading a non-CSV with a .csv extension.
        $check = wp_check_filetype( $name, $mimes );
        if ( empty( $check['ext'] ) ) {
            return new \WP_Error(
                'tejcart_import_bad_type',
                __( 'Only CSV files may be imported.', 'tejcart' )
            );
        }

        // Sniffing the first 4 KiB of an uploaded CSV; WP_Filesystem only does whole-file reads.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen( $tmp_name, 'rb' );
        if ( ! $fh ) {
            return new \WP_Error(
                'tejcart_import_unreadable',
                __( 'Could not read the uploaded file.', 'tejcart' )
            );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $sample = fread( $fh, 4096 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fh );
        if ( false === $sample ) {
            return new \WP_Error(
                'tejcart_import_unreadable',
                __( 'Could not read the uploaded file.', 'tejcart' )
            );
        }

        if ( 0 === strncmp( $sample, "\xEF\xBB\xBF", 3 ) ) {
            $sample = substr( $sample, 3 );
        }

        if ( '' !== $sample && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $sample, 'UTF-8' ) ) {
            return new \WP_Error(
                'tejcart_import_bad_encoding',
                __( 'The uploaded file is not valid UTF-8. Re-save it as UTF-8 and try again.', 'tejcart' )
            );
        }

        return true;
    }

    /**
     * Export all products as a CSV download.
     *
     * @return void
     */
    public function export_products() {
        $filename = 'products-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        $this->stream_export_rows( $output );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
        exit;
    }

    /**
     * Stream the full product catalog as CSV rows into $output.
     *
     * Audit #76 / 07 F-6 — previously this code path materialised the
     * full (id, sku) table into a single PHP array before the first
     * row was written. On a 1M-product catalog that crossed
     * `memory_limit`. The new path resolves SKU references **per batch**
     * via a single `WHERE id IN (…)` lookup so peak memory is bounded
     * by `batch_size + sku_cache_cap` regardless of catalog size.
     *
     * Extracted from {@see export_products()} so unit tests can drive
     * it against an in-memory stream without `exit`-ing the worker.
     * Output is byte-for-byte identical to the single-batch path.
     *
     * @param resource $output Writable stream (typically `php://output`).
     * @return void
     */
    private function stream_export_rows( $output ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        /**
         * Filter the CSV column set used for product export.
         *
         * Addons can splice in their own columns (and populate them via
         * tejcart_csv_export_row for each product). Column order in the
         * header and rows is driven by this filter.
         *
         * @since 1.0.0
         *
         * @param string[] $columns Column names in output order.
         */
        $columns = apply_filters( 'tejcart_csv_export_columns', self::CSV_COLUMNS );

        // Explicit empty $escape — PHP 8.4 deprecated the implicit default
        // and CI runs the 8.2/8.3/8.4 matrix. Same pattern used elsewhere
        // in this class on the import side.
        fputcsv( $output, $columns, ',', '"', '' );

        $batch_size = (int) apply_filters( 'tejcart_export_batch_size', 1000 );
        if ( $batch_size <= 0 ) {
            $batch_size = 1000;
        }

        /**
         * Filter the per-request cap on the lazy SKU-lookup cache.
         *
         * The cache is populated on-demand from each batch's referenced
         * IDs (variation parent, grouped children, upsells, cross-sells,
         * related, bundled items). When it grows past this cap we drop
         * the oldest half — entries are still resolvable, we just refetch.
         * Default 5000 keeps the cache around 200 KB on a typical catalog
         * while letting most cross-batch references hit the warm cache.
         *
         * @since 1.0.0
         *
         * @param int $cap Max entries before LRU-style trim.
         */
        $sku_cache_cap = (int) apply_filters( 'tejcart_export_sku_cache_max_entries', 5000 );
        if ( $sku_cache_cap <= 0 ) {
            $sku_cache_cap = 5000;
        }

        $id_to_sku = array();
        $last_id   = 0;

        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $batch = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                    $last_id,
                    $batch_size
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! is_array( $batch ) || empty( $batch ) ) {
                break;
            }

            // Seed the cache with the (id, sku) pairs from THIS batch's
            // own rows so the variation_parent_sku lookup (which usually
            // references a sibling row in the same id range) hits the
            // warm cache without a separate query.
            foreach ( $batch as $product ) {
                $id_to_sku[ (int) $product['id'] ] = (string) ( $product['sku'] ?? '' );
            }

            // Pre-resolve any cross-batch parent / related / upsell /
            // cross-sell / grouped-child / bundled SKUs referenced from
            // THIS batch in a single bulk SELECT, so each subsequent
            // build_export_cell() call hits the warm cache.
            $referenced_ids = $this->collect_referenced_product_ids( $batch, $id_to_sku );
            if ( ! empty( $referenced_ids ) ) {
                $this->resolve_sku_lookup_bulk( $referenced_ids, $id_to_sku );
            }

            // Bound the cache so a pathological cross-sell graph (a
            // catalog where every product references thousands of others)
            // can't blow PHP memory. When we overflow, drop the oldest
            // half — the rest still resolves, we just re-query on demand.
            if ( count( $id_to_sku ) > $sku_cache_cap ) {
                $id_to_sku = array_slice( $id_to_sku, intval( $sku_cache_cap / 2 ), null, true );
            }

            foreach ( $batch as $product ) {
                $row = array();
                foreach ( $columns as $col ) {
                    $row[] = $this->build_export_cell( $col, $product, $id_to_sku );
                }

                /**
                 * Filter a single row emitted to the export CSV.
                 *
                 * @since 1.0.0
                 *
                 * @param array $row     Numerically-indexed row matching $columns.
                 * @param array $product Raw product row from the DB.
                 * @param array $columns Column order.
                 */
                $row = apply_filters( 'tejcart_csv_export_row', $row, $product, $columns );

                // Explicit $escape='' for PHP 8.4 forward compat; see
                // header fputcsv() comment above.
                fputcsv( $output, tejcart_csv_sanitize_row( (array) $row ), ',', '"', '' );

                $last_id = (int) $product['id'];
            }

            // Flush each batch so the client gets bytes as we go and the
            // PHP process doesn't hold the whole catalog in the output buffer.
            if ( function_exists( 'flush' ) ) {
                flush();
            }
        } while ( count( $batch ) === $batch_size );
    }

    /**
     * Scan a freshly-fetched batch of product rows for cross-batch SKU
     * references — variation parent, grouped children, upsell, cross-sell,
     * related, bundled items — and return the IDs that aren't already in
     * the warm cache.
     *
     * Only IDs that the row's `type` actually consumes are collected so
     * we don't pay for a million unneeded lookups on a catalog of mostly
     * simple products with no related-product graph.
     *
     * @param array<int, array<string, mixed>> $batch     Batch rows.
     * @param array<int, string>               $id_to_sku Warm cache.
     * @return int[] Distinct product IDs to resolve.
     */
    private function collect_referenced_product_ids( array $batch, array $id_to_sku ): array {
        $ids = array();

        foreach ( $batch as $product ) {
            $product_id = (int) ( $product['id'] ?? 0 );
            if ( $product_id <= 0 ) {
                continue;
            }
            $type = (string) ( $product['type'] ?? '' );

            // Variation parent — every variation row needs one.
            if ( 'variation' === $type ) {
                $parent_id = (int) $this->get_product_meta_raw( $product_id, '_variation_parent_id' );
                if ( $parent_id > 0 && ! isset( $id_to_sku[ $parent_id ] ) ) {
                    $ids[ $parent_id ] = true;
                }
            }

            // Bundled items — bundle rows reference their bundled product IDs.
            if ( 'bundle' === $type ) {
                $raw = $this->get_product_meta_raw( $product_id, '_bundled_items' );
                $decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $item ) {
                        if ( is_array( $item ) && ! empty( $item['product_id'] ) ) {
                            $candidate = (int) $item['product_id'];
                            if ( $candidate > 0 && ! isset( $id_to_sku[ $candidate ] ) ) {
                                $ids[ $candidate ] = true;
                            }
                        }
                    }
                }
            }

            // Grouped children — grouped rows reference their child IDs.
            if ( 'grouped' === $type ) {
                $this->collect_ids_from_list_meta( $product_id, '_grouped_products', $id_to_sku, $ids );
            }

            // Upsell / cross-sell / related — any row can carry these.
            $this->collect_ids_from_list_meta( $product_id, '_upsell_ids',    $id_to_sku, $ids );
            $this->collect_ids_from_list_meta( $product_id, '_crosssell_ids', $id_to_sku, $ids );
            $this->collect_ids_from_list_meta( $product_id, '_related_ids',   $id_to_sku, $ids );
        }

        return array_map( 'intval', array_keys( $ids ) );
    }

    /**
     * Helper for {@see collect_referenced_product_ids()} — read a JSON /
     * array meta value and add any cache-miss IDs to $ids (as keys, so
     * duplicates collapse).
     *
     * @param int                  $product_id
     * @param string               $meta_key
     * @param array<int, string>   $id_to_sku Warm cache (read-only).
     * @param array<int, true>     $ids       Accumulator (mutated by ref).
     */
    private function collect_ids_from_list_meta( int $product_id, string $meta_key, array $id_to_sku, array &$ids ): void {
        $raw = $this->get_product_meta_raw( $product_id, $meta_key );

        $list = array();
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $list = $decoded;
            }
        } elseif ( is_array( $raw ) ) {
            $list = $raw;
        }

        foreach ( $list as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! isset( $id_to_sku[ $id ] ) ) {
                $ids[ $id ] = true;
            }
        }
    }

    /**
     * Resolve a list of product IDs to SKUs in a single bulk query and
     * merge the results into the warm cache. IDs that don't exist are
     * still cached as empty strings so we never re-query the same miss.
     *
     * @param int[]              $ids       Distinct product IDs to look up.
     * @param array<int, string> $id_to_sku Warm cache (mutated by reference).
     */
    private function resolve_sku_lookup_bulk( array $ids, array &$id_to_sku ): void {
        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

        // Build a placeholder string so $wpdb->prepare() can bind every
        // id safely. WPDB doesn't expand %d arrays natively so we hand-
        // roll the placeholders.
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sku FROM {$table} WHERE id IN ({$placeholders})",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $found = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $id              = (int) ( $r['id'] ?? 0 );
                $id_to_sku[ $id ] = (string) ( $r['sku'] ?? '' );
                $found[ $id ]    = true;
            }
        }

        // Cache misses as empty strings so the next batch doesn't re-query
        // the same dead reference (e.g. an upsell pointing at a deleted
        // product). Same behaviour the upfront load gave for free.
        foreach ( $ids as $id ) {
            if ( ! isset( $found[ $id ] ) && ! isset( $id_to_sku[ $id ] ) ) {
                $id_to_sku[ $id ] = '';
            }
        }
    }

    /**
     * Return a single cell value for the export row of a given column.
     *
     * @param string $col       Column name.
     * @param array  $product   Raw DB row.
     * @param array  $id_to_sku Pre-built lookup of product id => sku.
     * @return string
     */
    private function build_export_cell( string $col, array $product, array $id_to_sku ): string {
        switch ( $col ) {
            case 'price':
            case 'sale_price':
            case 'stock_quantity':
            case 'manage_stock':
            case 'weight':
            case 'min_purchase_quantity':
            case 'max_purchase_quantity':
            case 'sold_individually':
            case 'featured':
            case 'backorders':
            case 'catalog_visibility':
            case 'tax_class':
            case 'shipping_class':
                return isset( $product[ $col ] ) ? (string) $product[ $col ] : '';

            case 'length':
            case 'width':
            case 'height':
                $dims = ! empty( $product['dimensions'] ) ? json_decode( (string) $product['dimensions'], true ) : array();
                return is_array( $dims ) ? (string) ( $dims[ $col ] ?? '' ) : '';

            case 'categories':
                return $this->sanitize_csv_value(
                    $this->get_product_terms( (int) $product['id'], \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY )
                );

            case 'tags':
                return $this->sanitize_csv_value(
                    $this->get_product_terms( (int) $product['id'], \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY )
                );

            case 'brands':
                return $this->sanitize_csv_value(
                    $this->get_product_terms( (int) $product['id'], \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY )
                );

            case 'image_url':
                $url = ! empty( $product['image_id'] ) ? wp_get_attachment_url( (int) $product['image_id'] ) : '';
                return $this->sanitize_csv_value( (string) $url );

            case 'gallery_image_urls':
                $ids = ! empty( $product['gallery_ids'] ) ? json_decode( (string) $product['gallery_ids'], true ) : array();
                if ( ! is_array( $ids ) || empty( $ids ) ) {
                    return '';
                }
                $urls = array();
                foreach ( $ids as $gid ) {
                    $u = wp_get_attachment_url( (int) $gid );
                    if ( $u ) {
                        $urls[] = $u;
                    }
                }
                return $this->sanitize_csv_value( implode( '|', $urls ) );

            case 'variation_parent_sku':
                if ( 'variation' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $parent_id = (int) $this->get_product_meta_raw( (int) $product['id'], '_variation_parent_id' );
                return $this->sanitize_csv_value( $id_to_sku[ $parent_id ] ?? '' );

            case 'variation_attributes':
                if ( 'variation' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_variation_attributes' );
                return $this->sanitize_csv_value( $this->normalize_json_meta( $raw ) );

            case 'bundled_items_json':
                if ( 'bundle' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_bundled_items' );
                return $this->sanitize_csv_value( $this->normalize_json_meta( $raw ) );

            case 'grouped_children_skus':
                if ( 'grouped' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_grouped_products' );
                return $this->sanitize_csv_value( $this->ids_to_skus_csv( $raw, $id_to_sku ) );

            case 'upsell_skus':
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_upsell_ids' );
                return $this->sanitize_csv_value( $this->ids_to_skus_csv( $raw, $id_to_sku ) );

            case 'cross_sell_skus':
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_crosssell_ids' );
                return $this->sanitize_csv_value( $this->ids_to_skus_csv( $raw, $id_to_sku ) );

            case 'related_skus':
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_related_ids' );
                return $this->sanitize_csv_value( $this->ids_to_skus_csv( $raw, $id_to_sku ) );

            case 'downloadable_files_json':
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_download_files' );
                return $this->sanitize_csv_value( $this->normalize_json_meta( $raw ) );

            case 'external_url':
                if ( 'external' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_product_url' );
                return $this->sanitize_csv_value( is_string( $raw ) ? $raw : '' );

            case 'external_button_text':
                if ( 'external' !== ( $product['type'] ?? '' ) ) {
                    return '';
                }
                $raw = $this->get_product_meta_raw( (int) $product['id'], '_button_text' );
                return $this->sanitize_csv_value( is_string( $raw ) ? $raw : '' );

            default:
                return $this->sanitize_csv_value( isset( $product[ $col ] ) ? (string) $product[ $col ] : '' );
        }
    }

    /**
     * Fetch a single meta value directly, without instantiating the full
     * product object (export runs over the entire catalog).
     *
     * @return mixed Unserialized value or '' on miss.
     */
    private function get_product_meta_raw( int $product_id, string $key ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_product_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE product_id = %d AND meta_key = %s LIMIT 1",
            $product_id,
            $key
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( null === $raw ) {
            return '';
        }
        return maybe_unserialize( $raw, array( 'allowed_classes' => false ) );
    }

    /**
     * Normalize a meta value that may be JSON-encoded, array, or
     * serialized — always return a JSON string ready for CSV output.
     *
     * @param mixed $raw Raw meta value.
     * @return string JSON (empty string when $raw is empty).
     */
    private function normalize_json_meta( $raw ): string {
        if ( '' === $raw || null === $raw ) {
            return '';
        }

        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $raw = $decoded;
            }
        }

        if ( is_array( $raw ) ) {
            return (string) wp_json_encode( $raw );
        }

        return '';
    }

    /**
     * Convert an id-list meta (array or JSON) to a pipe-delimited list
     * of SKUs, resolved against $id_to_sku. Unknown IDs are dropped.
     */
    private function ids_to_skus_csv( $raw, array $id_to_sku ): string {
        $ids = array();

        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            $ids     = is_array( $decoded ) ? $decoded : array();
        } elseif ( is_array( $raw ) ) {
            $ids = $raw;
        }

        $skus = array();
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( isset( $id_to_sku[ $id ] ) && '' !== $id_to_sku[ $id ] ) {
                $skus[] = $id_to_sku[ $id ];
            }
        }

        return implode( '|', $skus );
    }

    /**
     * Prefix formula-injection characters to prevent CSV formula injection.
     *
     * @param string $value Raw string value.
     * @return string Safe string value.
     */
    private function sanitize_csv_value( string $value ): string {
        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }

    /**
     * Get comma-separated term names for a product.
     *
     * @param int    $product_id Product ID.
     * @param string $taxonomy   Taxonomy name (product_cat or product_tag).
     * @return string
     */
    private function get_product_terms( $product_id, $taxonomy ) {
        global $wpdb;

        $rel_table = $wpdb->prefix . 'tejcart_term_relationships';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $term_ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$rel_table} WHERE product_id = %d",
                $product_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $term_ids ) ) {
            return '';
        }

        $names = array();
        foreach ( $term_ids as $tt_id ) {
            $term = get_term_by( 'term_taxonomy_id', (int) $tt_id );
            if ( $term && ! is_wp_error( $term ) && $term->taxonomy === $taxonomy ) {
                $names[] = $term->name;
            }
        }

        return implode( ', ', $names );
    }

    /**
     * Render the import/export admin page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header for composition inside another
     *                      admin screen (Settings → Advanced → Import/Export).
     * @return void
     */
    public function render_page( $embedded = false ) {
        $export_url = wp_nonce_url(
            admin_url( 'admin.php?action=tejcart_export_products' ),
            'tejcart_export_products'
        );

        $max_upload = size_format( wp_max_upload_size() );

        $summary = get_transient( 'tejcart_import_summary' );
        if ( $summary ) {
            delete_transient( 'tejcart_import_summary' );
        }

        // The tab styles, step indicator, and card chrome live in the
        // product-import stylesheet, so we have to enqueue it on every
        // pageload — not just when the Import tab is active. Without
        // this, switching to the Export tab leaves the operator on an
        // unstyled stack of plain links because the import-form code
        // path (which used to be the only enqueue site) never runs.
        wp_enqueue_style(
            'tejcart-admin-product-import',
            tejcart_asset_url( 'assets/css/admin/product-import.css' ),
            array( 'dashicons' ),
            $this->asset_version( 'assets/css/admin/product-import.css' )
        );

        // Default to the Import tab — the column-mapping flow is the new
        // headline experience and most operators land here to upload, not
        // to download. Honour an explicit `?ie_tab=` query for deep-linking.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only tab switch; no state change.
        $active_tab = isset( $_GET['ie_tab'] ) ? sanitize_key( wp_unslash( $_GET['ie_tab'] ) ) : 'import';
        if ( 'export' !== $active_tab ) {
            $active_tab = 'import';
        }
        $base_url = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=import-export' );

        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Import / Export', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Bulk import and export your product catalog.', 'tejcart' ); ?></p>
                </div>
            </div>
        <?php endif; ?>

            <?php if ( $summary ) : ?>
                <?php $is_dry_run = ! empty( $summary['dry_run'] ); ?>
                <div class="notice <?php echo $is_dry_run ? 'notice-info' : 'notice-success'; ?>">
                    <p>
                        <strong><?php echo $is_dry_run ? esc_html__( 'Dry run complete — no changes were written.', 'tejcart' ) : esc_html__( 'Import complete.', 'tejcart' ); ?></strong>
                    </p>
                    <p>
                        <?php
                        if ( $is_dry_run ) {
                            printf(
                                /* translators: 1: created count, 2: updated count, 3: skipped count, 4: error count */
                                esc_html__( 'Would create: %1$d, update: %2$d, skip: %3$d, errors: %4$d.', 'tejcart' ),
                                (int) $summary['created'],
                                (int) $summary['updated'],
                                (int) $summary['skipped'],
                                (int) $summary['errors']
                            );
                        } else {
                            printf(
                                /* translators: 1: created count, 2: updated count, 3: skipped count, 4: error count */
                                esc_html__( 'Created: %1$d, updated: %2$d, skipped: %3$d, errors: %4$d.', 'tejcart' ),
                                (int) $summary['created'],
                                (int) $summary['updated'],
                                (int) $summary['skipped'],
                                (int) $summary['errors']
                            );
                        }
                        ?>
                    </p>
                    <?php if ( ! empty( $summary['error_messages'] ) ) : ?>
                        <ul>
                            <?php foreach ( $summary['error_messages'] as $msg ) : ?>
                                <li><?php echo esc_html( $msg ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tejcart-ie">
                <div class="tejcart-ie-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Import / Export', 'tejcart' ); ?>">
                    <a
                        href="<?php echo esc_url( add_query_arg( 'ie_tab', 'import', $base_url ) ); ?>"
                        class="tejcart-ie-tab<?php echo 'import' === $active_tab ? ' is-active' : ''; ?>"
                        role="tab"
                        aria-selected="<?php echo 'import' === $active_tab ? 'true' : 'false'; ?>"
                    >
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Import', 'tejcart' ); ?>
                    </a>
                    <a
                        href="<?php echo esc_url( add_query_arg( 'ie_tab', 'export', $base_url ) ); ?>"
                        class="tejcart-ie-tab<?php echo 'export' === $active_tab ? ' is-active' : ''; ?>"
                        role="tab"
                        aria-selected="<?php echo 'export' === $active_tab ? 'true' : 'false'; ?>"
                    >
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Export', 'tejcart' ); ?>
                    </a>
                </div>

                <?php if ( 'export' === $active_tab ) : ?>
                    <?php
                    $product_count = $this->count_exportable_products();
                    $field_count   = count( self::field_definitions() );
                    ?>
                    <div class="tejcart-ie-panel" role="tabpanel">
                        <div class="tejcart-card">
                            <div class="tejcart-card-header">
                                <h3><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Export Products', 'tejcart' ); ?></h3>
                            </div>
                            <div class="tejcart-card-body">
                                <p class="tejcart-ie-lead"><?php esc_html_e( 'Download a CSV file containing every product in the canonical TejCart format. Use it as a backup, edit it in a spreadsheet, or re-import it into another TejCart installation.', 'tejcart' ); ?></p>

                                <ul class="tejcart-ie-export-stats">
                                    <li>
                                        <span class="dashicons dashicons-products"></span>
                                        <strong><?php echo esc_html( number_format_i18n( $product_count ) ); ?></strong>
                                        <span><?php echo esc_html( _n( 'product', 'products', $product_count, 'tejcart' ) ); ?></span>
                                    </li>
                                    <li>
                                        <span class="dashicons dashicons-list-view"></span>
                                        <strong><?php echo esc_html( number_format_i18n( $field_count ) ); ?></strong>
                                        <span><?php esc_html_e( 'columns', 'tejcart' ); ?></span>
                                    </li>
                                    <li>
                                        <span class="dashicons dashicons-media-spreadsheet"></span>
                                        <strong>CSV</strong>
                                        <span><?php esc_html_e( 'UTF-8, RFC 4180', 'tejcart' ); ?></span>
                                    </li>
                                </ul>

                                <p>
                                    <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary button-hero">
                                        <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'tejcart' ); ?>
                                    </a>
                                </p>

                                <p class="description"><?php esc_html_e( 'The export is generated on the fly and streamed straight to your browser — no temporary file is left on the server.', 'tejcart' ); ?></p>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="tejcart-ie-panel" role="tabpanel">
                        <div class="tejcart-card">
                            <div class="tejcart-card-header">
                                <h3><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Import Products', 'tejcart' ); ?></h3>
                            </div>
                            <div class="tejcart-card-body">
                                <p class="tejcart-ie-lead">
                                    <?php
                                    printf(
                                        /* translators: %s: maximum upload size */
                                        esc_html__( 'Upload a CSV file, map its columns to TejCart product fields, then run the import. Maximum upload size: %s. Products are matched by SKU — existing products are updated, new ones are created.', 'tejcart' ),
                                        esc_html( $max_upload )
                                    );
                                    ?>
                                </p>
                                <?php $this->render_import_form(); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Count the products the exporter will write out.
     *
     * Used by the Export tab's "at a glance" panel so the operator can
     * tell at a glance how big the dump will be before clicking. Mirrors
     * the catalog scope the exporter uses — all rows in `tejcart_products`,
     * regardless of status, since variations and drafts still round-trip.
     */
    private function count_exportable_products(): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }
        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        return is_numeric( $count ) ? (int) $count : 0;
    }

    /**
     * Compute a cache-busting version string for an asset.
     *
     * Falls back to {@see TEJCART_VERSION} when the file isn't readable from
     * disk (mirrors `tejcart_asset_url()`, which prefers the `.min` sibling
     * unless `SCRIPT_DEBUG`). Suffixing with filemtime makes browser caches
     * pick up CSS/JS edits the moment they're deployed instead of waiting
     * for the next plugin version bump.
     */
    private function asset_version( string $relative_path ): string {
        $base    = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        $plugin  = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : plugin_dir_path( __DIR__ . '/tejcart.php' );
        $debug   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

        $candidate = $relative_path;
        if ( ! $debug && preg_match( '/\.(css|js)$/', $relative_path ) ) {
            $min = preg_replace( '/\.(css|js)$/', '.min.$1', $relative_path );
            if ( $min && file_exists( $plugin . $min ) ) {
                $candidate = $min;
            }
        }

        $full = $plugin . $candidate;
        if ( is_readable( $full ) ) {
            $mtime = @filemtime( $full );
            if ( $mtime ) {
                return $base . '.' . $mtime;
            }
        }

        return $base;
    }

    /**
     * Render the file upload form with field mapping preview.
     *
     * @return void
     */
    public function render_import_form() {
        $ajax_url    = admin_url( 'admin-ajax.php' );
        $form_nonce  = wp_create_nonce( 'tejcart_import_products' );
        $form_action = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=import-export&ie_tab=import' );

        wp_enqueue_style(
            'tejcart-admin-product-import',
            tejcart_asset_url( 'assets/css/admin/product-import.css' ),
            array(),
            $this->asset_version( 'assets/css/admin/product-import.css' )
        );

        wp_enqueue_script(
            'tejcart-admin-product-import',
            tejcart_asset_url( 'assets/js/admin/product-import.js' ),
            array(),
            $this->asset_version( 'assets/js/admin/product-import.js' ),
            true
        );

        wp_localize_script(
            'tejcart-admin-product-import',
            'tejcartImportSettings',
            array(
                'ajaxUrl' => $ajax_url,
                'nonce'   => $form_nonce,
                'i18n'    => array(
                    'pass_parents'    => __( 'Pass 1/3: products', 'tejcart' ),
                    'pass_variations' => __( 'Pass 2/3: variations', 'tejcart' ),
                    'pass_references' => __( 'Pass 3/3: references', 'tejcart' ),
                    'done'            => __( 'Import complete.', 'tejcart' ),
                    'done_dry'        => __( 'Dry run complete — no changes were written.', 'tejcart' ),
                    'cancelled'       => __( 'Import cancelled.', 'tejcart' ),
                    'error_generic'   => __( 'Import failed.', 'tejcart' ),
                    /* translators: %1$d: HTTP status code returned by the server. */
                    'error_http_4xx'  => __( 'Import failed (HTTP %1$d). The server rejected the request — usually a security plugin, WAF, or upload-size rule.', 'tejcart' ),
                    /* translators: %1$d: HTTP status code returned by the server. */
                    'error_http_5xx'  => __( 'Import failed (HTTP %1$d). The server may have timed out — try again with "Skip remote image downloads" enabled.', 'tejcart' ),
                    /* translators: %1$d: HTTP status code returned by the server. */
                    'error_http'      => __( 'Import failed (HTTP %1$d).', 'tejcart' ),
                    'error_network'   => __( 'Import failed: could not reach the server. Try again with "Skip remote image downloads" enabled if the import has many products with image URLs.', 'tejcart' ),
                    /* translators: %s: raw response body returned by the server. */
                    'error_response'  => __( 'Server response: %s', 'tejcart' ),
                    /* translators: 1: created count, 2: updated count, 3: skipped count, 4: error count. */
                    'counts'          => __( 'Created: %1$d · Updated: %2$d · Skipped: %3$d · Errors: %4$d', 'tejcart' ),
                    'progress_label'  => __( 'Importing products…', 'tejcart' ),
                    'analysing'       => __( 'Reading file…', 'tejcart' ),
                    'do_not_import'   => __( '— Do not import —', 'tejcart' ),
                    'map_column'      => __( 'Map this column', 'tejcart' ),
                    'sample_value'    => __( 'Sample value', 'tejcart' ),
                    /* translators: %s: filename. */
                    'file_label'      => __( 'File: %s', 'tejcart' ),
                    /* translators: %d: row count. */
                    'rows_label'      => __( 'Detected %d data rows.', 'tejcart' ),
                    'no_file'         => __( 'Please select a CSV file to import.', 'tejcart' ),
                    'required_label'  => __( 'Required', 'tejcart' ),
                    /* translators: %s: list of unmapped required field labels. */
                    'missing_required' => __( 'Please map a CSV column to the required field(s): %s.', 'tejcart' ),
                    /* translators: %s: list of duplicated field labels. */
                    'duplicate_field' => __( 'Each TejCart field can only be mapped once. Duplicate: %s.', 'tejcart' ),
                    'back'            => __( 'Back', 'tejcart' ),
                    'run_import'      => __( 'Run import', 'tejcart' ),
                    'no_columns'      => __( 'No columns were detected. Is this a valid CSV?', 'tejcart' ),
                ),
            )
        );
        ?>
        <div id="tejcart-import-app" class="tejcart-import-app" data-form-action="<?php echo esc_url( $form_action ); ?>">

            <div class="tejcart-import-steps" role="list" aria-label="<?php esc_attr_e( 'Import steps', 'tejcart' ); ?>">
                <div class="tejcart-import-step is-active" data-step="upload" role="listitem"><span class="tejcart-import-step-num">1</span><span class="tejcart-import-step-label"><?php esc_html_e( 'Upload CSV', 'tejcart' ); ?></span></div>
                <div class="tejcart-import-step" data-step="map" role="listitem"><span class="tejcart-import-step-num">2</span><span class="tejcart-import-step-label"><?php esc_html_e( 'Map fields', 'tejcart' ); ?></span></div>
                <div class="tejcart-import-step" data-step="run" role="listitem"><span class="tejcart-import-step-num">3</span><span class="tejcart-import-step-label"><?php esc_html_e( 'Run import', 'tejcart' ); ?></span></div>
            </div>

            <div class="tejcart-import-step-panel" data-step-panel="upload">
                <form id="tejcart-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $form_action ); ?>">
                    <?php wp_nonce_field( 'tejcart_import_products' ); ?>
                    <input type="hidden" name="action" value="tejcart_import_products" />

                    <div class="tejcart-file-upload">
                        <label for="tejcart_import_file"><strong><?php esc_html_e( 'CSV File', 'tejcart' ); ?></strong></label><br />
                        <input type="file" name="tejcart_import_file" id="tejcart_import_file" accept=".csv,text/csv" required />
                    </div>

                    <p>
                        <label>
                            <input type="checkbox" name="tejcart_import_dry_run" id="tejcart_import_dry_run" value="1" />
                            <?php esc_html_e( 'Dry run — parse the file and report what would change, but do not write to the database.', 'tejcart' ); ?>
                        </label>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="tejcart_import_skip_images" id="tejcart_import_skip_images" value="1" />
                            <?php esc_html_e( 'Skip remote image downloads (recommended for large imports — sideload images later from product edit pages).', 'tejcart' ); ?>
                        </label>
                    </p>

                    <div class="tejcart-form-actions">
                        <button type="submit" class="button button-primary" id="tejcart-import-continue">
                            <?php esc_html_e( 'Continue', 'tejcart' ); ?>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                        <span class="spinner" id="tejcart-import-spinner" aria-hidden="true"></span>
                    </div>

                    <div id="tejcart-import-upload-error" class="tejcart-import-error" hidden></div>
                </form>
            </div>

            <div class="tejcart-import-step-panel" data-step-panel="map" hidden>
                <div class="tejcart-import-file-meta">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                    <span id="tejcart-import-file-name"></span>
                    <span class="tejcart-import-file-rows" id="tejcart-import-file-rows"></span>
                </div>

                <p class="description"><?php esc_html_e( 'Each row below is one CSV column. Pick the matching TejCart field for every column you want to import. Required fields are marked.', 'tejcart' ); ?></p>

                <div class="tejcart-import-map-wrap">
                    <table class="wp-list-table widefat striped tejcart-import-map-table">
                        <thead>
                            <tr>
                                <th scope="col" class="tejcart-import-map-col"><?php esc_html_e( 'CSV column', 'tejcart' ); ?></th>
                                <th scope="col" class="tejcart-import-map-sample"><?php esc_html_e( 'Sample values', 'tejcart' ); ?></th>
                                <th scope="col" class="tejcart-import-map-field"><?php esc_html_e( 'TejCart field', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="tejcart-import-map-body"></tbody>
                    </table>
                </div>

                <div id="tejcart-import-map-error" class="tejcart-import-error" hidden></div>

                <div class="tejcart-form-actions">
                    <button type="button" class="button" id="tejcart-import-back">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        <?php esc_html_e( 'Back', 'tejcart' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="tejcart-import-run">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Run import', 'tejcart' ); ?>
                    </button>
                </div>
            </div>

            <div class="tejcart-import-step-panel" data-step-panel="run" hidden>
                <div id="tejcart-import-progress" class="tejcart-import-progress">
                    <div class="tejcart-import-progress-header">
                        <strong id="tejcart-import-progress-label"><?php esc_html_e( 'Importing products…', 'tejcart' ); ?></strong>
                        <span id="tejcart-import-progress-pct">0%</span>
                    </div>
                    <div class="tejcart-import-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="tejcart-import-progress-fill" id="tejcart-import-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="tejcart-import-progress-meta">
                        <span id="tejcart-import-progress-pass"><?php esc_html_e( 'Initialising…', 'tejcart' ); ?></span>
                        <span id="tejcart-import-progress-counts"></span>
                    </div>
                    <p>
                        <button type="button" class="button-link" id="tejcart-import-cancel"><?php esc_html_e( 'Cancel', 'tejcart' ); ?></button>
                    </p>
                </div>

                <div id="tejcart-import-result" class="tejcart-import-result" hidden></div>

                <div class="tejcart-form-actions" id="tejcart-import-done-actions" hidden>
                    <button type="button" class="button" id="tejcart-import-restart"><?php esc_html_e( 'Import another file', 'tejcart' ); ?></button>
                </div>
            </div>

            <details class="tejcart-import-columns">
                <summary><?php esc_html_e( 'CSV column reference', 'tejcart' ); ?></summary>
                <p class="description"><?php esc_html_e( 'TejCart canonical fields you can map your columns to. Required fields are marked.', 'tejcart' ); ?></p>

                <table class="wp-list-table widefat striped tejcart-import-columns-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Field', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Required', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'tejcart' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( self::field_definitions() as $col => $info ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $info['label'] ); ?></strong><br />
                                    <code><?php echo esc_html( $col ); ?></code>
                                </td>
                                <td><?php echo ! empty( $info['required'] ) ? esc_html__( 'Yes', 'tejcart' ) : esc_html__( 'No', 'tejcart' ); ?></td>
                                <td><?php echo esc_html( $info['description'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
        <?php
    }

    /**
     * Per-import in-memory caches reset at the start of each import_products() run.
     *
     * Term lookups in particular are extremely hot in large imports — caching
     * the (taxonomy, name) -> term_taxonomy_id resolution avoids re-issuing the
     * same get_term_by()/wp_insert_term() pair for every row that shares a
     * category, tag, or brand.
     *
     * @var array<string, int>
     */
    private $term_cache = array();

    /**
     * Whether the current import should skip remote image sideload.
     *
     * Set per-run via the $options arg to import_products(). Sideloading 20K+
     * remote images in one synchronous request is the fastest way to time the
     * importer out, so the CLI and the "skip images" checkbox both flip this.
     */
    private bool $skip_images = false;

    /**
     * Parallel-sideload concurrency for the current import.
     *
     * 0 means "fall back to the legacy per-row inline sideload" (preserves
     * the pre-1.1 behaviour exactly for callers that don't opt in). >=1
     * activates {@see Image_Sideloader} and routes image URLs through
     * Requests::request_multiple() so 15K-row catalogs finish in minutes
     * rather than hours.
     *
     * Set per-run via the $options arg to import_products() (CLI:
     * `--image-concurrency`). Defaults to {@see Image_Sideloader::default_concurrency()}
     * when --with-images is on without an explicit override.
     */
    private int $image_concurrency = 0;

    /**
     * When true, image sideload is deferred to Action Scheduler instead of
     * happening inline. Each batch is enqueued under the
     * `tejcart_import_image_sideload` hook with the same job payload the
     * inline flush would use; the listener (see {@see \TejCart\Core\Action_Scheduler::task_import_image_sideload()})
     * processes a fresh wave per job tick.
     *
     * This is the recommended mode for very large catalogs: the import
     * finishes in seconds (products written, images queued) and the
     * scheduler grinds through downloads in the background, surviving
     * PHP-FPM crashes / deploy restarts via AS's persistent queue.
     */
    private bool $image_defer = false;

    /**
     * Cross-pass URL → attachment-id cache. Populated lazily by
     * {@see flush_image_buffer()} so a CSV that repeats the same image URL
     * across multiple rows (think variant grid + parent product) only ever
     * hits the network once per import.
     *
     * @var array<string, int>
     */
    private array $image_attachment_cache = array();

    /**
     * Pending image-sideload jobs queued during a chunk/batch, flushed at
     * the end of the batch (or to Action Scheduler in deferred mode). Each
     * entry is shaped:
     *
     *     [
     *       'url'          => string,
     *       'product_id'   => int,
     *       'product_name' => string,
     *       'role'         => 'main' | 'gallery',
     *       'key'          => string,  // unique within the buffer
     *     ]
     *
     * Held in memory between calls to {@see import_product_row()} only —
     * never crosses transaction boundaries (the buffer is flushed and
     * reset before each COMMIT so a DB rollback can't strand floating
     * attachment rows).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $image_buffer = array();

    /**
     * Cached Image_Sideloader instance for the current import. Reused
     * across batches so the prefetch cache + (eventually) any per-instance
     * stats accumulator survive the chunk-loop boundary.
     */
    private ?Import\Image_Sideloader $image_sideloader = null;

    /**
     * Whether the current import file uses an alternate header convention
     * the bridge in {@see External_CSV_Compat} recognises.
     *
     * Set by {@see read_csv_headers()}; consumed by the row dispatchers in
     * {@see stream_csv_rows()} and {@see process_import_chunk()} so a parsed
     * row can be normalised through {@see External_CSV_Compat::translate_row()}
     * before the existing TejCart row handlers see it.
     */
    private bool $alt_format_mode = false;

    /**
     * Parse and import products from an uploaded CSV file.
     *
     * Streams the file three times (parents, variations, SKU references) rather
     * than buffering every row in memory, so memory stays bounded regardless of
     * catalog size. Each batch within a pass is wrapped in a transaction so a
     * mid-import failure can't leave the products table half-written.
     *
     * @param array|string $file    Either a $_FILES entry (admin upload path)
     *                              or an absolute path to a CSV file (CLI path).
     * @param bool         $dry_run When true, wrap all writes in a transaction
     *                              that is rolled back at the end.
     * @param array        $options {
     *     Optional. Per-run tuning.
     *
     *     @type int  $batch_size        Rows per transaction. Default filterable
     *                                   via `tejcart_import_batch_size` (200).
     *     @type bool $skip_images       When true, skip remote image sideload. Useful
     *                                   for very large imports that would otherwise
     *                                   block on 20K+ HTTP fetches.
     *     @type int  $image_concurrency Parallel HTTP fetches when sideloading. 0 to
     *                                   disable parallelism (legacy per-row sync sideload),
     *                                   >=1 to enable {@see Import\Image_Sideloader}.
     *                                   Defaults to Image_Sideloader::default_concurrency()
     *                                   when images are enabled.
     *     @type bool $image_defer       When true, queue image fetches to Action
     *                                   Scheduler instead of fetching inline. Returns
     *                                   the import quickly; images stream in via
     *                                   `tejcart_import_image_sideload` jobs.
     * }
     * @return array Import summary with keys: created, updated, skipped, errors, error_messages.
     */
    public function import_products( $file, bool $dry_run = false, array $options = array() ) {
        global $wpdb;

        $summary = array(
            'created'        => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'errors'         => 0,
            'error_messages' => array(),
        );

        $path     = '';
        $filename = '';
        if ( is_array( $file ) ) {
            $path     = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
            $filename = isset( $file['name'] ) ? (string) $file['name'] : '';
        } elseif ( is_string( $file ) ) {
            $path     = $file;
            $filename = basename( $file );
        }

        if ( '' === $path || ! is_readable( $path ) ) {
            $summary['errors']++;
            $summary['error_messages'][] = __( 'Unable to open the uploaded file.', 'tejcart' );
            return $summary;
        }

        $this->raise_resource_limits();

        $batch_size = isset( $options['batch_size'] ) ? max( 1, (int) $options['batch_size'] ) : 0;
        if ( 0 === $batch_size ) {
            /**
             * Filter the per-batch transaction size for product imports.
             *
             * Rows are streamed from the CSV and committed in chunks of this
             * size. Smaller batches give more frequent progress points (and
             * lighter rollbacks on failure); larger batches trade that for
             * fewer COMMITs.
             *
             * @param int $batch_size Default 200.
             */
            $batch_size = (int) apply_filters( 'tejcart_import_batch_size', 200 );
            if ( $batch_size < 1 ) {
                $batch_size = 200;
            }
        }

        $this->skip_images            = ! empty( $options['skip_images'] );
        $this->image_defer            = ! empty( $options['image_defer'] );
        $this->image_concurrency      = $this->resolve_image_concurrency( $options );
        $this->image_buffer           = array();
        $this->image_attachment_cache = array();
        $this->image_sideloader       = null;
        $this->term_cache             = array();

        if ( function_exists( 'wp_suspend_cache_addition' ) ) {
            wp_suspend_cache_addition( true );
        }

        $transaction_started = false;
        if ( $dry_run ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'SET autocommit = 0' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'START TRANSACTION' );
            $transaction_started = true;
        }

        $finish_transaction = function () use ( &$transaction_started ) {
            global $wpdb;
            if ( $transaction_started ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'ROLLBACK' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'SET autocommit = 1' );
                $transaction_started = false;
            }
        };

        $cleanup = function () use ( $finish_transaction ) {
            $finish_transaction();
            $this->term_cache = array();
            if ( function_exists( 'wp_suspend_cache_addition' ) ) {
                wp_suspend_cache_addition( false );
            }
        };

        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $repair_error = '';
        if ( ! $table_exists ) {
            $repair_error = \TejCart\Core\Installer::ensure_tables();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        }
        if ( ! $table_exists ) {
            $summary['errors']++;
            $base_message = __(
                'The TejCart products table is missing and could not be created automatically. Please deactivate and reactivate the TejCart plugin, then try the import again. If the problem persists, contact your host — the database user may lack CREATE TABLE permission.',
                'tejcart'
            );
            if ( '' !== $repair_error ) {
                $summary['error_messages'][] = sprintf(
                    /* translators: 1: explanatory message, 2: raw MySQL error */
                    __( '%1$s (Database error: %2$s)', 'tejcart' ),
                    $base_message,
                    $repair_error
                );
            } else {
                $summary['error_messages'][] = $base_message;
            }
            $cleanup();
            return $summary;
        }

        if ( '' !== $filename ) {
            $filetype = wp_check_filetype( $filename, array( 'csv' => 'text/csv' ) );
            if ( empty( $filetype['ext'] ) ) {
                $summary['errors']++;
                $summary['error_messages'][] = __( 'Invalid file type. Please upload a CSV file.', 'tejcart' );
                $cleanup();
                return $summary;
            }
        }

        $headers = $this->read_csv_headers( $path, $summary );
        if ( null === $headers ) {
            $cleanup();
            return $summary;
        }

        $sku_to_id = array();

        $progress_callback = isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] )
            ? $options['progress_callback']
            : null;

        $total_rows = null;
        if ( $progress_callback ) {
            // Cheap streaming row count for the progress estimator. fgetcsv()
            // handles quoted multi-line cells correctly so this is accurate
            // even when the body contains embedded newlines.
            $total_rows = $this->count_csv_rows( $path );
            $progress_callback(
                array(
                    'event'      => 'start',
                    'total_rows' => $total_rows,
                )
            );
        }

        foreach ( array( 'parents', 'variations', 'references' ) as $pass ) {
            if ( $progress_callback ) {
                $progress_callback(
                    array(
                        'event'      => 'pass_start',
                        'pass'       => $pass,
                        'total_rows' => $total_rows,
                    )
                );
            }
            $this->stream_csv_rows( $path, $headers, $summary, $batch_size, $pass, $sku_to_id, $dry_run, $progress_callback );
        }

        if ( $progress_callback ) {
            $progress_callback(
                array(
                    'event'   => 'done',
                    'summary' => $summary,
                )
            );
        }

        $cleanup();

        return $summary;
    }

    /**
     * Stream the CSV once to count data rows (header excluded). Used by
     * callers that want an accurate denominator for a progress bar. About
     * 0.5s for a 15MB file — negligible vs. the wall-clock cost of the
     * actual import.
     */
    private function count_csv_rows( string $path ): int {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return 0;
        }
        // Skip header.
        fgetcsv( $handle, 0, ',', '"', '' );
        $count = 0;
        while ( false !== fgetcsv( $handle, 0, ',', '"', '' ) ) {
            $count++;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );
        return $count;
    }

    /**
     * Open the CSV, validate the header row, and return the normalized headers.
     *
     * Returns null on validation failure (after appending a message to $summary).
     *
     * @param string $path     Filesystem path to the CSV.
     * @param array  $summary  Counters (mutated on failure).
     * @return string[]|null   Lowercased, trimmed header row, or null.
     */
    private function read_csv_headers( string $path, array &$summary ): ?array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            $summary['errors']++;
            $summary['error_messages'][] = __( 'Unable to open the uploaded file.', 'tejcart' );
            return null;
        }

        $headers = fgetcsv( $handle, 0, ',', '"', '' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        if ( ! $headers ) {
            $summary['errors']++;
            $summary['error_messages'][] = __( 'The CSV file is empty or has no header row.', 'tejcart' );
            return null;
        }

        if ( isset( $headers[0] ) && is_string( $headers[0] ) && 0 === strncmp( $headers[0], "\xEF\xBB\xBF", 3 ) ) {
            $headers[0] = substr( $headers[0], 3 );
        }

        $headers = array_map( 'strtolower', array_map( 'trim', $headers ) );

        // Accept foreign-formatted product CSVs by translating their headers
        // into TejCart's canonical column names up front. Per-row value tweaks
        // (image splitting, attribute aggregation, type tokenisation, etc.)
        // are applied by External_CSV_Compat::translate_row() in
        // stream_csv_rows() / process_import_chunk().
        $this->alt_format_mode = External_CSV_Compat::is_external_csv( $headers );
        if ( $this->alt_format_mode ) {
            $headers = External_CSV_Compat::canonicalize_headers( $headers );
        }

        if ( ! in_array( 'name', $headers, true ) ) {
            $summary['errors']++;
            $summary['error_messages'][] = __( 'The CSV file must contain a "name" column.', 'tejcart' );
            return null;
        }
        if ( ! in_array( 'price', $headers, true ) ) {
            $summary['errors']++;
            $summary['error_messages'][] = __( 'The CSV file must contain a "price" column.', 'tejcart' );
            return null;
        }

        return $headers;
    }

    /**
     * Stream-process the CSV body for a given pass.
     *
     * Pass-aware row dispatch:
     *  - 'parents'    — every non-variation row is created/updated; variations skipped here.
     *  - 'variations' — only variation rows; parent SKUs resolved against $sku_to_id (with
     *                   a one-shot DB fallback).
     *  - 'references' — third pass for cross-sells, upsells, bundled items, grouped children.
     *
     * Each batch is wrapped in its own transaction (when not already inside the dry-run
     * outer transaction) so a row-level failure inside `wpdb` only rolls that batch.
     *
     * @param string   $path       CSV file path.
     * @param string[] $headers    Lowercased header row from read_csv_headers().
     * @param array    $summary    Counters (mutated).
     * @param int      $batch_size Rows per transaction.
     * @param string   $pass       'parents' | 'variations' | 'references'.
     * @param array    $sku_to_id  SKU map (mutated in pass 'parents'; read in others).
     * @param bool     $dry_run    Whether the outer transaction is already active.
     */
    private function stream_csv_rows(
        string $path,
        array $headers,
        array &$summary,
        int $batch_size,
        string $pass,
        array &$sku_to_id,
        bool $dry_run,
        ?callable $progress_callback = null
    ): void {
        global $wpdb;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return;
        }

        // Skip the header row.
        fgetcsv( $handle, 0, ',', '"', '' );

        $row_number = 1;
        $in_batch   = 0;
        $use_batch_tx = ! $dry_run;

        if ( $use_batch_tx ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'START TRANSACTION' );
        }

        while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            $row_number++;

            if ( null === $row || empty( array_filter( $row, static fn( $cell ) => '' !== (string) $cell ) ) ) {
                if ( 'parents' === $pass ) {
                    $summary['skipped']++;
                }
                continue;
            }

            if ( count( $row ) !== count( $headers ) ) {
                if ( 'parents' === $pass ) {
                    $summary['errors']++;
                    $summary['error_messages'][] = sprintf(
                        /* translators: %d: row number */
                        __( 'Row %d: column count mismatch. Skipped.', 'tejcart' ),
                        $row_number
                    );
                }
                continue;
            }

            $data = array_combine( $headers, $row );

            if ( $this->alt_format_mode ) {
                $data = External_CSV_Compat::translate_row( $data );
            }

            /**
             * Filter a parsed CSV row before TejCart processes it.
             *
             * Return an array to proceed with the (possibly modified) data,
             * or return false/null to skip the row.
             *
             * @since 1.0.0
             *
             * @param array $data       Header-keyed row data.
             * @param int   $row_number 1-based line number.
             */
            $data = apply_filters( 'tejcart_csv_import_row', $data, $row_number );
            if ( empty( $data ) || ! is_array( $data ) ) {
                if ( 'parents' === $pass ) {
                    $summary['skipped']++;
                }
                continue;
            }

            $row_type = strtolower( (string) ( $data['type'] ?? '' ) );

            switch ( $pass ) {
                case 'parents':
                    if ( 'variation' === $row_type ) {
                        break;
                    }
                    $this->import_product_row( $data, $row_number, $summary, $sku_to_id );
                    break;

                case 'variations':
                    if ( 'variation' !== $row_type ) {
                        break;
                    }
                    $this->import_variation_row( $data, $row_number, $summary, $sku_to_id );
                    break;

                case 'references':
                    $sku = isset( $data['sku'] ) ? trim( (string) $data['sku'] ) : '';
                    if ( '' === $sku || ! isset( $sku_to_id[ $sku ] ) ) {
                        break;
                    }
                    $this->apply_sku_references( (int) $sku_to_id[ $sku ], $data, $sku_to_id );
                    break;
            }

            $in_batch++;
            if ( $use_batch_tx && $in_batch >= $batch_size ) {
                // Flush image jobs for this batch BEFORE we COMMIT so the
                // attachment rows + product image_id update land in the
                // same transaction. Deferred mode (queue-to-AS) is a no-op
                // here — the AS job will run its own commit later.
                $this->flush_image_buffer( $summary );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'COMMIT' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'START TRANSACTION' );
                $in_batch = 0;

                if ( $progress_callback ) {
                    $progress_callback(
                        array(
                            'event'    => 'batch',
                            'pass'     => $pass,
                            'rows'     => $batch_size,
                            'row_high' => $row_number,
                        )
                    );
                }
            }
        }

        // Drain any tail jobs from the final partial batch.
        $this->flush_image_buffer( $summary );

        if ( $use_batch_tx ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'COMMIT' );
        }

        if ( $progress_callback && $in_batch > 0 ) {
            $progress_callback(
                array(
                    'event'    => 'batch',
                    'pass'     => $pass,
                    'rows'     => $in_batch,
                    'row_high' => $row_number,
                )
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );
    }

    /**
     * Resolve the per-chunk batch size for an import job.
     *
     * Sideloading remote images dominates per-row time and a single
     * unreachable URL can stall the whole chunk through download_url()'s
     * timeout. When images are being fetched the batch is capped tightly
     * (default 5) so each chunk reliably finishes inside the host's PHP /
     * proxy timeout — otherwise the chunk request returns a 502/504 and
     * the JS poller surfaces a generic "Import failed." with no detail.
     *
     * @param bool $skip_images Whether the job will skip remote images.
     * @return int Batch size, always >= 1.
     */
    private function resolve_chunk_batch_size( bool $skip_images ): int {
        $batch_size = $skip_images
            ? (int) apply_filters( 'tejcart_import_batch_size', 200 )
            : (int) apply_filters( 'tejcart_import_image_batch_size', 5 );
        return max( 1, $batch_size );
    }

    /**
     * Resolve the parallel-fetch concurrency for the current import.
     *
     * Returns 0 when images are skipped or the caller explicitly passes
     * `image_concurrency => 0` — that disables the new {@see Import\Image_Sideloader}
     * path and falls back to the legacy per-row inline sideload, preserving
     * the pre-1.1 behaviour exactly for callers that don't opt in.
     *
     * @param array<string, mixed> $options
     */
    private function resolve_image_concurrency( array $options ): int {
        if ( ! empty( $options['skip_images'] ) ) {
            return 0;
        }
        if ( array_key_exists( 'image_concurrency', $options ) ) {
            $explicit = (int) $options['image_concurrency'];
            return max( 0, min( 64, $explicit ) );
        }
        return Import\Image_Sideloader::default_concurrency();
    }

    /**
     * Lazily build (and cache) the per-import Image_Sideloader. Reusing one
     * instance across batches lets us share its prefetched URL map without
     * having to thread it through every call site.
     */
    private function get_image_sideloader(): Import\Image_Sideloader {
        if ( null === $this->image_sideloader ) {
            $this->image_sideloader = new Import\Image_Sideloader();
        }
        return $this->image_sideloader;
    }

    /**
     * Queue an image URL for sideload at the end of the current chunk.
     *
     * The buffer is flushed by {@see flush_image_buffer()} once per batch
     * (or once per pass, depending on the caller). When the caller has
     * opted into `image_defer`, flush_image_buffer() routes the queued
     * jobs through Action Scheduler instead of fetching them inline.
     *
     * @param int    $product_id   Owning product.
     * @param string $url          Remote URL.
     * @param string $product_name Product name (for alt text + attachment title).
     * @param string $role         'main' or 'gallery' — used by the flush step
     *                             to update `image_id` vs `gallery_ids`.
     */
    private function buffer_image_job( int $product_id, string $url, string $product_name, string $role ): void {
        $url = trim( $url );
        if ( '' === $url || $product_id <= 0 ) {
            return;
        }

        // Stable, collision-proof key. URL alone can collide across rows
        // (a parent main image is the same URL as the gallery on a sibling
        // product); pair it with product_id + role + a monotonic suffix.
        $key = $product_id . ':' . $role . ':' . count( $this->image_buffer ) . ':' . md5( $url );

        $this->image_buffer[] = array(
            'key'          => $key,
            'url'          => $url,
            'product_id'   => $product_id,
            'product_name' => $product_name,
            'role'         => $role,
        );
    }

    /**
     * Drain the image buffer.
     *
     * Inline mode: run the buffered URLs through {@see Import\Image_Sideloader::sideload_batch()}
     * and apply the resulting attachment ids (main → `image_id`; gallery →
     * `gallery_ids` JSON column) on the products table.
     *
     * Deferred mode: chunk the buffer into Action-Scheduler-sized jobs and
     * enqueue each chunk under the `tejcart_import_image_sideload` hook.
     * The listener (in `\TejCart\Core\Action_Scheduler`) re-uses the same
     * sideloader on each tick, so the wire format is identical.
     *
     * Failures are appended to `$summary['error_messages']` but never
     * abort the import — a single broken URL never costs us the rest of
     * the catalog.
     *
     * @param array<string, mixed> $summary Counters (mutated).
     */
    private function flush_image_buffer( array &$summary ): void {
        if ( empty( $this->image_buffer ) ) {
            return;
        }
        $buffer             = $this->image_buffer;
        $this->image_buffer = array();

        if ( $this->image_defer ) {
            $this->enqueue_deferred_image_jobs( $buffer );
            return;
        }

        $this->apply_image_results(
            $this->get_image_sideloader()->sideload_batch(
                $buffer,
                array(
                    'concurrency' => $this->image_concurrency,
                    'prefetched'  => $this->image_attachment_cache,
                )
            ),
            $buffer,
            $summary
        );
    }

    /**
     * Apply a batch of sideload results to the products table.
     *
     * `gallery_ids` is a JSON-encoded array on `tejcart_products`. To avoid
     * losing siblings when multiple gallery URLs arrive across waves, we
     * accumulate per-product before issuing one UPDATE per product per
     * column.
     *
     * @param array<string, array<string, mixed>>  $results Output of sideload_batch().
     * @param array<int, array<string, mixed>>     $buffer  The jobs that produced $results.
     * @param array<string, mixed>                 $summary Counters (mutated).
     */
    private function apply_image_results( array $results, array $buffer, array &$summary ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $main_per_product    = array(); // product_id => attachment_id
        $gallery_per_product = array(); // product_id => int[]

        foreach ( $buffer as $job ) {
            $key    = (string) $job['key'];
            $pid    = (int) $job['product_id'];
            $role   = (string) $job['role'];
            $url    = (string) $job['url'];
            $result = $results[ $key ] ?? null;

            if ( ! is_array( $result ) ) {
                continue;
            }

            if ( isset( $result['error'] ) && is_wp_error( $result['error'] ) ) {
                $summary['error_messages'][] = sprintf(
                    /* translators: 1: product id, 2: error message */
                    __( 'Product %1$d: image import failed. %2$s', 'tejcart' ),
                    $pid,
                    $result['error']->get_error_message()
                );
                continue;
            }

            $attachment_id = (int) ( $result['attachment_id'] ?? 0 );
            if ( $attachment_id <= 0 ) {
                continue;
            }

            // Memoize so the same URL on a later batch / pass skips the network.
            if ( '' !== $url ) {
                $this->image_attachment_cache[ $url ] = $attachment_id;
            }

            if ( 'main' === $role ) {
                // Last-write-wins for `main` (a CSV row shouldn't list multiple
                // primaries, but if it does the buffer order is the input order).
                $main_per_product[ $pid ] = $attachment_id;
            } else {
                $gallery_per_product[ $pid ][] = $attachment_id;
            }
        }

        foreach ( $main_per_product as $pid => $att_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array( 'image_id' => $att_id ),
                array( 'id' => $pid ),
                array( '%d' ),
                array( '%d' )
            );
        }

        foreach ( $gallery_per_product as $pid => $att_ids ) {
            $att_ids = array_values( array_unique( array_filter( array_map( 'intval', $att_ids ) ) ) );
            if ( empty( $att_ids ) ) {
                continue;
            }
            // Merge with any gallery already on the row (e.g. a previous batch
            // sideloaded part of the same product's gallery).
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing_raw = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT gallery_ids FROM {$table} WHERE id = %d LIMIT 1",
                    $pid
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing = array();
            if ( is_string( $existing_raw ) && '' !== $existing_raw ) {
                $decoded = json_decode( $existing_raw, true );
                if ( is_array( $decoded ) ) {
                    $existing = array_values( array_filter( array_map( 'intval', $decoded ) ) );
                }
            }
            $merged = array_values( array_unique( array_merge( $existing, $att_ids ) ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array( 'gallery_ids' => wp_json_encode( $merged ) ),
                array( 'id' => $pid ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }

    /**
     * Process a wave of deferred image-sideload jobs.
     *
     * This is the public entry point invoked by Action_Scheduler when
     * dequeuing a `tejcart_import_image_sideload` job. The wave was
     * pre-sized to the original import's image-concurrency, so one tick
     * == one curl_multi fetch round.
     *
     * Idempotent on retry: the sideloader's prefetch step dedups against
     * `_tejcart_source_url`, so a re-tick of the same job is a no-op
     * beyond the dedup SELECT.
     *
     * @param array<int, array<string, mixed>> $jobs        Job buffer (see {@see buffer_image_job()}).
     * @param int                              $concurrency Per-wave parallel-fetch limit.
     * @return array{errors:int, error_messages:string[]} Summary of failures.
     */
    public function process_deferred_image_jobs( array $jobs, int $concurrency ): array {
        $summary = array(
            'created'        => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'errors'         => 0,
            'error_messages' => array(),
        );

        if ( empty( $jobs ) ) {
            return array( 'errors' => 0, 'error_messages' => array() );
        }

        $results = $this->get_image_sideloader()->sideload_batch(
            $jobs,
            array( 'concurrency' => max( 1, $concurrency ) )
        );

        $this->apply_image_results( $results, $jobs, $summary );

        return array(
            'errors'         => count( $summary['error_messages'] ),
            'error_messages' => $summary['error_messages'],
        );
    }

    /**
     * Chunk a buffer into Action-Scheduler-sized jobs and enqueue them
     * under the `tejcart_import_image_sideload` hook.
     *
     * The chunk size matches the per-call concurrency so each AS tick does
     * exactly one `Requests::request_multiple()` wave — no point in queuing
     * 500 URLs in one job and then processing them in 60 internal waves;
     * better to let AS spread the load across ticks and let an admin cancel
     * mid-stream.
     *
     * @param array<int, array<string, mixed>> $buffer
     */
    private function enqueue_deferred_image_jobs( array $buffer ): void {
        if ( empty( $buffer ) ) {
            return;
        }

        $chunk_size = max( 1, $this->image_concurrency );
        $scheduler  = \TejCart\Core\Action_Scheduler::instance();

        $when = time();
        foreach ( array_chunk( $buffer, $chunk_size ) as $chunk ) {
            $scheduler->schedule_single(
                $when,
                'tejcart_import_image_sideload',
                array(
                    'jobs'        => $chunk,
                    'concurrency' => $this->image_concurrency,
                )
            );
            // Stagger so AS doesn't try to run them all on a single tick.
            $when += 5;
        }
    }

    /**
     * Best-effort: lift the request's PHP time and memory budget so a 20K-row
     * CSV doesn't get killed by the default `max_execution_time`. No-ops when
     * either tweak is locked down (e.g. by safe-mode or container limits).
     */
    private function raise_resource_limits(): void {
        if ( function_exists( 'set_time_limit' ) ) {
            // CSV imports legitimately need to extend the time limit.
            // Replace @ with an explicit failure log (SEC-022) so a host
            // with set_time_limit disabled surfaces a debug-level
            // trace rather than silently swallowing the call.
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            $lifted = set_time_limit( 0 );
            if ( ! $lifted && function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Product import: set_time_limit(0) rejected; import may be killed mid-run.', 'debug' );
            }
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
    }

    /**
     * Process a single non-variation CSV row.
     *
     * Extracted from import_products() so the two-pass loop can call it
     * for parents and the variation pass can defer by type.
     *
     * @param array $data       Header-keyed row data.
     * @param int   $row_number 1-based line number.
     * @param array $summary    Summary counters (mutated in place).
     * @param array $sku_to_id  SKU → product id map (mutated on success).
     */
    private function import_product_row( array $data, int $row_number, array &$summary, array &$sku_to_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        if ( empty( trim( (string) ( $data['name'] ?? '' ) ) ) ) {
            $summary['errors']++;
            $summary['error_messages'][] = sprintf(
                /* translators: %d: row number */
                __( 'Row %d: missing product name. Skipped.', 'tejcart' ),
                $row_number
            );
            return;
        }

        $row_type = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : 'physical';

        $derived_price_types = array( 'grouped', 'variable', 'bundle' );

        $price = isset( $data['price'] ) ? trim( (string) $data['price'] ) : '';
        if ( '' === $price ) {
            if ( ! in_array( $row_type, $derived_price_types, true ) ) {
                $summary['errors']++;
                $summary['error_messages'][] = sprintf(
                    /* translators: %d: row number */
                    __( 'Row %d: invalid or missing price. Skipped.', 'tejcart' ),
                    $row_number
                );
                return;
            }
            $price = '0';
        } elseif ( ! is_numeric( $price ) ) {
            $summary['errors']++;
            $summary['error_messages'][] = sprintf(
                /* translators: %d: row number */
                __( 'Row %d: invalid or missing price. Skipped.', 'tejcart' ),
                $row_number
            );
            return;
        }

        $data['price'] = $price;

            $dimensions = array(
                'length' => isset( $data['length'] ) ? sanitize_text_field( (string) $data['length'] ) : '',
                'width'  => isset( $data['width'] )  ? sanitize_text_field( (string) $data['width'] )  : '',
                'height' => isset( $data['height'] ) ? sanitize_text_field( (string) $data['height'] ) : '',
            );

            // CSV-injection prefix neutralisation on the user-presentable
            // string fields (L-3). The export path runs every cell through
            // tejcart_csv_sanitize_row, but we cannot rely on every
            // downstream emitter (third-party REST clients, manual SQL
            // exports, sibling reporters) to do the same — store the safe
            // form so the database is the canonical defended copy.
            $csv_safe = static fn( $v ) => function_exists( 'tejcart_csv_sanitize_cell' )
                ? tejcart_csv_sanitize_cell( (string) $v )
                : (string) $v;

            $product_data = array(
                'name'                  => sanitize_text_field( $csv_safe( $data['name'] ) ),
                'slug'                  => ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $data['name'] ),
                'type'                  => ! empty( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'physical',
                'status'                => ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'publish',
                'description'           => isset( $data['description'] ) ? wp_kses_post( $csv_safe( $data['description'] ) ) : '',
                'short_description'     => isset( $data['short_description'] ) ? wp_kses_post( $csv_safe( $data['short_description'] ) ) : '',
                'sku'                   => isset( $data['sku'] ) ? sanitize_text_field( $csv_safe( $data['sku'] ) ) : null,
                'price'                 => (float) $data['price'],
                'sale_price'            => isset( $data['sale_price'] ) && '' !== $data['sale_price'] ? (float) $data['sale_price'] : null,
                'stock_quantity'        => isset( $data['stock_quantity'] ) && '' !== $data['stock_quantity'] ? (int) $data['stock_quantity'] : null,
                'stock_status'          => ! empty( $data['stock_status'] ) ? sanitize_text_field( $data['stock_status'] ) : 'instock',
                'manage_stock'          => isset( $data['manage_stock'] ) ? (int) (bool) $data['manage_stock'] : 0,
                'backorders'            => isset( $data['backorders'] ) && in_array( $data['backorders'], array( 'no', 'notify', 'yes' ), true ) ? $data['backorders'] : 'no',
                'sold_individually'     => ! empty( $data['sold_individually'] ) && in_array( strtolower( (string) $data['sold_individually'] ), array( '1', 'yes', 'true' ), true ) ? 1 : 0,
                'min_purchase_quantity' => isset( $data['min_purchase_quantity'] ) && '' !== $data['min_purchase_quantity'] ? max( 1, (int) $data['min_purchase_quantity'] ) : 1,
                'max_purchase_quantity' => isset( $data['max_purchase_quantity'] ) && '' !== $data['max_purchase_quantity'] ? max( 0, (int) $data['max_purchase_quantity'] ) : 0,
                'weight'                => isset( $data['weight'] ) && '' !== $data['weight'] ? (float) $data['weight'] : null,
                'dimensions'            => wp_json_encode( $dimensions ),
                'tax_class'             => isset( $data['tax_class'] ) ? sanitize_text_field( (string) $data['tax_class'] ) : '',
                'shipping_class'        => isset( $data['shipping_class'] ) ? sanitize_key( (string) $data['shipping_class'] ) : '',
                'catalog_visibility'    => isset( $data['catalog_visibility'] ) && in_array( $data['catalog_visibility'], array( 'visible', 'catalog', 'search', 'hidden' ), true ) ? $data['catalog_visibility'] : 'visible',
                'featured'              => ! empty( $data['featured'] ) && in_array( strtolower( (string) $data['featured'] ), array( '1', 'yes', 'true' ), true ) ? 1 : 0,
            );

            $valid_statuses = array( 'publish', 'draft', 'pending' );
            if ( ! in_array( $product_data['status'], $valid_statuses, true ) ) {
                $product_data['status'] = 'publish';
            }

            $valid_stock = array( 'instock', 'outofstock', 'onbackorder' );
            if ( ! in_array( $product_data['stock_status'], $valid_stock, true ) ) {
                $product_data['stock_status'] = 'instock';
            }

            $existing_id = null;
            if ( ! empty( $product_data['sku'] ) ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $existing_id = $wpdb->get_var(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE sku = %s LIMIT 1",
                        $product_data['sku']
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            }

            $format = array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%f',
                '%f',
                '%d',
                '%s',
                '%d',
                '%s',
                '%d',
                '%d',
                '%d',
                '%f',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
            );

            if ( $existing_id ) {
                if ( empty( $data['slug'] ) ) {
                    unset( $product_data['slug'] );
                    $format_for_update = $format;
                    array_splice( $format_for_update, 1, 1 );
                } else {
                    $product_data['slug'] = \TejCart\Product\Product_Factory::generate_unique_slug(
                        $product_data['slug'],
                        (int) $existing_id
                    );
                    $format_for_update    = $format;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->update(
                    $table,
                    $product_data,
                    array( 'id' => (int) $existing_id ),
                    $format_for_update,
                    array( '%d' )
                );

                if ( false === $result ) {
                    $summary['errors']++;
                    $summary['error_messages'][] = sprintf(
                        /* translators: 1: row number, 2: database error summary */
                        __( 'Row %1$d: database error updating product. %2$s', 'tejcart' ),
                        $row_number,
                        $this->summarize_db_error( (string) $wpdb->last_error, $row_number, 'update' )
                    );
                } else {
                    $summary['updated']++;
                    $product_id = (int) $existing_id;
                }
            } else {
                $product_data['slug'] = \TejCart\Product\Product_Factory::generate_unique_slug( $product_data['slug'] );

                if ( ! empty( $product_data['sku'] ) ) {
                    $conflict_id = \TejCart\Product\Product_Factory::sku_exists( (string) $product_data['sku'], 0 );
                    if ( $conflict_id > 0 ) {
                        $summary['errors']++;
                        $summary['error_messages'][] = sprintf(
                            /* translators: 1: row number, 2: SKU, 3: existing product ID */
                            __( 'Row %1$d: SKU %2$s already exists on product #%3$d.', 'tejcart' ),
                            $row_number,
                            (string) $product_data['sku'],
                            $conflict_id
                        );
                        return;
                    }
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->insert( $table, $product_data, $format );

                if ( false === $result ) {
                    $summary['errors']++;
                    $summary['error_messages'][] = sprintf(
                        /* translators: 1: row number, 2: database error summary */
                        __( 'Row %1$d: database error creating product. %2$s', 'tejcart' ),
                        $row_number,
                        $this->summarize_db_error( (string) $wpdb->last_error, $row_number, 'insert' )
                    );
                } else {
                    $summary['created']++;
                    $product_id = (int) $wpdb->insert_id;
                }
            }

            if ( isset( $product_id ) && $product_id > 0 ) {
                if ( ! empty( $data['categories'] ) ) {
                    $this->assign_terms( $product_id, $data['categories'], \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY );
                }
                if ( ! empty( $data['tags'] ) ) {
                    $this->assign_terms( $product_id, $data['tags'], \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY );
                }
                if ( ! empty( $data['brands'] ) ) {
                    $this->assign_terms( $product_id, $data['brands'], \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY );
                }
                if ( ! empty( $data['image_url'] ) && ! $this->skip_images ) {
                    if ( $this->image_concurrency > 0 ) {
                        // Buffer for parallel flush at end of batch.
                        $this->buffer_image_job(
                            (int) $product_id,
                            trim( (string) $data['image_url'] ),
                            (string) $product_data['name'],
                            'main'
                        );
                    } else {
                        $attachment_id = $this->sideload_product_image( trim( $data['image_url'] ), $product_id, $product_data['name'] );
                        if ( is_wp_error( $attachment_id ) ) {
                            $summary['error_messages'][] = sprintf(
                                /* translators: 1: row number, 2: error message */
                                __( 'Row %1$d: image import failed. %2$s', 'tejcart' ),
                                $row_number,
                                $attachment_id->get_error_message()
                            );
                        } elseif ( $attachment_id > 0 ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $wpdb->update(
                                $table,
                                array( 'image_id' => $attachment_id ),
                                array( 'id' => $product_id ),
                                array( '%d' ),
                                array( '%d' )
                            );
                        }
                    }
                }

                if ( ! empty( $data['gallery_image_urls'] ) && ! $this->skip_images ) {
                    $urls = array_filter( array_map( 'trim', explode( '|', (string) $data['gallery_image_urls'] ) ) );
                    if ( $this->image_concurrency > 0 ) {
                        foreach ( $urls as $g_url ) {
                            $this->buffer_image_job(
                                (int) $product_id,
                                (string) $g_url,
                                (string) $product_data['name'],
                                'gallery'
                            );
                        }
                    } else {
                        $gids = array();
                        foreach ( $urls as $g_url ) {
                            $gid = $this->sideload_product_image( $g_url, $product_id, $product_data['name'] );
                            if ( ! is_wp_error( $gid ) && $gid > 0 ) {
                                $gids[] = (int) $gid;
                            }
                        }
                        if ( ! empty( $gids ) ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $wpdb->update(
                                $table,
                                array( 'gallery_ids' => wp_json_encode( $gids ) ),
                                array( 'id' => $product_id ),
                                array( '%s' ),
                                array( '%d' )
                            );
                        }
                    }
                }
            }

        if ( isset( $product_id ) && $product_id > 0 ) {
            if ( ! empty( $product_data['sku'] ) ) {
                $sku_to_id[ (string) $product_data['sku'] ] = (int) $product_id;
            }

            if ( isset( $data['tax_class'] ) ) {
                $tc_product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
                if ( $tc_product ) {
                    $tc_product->set_tax_class( (string) $data['tax_class'] );
                }
            }

            if ( isset( $data['shipping_class'] ) ) {
                $sc_product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
                if ( $sc_product && method_exists( $sc_product, 'set_shipping_class' ) ) {
                    $sc_product->set_shipping_class( (string) $data['shipping_class'] );
                }
            }

            if ( isset( $data['featured'] ) ) {
                $f_product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
                if ( $f_product && method_exists( $f_product, 'set_featured' ) ) {
                    $val = strtolower( trim( (string) $data['featured'] ) );
                    $f_product->set_featured( in_array( $val, array( '1', 'yes', 'true' ), true ) );
                }
            }

            if ( isset( $data['downloadable_files_json'] ) && '' !== trim( (string) $data['downloadable_files_json'] ) ) {
                $decoded = json_decode( (string) $data['downloadable_files_json'], true );
                if ( is_array( $decoded ) ) {
                    $product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
                    if ( $product ) {
                        $product->update_meta( '_download_files', wp_json_encode( $decoded ) );
                    }
                }
            }

            if ( 'external' === ( $product_data['type'] ?? '' ) ) {
                $ext_product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
                if ( $ext_product ) {
                    if ( isset( $data['external_url'] ) ) {
                        $ext_product->update_meta( '_product_url', esc_url_raw( (string) $data['external_url'] ) );
                    }
                    if ( isset( $data['external_button_text'] ) ) {
                        $ext_product->update_meta( '_button_text', sanitize_text_field( (string) $data['external_button_text'] ) );
                    }
                }
            }
        }
    }

    /**
     * Process a variation row. Resolves its parent_sku against the map
     * built in pass 1 and persists the variation's attribute structure.
     *
     * @param array $data       Row data.
     * @param int   $row_number Line number.
     * @param array $summary    Counters (mutated).
     * @param array $sku_to_id  SKU → id map from pass 1.
     */
    private function import_variation_row( array $data, int $row_number, array &$summary, array &$sku_to_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $parent_sku = isset( $data['variation_parent_sku'] ) ? trim( (string) $data['variation_parent_sku'] ) : '';
        if ( '' === $parent_sku ) {
            $summary['errors']++;
            $summary['error_messages'][] = sprintf(
                /* translators: %d: row number */
                __( 'Row %d: variation row missing variation_parent_sku. Skipped.', 'tejcart' ),
                $row_number
            );
            return;
        }

        $parent_id = 0;
        if ( isset( $sku_to_id[ $parent_sku ] ) ) {
            $parent_id = (int) $sku_to_id[ $parent_sku ];
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $parent_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE sku = %s LIMIT 1",
                $parent_sku
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        if ( ! $parent_id ) {
            $summary['errors']++;
            $summary['error_messages'][] = sprintf(
                /* translators: 1: row number, 2: parent sku */
                __( 'Row %1$d: parent SKU "%2$s" not found for variation. Skipped.', 'tejcart' ),
                $row_number,
                $parent_sku
            );
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $parent_type = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT type FROM {$table} WHERE id = %d LIMIT 1",
            $parent_id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( 'variable' !== $parent_type ) {
            $summary['errors']++;
            $summary['error_messages'][] = sprintf(
                /* translators: 1: row number, 2: parent sku, 3: parent type */
                __( 'Row %1$d: parent SKU "%2$s" is type "%3$s", not "variable". Variation skipped.', 'tejcart' ),
                $row_number,
                $parent_sku,
                $parent_type ?: 'unknown'
            );
            return;
        }

        $data['type'] = 'variation';
        $this->import_product_row( $data, $row_number, $summary, $sku_to_id );

        $sku = isset( $data['sku'] ) ? trim( (string) $data['sku'] ) : '';
        if ( '' === $sku || ! isset( $sku_to_id[ $sku ] ) ) {
            return;
        }

        $variation_id = (int) $sku_to_id[ $sku ];
        $product      = \TejCart\Product\Product_Factory::get_product( $variation_id );
        if ( ! $product ) {
            return;
        }

        $product->update_meta( '_variation_parent_id', $parent_id );

        if ( isset( $data['variation_attributes'] ) && '' !== trim( (string) $data['variation_attributes'] ) ) {
            $attrs = json_decode( (string) $data['variation_attributes'], true );
            if ( is_array( $attrs ) ) {
                $product->update_meta( '_variation_attributes', wp_json_encode( $attrs ) );
            }
        }
    }

    /**
     * Pass 3: persist cross-product references whose targets are addressed
     * by SKU — grouped children, upsells, cross-sells, bundled items. Any
     * SKU that didn't resolve is logged as a warning and dropped.
     */
    private function apply_sku_references( int $product_id, array $data, array $sku_to_id ): void {
        $product = \TejCart\Product\Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        if ( isset( $data['upsell_skus'] ) && '' !== trim( (string) $data['upsell_skus'] ) ) {
            $ids = $this->skus_to_ids( (string) $data['upsell_skus'], $sku_to_id );
            $product->update_meta( '_upsell_ids', wp_json_encode( $ids ) );
        }

        if ( isset( $data['cross_sell_skus'] ) && '' !== trim( (string) $data['cross_sell_skus'] ) ) {
            $ids = $this->skus_to_ids( (string) $data['cross_sell_skus'], $sku_to_id );
            $product->update_meta( '_crosssell_ids', wp_json_encode( $ids ) );
        }

        if ( isset( $data['related_skus'] ) && '' !== trim( (string) $data['related_skus'] ) ) {
            $ids = $this->skus_to_ids( (string) $data['related_skus'], $sku_to_id );
            $product->update_meta( '_related_ids', wp_json_encode( $ids ) );
        }

        if ( isset( $data['grouped_children_skus'] ) && '' !== trim( (string) $data['grouped_children_skus'] ) ) {
            $ids = $this->skus_to_ids( (string) $data['grouped_children_skus'], $sku_to_id );
            $product->update_meta( '_grouped_products', wp_json_encode( $ids ) );
        }

        if ( isset( $data['bundled_items_json'] ) && '' !== trim( (string) $data['bundled_items_json'] ) ) {
            $decoded = json_decode( (string) $data['bundled_items_json'], true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as &$item ) {
                    if ( is_array( $item ) && ! empty( $item['sku'] ) && isset( $sku_to_id[ $item['sku'] ] ) ) {
                        $item['product_id'] = (int) $sku_to_id[ $item['sku'] ];
                    }
                }
                unset( $item );
                $product->update_meta( '_bundled_items', wp_json_encode( $decoded ) );
            }
        }
    }

    /**
     * Translate a pipe-delimited SKU list into an array of product ids.
     *
     * Resolves first against $sku_to_id (built up in pass 1 of a synchronous
     * import) and falls back to a single grouped DB lookup for any SKU that
     * isn't in the map. The DB fallback matters for chunked / resumed imports
     * where pass 3 runs in a new request without the in-memory map.
     */
    private function skus_to_ids( string $csv, array $sku_to_id ): array {
        $skus = array_values( array_filter( array_map( 'trim', explode( '|', $csv ) ) ) );
        if ( empty( $skus ) ) {
            return array();
        }

        $ids     = array();
        $missing = array();
        foreach ( $skus as $sku ) {
            if ( isset( $sku_to_id[ $sku ] ) ) {
                $ids[] = (int) $sku_to_id[ $sku ];
            } else {
                $missing[] = $sku;
            }
        }

        if ( ! empty( $missing ) ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'tejcart_products';
            $placeholders = implode( ', ', array_fill( 0, count( $missing ), '%s' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id, sku FROM {$table} WHERE sku IN ({$placeholders})",
                    ...$missing
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $ids[] = (int) $row['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Assign taxonomy terms to a product, creating terms if they do not exist.
     *
     * @param int    $product_id     Product ID.
     * @param string $term_names_csv Comma-separated term names.
     * @param string $taxonomy       Taxonomy name.
     * @return void
     */
    private function assign_terms( $product_id, $term_names_csv, $taxonomy ) {
        global $wpdb;

        $rel_table  = $wpdb->prefix . 'tejcart_term_relationships';
        $term_names = array_map( 'trim', explode( ',', $term_names_csv ) );

        foreach ( $term_names as $term_name ) {
            if ( '' === $term_name ) {
                continue;
            }

            $cache_key = $taxonomy . ':' . strtolower( $term_name );
            if ( isset( $this->term_cache[ $cache_key ] ) ) {
                $tt_id = $this->term_cache[ $cache_key ];
            } else {
                $term = get_term_by( 'name', $term_name, $taxonomy );

                if ( ! $term ) {
                    $result = wp_insert_term( $term_name, $taxonomy );
                    if ( is_wp_error( $result ) ) {
                        continue;
                    }
                    $tt_id = (int) $result['term_taxonomy_id'];
                } else {
                    $tt_id = (int) $term->term_taxonomy_id;
                }

                $this->term_cache[ $cache_key ] = $tt_id;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $exists = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$rel_table} WHERE product_id = %d AND term_taxonomy_id = %d",
                    $product_id,
                    $tt_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->insert(
                    $rel_table,
                    array(
                        'product_id'       => $product_id,
                        'term_taxonomy_id' => $tt_id,
                    ),
                    array( '%d', '%d' )
                );
            }
        }
    }

    /**
     * Download a remote image and create a media library attachment for it.
     *
     * If an attachment with the same source URL already exists in the media
     * library it is reused instead of being re-downloaded.
     *
     * @param string $image_url    Source image URL.
     * @param int    $product_id   Product ID (used for the attachment description).
     * @param string $product_name Product name (used for the attachment title/alt).
     * @return int|\WP_Error Attachment ID on success, 0 if the URL is invalid, WP_Error on failure.
     */
    private function sideload_product_image( $image_url, $product_id, $product_name ) {
        $image_url = esc_url_raw( $image_url );
        if ( '' === $image_url ) {
            return 0;
        }

        /**
         * Filter whether remote image URLs are allowed during import.
         *
         * Stores can flip this off via the Products settings tab
         * (`tejcart_allow_remote_image_import`) to forbid any outbound HTTP
         * during bulk imports on a hardened host.
         *
         * @param bool $allowed Whether remote image URLs may be fetched.
         */
        $allow_remote = (bool) apply_filters(
            'tejcart_allow_remote_image_import',
            'no' !== (string) get_option( 'tejcart_allow_remote_image_import', 'yes' )
        );
        if ( ! $allow_remote ) {
            return new \WP_Error(
                'tejcart_remote_image_disabled',
                __( 'Remote image imports are disabled in TejCart settings.', 'tejcart' )
            );
        }

        if ( ! \TejCart\Security\Network::is_safe_remote_url( $image_url ) ) {
            return new \WP_Error(
                'tejcart_unsafe_image_url',
                sprintf(
                    /* translators: %s: URL */
                    __( 'Refused to fetch image from unsafe URL: %s', 'tejcart' ),
                    $image_url
                )
            );
        }

        $existing = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'     => array(
                    array(
                        'key'   => '_tejcart_source_url',
                        'value' => $image_url,
                    ),
                ),
            )
        );
        if ( ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // 15s per image keeps a single unreachable URL from monopolising the
        // chunk's PHP-execution budget. With the image-mode batch_size of 5,
        // worst-case chunk time is bounded around 75s — fits within typical
        // PHP / proxy timeouts.
        $download_timeout = (int) apply_filters( 'tejcart_import_image_download_timeout', 15 );
        $tmp              = download_url( $image_url, max( 1, $download_timeout ) );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // M-04: DNS-rebinding TOCTOU mitigation. is_safe_remote_url()
        // resolved the host once before the fetch; an attacker who
        // controls the authoritative DNS for the URL can flip the
        // record between the validation lookup and the actual HTTP
        // request, returning a public IP first and a private/metadata
        // IP for the GET. Re-resolve the host once more after the
        // download — if any returned IP now falls in the
        // private/metadata range, drop the temp file rather than
        // sideloading a possibly-attacker-controlled body (e.g. an
        // AWS IMDSv1 token document) into the media library.
        $url_host = wp_parse_url( $image_url, PHP_URL_HOST );
        if ( is_string( $url_host ) && '' !== $url_host ) {
            $post_ips = \TejCart\Security\Network::resolve_host( strtolower( $url_host ) );
            foreach ( $post_ips as $post_ip ) {
                if ( \TejCart\Security\Network::is_private_ip( $post_ip ) ) {
                    if ( file_exists( $tmp ) ) {
                        @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                    }
                    return new \WP_Error(
                        'tejcart_unsafe_image_url',
                        sprintf(
                            /* translators: %s: URL */
                            __( 'Refused to import image — host resolved to a private/metadata IP after download (possible DNS rebinding): %s', 'tejcart' ),
                            $image_url
                        )
                    );
                }
            }
        }

        $url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
        $base_name = $url_path ? basename( $url_path ) : '';
        $base_name = sanitize_file_name( $base_name );
        if ( '' === $base_name || false === strpos( $base_name, '.' ) ) {
            $mime = wp_check_filetype( $tmp );
            if ( empty( $mime['ext'] ) && function_exists( 'mime_content_type' ) ) {
                $detected = mime_content_type( $tmp );
                $map      = array(
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                );
                $ext = isset( $map[ $detected ] ) ? $map[ $detected ] : 'jpg';
            } else {
                $ext = ! empty( $mime['ext'] ) ? $mime['ext'] : 'jpg';
            }
            $base_name = sanitize_title( $product_name ) . '.' . $ext;
        }

        $file_array = array(
            'name'     => $base_name,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload(
            $file_array,
            0,
            $product_name,
            array(
                'post_title'   => $product_name,
                'post_excerpt' => $product_name,
            )
        );

        if ( is_wp_error( $attachment_id ) ) {
            if ( file_exists( $tmp ) ) {
                wp_delete_file( $tmp );
            }
            return $attachment_id;
        }

        update_post_meta( $attachment_id, '_tejcart_source_url', $image_url );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $product_name );

        return (int) $attachment_id;
    }

    /**
     * M-02: redact `$wpdb->last_error` for the customer-facing CSV
     * import response while preserving the full error in the server
     * log for ops debugging.
     *
     * The raw DB error string can leak schema, column-name, constraint,
     * or trigger details (e.g. `Duplicate entry 'X' for key
     * 'wp_tejcart_products.sku_unique'`) that aid a future SQL-injection
     * chain. The endpoint is admin-only and EDIT_PRODUCTS-gated, but
     * defence-in-depth still applies — emit a generic summary upstream.
     *
     * @param string $error_message Raw $wpdb->last_error.
     * @param int    $row_number    1-based CSV row index for the log line.
     * @param string $operation     'insert' | 'update' for log context.
     * @return string Redacted summary safe for the response payload.
     */
    private function summarize_db_error( string $error_message, int $row_number, string $operation ): string {
        if ( '' === $error_message ) {
            return '';
        }

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'CSV import row %d %s DB error: %s', $row_number, $operation, $error_message ),
                'error'
            );
        }

        return __( '(database error suppressed — see error log for details)', 'tejcart' );
    }
}
