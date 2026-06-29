<?php
/**
 * System Status diagnostic page.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays system diagnostic information and provides
 * maintenance action buttons for TejCart administrators.
 */
class System_Status {
    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Handle action button requests (clear transients, clear sessions).
     *
     * @return void
     */
    public function handle_actions() {
        if ( ! isset( $_POST['tejcart_status_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_system_status_action' ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['tejcart_status_action'] ) );

        switch ( $action ) {
            case 'clear_transients':
                $this->clear_transients();
                set_transient( 'tejcart_status_notice', __( 'TejCart transients cleared.', 'tejcart' ), 30 );
                break;

            case 'clear_sessions':
                $this->clear_sessions();
                set_transient( 'tejcart_status_notice', __( 'Sessions table cleared.', 'tejcart' ), 30 );
                break;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=system-status' ) );
        exit;
    }

    /**
     * Render the system status page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header for composition inside another
     *                      admin screen (Settings → Advanced → System Status).
     * @return void
     */
    public function render( $embedded = false ) {
        $notice = get_transient( 'tejcart_status_notice' );
        if ( $notice ) {
            delete_transient( 'tejcart_status_notice' );
        }

        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'System Status', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Diagnostic information and maintenance tools.', 'tejcart' ); ?></p>
                </div>
                <div class="tejcart-page-header-actions">
                    <button type="button" class="button" id="tejcart-copy-status">
                        <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy for Support', 'tejcart' ); ?>
                    </button>
                </div>
            </div>
        <?php else : ?>
            <div class="tejcart-page-header-actions tejcart-embedded-actions">
                <button type="button" class="button" id="tejcart-copy-status">
                    <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy for Support', 'tejcart' ); ?>
                </button>
            </div>
        <?php endif; ?>

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $notice ); ?></p>
                </div>
            <?php endif; ?>

            <div class="tejcart-status-actions">
                <form method="post" class="tejcart-inline-form">
                    <?php wp_nonce_field( 'tejcart_system_status_action' ); ?>
                    <input type="hidden" name="tejcart_status_action" value="clear_transients" />
                    <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Clear all TejCart transients?', 'tejcart' ); ?>');">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Transients', 'tejcart' ); ?>
                    </button>
                </form>

                <form method="post" class="tejcart-inline-form">
                    <?php wp_nonce_field( 'tejcart_system_status_action' ); ?>
                    <input type="hidden" name="tejcart_status_action" value="clear_sessions" />
                    <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Truncate the sessions table? All active sessions will be lost.', 'tejcart' ); ?>');">
                        <span class="dashicons dashicons-database-remove"></span> <?php esc_html_e( 'Clear Sessions', 'tejcart' ); ?>
                    </button>
                </form>
            </div>

            <div id="tejcart-status-report">
                <?php $this->render_wordpress_environment(); ?>
                <?php $this->render_server_environment(); ?>
                <?php $this->render_tejcart_section(); ?>
                <?php $this->render_active_theme(); ?>
                <?php $this->render_active_plugins(); ?>
            </div>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>

