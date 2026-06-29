<?php
/**
 * Order Status constants and helpers.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Defines order status constants and provides validation/label helpers.
 */
class Order_Status {
    const PENDING             = 'pending';
    const PROCESSING          = 'processing';
    const ON_HOLD             = 'on-hold';
    const COMPLETED           = 'completed';
    const CANCELLED           = 'cancelled';
    const REFUNDED            = 'refunded';
    const PARTIALLY_REFUNDED  = 'partially-refunded';
    const FAILED              = 'failed';

    /**
     * Get all statuses as an associative array of slug => label.
     *
     * Tier-2 modules and sibling plugins that introduce a genuinely new
     * order status (e.g. the order-tracking module's `shipped` /
     * `delivered`) register it here via the `tejcart_order_statuses`
     * filter. Because this is the single source of truth consumed by
     * {@see is_valid()} and (transitively) {@see is_valid_transition()},
     * registering a status here is what makes {@see \TejCart\Order\Order::update_status()}
     * accept it. A filtered status MUST also be wired into
     * {@see get_allowed_transitions()} (via `tejcart_order_allowed_transitions`)
     * or no edge will reach it.
     *
     * The same `tejcart_order_statuses` filter is also applied by
     * {@see \TejCart\Order\Order_Manager::get_order_statuses()}; keeping it
     * here means both entry points return the identical, complete list.
     *
     * @return array<string,string> Associative array of slug => label.
     */
    public static function get_statuses() {
        $statuses = array(
            self::PENDING            => __( 'Pending', 'tejcart' ),
            self::PROCESSING         => __( 'Processing', 'tejcart' ),
            self::ON_HOLD            => __( 'On Hold', 'tejcart' ),
            self::COMPLETED          => __( 'Completed', 'tejcart' ),
            self::CANCELLED          => __( 'Cancelled', 'tejcart' ),
            self::REFUNDED           => __( 'Refunded', 'tejcart' ),
            self::PARTIALLY_REFUNDED => __( 'Partially refunded', 'tejcart' ),
            self::FAILED             => __( 'Failed', 'tejcart' ),
        );

        /**
         * Filter the list of registered order statuses.
         *
         * @param array<string,string> $statuses Slug => translated label.
         */
        $filtered = apply_filters( 'tejcart_order_statuses', $statuses );

        // Defensive: a buggy listener returning a non-array (or an empty
        // one) must not break status validation plugin-wide.
        if ( ! is_array( $filtered ) || array() === $filtered ) {
            return $statuses;
        }

        return $filtered;
    }

    /**
     * Check whether a status string is valid.
     *
     * @param string $status Status slug.
     * @return bool
     */
    public static function is_valid( $status ) {
        $statuses = self::get_statuses();

        return isset( $statuses[ $status ] );
    }

    /**
     * Get the human-readable label for a status.
     *
     * For an unregistered status — e.g. a `shipped` / `delivered` order left
     * behind after the order-tracking module that registered it is disabled —
     * this falls back to a humanized form of the slug ("Shipped", "Delivered")
     * rather than an empty string. This keeps the order's TRUE state visible
     * (matching WooCommerce's behaviour for inactive custom statuses) instead
     * of blanking it out or misreporting it as another status. The stored
     * status is never mutated; re-enabling the module restores the rich label.
     *
     * @param string $status Status slug.
     * @return string Translated label, or a humanized form of the slug if the
     *                status is not registered. Empty only for an empty slug.
     */
    public static function get_label( $status ) {
        $status   = (string) $status;
        $statuses = self::get_statuses();

        if ( isset( $statuses[ $status ] ) ) {
            return $statuses[ $status ];
        }

        if ( '' === $status ) {
            return '';
        }

        return ucfirst( str_replace( array( '-', '_' ), ' ', $status ) );
    }

    /**
     * Statuses in which an order holds captured funds and can therefore be
     * refunded.
     *
     * A refund returns money that was actually collected, so orders that
     * never captured anything — `pending` / `on-hold` (awaiting payment),
     * `failed`, `cancelled` — are excluded, as is `refunded` (already fully
     * returned). This mirrors {@see get_allowed_transitions()}, where only
     * these three statuses carry an edge to `refunded` /
     * `partially-refunded`.
     *
     * @return string[]
     */
    public static function get_refundable_statuses() {
        $statuses = array(
            self::PROCESSING,
            self::COMPLETED,
            self::PARTIALLY_REFUNDED,
        );

        /**
         * Filter the statuses an order may be refunded from.
         *
         * Sites whose gateways capture funds in a status not listed here
         * (e.g. an authorise-and-hold flow that parks paid orders in
         * `on-hold`) can add it. Listeners must return an array of valid
         * status slugs.
         *
         * @param string[] $statuses Refundable status slugs.
         */
        $filtered = apply_filters( 'tejcart_refundable_order_statuses', $statuses );

        return is_array( $filtered ) ? $filtered : $statuses;
    }

    /**
     * Whether an order in the given status can be refunded — i.e. funds
     * have been captured and there is money to return.
     *
     * @param string $status Status slug.
     * @return bool
     */
    public static function is_refundable( $status ) {
        return in_array( (string) $status, self::get_refundable_statuses(), true );
    }

