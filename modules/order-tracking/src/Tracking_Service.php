<?php
/**
 * Tracking service — the business-logic layer.
 *
 * All add / update / delete go through this class so:
 *  - Sanitisation is consistent across REST, AJAX, CLI.
 *  - The status state machine is enforced once.
 *  - `do_action()` events fire from a single place (no drift between
 *    AJAX-only and CLI-only side effects).
 *  - The order is auto-transitioned to "shipped" the first time tracking
 *    is added, mirroring the docblock contract that originally claimed
 *    this behaviour but never implemented it.
 *
 * Cents-integer purity note: `cost` is currency-aware DECIMAL on disk,
 * but our public API takes a `Money` value object when available so
 * callers don't deal in floats. Float inputs are accepted on the
 * boundary and immediately normalised.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tracking_Service {
    private Shipment_Repository $repo;

    public function __construct( ?Shipment_Repository $repo = null ) {
        $this->repo = $repo ?? new Shipment_Repository();
    }

    public function repository(): Shipment_Repository {
        return $this->repo;
    }

    /**
     * Add a tracking row to an order.
     *
     * @param array<string, mixed> $data Raw input. See `sanitise_input()` for accepted keys.
     * @return int|\WP_Error  Shipment id on success, WP_Error on validation/DB failure.
     */
    public function add( int $order_id, array $data ): int|\WP_Error {
        if ( $order_id <= 0 ) {
            return new \WP_Error( 'invalid_order', __( 'Invalid order ID.', 'tejcart' ) );
        }

        $clean = $this->sanitise_input( $data );

        if ( '' === $clean['carrier'] || '' === $clean['tracking_number'] ) {
            return new \WP_Error(
                'invalid_payload',
                __( 'Carrier and tracking number are required.', 'tejcart' )
            );
        }
        if ( ! Carriers::exists( $clean['carrier'] ) ) {
            return new \WP_Error(
                'unknown_carrier',
                __( 'Unknown carrier.', 'tejcart' )
            );
        }
        if ( ! Shipment_Status::is_valid( $clean['status'] ) ) {
            return new \WP_Error(
                'invalid_status',
                __( 'Unknown shipment status.', 'tejcart' )
            );
        }

        $clean['order_id']     = $order_id;
        $clean['created_by']   = (int) get_current_user_id();
        $clean['tracking_url'] = '' !== $clean['tracking_url']
            ? $clean['tracking_url']
            : Carriers::build_url( $clean['carrier'], $clean['tracking_number'] );
        if ( '' !== $clean['tracking_url'] ) {
            $clean['tracking_url'] = esc_url_raw( $clean['tracking_url'] );
        }
        if ( '' !== $clean['label_url'] ) {
            $clean['label_url'] = esc_url_raw( $clean['label_url'] );
        }

        // Auto-stamp shipped_at the first time we hit a "shipped" status
        // unless the caller supplied an explicit timestamp.
        if (
            in_array( $clean['status'], array( Shipment_Status::SHIPPED, Shipment_Status::IN_TRANSIT, Shipment_Status::OUT_FOR_DELIVERY ), true )
            && null === $clean['shipped_at']
        ) {
            $clean['shipped_at'] = current_time( 'mysql', true );
        }
        if ( Shipment_Status::DELIVERED === $clean['status'] && null === $clean['delivered_at'] ) {
            $clean['delivered_at'] = current_time( 'mysql', true );
        }

        $shipment_id = $this->repo->insert( $clean );
        if ( is_wp_error( $shipment_id ) ) {
            return $shipment_id;
        }

        /**
         * Fires after a tracking row has been added.
         *
         * @param int   $order_id    Order id.
         * @param int   $shipment_id Shipment id.
         * @param array $data        The sanitised payload that was inserted.
         */
        do_action( 'tejcart_order_tracking_added', $order_id, $shipment_id, $clean );

        $this->maybe_transition_order_to_shipped( $order_id );

        return $shipment_id;
    }

    /**
     * Update an existing shipment. Status changes go through the state
     * machine; non-status updates are merged in directly.
     *
     * @param array<string, mixed> $data Whitelisted keys: carrier, service,
     *                                   tracking_number, tracking_url,
     *                                   label_url, status, cost,
     *                                   shipped_at, delivered_at, meta.
     */
    public function update( int $shipment_id, array $data ): bool|\WP_Error {
        $existing = $this->repo->find( $shipment_id );
        if ( null === $existing ) {
            return new \WP_Error( 'not_found', __( 'Shipment not found.', 'tejcart' ) );
        }
        if ( ! empty( $existing['deleted_at'] ) ) {
            return new \WP_Error( 'gone', __( 'Shipment has been deleted.', 'tejcart' ) );
        }

        $clean = $this->sanitise_input( $data, true );

        if ( isset( $clean['status'] ) ) {
            $from = (string) $existing['status'];
            $to   = $clean['status'];
            if ( $from !== $to ) {
                if ( ! Shipment_Status::is_valid( $to ) ) {
                    return new \WP_Error( 'invalid_status', __( 'Unknown shipment status.', 'tejcart' ) );
                }
                if ( Shipment_Status::is_terminal( $from ) ) {
                    return new \WP_Error(
                        'terminal_status',
                        __( 'This shipment is already in a terminal state.', 'tejcart' )
                    );
                }
                if ( ! Shipment_Status::can_transition( $from, $to ) ) {
                    return new \WP_Error(
                        'invalid_transition',
                        sprintf(
                            /* translators: 1: from-status, 2: to-status */
                            __( 'Cannot transition shipment from %1$s to %2$s.', 'tejcart' ),
                            $from,
                            $to
                        )
                    );
                }
                if ( Shipment_Status::DELIVERED === $to && empty( $clean['delivered_at'] ) ) {
                    $clean['delivered_at'] = current_time( 'mysql', true );
                }
                if (
                    in_array( $to, array( Shipment_Status::SHIPPED, Shipment_Status::IN_TRANSIT ), true )
                    && empty( $existing['shipped_at'] )
                    && empty( $clean['shipped_at'] )
                ) {
                    $clean['shipped_at'] = current_time( 'mysql', true );
                }
            }
        }

        if ( isset( $clean['carrier'] ) && ! Carriers::exists( $clean['carrier'] ) ) {
            return new \WP_Error( 'unknown_carrier', __( 'Unknown carrier.', 'tejcart' ) );
        }

        if ( isset( $clean['tracking_url'] ) && '' !== $clean['tracking_url'] ) {
            $clean['tracking_url'] = esc_url_raw( $clean['tracking_url'] );
        }
        if ( isset( $clean['label_url'] ) && '' !== $clean['label_url'] ) {
            $clean['label_url'] = esc_url_raw( $clean['label_url'] );
        }

        $ok = $this->repo->update( $shipment_id, $clean );
        if ( ! $ok ) {
            return new \WP_Error( 'db_error', __( 'Could not update shipment.', 'tejcart' ) );
        }

        $new = $this->repo->find( $shipment_id );

        /**
         * Fires after a shipment row has been updated.
         *
         * @param int                  $order_id    Order id.
         * @param int                  $shipment_id Shipment id.
         * @param array<string, mixed> $new         New row.
         * @param array<string, mixed> $previous    Previous row.
         */
        do_action( 'tejcart_order_tracking_updated', (int) $existing['order_id'], $shipment_id, $new ?? array(), $existing );

        if ( isset( $clean['status'] ) && (string) $existing['status'] !== $clean['status'] ) {
            /**
             * Fires after a shipment status transition.
             *
             * @param int    $order_id    Order id.
             * @param int    $shipment_id Shipment id.
             * @param string $from        Previous status.
             * @param string $to          New status.
             */
            do_action(
                'tejcart_shipment_status_changed',
                (int) $existing['order_id'],
                $shipment_id,
                (string) $existing['status'],
                $clean['status']
            );
        }

        return true;
    }

    public function delete( int $shipment_id ): bool|\WP_Error {
        $existing = $this->repo->find( $shipment_id );
        if ( null === $existing ) {
            return new \WP_Error( 'not_found', __( 'Shipment not found.', 'tejcart' ) );
        }
        if ( ! empty( $existing['deleted_at'] ) ) {
            return true;
        }
        $ok = $this->repo->delete( $shipment_id );
        if ( ! $ok ) {
            return new \WP_Error( 'db_error', __( 'Could not delete shipment.', 'tejcart' ) );
        }
        /**
         * Fires after a shipment has been soft-deleted.
         *
         * @param int                  $order_id    Order id.
         * @param int                  $shipment_id Shipment id.
         * @param array<string, mixed> $row         Previous row.
         */
        do_action( 'tejcart_order_tracking_deleted', (int) $existing['order_id'], $shipment_id, $existing );
        return true;
    }

    /**
     * Convenience accessor for templates: array of shipments for the
     * given order, decorated with carrier label + URL fallback.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_order( int $order_id ): array {
        $rows = $this->repo->find_for_order( $order_id );
        foreach ( $rows as &$row ) {
            $carrier = (string) ( $row['carrier'] ?? '' );
            $number  = (string) ( $row['tracking_number'] ?? '' );
            if ( empty( $row['tracking_url'] ) ) {
                $row['tracking_url'] = Carriers::build_url( $carrier, $number );
            }
            $row['carrier_label'] = Carriers::label( $carrier );

            // Decode the carrier event timeline from `meta` if present
            // (populated by the polling/webhook pipeline). We only
            // surface the public-safe fields — time, status, message,
            // location — never the raw provider payload.
            $row['events'] = $this->extract_events( $row['meta'] ?? null );
        }
        unset( $row );
        /**
         * Filter the shipment list for an order before it leaves the service layer.
         *
         * @param array<int, array<string, mixed>> $rows
         * @param int                              $order_id
         */
        return (array) apply_filters( 'tejcart_order_get_tracking', $rows, $order_id );
    }

    /**
     * Sanitise a raw payload from REST / AJAX / CLI into a column-aligned
     * array. Unknown keys are dropped. Status defaults to PENDING for new
     * inserts; on partial update the caller filters which columns it
     * passes through.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitise_input( array $data, bool $partial = false ): array {
        $out = array();

        if ( array_key_exists( 'carrier', $data ) || ! $partial ) {
            $raw_carrier    = isset( $data['carrier'] ) ? (string) $data['carrier'] : '';
            // Fold known aliases (e.g. `dhl_express` → `dhl`) so admin
            // free-text and shipping-module-driver ids both reach the
            // same canonical slug.
            $out['carrier'] = '' === $raw_carrier ? '' : Carriers::normalize_slug( $raw_carrier );
        }
        if ( array_key_exists( 'service', $data ) || ! $partial ) {
            $out['service'] = isset( $data['service'] ) ? sanitize_text_field( (string) $data['service'] ) : '';
        }
        if ( array_key_exists( 'tracking_number', $data ) || ! $partial ) {
            $tracking = isset( $data['tracking_number'] ) ? (string) $data['tracking_number'] : '';
            // Tracking numbers are alphanumerics; trim aggressive whitespace
            // and strip control chars but preserve case (some carriers care).
            $tracking = preg_replace( '/[\x00-\x1F\x7F]+/', '', $tracking ) ?? '';
            $out['tracking_number'] = trim( sanitize_text_field( $tracking ) );
        }
        if ( array_key_exists( 'tracking_url', $data ) || ! $partial ) {
            $out['tracking_url'] = isset( $data['tracking_url'] ) ? (string) $data['tracking_url'] : '';
        }
        if ( array_key_exists( 'label_url', $data ) || ! $partial ) {
            $out['label_url'] = isset( $data['label_url'] ) ? (string) $data['label_url'] : '';
        }
        if ( array_key_exists( 'status', $data ) || ! $partial ) {
            $out['status'] = isset( $data['status'] )
                ? sanitize_key( (string) $data['status'] )
                : Shipment_Status::PENDING;
        }
        if ( array_key_exists( 'cost', $data ) || ! $partial ) {
            // Audit M-29: round to the order's currency decimals instead
            // of storing a raw float. Prevents 1.234000000001 drift for
            // KWD (3dp) and JPY (0dp).
            $cost     = isset( $data['cost'] ) ? (float) $data['cost'] : 0.0;
            $currency = isset( $data['currency'] ) ? (string) $data['currency'] : (string) get_option( 'tejcart_currency', 'USD' );
            $decimals = class_exists( '\\TejCart\\Money\\Currency' ) ? \TejCart\Money\Currency::decimals( $currency ) : 2;
            $out['cost'] = round( $cost, $decimals );
        }
        if ( array_key_exists( 'shipped_at', $data ) || ! $partial ) {
            $out['shipped_at'] = $this->normalise_datetime( $data['shipped_at'] ?? null );
        }
        if ( array_key_exists( 'delivered_at', $data ) || ! $partial ) {
            $out['delivered_at'] = $this->normalise_datetime( $data['delivered_at'] ?? null );
        }
        if ( array_key_exists( 'meta', $data ) ) {
            $meta = $data['meta'];
            if ( is_string( $meta ) ) {
                $decoded = json_decode( $meta, true );
                $meta    = is_array( $decoded ) ? $decoded : null;
            }
            $out['meta'] = is_array( $meta ) ? $meta : null;
        }

        return $out;
    }

    /**
     * @param mixed $meta Raw meta value (string JSON, array, or null).
     * @return array<int, array<string, mixed>>
     */
    private function extract_events( mixed $meta ): array {
        if ( null === $meta || '' === $meta ) {
            return array();
        }
        $decoded = is_string( $meta ) ? json_decode( $meta, true ) : $meta;
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        $events = $decoded['provider_status']['events'] ?? array();
        if ( ! is_array( $events ) ) {
            return array();
        }
        $clean = array();
        foreach ( $events as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }
            $clean[] = array(
                'time'     => isset( $ev['time'] )     ? (string) $ev['time']     : null,
                'status'   => isset( $ev['status'] )   ? (string) $ev['status']   : '',
                'message'  => isset( $ev['message'] )  ? (string) $ev['message']  : '',
                'location' => isset( $ev['location'] ) ? (string) $ev['location'] : '',
            );
        }
        return $clean;
    }

    private function normalise_datetime( mixed $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( is_int( $value ) ) {
            return gmdate( 'Y-m-d H:i:s', $value );
        }
        if ( is_string( $value ) ) {
            $ts = strtotime( $value );
            if ( false === $ts ) {
                return null;
            }
            return gmdate( 'Y-m-d H:i:s', $ts );
        }
        return null;
    }

    /**
     * Auto-transition the order to "shipped" the first time tracking is
     * added, but only if the merchant hasn't disabled the behaviour.
     */
    private function maybe_transition_order_to_shipped( int $order_id ): void {
        /**
         * Filter whether adding tracking should auto-advance the order
         * status to "shipped". Defaults true.
         *
         * @param bool $auto_advance
         * @param int  $order_id
         */
        $auto = (bool) apply_filters( 'tejcart_order_tracking_auto_ship_order', true, $order_id );
        if ( ! $auto ) {
            return;
        }
        if ( ! function_exists( 'tejcart_get_order' ) ) {
            return;
        }
        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) || ! method_exists( $order, 'update_status' ) ) {
            return;
        }
        $current = (string) $order->get_status();
        // Only advance from a pre-fulfilment status that still holds the
        // goods. Orders already shipped/delivered/closed (completed,
        // cancelled, refunded, partially-refunded, failed) are left alone —
        // attempting those would just be blocked as invalid transitions and
        // log noise. The edges pending|processing|on-hold → shipped are
        // registered by Order_Statuses::register_transitions().
        if ( ! in_array( $current, array( 'pending', 'processing', 'on-hold' ), true ) ) {
            return;
        }
        $order->update_status( Order_Statuses::SHIPPED, __( 'Tracking added.', 'tejcart' ) );
    }
}
