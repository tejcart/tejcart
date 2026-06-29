<?php
/**
 * Admin Notification Email.
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
 * Sends a new-order notification email to the site administrator.
 */
class Admin_Notification extends Abstract_Email {
    /**
     * Constructor. Set defaults for the admin notification email.
     */
    public function __construct() {
        $this->id            = 'admin_notification';
        $this->title         = 'New Order (Admin)';
        $this->description   = 'Sent to the site admin when a new order is placed.';
        $this->subject       = '[{site_title}] New order #{order_number}';
        $this->heading       = 'New order received';
        $this->template_html = 'emails/admin-notification.php';
        $this->recipient     = get_option( 'admin_email' );

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

        $this->object = $order;

        $this->send();
    }

    /**
     * Return template arguments for the admin notification template.
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
