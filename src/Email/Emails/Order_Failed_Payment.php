<?php
/**
 * Order Failed Payment Email.
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
 * Sent to the customer when their order payment fails.
 */
class Order_Failed_Payment extends Abstract_Email {
    public function __construct() {
        $this->id            = 'order_failed_payment';
        $this->title         = 'Order Failed Payment';
        $this->description   = 'Sent to the customer when their payment fails and the order is marked failed.';
        $this->subject       = 'Payment failed for your {site_title} order #{order_number}';
        $this->heading       = 'We could not process your payment';
        $this->template_html = 'emails/order-failed-payment.php';

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
