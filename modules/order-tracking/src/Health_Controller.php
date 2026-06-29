<?php
/**
 * Health-check REST endpoint.
 *
 *   GET /tejcart/v1/tracking/health
 *
 * Returns:
 *   {
 *     "status": "ok|degraded",
 *     "db_version": "2.1.0",
 *     "in_flight": 12,
 *     "delivered_24h": 87,
 *     "failed_pollings_24h": 0,
 *     "providers": { "easypost": { "configured": true } },
 *     "next_poll": 1714838400
 *   }
 *
 * Capability-gated to `tejcart_manage_orders` so we don't expose
 * volume-of-orders data to anonymous scrapers; merchants can ping it
 * from monitoring tools (Datadog, Pingdom) by minting a TejCart API
 * key for that purpose.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

use TejCart\Tier2\Order_Tracking\Providers\Polling_Job;
use TejCart\Tier2\Order_Tracking\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Health_Controller {
    public const NAMESPACE_V1 = 'tejcart/v1';
    public const ROUTE        = '/tracking/health';

    private Provider_Registry $providers;

    public function __construct( Provider_Registry $providers ) {
        $this->providers = $providers;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            self::ROUTE,
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'handle' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                ),
            )
        );
    }

    public function permission_check(): bool|\WP_Error {
        if ( Capability::current_user_can_manage() ) {
            return true;
        }
        return new \WP_Error( 'rest_forbidden', 'Forbidden', array( 'status' => 403 ) );
    }

    public function handle(): \WP_REST_Response {
        return new \WP_REST_Response( $this->snapshot(), 200 );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array {
        global $wpdb;
        $table = Schema_Migrator::table_name();
        $now   = time();
        $day   = $now - DAY_IN_SECONDS;

        $terminal = "'" . implode( "','", array( Shipment_Status::DELIVERED, Shipment_Status::RETURNED ) ) . "'";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; {$terminal} is built from a whitelisted status constants array; no user input.
        $in_flight = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND status NOT IN ({$terminal})" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $delivered_24h = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND delivered_at >= %s", Shipment_Status::DELIVERED, gmdate( 'Y-m-d H:i:s', $day ) ) );

        $providers = array();
        foreach ( $this->providers->all() as $slug => $provider ) {
            $providers[ $slug ] = array(
                'configured' => $provider->is_configured(),
                'label'      => $provider->label(),
            );
        }

        $failed = (int) get_option( Dead_Letter::FAILED_24H_OPTION, 0 );

        $next = null;
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            $next = as_next_scheduled_action( Polling_Job::HOOK, array(), 'tejcart' );
            if ( false === $next ) { $next = null; }
        }

        $status = ( $failed > 50 ) ? 'degraded' : 'ok';

        return array(
            'status'              => $status,
            'db_version'          => get_option( TEJCART_ORDER_TRACKING_DB_VERSION_OPTION, 'unknown' ),
            'in_flight'           => $in_flight,
            'delivered_24h'       => $delivered_24h,
            'failed_pollings_24h' => $failed,
            'providers'           => $providers,
            'next_poll'           => $next,
            'generated_at'        => gmdate( 'c', $now ),
        );
    }
}
