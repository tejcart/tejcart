<?php
/**
 * Settings REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Settings\Settings_Tabs;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/settings.
 *
 * Reads and writes the tab/field configuration registered by
 * Settings_Tabs. Storage backs onto wp_options under the `tejcart_*`
 * prefix (matching Settings_API::get_option / update_option).
 *
 * Built-in field types (text, textarea, number, checkbox, select,
 * radio, password, color) are validated server-side; readonly /
 * heading / note are excluded from writes.
 */
class Settings_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'settings';

    /**
     * Sentinel returned in place of a stored password value so secrets are
     * never echoed back in REST read responses. When the client submits this
     * exact value unchanged, the stored secret is preserved on write.
     */
    const SECRET_MASK = '__tejcart_secret_unchanged__';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        $namespace = $this->namespace;
        $base      = $this->rest_base;

        register_rest_route( $namespace, '/' . $base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_groups' ),
                'permission_callback' => array( $this, 'read_permissions_check' ),
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/(?P<group>[\w-]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_options' ),
                'permission_callback' => array( $this, 'read_permissions_check' ),
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/(?P<group>[\w-]+)/batch', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'batch_update' ),
                'permission_callback' => array( $this, 'manage_permissions_check' ),
                'args'                => array(
                    'update' => array(
                        'type'        => 'array',
                        'description' => __( 'Array of {id,value} updates.', 'tejcart' ),
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'    => array( 'type' => 'string' ),
                                'value' => array(),
                            ),
                        ),
                    ),
                ),
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/(?P<group>[\w-]+)/(?P<id>[\w-]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_option_item' ),
                'permission_callback' => array( $this, 'read_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_option_item' ),
                'permission_callback' => array( $this, 'manage_permissions_check' ),
                'args'                => array(
                    'value' => array( 'description' => __( 'New value.', 'tejcart' ) ),
                ),
            ),
        ) );
    }

    public function read_permissions_check() {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_read_settings',
                __( 'Sorry, you are not allowed to read TejCart settings.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        return true;
    }

    public function manage_permissions_check() {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_manage_settings',
                __( 'Sorry, you are not allowed to manage TejCart settings.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        return true;
    }

    public function get_groups( WP_REST_Request $request ) {
        $tabs = ( new Settings_Tabs() )->get_tabs();
        $groups = array();
        foreach ( $tabs as $id => $tab ) {
            $groups[] = array(
                'id'          => (string) ( $tab['id'] ?? $id ),
                'label'       => (string) ( $tab['label'] ?? $id ),
                'description' => '',
            );
        }

        // Storefront page IDs (created on activation, stored as options).
        // Exposed as a `pages` map and as flat `<key>_page_id` fields so
        // headless clients can resolve storefront URLs without scraping the
        // admin. `account` falls back to the My Account page when no separate
        // account page is configured.
        $myaccount = (int) get_option( 'tejcart_myaccount_page_id', 0 );
        $page_ids  = array(
            'shop'      => (int) get_option( 'tejcart_shop_page_id', 0 ),
            'cart'      => (int) get_option( 'tejcart_cart_page_id', 0 ),
            'checkout'  => (int) get_option( 'tejcart_checkout_page_id', 0 ),
            'myaccount' => $myaccount,
            'account'   => (int) get_option( 'tejcart_account_page_id', 0 ) ?: $myaccount,
            'thankyou'  => (int) get_option( 'tejcart_thankyou_page_id', 0 ),
            'terms'     => (int) get_option( 'tejcart_terms_page_id', 0 ),
            'wishlist'  => (int) get_option( 'tejcart_wishlist_page_id', 0 ),
        );

        $pages    = array();
        $response = array( 'groups' => $groups );
        foreach ( $page_ids as $key => $pid ) {
            $value                          = $pid > 0 ? $pid : null;
            $pages[ $key ]                  = $value;
            $response[ $key . '_page_id' ]  = $value;
        }
        $response['pages'] = $pages;

        return rest_ensure_response( $response );
    }

    public function get_options( WP_REST_Request $request ) {
        $group = (string) $request['group'];
        $fields = $this->fields_for( $group );
        if ( null === $fields ) {
            return new WP_Error( 'tejcart_rest_settings_group_not_found', __( 'Settings group not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $items = array();
        foreach ( $fields as $field ) {
            $name = (string) ( $field['name'] ?? '' );
            if ( '' === $name ) {
                continue;
            }
            $items[] = $this->shape_option( $field );
        }
        return rest_ensure_response( $items );
    }

    public function get_option_item( WP_REST_Request $request ) {
        $field = $this->find_field( (string) $request['group'], (string) $request['id'] );
        if ( is_wp_error( $field ) ) {
            return $field;
        }
        return rest_ensure_response( $this->shape_option( $field ) );
    }

    public function update_option_item( WP_REST_Request $request ) {
        $field = $this->find_field( (string) $request['group'], (string) $request['id'] );
        if ( is_wp_error( $field ) ) {
            return $field;
        }

        $sanitized = $this->sanitize_value( $field, $request->get_param( 'value' ) );
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        update_option( 'tejcart_' . $field['name'], $sanitized );

        return rest_ensure_response( $this->shape_option( $field ) );
    }

    public function batch_update( WP_REST_Request $request ) {
        $group   = (string) $request['group'];
        $fields  = $this->fields_for( $group );
        if ( null === $fields ) {
            return new WP_Error( 'tejcart_rest_settings_group_not_found', __( 'Settings group not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $by_id = array();
        foreach ( $fields as $field ) {
            $by_id[ (string) ( $field['name'] ?? '' ) ] = $field;
        }

        $updates  = (array) $request->get_param( 'update' );
        $applied  = array();
        $failures = array();

        foreach ( $updates as $row ) {
            $id = (string) ( $row['id'] ?? '' );
            if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
                $failures[] = array( 'id' => $id, 'error' => 'unknown_setting' );
                continue;
            }
            $sanitized = $this->sanitize_value( $by_id[ $id ], $row['value'] ?? null );
            if ( is_wp_error( $sanitized ) ) {
                $failures[] = array( 'id' => $id, 'error' => $sanitized->get_error_code() );
                continue;
            }
            update_option( 'tejcart_' . $by_id[ $id ]['name'], $sanitized );
            $applied[] = $this->shape_option( $by_id[ $id ] );
        }

        return rest_ensure_response(
            array(
                'updated' => $applied,
                'failed'  => $failures,
            )
        );
    }

    /**
     * @return array|null Fields for a group, or null when the group isn't registered.
     */
    protected function fields_for( string $group ): ?array {
        $tabs = ( new Settings_Tabs() );
        if ( ! $tabs->get_tab( $group ) ) {
            return null;
        }
        return (array) $tabs->get_tab_fields( $group );
    }

    /**
     * @return array|WP_Error
     */
    protected function find_field( string $group, string $id ) {
        $fields = $this->fields_for( $group );
        if ( null === $fields ) {
            return new WP_Error( 'tejcart_rest_settings_group_not_found', __( 'Settings group not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        foreach ( $fields as $field ) {
            if ( (string) ( $field['name'] ?? '' ) === $id ) {
                return $field;
            }
        }
        return new WP_Error( 'tejcart_rest_setting_not_found', __( 'Setting not found.', 'tejcart' ), array( 'status' => 404 ) );
    }

    /**
     * Convert one Settings_API field definition into the API shape.
     */
    protected function shape_option( array $field ): array {
        $name    = (string) ( $field['name'] ?? '' );
        $type    = (string) ( $field['type'] ?? 'text' );
        $default = $field['default'] ?? '';
        $value   = get_option( 'tejcart_' . $name, $default );

        // Never return a stored secret in cleartext. Replace a non-empty
        // password value with the sentinel; sanitize_value() restores the
        // stored secret when the unchanged sentinel is submitted back.
        if ( 'password' === $type && '' !== (string) $value ) {
            $value = self::SECRET_MASK;
        }

        return array(
            'id'          => $name,
            'label'       => (string) ( $field['label'] ?? $name ),
            'description' => (string) ( $field['desc'] ?? '' ),
            'type'        => $type,
            'value'       => $value,
            'default'     => $default,
            'options'     => isset( $field['options'] ) ? (array) $field['options'] : null,
        );
    }

    /**
     * Type-aware sanitization mirroring Settings_API::sanitize().
     *
     * @return mixed|WP_Error
     */
    protected function sanitize_value( array $field, $value ) {
        $type = (string) ( $field['type'] ?? 'text' );

        if ( in_array( $type, array( 'readonly', 'heading', 'note' ), true ) ) {
            return new WP_Error( 'tejcart_rest_setting_not_writable', __( 'This setting is not writable.', 'tejcart' ), array( 'status' => 400 ) );
        }

        switch ( $type ) {
            case 'password':
                // The read path masks secrets with SECRET_MASK; if the client
                // submits it unchanged, preserve the stored secret rather than
                // overwriting it with the sentinel.
                if ( self::SECRET_MASK === (string) $value ) {
                    return get_option( 'tejcart_' . (string) ( $field['name'] ?? '' ), '' );
                }
                return sanitize_text_field( (string) $value );

            case 'text':
                return sanitize_text_field( (string) $value );

            case 'textarea':
                return sanitize_textarea_field( (string) $value );

            case 'number':
                return (int) $value;

            case 'checkbox':
                if ( is_bool( $value ) ) {
                    return $value ? 'yes' : 'no';
                }
                return ( 'yes' === $value || 1 === $value || '1' === $value || true === $value ) ? 'yes' : 'no';

            case 'select':
            case 'radio':
                $allowed = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
                if ( ! in_array( (string) $value, $allowed, true ) ) {
                    return new WP_Error(
                        'tejcart_rest_setting_invalid_value',
                        __( 'Value is not in the allowed options.', 'tejcart' ),
                        array( 'status' => 400 )
                    );
                }
                return (string) $value;

            case 'color':
                $color = sanitize_hex_color( (string) $value );
                if ( null === $color && '' !== (string) $value ) {
                    return new WP_Error( 'tejcart_rest_setting_invalid_color', __( 'Invalid color value.', 'tejcart' ), array( 'status' => 400 ) );
                }
                return $color ?? '';

            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
