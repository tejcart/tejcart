<?php
/**
 * System Status REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Admin\Tools_Page;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/system_status.
 *
 * Provides a JSON snapshot of environment + TejCart-specific diagnostics
 * for monitoring/healthcheck tooling, plus a dispatcher onto the same
 * maintenance tools the admin Tools page exposes.
 */
class System_Status_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'system_status';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_status' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/tools',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_tools' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/tools/(?P<id>[\w_-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'run_tool' ),
                    'permission_callback' => array( $this, 'manage_permissions_check' ),
                ),
            )
        );
    }

    public function read_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE );
    }

    public function manage_permissions_check() {
        // Maintenance tools mutate site-wide state (user sessions, transients,
        // roles & capabilities), so they require the same `manage_options`
        // gate as the admin Tools form — NOT the lesser MANAGE_STORE that a
        // shop_manager holds. Otherwise a shop_manager could reset roles or
        // log out every customer over REST.
        return current_user_can( 'manage_options' );
    }

    /**
     * Build the snapshot.
     */
    public function get_status( WP_REST_Request $request ) {
        global $wp_version, $wpdb;

        $environment = array(
            'home_url'          => home_url(),
            'site_url'          => site_url(),
            'wp_version'        => $wp_version,
            'php_version'       => PHP_VERSION,
            'php_max_execution' => (int) ini_get( 'max_execution_time' ),
            'php_memory_limit'  => (string) ini_get( 'memory_limit' ),
            'mysql_version'     => $wpdb->db_version(),
            'language'          => get_locale(),
            'timezone'          => wp_timezone_string(),
            'wp_debug_mode'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'is_ssl'            => is_ssl(),
        );

        $tejcart = array(
            'version'        => defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : null,
            'plugin_dir'     => defined( 'TEJCART_PLUGIN_FILE' ) ? '/' . dirname( TEJCART_PLUGIN_BASENAME ) . '/' : null,
            'currency'       => function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : get_option( 'tejcart_currency', 'USD' ),
            'rest_url'       => rest_url( 'tejcart/v1/' ),
            'tables'         => $this->table_summary(),
            'gateways'       => $this->active_gateways(),
            'shipping_zones' => $this->shipping_summary(),
            'cron_jobs'      => $this->cron_summary(),
        );

        $theme = wp_get_theme();
        $theme_summary = array(
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        );

        $active_plugins = array();
        $plugins        = get_option( 'active_plugins', array() );
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ( (array) $plugins as $plugin_path ) {
            if ( isset( $all_plugins[ $plugin_path ] ) ) {
                $p                = $all_plugins[ $plugin_path ];
                $active_plugins[] = array(
                    'name'    => (string) ( $p['Name'] ?? $plugin_path ),
                    'version' => (string) ( $p['Version'] ?? '' ),
                );
            } else {
                $active_plugins[] = array( 'name' => $plugin_path, 'version' => '' );
            }
        }

        return rest_ensure_response(
            array(
                'environment'    => $environment,
                'tejcart'        => $tejcart,
                'theme'          => $theme_summary,
                'active_plugins' => $active_plugins,
            )
        );
    }

    public function get_tools( WP_REST_Request $request ) {
        $tools = ( new Tools_Page() )->get_tools();
        $items = array();
        foreach ( $tools as $tool ) {
            $items[] = array(
                'id'          => (string) ( $tool['action'] ?? '' ),
                'label'       => (string) ( $tool['label'] ?? '' ),
                'description' => (string) ( $tool['description'] ?? '' ),
                'button'      => (string) ( $tool['button'] ?? '' ),
            );
        }
        return rest_ensure_response( $items );
    }

    public function run_tool( WP_REST_Request $request ) {
        $action = (string) $request['id'];
        $page   = new Tools_Page();
        $valid  = false;
        foreach ( $page->get_tools() as $tool ) {
            if ( ( $tool['action'] ?? '' ) === $action ) {
                $valid = true;
                break;
            }
        }
        if ( ! $valid ) {
            return new WP_Error( 'tejcart_rest_tool_not_found', __( 'Unknown tool.', 'tejcart' ), array( 'status' => 404 ) );
        }

        // Mirror the admin form's explicit-confirmation requirement for
        // destructive actions: even an authorised caller must pass
        // `confirm=yes` so a CSRF-style replay or an accidental call cannot
        // wipe sessions/transients or rewrite roles.
        if ( in_array( $action, Tools_Page::DESTRUCTIVE_ACTIONS, true ) ) {
            $confirm = (string) $request->get_param( 'confirm' );
            if ( 'yes' !== $confirm ) {
                return new WP_Error(
                    'tejcart_rest_confirmation_required',
                    __( 'This maintenance action is destructive and requires confirm=yes.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }
        }

        $result = $page->run_tool( $action );
        if ( ! $result['success'] ) {
            return new WP_Error( 'tejcart_rest_tool_failed', $result['message'], array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'id'      => $action,
            'success' => true,
            'message' => $result['message'],
        ) );
    }

    /**
     * Per-table row count + size summary.
     */
    protected function table_summary(): array {
        global $wpdb;

        $tables = array(
            'tejcart_products',
            'tejcart_product_meta',
            'tejcart_orders',
            'tejcart_order_items',
            'tejcart_order_meta',
            'tejcart_order_refunds',
            'tejcart_customers',
            'tejcart_coupons',
            'tejcart_sessions',
            'tejcart_term_relationships',
            'tejcart_api_keys',
        );

        $out = array();
        foreach ( $tables as $tbl ) {
            $full = $wpdb->prefix . $tbl;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $stats = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT TABLE_ROWS AS rows, ROUND(( DATA_LENGTH + INDEX_LENGTH ) / 1024, 2) AS size_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                    DB_NAME,
                    $full
                )
            );
            $out[ $tbl ] = $stats
                ? array( 'exists' => true, 'rows' => (int) ( $stats->rows ?? 0 ), 'size_kb' => (float) ( $stats->size_kb ?? 0 ) )
                : array( 'exists' => false, 'rows' => 0, 'size_kb' => 0 );
        }
        return $out;
    }

    protected function active_gateways(): array {
        if ( ! function_exists( 'tejcart' ) || ! tejcart()->gateways() ) {
            return array();
        }
        $out = array();
        foreach ( tejcart()->gateways()->get_available_gateways() as $gw ) {
            $out[] = array(
                'id'    => $gw->get_id(),
                'title' => $gw->get_title(),
            );
        }
        return $out;
    }

    protected function shipping_summary(): array {
        $manager = new \TejCart\Shipping\Shipping_Manager();
        $out     = array();
        foreach ( $manager->get_zones() as $zone ) {
            $out[] = array(
                'id'      => (int) ( $zone['id'] ?? 0 ),
                'name'    => (string) ( $zone['name'] ?? '' ),
                'methods' => count( (array) ( $zone['methods'] ?? array() ) ),
            );
        }
        return $out;
    }

    protected function cron_summary(): array {
        $hooks = array(
            'tejcart_cleanup_sessions',
            'tejcart_process_pending_orders',
            'tejcart_send_queued_emails',
            'tejcart_check_expired_coupons',
        );
        $out = array();
        foreach ( $hooks as $hook ) {
            $next      = wp_next_scheduled( $hook );
            $out[ $hook ] = $next ? gmdate( 'c', $next ) : null;
        }
        return $out;
    }
}
