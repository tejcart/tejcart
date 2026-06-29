<?php
/**
 * Check payments offline gateway.
 *
 * @package TejCart\Gateways\Offline
 */

declare( strict_types=1 );

namespace TejCart\Gateways\Offline;

use TejCart\Gateways\Abstract_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lets the customer pay by mailing a personal / company check.
 *
 * The order is placed on-hold until the shop manager receives and
 * clears the check.
 */
class Check_Gateway extends Abstract_Gateway {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'cheque';
        $this->title       = __( 'Check payments', 'tejcart' );
        $this->description = __( 'Please send a check to Store Name, Store Street, Store Town, Store State / County, Store Postcode.', 'tejcart' );
        $this->supports    = array( 'products' );

        parent::__construct();

        add_action( 'tejcart_thankyou_' . $this->id, array( $this, 'render_instructions' ), 10, 1 );
    }

    /**
     * Define admin settings fields.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'      => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable check payments as a payment method.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'        => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Check payments', 'tejcart' ),
            ),
            'description'  => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Please send a check to Store Name, Store Street, Store Town, Store State / County, Store Postcode.', 'tejcart' ),
            ),
            'instructions' => array(
                'type'        => 'textarea',
                'title'       => __( 'Instructions', 'tejcart' ),
                'description' => __( 'Instructions that will be added to the thank-you page and order confirmation email.', 'tejcart' ),
                'default'     => '',
            ),
        );
    }

    /**
     * Process payment - mark the order on-hold pending receipt of the check.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        $order = new \TejCart\Order\Order( $order_id );

        if ( ! $order->get_id() ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'tejcart' ),
            );
        }

        // Order_Cart_Cleanup listens on tejcart_order_status_on-hold
        // and empties the cart centrally. Inline empty_cart() removed.
        $order->update_status( 'on-hold', __( 'Awaiting check payment.', 'tejcart' ) );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Render payment instructions on the thank-you page.
     *
     * @param mixed $order Order instance or ID.
     */
    public function render_instructions( $order = null ): void {
        $instructions = trim( (string) $this->get_option( 'instructions', '' ) );

        if ( '' === $instructions ) {
            return;
        }

        echo '<section class="tejcart-check-instructions">';
        echo wp_kses_post( wpautop( wptexturize( $instructions ) ) );
        echo '</section>';
    }

    /**
     * Build the thank-you URL for an order.
     *
     * @param \TejCart\Order\Order $order Order instance.
     * @return string
     */
    private function get_return_url( $order ): string {
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
}
