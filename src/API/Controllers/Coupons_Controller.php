<?php
/**
 * Coupons REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use TejCart\Coupon\Coupon;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/coupons.
 *
 * Supports CRUD against the tejcart_coupons table so headless/POS/ERP
 * integrations can manage promotions programmatically.
 */
class Coupons_Controller extends WP_REST_Controller {
    use REST_Rate_Limit;

    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'coupons';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => $this->get_writable_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => $this->get_writable_args(),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Read-side permission check: admin + 60/min rate limit.
     */
    public function read_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to list coupons.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        return $this->enforce_rest_rate_limit( $request, 'coupons_read', 60 );
    }

    /**
     * Write-side permission check: admin + 30/min rate limit.
     */
    public function write_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to modify coupons.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        return $this->enforce_rest_rate_limit( $request, 'coupons_write', 30 );
    }

    /**
     * List coupons.
     */
    public function get_items( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';

        $per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $offset   = ( $page - 1 ) * $per_page;

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $items = array();
        foreach ( (array) $rows as $row ) {
            $coupon = new Coupon( (int) $row->id );
            if ( $coupon->get_id() ) {
                $items[] = $this->prepare_coupon_for_response( $coupon );
            }
        }

        return rest_ensure_response( $items );
    }

    /**
     * Fetch a single coupon.
     */
    public function get_item( $request ) {
        $coupon = new Coupon( (int) $request['id'] );
        if ( ! $coupon->get_id() ) {
            return new WP_Error( 'tejcart_coupon_not_found', __( 'Coupon not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_coupon_for_response( $coupon ) );
    }

    /**
     * Create a new coupon.
     */
    public function create_item( $request ) {
        $code = strtolower( trim( sanitize_text_field( (string) $request->get_param( 'code' ) ) ) );
        if ( '' === $code ) {
            return new WP_Error( 'tejcart_coupon_missing_code', __( 'Coupon code is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        if ( $this->code_exists( $code ) ) {
            return new WP_Error( 'tejcart_coupon_duplicate_code', __( 'A coupon with that code already exists.', 'tejcart' ), array( 'status' => 409 ) );
        }

        $coupon = new Coupon();
        $applied = $this->apply_request_to_coupon( $coupon, $request );
        if ( is_wp_error( $applied ) ) {
            return $applied;
        }

        if ( ! $coupon->save() ) {
            return new WP_Error( 'tejcart_coupon_save_failed', __( 'Could not save the coupon.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $response = rest_ensure_response( $this->prepare_coupon_for_response( $coupon ) );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * Update a coupon.
     */
    public function update_item( $request ) {
        $coupon = new Coupon( (int) $request['id'] );
        if ( ! $coupon->get_id() ) {
            return new WP_Error( 'tejcart_coupon_not_found', __( 'Coupon not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        if ( null !== $request->get_param( 'code' ) ) {
            $new_code = strtolower( trim( sanitize_text_field( (string) $request->get_param( 'code' ) ) ) );
            if ( '' === $new_code ) {
                return new WP_Error( 'tejcart_coupon_missing_code', __( 'Coupon code is required.', 'tejcart' ), array( 'status' => 400 ) );
            }
            if ( $this->code_exists( $new_code, $coupon->get_id() ) ) {
                return new WP_Error( 'tejcart_coupon_duplicate_code', __( 'A coupon with that code already exists.', 'tejcart' ), array( 'status' => 409 ) );
            }
        }

        $applied = $this->apply_request_to_coupon( $coupon, $request );
        if ( is_wp_error( $applied ) ) {
            return $applied;
        }

        if ( ! $coupon->save() ) {
            return new WP_Error( 'tejcart_coupon_save_failed', __( 'Could not save the coupon.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response( $this->prepare_coupon_for_response( $coupon ) );
    }

    /**
     * Is `$code` already used by another coupon?
     *
     * @param string $code       Lowercased, trimmed coupon code.
     * @param int    $exclude_id Coupon id to exclude from the lookup (for updates).
     */
    private function code_exists( string $code, int $exclude_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';
        // $table is composed from $wpdb->prefix and a constant suffix.
        if ( $exclude_id > 0 ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE code = %s AND id <> %d LIMIT 1",
                    $code,
                    $exclude_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
        return ! empty( $row );
    }

    /**
     * Delete a coupon.
     */
    public function delete_item( $request ) {
        $coupon = new Coupon( (int) $request['id'] );
        if ( ! $coupon->get_id() ) {
            return new WP_Error( 'tejcart_coupon_not_found', __( 'Coupon not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $payload = $this->prepare_coupon_for_response( $coupon );

        if ( ! $coupon->delete() ) {
            return new WP_Error( 'tejcart_coupon_delete_failed', __( 'Could not delete the coupon.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $payload,
            )
        );
    }

    /**
     * Copy writable fields from the request onto a Coupon instance.
     *
     * All numeric / boolean fields are coerced before they reach the model,
     * so even permissive JSON clients ("usage_limit":"5","amount":"ten")
     * cannot poison row values.
     *
     * @return WP_Error|null WP_Error when a supplied field is invalid (e.g. an
     *                       unknown discount type), null on success.
     */
    private function apply_request_to_coupon( Coupon $coupon, WP_REST_Request $request ) {
        if ( null !== $request->get_param( 'code' ) ) {
            $coupon->set_code( sanitize_text_field( (string) $request->get_param( 'code' ) ) );
        }

        // Accept `discount_type` as an alias for `type`: the storefront cart
        // endpoint and the public coupon shape both speak `discount_type`, so
        // honouring it here keeps the create/read contract symmetric. An
        // explicit value that isn't one of the known types is rejected (400)
        // rather than silently dropped, so bad input fails loudly at write
        // time instead of persisting a coupon with the wrong (default) type.
        $type_param = $request->get_param( 'discount_type' );
        if ( null === $type_param ) {
            $type_param = $request->get_param( 'type' );
        }
        if ( null !== $type_param && '' !== (string) $type_param ) {
            $type          = sanitize_key( (string) $type_param );
            $allowed_types = array( 'percentage', 'fixed', 'fixed_product', 'free_shipping' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                return new WP_Error(
                    'tejcart_coupon_invalid_type',
                    __( 'Invalid coupon discount type.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }
            $coupon->set_type( $type );
        }
        if ( null !== $request->get_param( 'amount' ) ) {
            $amount = (float) $request->get_param( 'amount' );
            $amount = max( 0.0, min( 999999.99, $amount ) );
            $coupon->set_amount( round( $amount, 4 ) );
        }
        if ( null !== $request->get_param( 'usage_limit' ) ) {
            $coupon->set_usage_limit( $this->nullable_nonneg_int( $request->get_param( 'usage_limit' ) ) );
        }
        if ( null !== $request->get_param( 'usage_limit_per_user' ) ) {
            $coupon->set_usage_limit_per_user( $this->nullable_nonneg_int( $request->get_param( 'usage_limit_per_user' ) ) );
        }
        if ( null !== $request->get_param( 'minimum_amount' ) ) {
            $coupon->set_minimum_amount( $this->nullable_nonneg_float( $request->get_param( 'minimum_amount' ) ) );
        }
        if ( null !== $request->get_param( 'maximum_amount' ) ) {
            $coupon->set_maximum_amount( $this->nullable_nonneg_float( $request->get_param( 'maximum_amount' ) ) );
        }
        if ( null !== $request->get_param( 'expires_at' ) ) {
            $coupon->set_expires_at( $this->sanitize_datetime( $request->get_param( 'expires_at' ) ) );
        }
        if ( null !== $request->get_param( 'status' ) ) {
            $status = sanitize_key( (string) $request->get_param( 'status' ) );
            if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
                $coupon->set_status( $status );
            }
        }
        if ( null !== $request->get_param( 'individual_use' ) && method_exists( $coupon, 'set_individual_use' ) ) {
            $coupon->set_individual_use( rest_sanitize_boolean( $request->get_param( 'individual_use' ) ) );
        }
        if ( null !== $request->get_param( 'exclude_sale_items' ) && method_exists( $coupon, 'set_exclude_sale_items' ) ) {
            $coupon->set_exclude_sale_items( rest_sanitize_boolean( $request->get_param( 'exclude_sale_items' ) ) );
        }
        if ( null !== $request->get_param( 'email_restrictions' ) && method_exists( $coupon, 'set_email_restrictions' ) ) {
            $raw = $request->get_param( 'email_restrictions' );
            if ( is_string( $raw ) ) {
                $raw = preg_split( '/[\s,]+/', $raw );
            }
            $clean = array();
            foreach ( (array) $raw as $entry ) {
                $entry = strtolower( trim( sanitize_text_field( (string) $entry ) ) );
                if ( '' !== $entry ) {
                    $clean[] = $entry;
                }
            }
            $coupon->set_email_restrictions( array_values( array_unique( $clean ) ) );
        }

        return null;
    }

    /**
     * Coerce to a non-negative integer or null (preserves "unlimited" semantics).
     */
    private function nullable_nonneg_int( $value ): ?int {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return max( 0, (int) $value );
    }

    /**
     * Coerce to a non-negative float or null.
     */
    private function nullable_nonneg_float( $value ): ?float {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return round( max( 0.0, (float) $value ), 4 );
    }

    /**
     * Sanitize a datetime input; only accept YYYY-MM-DD[ HH:MM:SS] or ISO8601.
     */
    private function sanitize_datetime( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }
        $value = sanitize_text_field( (string) $value );
        $ts    = strtotime( $value );
        if ( false === $ts || $ts < 0 ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }

    /**
     * Serialise a coupon for the API response.
     *
     * Adds the post-M1 canonical fields (`amount_minor_units`,
     * `percentage_basis_points`, `minimum_amount_minor_units`,
     * `maximum_amount_minor_units`, `currency`) alongside the legacy
     * back-compat floats so existing consumers keep working while new
     * consumers can use the exact integer representation. See
     * docs/money-representation.md §2.5 + §2.7.
     */
    private function prepare_coupon_for_response( Coupon $coupon ): array {
        $payload    = $coupon->to_array();

        // Expose the discount type under `discount_type` (the field name the
        // cart endpoint and public API use) in addition to the legacy `type`
        // key, so reads round-trip the value clients write.
        $payload['discount_type'] = isset( $payload['type'] ) ? $payload['type'] : $coupon->get_type();

        $amount_money = $coupon->get_amount_money();
        $min_money    = $coupon->get_minimum_amount_money();
        $max_money    = $coupon->get_maximum_amount_money();

        $payload['amount_minor_units']         = null !== $amount_money ? $amount_money->as_minor_units() : null;
        $payload['percentage_basis_points']    = $coupon->get_percentage_basis_points();
        $payload['minimum_amount_minor_units'] = null !== $min_money ? $min_money->as_minor_units() : null;
        $payload['maximum_amount_minor_units'] = null !== $max_money ? $max_money->as_minor_units() : null;
        $payload['currency']                   = null !== $amount_money
            ? $amount_money->currency()
            : ( null !== $min_money ? $min_money->currency() : ( function_exists( 'tejcart_get_currency' ) ? (string) tejcart_get_currency() : 'USD' ) );

        return $payload;
    }

    /**
     * Query params for the collection endpoint.
     */
    public function get_collection_params(): array {
        return \TejCart\API\Param_Sanitizers::decorate( array(
            'page'     => array(
                'description' => __( 'Current page.', 'tejcart' ),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ),
            'per_page' => array(
                'description' => __( 'Max items per page.', 'tejcart' ),
                'type'        => 'integer',
                'default'     => 20,
                'minimum'     => 1,
                'maximum'     => 100,
            ),
        ) );
    }

    /**
     * Schema for create/update body params.
     */
    private function get_writable_args(): array {
        return array(
            'code'                 => array( 'type' => 'string' ),
            'type'                 => array(
                'type' => 'string',
                'enum' => array( 'percentage', 'fixed', 'fixed_product', 'free_shipping' ),
            ),
            // Alias accepted by apply_request_to_coupon(); kept in the writable
            // args so it shows up in the route schema for API consumers.
            'discount_type'        => array(
                'type' => 'string',
                'enum' => array( 'percentage', 'fixed', 'fixed_product', 'free_shipping' ),
            ),
            'amount'               => array( 'type' => 'number' ),
            'usage_limit'          => array( 'type' => array( 'integer', 'null' ) ),
            'usage_limit_per_user' => array( 'type' => array( 'integer', 'null' ) ),
            'minimum_amount'       => array( 'type' => array( 'number', 'null' ) ),
            'maximum_amount'       => array( 'type' => array( 'number', 'null' ) ),
            'expires_at'           => array( 'type' => array( 'string', 'null' ) ),
            'status'               => array(
                'type' => 'string',
                'enum' => array( 'active', 'inactive' ),
            ),
            'individual_use'       => array( 'type' => 'boolean' ),
            'exclude_sale_items'   => array( 'type' => 'boolean' ),
            'email_restrictions'   => array( 'type' => array( 'array', 'string' ) ),
        );
    }
}
