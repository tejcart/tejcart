<?php
/**
 * Async worker for PayPal webhook events (C-2).
 *
 * The webhook REST handler claims the event, persists the raw payload to
 * `wp_tejcart_paypal_events`, returns 200 OK to PayPal immediately, and
 * dispatches an Action Scheduler job (this worker) to do the heavy
 * fulfilment work out-of-band. PayPal's 25s response window is no longer
 * coupled to our in-process work latency.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PayPal_Event_Worker {

    public const PROCESS_HOOK = 'tejcart_paypal_process_webhook_event';

    /** Max in-band attempts before the row is moved to dead_letter status. */
    public const MAX_ATTEMPTS = 5;

    public function init(): void {
        add_action( self::PROCESS_HOOK, array( $this, 'process_event_row' ), 10, 1 );
    }

    /**
     * Hydrate the raw payload and re-enter PayPal_Webhook::process_event.
     *
     * @param int $row_id `wp_tejcart_paypal_events.id` of the pending row.
     */
    public function process_event_row( $row_id ): void {
        global $wpdb;
        $row_id = (int) $row_id;
        if ( $row_id <= 0 ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT id, event_id, event_type, payload, status, attempts FROM {$wpdb->prefix}tejcart_paypal_events WHERE id = %d",
                $row_id
            ),
            ARRAY_A
        );
        if ( ! $row || 'pending' !== ( $row['status'] ?? '' ) ) {
            return;
        }

        $payload = json_decode( (string) $row['payload'], true );
        if ( ! is_array( $payload ) ) {
            $this->mark_dead_letter( $row_id, 'malformed payload', (string) ( $row['event_id'] ?? '' ) );
            return;
        }

        $webhook = new PayPal_Webhook( PayPal_Gateway::get_shared_instance() );
        try {
            $result = $webhook->process_event( $payload );
        } catch ( \Throwable $e ) {
            $this->mark_failure( $row_id, (int) $row['attempts'], 'exception: ' . $e->getMessage(), (string) ( $row['event_id'] ?? '' ) );
            return;
        }

        if ( is_wp_error( $result ) ) {
            // P-H3: a permanent amount / currency mismatch is terminal,
            // not transient. handle_capture_completed has already moved
            // the order to on-hold for manual review, so the event IS
            // handled — retrying it 5× changes nothing and ends in a
            // false `critical` dead-letter alert. Recognise the two
            // mismatch error codes as non-retryable: record the outcome
            // on the row and stop, without rescheduling or escalating.
            if ( $this->is_non_retryable_error( $result ) ) {
                $this->mark_non_retryable( $row_id, 'wp_error (terminal): ' . $result->get_error_message() );
                return;
            }
            $this->mark_failure( $row_id, (int) $row['attempts'], 'wp_error: ' . $result->get_error_message(), (string) ( $row['event_id'] ?? '' ) );
            return;
        }

        $this->mark_success( $row_id );
    }

    /**
     * Whether a WP_Error from process_event represents a permanent,
     * non-retryable condition. The order has already been side-effected
     * (e.g. moved to on-hold) so further retries are pointless.
     */
    private function is_non_retryable_error( \WP_Error $error ): bool {
        $terminal = array(
            'tejcart_paypal_amount_mismatch',
            'tejcart_paypal_currency_mismatch',
        );
        return in_array( $error->get_error_code(), $terminal, true );
    }

    /**
     * Mark a row as terminally handled after a non-retryable error.
     *
     * Stored as `processed` (the event WAS handled — the order is on-hold
     * awaiting a human) so it leaves the pending queue without triggering
     * the dead-letter `critical` path. The reason is preserved in
     * `last_error` for the audit trail and logged once at `warning`.
     */
    private function mark_non_retryable( int $row_id, string $error ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            "{$wpdb->prefix}tejcart_paypal_events",
            array(
                'status'       => 'processed',
                'last_error'   => substr( $error, 0, 65535 ),
                'processed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $row_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        tejcart_log( sprintf( 'PayPal event %d resolved as non-retryable (order placed on-hold for manual review): %s', $row_id, $error ), 'warning' );
    }

    private function mark_success( int $row_id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            "{$wpdb->prefix}tejcart_paypal_events",
            array(
                'status'       => 'processed',
                'processed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $row_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    private function mark_failure( int $row_id, int $attempts, string $error, string $event_id = '' ): void {
        global $wpdb;
        $next_attempts = $attempts + 1;
        $status        = $next_attempts >= self::MAX_ATTEMPTS ? 'dead_letter' : 'pending';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            "{$wpdb->prefix}tejcart_paypal_events",
            array(
                'status'     => $status,
                'attempts'   => $next_attempts,
                'last_error' => substr( $error, 0, 65535 ),
            ),
            array( 'id' => $row_id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( 'dead_letter' === $status ) {
            tejcart_log( sprintf( 'PayPal event %d moved to dead_letter after %d attempts: %s', $row_id, $next_attempts, $error ), 'critical' );
            // Audit H-11 (PPCP F-003): release the dedup claim so
            // PayPal retries can be accepted after the dead-letter.
            // Without this, the claim was held for 4 days and every
            // PayPal redelivery hit the duplicate short-circuit.
            self::release_claim( $event_id );
            do_action( 'tejcart_paypal_event_dead_letter', $row_id, $error );
            return;
        }

        // Retry with jittered exponential backoff (2m, 5m, 15m, 60m).
        $delays = array( 120, 300, 900, 3600 );
        $delay  = $delays[ min( $next_attempts - 1, count( $delays ) - 1 ) ];
        $delay += function_exists( 'wp_rand' ) ? wp_rand( 0, (int) ( $delay * 0.25 ) ) : 0;
        \TejCart\Core\Action_Scheduler::instance()->schedule_single(
            time() + $delay,
            self::PROCESS_HOOK,
            array( $row_id )
        );
    }

    private function mark_dead_letter( int $row_id, string $error, string $event_id = '' ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            "{$wpdb->prefix}tejcart_paypal_events",
            array(
                'status'     => 'dead_letter',
                'last_error' => substr( $error, 0, 65535 ),
            ),
            array( 'id' => $row_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        tejcart_log( sprintf( 'PayPal event %d marked dead_letter: %s', $row_id, $error ), 'critical' );
        // Audit H-11 (PPCP F-003): see mark_failure() comment.
        self::release_claim( $event_id );
        do_action( 'tejcart_paypal_event_dead_letter', $row_id, $error );
    }

    /**
     * Release the dedup claim for a PayPal event ID.
     *
     * Mirrors `PayPal_Webhook::release_event_claim()` — duplicated
     * here so the worker (which runs out-of-band via Action Scheduler)
     * can release without instantiating the full webhook handler.
     *
     * Called when a row transitions to dead_letter so PayPal's next
     * retry gets a fresh processing attempt instead of silently
     * hitting the duplicate short-circuit and returning 200 OK.
     */
    private static function release_claim( string $event_id ): void {
        if ( '' === $event_id ) {
            return;
        }
        $hash = hash( 'sha256', $event_id );
        if ( class_exists( \TejCart\Core\Lock::class ) ) {
            \TejCart\Core\Lock::release( 'wh_' . $hash );
        }
        delete_option( 'tejcart_wh_' . $hash );
    }
}
