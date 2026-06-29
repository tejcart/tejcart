<?php
/**
 * Maintenance Tools page.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tools admin screen: a single page with capability-gated, nonce-protected
 * maintenance actions scoped strictly to TejCart data.
 */
class Tools_Page {
    /**
     * Action keys that must carry an explicit `tejcart_tools_confirm`
     * field. The JS confirm() dialog on the form's submit button sets
     * it; a stale-tab replay that bypasses the dialog fails the
     * server-side check even with a valid nonce.
     */
    public const DESTRUCTIVE_ACTIONS = array(
        'clear_sessions',
        'clear_transients',
        'reset_roles',
    );

    /**
     * Register handlers.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Handle tool button submissions.
     *
     * @return void
     */
    public function handle_actions(): void {
        if ( empty( $_POST['tejcart_tools_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_tools_action' ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['tejcart_tools_action'] ) );

        // Destructive maintenance tools must carry an explicit
        // `tejcart_tools_confirm=yes` field. The JS confirm() dialog
        // on the submit button sets it; a clickjack / stale-tab replay
        // that bypasses the dialog will fail this server-side check
        // even though nonce + cap pass. See SEC-025 in the audit.
        if ( in_array( $action, self::DESTRUCTIVE_ACTIONS, true ) ) {
            $confirm = isset( $_POST['tejcart_tools_confirm'] )
                ? sanitize_key( wp_unslash( (string) $_POST['tejcart_tools_confirm'] ) )
                : '';
            if ( 'yes' !== $confirm ) {
                set_transient( 'tejcart_tools_notice', array(
                    'type'    => 'error',
                    'message' => __( 'Destructive action cancelled — confirmation token missing.', 'tejcart' ),
                ), 30 );
                wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=tools' ) );
                exit;
            }
        }

        $result = $this->run_tool( $action );

        set_transient( 'tejcart_tools_notice', array(
            'type'    => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
        ), 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=tools' ) );
        exit;
    }

    /**
     * Render the tools page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header for composition inside another
     *                      admin screen (Settings → Advanced → Tools).
     * @return void
     */
    public function render( bool $embedded = false ): void {
        $notice = get_transient( 'tejcart_tools_notice' );
        if ( $notice ) {
            delete_transient( 'tejcart_tools_notice' );
        }

        $tools = $this->get_tools();
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Tools', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Maintenance actions scoped to TejCart data.', 'tejcart' ); ?></p>
                </div>
            </div>
        <?php endif; ?>

            <?php if ( is_array( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:28%;"><?php esc_html_e( 'Tool', 'tejcart' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'tejcart' ); ?></th>
                        <th style="width:18%;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $tools as $tool ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $tool['label'] ); ?></strong></td>
                        <td><?php echo esc_html( $tool['description'] ); ?></td>
                        <td>
                            <form method="post" style="margin:0;">
                                <?php wp_nonce_field( 'tejcart_tools_action' ); ?>
                                <input type="hidden" name="tejcart_tools_action" value="<?php echo esc_attr( $tool['action'] ); ?>" />
                                <input type="hidden" name="tejcart_tools_confirm" value="" data-confirm-input />
                                <button type="submit" class="button button-secondary"
                                    onclick="if(!confirm('<?php echo esc_js( $tool['confirm'] ); ?>')){return false;}this.form.querySelector('[data-confirm-input]').value='yes';return true;">
                                    <?php echo esc_html( $tool['button'] ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:16px;">
                <?php esc_html_e( 'Background tasks scheduled by TejCart — recurring maintenance, queued emails, and webhook retries — are listed on the Scheduled Actions screen, where you can run or cancel them.', 'tejcart' ); ?>
                <a class="button button-secondary" style="margin-left:8px;" href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=scheduled-actions' ) ); ?>">
                    <?php esc_html_e( 'View Scheduled Actions', 'tejcart' ); ?>
                </a>
            </p>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Run a maintenance tool by action key. Used by both the admin form
     * handler and the REST controller so behaviour stays in lock-step.
     *
     * @param string $action Tool action key (see get_tools()).
     * @return array{success:bool, message:string} Outcome.
     */
    public function run_tool( string $action ): array {
        switch ( $action ) {
            case 'clear_sessions':
                $count = $this->clear_sessions();
                return array( 'success' => true, 'message' => sprintf( /* translators: %d: number of sessions */ __( 'Cleared %d customer session(s).', 'tejcart' ), $count ) );

            case 'clear_transients':
                $count = $this->clear_transients();
                return array( 'success' => true, 'message' => sprintf( /* translators: %d: number of transients */ __( 'Cleared %d TejCart transient row(s).', 'tejcart' ), $count ) );

            case 'recount_terms':
                $count = $this->recount_terms();
                return array( 'success' => true, 'message' => sprintf( /* translators: %d: number of terms */ __( 'Recounted %d taxonomy term(s).', 'tejcart' ), $count ) );

            case 'regenerate_downloads':
                $count = $this->regenerate_download_permissions();
                return array( 'success' => true, 'message' => sprintf( /* translators: %d: number of orders */ __( 'Regenerated download permissions for %d order(s).', 'tejcart' ), $count ) );

            case 'clear_template_cache':
                $this->clear_template_cache();
                return array( 'success' => true, 'message' => __( 'Template cache cleared.', 'tejcart' ) );

            case 'clear_geolocation_cache':
                $count = $this->clear_geolocation_cache();
                return array( 'success' => true, 'message' => sprintf( /* translators: %d: number of cache rows */ __( 'Cleared %d geolocation cache entries.', 'tejcart' ), $count ) );

            case 'reset_roles':
                $this->reset_roles();
                return array( 'success' => true, 'message' => __( 'TejCart roles and capabilities reset to defaults.', 'tejcart' ) );

            case 'regenerate_product_images':
                $count = $this->regenerate_product_images();
                return array(
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %d: number of attachments queued */
                        __( 'Queued %d product image(s) for regeneration. Metadata is rebuilt in the background.', 'tejcart' ),
                        $count
                    ),
                );

            default:
                return array( 'success' => false, 'message' => __( 'Unknown tool action.', 'tejcart' ) );
        }
    }

    /**
     * Re-run attachment metadata generation against every image referenced
     * by a product (image_id or gallery_ids). This is how we build the
     * crops for the TejCart-specific image sizes on a store that uploaded
     * its images before the sizes were registered.
     *
     * Returns the number of unique attachments processed.
     *
     * @return int
     */
    private function regenerate_product_images(): int {
        global $wpdb;
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT image_id, gallery_ids FROM {$table}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $attachment_ids = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row['image_id'] ) ) {
                $attachment_ids[ (int) $row['image_id'] ] = true;
            }
            if ( ! empty( $row['gallery_ids'] ) ) {
                $decoded = json_decode( (string) $row['gallery_ids'], true );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $gid ) {
                        $attachment_ids[ (int) $gid ] = true;
                    }
                }
            }
        }

