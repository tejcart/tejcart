<?php
/**
 * Tax Classes REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Tax\Tax_Manager;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/taxes/classes.
 */
class Tax_Classes_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'taxes/classes';

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
        $manager = new Tax_Manager();
        $classes = array();
        foreach ( $manager->get_tax_classes() as $row ) {
            $classes[] = array(
                'id'   => (int) ( $row['id'] ?? 0 ),
                'name' => (string) ( $row['name'] ?? '' ),
                'slug' => isset( $row['name'] ) ? sanitize_title( $row['name'] ) : '',
            );
        }
        return rest_ensure_response( $classes );
    }

    public function create_item( $request ) {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
        if ( '' === $name ) {
            return new WP_Error( 'tejcart_rest_tax_class_missing_name', __( 'Name is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $manager = new Tax_Manager();
        $id      = $manager->add_tax_class( $name );
        if ( ! $id ) {
            return new WP_Error( 'tejcart_rest_tax_class_create_failed', __( 'Could not create tax class.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $response = rest_ensure_response(
            array(
                'id'   => (int) $id,
                'name' => $name,
                'slug' => sanitize_title( $name ),
            )
        );
        $response->set_status( 201 );
        return $response;
    }

    public function delete_item( $request ) {
        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Tax classes do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $id      = (int) $request['id'];
        $manager = new Tax_Manager();

        $previous = null;
        foreach ( $manager->get_tax_classes() as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $id ) {
                $previous = array(
                    'id'   => $id,
                    'name' => (string) ( $row['name'] ?? '' ),
                    'slug' => isset( $row['name'] ) ? sanitize_title( $row['name'] ) : '',
                );
                break;
            }
        }

        if ( ! $previous ) {
            return new WP_Error( 'tejcart_rest_tax_class_not_found', __( 'Tax class not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $ok = $manager->delete_tax_class( $id );
        if ( ! $ok ) {
            return new WP_Error(
                'tejcart_rest_tax_class_delete_failed',
                __( 'Could not delete tax class. Built-in classes (Standard, Reduced, Zero) cannot be removed.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'tax_class',
            'type'       => 'object',
            'properties' => array(
                'id'   => array( 'type' => 'integer', 'readonly' => true ),
                'name' => array( 'type' => 'string' ),
                'slug' => array( 'type' => 'string', 'readonly' => true ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
