<?php
/**
 * Order Processing Email.
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
 * Sends an order processing notification email to the customer.
 */
class Order_Processing extends Abstract_Email {
    /**
     * Constructor. Set defaults for the order processing email.
     */
    public function __construct() {
        $this->id            = 'order_processing';
        $this->title         = 'Order Processing';
        $this->description   = 'Sent to the customer when their order status changes to processing.';
        $this->subject       = 'Your {site_title} order #{order_number} is being processed';
        $this->heading       = 'Your order is being processed';
        $this->template_html = 'emails/order-processing.php';

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
     * Return template arguments for the order processing template.
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
