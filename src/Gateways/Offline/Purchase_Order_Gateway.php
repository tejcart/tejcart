<?php
/**
 * Purchase Order payment gateway.
 *
 * Allows B2B customers to place orders using a PO number. The order
 * is created in the "on-hold" status and the PO number is stored as
 * order meta for manual reconciliation.
 *
 * @package TejCart\Gateways\Offline
 */

declare( strict_types=1 );

namespace TejCart\Gateways\Offline;

use TejCart\Gateways\Abstract_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Purchase_Order_Gateway extends Abstract_Gateway {
    public function __construct() {
        $this->id          = 'purchase_order';
        $this->title       = __( 'Purchase Order', 'tejcart' );
        $this->description = __( 'Place an order using a purchase order number.', 'tejcart' );
        $this->supports    = array( 'products' );

        parent::__construct();

        add_action( 'tejcart_thankyou_' . $this->id, array( $this, 'render_po_info' ), 10, 1 );
    }

    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Purchase Order as a payment method for B2B customers.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'       => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Purchase Order', 'tejcart' ),
            ),
            'description' => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Enter your purchase order number. Orders will be processed upon PO verification.', 'tejcart' ),
            ),
            'instructions' => array(
                'type'        => 'textarea',
                'title'       => __( 'Instructions', 'tejcart' ),
                'description' => __( 'Instructions shown on the thank-you page and in order emails.', 'tejcart' ),
                'default'     => __( 'Your order will be processed once the purchase order has been verified.', 'tejcart' ),
            ),
        );
    }

    /**
     * Only available to logged-in B2B company members.
     */
    public function is_available(): bool {
        if ( ! parent::is_available() ) {
            return false;
        }

        $company = $this->get_current_company();
        if ( ! $company ) {
            return false;
        }

        if ( ! $company->is_active() ) {
            return false;
        }

        return (bool) apply_filters( 'tejcart_purchase_order_is_available', true, $company, $this );
    }

    /**
     * Render the PO number input at checkout.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        echo '<fieldset class="tejcart-purchase-order-fields">';
        echo '<p class="form-row form-row-wide">';
        echo '<label for="purchase_order_number">' . esc_html__( 'PO Number', 'tejcart' ) . ' <abbr class="required" title="required">*</abbr></label>';
        echo '<input type="text" class="input-text" name="purchase_order_number" id="purchase_order_number" '
            . 'placeholder="' . esc_attr__( 'Enter your PO number', 'tejcart' ) . '" required />';
        echo '</p>';
        echo '</fieldset>';
    }

    /**
     * Validate that a PO number was provided.
     */
    public function validate_fields(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $po = isset( $_POST['purchase_order_number'] )
            ? sanitize_text_field( wp_unslash( $_POST['purchase_order_number'] ) )
            : '';

        if ( '' === $po ) {
            if ( function_exists( 'tejcart_add_notice' ) ) {
                tejcart_add_notice( __( 'Please enter your Purchase Order number.', 'tejcart' ), 'error' );
            }
            return false;
        }

        return true;
    }

    /** @return array{result: string, redirect: string, message?: string} */
    public function process_payment( int $order_id ): array {
        $order = new \TejCart\Order\Order( $order_id );

        if ( ! $order->get_id() ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'tejcart' ),
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $po_number = isset( $_POST['purchase_order_number'] )
            ? sanitize_text_field( wp_unslash( $_POST['purchase_order_number'] ) )
            : '';

        if ( function_exists( 'tejcart_update_order_meta' ) ) {
            tejcart_update_order_meta( $order_id, '_purchase_order_number', $po_number );
        }

        $company = $this->get_current_company();
        if ( $company && function_exists( 'tejcart_update_order_meta' ) ) {
            tejcart_update_order_meta( $order_id, '_b2b_company_id', (string) $company->get_id() );
        }

        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s: PO number. */
                __( 'Awaiting purchase order reconciliation. PO: %s', 'tejcart' ),
                $po_number
            )
        );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Render PO number and instructions on the thank-you page.
     *
     * @param int $order_id Order ID.
     */
    public function render_po_info( $order_id ): void {
        $po = function_exists( 'tejcart_get_order_meta' )
            ? tejcart_get_order_meta( (int) $order_id, '_purchase_order_number', true )
            : '';

        $instructions = trim( (string) $this->get_option( 'instructions', '' ) );

        if ( '' === $po && '' === $instructions ) {
            return;
        }

        echo '<section class="tejcart-purchase-order-info">';
        echo '<h2>' . esc_html__( 'Purchase Order', 'tejcart' ) . '</h2>';
        if ( '' !== $po ) {
            echo '<p>' . sprintf(
                /* translators: %s: PO number. */
                esc_html__( 'PO Number: %s', 'tejcart' ),
                '<strong>' . esc_html( $po ) . '</strong>'
            ) . '</p>';
        }
        if ( '' !== $instructions ) {
            $formatted = $instructions;
            if ( function_exists( 'wptexturize' ) ) {
                $formatted = wptexturize( $formatted );
            }
            if ( function_exists( 'wpautop' ) ) {
                $formatted = wpautop( $formatted );
            }
            echo wp_kses_post( $formatted );
        }
        echo '</section>';
    }

    /**
     * Show PO number in admin order details.
     */
    public function is_admin_visible(): bool {
        return true;
    }

    private function get_return_url( \TejCart\Order\Order $order ): string {
        $page_id = (int) get_option( 'tejcart_thankyou_page_id', 0 );
        $url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
            ),
            $url
        );
    }

    /**
     * @return \TejCart\B2B\Company|null
     */
    private function get_current_company() {
        if ( ! function_exists( 'get_current_user_id' ) || 0 === get_current_user_id() ) {
            return null;
        }
        if ( ! class_exists( '\\TejCart\\B2B\\Member_Repository' ) ) {
            return null;
        }

        $member = \TejCart\B2B\Member_Repository::find_by_user( get_current_user_id() );
        if ( ! $member || ! $member->is_active() ) {
            return null;
        }

        return \TejCart\B2B\Company_Repository::find( $member->get_company_id() );
    }
}
