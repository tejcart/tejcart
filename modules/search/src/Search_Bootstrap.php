<?php
/**
 * Search module orchestrator.
 *
 * Wires the search index, REST API, autocomplete widget, and
 * product-lifecycle listeners. Called from the module bootstrap
 * on `tejcart_init` priority 20.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Search_Bootstrap {
    private static bool $initialised = false;

    public static function init(): void {
        if ( self::$initialised ) {
            return;
        }
        self::$initialised = true;

        self::maybe_upgrade_schema();

        $index   = new Search_Index();
        $service = new Search_Service( $index );

        ( new REST_Controller( $service ) )->register();
        ( new Autocomplete_Widget() )->register();

        self::register_product_hooks( $index );
        self::register_admin_hooks( $index );
        self::register_frontend_hooks( $service );
    }

    /**
     * Ensure the search index table exists and is up to date.
     *
     * Mirrors the Tier-2 schema pattern ({@see \TejCart\Tier2\Tier2::boot()}):
     * gate dbDelta behind a version cursor so it only runs when the
     * module's schema version changes — never on every request. The
     * `install` registry callback already creates the table on the
     * toggle-ON transition; this is the safety net that also covers:
     *
     *   - a future release bumping TEJCART_SEARCH_DB_VERSION (an
     *     already-enabled site picks up the new schema on update without
     *     a manual off/on toggle),
     *   - the table being absent because an earlier install callback
     *     failed or the module was enabled before that callback existed.
     *
     * The check is a single (cached) option read on the hot path; the
     * expensive dbDelta only fires when the stored version differs.
     */
    private static function maybe_upgrade_schema(): void {
        if ( ! defined( 'TEJCART_SEARCH_DB_VERSION' ) || ! defined( 'TEJCART_SEARCH_DB_VERSION_OPTION' ) ) {
            return;
        }
        if ( get_option( TEJCART_SEARCH_DB_VERSION_OPTION, '' ) === TEJCART_SEARCH_DB_VERSION ) {
            return;
        }
        // Search_Index::install() runs dbDelta, stamps the version option
        // to current (so this guard no-ops on the next request) and
        // schedules a reindex to populate the rebuilt table.
        Search_Index::install();
    }

    /**
     * Reset state for testing.
     */
    public static function reset(): void {
        self::$initialised = false;
    }

    private static function register_product_hooks( Search_Index $index ): void {
        add_action( 'tejcart_product_saved', static function ( int $product_id ) use ( $index ): void {
            $index->index_product( $product_id );
            Search_Service::flush_cache();
        } );

        add_action( 'tejcart_product_deleted', static function ( int $product_id ) use ( $index ): void {
            $index->remove_product( $product_id );
            Search_Service::flush_cache();
        } );
    }

    private static function register_frontend_hooks( Search_Service $service ): void {
        add_action( 'tejcart_before_shop_loop', array( static::class, 'render_shop_search_bar' ), 10, 2 );

        // Make the shop grid's "View all results" page use the same matching
        // engine (FULLTEXT + fuzzy) as the autocomplete dropdown, so a typo
        // like "kotton" still surfaces the "cotton" products instead of an
        // empty grid. We hand the grid a relevance-ordered ID list; the grid
        // applies its own pagination/visibility on top.
        add_filter(
            'tejcart_shop_search_product_ids',
            static function ( $ids, string $term ) use ( $service ) {
                if ( null !== $ids ) {
                    return $ids; // Another provider already answered.
                }

                /**
                 * Maximum number of products the shop full-results page will
                 * pull from the search engine. Generous enough to fill several
                 * pages of results while bounding the fuzzy scan.
                 *
                 * @param int    $limit The result cap.
                 * @param string $term  The search term.
                 */
                $limit = (int) apply_filters( 'tejcart_search_shop_results_limit', 200, $term );
                $limit = max( 1, $limit );

                $results = $service->search( $term, $limit );

                return array_map( static fn( array $r ): int => (int) $r['id'], $results );
            },
            10,
            2
        );
    }

    public static function render_shop_search_bar(): void {
        if ( ! function_exists( 'do_shortcode' ) || ! shortcode_exists( 'tejcart_product_search' ) ) {
            return;
        }
        echo '<div class="tejcart-shop-search">';
        echo do_shortcode( '[tejcart_product_search]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode emits escaped markup.
        echo '</div>';
    }

    private static function register_admin_hooks( Search_Index $index ): void {
        add_filter( 'tejcart_settings_tabs', array( static::class, 'register_settings_tab' ) );
        add_filter( 'tejcart_settings_tab_groups', array( static::class, 'register_settings_tab_group' ), 10, 2 );
        add_filter( 'tejcart_get_settings_search', array( static::class, 'register_settings_fields' ) );
        add_action( 'admin_enqueue_scripts', array( static::class, 'maybe_enqueue_admin_assets' ) );
        add_action( 'wp_ajax_tejcart_search_reindex', static function () use ( $index ): void {
            self::ajax_reindex( $index );
        } );
        add_action( 'wp_ajax_tejcart_search_reindex_status', array( static::class, 'ajax_reindex_status' ) );

        add_action( 'tejcart_search_reindex_batch', static function () use ( $index ): void {
            $index->reindex_all();
            Search_Service::flush_cache();
        } );
    }

    /**
     * @param array<string,array<string,mixed>> $tabs
     * @return array<string,array<string,mixed>>
     */
    public static function register_settings_tab( array $tabs ): array {
        $tabs['search'] = array(
            'id'       => 'search',
            'label'    => __( 'Search', 'tejcart' ),
            'icon'     => 'dashicons-search',
            'desc'     => __( 'Configure product search indexing and autocomplete.', 'tejcart' ),
            'sections' => array(),
        );
        return $tabs;
    }

    /**
     * @param array<string,array{label:string,tabs:string[]}> $groups
     * @param array<string,mixed>                              $tabs
     * @return array<string,array{label:string,tabs:string[]}>
     */
    public static function register_settings_tab_group( array $groups, array $tabs ): array {
        if ( isset( $groups['store'] ) && is_array( $groups['store']['tabs'] ?? null ) ) {
            $pos = array_search( 'products', $groups['store']['tabs'], true );
            if ( false !== $pos ) {
                array_splice( $groups['store']['tabs'], $pos + 1, 0, array( 'search' ) );
            } else {
                $groups['store']['tabs'][] = 'search';
            }
        }
        return $groups;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    public static function register_settings_fields( array $fields ): array {
        return array(
            array(
                'name'  => 'search_autocomplete_heading',
                'label' => __( 'Autocomplete', 'tejcart' ),
                'type'  => 'heading',
                'desc'  => __( 'Controls how the storefront search dropdown behaves.', 'tejcart' ),
            ),
            array(
                'name'    => 'search_debounce',
                'label'   => __( 'Debounce delay (ms)', 'tejcart' ),
                'type'    => 'number',
                'default' => '300',
                'min'     => '100',
                'max'     => '1000',
                'step'    => '50',
                'desc'    => __( 'Milliseconds to wait after the last keystroke before querying. Lower values feel snappier but increase server load.', 'tejcart' ),
            ),
            array(
                'name'    => 'search_min_chars',
                'label'   => __( 'Minimum characters', 'tejcart' ),
                'type'    => 'number',
                'default' => '2',
                'min'     => '1',
                'max'     => '5',
                'step'    => '1',
                'desc'    => __( 'Minimum query length before autocomplete fires. Raising this reduces noise on small catalogs.', 'tejcart' ),
            ),
            array(
                'name'    => 'search_max_results',
                'label'   => __( 'Maximum suggestions', 'tejcart' ),
                'type'    => 'number',
                'default' => '8',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
                'desc'    => __( 'Number of products shown in the autocomplete dropdown.', 'tejcart' ),
            ),
            array(
                'name'  => 'search_relevance_heading',
                'label' => __( 'Relevance weights', 'tejcart' ),
                'type'  => 'heading',
                'desc'  => __( 'Adjust how much each product field contributes to the relevance score. Higher values rank matches in that field higher.', 'tejcart' ),
            ),
            array(
                'name'    => 'search_weight_title',
                'label'   => __( 'Title', 'tejcart' ),
                'type'    => 'number',
                'default' => '10',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
            ),
            array(
                'name'    => 'search_weight_sku',
                'label'   => __( 'SKU', 'tejcart' ),
                'type'    => 'number',
                'default' => '8',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
            ),
            array(
                'name'    => 'search_weight_short_desc',
                'label'   => __( 'Short description', 'tejcart' ),
                'type'    => 'number',
                'default' => '5',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
            ),
            array(
                'name'    => 'search_weight_desc',
                'label'   => __( 'Description', 'tejcart' ),
                'type'    => 'number',
                'default' => '2',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
            ),
            array(
                'name'    => 'search_weight_attrs',
                'label'   => __( 'Attributes', 'tejcart' ),
                'type'    => 'number',
                'default' => '3',
                'min'     => '1',
                'max'     => '20',
                'step'    => '1',
            ),
            array(
                'name'  => 'search_index_heading',
                'label' => __( 'Search index', 'tejcart' ),
                'type'  => 'heading',
                'desc'  => __( 'The search index is updated automatically when products are saved or deleted. Use the button below to rebuild the entire index.', 'tejcart' ),
            ),
            array(
                'name'  => 'search_reindex_note',
                'label' => __( 'Rebuild index', 'tejcart' ),
                'type'  => 'note',
                'desc'  => __( 'Re-creates the search index from scratch. Run this after a bulk import or if search results seem stale.', 'tejcart' ),
            ),
        );
    }

    public static function maybe_enqueue_admin_assets( string $hook_suffix ): void {
        if ( false === strpos( $hook_suffix, 'tejcart-settings' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        if ( 'search' !== $tab ) {
            return;
        }
        wp_enqueue_script(
            'tejcart-search-settings',
            tejcart_asset_url( 'assets/js/admin/tejcart-search-settings.js' ),
            array(),
            defined( 'TEJCART_SEARCH_VERSION' ) ? TEJCART_SEARCH_VERSION : '1.0.0',
            true
        );
        wp_localize_script(
            'tejcart-search-settings',
            'tejcartSearchSettings',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'tejcart_search_reindex' ),
                'i18n'    => array(
                    'reindex'    => __( 'Reindex Now', 'tejcart' ),
                    'reindexing' => __( 'Reindexing…', 'tejcart' ),
                    'queued'     => __( 'Reindex queued. Running in the background…', 'tejcart' ),
                    /* translators: %d: number of products indexed */
                    'done'       => __( 'Done! %d products indexed.', 'tejcart' ),
                    'complete'   => __( 'Reindex complete.', 'tejcart' ),
                    /* translators: %s: error message */
                    'error'      => __( 'Error: %s', 'tejcart' ),
                ),
            )
        );
    }

    private static function ajax_reindex( Search_Index $index ): void {
        check_ajax_referer( 'tejcart_search_reindex', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'tejcart' ), 403 );
        }

        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            $scheduler = \TejCart\Core\Action_Scheduler::instance();
            if ( $scheduler->is_scheduled( 'tejcart_search_reindex_batch' ) ) {
                wp_send_json_error( __( 'A reindex is already in progress.', 'tejcart' ), 409 );
            }
            $scheduler->schedule_single( time(), 'tejcart_search_reindex_batch' );
            wp_send_json_success( array(
                'status'  => 'queued',
                'message' => __( 'Reindex queued. Running in the background…', 'tejcart' ),
            ) );
            return;
        }

        $count = $index->reindex_all();
        Search_Service::flush_cache();
        wp_send_json_success( array( 'indexed' => $count ) );
    }

    public static function ajax_reindex_status(): void {
        check_ajax_referer( 'tejcart_search_reindex', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'tejcart' ), 403 );
        }
        $pending = false;
        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            $pending = \TejCart\Core\Action_Scheduler::instance()->is_scheduled( 'tejcart_search_reindex_batch' );
        }
        wp_send_json_success( array( 'pending' => $pending ) );
    }
}
