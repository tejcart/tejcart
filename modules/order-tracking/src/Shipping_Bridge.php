<?php
/**
 * Bridge between the bundled shipping module and order-tracking.
 *
 * The shipping module owns label purchase: it talks to FedEx / UPS /
 * USPS / DHL / EasyPost / Shippo, buys a label, and persists the
 * resulting shipment row in its OWN `wp_tejcart_shipments` table.
 * Order-tracking owns the customer-facing tracking story: it polls
 * providers, receives carrier webhooks, runs the status state machine,
 * and renders the timeline on the order page.
 *
 * Before this bridge existed the only handoff was "merchant copies the
 * tracking number from the Shipments admin panel and pastes it into the
 * Order Tracking metabox" — viable at low volume, impossible at
 * thousands of orders per day. This class subscribes to the
 * `tejcart_shipping_label_purchased` action fired by
 * {@see \TejCart\Shipping_Plugin\Core\Label_Service::purchase()} and
 * forwards the data to {@see Tracking_Service::add()} so a tracking row
 * appears automatically the instant a label is bought.
 *
 * Carrier slug fold-in: the shipping driver emits its own driver id
 * (`fedex`, `dhl_express`, ...) which doesn't always match the canonical
 * slug order-tracking and the EasyPost / Shippo / AfterShip providers
 * agree on (`dhl`). We pipe the incoming carrier through
 * {@see Carriers::normalize_slug()} so a future webhook can resolve the
 * shipment without surprises.
 *
 * Idempotency: `Tracking_Service::add()` is protected by the table's
 * `UNIQUE (order_id, tracking_number)` index. A retried label-purchase
 * call (same idempotency key on the shipping side) re-emits the event
 * for the same tracking number; the duplicate insert is caught and we
 * silently move on.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shipping_Bridge {
    public const HOOK = 'tejcart_shipping_label_purchased';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        // Priority 10, 4 args — matches the upstream signature.
        add_action( self::HOOK, array( $this, 'on_label_purchased' ), 10, 4 );
    }

    /**
     * @param int                  $order_id
     * @param string               $carrier_id      Shipping-side driver id.
     * @param string               $tracking_number Carrier tracking / AWB number.
     * @param array<string, mixed> $context         Structured handoff payload from Label_Service.
     */
    public function on_label_purchased( $order_id, $carrier_id, $tracking_number, $context = array() ): void {
        $order_id        = (int) $order_id;
        $tracking_number = trim( (string) $tracking_number );
        if ( $order_id <= 0 || '' === $tracking_number ) {
            return;
        }

        $canonical = Carriers::normalize_slug( (string) $carrier_id );
        if ( '' === $canonical || ! Carriers::exists( $canonical ) ) {
            // Surface for observability — the shipping side emitted a
            // carrier we don't have a public tracking-URL template for.
            // Merchants can teach us about it via the
            // `tejcart_order_tracking_carriers` /
            // `tejcart_order_tracking_carrier_aliases` filters.
            do_action(
                'tejcart_order_tracking_bridge_unknown_carrier',
                $order_id,
                (string) $carrier_id,
                $tracking_number
            );
            return;
        }

        $ctx = is_array( $context ) ? $context : array();

        $payload = array(
            'carrier'         => $canonical,
            'tracking_number' => $tracking_number,
            'service'         => (string) ( $ctx['service_code'] ?? '' ),
            'label_url'       => (string) ( $ctx['label_url']    ?? '' ),
            'status'          => Shipment_Status::LABEL_CREATED,
            // Carry forward useful provenance so the audit log can
            // reconcile a tracking row back to the originating label.
            'meta'            => array(
                'origin'             => 'shipping_module',
                'shipping' => array(
                    'shipment_id'     => (int)    ( $ctx['shipment_id']     ?? 0 ),
                    'driver_id'       => (string) ( $ctx['driver_id']       ?? (string) $carrier_id ),
                    'service_code'    => (string) ( $ctx['service_code']    ?? '' ),
                    'rate_id'         => (string) ( $ctx['rate_id']         ?? '' ),
                    'label_format'    => (string) ( $ctx['label_format']    ?? '' ),
                    'cost_cents'      => (int)    ( $ctx['cost_cents']      ?? 0 ),
                    'currency'        => (string) ( $ctx['currency']        ?? '' ),
                    'idempotency_key' => (string) ( $ctx['idempotency_key'] ?? '' ),
                ),
            ),
        );

        $result = $this->service->add( $order_id, $payload );

        if ( $result instanceof \WP_Error ) {
            // `duplicate_tracking` is expected on an idempotent retry of
            // Label_Service::purchase() — log nothing, swallow it. Other
            // errors (unknown carrier, invalid status) shouldn't reach
            // here because we pre-validated above, but surface them for
            // operators rather than swallowing silently.
            if ( 'duplicate_tracking' !== $result->get_error_code() ) {
                do_action(
                    'tejcart_order_tracking_bridge_failed',
                    $order_id,
                    $canonical,
                    $tracking_number,
                    $result
                );
            }
            return;
        }

        do_action(
            'tejcart_order_tracking_bridge_added',
            $order_id,
            (int) $result,
            $canonical,
            $tracking_number,
            $ctx
        );
    }
}
