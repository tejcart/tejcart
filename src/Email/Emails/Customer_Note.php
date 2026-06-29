<?php
/**
 * Customer Note Email.
 *
 * Sent to the customer when the merchant adds a customer-visible note
 * to their order (admin order screen or REST API).
 *
 * Audit reference: #1344 / 06 F-H4. Previously the admin's
 * "customer-visible" note showed up in the admin UI only; the buyer
 * never saw it unless they logged in and checked the order page.
 *
 * @package TejCart\Email\Emails
 */

declare( strict_types=1 );

namespace TejCart\Email\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Abstract_Email;

class Customer_Note extends Abstract_Email {
    /**
     * Body of the note attached to the order.
     *
     * @var string
     */
    protected $note_body = '';

    public function __construct() {
        $this->id            = 'customer_note';
        $this->title         = 'Customer Note';
        $this->description   = 'Sent to the customer when the merchant adds a customer-visible note to their order.';
        $this->subject       = 'Note added to your {site_title} order #{order_number}';
        $this->heading       = 'A note has been added to your order';
        $this->template_html = 'emails/customer-note.php';

        parent::__construct();
    }

    /**
     * Trigger the email.
     *
     * Accepts two shapes, matching the two hooks that produce
     * customer-visible notes today:
     *   - `tejcart_admin_customer_note_added`: ( $order_id, $note_body )
     *   - `tejcart_new_customer_note`:         ( $order_id, $note_body, $order? )
     *
     * @param int                       $order_id Order ID.
     * @param string|array<string,mixed> $note     Note body (string) or compound payload.
     * @param mixed                      $order    Optional pre-resolved order object.
     */
    public function trigger( $order_id, $note = '', $order = null ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        // Normalise variants: the REST controller passes an array,
        // the admin path passes a string.
        if ( is_array( $note ) ) {
            $note_body = isset( $note['note'] ) ? (string) $note['note'] : '';
        } else {
            $note_body = (string) $note;
        }
        $note_body = trim( $note_body );
        if ( '' === $note_body ) {
            return;
        }

        if ( ! is_object( $order ) && function_exists( 'tejcart_get_order' ) ) {
            $order = tejcart_get_order( $order_id );
        }
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_email' ) ) {
            return;
        }

        $this->object    = $order;
        $this->note_body = $note_body;
        $this->recipient = (string) $order->get_customer_email();

        if ( '' === $this->recipient ) {
            return;
        }

        $this->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function get_template_args() {
        return array(
            'order'         => $this->object,
            'note_body'     => $this->note_body,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