        <?php
    }

    /**
     * Render WordPress Environment section.
     *
     * @return void
     */
    private function render_wordpress_environment() {
        global $wp_version;

        $rows = array(
            __( 'WordPress Version', 'tejcart' )    => esc_html( $wp_version ),
            __( 'Multisite', 'tejcart' )             => is_multisite() ? esc_html__( 'Yes', 'tejcart' ) : esc_html__( 'No', 'tejcart' ),
            __( 'Site URL', 'tejcart' )              => esc_html( site_url() ),
            __( 'Home URL', 'tejcart' )              => esc_html( home_url() ),
            __( 'WP Memory Limit', 'tejcart' )       => esc_html( WP_MEMORY_LIMIT ),
            __( 'WP Debug', 'tejcart' )              => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? esc_html__( 'On', 'tejcart' ) : esc_html__( 'Off', 'tejcart' ),
            __( 'WP Cron', 'tejcart' )               => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? esc_html__( 'Disabled', 'tejcart' ) : esc_html__( 'Enabled', 'tejcart' ),
            __( 'External Object Cache', 'tejcart' ) => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
                ? esc_html__( 'Active', 'tejcart' )
                : esc_html__( 'Not detected — Redis or Memcached is recommended for high-traffic stores; without it TejCart\'s object-cache lookups are in-process only.', 'tejcart' ),
            __( 'Language', 'tejcart' )              => esc_html( get_locale() ),
            __( 'Permalink Structure', 'tejcart' )   => esc_html( get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : __( 'Default (Plain)', 'tejcart' ) ),
        );

        $this->render_table( __( 'WordPress Environment', 'tejcart' ), $rows );
    }

    /**
     * Render Server Environment section.
     *
     * @return void
     */
    private function render_server_environment() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $mysql_version = $wpdb->get_var( 'SELECT VERSION()' );

        $curl_version = '';
        if ( function_exists( 'curl_version' ) ) {
            $cv           = curl_version();
            $curl_version = $cv['version'] . ' (OpenSSL ' . ( $cv['ssl_version'] ?? 'N/A' ) . ')';
        }

        $openssl_version = defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : __( 'N/A', 'tejcart' );

        $rows = array(
            __( 'PHP Version', 'tejcart' )           => esc_html( PHP_VERSION ),
            __( 'MySQL Version', 'tejcart' )         => esc_html( $mysql_version ),
            __( 'Server Software', 'tejcart' )       => esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'tejcart' ) ),
            __( 'PHP Max Upload Size', 'tejcart' )   => esc_html( ini_get( 'upload_max_filesize' ) ),
            __( 'PHP Max Execution Time', 'tejcart' ) => esc_html( ini_get( 'max_execution_time' ) . 's' ),
            __( 'PHP Memory Limit', 'tejcart' )      => esc_html( ini_get( 'memory_limit' ) ),
            __( 'cURL Version', 'tejcart' )          => esc_html( $curl_version ? $curl_version : __( 'N/A', 'tejcart' ) ),
            __( 'OpenSSL Version', 'tejcart' )       => esc_html( $openssl_version ),
            __( 'fsockopen/cURL', 'tejcart' )        => ( function_exists( 'fsockopen' ) || function_exists( 'curl_init' ) )
                ? esc_html__( 'Enabled', 'tejcart' )
                : esc_html__( 'Disabled', 'tejcart' ),
            __( 'DOMDocument', 'tejcart' )           => class_exists( 'DOMDocument' )
                ? esc_html__( 'Available', 'tejcart' )
                : esc_html__( 'Not available', 'tejcart' ),
            __( 'GZip', 'tejcart' )                  => function_exists( 'gzopen' )
                ? esc_html__( 'Available', 'tejcart' )
                : esc_html__( 'Not available', 'tejcart' ),
            __( 'Multibyte String', 'tejcart' )      => extension_loaded( 'mbstring' )
                ? esc_html__( 'Available', 'tejcart' )
                : esc_html__( 'Not available', 'tejcart' ),
        );

        $this->render_table( __( 'Server Environment', 'tejcart' ), $rows );
    }

    /**
     * Render the TejCart-specific section.
     *
     * @return void
     */
    private function render_tejcart_section() {
        global $wpdb;

        $rows = array();

        $rows[ __( 'Plugin Version', 'tejcart' ) ] = esc_html( defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : __( 'Unknown', 'tejcart' ) );

        $tejcart_tables = array(
            'tejcart_products',
            'tejcart_product_meta',
            'tejcart_orders',
            'tejcart_order_items',
            'tejcart_order_meta',
            'tejcart_customers',
            'tejcart_coupons',
            'tejcart_sessions',
            'tejcart_term_relationships',
        );

        $table_info = '';
        foreach ( $tejcart_tables as $tbl ) {
            $full_name = $wpdb->prefix . $tbl;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row_count = $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $full_name
            ) );

            if ( ! $row_count ) {
                $table_info .= esc_html( $tbl ) . ': ' . esc_html__( 'Table not found', 'tejcart' ) . "\n";
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $stats = $wpdb->get_row( $wpdb->prepare(
                'SELECT TABLE_ROWS, ROUND(( DATA_LENGTH + INDEX_LENGTH ) / 1024, 2) AS size_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $full_name
            ) );

            $table_info .= esc_html( $tbl ) . ': ' . esc_html( $stats->TABLE_ROWS ?? 0 ) . ' ' . esc_html__( 'rows', 'tejcart' ) . ', ' . esc_html( $stats->size_kb ?? 0 ) . ' KB' . "\n";
        }
        $rows[ __( 'Database Tables', 'tejcart' ) ] = '<pre style="margin:0;white-space:pre-wrap;">' . $table_info . '</pre>';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
                $wpdb->esc_like( 'tejcart_' ) . '%'
            )
        );

        $options_text = '';
        if ( $options ) {
            foreach ( $options as $opt ) {
                $value = mb_strlen( $opt->option_value ) > 100 ? mb_substr( $opt->option_value, 0, 100 ) . '...' : $opt->option_value;
                $options_text .= esc_html( $opt->option_name ) . ' = ' . esc_html( $value ) . "\n";
            }
        } else {
            $options_text = esc_html__( 'No tejcart_ options found.', 'tejcart' );
        }
        $rows[ __( 'TejCart Options', 'tejcart' ) ] = '<pre style="margin:0;white-space:pre-wrap;max-height:300px;overflow:auto;">' . $options_text . '</pre>';

        $overrides      = $this->get_template_overrides();
        $overrides_text = '';
        if ( ! empty( $overrides ) ) {
            foreach ( $overrides as $override ) {
                $overrides_text .= esc_html( $override ) . "\n";
            }
        } else {
            $overrides_text = esc_html__( 'No template overrides detected.', 'tejcart' );
        }
        $rows[ __( 'Template Overrides', 'tejcart' ) ] = '<pre style="margin:0;white-space:pre-wrap;">' . $overrides_text . '</pre>';

        $gateway_registry = new \TejCart\Gateways\Gateway_Registry();
        $gateway_registry->init();
        $gateways      = $gateway_registry->get_available_gateways();
        $gateway_names = array();
        foreach ( $gateways as $gw ) {
            $gateway_names[] = $gw->get_title() . ' (' . $gw->get_id() . ')';
        }
        $rows[ __( 'Active Gateways', 'tejcart' ) ] = ! empty( $gateway_names )
            ? esc_html( implode( ', ', $gateway_names ) )
            : esc_html__( 'None', 'tejcart' );

        $paypal_settings = get_option( 'tejcart_gateway_tejcart_paypal', array() );
        if ( is_array( $paypal_settings ) && ! empty( $paypal_settings['enabled'] ) && 'yes' === $paypal_settings['enabled'] ) {
            $webhook_id = isset( $paypal_settings['webhook_id'] ) ? trim( (string) $paypal_settings['webhook_id'] ) : '';
            if ( '' !== $webhook_id ) {
                $rows[ __( 'PayPal Webhook', 'tejcart' ) ] =
                    '<span style="color:#1a8754;font-weight:600;">' . esc_html__( 'Configured', 'tejcart' ) . '</span> '
                    . '<code>' . esc_html( $webhook_id ) . '</code>';
            } else {
                $rows[ __( 'PayPal Webhook', 'tejcart' ) ] =
                    '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Not configured', 'tejcart' ) . '</span> '
                    . esc_html__( '— PayPal will reject incoming notifications until a webhook ID is registered.', 'tejcart' );
            }
        }

        $shipping_manager = new \TejCart\Shipping\Shipping_Manager();
        $zones            = $shipping_manager->get_zones();
        $method_list      = array();
        foreach ( $zones as $zone ) {
            if ( ! empty( $zone['methods'] ) ) {
                foreach ( $zone['methods'] as $method ) {
                    $method_list[] = ( $zone['name'] ?? '' ) . ': ' . ( $method['type'] ?? $method['id'] ?? 'unknown' );
                }
            }
        }
        $rows[ __( 'Active Shipping Methods', 'tejcart' ) ] = ! empty( $method_list )
            ? esc_html( implode( ', ', $method_list ) )
            : esc_html__( 'None', 'tejcart' );

        $rest_url = rest_url( 'tejcart/v1/' );
        $rows[ __( 'REST API', 'tejcart' ) ] = esc_html( $rest_url );

        $log_dir  = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR . 'logs/' : '';
        $log_size = __( 'N/A', 'tejcart' );
        if ( $log_dir && is_dir( $log_dir ) ) {
            $total = 0;
            $files = glob( $log_dir . '*.log' );
            if ( $files ) {
                foreach ( $files as $lf ) {
                    $total += filesize( $lf );
                }
            }
            $log_size = size_format( $total );
        }
        $rows[ __( 'Log File Size', 'tejcart' ) ] = esc_html( $log_size );

        $sessions_table = $wpdb->prefix . 'tejcart_sessions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $session_count = $wpdb->get_var( $wpdb->prepare(
            'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME,
            $sessions_table
        ) );
        $rows[ __( 'Session Row Count', 'tejcart' ) ] = esc_html( $session_count ? $session_count : '0' );

        $last_cleanup = get_option( 'tejcart_last_session_cleanup', array() );
        if ( is_array( $last_cleanup ) && ! empty( $last_cleanup['time'] ) ) {
            $rows[ __( 'Last Session Cleanup', 'tejcart' ) ] = esc_html(
                sprintf(
                    /* translators: 1: human readable diff (e.g. 5 minutes), 2: row count. */
                    __( '%1$s ago (%2$d expired rows removed)', 'tejcart' ),
                    human_time_diff( (int) $last_cleanup['time'], time() ),
                    (int) ( $last_cleanup['deleted'] ?? 0 )
                )
            );
        } else {
            $rows[ __( 'Last Session Cleanup', 'tejcart' ) ] = esc_html__( 'Never (cron may not be running)', 'tejcart' );
        }

        $cron_hooks = array(
            'tejcart_cleanup_sessions',
            'tejcart_process_pending_orders',
            'tejcart_send_queued_emails',
            'tejcart_check_expired_coupons',
        );

        $cron_text = '';
        foreach ( $cron_hooks as $hook ) {
            $next = wp_next_scheduled( $hook );
            if ( $next ) {
                $cron_text .= esc_html( $hook ) . ': ' . esc_html( date_i18n( 'Y-m-d H:i:s', $next ) ) . "\n";
            } else {
                $cron_text .= esc_html( $hook ) . ': ' . esc_html__( 'Not scheduled', 'tejcart' ) . "\n";
            }
        }
        $rows[ __( 'Cron Jobs', 'tejcart' ) ] = '<pre style="margin:0;white-space:pre-wrap;">' . $cron_text . '</pre>';

        $this->render_table( __( 'TejCart', 'tejcart' ), $rows, false );
    }

    /**
     * Render the Active Theme section.
     *
     * @return void
     */
    private function render_active_theme() {
        $theme       = wp_get_theme();
        $is_child    = is_child_theme();
        $parent_name = '';

        if ( $is_child ) {
            $parent      = wp_get_theme( $theme->template );
            $parent_name = $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' );
        }

        $override_files = $this->get_template_overrides();

        $rows = array(
            __( 'Theme Name', 'tejcart' )    => esc_html( $theme->get( 'Name' ) ),
            __( 'Theme Version', 'tejcart' ) => esc_html( $theme->get( 'Version' ) ),
            __( 'Is Child Theme', 'tejcart' ) => $is_child ? esc_html__( 'Yes', 'tejcart' ) : esc_html__( 'No', 'tejcart' ),
        );

        if ( $is_child ) {
            $rows[ __( 'Parent Theme', 'tejcart' ) ] = esc_html( $parent_name );
        }

        $overrides_text = '';
        if ( ! empty( $override_files ) ) {
            foreach ( $override_files as $file ) {
                $overrides_text .= esc_html( $file ) . "\n";
            }
        } else {
            $overrides_text = esc_html__( 'No TejCart template overrides.', 'tejcart' );
        }
        $rows[ __( 'Template Overrides', 'tejcart' ) ] = '<pre style="margin:0;white-space:pre-wrap;">' . $overrides_text . '</pre>';

        $this->render_table( __( 'Active Theme', 'tejcart' ), $rows, false );
    }

    /**
     * Render the Active Plugins section.
     *
     * @return void
     */
    private function render_active_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option( 'active_plugins', array() );
        $all_plugins    = get_plugins();

        $rows = array();
        foreach ( $active_plugins as $plugin_path ) {
            if ( isset( $all_plugins[ $plugin_path ] ) ) {
                $plugin = $all_plugins[ $plugin_path ];
                $rows[ esc_html( $plugin['Name'] ) ] = esc_html( $plugin['Version'] );
            }
        }

        if ( empty( $rows ) ) {
            $rows[ __( 'No active plugins.', 'tejcart' ) ] = '';
        }

        $this->render_table( __( 'Active Plugins', 'tejcart' ), $rows );
    }

    /**
     * Render a status table.
     *
     * @param string $title       Table heading.
     * @param array  $rows        Key-value pairs to display.
     * @param bool   $escape_vals Whether to escape values (false if already escaped/HTML).
     * @return void
     */
    private function render_table( $title, $rows, $escape_vals = true ) {
        ?>
        <div class="tejcart-card tejcart-status-card">
            <div class="tejcart-card-header"><h3><?php echo esc_html( $title ); ?></h3></div>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <?php foreach ( $rows as $label => $value ) : ?>
                        <tr>
                            <td class="tejcart-status-label"><?php echo esc_html( $label ); ?></td>
                            <?php if ( $escape_vals ) : ?>
                                <td><?php echo esc_html( $value ); ?></td>
                            <?php else : ?>
                                <td><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values pre-escaped. ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Get a list of TejCart template files overridden by the active theme.
     *
     * Looks for files in the theme's tejcart/ subdirectory.
     *
     * @return string[] List of relative template file paths.
     */
    private function get_template_overrides() {
        $theme_dir  = get_stylesheet_directory() . '/tejcart/';
        $parent_dir = get_template_directory() . '/tejcart/';
        $overrides  = array();

        $dirs_to_check = array( $theme_dir );
        if ( is_child_theme() && $parent_dir !== $theme_dir ) {
            $dirs_to_check[] = $parent_dir;
        }

        foreach ( $dirs_to_check as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( 'php' === pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) ) {
                    $relative    = str_replace( $dir, '', $file->getPathname() );
                    $overrides[] = 'tejcart/' . ltrim( $relative, '/' );
                }
            }
        }

        return $overrides;
    }

    /**
     * Delete all tejcart_ transients from the database.
     *
     * @return void
     */
    private function clear_transients() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_tejcart_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_' ) . '%'
            )
        );
    }

    /**
     * Truncate the sessions table.
     *
     * @return void
     */
    private function clear_sessions() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }
}
