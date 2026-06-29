<?php
/**
 * Order Completed Email.
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
 * Sends an order completed notification email to the customer.
 */
class Order_Completed extends Abstract_Email {
    /**
     * Constructor. Set defaults for the order completed email.
     */
    public function __construct() {
        $this->id            = 'order_completed';
        $this->title         = 'Order Completed';
        $this->description   = 'Sent to the customer when their order status changes to completed.';
        $this->subject       = 'Your {site_title} order #{order_number} is complete';
        $this->heading       = 'Your order is complete';
        $this->template_html = 'emails/order-completed.php';

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
     * Return template arguments for the order completed template.
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