        $count = 0;
        foreach ( array_keys( $attachment_ids ) as $attachment_id ) {
            if ( $attachment_id <= 0 ) {
                continue;
            }
            $file = get_attached_file( $attachment_id );
            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }
            $metadata = wp_generate_attachment_metadata( $attachment_id, $file );
            if ( is_array( $metadata ) ) {
                wp_update_attachment_metadata( $attachment_id, $metadata );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Tool definitions.
     *
     * @return array<int, array<string, string>>
     */
    public function get_tools(): array {
        return array(
            array(
                'label'       => __( 'Clear customer sessions', 'tejcart' ),
                'description' => __( 'Truncates the sessions table. All active carts will be abandoned.', 'tejcart' ),
                'action'      => 'clear_sessions',
                'button'      => __( 'Clear sessions', 'tejcart' ),
                'confirm'     => __( 'Clear ALL customer sessions?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Clear TejCart transients', 'tejcart' ),
                'description' => __( 'Deletes expired and stale TejCart-namespaced transients.', 'tejcart' ),
                'action'      => 'clear_transients',
                'button'      => __( 'Clear transients', 'tejcart' ),
                'confirm'     => __( 'Clear TejCart transients?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Recount taxonomy terms', 'tejcart' ),
                'description' => __( 'Rebuilds the product count stored on each category, tag, and brand term.', 'tejcart' ),
                'action'      => 'recount_terms',
                'button'      => __( 'Recount terms', 'tejcart' ),
                'confirm'     => __( 'Recount all TejCart taxonomy terms?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Regenerate download permissions', 'tejcart' ),
                'description' => __( 'Resets download counts so customers can re-download purchased files within their limits.', 'tejcart' ),
                'action'      => 'regenerate_downloads',
                'button'      => __( 'Regenerate', 'tejcart' ),
                'confirm'     => __( 'Regenerate download permissions for all completed orders?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Clear template cache', 'tejcart' ),
                'description' => __( 'Clears the cached template-override lookup map so theme changes take effect immediately.', 'tejcart' ),
                'action'      => 'clear_template_cache',
                'button'      => __( 'Clear template cache', 'tejcart' ),
                'confirm'     => __( 'Clear the template cache?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Clear geolocation cache', 'tejcart' ),
                'description' => __( 'Deletes cached geolocation lookups (country/state inferred from IP).', 'tejcart' ),
                'action'      => 'clear_geolocation_cache',
                'button'      => __( 'Clear geolocation cache', 'tejcart' ),
                'confirm'     => __( 'Clear the geolocation cache?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Regenerate product images', 'tejcart' ),
                'description' => __( 'Rebuilds the TejCart-specific image crops (card, main, thumb) for every image referenced by a product. Useful after upgrading from a version that pre-dated the custom sizes.', 'tejcart' ),
                'action'      => 'regenerate_product_images',
                'button'      => __( 'Regenerate', 'tejcart' ),
                'confirm'     => __( 'Regenerate TejCart image crops for every product image?', 'tejcart' ),
            ),
            array(
                'label'       => __( 'Reset TejCart roles', 'tejcart' ),
                'description' => __( 'Restores default capabilities for the Shop Manager / Customer roles.', 'tejcart' ),
                'action'      => 'reset_roles',
                'button'      => __( 'Reset roles', 'tejcart' ),
                'confirm'     => __( 'Reset TejCart roles and capabilities to defaults?', 'tejcart' ),
            ),
        );
    }

    /**
     * Truncate the sessions table.
     *
     * @return int Row count prior to truncate.
     */
    private function clear_sessions(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        return $count;
    }

    /**
     * Delete all tejcart_ transients.
     *
     * @return int Rows deleted.
     */
    private function clear_transients(): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_tejcart_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_' ) . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $deleted;
    }

