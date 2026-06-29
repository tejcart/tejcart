<?php
/**
 * Back in Stock Email.
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
 * Sends a back-in-stock notification email to a subscriber.
 *
 * Previously fired by `Stock_Notifications::send_notification()` via a
 * direct `wp_mail()` call (06 F-M2) with a plain-text body, no
 * marker header, and no per-message Content-Type. Routing through this
 * class gives admins the same template-system, log, and per-message
 * envelope handling available for every other transactional email.
 *
 * The `tejcart_stock_notification_email` filter is preserved by the
 * caller (`Stock_Notifications::send_notification()`) so existing
 * integrations continue to override the payload.
 */
class Back_In_Stock_Email extends Abstract_Email {
    /**
     * Product ID this notification is about.
     *
     * @var int
     */
    protected $product_id = 0;

    /**
     * Resolved product instance (cached across get_subject / template).
     *
     * @var mixed
     */
    protected $product = null;

    /**
     * Pre-built absolute unsubscribe URL for the current recipient.
     *
     * @var string
     */
    protected $unsubscribe_url = '';

    /**
     * Constructor. Set defaults for the back-in-stock email.
     */
    public function __construct() {
        $this->id            = 'back_in_stock';
        $this->title         = 'Back in Stock Notification';
        $this->description   = 'Sent to customers who subscribed to "notify me when available" for a product that just came back in stock.';
        $this->subject       = 'Back in stock: {product_name}';
        $this->heading       = 'Back in Stock';
        $this->template_html = 'emails/back-in-stock.php';
        $this->recipient     = '';

        parent::__construct();
    }

    /**
     * Trigger the email for a given product / subscriber.
     *
     * @param int    $product_id       Product post ID that is back in stock.
     * @param string $recipient_email  Subscriber email address.
     * @param string $unsubscribe_url  Absolute unsubscribe URL for this row.
     * @return bool
     */
    public function trigger( $product_id, $recipient_email = '', $unsubscribe_url = '' ) {
        $this->product_id      = absint( $product_id );
        $this->recipient       = sanitize_email( (string) $recipient_email );
        $this->unsubscribe_url = esc_url_raw( (string) $unsubscribe_url );

        if ( ! $this->product_id || '' === $this->recipient ) {
            return false;
        }

        $this->product = function_exists( 'tejcart_get_product' )
            ? tejcart_get_product( $this->product_id )
            : null;

        if ( ! is_object( $this->product ) ) {
            return false;
        }

        return (bool) $this->send();
    }

    /**
     * Get the email subject with product-specific placeholder replacement.
     *
     * @return string
     */
    public function get_subject() {
        $name = is_object( $this->product ) && method_exists( $this->product, 'get_name' )
            ? (string) $this->product->get_name()
            : '';

        $subject = str_replace( '{product_name}', sanitize_text_field( $name ), (string) $this->subject );
        $subject = str_replace( '{site_title}', get_bloginfo( 'name' ), $subject );

        return apply_filters( 'tejcart_email_subject', $subject, $this );
    }

    /**
     * Return template arguments for the back-in-stock template.
     *
     * @return array<string, mixed>
     */
    public function get_template_args() {
        $product_name = '';
        $permalink    = '';
        if ( is_object( $this->product ) ) {
            if ( method_exists( $this->product, 'get_name' ) ) {
                $product_name = (string) $this->product->get_name();
            }
            if ( method_exists( $this->product, 'get_permalink' ) ) {
                $permalink = (string) $this->product->get_permalink();
            }
        }

        return array(
            'product_id'      => $this->product_id,
            'product_name'    => $product_name,
            'product_url'     => $permalink,
            'unsubscribe_url' => $this->unsubscribe_url,
            'email_heading'   => $this->get_heading(),
            'email'           => $this,
        );
    }
}
