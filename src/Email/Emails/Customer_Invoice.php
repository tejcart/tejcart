<?php
/**
 * Customer Invoice / Pay-link Email.
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
 * Manual email that re-sends an order's checkout/pay link to the
 * customer. The "Customer invoice / Order details" email — fired on
 * demand from the Orders admin, never automatically.
 *
 * When the order is awaiting payment (pending / on-hold / failed),
 * the email includes a pay-now link pointing at the checkout with
 * the order key preloaded. For paid orders it just reprints the
 * order summary.
 */
class Customer_Invoice extends Abstract_Email {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id            = 'customer_invoice';
        $this->title         = 'Customer invoice / Order details';
        $this->description   = 'Manual email re-sending an order summary, including a pay-now link for unpaid orders.';
        $this->subject       = 'Invoice for your {site_title} order #{order_number}';
        $this->heading       = 'Invoice for order #{order_number}';
        $this->template_html = 'emails/customer-invoice.php';

        parent::__construct();
    }

    /**
     * Trigger the email for a specific order.
     *
     * @param int   $order_id Order ID.
     * @param mixed $order    Hydrated order, when available.
     */
    public function trigger( $order_id, $order = null ) {
        if ( ! $order ) {
            $order = \TejCart\Order\Order_Factory::get_order( (int) $order_id );
        }

        if ( ! $order ) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_customer_email();

        $this->send();
    }

    /**
     * Pay-now URL for unpaid orders; empty string otherwise.
     */
    public function get_pay_url(): string {
        $order = $this->object;
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
            return '';
        }

        $awaiting_payment = in_array(
            (string) $order->get_status(),
            array( 'pending', 'on-hold', 'failed' ),
            true
        );

        if ( ! $awaiting_payment ) {
            return '';
        }

        $checkout_id = (int) get_option( 'tejcart_checkout_page_id', 0 );
        $base        = $checkout_id ? get_permalink( $checkout_id ) : home_url( '/' );

        return add_query_arg(
            array(
                'pay_for_order' => $order->get_id(),
                'order_key'     => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
            ),
            $base
        );
    }

    /**
     * Template args passed to the HTML template.
     */
    public function get_template_args() {
        return array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
            'pay_url'       => $this->get_pay_url(),
        );
    }
}
