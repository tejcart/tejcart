<?php
/**
 * Built-in tracking webhook handlers for EasyPost and Shippo.
 *
 * EasyPost:
 *   - `tracker.updated` → update local shipment status by tracking number.
 *   - HMAC-SHA256 signature in `X-Hmac-Signature` (we compare against the
 *     merchant's webhook secret configured on the credentials page).
 *
 * Shippo:
 *   - `track_updated` → update local shipment status.
 *   - No HMAC; Shippo recommends an unguessable URL token. We verify a
 *     `?token=...` query string against a stored secret instead.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tracking_Webhook_Handlers {
    private Shipment_Repository $shipments;

    public function __construct( ?Shipment_Repository $shipments = null ) {
        $this->shipments = $shipments ?? new Shipment_Repository();
    }

    public function register( Credentials_Vault $vault ): void {
        if ( ! function_exists( 'add_filter' ) ) {
            return;
        }
        add_filter( 'tejcart_shipping_webhook_handlers', function ( $handlers ) use ( $vault ) {
            if ( ! is_array( $handlers ) ) {
                $handlers = array();
            }
            $handlers['easypost'] = function ( $request ) use ( $vault ) {
                return $this->handle_easypost( $request, $vault );
            };
            $handlers['shippo'] = function ( $request ) use ( $vault ) {
                return $this->handle_shippo( $request, $vault );
            };
            return $handlers;
        } );
    }

    public function handle_easypost( $request, Credentials_Vault $vault ) {
        $body          = is_object( $request ) && method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
        $signature_hdr = is_object( $request ) && method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'x_hmac_signature' ) : '';
        $secret        = (string) ( $vault->get( 'easypost' )['webhook_secret'] ?? '' );

        // Fail closed: a missing or empty webhook secret means the endpoint
        // cannot authenticate the upstream. Accepting unsigned events would
        // let any anonymous caller move shipment statuses around (e.g. flip
        // an order to "delivered" before the package leaves the warehouse).
        if ( '' === $secret ) {
            return new \WP_Error(
                'tejcart_shipping_webhook_not_configured',
                'EasyPost webhook secret is not configured.',
                array( 'status' => 401 )
            );
        }

        $expected = hash_hmac( 'sha256', $body, $secret );
        if ( '' === $signature_hdr || ! hash_equals( $expected, $signature_hdr ) ) {
            return new \WP_Error( 'tejcart_shipping_webhook_signature', 'Invalid HMAC signature.', array( 'status' => 401 ) );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new \WP_Error( 'tejcart_shipping_webhook_body', 'Malformed JSON.', array( 'status' => 400 ) );
        }

        // De-dup by event id. EasyPost re-delivers the same event on a
        // retry loop until we 2xx — without this guard a network blip
        // turns into a flapping status update.
        $event_id = (string) ( $decoded['id'] ?? '' );
        if ( '' !== $event_id && $this->event_already_processed( 'easypost', $event_id ) ) {
            return array( 'ok' => true, 'duplicate' => $event_id );
        }

        $description = (string) ( $decoded['description'] ?? '' );
        if ( 'tracker.updated' !== $description ) {
            // Acknowledge but ignore — not a tracker event.
            if ( '' !== $event_id ) {
                $this->mark_event_processed( 'easypost', $event_id );
            }
            return array( 'ok' => true, 'ignored' => $description );
        }

        $result = is_array( $decoded['result'] ?? null ) ? $decoded['result'] : array();
        $tn     = (string) ( $result['tracking_code'] ?? '' );
        $st     = $this->normalise_easypost_status( (string) ( $result['status'] ?? '' ) );

        $this->update_by_tracking_number( $tn, $st );
        if ( '' !== $event_id ) {
            $this->mark_event_processed( 'easypost', $event_id );
        }
        return array( 'ok' => true, 'tracking' => $tn, 'status' => $st );
    }

    public function handle_shippo( $request, Credentials_Vault $vault ) {
        $body  = is_object( $request ) && method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
        $token = is_object( $request ) && method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'token' ) : '';
        $secret = (string) ( $vault->get( 'shippo' )['webhook_token'] ?? '' );

        if ( '' === $secret ) {
            return new \WP_Error(
                'tejcart_shipping_webhook_not_configured',
                'Shippo webhook token is not configured.',
                array( 'status' => 401 )
            );
        }
        if ( '' === $token || ! hash_equals( $secret, $token ) ) {
            return new \WP_Error( 'tejcart_shipping_webhook_token', 'Invalid webhook token.', array( 'status' => 401 ) );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new \WP_Error( 'tejcart_shipping_webhook_body', 'Malformed JSON.', array( 'status' => 400 ) );
        }

        $data     = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array();
        $event_id = (string) ( $decoded['event_id'] ?? ( $data['object_id'] ?? '' ) );
        if ( '' !== $event_id && $this->event_already_processed( 'shippo', $event_id ) ) {
            return array( 'ok' => true, 'duplicate' => $event_id );
        }

        $event = (string) ( $decoded['event'] ?? '' );
        if ( 'track_updated' !== $event ) {
            if ( '' !== $event_id ) {
                $this->mark_event_processed( 'shippo', $event_id );
            }
            return array( 'ok' => true, 'ignored' => $event );
        }

        $tracking_status = is_array( $data['tracking_status'] ?? null ) ? $data['tracking_status'] : array();
        $tn              = (string) ( $data['tracking_number'] ?? '' );
        $st              = $this->normalise_shippo_status( (string) ( $tracking_status['status'] ?? '' ) );

        $this->update_by_tracking_number( $tn, $st );
        if ( '' !== $event_id ) {
            $this->mark_event_processed( 'shippo', $event_id );
        }
        return array( 'ok' => true, 'tracking' => $tn, 'status' => $st );
    }

    /**
     * Has this carrier+event already been processed in the recent past?
     * Keyed by a SHA-256 hash so a malformed event id can't construct a
     * pathological transient key.
     */
    private function event_already_processed( string $carrier, string $event_id ): bool {
        if ( ! function_exists( 'get_transient' ) ) {
            return false;
        }
        return false !== get_transient( $this->event_marker_key( $carrier, $event_id ) );
    }

    private function mark_event_processed( string $carrier, string $event_id ): void {
        if ( ! function_exists( 'set_transient' ) ) {
            return;
        }
        // 24 h matches the longest carrier retry window (EasyPost ~24 h,
        // Shippo ~24 h). Long enough to absorb retry storms, short enough
        // that the transient table stays bounded.
        $ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
        set_transient( $this->event_marker_key( $carrier, $event_id ), 1, $ttl );
    }

    private function event_marker_key( string $carrier, string $event_id ): string {
        return 'tejcart_ship_evt_' . substr( hash( 'sha256', $carrier . '|' . $event_id ), 0, 32 );
    }

    private function update_by_tracking_number( string $tracking_number, string $status ): void {
        if ( '' === $tracking_number ) {
            return;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
            return;
        }
        $schema = new Schema();
        $table  = $schema->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $sql = $wpdb->prepare( "SELECT id, carrier_id FROM {$table} WHERE tracking_number = %s LIMIT 1", $tracking_number );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; prepared above.
        $row = $wpdb->get_row( $sql, \ARRAY_A );
        if ( ! is_array( $row ) ) {
            return;
        }
        $shipment_id = (int) ( $row['id'] ?? 0 );
        $carrier_id  = (string) ( $row['carrier_id'] ?? '' );
        $this->shipments->update_tracking(
            $shipment_id,
            new Tracking( $carrier_id, $tracking_number, $status )
        );
    }

    private function normalise_easypost_status( string $raw ): string {
        return match ( strtolower( $raw ) ) {
            'pre_transit'      => Tracking::STATUS_PRE_TRANSIT,
            'in_transit'       => Tracking::STATUS_IN_TRANSIT,
            'out_for_delivery' => Tracking::STATUS_OUT_FOR_DELIVERY,
            'delivered'        => Tracking::STATUS_DELIVERED,
            'returned'         => Tracking::STATUS_RETURNED,
            'failure',
            'error',
            'exception'        => Tracking::STATUS_EXCEPTION,
            default            => Tracking::STATUS_UNKNOWN,
        };
    }

    private function normalise_shippo_status( string $raw ): string {
        return match ( strtoupper( $raw ) ) {
            'PRE_TRANSIT' => Tracking::STATUS_PRE_TRANSIT,
            'TRANSIT'     => Tracking::STATUS_IN_TRANSIT,
            'DELIVERED'   => Tracking::STATUS_DELIVERED,
            'RETURNED'    => Tracking::STATUS_RETURNED,
            'FAILURE'     => Tracking::STATUS_EXCEPTION,
            default       => Tracking::STATUS_UNKNOWN,
        };
    }
}
