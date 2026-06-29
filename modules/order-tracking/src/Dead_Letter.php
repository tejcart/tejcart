<?php
/**
 * Dead-letter tracking for repeatedly-failing carrier polls.
 *
 * Without this, a permanently broken tracking number (carrier never
 * acknowledges it, or auth-rotated and the merchant didn't update the
 * settings) gets retried on every poll tick forever and floods logs.
 *
 * Strategy:
 *  - Listen on `tejcart_order_tracking_poll_failed`. Increment a
 *    per-shipment failure counter (option-stored).
 *  - When the counter exceeds the threshold (default 5), emit
 *    `tejcart_order_tracking_dead_letter` and add the shipment to a
 *    skip list — `Polling_Job` checks the list and skips dead-lettered
 *    rows on subsequent ticks.
 *  - Successful polls (any successful update on the row) reset the
 *    counter. Operators can reset manually via the "Re-poll" button or
 *    `wp tejcart tracking repoll <id>`.
 *
 * Also maintains the rolling 24h failed-polls counter that feeds the
 * health endpoint.
 *
 * Storage: a single option keyed by shipment id. For very large
 * stores this becomes ~1KB per dead-lettered row, capped by the count
 * threshold being low. If it ever became a problem we'd promote it to
 * a column on the shipments table — overkill for v1.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dead_Letter {
    public const OPTION_KEY        = 'tejcart_order_tracking_dead_letter';
    public const FAILED_24H_OPTION = 'tejcart_order_tracking_failed_24h';
    public const DEFAULT_THRESHOLD = 5;

    public function register(): void {
        add_action( 'tejcart_order_tracking_poll_failed', array( $this, 'on_poll_failed' ), 10, 3 );
        add_action( 'tejcart_order_tracking_updated',     array( $this, 'on_update' ), 10, 4 );
    }

    /**
     * @param int       $shipment_id
     * @param string    $carrier
     * @param \WP_Error $error
     */
    public function on_poll_failed( $shipment_id, $carrier, $error ): void {
        $shipment_id = (int) $shipment_id;
        if ( $shipment_id <= 0 ) {
            return;
        }

        $state                          = $this->load();
        $entry                          = $state[ $shipment_id ] ?? array( 'count' => 0, 'last_error' => '', 'last_at' => 0, 'dead' => false );
        $entry['count']                 = ( (int) $entry['count'] ) + 1;
        $entry['last_error']            = $error instanceof \WP_Error ? $error->get_error_code() : '';
        $entry['last_at']               = time();
        if ( $entry['count'] >= $this->threshold() && empty( $entry['dead'] ) ) {
            $entry['dead'] = true;
            do_action( 'tejcart_order_tracking_dead_letter', $shipment_id, $carrier, $entry );
        }
        $state[ $shipment_id ] = $entry;

        // Bound the option size — never let it grow past 5000 entries.
        if ( count( $state ) > 5000 ) {
            $state = array_slice( $state, -5000, null, true );
        }

        $this->save( $state );

        $this->bump_24h_counter();
    }

    /**
     * @param int                  $order_id
     * @param int                  $shipment_id
     * @param array<string, mixed> $new_row
     * @param array<string, mixed> $old_row
     */
    public function on_update( $order_id, $shipment_id, $new_row, $old_row ): void {
        $shipment_id = (int) $shipment_id;
        if ( $shipment_id <= 0 ) {
            return;
        }
        $state = $this->load();
        if ( isset( $state[ $shipment_id ] ) ) {
            unset( $state[ $shipment_id ] );
            $this->save( $state );
        }
    }

    public function is_dead( int $shipment_id ): bool {
        $state = $this->load();
        return ! empty( $state[ $shipment_id ]['dead'] );
    }

    /**
     * Return the raw dead-letter entry for a shipment, or null when none
     * has been recorded. Used by the GDPR exporter to surface tracking
     * failure context as part of a subject-access response.
     *
     * @return array{count:int,last_error:string,last_at:int,dead:bool}|null
     */
    public function entry( int $shipment_id ): ?array {
        $state = $this->load();
        if ( ! isset( $state[ $shipment_id ] ) || ! is_array( $state[ $shipment_id ] ) ) {
            return null;
        }
        $entry = $state[ $shipment_id ];
        return array(
            'count'      => (int) ( $entry['count']      ?? 0 ),
            'last_error' => (string) ( $entry['last_error'] ?? '' ),
            'last_at'    => (int) ( $entry['last_at']    ?? 0 ),
            'dead'       => (bool) ( $entry['dead']      ?? false ),
        );
    }

    public function reset( int $shipment_id ): void {
        $state = $this->load();
        if ( isset( $state[ $shipment_id ] ) ) {
            unset( $state[ $shipment_id ] );
            $this->save( $state );
        }
    }

    /**
     * @return array<int, array{count:int,last_error:string,last_at:int,dead:bool}>
     */
    private function load(): array {
        $state = get_option( self::OPTION_KEY, array() );
        return is_array( $state ) ? $state : array();
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private function save( array $state ): void {
        update_option( self::OPTION_KEY, $state, false );
    }

    private function threshold(): int {
        /** @var int */
        return max( 1, (int) apply_filters( 'tejcart_order_tracking_dead_letter_threshold', self::DEFAULT_THRESHOLD ) );
    }

    /**
     * Maintains a 24-hour rolling counter of poll failures for the
     * health endpoint. Stores `[count, window_start]`; when the
     * window slides past 24h we reset to 1.
     */
    private function bump_24h_counter(): void {
        $state = get_option( self::FAILED_24H_OPTION, 0 );
        $meta  = get_option( self::FAILED_24H_OPTION . '_meta', array( 'window_start' => 0 ) );
        $now   = time();
        if ( ! is_array( $meta ) || ! isset( $meta['window_start'] ) || ( $now - (int) $meta['window_start'] ) > DAY_IN_SECONDS ) {
            update_option( self::FAILED_24H_OPTION, 1, false );
            update_option( self::FAILED_24H_OPTION . '_meta', array( 'window_start' => $now ), false );
            return;
        }
        update_option( self::FAILED_24H_OPTION, ( (int) $state ) + 1, false );
    }
}
