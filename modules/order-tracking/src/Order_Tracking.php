<?php
/**
 * Order tracking — plugin orchestrator and backwards-compat facade.
 *
 * This used to be a single 200-LOC static class doing carrier table,
 * AJAX, public lookup, validation, and DB I/O all in one. The 1.0.0
 * release split that into a Repository / Service / Carriers / Status /
 * REST / AJAX / CLI / Privacy stack — see those classes for the actual
 * implementation. This file now just wires them together and exposes
 * a small static surface for legacy callers and templates.
 *
 * Public extension points:
 *
 *  Filters:
 *   - tejcart_order_tracking_carriers       → carrier registry
 *   - tejcart_order_tracking_capability     → required cap (default `tejcart_manage_orders`)
 *   - tejcart_order_tracking_auto_ship_order → auto-advance order to "shipped" when tracking is added (default true)
 *   - tejcart_order_get_tracking            → list of shipments for an order (used by templates)
 *   - tejcart_shipment_status_transitions   → state-machine graph
 *
 *  Actions:
 *   - tejcart_order_tracking_added           ($order_id, $shipment_id, $data)
 *   - tejcart_order_tracking_updated         ($order_id, $shipment_id, $new_row, $old_row)
 *   - tejcart_order_tracking_deleted         ($order_id, $shipment_id, $row)
 *   - tejcart_shipment_status_changed        ($order_id, $shipment_id, $from, $to)
 *   - tejcart_order_tracking_order_cancelled ($order_id, $new_status)
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Tracking {
    private static ?Tracking_Service $service = null;
    private static ?\TejCart\Tier2\Order_Tracking\Providers\Provider_Registry $providers = null;

    public static function init(): void {
        $service   = self::service();
        $providers = self::providers();

        // Auto-register the EasyPost reference driver. Sites that don't
        // configure the API key get an unconfigured provider (skipped
        // silently by the polling job). Sites that want to swap in a
        // different driver can unregister or replace via the
        // `tejcart_order_tracking_providers` filter.
        $providers->register( new \TejCart\Tier2\Order_Tracking\Providers\EasyPost_Provider() );
        $providers->register( new \TejCart\Tier2\Order_Tracking\Providers\Shippo_Provider() );
        $providers->register( new \TejCart\Tier2\Order_Tracking\Providers\AfterShip_Provider() );

        // Register the `shipped` / `delivered` order statuses with core
        // before anything tries to transition an order into them. Must run
        // in every context (checkout, webhooks, CLI) — not just admin.
        ( new Order_Statuses() )->register();

        ( new Ajax_Controller( $service ) )->register();
        ( new REST_Controller( $service ) )->register();
        ( new Privacy( $service ) )->register();
        ( new Order_Status_Listener( $service ) )->register();
        ( new Emails( $service ) )->register();
        ( new Audit_Log() )->register();
        ( new Dead_Letter() )->register();
        ( new Health_Controller( $providers ) )->register();
        ( new \TejCart\Tier2\Order_Tracking\Providers\Polling_Job( $service, $providers ) )->register();
        ( new \TejCart\Tier2\Order_Tracking\Providers\Webhook_Receiver( $service, $providers ) )->register();
        ( new Retention_Cron( $service->repository() ) )->register();
        // Auto-ingest labels purchased by the bundled shipping module so
        // a merchant doesn't have to copy/paste tracking numbers across
        // admin screens. See Shipping_Bridge for the contract.
        ( new Shipping_Bridge( $service ) )->register();

        if ( is_admin() ) {
            ( new Admin_Metabox( $service ) )->register();
            $tools    = new Admin_Tools( $service );
            $settings = new Settings( $providers );
            $settings->set_tools( $tools );
            $settings->register();
            $tools->register();
            ( new Orders_Column( $service ) )->register();
        } else {
            $shortcode = new \TejCart\Tier2\Order_Tracking\Frontend\Shortcode();
            $shortcode->register();
            ( new \TejCart\Tier2\Order_Tracking\Frontend\Block( $shortcode ) )->register();
            ( new \TejCart\Tier2\Order_Tracking\Frontend\Customer_Order_View( $service ) )->register();
        }

        add_filter( 'tejcart_order_get_tracking', array( __CLASS__, 'filter_get_for_order' ), 10, 2 );
    }

    public static function providers(): \TejCart\Tier2\Order_Tracking\Providers\Provider_Registry {
        if ( null === self::$providers ) {
            self::$providers = new \TejCart\Tier2\Order_Tracking\Providers\Provider_Registry();
        }
        return self::$providers;
    }

    /**
     * Test-only seam.
     */
    public static function set_providers( ?\TejCart\Tier2\Order_Tracking\Providers\Provider_Registry $providers ): void {
        self::$providers = $providers;
    }

    public static function service(): Tracking_Service {
        if ( null === self::$service ) {
            self::$service = new Tracking_Service();
        }
        return self::$service;
    }

    /**
     * Test-only seam to inject a mock service.
     */
    public static function set_service( ?Tracking_Service $service ): void {
        self::$service = $service;
    }

    // ---- Backwards-compat static facade -------------------------------

    /** @return array<string, array{label:string,url:string}> */
    public static function carriers(): array {
        return Carriers::all();
    }

    public static function build_tracking_url( string $carrier, string $tracking ): string {
        return Carriers::build_url( $carrier, $tracking );
    }

    /**
     * @param array<string, mixed> $data
     * @return int|\WP_Error
     */
    public static function add_tracking( int|string $order_id, array $data ): int|\WP_Error {
        return self::service()->add( (int) $order_id, $data );
    }

    public static function delete_tracking( int|string $shipment_id ): bool {
        $result = self::service()->delete( (int) $shipment_id );
        return ! is_wp_error( $result ) && false !== $result;
    }

    /**
     * Filter callback used by templates that pull the shipment list.
     *
     * Signature mirrors the legacy filter: ( $existing, $order_id ).
     * Any array — including an empty one — is passed through unchanged.
     * The service itself fires this filter with the looked-up rows, so
     * recursing on an empty array would re-enter `Tracking_Service::for_order()`
     * for every order without shipments and blow the stack. Legacy
     * callers that want a service lookup invoke the filter with `null`
     * (or omit the initial value entirely).
     *
     * @param mixed $existing
     */
    public static function filter_get_for_order( $existing, int|string $order_id ): array {
        if ( is_array( $existing ) ) {
            return $existing;
        }
        return self::service()->for_order( (int) $order_id );
    }

    /**
     * Direct accessor preserved for legacy callers.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_for_order( $existing, int|string $order_id ): array {
        return self::filter_get_for_order( $existing, $order_id );
    }
}
