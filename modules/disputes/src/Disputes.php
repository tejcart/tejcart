<?php
/**
 * Dispute orchestration module.
 *
 * Wires Stripe and PayPal dispute webhooks into the unified
 * `wp_tejcart_disputes` table, surfaces an admin queue under the
 * TejCart menu, and emails the store admin the moment a dispute opens
 * so they have time to gather evidence before the issuer's response
 * window closes.
 *
 * Both gateways already raise their own action when a dispute event
 * lands — `tejcart_stripe_dispute_created` and
 * `tejcart_paypal_dispute_created` — so this module hooks in
 * non-invasively without touching either gateway's webhook code.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Disputes {
    /**
     * Hook the module into TejCart core. Called once from
     * TejCart\Tier2\Tier2::boot().
     */
    public static function init(): void {
        add_action( 'tejcart_stripe_dispute_created', array( __CLASS__, 'on_stripe_dispute' ), 10, 2 );
        add_action( 'tejcart_stripe_webhook_event', array( __CLASS__, 'on_stripe_event' ), 10, 3 );
        add_action( 'tejcart_paypal_dispute_created', array( __CLASS__, 'on_paypal_dispute_created' ), 10, 3 );
        add_action( 'tejcart_paypal_dispute_updated', array( __CLASS__, 'on_paypal_dispute_updated' ), 10, 3 );
        add_action( 'tejcart_paypal_dispute_resolved', array( __CLASS__, 'on_paypal_dispute_resolved' ), 10, 4 );

        // The Evidence_Reminder cron only fires the action; the email
        // listener is wired here so the cron stays a pure scheduler
        // (easier to swap with Action Scheduler later).
        $email_notifier = new Email_Notification();
        add_action( 'tejcart_disputes_evidence_due_soon', array( $email_notifier, 'send_evidence_reminder' ), 10, 2 );

        // Daily reminder job. Runs everywhere (including in WP-Cron
        // outside admin) so reminders go out even when no admin
        // page-load happens to flush the wp-cron queue.
        ( new Evidence_Reminder() )->register();

        // REST API surface — read-only by default. Registered on every
        // request so the WordPress REST cache can serve the OPTIONS
        // preflight without a re-init.
        ( new REST_API() )->register();

        // #1214: GDPR exporter + eraser. Disputes hold PII-bearing
        // free-text columns (notes, payload) that must be surfaced to
        // a customer export request and anonymised on erase.
        ( new Privacy() )->register();

        if ( is_admin() ) {
            ( new Admin_Queue() )->register();
            ( new Order_Panel() )->register();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            CLI::register();
        }
    }

    /**
     * Capture a Stripe charge.dispute.created event into the local table.
     *
     * Signature matches the action raised by TejCart_Stripe\Webhook:
     *   do_action( 'tejcart_stripe_dispute_created', $dispute, $order );
     *
     * @param array<string, mixed> $dispute Stripe dispute resource.
     * @param mixed                $order   TejCart order or null.
     */
    public static function on_stripe_dispute( $dispute, $order = null ): void {
        if ( ! is_array( $dispute ) ) {
            return;
        }

        $external_id = (string) ( $dispute['id'] ?? '' );
        if ( '' === $external_id ) {
            return;
        }

        $currency = strtoupper( (string) ( $dispute['currency'] ?? '' ) );
        $amount   = self::stripe_minor_to_major( (int) ( $dispute['amount'] ?? 0 ), $currency );

        $entity = new Dispute( array(
            'order_id'        => self::resolve_order_id( $order ),
            'gateway'         => 'stripe',
            'external_id'     => $external_id,
            'transaction_ref' => (string) ( $dispute['payment_intent'] ?? $dispute['charge'] ?? '' ),
            'status'          => self::map_stripe_status( (string) ( $dispute['status'] ?? '' ) ),
            'reason'          => (string) ( $dispute['reason'] ?? '' ),
            'amount'          => $amount,
            'currency'        => $currency,
            'evidence_due'    => self::stripe_evidence_due( $dispute ),
            'payload'         => $dispute,
        ) );
        self::stamp_resolved_at_if_terminal( $entity );

        $existing      = Dispute::find_by_external( 'stripe', $external_id );
        $is_new        = null === $existing;
        $status_before = $is_new ? '' : ( $existing->status ?? '' );
        if ( $entity->save() ) {
            Dispute_Event::record( $entity->id, $is_new ? 'webhook_created' : 'webhook_updated', $status_before, $entity->status, $dispute, 'stripe', $external_id );
            if ( $is_new ) {
                self::notify_new( $entity );
                do_action( 'tejcart_dispute_opened', $entity );
            } else {
                do_action( 'tejcart_dispute_updated', $entity );
            }
        }
    }

    /**
     * Catch follow-up Stripe events (closed, won, lost) routed via the
     * generic `tejcart_stripe_webhook_event` action — the gateway only
     * raises a dedicated action for `created`.
     *
     * @param string               $type   Stripe event type.
     * @param array<string, mixed> $object Event data object.
     * @param array<string, mixed> $event  Full event envelope.
     */
    public static function on_stripe_event( string $type, $object, $event ): void {
        if ( ! is_array( $object ) || strpos( $type, 'charge.dispute.' ) !== 0 ) {
            return;
        }
        if ( 'charge.dispute.created' === $type ) {
            return; // Handled separately so we can notify on first open only.
        }

        $external_id = (string) ( $object['id'] ?? '' );
        if ( '' === $external_id ) {
            return;
        }

        $existing = Dispute::find_by_external( 'stripe', $external_id );
        if ( null === $existing ) {
            // We never saw the create event; treat this as a late insert.
            self::on_stripe_dispute( $object, null );
            return;
        }

        $new_status = self::map_stripe_status( (string) ( $object['status'] ?? '' ) );
        if ( self::should_reject_status_change( $existing, $new_status, 'stripe' ) ) {
            Dispute_Event::record( $existing->id, 'webhook_rejected', $existing->status, $new_status, $object, 'stripe', $external_id );
            return;
        }

        $status_before     = $existing->status;
        $existing->status  = $new_status;
        $existing->outcome = self::stripe_outcome( $object );
        $existing->payload = $object;
        self::stamp_resolved_at_if_terminal( $existing );
        $existing->save();
        Dispute_Event::record( $existing->id, 'webhook_updated', $status_before, $existing->status, $object, 'stripe', $external_id );
        do_action( 'tejcart_dispute_updated', $existing );
    }

    /**
     * Capture a PayPal dispute open event.
     *
     * Signature matches the action raised by PayPal_Webhook:
     *   do_action( 'tejcart_paypal_dispute_created', $order_id, $dispute_id, $resource );
     *
     * @param mixed                $order_id   Order ID or 0.
     * @param string               $dispute_id PayPal dispute ID.
     * @param array<string, mixed> $resource   Raw webhook resource.
     */
    public static function on_paypal_dispute_created( $order_id, $dispute_id, $resource ): void {
        if ( ! is_array( $resource ) ) {
            return;
        }
        $external_id = (string) $dispute_id;
        if ( '' === $external_id ) {
            return;
        }

        $tx_ref = '';
        if ( ! empty( $resource['disputed_transactions'] ) && is_array( $resource['disputed_transactions'] ) ) {
            foreach ( $resource['disputed_transactions'] as $tx ) {
                if ( is_array( $tx ) && ! empty( $tx['seller_transaction_id'] ) ) {
                    $tx_ref = (string) $tx['seller_transaction_id'];
                    break;
                }
            }
        }

        $entity = new Dispute( array(
            'order_id'        => $order_id ? (int) $order_id : null,
            'gateway'         => 'paypal',
            'external_id'     => $external_id,
            'transaction_ref' => $tx_ref,
            'status'          => self::map_paypal_status( (string) ( $resource['status'] ?? 'OPEN' ) ),
            'reason'          => (string) ( $resource['reason'] ?? '' ),
            'amount'          => isset( $resource['dispute_amount']['value'] )
                ? (float) $resource['dispute_amount']['value']
                : 0.0,
            'currency'        => strtoupper( (string) ( $resource['dispute_amount']['currency_code'] ?? '' ) ),
            'evidence_due'    => self::paypal_evidence_due( $resource ),
            'payload'         => $resource,
        ) );

        $is_new = null === Dispute::find_by_external( 'paypal', $external_id );
        if ( $entity->save() ) {
            Dispute_Event::record( $entity->id, $is_new ? 'webhook_created' : 'webhook_updated', '', $entity->status, $resource, 'paypal', $external_id );
            if ( $is_new ) {
                self::notify_new( $entity );
                do_action( 'tejcart_dispute_opened', $entity );
            }
        }
    }

    /**
     * Capture a PayPal CUSTOMER.DISPUTE.UPDATED event into the local
     * table. Mirrors {@see on_paypal_dispute_created} but treats the
     * event as an upsert — if we never saw the create event (a webhook
     * delivery race or a manual replay) this is the first chance we
     * have to record the dispute. Without an upsert path the admin
     * queue would never reflect status / stage / evidence-due changes
     * pushed by PayPal after the open event.
     *
     * Signature matches the action fired by PayPal_Webhook:
     *   do_action( 'tejcart_paypal_dispute_updated', $order_id, $dispute_id, $resource );
     *
     * @param mixed                $order_id   Order ID or 0.
     * @param string               $dispute_id PayPal dispute ID.
     * @param array<string, mixed> $resource   Raw webhook resource.
     */
    public static function on_paypal_dispute_updated( $order_id, $dispute_id, $resource ): void {
        if ( ! is_array( $resource ) ) {
            return;
        }
        $external_id = (string) $dispute_id;
        if ( '' === $external_id ) {
            return;
        }

        $existing = Dispute::find_by_external( 'paypal', $external_id );
        if ( ! $existing ) {
            // First sighting — treat as a deferred create so the row
            // exists for the admin queue. on_paypal_dispute_created()
            // already de-dupes via find_by_external() so a subsequent
            // create won't double-insert.
            self::on_paypal_dispute_created( $order_id, $external_id, $resource );
            return;
        }

        $status_before = $existing->status;
        $status_raw    = (string) ( $resource['status'] ?? '' );
        if ( '' !== $status_raw ) {
            $new_status = self::map_paypal_status( $status_raw );
            if ( self::should_reject_status_change( $existing, $new_status, 'paypal' ) ) {
                Dispute_Event::record( $existing->id, 'webhook_rejected', $existing->status, $new_status, $resource, 'paypal', $external_id );
                return;
            }
            $existing->status = $new_status;
        }

        if ( isset( $resource['reason'] ) ) {
            $existing->reason = (string) $resource['reason'];
        }
        if ( isset( $resource['dispute_amount']['value'] ) ) {
            $existing->amount = (float) $resource['dispute_amount']['value'];
        }
        if ( isset( $resource['dispute_amount']['currency_code'] ) ) {
            $existing->currency = strtoupper( (string) $resource['dispute_amount']['currency_code'] );
        }
        $evidence_due = self::paypal_evidence_due( $resource );
        if ( null !== $evidence_due ) {
            $existing->evidence_due = $evidence_due;
        }
        if ( ! $existing->order_id && $order_id ) {
            $existing->order_id = (int) $order_id;
        }
        $existing->payload = $resource;

        self::stamp_resolved_at_if_terminal( $existing );

        if ( $existing->save() ) {
            Dispute_Event::record( $existing->id, 'webhook_updated', $status_before, $existing->status, $resource, 'paypal', $external_id );
            do_action( 'tejcart_dispute_updated', $existing );
        }
    }

    /**
     * Match the action raised by PayPal_Webhook:
     *   do_action( 'tejcart_paypal_dispute_resolved', $order_id, $dispute_id, $outcome, $resource );
     *
     * @param mixed                $order_id   Order ID or 0.
     * @param string               $dispute_id PayPal dispute ID.
     * @param string               $outcome    PayPal outcome code (BUYER_FAVOR, SELLER_FAVOR, NONE).
     * @param array<string, mixed> $resource   Raw webhook resource.
     */
    public static function on_paypal_dispute_resolved( $order_id, $dispute_id, $outcome, $resource = array() ): void {
        if ( ! is_array( $resource ) ) {
            $resource = array();
        }
        $existing = Dispute::find_by_external( 'paypal', (string) $dispute_id );
        if ( ! $existing ) {
            // PayPal can send RESOLVED without us ever having seen the
            // CREATED event — webhook subscription gaps, OPS replays,
            // or the dispute being opened before the merchant connected
            // the webhook. Backfill the row from the resolved payload
            // so the admin queue still has audit history for it.
            self::on_paypal_dispute_created( $order_id, (string) $dispute_id, $resource );
            $existing = Dispute::find_by_external( 'paypal', (string) $dispute_id );
            if ( ! $existing ) {
                return;
            }
        }

        $status_before     = $existing->status;
        $outcome           = strtoupper( (string) $outcome );
        $existing->outcome = $outcome;
        $existing->status  = self::map_paypal_outcome_to_status( $outcome );
        if ( $resource ) {
            $existing->payload = $resource;
        }
        self::stamp_resolved_at_if_terminal( $existing );
        $existing->save();
        Dispute_Event::record( $existing->id, 'webhook_resolved', $status_before, $existing->status, $resource, 'paypal', (string) $dispute_id );
        do_action( 'tejcart_dispute_updated', $existing );
    }

    /**
     * Trigger the admin notification email exactly once per new dispute.
     */
    private static function notify_new( Dispute $dispute ): void {
        ( new Email_Notification() )->send_admin_alert( $dispute );
    }

    /**
     * Stamp `resolved_at` when a webhook update lands the dispute in a
     * terminal status. The manual {@see Dispute::resolve()} flow already
     * sets this column, but webhook-driven transitions used to skip it
     * which left reports with a null resolution timestamp on every
     * gateway-driven close.
     */
    private static function stamp_resolved_at_if_terminal( Dispute $dispute ): void {
        if ( ! $dispute->is_terminal() ) {
            return;
        }
        if ( null !== $dispute->resolved_at && '' !== $dispute->resolved_at ) {
            return;
        }
        $dispute->resolved_at = function_exists( 'current_time' )
            ? current_time( 'mysql', true )
            : gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * @param mixed $order
     */
    private static function resolve_order_id( $order ): ?int {
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            $id = (int) $order->get_id();
            return $id > 0 ? $id : null;
        }
        if ( is_numeric( $order ) ) {
            $id = (int) $order;
            return $id > 0 ? $id : null;
        }
        return null;
    }

    /**
     * Map a Stripe dispute `status` string onto TejCart's unified enum.
     *
     * Per the Stripe Dispute object reference, possible values are:
     * `warning_needs_response`, `warning_under_review`, `warning_closed`,
     * `needs_response`, `under_review`, `charge_refunded`, `won`, `lost`.
     */
    private static function map_stripe_status( string $stripe_status ): string {
        switch ( $stripe_status ) {
            case 'won':
                return Dispute::STATUS_WON;
            case 'lost':
                return Dispute::STATUS_LOST;
            case 'charge_refunded':
                return Dispute::STATUS_ACCEPTED;
            case 'warning_closed':
                return Dispute::STATUS_CLOSED;
            case 'under_review':
            case 'warning_under_review':
                return Dispute::STATUS_UNDER_REVIEW;
            case 'needs_response':
            case 'warning_needs_response':
                return Dispute::STATUS_NEEDS_RESPONSE;
            case '':
                return Dispute::STATUS_OPEN;
            default:
                self::log_unknown_status( 'stripe', $stripe_status );
                return Dispute::STATUS_OPEN;
        }
    }

    private static function stripe_outcome( array $object ): string {
        $status = (string) ( $object['status'] ?? '' );
        if ( in_array( $status, array( 'won', 'lost' ), true ) ) {
            return strtoupper( $status );
        }
        return '';
    }

    /**
     * @param array<string, mixed> $dispute
     */
    private static function stripe_evidence_due( array $dispute ): ?string {
        $due = $dispute['evidence_details']['due_by'] ?? null;
        if ( ! $due ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', (int) $due );
    }

    private static function map_paypal_status( string $status ): string {
        switch ( strtoupper( $status ) ) {
            case 'WAITING_FOR_BUYER_RESPONSE':
            case 'OPEN':
                return Dispute::STATUS_OPEN;
            case 'WAITING_FOR_SELLER_RESPONSE':
            case 'RESPONSE_REQUIRED':
                return Dispute::STATUS_NEEDS_RESPONSE;
            case 'UNDER_REVIEW':
                return Dispute::STATUS_UNDER_REVIEW;
            case 'RESOLVED':
                return Dispute::STATUS_CLOSED;
            default:
                self::log_unknown_status( 'paypal', $status );
                return Dispute::STATUS_OPEN;
        }
    }

    /**
     * Map PayPal dispute outcome codes to TejCart's unified status enum.
     *
     * The canonical values from customer_disputes_v1 are
     * `RESOLVED_BUYER_FAVOUR` / `RESOLVED_SELLER_FAVOUR` (British spelling)
     * along with `RESOLVED_WITH_PAYOUT`, `CANCELED_BY_BUYER`, `ACCEPTED`
     * (deprecated), `DENIED` (deprecated) and `NONE`. The adjudication
     * outcome and some sandbox payloads use the shorter `BUYER_FAVOR` /
     * `SELLER_FAVOR` forms, so accept both spellings.
     */
    private static function map_paypal_outcome_to_status( string $outcome ): string {
        switch ( strtoupper( $outcome ) ) {
            case 'RESOLVED_BUYER_FAVOUR':
            case 'RESOLVED_BUYER_FAVOR':
            case 'BUYER_FAVOR':
                // Buyer won → merchant lost the chargeback.
                return Dispute::STATUS_LOST;
            case 'RESOLVED_SELLER_FAVOUR':
            case 'RESOLVED_SELLER_FAVOR':
            case 'SELLER_FAVOR':
                return Dispute::STATUS_WON;
            case 'CANCELED_BY_BUYER':
                return Dispute::STATUS_CLOSED;
            case 'ACCEPTED':
                // Deprecated outcome — treat as a merchant-side acceptance.
                return Dispute::STATUS_ACCEPTED;
            case 'DENIED':
                // Deprecated outcome — PayPal denied the customer's claim.
                return Dispute::STATUS_WON;
            case 'RESOLVED_WITH_PAYOUT':
                // PayPal disbursed funds to the buyer — financially
                // identical to a merchant LOSS. Mapping this to CLOSED
                // (the previous behaviour) caused liability reports and
                // chargeback-rate dashboards to undercount losses.
                return Dispute::STATUS_LOST;
            case 'NONE':
            default:
                return Dispute::STATUS_CLOSED;
        }
    }

    /**
     * @param array<string, mixed> $resource
     */
    private static function paypal_evidence_due( array $resource ): ?string {
        $due = $resource['seller_response_due_date'] ?? null;
        if ( ! $due || ! is_string( $due ) ) {
            return null;
        }
        $ts = strtotime( $due );
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
    }

    /**
     * Stripe quotes amounts in minor units. Reuse the same zero-decimal
     * list the gateway uses so JPY etc. round-trip correctly.
     */
    private static function stripe_minor_to_major( int $minor, string $currency ): float {
        return \TejCart\Money\Currency::from_minor_units( $minor, $currency );
    }

    /**
     * Guard against webhook events reopening a manually-resolved dispute.
     *
     * Policy: once a dispute reaches a terminal status, only another
     * terminal status from the gateway can override it (e.g., the gateway
     * confirming `won`). Non-terminal statuses (like a replayed `OPEN`
     * event arriving late) are rejected with an audit note.
     *
     * Filterable via `tejcart_disputes_webhook_overwrite_policy` — return
     * false to allow the overwrite (gateway-authoritative mode).
     */
    private static function should_reject_status_change( Dispute $existing, string $new_status, string $gateway ): bool {
        if ( ! $existing->is_terminal() ) {
            return false;
        }
        if ( in_array( $new_status, Dispute::terminal_statuses(), true ) ) {
            return false;
        }

        $reject = apply_filters( 'tejcart_disputes_webhook_overwrite_policy', true, $existing, $new_status, $gateway );
        if ( ! $reject ) {
            return false;
        }

        $existing->append_note(
            sprintf( 'Webhook attempted status change to "%s" but dispute is already resolved as "%s"; ignored.', $new_status, $existing->status ),
            $gateway
        );
        return true;
    }

    /**
     * Log + fire action when a gateway returns a status value not in our
     * mapping table. This surfaces API evolution that would otherwise be
     * silently swallowed into `open`.
     */
    private static function log_unknown_status( string $gateway, string $native_status ): void {
        if ( function_exists( 'do_action' ) ) {
            do_action( 'tejcart_disputes_unknown_status', $native_status, $gateway );
        }
        if ( function_exists( 'tejcart' ) && method_exists( tejcart(), 'logger' ) ) {
            tejcart()->logger()->warning(
                sprintf( 'Disputes: unmapped %s status "%s" — defaulting to open.', $gateway, $native_status )
            );
        }
    }
}
