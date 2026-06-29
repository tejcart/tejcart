<?php
/**
 * Shipping Zone Locations REST API Controller.
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
 * REST controller for /tejcart/v1/shipping/zones/{id}/locations.
 *
 * Locations are stored on the zone in two arrays — `countries` (entries
 * are either "US" or "US:CA") and `postcodes` (rules like "902*"). The
 * REST endpoint flattens both into the standard {code, type} list.
 */
class Shipping_Zone_Locations_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Parent base.
     */
    protected $rest_base = 'shipping/zones';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/locations',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'replace_items' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'locations' => array(
                            'type'        => 'array',
                            'required'    => true,
                            'description' => __( 'Replace the zone location set.', 'tejcart' ),
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'code' => array( 'type' => 'string' ),
                                    'type' => array(
                                        'type' => 'string',
                                        'enum' => array( 'country', 'state', 'postcode' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
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
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->locations_from_zone( $zone ) );
    }

    public function replace_items( WP_REST_Request $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $locations = (array) $request->get_param( 'locations' );
        $countries = array();
        $postcodes = array();

        foreach ( $locations as $loc ) {
            $code = isset( $loc['code'] ) ? sanitize_text_field( (string) $loc['code'] ) : '';
            $type = isset( $loc['type'] ) ? sanitize_text_field( (string) $loc['type'] ) : 'country';
            if ( '' === $code ) {
                continue;
            }
            switch ( $type ) {
                case 'country':
                    $countries[] = strtoupper( $code );
                    break;
                case 'state':

                    $countries[] = strtoupper( $code );
                    break;
                case 'postcode':
                    $postcodes[] = $code;
                    break;
            }
        }

        $manager->update_zone(
            (int) $zone['id'],
            array(
                'countries' => array_values( array_unique( $countries ) ),
                'postcodes' => array_values( array_unique( $postcodes ) ),
            )
        );

        $zone = ( new Shipping_Manager() )->get_zone( (int) $zone['id'] );
        return rest_ensure_response( $this->locations_from_zone( $zone ) );
    }

    /**
     * Flatten the internal zone into the standard locations array.
     */
    protected function locations_from_zone( array $zone ): array {
        $out = array();

        foreach ( (array) ( $zone['countries'] ?? array() ) as $entry ) {
            $entry = (string) $entry;
            $type  = ( false !== strpos( $entry, ':' ) ) ? 'state' : 'country';
            $out[] = array(
                'code' => $entry,
                'type' => $type,
            );
        }

        foreach ( (array) ( $zone['postcodes'] ?? array() ) as $entry ) {
            $out[] = array(
                'code' => (string) $entry,
                'type' => 'postcode',
            );
        }

        return $out;
    }
}
