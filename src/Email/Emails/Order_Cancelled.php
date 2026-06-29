<?php
/**
 * Order Cancelled Email.
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
 * Sends an order cancelled notification email to the customer.
 */
class Order_Cancelled extends Abstract_Email {
    /**
     * Constructor. Set defaults for the order cancelled email.
     */
    public function __construct() {
        $this->id            = 'order_cancelled';
        $this->title         = 'Order Cancelled';
        $this->description   = 'Sent to the customer when their order status changes to cancelled.';
        $this->subject       = 'Your {site_title} order #{order_number} has been cancelled';
        $this->heading       = 'Your order has been cancelled';
        $this->template_html = 'emails/order-cancelled.php';

        parent::__construct();
    }

    /**
     * Trigger the email.
     *
     * @param int   $order_id The order ID.
     * @param mixed $order    The order object.
     * @return void
     */
    public function trigger( $order_id, $order = null ) {
        if ( ! $order ) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_customer_email();

        $this->send();
    }

    /**
     * Return template arguments for the order cancelled template.
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
