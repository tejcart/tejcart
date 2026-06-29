<?php
/**
 * Shipping Zone Methods REST API Controller.
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
 * REST controller for /tejcart/v1/shipping/zones/{id}/methods.
 *
 * Internally each zone stores methods as a positional array of
 * {id, title, settings} dicts. The `instance_id` we expose to REST is
 * the array index — stable for the life of a request — so updates and
 * deletes target the correct row.
 */
class Shipping_Zone_Methods_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Parent base.
     */
    protected $rest_base = 'shipping/zones';

    /**
     * Built-in method type ids. Carrier-driven methods (e.g.
     * `carrier_shiprocket`) are discovered at request time via the
     * `tejcart_shipping_method_classes` filter — see
     * {@see allowed_types()} — so any carrier registered by the bundled
     * shipping module or a third-party add-on becomes a valid REST
     * `method_id` automatically.
     */
    protected array $allowed_types = array( 'flat_rate', 'free_shipping', 'local_pickup', 'weight_based' );

    /**
     * Resolve the full set of accepted method ids for the current
     * request. Always includes the four built-ins plus every key
     * registered through the `tejcart_shipping_method_classes` filter.
     *
     * Deliberately ignores the per-carrier enable state from
     * `Carrier_State`: a disabled carrier's method id stays valid for
     * REST writes so external tooling can re-attach a paused carrier
     * to a zone, and so saved rows survive a toggle-off → toggle-on
     * cycle without their `method_id` being rejected on the next PUT.
     * The runtime gate at {@see Carrier_Driven_Method::quotes_for_cart()}
     * is the one source of truth for "should this quote at checkout?".
     *
     * @return string[]
     */
    protected function allowed_types(): array {
        $built_in = array_fill_keys( $this->allowed_types, '' );
        $classes  = apply_filters( 'tejcart_shipping_method_classes', $built_in );
        if ( ! is_array( $classes ) ) {
            $classes = $built_in;
        }
        $ids = array();
        foreach ( array_keys( $classes ) as $id ) {
            $id = (string) $id;
            if ( '' !== $id ) {
                $ids[] = $id;
            }
        }
        // Built-ins always remain valid even if a filter dropped them.
        foreach ( $this->allowed_types as $built_in_id ) {
            if ( ! in_array( $built_in_id, $ids, true ) ) {
                $ids[] = $built_in_id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/methods',
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
                    'args'                => $this->get_writable_args( true ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/methods/(?P<instance_id>[\d]+)',
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

        $items   = array();
        $methods = (array) ( $zone['methods'] ?? array() );
        foreach ( $methods as $idx => $method ) {
            $items[] = $this->prepare_method( $idx, $method );
        }

        return rest_ensure_response( $items );
    }

    public function get_item( $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $methods     = (array) ( $zone['methods'] ?? array() );
        $instance_id = (int) $request['instance_id'];
        if ( ! isset( $methods[ $instance_id ] ) ) {
            return new WP_Error( 'tejcart_rest_zone_method_not_found', __( 'Method not found on this zone.', 'tejcart' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_method( $instance_id, $methods[ $instance_id ] ) );
    }

    public function create_item( $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $method_id = (string) $request->get_param( 'method_id' );
        $allowed   = $this->allowed_types();
        if ( ! in_array( $method_id, $allowed, true ) ) {
            return new WP_Error(
                'tejcart_rest_zone_method_invalid_type',
                sprintf(
                    /* translators: %s: comma-separated list of valid method ids */
                    __( 'Invalid method_id. Allowed: %s.', 'tejcart' ),
                    implode( ', ', $allowed )
                ),
                array( 'status' => 400 )
            );
        }

        $title    = sanitize_text_field( (string) ( $request->get_param( 'title' ) ?: $method_id ) );
        $settings = $this->sanitize_settings( (array) ( $request->get_param( 'settings' ) ?? array() ) );

        $methods   = (array) ( $zone['methods'] ?? array() );
        $methods[] = array(
            'id'       => $method_id,
            'title'    => $title,
            'settings' => $settings,
        );

        $manager->update_zone( (int) $zone['id'], array( 'methods' => $methods ) );

        $instance_id = count( $methods ) - 1;
        $response    = rest_ensure_response( $this->prepare_method( $instance_id, $methods[ $instance_id ] ) );
        $response->set_status( 201 );
        return $response;
    }

    public function update_item( $request ) {
        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $methods     = (array) ( $zone['methods'] ?? array() );
        $instance_id = (int) $request['instance_id'];
        if ( ! isset( $methods[ $instance_id ] ) ) {
            return new WP_Error( 'tejcart_rest_zone_method_not_found', __( 'Method not found on this zone.', 'tejcart' ), array( 'status' => 404 ) );
        }

        if ( null !== $request->get_param( 'title' ) ) {
            $methods[ $instance_id ]['title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
        }
        if ( null !== $request->get_param( 'settings' ) ) {
            $methods[ $instance_id ]['settings'] = $this->sanitize_settings( (array) $request->get_param( 'settings' ) );
        }
        if ( null !== $request->get_param( 'method_id' ) ) {
            $type = (string) $request->get_param( 'method_id' );
            if ( ! in_array( $type, $this->allowed_types(), true ) ) {
                return new WP_Error( 'tejcart_rest_zone_method_invalid_type', __( 'Invalid method_id.', 'tejcart' ), array( 'status' => 400 ) );
            }
            $methods[ $instance_id ]['id'] = $type;
        }

        $manager->update_zone( (int) $zone['id'], array( 'methods' => $methods ) );

        return rest_ensure_response( $this->prepare_method( $instance_id, $methods[ $instance_id ] ) );
    }

    public function delete_item( $request ) {
        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Zone methods do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $manager = new Shipping_Manager();
        $zone    = $manager->get_zone( (int) $request['id'] );
        if ( ! $zone ) {
            return new WP_Error( 'tejcart_rest_zone_not_found', __( 'Shipping zone not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $methods     = (array) ( $zone['methods'] ?? array() );
        $instance_id = (int) $request['instance_id'];
        if ( ! isset( $methods[ $instance_id ] ) ) {
            return new WP_Error( 'tejcart_rest_zone_method_not_found', __( 'Method not found on this zone.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $previous = $this->prepare_method( $instance_id, $methods[ $instance_id ] );

        array_splice( $methods, $instance_id, 1 );
        $manager->update_zone( (int) $zone['id'], array( 'methods' => array_values( $methods ) ) );

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * Coerce settings into the shape Shipping_Methods expects.
     */
    protected function sanitize_settings( array $settings ): array {
        $out = array();
        if ( isset( $settings['cost'] ) ) {
            $out['cost'] = (float) $settings['cost'];
        }
        if ( isset( $settings['min_amount'] ) ) {
            $out['min_amount'] = (float) $settings['min_amount'];
        }
        if ( isset( $settings['rates'] ) && is_array( $settings['rates'] ) ) {
            $rates = array();
            foreach ( $settings['rates'] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $rates[] = array(
                    'weight_from' => isset( $row['weight_from'] ) ? (float) $row['weight_from'] : 0.0,
                    'weight_to'   => isset( $row['weight_to'] ) ? (float) $row['weight_to'] : 0.0,
                    'cost'        => isset( $row['cost'] ) ? (float) $row['cost'] : 0.0,
                );
            }
            $out['rates'] = $rates;
        }
        if ( isset( $settings['class_costs'] ) && is_array( $settings['class_costs'] ) ) {
            $out['class_costs'] = array_map( 'floatval', $settings['class_costs'] );
        }
        if ( isset( $settings['service_code'] ) ) {
            $service_code = sanitize_text_field( (string) $settings['service_code'] );
            if ( '' !== $service_code ) {
                $out['service_code'] = $service_code;
            }
        }
        return $out;
    }

    /**
     * Convert internal method dict to API shape.
     */
    protected function prepare_method( int $instance_id, array $method ): array {
        $type = (string) ( $method['id'] ?? '' );

        return array(
            'instance_id' => $instance_id,
            'method_id'   => $type,
            'title'       => (string) ( $method['title'] ?? $type ),
            'enabled'     => true,
            'order'       => $instance_id,
            'settings'    => is_array( $method['settings'] ?? null ) ? $method['settings'] : array(),
        );
    }

    /**
     * Writable body args.
     */
    private function get_writable_args( bool $for_create ): array {
        return array(
            'method_id' => array(
                'type'     => 'string',
                'required' => $for_create,
                'enum'     => $this->allowed_types(),
            ),
            'title'     => array( 'type' => 'string' ),
            'settings'  => array( 'type' => 'object' ),
        );
    }
}
