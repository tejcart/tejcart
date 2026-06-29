<?php
/**
 * Products REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use TejCart\Product\Product_Factory;
use TejCart\Product\Product_Reviews;
use TejCart\Product\Product_Type_Registry;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the products endpoint.
 */
class Products_Controller extends WP_REST_Controller {
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
    protected $rest_base = 'products';

    /**
     * Register routes for products.
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
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
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
                            'description' => __( 'Unique identifier for the product.', 'tejcart' ),
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
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'delete_item_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Unique identifier for the product.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/batch',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'batch_items' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                    'args'                => array(
                        'create' => array(
                            'description' => __( 'Array of product payloads to create.', 'tejcart' ),
                            'type'        => 'array',
                            'default'     => array(),
                        ),
                        'update' => array(
                            'description' => __( 'Array of product payloads to update (each must include id).', 'tejcart' ),
                            'type'        => 'array',
                            'default'     => array(),
                        ),
                        'delete' => array(
                            'description' => __( 'Array of product IDs to delete.', 'tejcart' ),
                            'type'        => 'array',
                            'default'     => array(),
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/variations',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_variations' ),
                    'permission_callback' => array( $this, 'get_item_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Variable product ID.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * GET /products/{id}/variations — return the variation children of a
     * variable parent. Each entry is the standard product response so
     * consumers don't need a second round-trip per variation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_variations( $request ) {
        $parent_id = (int) $request->get_param( 'id' );
        $parent    = Product_Factory::get_product( $parent_id );

        if ( ! $parent || 'variable' !== $parent->get_type() ) {
            return new WP_Error(
                'tejcart_rest_not_variable',
                __( 'Variations are only available on variable products.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $variations = method_exists( $parent, 'get_variations' ) ? (array) $parent->get_variations() : array();
        $out        = array();

        foreach ( $variations as $variation ) {
            $data  = $this->prepare_item_for_response( $variation, $request );
            $out[] = $this->prepare_response_for_collection( $data );
        }

        $response = rest_ensure_response( $out );
        $response->header( 'X-WP-Total', count( $out ) );
        $response->header( 'X-WP-TotalPages', 1 );

        return $response;
    }

    /**
     * Check if a given request has access to read products.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true
     */
    public function get_items_permissions_check( $request ) {
        return $this->enforce_rest_rate_limit( $request, 'products', 60 );
    }

