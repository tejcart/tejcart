<?php
/**
 * Tax Rates REST API Controller.
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
 * REST controller for /tejcart/v1/taxes.
 */
class Tax_Rates_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'taxes';

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
                    'args'                => $this->get_writable_args( true ),
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
                    'args'                => $this->get_writable_args( false ),
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

    /**
     * List tax rates.
     */
    public function get_items( $request ) {
        $manager = new Tax_Manager();
        $rates   = $manager->get_rates();

        $class_filter   = (string) ( $request->get_param( 'class' ) ?? '' );
        $country_filter = strtoupper( (string) ( $request->get_param( 'country' ) ?? '' ) );

        $items = array();
        foreach ( $rates as $rate ) {
            if ( '' !== $class_filter && (string) ( $rate['tax_class'] ?? '' ) !== $class_filter ) {
                continue;
            }
            if ( '' !== $country_filter && strtoupper( (string) ( $rate['country'] ?? '' ) ) !== $country_filter ) {
                continue;
            }
            $items[] = $this->prepare_rate( $rate );
        }

        return rest_ensure_response( $items );
    }

    /**
     * Fetch one rate.
     */
    public function get_item( $request ) {
        $manager = new Tax_Manager();
        $rate    = $this->find_rate( $manager, (int) $request['id'] );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }
        return rest_ensure_response( $this->prepare_rate( $rate ) );
    }

    /**
     * Create a tax rate.
     */
    public function create_item( $request ) {
        $data = $this->collect_writable_data( $request );
        if ( '' === (string) ( $data['country'] ?? '' ) ) {
            return new WP_Error( 'tejcart_rest_tax_missing_country', __( 'Country is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $manager = new Tax_Manager();
        $id      = $manager->add_rate( $data );
        if ( ! $id ) {
            return new WP_Error( 'tejcart_rest_tax_create_failed', __( 'Could not create tax rate.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $rate = $this->find_rate( $manager, (int) $id );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $response = rest_ensure_response( $this->prepare_rate( $rate ) );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * Update a tax rate.
     */
    public function update_item( $request ) {
        $manager = new Tax_Manager();
        $rate    = $this->find_rate( $manager, (int) $request['id'] );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $data = $this->collect_writable_data( $request, true );
        if ( empty( $data ) ) {
            return rest_ensure_response( $this->prepare_rate( $rate ) );
        }

        $ok = $manager->update_rate( (int) $rate['id'], $data );
        if ( ! $ok ) {
            return new WP_Error( 'tejcart_rest_tax_update_failed', __( 'Could not update tax rate.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $rate = $this->find_rate( new Tax_Manager(), (int) $rate['id'] );
        return rest_ensure_response( $this->prepare_rate( $rate ) );
    }

    /**
     * Delete a tax rate.
     */
    public function delete_item( $request ) {
        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Tax rates do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $manager = new Tax_Manager();
        $rate    = $this->find_rate( $manager, (int) $request['id'] );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $previous = $this->prepare_rate( $rate );
        $ok       = $manager->delete_rate( (int) $rate['id'] );
        if ( ! $ok ) {
            return new WP_Error( 'tejcart_rest_tax_delete_failed', __( 'Could not delete tax rate.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * Find a rate by id or return WP_Error.
     *
     * @return array|WP_Error
     */
    protected function find_rate( Tax_Manager $manager, int $id ) {
        foreach ( $manager->get_rates() as $rate ) {
            if ( (int) ( $rate['id'] ?? 0 ) === $id ) {
                return $rate;
            }
        }
        return new WP_Error( 'tejcart_rest_tax_not_found', __( 'Tax rate not found.', 'tejcart' ), array( 'status' => 404 ) );
    }

    /**
     * Normalise a rate row for responses.
     */
    protected function prepare_rate( array $rate ): array {
        return array(
            'id'        => (int) ( $rate['id'] ?? 0 ),
            'country'   => (string) ( $rate['country'] ?? '' ),
            'state'     => (string) ( $rate['state'] ?? '' ),
            'rate'      => (float) ( $rate['rate'] ?? 0 ),
            'name'      => (string) ( $rate['name'] ?? '' ),
            'priority'  => (int) ( $rate['priority'] ?? 1 ),
            'compound'  => (string) ( $rate['compound'] ?? 'no' ),
            'shipping'  => (string) ( $rate['shipping'] ?? 'yes' ),
            'class'     => (string) ( $rate['tax_class'] ?? '' ),
        );
    }

    /**
     * Pull writable fields from the request.
     */
    protected function collect_writable_data( WP_REST_Request $request, bool $partial = false ): array {
        $fields = array( 'country', 'state', 'rate', 'name', 'priority', 'compound', 'shipping' );
        $data   = array();
        foreach ( $fields as $field ) {
            $value = $request->get_param( $field );
            if ( null === $value && $partial ) {
                continue;
            }
            if ( null === $value ) {
                continue;
            }
            $data[ $field ] = $value;
        }

        $class = $request->get_param( 'class' );
        if ( null !== $class ) {
            $data['tax_class'] = (string) $class;
        }

        return $data;
    }

    public function get_collection_params(): array {
        return \TejCart\API\Param_Sanitizers::decorate( array(
            'class'   => array(
                'description' => __( 'Filter by tax class.', 'tejcart' ),
                'type'        => 'string',
            ),
            'country' => array(
                'description' => __( 'Filter by country code.', 'tejcart' ),
                'type'        => 'string',
            ),
        ) );
    }

    /**
     * Writable args.
     *
     * @param bool $for_create Whether this is for the create endpoint.
     */
    private function get_writable_args( bool $for_create ): array {
        return array(
            'country'  => array(
                'type'     => 'string',
                'required' => $for_create,
            ),
            'state'    => array( 'type' => 'string' ),
            'rate'     => array( 'type' => 'number' ),
            'name'     => array( 'type' => 'string' ),
            'priority' => array( 'type' => 'integer' ),
            'compound' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
            'shipping' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
            'class'    => array( 'type' => 'string' ),
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'tax_rate',
            'type'       => 'object',
            'properties' => array(
                'id'       => array( 'type' => 'integer', 'readonly' => true ),
                'country'  => array( 'type' => 'string' ),
                'state'    => array( 'type' => 'string' ),
                'rate'     => array( 'type' => 'number' ),
                'name'     => array( 'type' => 'string' ),
                'priority' => array( 'type' => 'integer' ),
                'compound' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
                'shipping' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
                'class'    => array( 'type' => 'string' ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
