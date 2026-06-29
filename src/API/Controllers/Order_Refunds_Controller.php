<?php
/**
 * Order Refunds REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Core\Capabilities;
use TejCart\Order\Order;
use TejCart\Order\Order_Manager;
use TejCart\Order\Order_Refund;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/orders/{order_id}/refunds.
 *
 * Reuses Order_Manager::process_refund for the default flow (it handles
 * gateway-side reversal + status transitions). When refund_payment=false
 * is supplied, creates a local Order_Refund row WITHOUT calling the
 * gateway, matching common store REST behavior.
 */
class Order_Refunds_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Parent base.
     */
    protected $rest_base = 'orders';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<order_id>[\d]+)/refunds',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'amount'         => array(
                            'type'        => 'number',
                            'required'    => true,
                            'description' => __( 'Refund amount in the order\'s currency. The endpoint refuses cross-currency refunds — refunds are always denominated in the original order currency.', 'tejcart' ),
                        ),
                        'reason'         => array( 'type' => 'string' ),
                        'refund_payment' => array(
                            'type'        => 'boolean',
                            'default'     => true,
                            'description' => __( 'When true, also refunds via the order\'s gateway. When false, records locally only.', 'tejcart' ),
                        ),
                        'currency'       => array(
                            'type'        => 'string',
                            'description' => __( 'Optional ISO-4217 currency code. Must match the order currency exactly; cross-currency refunds are rejected (N-L2).', 'tejcart' ),
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<order_id>[\d]+)/refunds/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'force' => array( 'type' => 'boolean', 'default' => false ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    public function read_permissions_check() {
        return Capabilities::check( Capabilities::MANAGE_ORDERS );
    }

    public function write_permissions_check() {
        return Capabilities::check( Capabilities::REFUND_ORDERS );
    }

    /**
     * List refunds.
     */
    public function get_items( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $refunds = Order_Refund::get_refunds( $order->get_id() );
        $items   = array();
        foreach ( $refunds as $refund ) {
            $items[] = $this->prepare_refund( $refund );
        }

        return rest_ensure_response( $items );
    }

    /**
     * Fetch one refund.
     */
    public function get_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $refund = $this->find_refund( $order->get_id(), (int) $request['id'] );
        if ( is_wp_error( $refund ) ) {
            return $refund;
        }

        return rest_ensure_response( $this->prepare_refund( $refund ) );
    }

    /**
     * Create a refund.
     */
    public function create_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $amount = round( (float) $request->get_param( 'amount' ), 4 );
        if ( $amount <= 0 ) {
            return new WP_Error( 'tejcart_rest_refund_invalid_amount', __( 'Refund amount must be greater than zero.', 'tejcart' ), array( 'status' => 400 ) );
        }

        // M-6 / L-2: cap-check via the canonical
        // Order_Refund::remaining_refundable_minor helper so every
        // refund entry point compares in identical integer minor units
        // against the order's stored currency.
        $order_currency  = strtoupper( (string) $order->get_currency() );

        // N-L2: reject any cross-currency refund. The Order_Refund row
        // implicitly inherits the order's currency, and the gateway
        // reversal would be denominated in the order currency too —
        // accepting a client-supplied mismatched currency would silently
        // refund the wrong amount. Optional param; when present it must
        // match the order's currency exactly (case-insensitive).
        $requested_currency = strtoupper( (string) $request->get_param( 'currency' ) );
        if ( '' !== $requested_currency && $requested_currency !== $order_currency ) {
            return new WP_Error(
                'tejcart_rest_refund_currency_mismatch',
                sprintf(
                    /* translators: 1: requested currency code, 2: order currency code */
                    __( 'Refund currency (%1$s) does not match the order currency (%2$s). Cross-currency refunds are not supported.', 'tejcart' ),
                    $requested_currency,
                    $order_currency
                ),
                array( 'status' => 400 )
            );
        }

        $order_total     = (float) $order->get_total();
        $remaining_minor = Order_Refund::remaining_refundable_minor( $order->get_id(), $order_currency, $order_total );
        $requested_minor = \TejCart\Money\Currency::to_minor_units( $amount, $order_currency );
        $allowed_minor   = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );

        if ( $requested_minor > $remaining_minor ) {
            $remaining = max( 0.0, $order_total - (float) Order_Refund::get_total_refunded( $order->get_id() ) );
            return new WP_Error(
                'tejcart_rest_refund_exceeds_remaining',
                sprintf(
                    /* translators: %s: remaining refundable amount */
                    __( 'Refund exceeds the remaining refundable amount (%s).', 'tejcart' ),
                    number_format( $remaining, 2 )
                ),
                array( 'status' => 400 )
            );
        }

        $reason         = sanitize_text_field( (string) $request->get_param( 'reason' ) );
        $refund_payment = null === $request->get_param( 'refund_payment' ) ? true : (bool) $request->get_param( 'refund_payment' );

        if ( $refund_payment ) {
            $manager = new Order_Manager();
            $ok      = $manager->process_refund( $order->get_id(), $amount, $reason );
            if ( ! $ok ) {
                return new WP_Error(
                    'tejcart_rest_refund_failed',
                    __( 'Could not process the refund. The gateway may have rejected it, or the amount exceeds limits.', 'tejcart' ),
                    array( 'status' => 500 )
                );
            }
        } else {
            $refund = new Order_Refund( array(
                'order_id' => $order->get_id(),
                'amount'   => $amount,
                'reason'   => $reason,
            ) );
            if ( ! $refund->save() ) {
                return new WP_Error( 'tejcart_rest_refund_failed', __( 'Could not record the refund.', 'tejcart' ), array( 'status' => 500 ) );
            }

            // Integer minor-units comparison so JPY / KRW / KWD / BHD
            // also flip status correctly. (M-6 follow-on.)
            $post_save_total_minor = \TejCart\Money\Currency::to_minor_units(
                (float) Order_Refund::get_total_refunded( $order->get_id() ),
                $order_currency
            );
            if ( $post_save_total_minor >= $allowed_minor ) {
                $order->update_status( 'refunded', __( 'Order fully refunded (offline).', 'tejcart' ) );
            }

            /** @see Order_Manager::process_refund() */
            do_action( 'tejcart_refund_created', $order->get_id(), $amount, $reason, $order );
        }

        $refunds = Order_Refund::get_refunds( $order->get_id() );
        $created = $refunds[0] ?? null;
        if ( ! $created ) {
            return new WP_Error( 'tejcart_rest_refund_failed', __( 'Refund was processed but could not be retrieved.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $response = rest_ensure_response( $this->prepare_refund( $created ) );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * Delete a refund record.
     *
     * This removes the local record only; it does NOT reverse a gateway-side
     * refund. Callers that need to undo a gateway refund must handle that
     * on the gateway first.
     */
    public function delete_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $refund = $this->find_refund( $order->get_id(), (int) $request['id'] );
        if ( is_wp_error( $refund ) ) {
            // Idempotency: a refund row that is already gone returns
            // 410 Gone instead of 404. Lets clients retry safely
            // without leaking row-existence through differential errors.
            // See review finding H-2.
            $error = $refund->get_error_data();
            $status = is_array( $error ) && isset( $error['status'] ) ? (int) $error['status'] : 410;
            if ( 404 === $status ) {
                return new WP_Error(
                    'tejcart_rest_refund_already_deleted',
                    __( 'Refund record is no longer present (already deleted).', 'tejcart' ),
                    array( 'status' => 410 )
                );
            }
            return $refund;
        }

        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Refunds do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        // Refuse to delete a refund row that carries a gateway
        // transaction_ref. The row represents money that the payment
        // gateway has actually moved; deleting only the local ledger
        // entry leaves Order_Refund::get_total_refunded() under-reading
        // the cumulative total and a future refund attempt passes the
        // cap check and double-refunds the buyer. The merchant must
        // first reverse at the gateway (refund the refund) and only
        // then can the local row be cleared. See review finding H-2.
        $tx_ref = (string) $refund->transaction_ref;
        if ( '' !== $tx_ref ) {
            return new WP_Error(
                'tejcart_rest_refund_gateway_locked',
                sprintf(
                    /* translators: %s: gateway refund identifier */
                    __( 'This refund is linked to gateway transaction %s. Reverse it at the gateway before deleting the local record.', 'tejcart' ),
                    $tx_ref
                ),
                array( 'status' => 409, 'transaction_ref' => $tx_ref )
            );
        }

        $previous = $this->prepare_refund( $refund );

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_refunds';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete(
            $table,
            array( 'id' => $refund->id, 'order_id' => $order->get_id() ),
            array( '%d', '%d' )
        );

        if ( false === $deleted ) {
            return new WP_Error( 'tejcart_rest_refund_delete_failed', __( 'Could not delete refund record.', 'tejcart' ), array( 'status' => 500 ) );
        }

        // Sync the denormalised `_paypal_refunds` meta cache so the
        // order timeline / admin UI doesn't keep showing a refund the
        // operator has just deleted. Best-effort: a missing entry is
        // fine, we simply rewrite without the deleted id.
        if ( method_exists( $order, 'get_meta' ) && method_exists( $order, 'update_meta' ) ) {
            $existing_meta = $order->get_meta( '_paypal_refunds' );
            if ( is_array( $existing_meta ) && ! empty( $existing_meta ) ) {
                $filtered = array();
                foreach ( $existing_meta as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }
                    $entry_id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
                    if ( '' !== $entry_id && '' !== $tx_ref && $entry_id === $tx_ref ) {
                        continue;
                    }
                    $filtered[] = $entry;
                }
                if ( count( $filtered ) !== count( $existing_meta ) ) {
                    $order->update_meta( '_paypal_refunds', array_values( $filtered ) );
                }
            }
        }

        // If the order is currently in `refunded` status, recompute
        // the cumulative refund total against the post-delete state.
        // When the new total falls below the order total, transition
        // back to `processing` so the order isn't lying about its
        // refund history. Without this the order stays "refunded"
        // even after the refund row that pushed it over the cap is
        // deleted. See review finding M-10.
        if ( method_exists( $order, 'get_status' ) && 'refunded' === $order->get_status() ) {
            $remaining = (float) Order_Refund::get_total_refunded( $order->get_id() );
            $order_total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;

            if ( $order_total > 0 && $remaining < $order_total - 0.0001 ) {
                /**
                 * Filter the status the order is restored to when a
                 * refund row's deletion drops cumulative refunds below
                 * the order total.
                 *
                 * @since 1.0.1
                 *
                 * @param string                $status   Default 'processing'.
                 * @param \TejCart\Order\Order  $order    The order being restored.
                 * @param \TejCart\Order\Order_Refund $refund Deleted refund row.
                 */
                $restore_to = (string) apply_filters(
                    'tejcart_refund_delete_restore_status',
                    'processing',
                    $order,
                    $refund
                );

                if ( '' !== $restore_to && method_exists( $order, 'update_status' ) ) {
                    $order->update_status(
                        $restore_to,
                        sprintf(
                            /* translators: %s: deleted refund amount with currency */
                            __( 'Refund record removed; order returned to %s status.', 'tejcart' ),
                            $restore_to
                        )
                    );
                }
            }
        }

        /**
         * Fires after a local refund row has been deleted via REST.
         *
         * Listeners (Returns RMA bridge, accounting integrations) can
         * roll back any side-effects they recorded when the refund was
         * created.
         *
         * @since 1.0.1
         *
         * @param int                          $order_id
         * @param int                          $refund_id
         * @param array                        $previous Snapshot of the deleted row.
         * @param \TejCart\Order\Order         $order
         */
        do_action( 'tejcart_rest_refund_deleted', $order->get_id(), $refund->id, $previous, $order );

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * @return Order|WP_Error
     */
    protected function fetch_order( int $order_id ) {
        if ( $order_id <= 0 ) {
            return new WP_Error( 'tejcart_rest_order_invalid_id', __( 'Invalid order ID.', 'tejcart' ), array( 'status' => 404 ) );
        }
        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            return new WP_Error( 'tejcart_rest_order_invalid_id', __( 'Order not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $order;
    }

    /**
     * @return Order_Refund|WP_Error
     */
    protected function find_refund( int $order_id, int $id ) {
        foreach ( Order_Refund::get_refunds( $order_id ) as $refund ) {
            if ( (int) $refund->id === $id ) {
                return $refund;
            }
        }
        return new WP_Error( 'tejcart_rest_refund_not_found', __( 'Refund not found.', 'tejcart' ), array( 'status' => 404 ) );
    }

    /**
     * Serialise a refund for the API.
     */
    protected function prepare_refund( Order_Refund $refund ): array {
        // Emit money fields in both the legacy major-unit decimal form
        // (back-compat) and the canonical integer minor units with the
        // currency code, per spec §2.7. amount_money carries the
        // parent order's currency since refunds inherit it.
        $money = $refund->get_amount_money();
        return array(
            'id'                 => (int) $refund->id,
            'order_id'           => (int) $refund->order_id,
            'date_created'       => $refund->date ? mysql_to_rfc3339( $refund->date ) : null,
            'amount'             => (float) $refund->amount,
            'amount_minor_units' => $refund->get_amount_minor(),
            'currency'           => $money->currency(),
            'reason'             => (string) $refund->reason,
            'refunded_by'        => (int) $refund->refunded_by,
            'transaction_ref'    => (string) $refund->transaction_ref,
            'items'              => is_array( $refund->items ) ? array_values( $refund->items ) : array(),
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'order_refund',
            'type'       => 'object',
            'properties' => array(
                'id'                 => array( 'type' => 'integer', 'readonly' => true ),
                'order_id'           => array( 'type' => 'integer', 'readonly' => true ),
                'date_created'       => array( 'type' => array( 'string', 'null' ), 'readonly' => true ),
                'amount'             => array( 'type' => 'number' ),
                'amount_minor_units' => array( 'type' => 'integer', 'readonly' => true ),
                'currency'           => array( 'type' => 'string', 'readonly' => true ),
                'reason'             => array( 'type' => 'string' ),
                'refunded_by'        => array( 'type' => 'integer', 'readonly' => true ),
                'transaction_ref'    => array( 'type' => 'string', 'readonly' => true ),
                'items'           => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'order_item_id' => array( 'type' => 'integer' ),
                            'quantity'      => array( 'type' => 'integer' ),
                            'amount'        => array( 'type' => 'number' ),
                        ),
                    ),
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