    /**
     * Check if a given request has access to read a single product.
     *
     * Non-admin users may only view published products.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error
     */
    public function get_item_permissions_check( $request ) {
        $rl = $this->enforce_rest_rate_limit( $request, 'products', 60 );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        if ( \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            return true;
        }

        $product = Product_Factory::get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return new WP_Error(
                'tejcart_rest_product_not_found',
                __( 'Product not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        if ( 'publish' !== $product->get_status() ) {
            return new WP_Error(
                'tejcart_rest_product_not_found',
                __( 'Product not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        return true;
    }

    /**
     * Check if a given request has access to create a product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function create_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_create',
                __( 'Sorry, you are not allowed to create products.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'products', 30 );
    }

    /**
     * Check if a given request has access to update a product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function update_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_update',
                __( 'Sorry, you are not allowed to update products.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'products', 30 );
    }

    /**
     * Check if a given request has access to delete a product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function delete_item_permissions_check( $request ) {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::DELETE_PRODUCTS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_delete',
                __( 'Sorry, you are not allowed to delete products.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'products', 30 );
    }

    /**
     * Get a collection of products.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_items( $request ) {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_products';
        $per_page = max( 1, min( absint( $request->get_param( 'per_page' ) ?: 10 ), 100 ) );
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $orderby  = sanitize_sql_orderby( $request->get_param( 'orderby' ) . ' ' . $request->get_param( 'order' ) ) ? sanitize_text_field( $request->get_param( 'orderby' ) ) : 'id';
        $order    = strtoupper( $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $values = array();

        if ( \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            $status = $request->get_param( 'status' );
            if ( ! empty( $status ) ) {
                $where[]  = 'status = %s';
                $values[] = sanitize_text_field( $status );
            }
        } else {
            $where[]  = 'status = %s';
            $values[] = 'publish';
        }

        $type = $request->get_param( 'type' );
        if ( ! empty( $type ) ) {
            $where[]  = 'type = %s';
            $values[] = sanitize_text_field( $type );
        } else {
            $where[] = "type <> 'variation'";
        }

        $search = $request->get_param( 'search' );
        if ( ! empty( $search ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
            $where[]  = '(name LIKE %s OR sku LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $exclude = $request->get_param( 'exclude' );
        if ( ! empty( $exclude ) ) {
            if ( is_string( $exclude ) ) {
                $exclude = explode( ',', $exclude );
            }
            $exclude_ids = array_values( array_filter( array_map( 'absint', (array) $exclude ) ) );
            if ( ! empty( $exclude_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
                $where[]      = "id NOT IN ({$placeholders})";
                foreach ( $exclude_ids as $eid ) {
                    $values[] = $eid;
                }
            }
        }

        $featured_param = $request->get_param( 'featured' );
        if ( null !== $featured_param && '' !== $featured_param ) {
            $where[]  = 'featured = %d';
            $values[] = rest_sanitize_boolean( $featured_param ) ? 1 : 0;
        }

        $in_stock_param = $request->get_param( 'in_stock' );
        if ( null !== $in_stock_param && '' !== $in_stock_param ) {
            if ( rest_sanitize_boolean( $in_stock_param ) ) {
                $where[] = "stock_status IN ('instock','onbackorder')";
            } else {
                $where[] = "stock_status = 'outofstock'";
            }
        }

        $on_sale_param = $request->get_param( 'on_sale' );
        if ( null !== $on_sale_param && '' !== $on_sale_param ) {
            if ( rest_sanitize_boolean( $on_sale_param ) ) {
                $where[] = "sale_price IS NOT NULL AND sale_price <> '' AND CAST(sale_price AS DECIMAL(20,4)) > 0 AND CAST(sale_price AS DECIMAL(20,4)) < CAST(price AS DECIMAL(20,4))";
            } else {
                $where[] = "(sale_price IS NULL OR sale_price = '' OR CAST(sale_price AS DECIMAL(20,4)) >= CAST(price AS DECIMAL(20,4)))";
            }
        }

        foreach ( array(
            'category' => \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY,
            'tag'      => \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY,
            'brand'    => \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY,
        ) as $param => $tax ) {
            $raw     = $request->get_param( $param );
            $snippet = $this->term_in_subquery( $tax, $raw );
            if ( null !== $snippet ) {
                $where[] = $snippet;
            }
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $values )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
        }

        $values[] = $offset;
        $values[] = $per_page;

        $allowed_orderby = array( 'id', 'name', 'price', 'created_at', 'status', 'stock_quantity' );
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'id';
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d, %d",
                $values
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $products = array();

        $products_map = \TejCart\Product\Product_Factory::get_products( array_map( 'intval', $ids ) );

        foreach ( $ids as $id ) {
            $product = $products_map[ (int) $id ] ?? null;

            if ( ! $product ) {
                continue;
            }

            $data       = $this->prepare_item_for_response( $product, $request );
            $products[] = $this->prepare_response_for_collection( $data );
        }

        $response = rest_ensure_response( $products );

        $max_pages = (int) ceil( $total / $per_page );

        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', $max_pages );

        return $response;
    }

    /**
     * Get a single product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $product = Product_Factory::get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return new WP_Error(
                'tejcart_rest_product_not_found',
                __( 'Product not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $response = $this->prepare_item_for_response( $product, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Create a product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( $request ) {
        $type = sanitize_text_field( $request->get_param( 'type' ) ) ?: 'physical';

        // Pull from the registry so addons that registered a custom type via
        // `tejcart_product_types` (subscription, pre-order, etc.) can be
        // created through the REST API. The legacy `tejcart_product_class_map`
        // filter is fired inside the registry for backwards compatibility.
        $class_map = Product_Type_Registry::get_class_map( $type, 0 );

        if ( ! isset( $class_map[ $type ] ) || ! class_exists( $class_map[ $type ] ) ) {
            return new WP_Error(
                'tejcart_rest_invalid_product_type',
                __( 'Invalid product type.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $class_name = $class_map[ $type ];
        $product    = new $class_name();

        $this->set_product_properties( $product, $request );

        $name = $product->get_name();
        if ( empty( $name ) ) {
            return new WP_Error(
                'tejcart_rest_product_name_required',
                __( 'Product name is required.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $result = $product->save();

        if ( false === $result ) {
            $save_error = method_exists( $product, 'get_last_save_error' ) ? $product->get_last_save_error() : null;
            if ( $save_error instanceof WP_Error ) {
                return $save_error;
            }
            return new WP_Error(
                'tejcart_rest_product_create_failed',
                __( 'Could not create the product.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        $response = $this->prepare_item_for_response( $product, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Update an existing product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $product = Product_Factory::get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return new WP_Error(
                'tejcart_rest_product_not_found',
                __( 'Product not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $this->set_product_properties( $product, $request );

        $result = $product->save();

        if ( false === $result ) {
            $save_error = method_exists( $product, 'get_last_save_error' ) ? $product->get_last_save_error() : null;
            if ( $save_error instanceof WP_Error ) {
                return $save_error;
            }
            return new WP_Error(
                'tejcart_rest_product_update_failed',
                __( 'Could not update the product.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        $response = $this->prepare_item_for_response( $product, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Delete a product.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item( $request ) {
        $product = Product_Factory::get_product( (int) $request->get_param( 'id' ) );

        if ( ! $product ) {
            return new WP_Error(
                'tejcart_rest_product_not_found',
                __( 'Product not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $deleted = $product->delete();

        if ( ! $deleted ) {
            return new WP_Error(
                'tejcart_rest_product_delete_failed',
                __( 'Could not delete the product.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response( array(
            'deleted' => true,
            'id'      => (int) $request->get_param( 'id' ),
        ) );
    }

    /**
     * Batch create / update / delete products in a single request.
     *
     * Payload:
     *   {
     *     "create": [ { name, price, ... }, ... ],
     *     "update": [ { id, ... }, ... ],
     *     "delete": [ 1, 2, 3 ]
     *   }
     *
     * Each sub-request goes through the same create_item / update_item /
     * delete_item callback so validation and events match the single-item
     * endpoints. Individual failures are surfaced per entry rather than
     * aborting the batch.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function batch_items( $request ) {
        $create_in = (array) $request->get_param( 'create' );
        $update_in = (array) $request->get_param( 'update' );
        $delete_in = (array) $request->get_param( 'delete' );

        $max_per_op = (int) apply_filters( 'tejcart_rest_batch_max_per_operation', 100 );
        if ( $max_per_op > 0 ) {
            $create_in = array_slice( $create_in, 0, $max_per_op );
            $update_in = array_slice( $update_in, 0, $max_per_op );
            $delete_in = array_slice( $delete_in, 0, $max_per_op );
        }

        $created = array();
        foreach ( $create_in as $payload ) {
            $sub = $this->spawn_subrequest( 'POST', $payload );
            $created[] = $this->batch_result( $this->create_item( $sub ) );
        }

        $updated = array();
        foreach ( $update_in as $payload ) {
            if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
                $updated[] = array(
                    'error' => array(
                        'code'    => 'tejcart_rest_batch_missing_id',
                        'message' => __( 'Update entries must include an id.', 'tejcart' ),
                    ),
                );
                continue;
            }
            $sub = $this->spawn_subrequest( 'PUT', $payload );
            $sub->set_url_params( array( 'id' => (int) $payload['id'] ) );
            $updated[] = $this->batch_result( $this->update_item( $sub ) );
        }

        $deleted = array();
        foreach ( $delete_in as $id ) {
            // Re-check the per-operation cap. The batch route is gated on
            // EDIT_PRODUCTS (create_item_permissions_check), but DELETE
            // requires the separate DELETE_PRODUCTS cap (see
            // delete_item_permissions_check). Without this check, an API
            // key / role with EDIT_PRODUCTS only could delete products by
            // stuffing IDs into delete[], bypassing the least-privilege
            // split. (H-6.)
            if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::DELETE_PRODUCTS ) ) {
                $deleted[] = array(
                    'error' => array(
                        'code'    => 'tejcart_rest_cannot_delete',
                        'message' => __( 'Sorry, you are not allowed to delete products.', 'tejcart' ),
                    ),
                );
                continue;
            }
            $sub = $this->spawn_subrequest( 'DELETE', array() );
            $sub->set_url_params( array( 'id' => (int) $id ) );
            $deleted[] = $this->batch_result( $this->delete_item( $sub ) );
        }

        return rest_ensure_response( array(
            'create' => $created,
            'update' => $updated,
            'delete' => $deleted,
        ) );
    }

    /**
     * Build a minimal sub-request that carries the payload into the
     * per-item callbacks. Using WP_REST_Request directly (rather than
     * dispatching through the server) keeps the call synchronous and
     * avoids re-running permission checks that already cleared at the
     * batch level.
     *
     * @param string $method POST|PUT|DELETE
     * @param array  $body   JSON body to seed as params.
     */
    private function spawn_subrequest( string $method, array $body ): \WP_REST_Request {
        $sub = new \WP_REST_Request( $method, '/' . $this->namespace . '/' . $this->rest_base );
        foreach ( $body as $k => $v ) {
            $sub->set_param( (string) $k, $v );
        }
        return $sub;
    }

    /**
     * Normalise a per-item response (or WP_Error) into the batch shape.
     *
     * @param mixed $result Return value from create/update/delete_item.
     * @return array
     */
    private function batch_result( $result ): array {
        if ( is_wp_error( $result ) ) {
            return array(
                'error' => array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'data'    => $result->get_error_data(),
                ),
            );
        }

        if ( $result instanceof \WP_REST_Response ) {
            return (array) $result->get_data();
        }

        return is_array( $result ) ? $result : array( 'data' => $result );
    }

    /**
     * Prepare a single product for response.
     *
     * @param \TejCart\Product\Product_Types\Abstract_Product $product Product object.
     * @param WP_REST_Request                                  $request Request object.
     * @return WP_REST_Response
     */
    public function prepare_item_for_response( $product, $request ) {
        $data = array(
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku'               => $product->get_sku(),
            'price'             => $product->get_price(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'on_sale'           => $product->is_on_sale(),
            'sale_date_from'    => $product->get_sale_date_from() ?: null,
            'sale_date_to'      => $product->get_sale_date_to() ?: null,
            'stock_quantity'    => $product->get_stock_quantity(),
            'stock_status'      => $product->get_stock_status(),
            'in_stock'          => $product->is_in_stock(),

            'purchasable'       => ( $product instanceof \TejCart\Product\Product_Types\Grouped_Product )
                ? $product->has_purchasable_children()
                : $product->is_purchasable(),
            'virtual'           => $product->is_virtual(),
            'downloadable'      => $product->is_downloadable(),
            'weight'            => $product->get_weight(),
            'dimensions'        => $product->get_dimensions(),
            'image_id'          => $product->get_image_id(),
            'image'             => $this->product_image_urls( (int) $product->get_image_id() ),
            'gallery_ids'       => $product->get_gallery_ids(),
            'average_rating'    => Product_Reviews::get_average_rating( $product->get_id() ),
            'review_count'      => Product_Reviews::get_review_count( $product->get_id() ),
            'upsell_ids'        => $product->get_upsell_ids(),
            'crosssell_ids'     => $product->get_crosssell_ids(),
            'related_ids'       => method_exists( $product, 'get_related_ids' ) ? $product->get_related_ids() : array(),
            'tax_class'         => $product->get_tax_class(),
            'shipping_class'    => method_exists( $product, 'get_shipping_class' ) ? (string) $product->get_shipping_class() : '',
            'featured'          => method_exists( $product, 'is_featured' ) ? (bool) $product->is_featured() : false,
            'categories'        => $this->terms_for_response( (int) $product->get_id(), \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY ),
            'tags'              => $this->terms_for_response( (int) $product->get_id(), \TejCart\Product\Product_Taxonomy::TAG_TAXONOMY ),
            'brands'            => $this->terms_for_response( (int) $product->get_id(), \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY ),
        );

        $response = rest_ensure_response( $data );

        /**
         * Filter the product data returned by the REST API.
         *
         * @param WP_REST_Response                                 $response The response object.
         * @param \TejCart\Product\Product_Types\Abstract_Product  $product  The product object.
         * @param WP_REST_Request                                  $request  The request object.
         */
        return apply_filters( 'tejcart_rest_prepare_product', $response, $product, $request );
    }

    /**
     * Get the product schema, conforming to JSON Schema.
     *
     * @return array
     */
    public function get_item_schema() {
        $schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'product',
            'type'       => 'object',
            'properties' => array(
                'id'                => array(
                    'description' => __( 'Unique identifier for the product.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'name'              => array(
                    'description' => __( 'Product name.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'slug'              => array(
                    'description' => __( 'Product slug.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'type'              => array(
                    'description' => __( 'Product type.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'enum'        => Product_Type_Registry::get_rest_types(),
                ),
                'status'            => array(
                    'description' => __( 'Product status.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
                ),
                'description'       => array(
                    'description' => __( 'Product description.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'short_description' => array(
                    'description' => __( 'Product short description.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'sku'               => array(
                    'description' => __( 'Stock keeping unit.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'price'             => array(
                    'description' => __( 'Current active price.', 'tejcart' ),
                    'type'        => array( 'string', 'number' ),
                    'context'     => array( 'view', 'edit' ),
                    'readonly'    => true,
                ),
                'regular_price'     => array(
                    'description' => __( 'Regular price.', 'tejcart' ),
                    // Accept both a decimal string ("19.99") and a JSON number
                    // (19.99): REST clients legitimately send either. A string
                    // value passed against a string-only schema is fine, but a
                    // numeric value would otherwise fail validation with 400.
                    'type'        => array( 'string', 'number' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'sale_price'        => array(
                    'description' => __( 'Sale price.', 'tejcart' ),
                    'type'        => array( 'string', 'number' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'stock_quantity'    => array(
                    'description' => __( 'Stock quantity.', 'tejcart' ),
                    'type'        => array( 'integer', 'null' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'stock_status'      => array(
                    'description' => __( 'Stock status.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
                ),
                'in_stock'          => array(
                    'description' => __( 'Whether the product is in stock.', 'tejcart' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'purchasable'       => array(
                    'description' => __( 'Whether the product is purchasable.', 'tejcart' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'virtual'           => array(
                    'description' => __( 'Whether the product is virtual.', 'tejcart' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view', 'edit' ),
                ),
                'downloadable'      => array(
                    'description' => __( 'Whether the product is downloadable.', 'tejcart' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view', 'edit' ),
                ),
                'weight'            => array(
                    'description' => __( 'Product weight.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'dimensions'        => array(
                    'description' => __( 'Product dimensions.', 'tejcart' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                    'properties'  => array(
                        'length' => array( 'type' => 'string' ),
                        'width'  => array( 'type' => 'string' ),
                        'height' => array( 'type' => 'string' ),
                    ),
                ),
                'image_id'          => array(
                    'description' => __( 'Main image attachment ID.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                ),
                'gallery_ids'       => array(
                    'description' => __( 'Gallery image attachment IDs.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'integer' ),
                ),
                'average_rating'    => array(
                    'description' => __( 'Average product review rating.', 'tejcart' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'review_count'      => array(
                    'description' => __( 'Number of approved product reviews.', 'tejcart' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
                'upsell_ids'        => array(
                    'description' => __( 'Upsell product IDs.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'integer' ),
                ),
                'crosssell_ids'     => array(
                    'description' => __( 'Cross-sell product IDs.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'integer' ),
                ),
                'related_ids'       => array(
                    'description' => __( 'Manually-curated related product IDs (overrides auto-discovery).', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'integer' ),
                ),
                'tax_class'         => array(
                    'description' => __( 'Tax class slug; empty string means the standard class.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'shipping_class'    => array(
                    'description' => __( 'Shipping class slug.', 'tejcart' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                ),
                'featured'          => array(
                    'description' => __( 'Whether the product is flagged as featured.', 'tejcart' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view', 'edit' ),
                ),
                'categories'        => array(
                    'description' => __( 'Assigned product categories.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'object' ),
                    'readonly'    => true,
                ),
                'tags'              => array(
                    'description' => __( 'Assigned product tags.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'object' ),
                    'readonly'    => true,
                ),
                'brands'            => array(
                    'description' => __( 'Assigned product brands.', 'tejcart' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array( 'type' => 'object' ),
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

        $params['status'] = array(
            'description' => __( 'Filter by product status.', 'tejcart' ),
            'type'        => 'string',
            'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
        );

        $params['type'] = array(
            'description' => __( 'Filter by product type.', 'tejcart' ),
            'type'        => 'string',
            'enum'        => Product_Type_Registry::get_rest_types(),
        );

        $params['in_stock'] = array(
            'description' => __( 'Limit results to products currently marked in-stock.', 'tejcart' ),
            'type'        => 'boolean',
        );

        $params['on_sale'] = array(
            'description' => __( 'Limit results to products with an active sale price.', 'tejcart' ),
            'type'        => 'boolean',
        );

        $params['featured'] = array(
            'description' => __( 'Limit results to featured products (true) or non-featured (false).', 'tejcart' ),
            'type'        => 'boolean',
        );

        $params['category'] = array(
            'description' => __( 'Filter by category. Accepts comma-separated slugs or term IDs.', 'tejcart' ),
            'type'        => 'string',
        );

        $params['tag'] = array(
            'description' => __( 'Filter by tag. Accepts comma-separated slugs or term IDs.', 'tejcart' ),
            'type'        => 'string',
        );

        $params['brand'] = array(
            'description' => __( 'Filter by brand. Accepts comma-separated slugs or term IDs.', 'tejcart' ),
            'type'        => 'string',
        );

        $params['orderby'] = array(
            'description' => __( 'Sort collection by attribute.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'id',
            'enum'        => array( 'id', 'name', 'price', 'created_at', 'status', 'stock_quantity' ),
        );

        $params['order'] = array(
            'description' => __( 'Order sort attribute ascending or descending.', 'tejcart' ),
            'type'        => 'string',
            'default'     => 'DESC',
            'enum'        => array( 'ASC', 'DESC' ),
        );

        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
        $params['exclude'] = array(
            'description' => __( 'Comma-separated list of product IDs to exclude from the result set.', 'tejcart' ),
            'type'        => 'string',
        );

        return \TejCart\API\Param_Sanitizers::decorate( $params );
    }

    /**
     * Resolve attachment URLs for a product's featured image so clients can
     * render a thumbnail without a second request. Returns an array of
     * `{ thumbnail, medium, full }` URLs, or null when no image is set.
     *
     * @param int $image_id Attachment ID.
     * @return array{thumbnail:string, medium:string, full:string}|null
     */
    protected function product_image_urls( int $image_id ): ?array {
        if ( $image_id <= 0 ) {
            return null;
        }
        $full   = wp_get_attachment_image_url( $image_id, 'full' );
        $medium = wp_get_attachment_image_url( $image_id, 'medium' );
        $thumb  = wp_get_attachment_image_url( $image_id, 'thumbnail' );
        if ( ! $full && ! $medium && ! $thumb ) {
            return null;
        }
        return array(
            'thumbnail' => (string) ( $thumb  ?: $medium ?: $full ),
            'medium'    => (string) ( $medium ?: $full ?: $thumb ),
            'full'      => (string) ( $full   ?: $medium ?: $thumb ),
        );
    }

    /**
     * Build a compact term list ({id, slug, name}) for a product/taxonomy.
     *
     * @param int    $product_id
     * @param string $taxonomy
     * @return array<int, array{id:int, slug:string, name:string}>
     */
    protected function terms_for_response( int $product_id, string $taxonomy ): array {
        $terms = \TejCart\Product\Product_Taxonomy::get_product_terms( $product_id, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return array();
        }

        $out = array();
        foreach ( $terms as $term ) {
            if ( ! is_object( $term ) ) {
                continue;
            }
            $out[] = array(
                'id'   => (int) ( $term->term_id ?? 0 ),
                'slug' => (string) ( $term->slug ?? '' ),
                'name' => (string) ( $term->name ?? '' ),
            );
        }
        return $out;
    }

    /**
     * Resolve a "term IN" sub-query for a `?tag=` / `?brand=` style filter.
     * Accepts comma-separated slugs OR ints. Returns null when no match.
     *
     * @param string $taxonomy
     * @param mixed  $raw      Request param value.
     * @return string|null     SQL `id IN (...)` snippet, or null.
     */
    protected function term_in_subquery( string $taxonomy, $raw ): ?string {
        global $wpdb;

        if ( null === $raw || '' === $raw ) {
            return null;
        }

        $rel_table = $wpdb->prefix . 'tejcart_term_relationships';
        $tokens    = is_array( $raw ) ? $raw : array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
        if ( empty( $tokens ) ) {
            return null;
        }

        $tt_ids = array();
        foreach ( $tokens as $tok ) {
            $term = is_numeric( $tok )
                ? get_term( (int) $tok, $taxonomy )
                : get_term_by( 'slug', sanitize_title( (string) $tok ), $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $tt_ids[] = (int) $term->term_taxonomy_id;
            }
        }

        if ( empty( $tt_ids ) ) {
            return 'id IN ( 0 )';
        }

        $placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->prepare(
            "id IN ( SELECT product_id FROM {$rel_table} WHERE term_taxonomy_id IN ({$placeholders}) )",
            ...$tt_ids
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Set writable product properties from request data.
     *
     * @param \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
     * @param WP_REST_Request                                  $request Request object.
     * @return void
     */
    protected function set_product_properties( $product, $request ) {
        $setters = array(
            'name'              => 'set_name',
            'slug'              => 'set_slug',
            'status'            => 'set_status',
            'description'       => 'set_description',
            'short_description' => 'set_short_description',
            'sku'               => 'set_sku',
            // `price` is a write alias for `regular_price` (the response
            // exposes both, so this keeps read/write symmetric). It is listed
            // before `regular_price` so an explicit `regular_price` in the same
            // request wins when both are sent.
            'price'             => 'set_price',
            'regular_price'     => 'set_price',
            'sale_price'        => 'set_sale_price',
            'stock_quantity'    => 'set_stock_quantity',
            'stock_status'      => 'set_stock_status',
            'manage_stock'      => 'set_manage_stock',
            'weight'            => 'set_weight',
            'dimensions'        => 'set_dimensions',
            'image_id'          => 'set_image_id',
            'gallery_ids'       => 'set_gallery_ids',
            'downloadable'      => 'set_downloadable',
            'virtual'           => 'set_virtual',
            'tax_class'         => 'set_tax_class',
            'shipping_class'    => 'set_shipping_class',
            'featured'          => 'set_featured',
            'related_ids'       => 'set_related_ids',
        );

        foreach ( $setters as $param => $method ) {
            $value = $request->get_param( $param );

            if ( null === $value || ! method_exists( $product, $method ) ) {
                continue;
            }

            // Publishing is a distinct capability from editing. A caller with
            // only EDIT_PRODUCTS may create/edit drafts but must not push a
            // product live: downgrade a publish/private request to `pending`
            // (held for review) unless the caller also holds PUBLISH_PRODUCTS.
            if ( 'status' === $param ) {
                $value = (string) $value;
                if ( in_array( $value, array( 'publish', 'private' ), true )
                    && ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::PUBLISH_PRODUCTS ) ) {
                    $value = 'pending';
                }
            }

            $product->$method( $value );
        }
    }
}
