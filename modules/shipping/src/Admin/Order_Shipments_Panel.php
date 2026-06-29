<?php
/**
 * Admin order detail "Shipments" card.
 *
 * Renders inside the core admin order page (`tejcart_admin_order_after_main`)
 * and lets the merchant:
 *
 *   - View existing shipments for the order (carrier, service, tracking,
 *     label URL, status, cost).
 *   - Buy a label against the rate persisted at checkout (one click per
 *     uncovered method).
 *   - Refresh tracking on demand (forces an immediate Label_Service::track()
 *     instead of waiting for the scheduled poll).
 *   - Void a previously purchased label (carrier-permitting).
 *
 * Capability gate: Capabilities::MANAGE_SHIPPING (with manage_options
 * fallback so single-admin installs keep working).
 *
 * @package TejCart\Shipping_Plugin\Admin
 */

namespace TejCart\Shipping_Plugin\Admin;

use TejCart\Shipping_Plugin\Core\Capabilities;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Carrier_Registry;
use TejCart\Shipping_Plugin\Core\Label_Service;
use TejCart\Shipping_Plugin\Core\Order_Integration;
use TejCart\Shipping_Plugin\Core\Shipment;
use TejCart\Shipping_Plugin\Core\Shipment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Shipments_Panel {
    public const ACTION_BUY    = 'tejcart_shipping_buy_label';
    public const ACTION_VOID   = 'tejcart_shipping_void_label';
    public const ACTION_TRACK  = 'tejcart_shipping_refresh_tracking';
    public const NONCE         = 'tejcart_shipping_order_panel';

    private Carrier_Registry $registry;
    private Shipment_Repository $shipments;
    private Label_Service $label_service;

    public function __construct(
        Carrier_Registry $registry,
        Shipment_Repository $shipments,
        Label_Service $label_service
    ) {
        $this->registry      = $registry;
        $this->shipments     = $shipments;
        $this->label_service = $label_service;
    }

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'tejcart_admin_order_after_main', array( $this, 'render' ) );
        add_action( 'admin_post_' . self::ACTION_BUY,   array( $this, 'handle_buy' ) );
        add_action( 'admin_post_' . self::ACTION_VOID,  array( $this, 'handle_void' ) );
        add_action( 'admin_post_' . self::ACTION_TRACK, array( $this, 'handle_track' ) );
    }

    /**
     * @param mixed $order The TejCart\Order\Order being viewed.
     */
    public function render( $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }
        if ( ! Capabilities::check() ) {
            return;
        }

        $carrier_id   = (string) $order->get_meta( Order_Integration::META_CARRIER_ID );
        $service_code = (string) $order->get_meta( Order_Integration::META_SERVICE_CODE );
        $rate_id      = (string) $order->get_meta( Order_Integration::META_RATE_ID );
        $shipments    = $this->shipments->find_by_order( $order_id );

        if ( '' === $carrier_id && array() === $shipments ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only read; nonce verified in handler
        $notice = isset( $_GET['tejcart_shipping_msg'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['tejcart_shipping_msg'] ) )
            : '';
        $error  = isset( $_GET['tejcart_shipping_err'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['tejcart_shipping_err'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="tejcart-card tejcart-shipping-card">';
        echo '<div class="tejcart-card-header"><h3><span class="dashicons dashicons-cart"></span> '
            . esc_html__( 'Shipments', 'tejcart' ) . '</h3></div>';
        echo '<div class="tejcart-card-body">';

        if ( '' !== $notice ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
        }
        if ( '' !== $error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
        }

        if ( '' !== $carrier_id ) {
            $driver = $this->registry->get( $carrier_id );
            $label  = null === $driver ? $carrier_id : $driver->label();

            echo '<p><strong>' . esc_html__( 'Selected carrier', 'tejcart' ) . ':</strong> '
                . esc_html( $label );
            if ( '' !== $service_code ) {
                echo ' &mdash; <code>' . esc_html( $service_code ) . '</code>';
            }
            echo '</p>';

            if ( '' !== $rate_id && ! $this->order_has_active_label( $shipments ) ) {
                $this->render_buy_form( $order_id );
            } elseif ( '' === $rate_id ) {
                echo '<p class="description">' . esc_html__(
                    'No rate token was captured on this order — label purchase is unavailable. The carrier may be a rate-card-only driver, or the quote was selected from a non-carrier method.',
                    'tejcart'
                ) . '</p>';
            }
        }

        if ( array() !== $shipments ) {
            $this->render_shipments_table( $order_id, $shipments );
        }

        echo '</div></div>';
    }

    private function render_buy_form( int $order_id ): void {
        $action = esc_url( admin_url( 'admin-post.php' ) );
        echo '<form method="post" action="' . esc_attr( $action ) . '" class="tejcart-shipping-buy-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BUY ) . '" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
        wp_nonce_field( self::NONCE );
        submit_button( __( 'Buy shipping label', 'tejcart' ), 'primary', 'submit', false );
        echo '</form>';
    }

    /**
     * @param Shipment[] $shipments
     */
    private function render_shipments_table( int $order_id, array $shipments ): void {
        echo '<table class="widefat striped tejcart-shipping-shipments-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'ID', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Carrier', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Service', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Tracking', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Status', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Cost', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Label', 'tejcart' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Actions', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $shipments as $shipment ) {
            $driver       = $this->registry->get( $shipment->carrier_id );
            $carrier_lbl  = null === $driver ? $shipment->carrier_id : $driver->label();
            $multiplier   = \TejCart\Money\Currency::multiplier( $shipment->currency );
            $decimals     = \TejCart\Money\Currency::decimals( $shipment->currency );
            $cost_display = number_format( $shipment->cost_cents / $multiplier, $decimals ) . ' ' . $shipment->currency;

            echo '<tr>';
            echo '<td>#' . esc_html( (string) $shipment->id ) . '</td>';
            echo '<td>' . esc_html( $carrier_lbl ) . '</td>';
            echo '<td>' . esc_html( $shipment->service_code ) . '</td>';
            echo '<td>' . ( '' === $shipment->tracking_number
                ? '<em>' . esc_html__( 'pending', 'tejcart' ) . '</em>'
                : '<code>' . esc_html( $shipment->tracking_number ) . '</code>' )
                . '</td>';
            echo '<td>' . esc_html( $shipment->status ) . '</td>';
            echo '<td>' . esc_html( $cost_display ) . '</td>';
            echo '<td>' . ( '' === $shipment->label_url
                ? '&mdash;'
                : '<a href="' . esc_url( $shipment->label_url ) . '" target="_blank" rel="noopener noreferrer">'
                  . esc_html( $shipment->label_format ) . '</a>' )
                . '</td>';

            echo '<td>';
            $this->render_action_button( self::ACTION_TRACK, $order_id, (int) $shipment->id, __( 'Refresh tracking', 'tejcart' ) );
            if ( Shipment::STATUS_VOIDED !== $shipment->status && Shipment::STATUS_DELIVERED !== $shipment->status ) {
                $this->render_action_button( self::ACTION_VOID, $order_id, (int) $shipment->id, __( 'Void', 'tejcart' ), 'delete' );
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_action_button( string $action, int $order_id, int $shipment_id, string $label, string $style = 'small' ): void {
        $url = esc_url( admin_url( 'admin-post.php' ) );
        echo '<form method="post" action="' . esc_attr( $url ) . '" class="tejcart-shipping-action-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
        echo '<input type="hidden" name="shipment_id" value="' . esc_attr( (string) $shipment_id ) . '" />';
        wp_nonce_field( self::NONCE );
        $class = 'delete' === $style ? 'button button-link-delete' : 'button button-small';
        echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
        echo '</form>';
    }

    public function handle_buy(): void {
        $order_id = $this->guard_request();

        $order = $this->load_order( $order_id );
        if ( null === $order ) {
            $this->redirect_to_order( $order_id, '', __( 'Order not found.', 'tejcart' ) );
        }

        $carrier_id   = (string) $order->get_meta( Order_Integration::META_CARRIER_ID );
        $service_code = (string) $order->get_meta( Order_Integration::META_SERVICE_CODE );
        $rate_id      = (string) $order->get_meta( Order_Integration::META_RATE_ID );

        if ( '' === $carrier_id || '' === $rate_id ) {
            $this->redirect_to_order( $order_id, '', __( 'No carrier rate token available for this order.', 'tejcart' ) );
        }

        try {
            $shipment = $this->label_service->purchase( $carrier_id, $order_id, $rate_id, $service_code );
            $this->redirect_to_order(
                $order_id,
                sprintf(
                    /* translators: 1: tracking number 2: carrier label */
                    __( 'Label purchased — tracking %1$s with %2$s.', 'tejcart' ),
                    $shipment->tracking_number === '' ? '(pending)' : $shipment->tracking_number,
                    $shipment->carrier_id
                ),
                ''
            );
        } catch ( Carrier_Exception $e ) {
            $this->redirect_to_order( $order_id, '', $e->getMessage() );
        } catch ( \Throwable $e ) {
            $this->redirect_to_order( $order_id, '', __( 'Label purchase failed.', 'tejcart' ) );
        }
    }

    public function handle_void(): void {
        $order_id    = $this->guard_request();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- nonce verified earlier in this handler via guard_request()
        $shipment_id = isset( $_POST['shipment_id'] ) ? (int) $_POST['shipment_id'] : 0;

        if ( $shipment_id <= 0 ) {
            $this->redirect_to_order( $order_id, '', __( 'Invalid shipment.', 'tejcart' ) );
        }

        try {
            $this->label_service->void( $shipment_id );
            $this->redirect_to_order( $order_id, __( 'Label voided.', 'tejcart' ), '' );
        } catch ( Carrier_Exception $e ) {
            $this->redirect_to_order( $order_id, '', $e->getMessage() );
        } catch ( \Throwable $e ) {
            $this->redirect_to_order( $order_id, '', __( 'Voiding the label failed.', 'tejcart' ) );
        }
    }

    public function handle_track(): void {
        $order_id    = $this->guard_request();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- nonce verified earlier in this handler via guard_request()
        $shipment_id = isset( $_POST['shipment_id'] ) ? (int) $_POST['shipment_id'] : 0;

        if ( $shipment_id <= 0 ) {
            $this->redirect_to_order( $order_id, '', __( 'Invalid shipment.', 'tejcart' ) );
        }

        $shipment = $this->shipments->find_by_id( $shipment_id );
        if ( null === $shipment ) {
            $this->redirect_to_order( $order_id, '', __( 'Shipment not found.', 'tejcart' ) );
        }
        if ( '' === $shipment->tracking_number ) {
            $this->redirect_to_order( $order_id, '', __( 'No tracking number on this shipment yet.', 'tejcart' ) );
        }

        try {
            $tracking = $this->label_service->track( $shipment->carrier_id, $shipment->tracking_number, $shipment->id );
            $this->redirect_to_order(
                $order_id,
                sprintf(
                    /* translators: %s: tracking status */
                    __( 'Tracking refreshed — current status: %s.', 'tejcart' ),
                    $tracking->status
                ),
                ''
            );
        } catch ( Carrier_Exception $e ) {
            $this->redirect_to_order( $order_id, '', $e->getMessage() );
        } catch ( \Throwable $e ) {
            $this->redirect_to_order( $order_id, '', __( 'Tracking refresh failed.', 'tejcart' ) );
        }
    }

    private function guard_request(): int {
        if ( ! Capabilities::check() ) {
            wp_die( esc_html__( 'You do not have permission to manage shipments.', 'tejcart' ) );
        }
        check_admin_referer( self::NONCE );
        return isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
    }

    private function load_order( int $order_id ) {
        // F-MODL-009: Use tejcart_get_order() instead of newing up \TejCart\Order\Order
        // directly so future Order factory decorators are not silently bypassed.
        if ( $order_id <= 0 || ! function_exists( 'tejcart_get_order' ) ) {
            return null;
        }
        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || (int) $order->get_id() <= 0 ) {
            return null;
        }
        return $order;
    }

    /**
     * @param Shipment[] $shipments
     */
    private function order_has_active_label( array $shipments ): bool {
        foreach ( $shipments as $shipment ) {
            if ( Shipment::STATUS_VOIDED !== $shipment->status ) {
                return true;
            }
        }
        return false;
    }

    private function redirect_to_order( int $order_id, string $msg, string $err ): void {
        $args = array(
            'page'     => 'tejcart-orders',
            'action'   => 'view',
            'order_id' => $order_id,
        );
        if ( '' !== $msg ) {
            $args['tejcart_shipping_msg'] = $msg;
        }
        if ( '' !== $err ) {
            $args['tejcart_shipping_err'] = $err;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
