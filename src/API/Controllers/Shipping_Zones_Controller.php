<?php
/**
 * Shipping Zones REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Shipping\Shipping_Manager;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/shipping/zones.
 *
 * Maps TejCart's option-backed zone storage (Shipping_Manager) to a REST
 * shape close to common store REST conventions. Locations and methods are exposed as separate
 * sub-resource controllers so each can be replaced/edited independently.
 */
class Shipping_Zones_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'shipping/zones';

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
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'name' => array( 'type' => 'string', 'required' => true ),
                    ),
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
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'name' => array( 'type' => 'string' ),
                    ),
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
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE );
    }

    public function write_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE );
    }

    public function get_items( $request ) {
        $manager = new Shipping_Manager();
        $items   = array();
        foreach ( $manager->get_zones() as $zone ) {
            $items[] = $this->prepare_zone( $zone );
        }
        return rest_ensure_response( $items );
    }

    public function get_item( $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $this->prepare_zone( $zone ) );
    }

    public function create_item( $request ) {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
        if ( '' === $name ) {
            return new WP_Error( 'tejcart_rest_zone_missing_name', __( 'Name is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $manager = new Shipping_Manager();
        $id      = $manager->add_zone( array( 'name' => $name ) );
        if ( ! $id ) {
            return new WP_Error( 'tejcart_rest_zone_create_failed', __( 'Could not create zone.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $zone     = $manager->get_zone( (int) $id );
        $response = rest_ensure_response( $this->prepare_zone( $zone ) );
        $response->set_status( 201 );
        return $response;
    }

    public function update_item( $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $data = array();
        if ( null !== $request->get_param( 'name' ) ) {
            $data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
        }

        if ( ! empty( $data ) ) {
            $manager->update_zone( (int) $zone['id'], $data );
        }

        $zone = ( new Shipping_Manager() )->get_zone( (int) $zone['id'] );
        return rest_ensure_response( $this->prepare_zone( $zone ) );
    }

    public function delete_item( $request ) {
        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Shipping zones do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $previous = $this->prepare_zone( $zone );
        if ( ! $manager->delete_zone( (int) $zone['id'] ) ) {
            return new WP_Error( 'tejcart_rest_zone_delete_failed', __( 'Could not delete zone.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * Convert internal zone shape to API shape.
     */
    protected function prepare_zone( array $zone ): array {
        return array(
            'id'    => (int) ( $zone['id'] ?? 0 ),
            'name'  => (string) ( $zone['name'] ?? '' ),
            'order' => (int) ( $zone['order'] ?? 0 ),
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'shipping_zone',
            'type'       => 'object',
            'properties' => array(
                'id'    => array( 'type' => 'integer', 'readonly' => true ),
                'name'  => array( 'type' => 'string' ),
                'order' => array( 'type' => 'integer' ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
