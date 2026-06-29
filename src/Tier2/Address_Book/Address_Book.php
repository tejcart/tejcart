<?php
/**
 * Customer address book.
 *
 * Lets logged-in customers store multiple billing / shipping addresses
 * and select them on the checkout page. Integrates by:
 *
 *  - Exposing AJAX endpoints (CRUD).
 *  - Filtering `tejcart_checkout_default_address` (consumed by the
 *    existing checkout fields renderer when present).
 *
 * @package TejCart\Tier2\Address_Book
 */

declare( strict_types=1 );

namespace TejCart\Tier2\Address_Book;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Address_Book {
    public static function init() {
        add_action( 'wp_ajax_tejcart_address_save',   array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_tejcart_address_delete', array( __CLASS__, 'ajax_delete' ) );
        add_action( 'wp_ajax_tejcart_address_list',   array( __CLASS__, 'ajax_list' ) );

        add_filter( 'tejcart_checkout_default_address', array( __CLASS__, 'inject_default' ), 10, 3 );
    }

    /**
     * Get all addresses for a user, optionally filtered by type.
     *
     * @return array<int, array{id:int,label:string,type:string,is_default:bool,address:array}>
     */
    public static function get_for_user( $user_id, $type = '' ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }
        $table = $wpdb->prefix . 'tejcart_addresses';
        $sql   = "SELECT * FROM {$table} WHERE user_id = %d";
        $args  = array( $user_id );
        if ( $type ) {
            $sql   .= ' AND type = %s';
            $args[] = $type;
        }
        $sql .= ' ORDER BY is_default DESC, id ASC';

        // $sql is composed from fixed clause strings (no user input); values are bound via $wpdb->prepare().
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $row ) {
            $row['address']    = json_decode( $row['address'], true ) ?: array();
            $row['is_default'] = (bool) $row['is_default'];
            $out[]             = $row;
        }
        return $out;
    }

    /**
     * Insert or update a single address row.
     *
     * @param int   $user_id
     * @param array $data    Must contain `address` (assoc array) and may contain id/label/type/is_default.
     * @return int|\WP_Error
     */
    public static function save( $user_id, array $data ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return new \WP_Error( 'invalid_user', __( 'You must be logged in.', 'tejcart' ) );
        }
        if ( empty( $data['address'] ) || ! is_array( $data['address'] ) ) {
            return new \WP_Error( 'invalid_address', __( 'Address payload is required.', 'tejcart' ) );
        }

        $table = $wpdb->prefix . 'tejcart_addresses';
        $type  = ! empty( $data['type'] ) && in_array( $data['type'], array( 'billing', 'shipping' ), true )
            ? $data['type']
            : 'shipping';
        $is_default = ! empty( $data['is_default'] ) ? 1 : 0;

        if ( $is_default ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "UPDATE {$table} SET is_default = 0 WHERE user_id = %d AND type = %s",
                    $user_id,
                    $type
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        $row = array(
            'user_id'    => $user_id,
            'label'      => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '',
            'type'       => $type,
            'is_default' => $is_default,
            'address'    => wp_json_encode( self::sanitize_address( $data['address'] ) ),
        );
        $formats = array( '%d', '%s', '%s', '%d', '%s' );

        if ( ! empty( $data['id'] ) ) {
            $id = (int) $data['id'];

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $owner = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $id )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $owner !== $user_id ) {
                return new \WP_Error( 'forbidden', __( 'You do not own this address.', 'tejcart' ) );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );
            return $id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->insert( $table, $row, $formats );
        return (int) $wpdb->insert_id;
    }

    /**
     * Delete an address (ownership-checked).
     */
    public static function delete( $user_id, $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_addresses';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return false !== $wpdb->delete(
            $table,
            array( 'id' => (int) $id, 'user_id' => (int) $user_id ),
            array( '%d', '%d' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Sanitize an address payload to a known set of keys.
     */
    public static function sanitize_address( array $address ) {
        $allowed = array(
            'first_name', 'last_name', 'company',
            'address_1', 'address_2', 'city', 'state',
            'postcode', 'country', 'phone',
        );
        $clean = array();
        foreach ( $allowed as $key ) {
            if ( isset( $address[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( (string) $address[ $key ] );
            }
        }
        return $clean;
    }

    private static function check_request() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Login required.', 'tejcart' ) ), 401 );
        }
        check_ajax_referer( 'tejcart_nonce', 'nonce' );
    }

    public static function ajax_list() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
        wp_send_json_success( self::get_for_user( get_current_user_id(), $type ) );
    }

    public static function ajax_save() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $payload = array(
            'id'         => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
            'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
            'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'shipping',
            'is_default' => ! empty( $_POST['is_default'] ),
            'address'    => isset( $_POST['address'] ) && is_array( $_POST['address'] )
                ? map_deep( wp_unslash( $_POST['address'] ), 'sanitize_text_field' )
                : array(),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $result = self::save( get_current_user_id(), $payload );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'id' => $result ) );
    }

    public static function ajax_delete() {
        self::check_request();
        // Nonce verified by check_request() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Missing id.', 'tejcart' ) ), 400 );
        }
        self::delete( get_current_user_id(), $id );
        wp_send_json_success();
    }

    /**
     * Filter applied by the checkout field renderer to pre-fill the form
     * with the user's saved default billing / shipping address.
     *
     * The filter contract (see {@see \TejCart\Checkout\Checkout_Fields::get_fields()})
     * is:
     *   - input  : array $defaults of `field_key => value` (e.g. `billing_first_name => 'Alex'`)
     *   - output : same shape, with new keys appended for fields not already set
     *
     * Earlier revisions hooked this filter with the wrong arity (2 args)
     * AND returned flat address keys (`first_name`) instead of prefixed
     * checkout-field keys (`billing_first_name`), which meant the
     * autofill never wired through. Both are corrected here.
     *
     * @param array $defaults Existing defaults keyed by checkout field key.
     * @param int   $user_id  Current user ID.
     * @param array $context  Render context (`is_billing` / `is_shipping`).
     * @return array
     */
    public static function inject_default( $defaults, $user_id = 0, $context = array() ) {
        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        $user_id = (int) $user_id;
        if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }
        if ( $user_id <= 0 ) {
            return $defaults;
        }

        $is_billing  = ! isset( $context['is_billing'] )  || ! empty( $context['is_billing'] );
        $is_shipping = ! isset( $context['is_shipping'] ) || ! empty( $context['is_shipping'] );

        if ( $is_billing ) {
            $defaults = self::merge_default_for_type( $defaults, $user_id, 'billing' );
        }
        if ( $is_shipping ) {
            $defaults = self::merge_default_for_type( $defaults, $user_id, 'shipping' );
        }

        return $defaults;
    }

    /**
     * Merge the user's `is_default` address of one type into the
     * checkout-field defaults map, prefixing each address key with
     * `billing_` / `shipping_` and skipping keys already populated by an
     * earlier filter listener.
     *
     * @param array  $defaults Existing defaults.
     * @param int    $user_id  Current user.
     * @param string $type     'billing' or 'shipping'.
     * @return array
     */
    private static function merge_default_for_type( array $defaults, int $user_id, string $type ): array {
        $rows = self::get_for_user( $user_id, $type );
        foreach ( $rows as $row ) {
            if ( empty( $row['is_default'] ) || ! is_array( $row['address'] ) ) {
                continue;
            }
            foreach ( $row['address'] as $key => $value ) {
                $field_key = $type . '_' . $key;
                if ( array_key_exists( $field_key, $defaults ) ) {
                    continue;
                }
                $value = (string) $value;
                if ( '' === $value ) {
                    continue;
                }
                $defaults[ $field_key ] = $value;
            }
            return $defaults;
        }
        return $defaults;
    }
}