    /**
     * Allowed status transitions. Terminal statuses (refunded) have no exits.
     *
     * Tier-2 modules and merchant integrations can extend or restrict the
     * map via the `tejcart_order_allowed_transitions` filter. Filter
     * implementations that introduce a new "from" key MUST also extend
     * is_valid() / get_statuses() if the new value is genuinely a new
     * status; this filter is intended for editing transitions between
     * existing statuses (e.g. re-opening REFUNDED, or locking down
     * COMPLETED → REFUNDED to admins only by removing the edge here and
     * implementing the cap check elsewhere).
     *
     * @return array<string, string[]>
     */
    public static function get_allowed_transitions() {
        // FAILED has no direct edge to REFUNDED on purpose: a failed payment
        // never captured funds, so there is nothing to refund. If an
        // out-of-band capture later succeeds at the gateway, recover by
        // moving FAILED → PROCESSING (already allowed) and issuing the
        // refund from there. Sites that genuinely need FAILED → REFUNDED
        // can add the edge through the `tejcart_order_allowed_transitions`
        // filter below.
        $transitions = array(
            self::PENDING             => array( self::PROCESSING, self::ON_HOLD, self::COMPLETED, self::CANCELLED, self::FAILED ),
            self::ON_HOLD             => array( self::PROCESSING, self::COMPLETED, self::CANCELLED, self::FAILED ),
            self::PROCESSING          => array( self::COMPLETED, self::ON_HOLD, self::CANCELLED, self::PARTIALLY_REFUNDED, self::REFUNDED, self::FAILED ),
            self::COMPLETED           => array( self::PARTIALLY_REFUNDED, self::REFUNDED ),
            // Partial refunds may continue (more partials) or escalate to a
            // full refund. They may also resolve back to processing if the
            // refund is reversed at the gateway (e.g. PayPal CAPTURE.REVERSED
            // after a chargeback win).
            self::PARTIALLY_REFUNDED  => array( self::PARTIALLY_REFUNDED, self::REFUNDED, self::PROCESSING, self::COMPLETED ),
            self::FAILED              => array( self::PENDING, self::PROCESSING, self::CANCELLED ),
            self::CANCELLED           => array( self::PENDING ),
            self::REFUNDED            => array(),
        );

        $filtered = apply_filters( 'tejcart_order_allowed_transitions', $transitions );

        // Defensive: reject filter output that isn't an array of arrays-of-strings,
        // so a buggy listener can't break update_status() globally.
        if ( ! is_array( $filtered ) ) {
            return $transitions;
        }
        foreach ( $filtered as $from => $tos ) {
            if ( ! is_string( $from ) || ! is_array( $tos ) ) {
                return $transitions;
            }
        }
        return $filtered;
    }

    /**
     * Whether a transition between two statuses is permitted.
     *
     * @param string $from Current status.
     * @param string $to   Desired status.
     * @return bool
     */
    public static function is_valid_transition( $from, $to ) {
        if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
            return false;
        }

        $map = self::get_allowed_transitions();

        return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
    }

    /**
     * Defence-in-depth capability gate for status transitions.
     *
     * `is_valid_transition()` only checks that the state machine permits the
     * edge; it does NOT verify the current user has authority to take it.
     * Most admin/AJAX entry points already perform their own cap check before
     * calling `Order::update_status()`, but new entry points (a future REST
     * route, a CLI shim, a third-party integration) can opt into this helper
     * to ensure the gate is enforced uniformly.
     *
     * Internal callers — webhooks, cron jobs, refund engines, CLI — should
     * NOT be gated; they run without a logged-in user. Pass `null` for
     * `$user_id` and the helper returns true on a valid transition. Callers
     * that need a buyer-context check should use `current_user_can_transition()`.
     *
     * Filterable via `tejcart_order_transition_required_cap` (default:
     * `Capabilities::MANAGE_ORDERS`) so integrations can require a different
     * capability per transition (e.g. require a finance role for REFUNDED).
     *
     * @since 1.0.1
     *
     * @param int|null $user_id User ID to check, or null to skip the cap check
     *                          (use for internal/system callers).
     * @param string   $from    Current status.
     * @param string   $to      Desired status.
     * @return bool True if the transition is both state-machine-valid and the
     *              caller has the required capability (or is internal).
     */
    public static function user_can_transition( $user_id, $from, $to ) {
        if ( ! self::is_valid_transition( $from, $to ) ) {
            return false;
        }

        if ( null === $user_id ) {
            return true;
        }

        $required_cap = (string) apply_filters(
            'tejcart_order_transition_required_cap',
            \TejCart\Core\Capabilities::MANAGE_ORDERS,
            (string) $from,
            (string) $to,
            (int) $user_id
        );

        if ( '' === $required_cap ) {
            return true;
        }

        return user_can( (int) $user_id, $required_cap );
    }

    /**
     * Convenience wrapper: defence-in-depth check against the current user.
     *
     * @since 1.0.1
     *
     * @param string $from Current status.
     * @param string $to   Desired status.
     * @return bool
     */
    public static function current_user_can_transition( $from, $to ) {
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

        return self::user_can_transition( $uid > 0 ? $uid : null, $from, $to );
    }
}
