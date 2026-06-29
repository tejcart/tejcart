<?php
/**
 * Product Tags REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Product\Product_Taxonomy;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/products/tags.
 *
 * Mirrors the common products/tags REST shape. Tags are flat — no parent / display / image.
 */
class Product_Tags_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'products/tags';

    /**
     * Backing taxonomy.
     */
    protected string $taxonomy = Product_Taxonomy::TAG_TAXONOMY;

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
                    'args'                => $this->get_writable_args(),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'write_permissions_check' ),
                    'args'                => array(
                        'force' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    public function read_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_PRODUCTS );
    }

    public function write_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_PRODUCTS );
    }

    /**
     * List tags.
     */
    public function get_items( $request ) {
        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

        $args = array(
            'taxonomy'   => $this->taxonomy,
            'hide_empty' => (bool) $request->get_param( 'hide_empty' ),
            'orderby'    => (string) ( $request->get_param( 'orderby' ) ?: 'name' ),
            'order'      => strtoupper( (string) ( $request->get_param( 'order' ) ?: 'ASC' ) ),
            'number'     => $per_page,
            'offset'     => ( $page - 1 ) * $per_page,
        );

        if ( null !== $request->get_param( 'search' ) ) {
            $args['search'] = (string) $request->get_param( 'search' );
        }
        $slug = $request->get_param( 'slug' );
        if ( $slug ) {
            $args['slug'] = is_array( $slug ) ? array_map( 'sanitize_title', $slug ) : sanitize_title( $slug );
        }
        $include = $request->get_param( 'include' );
        if ( $include ) {
            $args['include'] = array_map( 'absint', (array) $include );
        }
        $exclude = $request->get_param( 'exclude' );
        if ( $exclude ) {
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
            $args['exclude'] = array_map( 'absint', (array) $exclude );
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return new WP_Error( 'tejcart_rest_term_query_failed', $terms->get_error_message(), array( 'status' => 500 ) );
        }

        $items = array();
        foreach ( (array) $terms as $term ) {
            $items[] = $this->prepare_item_for_response( $term, $request )->get_data();
        }

        return rest_ensure_response( $items );
    }

    public function get_item( $request ) {
        $term = $this->fetch_term( (int) $request['id'] );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        return rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
    }

    public function create_item( $request ) {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
        if ( '' === $name ) {
            return new WP_Error( 'tejcart_rest_term_missing_name', __( 'Name is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $args = array();
        if ( null !== $request->get_param( 'slug' ) ) {
            $args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
        }
        if ( null !== $request->get_param( 'description' ) ) {
            $args['description'] = wp_kses_post( (string) $request->get_param( 'description' ) );
        }

        $created = wp_insert_term( $name, $this->taxonomy, $args );
        if ( is_wp_error( $created ) ) {
            return new WP_Error( 'tejcart_rest_term_create_failed', $created->get_error_message(), array( 'status' => 400 ) );
        }

        $term = get_term( (int) $created['term_id'], $this->taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'tejcart_rest_term_create_failed', __( 'Could not load created term.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $response = rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
        $response->set_status( 201 );
        return $response;
    }

    public function update_item( $request ) {
        $term = $this->fetch_term( (int) $request['id'] );
        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $args = array();
        if ( null !== $request->get_param( 'name' ) ) {
            $args['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
        }
        if ( null !== $request->get_param( 'slug' ) ) {
            $args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
        }
        if ( null !== $request->get_param( 'description' ) ) {
            $args['description'] = wp_kses_post( (string) $request->get_param( 'description' ) );
        }

        if ( ! empty( $args ) ) {
            $updated = wp_update_term( $term->term_id, $this->taxonomy, $args );
            if ( is_wp_error( $updated ) ) {
                return new WP_Error( 'tejcart_rest_term_update_failed', $updated->get_error_message(), array( 'status' => 400 ) );
            }
        }

        $term = get_term( $term->term_id, $this->taxonomy );

        return rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
    }

    public function delete_item( $request ) {
        $term = $this->fetch_term( (int) $request['id'] );
        if ( is_wp_error( $term ) ) {
            return $term;
        }

        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Tags do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $previous = $this->prepare_item_for_response( $term, $request )->get_data();

        $deleted = wp_delete_term( $term->term_id, $this->taxonomy );
        if ( is_wp_error( $deleted ) || true !== $deleted ) {
            return new WP_Error( 'tejcart_rest_term_delete_failed', __( 'Could not delete tag.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * @param WP_Term         $term
     * @param WP_REST_Request $request
     */
    public function prepare_item_for_response( $term, $request ) {
        $data = array(
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => (int) $term->count,
        );

        return rest_ensure_response( $data );
    }

    /**
     * @return WP_Term|WP_Error
     */
    protected function fetch_term( int $id ) {
        if ( $id <= 0 ) {
            return new WP_Error( 'tejcart_rest_term_invalid_id', __( 'Invalid tag ID.', 'tejcart' ), array( 'status' => 404 ) );
        }
        $term = get_term( $id, $this->taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'tejcart_rest_term_not_found', __( 'Tag not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $term;
    }

    public function get_collection_params(): array {
        return \TejCart\API\Param_Sanitizers::decorate( array(
            'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
            'per_page'   => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
            'search'     => array( 'type' => 'string' ),
            'orderby'    => array(
                'type'    => 'string',
                'enum'    => array( 'id', 'name', 'slug', 'count', 'description' ),
                'default' => 'name',
            ),
            'order'      => array(
                'type'    => 'string',
                'enum'    => array( 'asc', 'desc', 'ASC', 'DESC' ),
                'default' => 'asc',
            ),
            'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
            'slug'       => array( 'type' => array( 'string', 'array' ) ),
            'include'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
            'exclude'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
        ) );
    }

    private function get_writable_args(): array {
        return array(
            'name'        => array( 'type' => 'string' ),
            'slug'        => array( 'type' => 'string' ),
            'description' => array( 'type' => 'string' ),
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'product_tag',
            'type'       => 'object',
            'properties' => array(
                'id'          => array( 'type' => 'integer', 'readonly' => true ),
                'name'        => array( 'type' => 'string' ),
                'slug'        => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'count'       => array( 'type' => 'integer', 'readonly' => true ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
