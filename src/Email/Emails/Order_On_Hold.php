<?php
/**
 * Order On-Hold Email.
 *
 * @package TejCart\Email\Emails
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace TejCart\Email\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Abstract_Email;

/**
 * Sent to the customer when their order is placed on hold.
 */
class Order_On_Hold extends Abstract_Email {
    public function __construct() {
        $this->id            = 'order_on_hold';
        $this->title         = 'Order On Hold';
        $this->description   = 'Sent to the customer when their order status changes to on-hold (payment review, dispute, manual check).';
        $this->subject       = 'Your {site_title} order #{order_number} is on hold';
        $this->heading       = 'Your order is on hold';
        $this->template_html = 'emails/order-on-hold.php';

        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        if ( ! $order ) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_customer_email();

        $this->send();
    }

    public function get_template_args() {
        return array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
