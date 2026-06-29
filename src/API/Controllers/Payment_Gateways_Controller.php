<?php
/**
 * Payment Gateways REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Gateways\Abstract_Gateway;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/payment_gateways.
 *
 * Reflects the gateways registered in Gateway_Registry. Updates persist
 * via the gateway's own settings API (Abstract_Gateway::save_settings),
 * so changes show up identically on the admin Payments tab.
 */
class Payment_Gateways_Controller extends WP_REST_Controller {
    /**
     * Placeholder returned in place of a configured secret value. Echoed back
     * unchanged from the UI, it is ignored on write so a round-trip cannot
     * overwrite the stored secret.
     */
    private const REDACTED_SECRET = '********';

    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'payment_gateways';

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
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\w-]+)',
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
                    'args'                => array(
                        'enabled'     => array( 'type' => 'boolean' ),
                        'title'       => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                        'order'       => array( 'type' => 'integer' ),
                        'settings'    => array( 'type' => 'object' ),
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
        $items = array();
        foreach ( $this->all_gateways() as $gateway ) {
            $items[] = $this->prepare_gateway( $gateway );
        }
        return rest_ensure_response( $items );
    }

    public function get_item( $request ) {
        $gateway = $this->find_gateway( (string) $request['id'] );
        if ( is_wp_error( $gateway ) ) {
            return $gateway;
        }
        return rest_ensure_response( $this->prepare_gateway( $gateway ) );
    }

    public function update_item( $request ) {
        $gateway = $this->find_gateway( (string) $request['id'] );
        if ( is_wp_error( $gateway ) ) {
            return $gateway;
        }

        if ( null !== $request->get_param( 'enabled' ) ) {
            $gateway->update_option( 'enabled', $request->get_param( 'enabled' ) ? 'yes' : 'no' );
        }
        if ( null !== $request->get_param( 'title' ) ) {
            $gateway->update_option( 'title', sanitize_text_field( (string) $request->get_param( 'title' ) ) );
        }
        if ( null !== $request->get_param( 'description' ) ) {
            $gateway->update_option( 'description', wp_kses_post( (string) $request->get_param( 'description' ) ) );
        }
        if ( null !== $request->get_param( 'order' ) ) {
            $gateway->update_option( 'order', (int) $request->get_param( 'order' ) );
        }

        $settings = $request->get_param( 'settings' );
        if ( is_array( $settings ) ) {
            // Allow-list against the gateway's declared form fields. The
            // endpoint is admin-gated, but blindly forwarding raw values
            // (M-1) lets a CSRF on a logged-in admin flip production
            // PayPal credentials to attacker-controlled sandbox values
            // and pollute the option blob with arbitrary keys.
            $allowed = array();
            if ( method_exists( $gateway, 'get_form_fields' ) ) {
                $allowed = array_keys( (array) $gateway->get_form_fields() );
            }

            foreach ( $settings as $key => $value ) {
                $key = (string) $key;
                if ( '' === $key || ! in_array( $key, $allowed, true ) ) {
                    continue;
                }

                // Ignore the masked sentinel echoed back from a read response
                // so re-saving the settings form never overwrites a real
                // secret with the placeholder.
                if ( is_scalar( $value ) && self::REDACTED_SECRET === (string) $value ) {
                    continue;
                }

                if ( is_scalar( $value ) ) {
                    $value = sanitize_text_field( (string) $value );
                } else {
                    // Non-scalar values for declared scalar fields are dropped.
                    // Gateways that legitimately need structured settings can
                    // expose a sanitize_setting($key, $value) helper to opt in.
                    if ( method_exists( $gateway, 'sanitize_setting' ) ) {
                        $value = $gateway->sanitize_setting( $key, $value );
                    } else {
                        continue;
                    }
                }

                $gateway->update_option( $key, $value );
            }
        }

        $gateway->save_settings();

        return rest_ensure_response( $this->prepare_gateway( $gateway ) );
    }

    /**
     * @return Abstract_Gateway[]
     */
    protected function all_gateways(): array {
        if ( ! function_exists( 'tejcart' ) ) {
            return array();
        }
        $registry = tejcart()->gateways();
        if ( ! $registry ) {
            return array();
        }
        return $registry->get_gateways();
    }

    /**
     * @return Abstract_Gateway|WP_Error
     */
    protected function find_gateway( string $id ) {
        $gateway = function_exists( 'tejcart' ) && tejcart()->gateways()
            ? tejcart()->gateways()->get_gateway( $id )
            : null;

        if ( ! $gateway ) {
            return new WP_Error( 'tejcart_rest_gateway_not_found', __( 'Payment gateway not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return $gateway;
    }

    /**
     * Convert a gateway to the API shape.
     */
    protected function prepare_gateway( Abstract_Gateway $gateway ): array {
        $supports = array();

        foreach ( array( 'products', 'refunds', 'tokenization', 'subscriptions' ) as $feature ) {
            if ( $gateway->supports( $feature ) ) {
                $supports[] = $feature;
            }
        }

        $settings = $gateway->get_settings();

        unset( $settings['enabled'], $settings['title'], $settings['description'], $settings['order'] );

        // Never expose secret credential material (PayPal client secrets, etc.)
        // over REST — a leak is a direct pivot to merchant impersonation. Mask
        // non-empty secrets with a sentinel so the UI can still show that a
        // value is configured; update_item() ignores the sentinel on write so
        // a round-trip cannot blank the stored secret.
        foreach ( $gateway->get_secret_setting_keys() as $secret_key ) {
            if ( array_key_exists( $secret_key, $settings ) ) {
                $settings[ $secret_key ] = '' === (string) $settings[ $secret_key ] ? '' : self::REDACTED_SECRET;
            }
        }

        return array(
            'id'                  => $gateway->get_id(),
            'title'               => $gateway->get_title(),
            'description'         => $gateway->get_description(),
            'enabled'             => 'yes' === $gateway->get_option( 'enabled', 'no' ),
            'order'               => (int) $gateway->get_option( 'order', '0' ),
            'method_title'        => $gateway->get_title(),
            'method_description'  => $gateway->get_description(),
            'method_supports'     => $supports,
            'settings'            => $settings,
        );
    }

    public function get_item_schema(): array {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'payment_gateway',
            'type'       => 'object',
            'properties' => array(
                'id'                 => array( 'type' => 'string', 'readonly' => true ),
                'title'              => array( 'type' => 'string' ),
                'description'        => array( 'type' => 'string' ),
                'enabled'            => array( 'type' => 'boolean' ),
                'order'              => array( 'type' => 'integer' ),
                'method_title'       => array( 'type' => 'string', 'readonly' => true ),
                'method_description' => array( 'type' => 'string', 'readonly' => true ),
                'method_supports'    => array( 'type' => 'array', 'readonly' => true ),
                'settings'           => array( 'type' => 'object' ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