    /**
     * Force a recount on TejCart taxonomy terms.
     *
     * @return int Number of terms whose count was refreshed.
     */
    private function recount_terms(): int {
        $taxonomies = array(
            \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY,
            \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY,
        );

        if ( defined( 'TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY' ) || class_exists( '\TejCart\Product\Product_Taxonomy' ) ) {
            $taxonomies[] = \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY;
        }

        $refreshed = 0;
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'fields'     => 'tt_ids',
            ) );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            wp_update_term_count_now( array_map( 'intval', $terms ), $taxonomy );
            $refreshed += count( $terms );
        }

        return $refreshed;
    }

    /**
     * Reset download counters so customers can re-download files.
     *
     * Only touches TejCart's own meta keys; does not re-issue new tokens or
     * break existing signed URLs.
     *
     * @return int Number of orders scanned.
     */
    private function regenerate_download_permissions(): int {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order_ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$orders_table} WHERE status IN ( %s, %s )",
                'completed',
                'processing'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $order_meta_table = $wpdb->prefix . 'tejcart_order_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$order_meta_table} WHERE meta_key LIKE %s",
                $wpdb->esc_like( '_download_count_' ) . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array( $order_ids ) ? count( $order_ids ) : 0;
    }

    /**
     * Clear the template-override lookup cache (used by tejcart_get_template).
     *
     * @return void
     */
    private function clear_template_cache(): void {
        wp_cache_flush_group( 'tejcart_templates' );
        do_action( 'tejcart_clear_template_cache' );
    }

    /**
     * Delete TejCart geolocation-cache transients.
     *
     * @return int Rows deleted.
     */
    private function clear_geolocation_cache(): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_tejcart_geo_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_geo_' ) . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $deleted;
    }

    /**
     * Re-run the role installer to reset capabilities.
     *
     * @return void
     */
    private function reset_roles(): void {
        \TejCart\Core\Capabilities::install();

        if ( method_exists( '\TejCart\Core\Installer', 'install_roles' ) ) {
            \TejCart\Core\Installer::install_roles();
            return;
        }

        if ( method_exists( '\TejCart\Core\Installer', 'activate' ) ) {
            \TejCart\Core\Installer::activate();
        }
    }
}
