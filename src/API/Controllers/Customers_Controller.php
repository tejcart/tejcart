<?php
/**
 * Customers REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the customers endpoint.
 */
class Customers_Controller extends WP_REST_Controller {
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
    protected $rest_base = 'customers';

    /**
     * Register routes for customers.
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
                            'description' => __( 'Unique identifier for the customer.', 'tejcart' ),
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
    }

    /**
     * Check if a given request has access to list customers.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_items_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_list_customers',
                __( 'Sorry, you are not allowed to list customers.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'customers', 60 );
    }

    /**
     * Check if a given request has access to read a single customer.
     *
     * Admins can view any customer. Users can view their own profile.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_item_permissions_check( $request ) {
        $rl = $this->enforce_rest_rate_limit( $request, 'customers', 60 );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        if ( \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return true;
        }

        $user_id = (int) $request->get_param( 'id' );

        if ( get_current_user_id() === $user_id ) {
            return true;
        }

        return new WP_Error(
            'tejcart_rest_cannot_view_customer',
            __( 'Sorry, you are not allowed to view this customer.', 'tejcart' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Check if a given request has access to update a customer.
     *
     * Admins can update any customer. Users can update their own profile.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function update_item_permissions_check( $request ) {
        $rl = $this->enforce_rest_rate_limit( $request, 'customers', 30 );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        // Site admins and any role with the canonical store-management
        // capability (e.g. shop_manager) can update any customer — this
        // mirrors get_items_permissions_check() / get_item_permissions_check()
        // so the listing and update scopes are consistent.
        if ( current_user_can( 'manage_options' )
            || \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return true;
        }

        $user_id = (int) $request->get_param( 'id' );

        if ( get_current_user_id() === $user_id ) {
            return true;
        }

        return new WP_Error(
            'tejcart_rest_cannot_update_customer',
            __( 'Sorry, you are not allowed to update this customer.', 'tejcart' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Get a collection of customers.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_items( $request ) {
        $per_page = max( 1, min( absint( $request->get_param( 'per_page' ) ?: 10 ), 100 ) );
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $search   = $request->get_param( 'search' );
        $allowed_orderby = array( 'id', 'registered', 'display_name', 'email', 'login' );
        $orderby_raw     = sanitize_text_field( $request->get_param( 'orderby' ) ) ?: 'registered';
        $orderby         = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'registered';
        $order           = strtoupper( $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $args = array(
            'number'  => $per_page,
            'paged'   => $page,
            'orderby' => $orderby,
            'order'   => $order,
        );

        if ( ! empty( $search ) ) {
            $args['search']         = '*' . sanitize_text_field( $search ) . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
        }

        $allowed_roles = array( 'customer', 'subscriber', 'administrator', 'shop_manager' );
        $role          = $request->get_param( 'role' );
        if ( ! empty( $role ) ) {
            $sanitized_role = sanitize_text_field( $role );
            if ( in_array( $sanitized_role, $allowed_roles, true ) ) {
                $args['role'] = $sanitized_role;
            } else {
                return new WP_Error(
                    'tejcart_rest_invalid_role',
                    __( 'Invalid role parameter.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }
        }

        $user_query = new WP_User_Query( $args );
        $users      = $user_query->get_results();
        $total      = $user_query->get_total();

        $data = array();

        foreach ( $users as $user ) {
            $item_data = $this->prepare_item_for_response( $user, $request );
            $data[]    = $this->prepare_response_for_collection( $item_data );
        }

        $max_pages = (int) ceil( $total / $per_page );

        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', $max_pages );

        return $response;
    }

    /**
     * Get a single customer.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $user_id = (int) $request->get_param( 'id' );
        $user    = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error(
                'tejcart_rest_customer_not_found',
                __( 'Customer not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $response = $this->prepare_item_for_response( $user, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Update a customer.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $user_id = (int) $request->get_param( 'id' );
        $user    = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error(
                'tejcart_rest_customer_not_found',
                __( 'Customer not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $userdata = array( 'ID' => $user_id );

        $first_name = $request->get_param( 'first_name' );
        if ( null !== $first_name ) {
            $userdata['first_name'] = sanitize_text_field( $first_name );
        }

        $last_name = $request->get_param( 'last_name' );
        if ( null !== $last_name ) {
            $userdata['last_name'] = sanitize_text_field( $last_name );
        }

        $display_name = $request->get_param( 'display_name' );
        if ( null !== $display_name ) {
            $userdata['display_name'] = sanitize_text_field( $display_name );
        }

        $email = $request->get_param( 'email' );
        $email_changed = false;
        $previous_email = (string) $user->user_email;
        if ( null !== $email ) {
            $sanitized_email = sanitize_email( $email );

            if ( ! is_email( $sanitized_email ) ) {
                return new WP_Error(
                    'tejcart_rest_invalid_email',
                    __( 'Invalid email address.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }

            $existing_user = email_exists( $sanitized_email );
            if ( $existing_user && $existing_user !== $user_id ) {
                return new WP_Error(
                    'tejcart_rest_email_exists',
                    __( 'This email address is already in use.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }

            // M-7: require a current-password challenge before a
            // customer can change their email. Without this, anyone
            // who briefly sits on the customer's session (XSS, kiosk
            // login leftover, stolen cookie) can pivot to full account
            // takeover by flipping the email and triggering WP's
            // password-reset flow against an attacker-controlled
            // address. Admins skip the challenge — they have
            // manage_options + their own audit trail.
            if ( strtolower( $sanitized_email ) !== strtolower( $previous_email ) ) {
                $email_changed = true;

                $is_admin = current_user_can( 'manage_options' );
                if ( ! $is_admin ) {
                    $current_password = (string) $request->get_param( 'current_password' );
                    if ( '' === $current_password
                         || ! wp_check_password( $current_password, $user->user_pass, $user_id )
                    ) {
                        return new WP_Error(
                            'tejcart_rest_password_required',
                            __( 'Please confirm your current password to change the email address on your account.', 'tejcart' ),
                            array( 'status' => 401 )
                        );
                    }
                }
            }

            $userdata['user_email'] = $sanitized_email;
        }

        $url = $request->get_param( 'url' );
        if ( null !== $url ) {
            $userdata['user_url'] = esc_url_raw( $url );
        }

        $result = wp_update_user( $userdata );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'tejcart_rest_customer_update_failed',
                $result->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // M-7: notify the previous email when the address changes so a
        // session-takeover that flips the email cannot do so silently.
        if ( $email_changed && '' !== $previous_email && is_email( $previous_email ) ) {
            // Notify via the templated Account_Email_Changed email so the
            // security notice is delivered as designed HTML (with the
            // email-log marker headers and per-message Content-Type) rather
            // than the legacy plain-text `wp_mail()` body.
            $notice = new \TejCart\Email\Emails\Account_Email_Changed();
            $notice->trigger( $previous_email, (string) $userdata['user_email'] );

            /**
             * Fires after a customer's email has been changed via REST.
             * Listeners can extend the notification (Slack, audit log,
             * a one-click revert link tied to a HMAC token, etc.).
             *
             * @since 1.0.1
             *
             * @param int    $user_id        WP user ID whose email changed.
             * @param string $previous_email Email address before the change.
             * @param string $new_email      Email address after the change.
             */
            do_action( 'tejcart_rest_customer_email_changed', $user_id, $previous_email, (string) $userdata['user_email'] );
        }

        $billing_address = $request->get_param( 'billing_address' );
        if ( is_array( $billing_address ) ) {
            $billing_fields = array(
                'first_name', 'last_name', 'company', 'address_1',
                'address_2', 'city', 'state', 'postcode', 'country', 'phone',
            );

            foreach ( $billing_fields as $field ) {
                if ( isset( $billing_address[ $field ] ) ) {
                    update_user_meta( $user_id, 'tejcart_billing_' . $field, sanitize_text_field( $billing_address[ $field ] ) );
                }
            }
        }

        $shipping_address = $request->get_param( 'shipping_address' );
        if ( is_array( $shipping_address ) ) {
            $shipping_fields = array(
                'first_name', 'last_name', 'company', 'address_1',
                'address_2', 'city', 'state', 'postcode', 'country',
            );

            foreach ( $shipping_fields as $field ) {
                if ( isset( $shipping_address[ $field ] ) ) {
                    update_user_meta( $user_id, 'tejcart_shipping_' . $field, sanitize_text_field( $shipping_address[ $field ] ) );
                }
            }
        }

        $user     = get_userdata( $user_id );
        $response = $this->prepare_item_for_response( $user, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Prepare a single customer for response.
     *
     * @param WP_User         $user    User object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function prepare_item_for_response( $user, $request ) {
        $billing_fields = array(
            'first_name', 'last_name', 'company', 'address_1',
            'address_2', 'city', 'state', 'postcode', 'country', 'phone',
        );

        $billing_address = array();
        foreach ( $billing_fields as $field ) {
            $billing_address[ $field ] = get_user_meta( $user->ID, 'tejcart_billing_' . $field, true );
        }

        $shipping_fields = array(
            'first_name', 'last_name', 'company', 'address_1',
            'address_2', 'city', 'state', 'postcode', 'country',
        );

        $shipping_address = array();
        foreach ( $shipping_fields as $field ) {
            $shipping_address[ $field ] = get_user_meta( $user->ID, 'tejcart_shipping_' . $field, true );
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order_count  = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_table} WHERE customer_id = %d", $user->ID )
        );

        // SUM(total) is now BIGINT minor units in the order's currency.
        // For the customer-aggregate view we assume the shop currency
        // (multi-currency stores would want per-currency buckets — out
        // of M1 scope per docs/money-representation.md §3).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_spent_minor = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT COALESCE(SUM(total), 0) FROM {$orders_table} WHERE customer_id = %d AND status NOT IN ('cancelled', 'refunded')", $user->ID )
        );
        $shop_currency = function_exists( 'tejcart_get_currency' )
            ? (string) tejcart_get_currency()
            : (string) get_option( 'tejcart_currency', 'USD' );
        $total_spent   = \TejCart\Money\Currency::from_minor_units( $total_spent_minor, $shop_currency );

        $data = array(
            'id'                     => $user->ID,
            'email'                  => $user->user_email,
            'first_name'             => $user->first_name,
            'last_name'              => $user->last_name,
            'display_name'           => $user->display_name,
            'username'               => $user->user_login,
            'url'                    => $user->user_url,
            'registered_date'        => $user->user_registered,
            'role'                   => ! empty( $user->roles ) ? $user->roles[0] : '',
            'billing_address'        => $billing_address,
            'shipping_address'       => $shipping_address,
            'order_count'            => $order_count,
            'total_spent'            => $total_spent,
            'total_spent_minor_units' => $total_spent_minor,
            'currency'               => $shop_currency,
            'avatar_url'             => get_avatar_url( $user->ID ),
        );

        $response = rest_ensure_response( $data );

        /**
         * Filter the customer data returned by the REST API.
         *
         * @param WP_REST_Response $response The response object.
         * @param WP_User          $user     The user object.
         * @param WP_REST_Request  $request  The request object.
         */
        return apply_filters( 'tejcart_rest_prepare_customer', $response, $user, $request );
    }

    /**
     * Get the customer schema, conforming to JSON Schema.
     *
     * @return array
     */
    public function get_item_schema() {
        $schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'customer',
            'type'       => 'object',
            'properties' => array(
                'id'               => array(
                    'description' => __( 'Unique identifier for the customer.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'email'            => array(
                    'description' => __( 'Customer email address.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'email',
                    'context'     => array( 'view', 'edit' ),
                ),
                'first_name'       => array(
                    'description' => __( 'Customer first name.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'last_name'        => array(
                    'description' => __( 'Customer last name.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'display_name'     => array(
                    'description' => __( 'Customer display name.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'username'         => array(
                    'description' => __( 'Customer username.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'url'              => array(
                    'description' => __( 'Customer URL.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'uri',
                    'context'     => array( 'view', 'edit' ),
                ),
                'registered_date'  => array(
                    'description' => __( 'Date the customer registered.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'role'             => array(
                    'description' => __( 'Customer role.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'billing_address'  => array(
                    'description' => __( 'Billing address.', 'tejcart' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                    'properties'  => array(
                        'first_name' => array( 'type' => 'string' ),
                        'last_name'  => array( 'type' => 'string' ),
                        'company'    => array( 'type' => 'string' ),
                        'address_1'  => array( 'type' => 'string' ),
                        'address_2'  => array( 'type' => 'string' ),
                        'city'       => array( 'type' => 'string' ),
                        'state'      => array( 'type' => 'string' ),
                        'postcode'   => array( 'type' => 'string' ),
                        'country'    => array( 'type' => 'string' ),
                        'phone'      => array( 'type' => 'string' ),
                    ),
                ),
                'shipping_address' => array(
                    'description' => __( 'Shipping address.', 'tejcart' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                    'properties'  => array(
                        'first_name' => array( 'type' => 'string' ),
                        'last_name'  => array( 'type' => 'string' ),
                        'company'    => array( 'type' => 'string' ),
                        'address_1'  => array( 'type' => 'string' ),
                        'address_2'  => array( 'type' => 'string' ),
                        'city'       => array( 'type' => 'string' ),
                        'state'      => array( 'type' => 'string' ),
                        'postcode'   => array( 'type' => 'string' ),
                        'country'    => array( 'type' => 'string' ),
                    ),
                ),
                'order_count'      => array(
                    'description' => __( 'Number of orders placed by the customer.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'total_spent'      => array(
                    'description' => __( 'Total amount spent by the customer.', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'avatar_url'       => array(
                    'description' => __( 'Avatar URL.', 'tejcart' ),
                    'type'        => 'string',
                    'format'      => 'uri',
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

        $params['role'] = array(
            'description' => __( 'Filter by user role.', 'tejcart' ),
            'type'        => 'string',
        );

        $params['orderby'] = array(
            'description' => __( 'Sort collection by attribute.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'registered',
            'enum'        => array( 'id', 'registered', 'display_name', 'email', 'login' ),
        );

        $params['order'] = array(
            'description' => __( 'Order sort attribute ascending or descending.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'DESC',
            'enum'        => array( 'ASC', 'DESC' ),
        );

        return \TejCart\API\Param_Sanitizers::decorate( $params );
    }
}
