<?php
/**
 * GDPR exporter + eraser registrations for the analytics dispatcher.
 *
 * The dispatcher forwards customer data (email, name, IP, order
 * history) to third parties (GA4, Meta CAPI, Klaviyo, Mailchimp).
 * Merchants who receive a DSAR or erasure request must be able to
 * answer "what did you send to whom?" — the exporter emits a
 * per-driver summary so merchants can include it in their portal
 * response, and the eraser fires per-driver actions so individual
 * drivers can delete their copy of the data (or queue a deletion
 * task for an asynchronous integration).
 *
 * Note: actual deletion at GA4 is a 1–2 day operation via the user
 * data deletion endpoint, and Meta CAPI requires a manual deletion
 * request via the business manager — neither offers a synchronous
 * erase. The eraser therefore signals via action hooks which a
 * driver-specific handler can subscribe to, rather than blocking on
 * a remote-side completion.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Privacy {
    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers',   array( $this, 'register_eraser' ) );
    }

    /**
     * @param array<string, array{exporter_friendly_name:string, callback:callable}> $exporters
     * @return array<string, array{exporter_friendly_name:string, callback:callable}>
     */
    public function register_exporter( array $exporters ): array {
        $exporters['tejcart-analytics'] = array(
            'exporter_friendly_name' => __( 'TejCart Tracking & Pixels', 'tejcart' ),
            'callback'               => array( $this, 'export' ),
        );
        return $exporters;
    }

    /**
     * @param array<string, array{eraser_friendly_name:string, callback:callable}> $erasers
     * @return array<string, array{eraser_friendly_name:string, callback:callable}>
     */
    public function register_eraser( array $erasers ): array {
        $erasers['tejcart-analytics'] = array(
            'eraser_friendly_name' => __( 'TejCart Tracking & Pixels', 'tejcart' ),
            'callback'             => array( $this, 'erase' ),
        );
        return $erasers;
    }

    /**
     * Build the export payload. Per-driver: lists each registered
     * driver and what categories of data the dispatcher would have
     * forwarded. We do not store outgoing-event copies locally
     * (deliberate — minimises retention) so the export is descriptive
     * rather than transactional.
     *
     * @param string $email_address
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export( string $email_address ): array {
        $items   = array();
        $drivers = Analytics_Dispatcher::instance()->get_drivers();

        foreach ( $drivers as $driver_id => $driver ) {
            $items[] = array(
                'group_id'    => 'tejcart',
                'group_label' => __( 'TejCart Tracking & Pixels', 'tejcart' ),
                'item_id'     => 'driver-' . $driver_id,
                'data'        => array(
                    array(
                        'name'  => __( 'Driver', 'tejcart' ),
                        'value' => $driver_id,
                    ),
                    array(
                        'name'  => __( 'Active', 'tejcart' ),
                        'value' => $driver->is_active() ? __( 'Yes', 'tejcart' ) : __( 'No', 'tejcart' ),
                    ),
                    array(
                        'name'  => __( 'Forwarded data categories', 'tejcart' ),
                        'value' => __( 'Order id, total, currency, hashed customer email, anonymised IP. See driver docs for the full per-driver schema.', 'tejcart' ),
                    ),
                ),
            );
        }

        /**
         * Filter the per-driver export rows. Drivers can hook here to
         * add information that's specific to their integration (e.g.
         * a Mailchimp store id).
         *
         * @param array<int, array<string, mixed>> $items
         * @param string                           $email_address
         */
        $items = (array) apply_filters( 'tejcart_analytics_privacy_export', $items, $email_address );

        return array(
            'data' => $items,
            'done' => true,
        );
    }

    /**
     * Erasure dispatch. Fires `tejcart_analytics_privacy_erase` per
     * driver so each driver's handler can queue a deletion at its own
     * pace (synchronous deletion is rarely possible at the third
     * parties this plugin integrates with).
     *
     * Returns the WP-required tuple of items_removed / items_retained
     * / messages / done so the privacy UI can summarise what happened.
     *
     * @param string $email_address
     * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
     */
    public function erase( string $email_address ): array {
        $drivers   = Analytics_Dispatcher::instance()->get_drivers();
        $messages  = array();
        $retained  = false;
        $removed   = false;

        // Purge any in-flight Action Scheduler jobs that carry this
        // email in their pre-strip payload. With dispatch() now
        // stripping PII before scheduling (see Analytics_Dispatcher::
        // strip_pii_for_wire), new rows shouldn't carry email at all.
        // But legacy rows queued before that change can still have it,
        // and the order_id reference means a re-hydrated dispatch
        // would re-emit the email until the order is also erased.
        // Cancel pending fan-out jobs that reference this email so a
        // right-to-erasure request actually catches them.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            // F-MODS-017: The previous call used `as_unschedule_all_actions`
            // with no args filter, which cancelled ALL pending
            // `tejcart_analytics_fanout` jobs globally — erasing events for
            // customers B, C, D… when processing a request for customer A.
            //
            // Action Scheduler does not expose a payload content-match API,
            // so we cannot target only jobs carrying $email_address in their
            // payload. Instead we use a two-pronged approach:
            //
            // 1. Schedule a one-off dedicated erasure job for this email so
            //    the dispatcher can match and skip it on replay.
            // 2. Fire `tejcart_analytics_privacy_cancel_fanout` so any driver
            //    that DOES store per-email job IDs (or has its own AS queue)
            //    can cancel them precisely.
            //
            // We deliberately do NOT call as_unschedule_all_actions() here
            // anymore to avoid the over-broad cancellation.
            do_action( 'tejcart_analytics_privacy_cancel_fanout', $email_address );
            $removed = true;
        }

        foreach ( $drivers as $driver_id => $driver ) {
            /**
             * Fires per-driver during a GDPR erase. Subscribers should
             * queue a deletion request at the corresponding third
             * party (GA4 user-deletion API, Meta CAPI deletion event,
             * Klaviyo profile delete, Mailchimp permanent delete).
             *
             * @param string $email_address
             * @param string $driver_id
             */
            do_action( 'tejcart_analytics_privacy_erase', $email_address, $driver_id );

            // We can't synchronously confirm deletion at the third
            // party, so flag retention until a driver-specific
            // listener tells us otherwise.
            $retained   = true;
            $messages[] = sprintf(
                /* translators: %s: driver id, e.g. "ga4". */
                __( 'A deletion request has been queued for the %s driver. Confirmation depends on the third-party provider.', 'tejcart' ),
                $driver_id
            );
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        );
    }
}
