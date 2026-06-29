<?php
/**
 * Product Categories REST API Controller.
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
 * REST controller for /tejcart/v1/products/categories.
 *
 * Mirrors the common products/categories REST shape used by headless / migration
 * tooling so they can manage TejCart product categories programmatically.
 */
class Product_Categories_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'products/categories';

    /**
     * Taxonomy backing this controller.
     */
    protected string $taxonomy = Product_Taxonomy::CATEGORY_TAXONOMY;

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
                            'type'        => 'boolean',
                            'default'     => false,
                            'description' => __( 'Whether to bypass trash and force deletion.', 'tejcart' ),
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * Read access: anyone authenticated as TejCart-API or with manage_options.
     *
     * The API_Keys read/write enforcement layer separately blocks read-only
     * keys from writes and write-only keys from reads.
     */
    public function read_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_PRODUCTS );
    }

    public function write_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_PRODUCTS );
    }

    /**
     * List categories.
     */
    public function get_items( $request ) {
        $args = array(
            'taxonomy'   => $this->taxonomy,
            'hide_empty' => (bool) $request->get_param( 'hide_empty' ),
            'orderby'    => (string) ( $request->get_param( 'orderby' ) ?: 'name' ),
            'order'      => strtoupper( (string) ( $request->get_param( 'order' ) ?: 'ASC' ) ),
            'number'     => max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
            'offset'     => ( max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ) - 1 ) * max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
        );

        if ( null !== $request->get_param( 'search' ) ) {
            $args['search'] = (string) $request->get_param( 'search' );
        }

        $parent = $request->get_param( 'parent' );
        if ( null !== $parent ) {
            $args['parent'] = (int) $parent;
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

    /**
     * Get one category.
     */
    public function get_item( $request ) {
        $term = $this->fetch_term( (int) $request['id'] );
        if ( is_wp_error( $term ) ) {
            return $term;
        }

        return rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
    }

    /**
     * Create category.
     */
    public function create_item( $request ) {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
        if ( '' === $name ) {
            return new WP_Error( 'tejcart_rest_term_missing_name', __( 'Name is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $args = array();
        if ( null !== $request->get_param( 'slug' ) ) {
            $args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
        }
        if ( null !== $request->get_param( 'parent' ) ) {
            $args['parent'] = (int) $request->get_param( 'parent' );
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

        $this->save_term_meta( $term, $request );

        $response = rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * Update category.
     */
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
        if ( null !== $request->get_param( 'parent' ) ) {
            $args['parent'] = (int) $request->get_param( 'parent' );
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

        $this->save_term_meta( $term, $request );

        $term = get_term( $term->term_id, $this->taxonomy );

        return rest_ensure_response( $this->prepare_item_for_response( $term, $request )->get_data() );
    }

    /**
     * Delete category.
     */
    public function delete_item( $request ) {
        $term = $this->fetch_term( (int) $request['id'] );
        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $force = (bool) $request->get_param( 'force' );
        if ( ! $force ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Categories do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $previous = $this->prepare_item_for_response( $term, $request )->get_data();

        $deleted = wp_delete_term( $term->term_id, $this->taxonomy );
        if ( is_wp_error( $deleted ) || true !== $deleted ) {
            return new WP_Error( 'tejcart_rest_term_delete_failed', __( 'Could not delete category.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * Persist meta-backed fields (display, image, menu_order).
     */
    protected function save_term_meta( WP_Term $term, WP_REST_Request $request ): void {
        if ( null !== $request->get_param( 'display' ) ) {
            $display = (string) $request->get_param( 'display' );
            if ( in_array( $display, array( 'default', 'products', 'subcategories', 'both' ), true ) ) {
                update_term_meta( $term->term_id, 'display_type', $display );
            }
        }

        if ( null !== $request->get_param( 'menu_order' ) ) {
            update_term_meta( $term->term_id, 'order', (int) $request->get_param( 'menu_order' ) );
        }

        $image = $request->get_param( 'image' );
        if ( is_array( $image ) && ! empty( $image['id'] ) ) {
            update_term_meta( $term->term_id, 'thumbnail_id', (int) $image['id'] );
        } elseif ( null !== $image && empty( $image ) ) {
            delete_term_meta( $term->term_id, 'thumbnail_id' );
        }
    }

    /**
     * Hydrate a term into the response shape.
     *
     * @param WP_Term         $term    Term being prepared.
     * @param WP_REST_Request $request Current request.
     */
    public function prepare_item_for_response( $term, $request ) {
        $thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
        $image    = null;
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_url( $thumb_id, 'full' );
            if ( $src ) {
                $image = array(
                    'id'  => $thumb_id,
                    'src' => $src,
                    'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
                );
            }
        }

        $data = array(
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'parent'      => (int) $term->parent,
            'description' => $term->description,
            'display'     => (string) ( get_term_meta( $term->term_id, 'display_type', true ) ?: 'default' ),
            'image'       => $image,
            'menu_order'  => (int) ( get_term_meta( $term->term_id, 'order', true ) ?: 0 ),
            'count'       => (int) $term->count,
        );

        return rest_ensure_response( $data );
    }

    /**
     * Lookup a term, returning a WP_Error on miss.
     *
     * @param int $id Term ID.
     * @return WP_Term|WP_Error
     */
    protected function fetch_term( int $id ) {
        if ( $id <= 0 ) {
            return new WP_Error( 'tejcart_rest_term_invalid_id', __( 'Invalid category ID.', 'tejcart' ), array( 'status' => 404 ) );
        }
        $term = get_term( $id, $this->taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'tejcart_rest_term_not_found', __( 'Category not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $term;
    }

    /**
     * Collection params.
     */
    public function get_collection_params(): array {
        return \TejCart\API\Param_Sanitizers::decorate( array(
            'page'       => array(
                'description' => __( 'Current page.', 'tejcart' ),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ),
            'per_page'   => array(
                'description' => __( 'Max items per page.', 'tejcart' ),
                'type'        => 'integer',
                'default'     => 20,
                'minimum'     => 1,
                'maximum'     => 100,
            ),
            'search'     => array(
                'description' => __( 'Limit results to terms matching a string.', 'tejcart' ),
                'type'        => 'string',
            ),
            'orderby'    => array(
                'description' => __( 'Sort term collection.', 'tejcart' ),
                'type'        => 'string',
                'enum'        => array( 'id', 'name', 'slug', 'count', 'term_group', 'description' ),
                'default'     => 'name',
            ),
            'order'      => array(
                'description' => __( 'Order direction.', 'tejcart' ),
                'type'        => 'string',
                'enum'        => array( 'asc', 'desc', 'ASC', 'DESC' ),
                'default'     => 'asc',
            ),
            'hide_empty' => array(
                'description' => __( 'Hide terms not assigned to any product.', 'tejcart' ),
                'type'        => 'boolean',
                'default'     => false,
            ),
            'parent'     => array(
                'description' => __( 'Limit to direct children of a given parent.', 'tejcart' ),
                'type'        => 'integer',
            ),
            'slug'       => array(
                'description' => __( 'Limit to terms with the given slug(s).', 'tejcart' ),
                'type'        => array( 'string', 'array' ),
            ),
            'include'    => array(
                'description' => __( 'Limit to specific term IDs.', 'tejcart' ),
                'type'        => 'array',
                'items'       => array( 'type' => 'integer' ),
            ),
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
            'exclude'    => array(
                'description' => __( 'Exclude specific term IDs.', 'tejcart' ),
                'type'        => 'array',
                'items'       => array( 'type' => 'integer' ),
            ),
        ) );
    }

    /**
     * Writable body params.
     */
    private function get_writable_args(): array {
        return array(
            'name'        => array( 'type' => 'string' ),
            'slug'        => array( 'type' => 'string' ),
            'parent'      => array( 'type' => 'integer' ),
            'description' => array( 'type' => 'string' ),
            'display'     => array(
                'type' => 'string',
                'enum' => array( 'default', 'products', 'subcategories', 'both' ),
            ),
            'image'       => array(
                'type'       => 'object',
                'properties' => array(
                    'id'  => array( 'type' => 'integer' ),
                    'src' => array( 'type' => 'string' ),
                    'alt' => array( 'type' => 'string' ),
                ),
            ),
            'menu_order'  => array( 'type' => 'integer' ),
        );
    }

    /**
     * Public schema.
     */
    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'product_category',
            'type'       => 'object',
            'properties' => array(
                'id'          => array( 'type' => 'integer', 'readonly' => true ),
                'name'        => array( 'type' => 'string' ),
                'slug'        => array( 'type' => 'string' ),
                'parent'      => array( 'type' => 'integer' ),
                'description' => array( 'type' => 'string' ),
                'display'     => array(
                    'type' => 'string',
                    'enum' => array( 'default', 'products', 'subcategories', 'both' ),
                ),
                'image'       => array(
                    'type'       => array( 'object', 'null' ),
                    'properties' => array(
                        'id'  => array( 'type' => 'integer' ),
                        'src' => array( 'type' => 'string' ),
                        'alt' => array( 'type' => 'string' ),
                    ),
                ),
                'menu_order'  => array( 'type' => 'integer' ),
                'count'       => array( 'type' => 'integer', 'readonly' => true ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
