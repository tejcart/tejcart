<?php
/**
 * Application service for purchasing and tracking labels.
 *
 * Wraps a driver's `buy_label()` / `track()` calls with:
 *   - Credentials_Vault lookup
 *   - automatic idempotency-key derivation (so retries can never double-buy)
 *   - Shipment_Repository persistence
 *
 * Drivers that don't yet implement `buy_label()` / `track()` will throw
 * Carrier_Exception via the abstract base — callers should catch it and
 * surface "this carrier doesn't support label purchase yet."
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Label_Service {
    private Carrier_Registry $registry;
    private Credentials_Vault $vault;
    private Shipment_Repository $shipments;

    public function __construct(
        Carrier_Registry $registry,
        Credentials_Vault $vault,
        ?Shipment_Repository $shipments = null
    ) {
        $this->registry  = $registry;
        $this->vault     = $vault;
        $this->shipments = $shipments ?? new Shipment_Repository();
    }

    /**
     * Buy a label for a previously quoted rate. Returns the persisted
     * Shipment (which may be a freshly inserted row or an idempotent
     * replay of a prior call with the same key).
     *
     * @param array<string,mixed> $extra_credentials Driver-specific extras (e.g. shipment_id).
     */
    public function purchase(
        string $driver_id,
        int $order_id,
        string $rate_id,
        string $service_code,
        ?string $idempotency_key = null,
        array $extra_credentials = array()
    ): Shipment {
        $driver = $this->registry->get( $driver_id );
        if ( null === $driver ) {
            throw new Carrier_Exception( sprintf( 'Label_Service: no driver registered for "%s".', esc_html( $driver_id ) ) );
        }

        $key = $idempotency_key ?? $this->derive_idempotency_key( $driver_id, $order_id, $rate_id );

        $existing = $this->shipments->find_by_idempotency( $key );
        if ( null !== $existing ) {
            return $existing;
        }

        $credentials = $this->vault->get( $driver_id );
        $credentials['__idempotency_key'] = $key;
        foreach ( $extra_credentials as $k => $v ) {
            $credentials[ '__' . ltrim( (string) $k, '_' ) ] = (string) $v;
        }

        $label = $driver->buy_label( $rate_id, $credentials );

        $shipment = $this->shipments->record_label(
            $order_id,
            $label,
            $service_code,
            $rate_id,
            $key,
            array(
                'driver_id'        => $driver_id,
                'requested_extras' => array_keys( $extra_credentials ),
            )
        );

        /**
         * Fires after a carrier label has been purchased and persisted.
         *
         * This is the single point of integration between the shipping
         * module (which knows about carriers, rates and labels) and any
         * downstream consumer that wants to track the parcel — most
         * notably the order-tracking module's Shipping_Bridge, which
         * uses this event to auto-create a tracking row so the merchant
         * doesn't have to copy/paste the tracking number across modules.
         *
         * Listeners MUST be tolerant of duplicates: an idempotent retry
         * of `purchase()` short-circuits via the idempotency-key check
         * and re-emits this event for an already-recorded shipment.
         *
         * @param int    $order_id        Order id the label was purchased for.
         * @param string $carrier_id      Shipping driver id (e.g. `fedex`, `dhl_express`).
         * @param string $tracking_number Carrier-assigned tracking number / AWB.
         * @param array{shipment_id:int,driver_id:string,service_code:string,rate_id:string,label_url:string,label_format:string,cost_cents:int,currency:string,idempotency_key:string,status:string,meta:array<string,mixed>} $context Structured handoff payload.
         */
        do_action(
            'tejcart_shipping_label_purchased',
            $shipment->order_id,
            $shipment->carrier_id,
            $shipment->tracking_number,
            array(
                'shipment_id'     => $shipment->id,
                'driver_id'       => $driver_id,
                'service_code'    => $shipment->service_code,
                'rate_id'         => $shipment->rate_id,
                'label_url'       => $shipment->label_url,
                'label_format'    => $shipment->label_format,
                'cost_cents'      => $shipment->cost_cents,
                'currency'        => $shipment->currency,
                'idempotency_key' => $shipment->idempotency_key,
                'status'          => $shipment->status,
                'meta'            => $shipment->meta,
            )
        );

        return $shipment;
    }

    /**
     * Void a previously purchased label and mark the shipment row voided.
     *
     * @return Shipment Updated shipment row.
     */
    public function void( int $shipment_id ): Shipment {
        $shipment = $this->shipments->find_by_id( $shipment_id );
        if ( null === $shipment ) {
            throw new Carrier_Exception( sprintf( 'Label_Service: shipment %d not found.', (int) $shipment_id ) );
        }
        if ( Shipment::STATUS_VOIDED === $shipment->status ) {
            return $shipment;
        }

        $driver = $this->registry->get( $shipment->carrier_id );
        if ( null === $driver ) {
            throw new Carrier_Exception( sprintf( 'Label_Service: no driver registered for "%s".', esc_html( $shipment->carrier_id ) ) );
        }

        // Atomically transition PURCHASED → VOIDED before calling the carrier
        // so two concurrent voids can never both fire the upstream void_label
        // call (some carriers refund both, double-crediting the merchant).
        // The loser of the race observes the now-voided row and returns it.
        if ( ! $this->shipments->claim_void( $shipment->id ) ) {
            $refreshed = $this->shipments->find_by_id( $shipment->id );
            return null === $refreshed ? $shipment : $refreshed;
        }

        $token = $shipment->meta['transaction_id']
            ?? $shipment->meta['shipment_id']
            ?? $shipment->tracking_number;

        // If the carrier call throws, we deliberately do NOT roll the row
        // back — a retry would race with the original attempt and could now
        // genuinely double-void at the carrier. The exception bubbles so the
        // operator can reconcile manually.
        $driver->void_label( (string) $token, $this->vault->get( $shipment->carrier_id ) );

        $refreshed = $this->shipments->find_by_id( $shipment->id );
        return null === $refreshed ? $shipment : $refreshed;
    }

    public function track( string $driver_id, string $tracking_number, ?int $shipment_id = null ): Tracking {
        $driver = $this->registry->get( $driver_id );
        if ( null === $driver ) {
            throw new Carrier_Exception( sprintf( 'Label_Service: no driver registered for "%s".', esc_html( $driver_id ) ) );
        }
        $credentials = $this->vault->get( $driver_id );

        $tracking = $driver->track( $tracking_number, $credentials );

        if ( null !== $shipment_id && $shipment_id > 0 ) {
            $this->shipments->update_tracking( $shipment_id, $tracking );
        }
        return $tracking;
    }

    private function derive_idempotency_key( string $driver_id, int $order_id, string $rate_id ): string {
        return substr( hash( 'sha256', $driver_id . '|' . $order_id . '|' . $rate_id ), 0, 64 );
    }
}
