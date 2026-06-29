<?php
/**
 * Orders REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use TejCart\Coupon\Coupon;
use TejCart\Order\Order;
use TejCart\Order\Order_Factory;
use TejCart\Order\Order_Manager;
use TejCart\Product\Product_Factory;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the orders endpoint.
 */
class Orders_Controller extends WP_REST_Controller {
    use REST_Rate_Limit;

    /**
     * Route namespace.
     *
     * @var string
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'orders';

    /**
     * Order manager instance.
     *
     * @var Order_Manager
     */
    protected $order_manager;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->order_manager = new Order_Manager();
    }

    /**
     * Register routes for orders.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'get_items_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_item_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Unique identifier for the order.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'update_item_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/refund',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'refund_item' ),
                    'permission_callback' => array( $this, 'refund_item_permissions_check' ),
                    'args'                => array(
                        'id'     => array(
                            'description' => __( 'Unique identifier for the order.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                        'amount' => array(
                            'description' => __( 'Refund amount.', 'tejcart' ),
                            'type'        => 'number',
                            'required'    => true,
                        ),
                        'reason' => array(
                            'description' => __( 'Reason for the refund.', 'tejcart' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/notes',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_notes' ),
                    'permission_callback' => array( $this, 'get_notes_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Unique identifier for the order.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Check if a given request has access to list orders.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_items_permissions_check( $request ) {
        // Use Capabilities::check() so a Shop Manager with the
        // granular MANAGE_ORDERS cap (but no `manage_options`) can
        // list orders. Falls back to manage_options for compatibility
        // with sites that haven't reset roles since the cap matrix
        // was introduced. See review finding I-2.
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_list_orders',
                __( 'Sorry, you are not allowed to list orders.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'orders', 60 );
    }

    /**
     * Check if a given request has access to read a single order.
     *
     * Admins can view any order. Customers can view their own orders.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_item_permissions_check( $request ) {
        $rl = $this->enforce_rest_rate_limit( $request, 'orders', 60 );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        if ( \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return true;
        }

        if ( 0 === get_current_user_id() ) {
            return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'tejcart' ), array( 'status' => 401 ) );
        }

        $order = Order_Factory::get_order( (int) $request->get_param( 'id' ) );

        if ( $order && $order->get_customer_id() === get_current_user_id() ) {
            return true;
        }

        return new WP_Error(
            'tejcart_rest_cannot_view_order',
            __( 'Sorry, you are not allowed to view this order.', 'tejcart' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Check if a given request has access to create an order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function create_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_create_order',
                __( 'Sorry, you are not allowed to create orders.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'orders', 30 );
    }

    /**
     * Check if a given request has access to update an order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function update_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_update_order',
                __( 'Sorry, you are not allowed to update orders.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'orders', 30 );
    }

    /**
     * Check if a given request has access to refund an order.
     *
     * Refunds gate on the more granular REFUND_ORDERS cap so a
     * shop manager can list/view orders without being trusted to
     * move money. Mirrors Order_Refunds_Controller's posture.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function refund_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::REFUND_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_refund_order',
                __( 'Sorry, you are not allowed to process refunds.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'orders', 30 );
    }

    /**
     * Check if a given request has access to read order notes.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_notes_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_view_notes',
                __( 'Sorry, you are not allowed to view order notes.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'orders', 60 );
    }

    /**
     * Get a collection of orders.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_items( $request ) {
        global $wpdb;

        // Optional filter params have no schema default, so an omitted param
        // arrives as null. Cast to string before the WP sanitizers / strtoupper
        // run — passing null to those is a PHP 8.1+ deprecation that, with
        // display_errors on, prints an HTML warning ahead of the JSON body and
        // makes the response unparseable for clients.
        $args = array(
            'status'         => sanitize_text_field( (string) $request->get_param( 'status' ) ),
            'customer_email' => sanitize_email( (string) $request->get_param( 'customer_email' ) ),
            'date_from'      => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
            'date_to'        => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
            'per_page'       => max( 1, min( absint( $request->get_param( 'per_page' ) ?: 10 ), 100 ) ),
            'page'           => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
            'orderby'        => sanitize_text_field( (string) $request->get_param( 'orderby' ) ) ?: 'created_at',
            'order'          => strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
        );

        $orders = $this->order_manager->get_orders( $args );

        $data = array();

        foreach ( $orders as $order ) {
            $item_data = $this->prepare_item_for_response( $order, $request );
            $data[]    = $this->prepare_response_for_collection( $item_data );
        }

        $table        = $wpdb->prefix . 'tejcart_orders';
        $where        = array( '1=1' );
        $where_values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]        = 'status = %s';
            $where_values[] = $args['status'];
        }

        if ( ! empty( $args['customer_email'] ) ) {
            $where[]        = 'customer_email = %s';
            $where_values[] = $args['customer_email'];
        }

        if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
            $where[]        = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
            $where[]        = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $where_values ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $where_values )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
        }

        $max_pages = (int) ceil( $total / $args['per_page'] );

        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', $max_pages );

        return $response;
    }

    /**
     * Get a single order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $order = Order_Factory::get_order( (int) $request->get_param( 'id' ) );

        if ( ! $order ) {
            return new WP_Error(
                'tejcart_rest_order_not_found',
                __( 'Order not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        // Defense in depth: every byte of order PII this handler returns is
        // otherwise gated by a single permission_callback. Re-assert ownership
        // here so a future routing/registration change can't turn this into an
        // unauthenticated IDOR. Staff with MANAGE_ORDERS may view any order; a
        // logged-in customer may view only their own.
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS )
            && ( 0 === get_current_user_id() || $order->get_customer_id() !== get_current_user_id() ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_view_order',
                __( 'Sorry, you are not allowed to view this order.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $response = $this->prepare_item_for_response( $order, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Create an order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( $request ) {
        $data = array();

        $fields = array(
            'status', 'currency', 'payment_method', 'transaction_id',
            'coupon_code', 'customer_email', 'customer_name', 'customer_note',
        );

        foreach ( $fields as $field ) {
            $value = $request->get_param( $field );

            if ( null !== $value ) {
                $data[ $field ] = sanitize_text_field( $value );
            }
        }

        $customer_id = $request->get_param( 'customer_id' );
        if ( null !== $customer_id ) {
            $data['customer_id'] = absint( $customer_id );
        }

        $billing_address = $request->get_param( 'billing_address' );
        if ( null !== $billing_address ) {
            $data['billing_address'] = is_array( $billing_address ) ? wp_json_encode( $billing_address ) : sanitize_text_field( $billing_address );
        }

        $shipping_address = $request->get_param( 'shipping_address' );
        if ( null !== $shipping_address ) {
            $data['shipping_address'] = is_array( $shipping_address ) ? wp_json_encode( $shipping_address ) : sanitize_text_field( $shipping_address );
        }

        $ip = $request->get_param( 'ip_address' );
        if ( null !== $ip ) {
            $data['ip_address'] = sanitize_text_field( $ip );
        }
        if ( ! isset( $data['ip_address'] ) || '' === (string) $data['ip_address'] ) {
            // REST callers that omit the field still get the request's
            // REMOTE_ADDR (or "cli" under WP-CLI) so IP-locked downloads,
            // geo-fraud scoring, and forensics never see an empty column.
            $data['ip_address'] = \TejCart\Order\Order_Factory::resolve_ip();
        }

        $line_items_param = $request->get_param( 'line_items' );
        if ( null === $line_items_param ) {
            $line_items_param = $request->get_param( 'items' );
        }

        $resolved_items = $this->resolve_line_items( is_array( $line_items_param ) ? $line_items_param : array() );

        // Storefront "place order" path: when the caller supplies no explicit
        // line items, fall back to the active cart so a POST that follows
        // add-to-cart actually reflects the cart's contents. Programmatic
        // callers that pass line_items/items are unaffected.
        if ( empty( $resolved_items ) ) {
            $resolved_items = $this->resolve_line_items_from_cart();
        }

        // An order with no resolvable line items is meaningless (and would
        // poison totals / refund caps downstream). Reject it rather than
        // persisting an empty order.
        if ( empty( $resolved_items ) ) {
            return new WP_Error(
                'tejcart_rest_order_empty',
                __( 'Cannot create an order with no line items.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $subtotal = 0.0;
        foreach ( $resolved_items as $item ) {
            $subtotal += (float) $item['line_total'];
        }
        $subtotal = round( $subtotal, 2 );

        // Resolve the buyer email for coupon validation. Prefer the explicit
        // customer_email field, then the storefront `billing` payload, then a
        // billing_address email. Needed so per-user coupon limits and email
        // restrictions are enforced at order placement, not just at cart apply.
        $buyer_email   = isset( $data['customer_email'] ) ? sanitize_email( (string) $data['customer_email'] ) : '';
        $billing_param = $request->get_param( 'billing' );
        if ( '' === $buyer_email && is_array( $billing_param ) && ! empty( $billing_param['email'] ) ) {
            $buyer_email = sanitize_email( (string) $billing_param['email'] );
        }
        if ( '' === $buyer_email && is_array( $billing_address ) && ! empty( $billing_address['email'] ) ) {
            $buyer_email = sanitize_email( (string) $billing_address['email'] );
        }

        $discount_total   = 0.0;
        $coupon_to_record = null;
        $coupon_code      = isset( $data['coupon_code'] ) ? (string) $data['coupon_code'] : '';
        if ( '' !== $coupon_code ) {
            $coupon = \TejCart\Coupon\Coupon::get_by_code( $coupon_code );
            if ( $coupon instanceof \TejCart\Coupon\Coupon && $coupon->get_id() ) {
                // Reject the order when the coupon is not valid for this buyer
                // (usage limit, per-user limit, email restriction, min/max
                // spend, expiry). Mirrors the storefront checkout, which won't
                // place an order on an invalid coupon.
                $validity = $coupon->is_valid( $buyer_email, $subtotal );
                if ( is_wp_error( $validity ) ) {
                    $validity->add_data( array( 'status' => 400 ) );
                    return $validity;
                }
                $discount_total   = $this->calculate_coupon_discount(
                    $coupon_code,
                    $subtotal,
                    isset( $data['currency'] ) ? (string) $data['currency'] : ''
                );
                $coupon_to_record = $coupon;
            }
        }

        $shipping_total = 0.0;

        // Tax is *computed* server-side (never trusted from the request):
        // the schema marks every total readonly so a REST caller can't
        // overwrite them and bypass the capture-mismatch / refund-cap
        // guards (finding C-2). Previously this was hardcoded to 0.0, so
        // every REST-created order shipped with zero tax even though the
        // schema advertises tax as "computed at order-creation time".
        $tax_total = $this->compute_order_tax(
            max( 0.0, $subtotal - $discount_total ),
            is_array( $billing_address ) ? $billing_address : array(),
            is_array( $shipping_address ) ? $shipping_address : array(),
            isset( $data['currency'] ) ? (string) $data['currency'] : ''
        );

        $total = max( 0.0, round( $subtotal - $discount_total + $shipping_total + $tax_total, 2 ) );

        $data['subtotal']       = $subtotal;
        $data['discount_total'] = round( $discount_total, 2 );
        $data['shipping_total'] = $shipping_total;
        $data['tax_total']      = $tax_total;
        $data['total']          = $total;
        $data['items']          = $resolved_items;

        $order = Order_Factory::create( $data );

        if ( ! $order ) {
            return new WP_Error(
                'tejcart_rest_order_create_failed',
                __( 'Could not create the order.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        // Record coupon consumption so usage limits actually bite on the next
        // application/order. Mirrors Checkout::process(): bump the global
        // usage_count and the per-customer counter. Both are atomic and
        // self-clamping; failures here must not unwind a placed order.
        if ( $coupon_to_record instanceof \TejCart\Coupon\Coupon ) {
            $coupon_to_record->increment_usage();
            if ( '' !== $buyer_email ) {
                $coupon_to_record->reserve_usage_for_user( $buyer_email );
            }
        }

        // Fire the post-order processed action so addons that depend
        // on the canonical Checkout::process() pipeline still see
        // REST-created orders. The Subscriptions Checkout_Integration
        // listens here to create subscription rows for any
        // Subscription_Product line item; without this, an admin who
        // POSTs a subscription product to /wp-json/tejcart/v1/orders
        // gets an order with no subscription record.
        do_action( 'tejcart_checkout_order_processed', (int) $order->get_id(), array() );

        $response = $this->prepare_item_for_response( $order, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Update an existing order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $order = Order_Factory::get_order( (int) $request->get_param( 'id' ) );

        if ( ! $order ) {
            return new WP_Error(
                'tejcart_rest_order_not_found',
                __( 'Order not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $new_status = $request->get_param( 'status' );
        if ( null !== $new_status && $new_status !== $order->get_status() ) {
            $status_note = $request->get_param( 'status_note' ) ?: '';
            $result      = $order->update_status( sanitize_text_field( $new_status ), sanitize_text_field( $status_note ) );

            if ( ! $result ) {
                return new WP_Error(
                    'tejcart_rest_order_status_update_failed',
                    __( 'Could not update the order status.', 'tejcart' ),
                    array( 'status' => 500 )
                );
            }
        }

        // Public PATCH surface — only the fields a merchant actually
        // edits by hand. Totals (subtotal / discount_total / shipping_total /
        // tax_total / total) are deliberately absent: letting a REST caller
        // overwrite them mid-flight bypasses the capture-mismatch guard in
        // PayPal_AJAX::capture_order, poisons refund caps, and silently
        // breaks reconciliation. ip_address, customer_id, transaction_id
        // are also out — they are forensic / audit-trail evidence written
        // exactly once at order creation. See review finding C-2.
        $setters = array(
            'currency'         => 'set_currency',
            'payment_method'   => 'set_payment_method',
            'coupon_code'      => 'set_coupon_code',
            'customer_email'   => 'set_customer_email',
            'customer_name'    => 'set_customer_name',
            'billing_address'  => 'set_billing_address',
            'shipping_address' => 'set_shipping_address',
            'customer_note'    => 'set_customer_note',
        );

        /**
         * Filter the allow-list of order fields a REST PATCH may write.
         *
         * Adding a key here exposes it on `PUT /tejcart/v1/orders/{id}`.
         * The default list intentionally excludes totals, customer_id,
         * transaction_id, and ip_address — those are written at order
         * creation and altering them post-create defeats reconciliation
         * and forensic evidence. Merchants who genuinely need to adjust
         * totals (manual fee, refund-without-gateway, etc.) should use
         * a dedicated endpoint that re-derives totals from line items.
         *
         * @since 1.0.1
         *
         * @param array<string,string> $setters    param => Order setter method.
         * @param \TejCart\Order\Order $order      The order being updated.
         * @param \WP_REST_Request     $request    Incoming request.
         */
        $setters = (array) apply_filters( 'tejcart_rest_order_update_setters', $setters, $order, $request );

        $has_changes = false;

        foreach ( $setters as $param => $method ) {
            $value = $request->get_param( $param );

            if ( null !== $value && method_exists( $order, $method ) ) {
                $order->$method( $value );
                $has_changes = true;
            }
        }

        $meta_data = $request->get_param( 'meta_data' );
        if ( is_array( $meta_data ) ) {
            foreach ( $meta_data as $meta ) {
                if ( ! isset( $meta['key'], $meta['value'] ) ) {
                    continue;
                }

                $key = sanitize_text_field( (string) $meta['key'] );

                // Reject empty keys and any underscore-prefixed key.
                // The `_*` namespace is reserved for plugin / gateway state
                // (`_paypal_capture_id`, `_paypal_refunds`,
                // `_shipping_method_id`, `_tejcart_predispute_status`, …).
                // Letting a REST caller overwrite those would let an
                // attacker (or a compromised admin token) point an order
                // at a different capture, hide refunds, or move it out of
                // dispute on-hold. See review finding C-2.
                if ( '' === $key || '_' === $key[0] ) {
                    continue;
                }

                /**
                 * Filter whether a meta_data key may be written via the
                 * REST order update endpoint.
                 *
                 * Default: true for every non-underscore key. Return false
                 * to reject. Useful for stores that want to lock down
                 * additional custom keys (e.g. accounting integration meta).
                 *
                 * @since 1.0.1
                 *
                 * @param bool                 $allowed Whether the key may be written.
                 * @param string               $key     Sanitised meta key.
                 * @param mixed                $value   Raw value (pre-sanitisation).
                 * @param \TejCart\Order\Order $order   Order being updated.
                 */
                $allowed = (bool) apply_filters(
                    'tejcart_rest_order_meta_writable',
                    true,
                    $key,
                    $meta['value'],
                    $order
                );
                if ( ! $allowed ) {
                    continue;
                }

                $sanitized_value = is_scalar( $meta['value'] )
                    ? sanitize_text_field( $meta['value'] )
                    : wp_json_encode( $meta['value'] );
                $order->update_meta( $key, $sanitized_value );
            }
        }

        if ( $has_changes ) {
            $saved = $order->save();

            if ( ! $saved ) {
                return new WP_Error(
                    'tejcart_rest_order_update_failed',
                    __( 'Could not update the order.', 'tejcart' ),
                    array( 'status' => 500 )
                );
            }
        }

        $response = $this->prepare_item_for_response( $order, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Process a refund for an order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function refund_item( $request ) {
        $order_id = (int) $request->get_param( 'id' );
        $amount   = (float) $request->get_param( 'amount' );
        $reason   = sanitize_text_field( $request->get_param( 'reason' ) );

        if ( $amount <= 0 ) {
            return new WP_Error(
                'tejcart_rest_invalid_refund_amount',
                __( 'Refund amount must be greater than zero.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $order = Order_Factory::get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error(
                'tejcart_rest_order_not_found',
                __( 'Order not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        // M-6 / L-2: route through the canonical
        // Order_Refund::remaining_refundable_minor helper.
        $order_total          = (float) $order->get_total();
        $order_currency       = strtoupper( (string) $order->get_currency() );
        $remaining_minor      = \TejCart\Order\Order_Refund::remaining_refundable_minor( $order_id, $order_currency, $order_total );
        $requested_minor      = \TejCart\Money\Currency::to_minor_units( $amount, $order_currency );
        $remaining_refundable = max( 0.0, $order_total - (float) \TejCart\Order\Order_Refund::get_total_refunded( $order_id ) );

        if ( $requested_minor > $remaining_minor ) {
            return new WP_Error(
                'invalid_refund',
                sprintf(
                    /* translators: 1: refund amount, 2: remaining refundable amount */
                    __( 'Refund amount (%1$s) exceeds the remaining refundable amount (%2$s).', 'tejcart' ),
                    number_format( $amount, 2 ),
                    number_format( $remaining_refundable, 2 )
                ),
                array( 'status' => 400 )
            );
        }

        $result = $this->order_manager->process_refund( $order_id, $amount, $reason );

        if ( ! $result ) {
            return new WP_Error(
                'tejcart_rest_refund_failed',
                __( 'Could not process the refund.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response( array(
            'id'      => $order_id,
            'amount'  => $amount,
            'reason'  => $reason,
            'success' => true,
        ) );
    }

    /**
     * Get notes for an order.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_notes( $request ) {
        global $wpdb;

        $order_id = (int) $request->get_param( 'id' );
        $order    = Order_Factory::get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error(
                'tejcart_rest_order_not_found',
                __( 'Order not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows  = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$table} WHERE order_id = %d AND meta_key = '_order_note' ORDER BY meta_id ASC",
                $order_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $notes = array();

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $note_data = json_decode( $row['meta_value'], true );
                if ( ! is_array( $note_data ) ) {
                    $note_data = maybe_unserialize( $row['meta_value'], array( 'allowed_classes' => false ) );
                }

                if ( is_array( $note_data ) ) {
                    $notes[] = array(
                        'id'               => (int) $row['meta_id'],
                        'note'             => isset( $note_data['note'] ) ? wp_kses_post( $note_data['note'] ) : '',
                        'is_customer_note' => isset( $note_data['is_customer_note'] ) ? (bool) $note_data['is_customer_note'] : false,
                        'date'             => isset( $note_data['date'] ) ? sanitize_text_field( $note_data['date'] ) : '',
                        'author'           => isset( $note_data['author'] ) ? (int) $note_data['author'] : 0,
                    );
                }
            }
        }

        return rest_ensure_response( $notes );
    }

    /**
     * Prepare a single order for response.
     *
     * @param Order           $order   Order object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function prepare_item_for_response( $order, $request ) {
        $items      = $order->get_items();
        $items_data = array();

        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                $items_data[] = array(
                    'id'         => isset( $item->id ) ? (int) $item->id : 0,
                    'product_id' => isset( $item->product_id ) ? (int) $item->product_id : 0,
                    'name'       => isset( $item->name ) ? $item->name : '',
                    'quantity'   => isset( $item->quantity ) ? (int) $item->quantity : 0,
                    'price'      => isset( $item->price ) ? (float) $item->price : 0.0,
                    'total'      => isset( $item->total ) ? (float) $item->total : 0.0,
                );
            }
        }

        // Emit money fields in both the legacy major-unit decimal form
        // (back-compat for every existing consumer) and the canonical
        // integer-minor-units form (`*_minor_units`), with `currency`
        // alongside so machine consumers can reconcile against gateway
        // amounts without re-parsing strings. See spec §2.7 in
        // docs/money-representation.md.
        $data = array(
            'id'                     => $order->get_id(),
            'order_key'              => $order->get_order_key(),
            'order_number'           => $order->get_order_number(),
            'status'                 => $order->get_status(),
            'currency'               => $order->get_currency(),
            'subtotal'               => $order->get_subtotal(),
            'subtotal_minor_units'   => $order->get_subtotal_money()->as_minor_units(),
            'discount_total'         => $order->get_discount_total(),
            'discount_total_minor_units' => $order->get_discount_total_money()->as_minor_units(),
            'shipping_total'         => $order->get_shipping_total(),
            'shipping_total_minor_units' => $order->get_shipping_total_money()->as_minor_units(),
            'tax_total'              => $order->get_tax_total(),
            'tax_total_minor_units'  => $order->get_tax_total_money()->as_minor_units(),
            'total'                  => $order->get_total(),
            'total_minor_units'      => $order->get_total_money()->as_minor_units(),
            'payment_method'         => $order->get_payment_method(),
            'transaction_id'         => $order->get_transaction_id(),
            'coupon_code'            => $order->get_coupon_code(),
            'customer_id'            => $order->get_customer_id(),
            'customer_email'         => $order->get_customer_email(),
            'customer_name'          => $order->get_customer_name(),
            'billing_address'        => $order->get_billing_address(),
            'shipping_address'       => $order->get_shipping_address(),
            'customer_note'          => $order->get_customer_note(),
            'ip_address'             => $order->get_ip_address(),
            'items'                  => $items_data,
            // Grouped totals mirror of the flat *_total fields above. Clients
            // that prefer a single object (and the API-schema contract) read
            // order.totals.total rather than the individual top-level keys.
            'totals'                 => array(
                'subtotal'       => $order->get_subtotal(),
                'discount_total' => $order->get_discount_total(),
                'shipping_total' => $order->get_shipping_total(),
                'tax_total'      => $order->get_tax_total(),
                'total'          => $order->get_total(),
            ),
            'created_at'             => $order->get_created_at(),
            'updated_at'             => $order->get_updated_at(),
        );

        $response = rest_ensure_response( $data );

        /**
         * Filter the order data returned by the REST API.
         *
         * @param WP_REST_Response             $response The response object.
         * @param \TejCart\Order\Order         $order    The order object.
         * @param WP_REST_Request              $request  The request object.
         */
        return apply_filters( 'tejcart_rest_prepare_order', $response, $order, $request );
    }

    /**
     * Get the order schema, conforming to JSON Schema.
     *
     * @return array
     */
    public function get_item_schema() {
        $schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'order',
            'type'       => 'object',
            'properties' => array(
                'id'               => array(
                    'description' => __( 'Unique identifier for the order.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'order_key'        => array(
                    'description' => __( 'Order key.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'order_number'     => array(
                    'description' => __( 'Order number.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'status'           => array(
                    'description' => __( 'Order status.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'currency'         => array(
                    'description' => __( 'Currency code.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                // Totals are derived from line items + tax + shipping
                // and are computed at order-creation time. Marking them
                // readonly here means `get_endpoint_args_for_item_schema()`
                // does not advertise them as REST-writable, and the
                // controller's `update_item` setters allow-list also
                // refuses to overwrite them. See review finding C-2.
                'subtotal'                   => array(
                    'description' => __( 'Order subtotal (major-unit decimal; back-compat).', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'subtotal_minor_units'       => array(
                    'description' => __( 'Order subtotal in the order currency minor units (integer, canonical).', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'discount_total'             => array(
                    'description' => __( 'Total discount amount (major-unit decimal; back-compat).', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'discount_total_minor_units' => array(
                    'description' => __( 'Total discount in minor units (integer, canonical).', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'shipping_total'             => array(
                    'description' => __( 'Shipping total (major-unit decimal; back-compat).', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'shipping_total_minor_units' => array(
                    'description' => __( 'Shipping total in minor units (integer, canonical).', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'tax_total'                  => array(
                    'description' => __( 'Tax total (major-unit decimal; back-compat).', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'tax_total_minor_units'      => array(
                    'description' => __( 'Tax total in minor units (integer, canonical).', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'total'                      => array(
                    'description' => __( 'Order total (major-unit decimal; back-compat).', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'total_minor_units'          => array(
                    'description' => __( 'Order total in minor units (integer, canonical).', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'payment_method'   => array(
                    'description' => __( 'Payment method identifier.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                // Transaction ID is the gateway's own reference for the
                // captured payment, written exactly once at capture time.
                // Mutating it post-capture poisons reconciliation and
                // refund lookup paths. Readonly via REST.
                'transaction_id'   => array(
                    'description' => __( 'Transaction ID from payment gateway.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'coupon_code'      => array(
                    'description' => __( 'Applied coupon code.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                // customer_id is forensic evidence; the
                // checkout / guest-linker flows already write it and we
                // don't want a REST PATCH re-attributing an order to a
                // different account.
                'customer_id'      => array(
                    'description' => __( 'Customer user ID.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'customer_email'   => array(
                    'description' => __( 'Customer email address.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'email',
                    'context'     => array( 'view', 'edit' ),
                ),
                'customer_name'    => array(
                    'description' => __( 'Customer name.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'billing_address'  => array(
                    'description' => __( 'Billing address.', 'tejcart' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                ),
                'shipping_address' => array(
                    'description' => __( 'Shipping address.', 'tejcart' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                ),
                'customer_note'    => array(
                    'description' => __( 'Customer note.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'ip_address'       => array(
                    'description' => __( 'Customer IP address.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'items'            => array(
                    'description' => __( 'Order line items.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'         => array( 'type' => 'integer' ),
                            'product_id' => array( 'type' => 'integer' ),
                            'name'       => array( 'type' => 'string' ),
                            'quantity'   => array( 'type' => 'integer' ),
                            'price'      => array( 'type' => 'number' ),
                            'total'      => array( 'type' => 'number' ),
                        ),
                    ),
                ),
                'created_at'       => array(
                    'description' => __( 'Date the order was created.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'updated_at'       => array(
                    'description' => __( 'Date the order was last updated.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
            ),
        );

        return $this->add_additional_fields_schema( $schema );
    }

    /**
     * Get the query params for collections.
     *
     * @return array
     */
    public function get_collection_params(): array {
        $params = parent::get_collection_params();

        $params['status'] = array(
            'description' => __( 'Filter by order status.', 'tejcart' ),
            'type'        => 'string',
        );

        $params['customer_email'] = array(
            'description' => __( 'Filter by customer email.', 'tejcart' ),
            'type'        => 'string',
            'format'      => 'email',
        );

        $params['date_from'] = array(
            'description' => __( 'Filter orders created on or after this date (Y-m-d).', 'tejcart' ),
            'type'        => 'string',
            'format'      => 'date',
        );

        $params['date_to'] = array(
            'description' => __( 'Filter orders created on or before this date (Y-m-d).', 'tejcart' ),
            'type'        => 'string',
            'format'      => 'date',
        );

        $params['orderby'] = array(
            'description' => __( 'Sort collection by attribute.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'created_at',
            'enum'        => array( 'id', 'created_at', 'updated_at', 'total', 'status', 'order_number' ),
        );

        $params['order'] = array(
            'description' => __( 'Order sort attribute ascending or descending.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'DESC',
            'enum'        => array( 'ASC', 'DESC' ),
        );

        return \TejCart\API\Param_Sanitizers::decorate( $params );
    }

    /**
     * Build resolved line items from the active cart.
     *
     * Used by create_item() when the request carries no explicit line items,
     * so a REST "place order" call that followed add-to-cart reflects the
     * cart. Pricing is read from the live product (via the cart item), never
     * trusted from the client — same guarantee as resolve_line_items().
     *
     * @return array Resolved items ready for Order_Factory, or [] when the
     *               cart is empty / unavailable.
     */
    private function resolve_line_items_from_cart(): array {
        if ( ! function_exists( 'tejcart_get_cart' ) ) {
            return array();
        }

        $cart = tejcart_get_cart();
        if ( ! $cart || ! method_exists( $cart, 'get_items' ) ) {
            return array();
        }

        $resolved = array();
        foreach ( (array) $cart->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            $quantity   = (int) $item->get_quantity();
            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $unit_price = (float) $item->get_price();
            $line_total = method_exists( $item, 'get_line_total' )
                ? round( (float) $item->get_line_total(), 2 )
                : round( $unit_price * $quantity, 2 );

            $resolved[] = array(
                'product_id'   => $product_id,
                'product_name' => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
                'quantity'     => $quantity,
                'unit_price'   => $unit_price,
                'line_total'   => $line_total,
            );
        }

        return $resolved;
    }

    /**
     * Resolve a client-supplied line-items payload into trusted item rows.
     *
     * Each input item must include product_id and quantity; everything else —
     * name, unit price, line total — is read from the product record so the
     * client cannot forge pricing.
     *
     * @param array $items Raw line items from the request.
     * @return array Resolved items ready for Order_Factory.
     */
    private function resolve_line_items( array $items ): array {
        $resolved = array();

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity   = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

            if ( ! $product_id || ! $quantity ) {
                continue;
            }

            $product = Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $unit_price = (float) $product->get_price();
            $line_total = round( $unit_price * $quantity, 2 );

            $resolved[] = array(
                'product_id'   => $product_id,
                'product_name' => (string) $product->get_name(),
                'quantity'     => $quantity,
                'unit_price'   => $unit_price,
                'line_total'   => $line_total,
            );
        }

        return $resolved;
    }

    /**
     * Compute the tax owed on a REST-created order, server-side.
     *
     * Mirrors the storefront Cart_Calculator: it resolves the taxable
     * location from the `tejcart_tax_based_on` setting (billing / shipping
     * address, or the shop base address), then asks Tax_Manager for the
     * matching rate. Returns 0.0 when tax is disabled, no class-aware rate
     * matches the destination, or the Tax_Manager class is unavailable —
     * i.e. the prior hardcoded-zero behaviour is preserved for those cases.
     *
     * Note this is deliberately *computed*, never read from the request:
     * the order schema marks all totals readonly so a caller cannot inject
     * a tax figure (finding C-2).
     *
     * @param float                $taxable_amount   Subtotal minus discount.
     * @param array<string,mixed>  $billing_address  Posted billing address (free-form).
     * @param array<string,mixed>  $shipping_address Posted shipping address (free-form).
     * @param string               $currency         Order currency (for rounding precision).
     * @return float Tax amount in major units.
     */
    private function compute_order_tax( float $taxable_amount, array $billing_address, array $shipping_address, string $currency = '' ): float {
        if ( $taxable_amount <= 0 || ! class_exists( '\\TejCart\\Tax\\Tax_Manager' ) ) {
            return 0.0;
        }

        $tax_manager = new \TejCart\Tax\Tax_Manager();
        if ( ! $tax_manager->is_tax_enabled() ) {
            return 0.0;
        }

        $based_on = (string) get_option( 'tejcart_tax_based_on', 'billing_address' );
        if ( 'shipping_address' === $based_on ) {
            $address = ! empty( $shipping_address ) ? $shipping_address : $billing_address;
        } elseif ( 'store_address' === $based_on ) {
            $address = array(
                'country' => (string) get_option( 'tejcart_store_country', 'US' ),
                'state'   => (string) get_option( 'tejcart_store_state', '' ),
            );
        } else {
            $address = ! empty( $billing_address ) ? $billing_address : $shipping_address;
        }

        // Accept the common address-key conventions used across the plugin
        // (free-form REST input has no enforced schema), falling back to the
        // shop base country so a missing country never silently drops tax.
        $country = (string) (
            $address['country'] ?? $address['billing_country'] ?? $address['shipping_country']
            ?? get_option( 'tejcart_store_country', 'US' )
        );
        $state = (string) (
            $address['state'] ?? $address['billing_state'] ?? $address['shipping_state'] ?? ''
        );

        return (float) $tax_manager->calculate_tax( $taxable_amount, $country, $state, '', $currency );
    }

    /**
     * Calculate the discount for a coupon code against a given subtotal.
     *
     * Mirrors Cart::apply_coupon() lookup + validation so REST-side order
     * creation cannot bypass usage limits, expiry, or minimum-spend rules.
     *
     * @param string $code     Coupon code.
     * @param float  $subtotal Server-computed subtotal (in the order currency).
     * @param string $currency Order currency code; fixed amounts (stored in the
     *                         shop/base currency) are converted into it so the
     *                         discount matches the order's denomination.
     * @return float Discount amount (0.0 when invalid).
     */
    private function calculate_coupon_discount( string $code, float $subtotal, string $currency = '' ): float {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_coupons';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            return 0.0;
        }

        $coupon = new Coupon( (int) $row->id );
        $valid  = $coupon->is_valid( '', $subtotal );

        if ( is_wp_error( $valid ) ) {
            return 0.0;
        }

        $type   = $coupon->get_type();
        $amount = (float) $coupon->get_amount();

        if ( 'percentage' === $type ) {
            // Percent is a rate — it applies natively to the order-currency
            // subtotal, no conversion needed.
            return round( ( $subtotal * $amount ) / 100.0, 2 );
        }

        if ( 'free_shipping' === $type ) {
            return 0.0;
        }

        // Fixed amounts are stored in the shop/base currency. Convert into the
        // order's currency so the discount matches the (order-currency)
        // subtotal it is capped against. Passthrough when the order is in the
        // base currency or the currency switcher is inactive.
        $amount = (float) apply_filters( 'tejcart_amount_to_currency', $amount, $currency );

        return min( $subtotal, round( $amount, 2 ) );
    }
}
