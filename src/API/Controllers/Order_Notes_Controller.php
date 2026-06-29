<?php
/**
 * Order Notes REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Order\Order;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/orders/{order_id}/notes.
 *
 * Order notes are persisted in tejcart_order_meta with meta_key = '_order_note'
 * and a serialised note body (see Order::add_note / Order::get_customer_notes).
 * This controller reads/writes the same rows directly so each note has a
 * stable ID (the meta row id) that the REST layer can reference.
 */
class Order_Notes_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base (parent path only; notes live under /orders/{id}/notes).
     */
    protected $rest_base = 'orders';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<order_id>[\d]+)/notes',
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
                    'args'                => array(
                        'note'          => array(
                            'type'        => 'string',
                            'required'    => true,
                            'description' => __( 'Note body.', 'tejcart' ),
                        ),
                        'customer_note' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<order_id>[\d]+)/notes/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'read_permissions_check' ),
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
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS );
    }

    public function write_permissions_check() {
        return \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS );
    }

    /**
     * List notes for an order.
     */
    public function get_items( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $type = (string) ( $request->get_param( 'type' ) ?: 'any' );
        $rows = $this->query_notes( $order->get_id() );

        $items = array();
        foreach ( $rows as $row ) {
            $note = $this->unpack_note( $row );
            if ( ! $note ) {
                continue;
            }
            if ( 'customer' === $type && empty( $note['customer_note'] ) ) {
                continue;
            }
            if ( 'internal' === $type && ! empty( $note['customer_note'] ) ) {
                continue;
            }
            $items[] = $note;
        }

        return rest_ensure_response( $items );
    }

    /**
     * Fetch one note.
     */
    public function get_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $note = $this->fetch_note_row( $order->get_id(), (int) $request['id'] );
        if ( is_wp_error( $note ) ) {
            return $note;
        }

        $payload = $this->unpack_note( $note );
        if ( ! $payload ) {
            return new WP_Error( 'tejcart_rest_order_note_invalid', __( 'Note could not be read.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response( $payload );
    }

    /**
     * Create a note on an order.
     */
    public function create_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $body = (string) $request->get_param( 'note' );
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'tejcart_rest_order_note_empty', __( 'Note body is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $customer_note = (bool) $request->get_param( 'customer_note' );

        if ( ! $order->add_note( $body, $customer_note ) ) {
            return new WP_Error( 'tejcart_rest_order_note_create_failed', __( 'Could not create note.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $row = $this->fetch_latest_note_row( $order->get_id() );
        if ( ! $row ) {
            return new WP_Error( 'tejcart_rest_order_note_create_failed', __( 'Note was created but could not be retrieved.', 'tejcart' ), array( 'status' => 500 ) );
        }

        if ( $customer_note ) {
            do_action( 'tejcart_new_customer_note', $order, $body );
        }

        $response = rest_ensure_response( $this->unpack_note( $row ) );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * Delete a note.
     */
    public function delete_item( $request ) {
        $order = $this->fetch_order( (int) $request['order_id'] );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $row = $this->fetch_note_row( $order->get_id(), (int) $request['id'] );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        if ( ! (bool) $request->get_param( 'force' ) ) {
            return new WP_Error(
                'tejcart_rest_trash_not_supported',
                __( 'Order notes do not support trash; pass force=true to delete.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $previous = $this->unpack_note( $row );

        global $wpdb;
        $table   = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete(
            $table,
            array( 'id' => (int) $row->id, 'order_id' => $order->get_id() ),
            array( '%d', '%d' )
        );

        if ( false === $deleted ) {
            return new WP_Error( 'tejcart_rest_order_note_delete_failed', __( 'Could not delete note.', 'tejcart' ), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'deleted'  => true,
                'previous' => $previous,
            )
        );
    }

    /**
     * Resolve an order, returning 404 on miss.
     *
     * @param int $order_id Order ID.
     * @return Order|WP_Error
     */
    protected function fetch_order( int $order_id ) {
        if ( $order_id <= 0 ) {
            return new WP_Error( 'tejcart_rest_order_invalid_id', __( 'Invalid order ID.', 'tejcart' ), array( 'status' => 404 ) );
        }
        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            return new WP_Error( 'tejcart_rest_order_invalid_id', __( 'Order not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $order;
    }

    /**
     * Query all note rows for an order.
     *
     * @return object[]
     */
    protected function query_notes( int $order_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s ORDER BY id ASC",
                $order_id,
                '_order_note'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Single note row by id, scoped to an order.
     *
     * @return object|WP_Error
     */
    protected function fetch_note_row( int $order_id, int $id ) {
        if ( $id <= 0 ) {
            return new WP_Error( 'tejcart_rest_order_note_invalid_id', __( 'Invalid note ID.', 'tejcart' ), array( 'status' => 404 ) );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, meta_value FROM {$table} WHERE id = %d AND order_id = %d AND meta_key = %s",
                $id,
                $order_id,
                '_order_note'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( ! $row ) {
            return new WP_Error( 'tejcart_rest_order_note_not_found', __( 'Note not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $row;
    }

    /**
     * Most recent note row for an order.
     *
     * @return object|null
     */
    protected function fetch_latest_note_row( int $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
                $order_id,
                '_order_note'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Turn a meta row into the public note payload.
     *
     * @return array|null
     */
    protected function unpack_note( $row ): ?array {
        if ( ! $row || ! isset( $row->meta_value ) ) {
            return null;
        }
        $data = maybe_unserialize( $row->meta_value, array( 'allowed_classes' => false ) );
        if ( ! is_array( $data ) ) {
            return null;
        }

        $author_id = (int) ( $data['author'] ?? 0 );
        $author    = $author_id
            ? esc_html( (string) get_the_author_meta( 'display_name', $author_id ) )
            : __( 'System', 'tejcart' );

        return array(
            'id'            => (int) $row->id,
            'author'        => $author,
            'author_id'     => $author_id,
            'date_created'  => isset( $data['date'] ) ? mysql_to_rfc3339( (string) $data['date'] ) : null,
            'note'          => (string) ( $data['note'] ?? '' ),
            'customer_note' => ! empty( $data['is_customer_note'] ),
        );
    }

    public function get_collection_params(): array {
        return \TejCart\API\Param_Sanitizers::decorate( array(
            'type' => array(
                'description' => __( 'Filter by note visibility.', 'tejcart' ),
                'type'        => 'string',
                'enum'        => array( 'any', 'customer', 'internal' ),
                'default'     => 'any',
            ),
        ) );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'order_note',
            'type'       => 'object',
            'properties' => array(
                'id'            => array( 'type' => 'integer', 'readonly' => true ),
                'author'        => array( 'type' => 'string', 'readonly' => true ),
                'author_id'     => array( 'type' => 'integer', 'readonly' => true ),
                'date_created'  => array( 'type' => array( 'string', 'null' ), 'readonly' => true ),
                'note'          => array( 'type' => 'string' ),
                'customer_note' => array( 'type' => 'boolean' ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
