<?php
/**
 * REST API for shipment tracking.
 *
 * Exposes the same operations as the AJAX controller under the
 * `tejcart/v1` namespace so external systems (3PLs, carrier webhook
 * sources, headless storefronts) can integrate without scraping
 * admin-AJAX. Auth uses the same WordPress capability check as AJAX —
 * TejCart's API_Keys (basic auth) is consumed transparently.
 *
 * Routes:
 *   GET    /tejcart/v1/orders/{order_id}/tracking
 *   POST   /tejcart/v1/orders/{order_id}/tracking
 *   PATCH  /tejcart/v1/shipments/{id}
 *   DELETE /tejcart/v1/shipments/{id}
 *   POST   /tejcart/v1/shipments/{id}/status     (transition helper)
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class REST_Controller {
    private const NAMESPACE_V1 = 'tejcart/v1';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            '/orders/(?P<order_id>\d+)/tracking',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'list_for_order' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                    'args'                => array(
                        'order_id' => array( 'type' => 'integer', 'required' => true ),
                    ),
                ),
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                    'args'                => $this->create_args(),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/shipments/(?P<id>\d+)',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_one' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                ),
                array(
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                    'args'                => $this->update_args(),
                ),
                array(
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/shipments/(?P<id>\d+)/status',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'transition' ),
                    'permission_callback' => array( $this, 'permission_manage' ),
                    'args'                => array(
                        'status' => array(
                            'type'              => 'string',
                            'required'          => true,
                            'enum'              => Shipment_Status::all(),
                        ),
                    ),
                ),
            )
        );
    }

    public function permission_manage(): bool|\WP_Error {
        if ( Capability::current_user_can_manage() ) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            __( 'Sorry, you are not allowed to manage tracking.', 'tejcart' ),
            array( 'status' => 403 )
        );
    }

    public function list_for_order( \WP_REST_Request $request ): \WP_REST_Response {
        $order_id  = (int) $request->get_param( 'order_id' );
        $shipments = $this->service->for_order( $order_id );
        return rest_ensure_response( $shipments );
    }

    public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $order_id = (int) $request->get_param( 'order_id' );
        $payload  = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = $request->get_params();
        }
        $result = $this->service->add( $order_id, $payload );
        if ( is_wp_error( $result ) ) {
            return $this->error_with_status( $result, 400 );
        }
        $row = $this->service->repository()->find( (int) $result );
        return rest_ensure_response( $row )->set_status( 201 );
    }

    public function get_one( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id  = (int) $request->get_param( 'id' );
        $row = $this->service->repository()->find( $id );
        if ( null === $row ) {
            return new \WP_Error( 'not_found', __( 'Shipment not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $row );
    }

    public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id      = (int) $request->get_param( 'id' );
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = $request->get_params();
        }
        unset( $payload['id'] );
        $result = $this->service->update( $id, $payload );
        if ( is_wp_error( $result ) ) {
            return $this->error_with_status( $result, 400 );
        }
        return rest_ensure_response( $this->service->repository()->find( $id ) );
    }

    public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id     = (int) $request->get_param( 'id' );
        $result = $this->service->delete( $id );
        if ( is_wp_error( $result ) ) {
            return $this->error_with_status( $result, 400 );
        }
        return rest_ensure_response( array( 'deleted' => true ) );
    }

    public function transition( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id     = (int) $request->get_param( 'id' );
        $status = (string) $request->get_param( 'status' );
        $result = $this->service->update( $id, array( 'status' => $status ) );
        if ( is_wp_error( $result ) ) {
            return $this->error_with_status( $result, 400 );
        }
        return rest_ensure_response( $this->service->repository()->find( $id ) );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function create_args(): array {
        return array(
            'carrier'         => array( 'type' => 'string', 'required' => true ),
            'tracking_number' => array( 'type' => 'string', 'required' => true ),
            'service'         => array( 'type' => 'string' ),
            'tracking_url'    => array( 'type' => 'string', 'format' => 'uri' ),
            'label_url'       => array( 'type' => 'string', 'format' => 'uri' ),
            'status'          => array( 'type' => 'string', 'enum' => Shipment_Status::all() ),
            'cost'            => array( 'type' => 'number' ),
            'shipped_at'      => array( 'type' => 'string', 'format' => 'date-time' ),
            'delivered_at'    => array( 'type' => 'string', 'format' => 'date-time' ),
            'meta'            => array( 'type' => 'object' ),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function update_args(): array {
        $args = $this->create_args();
        foreach ( $args as &$arg ) {
            $arg['required'] = false;
        }
        unset( $arg );
        return $args;
    }

    private function error_with_status( \WP_Error $error, int $status ): \WP_Error {
        $data = $error->get_error_data();
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        $data['status'] = $status;
        $error->add_data( $data );
        return $error;
    }
}
