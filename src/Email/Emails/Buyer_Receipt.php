<?php
/**
 * Buyer Receipt Email.
 *
 * @package TejCart\Email\Emails
 */

declare( strict_types=1 );

namespace TejCart\Email\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Abstract_Email;

/**
 * Sends an order confirmation email to the buyer.
 */
class Buyer_Receipt extends Abstract_Email {
    /**
     * Constructor. Set defaults for the buyer receipt email.
     */
    public function __construct() {
        $this->id            = 'buyer_receipt';
        $this->title         = 'Order Confirmation';
        $this->description   = 'Sent to the customer after an order is placed.';
        $this->subject       = 'Your {site_title} order #{order_number} has been received';
        $this->heading       = 'Thank you for your order';
        $this->template_html = 'emails/buyer-receipt.php';

        parent::__construct();
    }

    /**
     * Trigger the email.
     *
     * Wired to both `tejcart_order_status_processing` (PayPal et al.) and
     * `tejcart_order_status_on-hold` (offline gateways like COD / BACS /
     * Cheque) so the customer always receives an itemized confirmation
     * regardless of which status the gateway lands the order in. Guarded
     * by an order-meta flag so an order that goes on-hold first and then
     * later moves to processing only sends the receipt once.
     *
     * @param int   $order_id The order ID.
     * @param mixed $order    The order object.
     * @return void
     */
    public function trigger( $order_id, $order = null ) {
        if ( ! $order ) {
            return;
        }

        if ( method_exists( $order, 'get_meta' ) && method_exists( $order, 'update_meta' ) ) {
            $already_sent = (string) $order->get_meta( '_tejcart_buyer_receipt_sent' );
            if ( '1' === $already_sent ) {
                return;
            }
            $order->update_meta( '_tejcart_buyer_receipt_sent', '1' );
        }

        $this->object    = $order;
        $this->recipient = $order->get_customer_email();

        $this->send();
    }

    /**
     * Return template arguments for the buyer receipt template.
     *
     * @return array
     */
    public function get_template_args() {
        return array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
