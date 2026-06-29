<?php
/**
 * Account Email Changed notification.
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
 * Security notice sent to the PREVIOUS email address when a customer's
 * account email is changed, so a session-takeover that flips the address
 * cannot do so silently.
 *
 * Replaces a legacy plain-text `wp_mail()` call in Customers_Controller —
 * routing through Abstract_Email gives the notice the same designed HTML
 * template, marker header (email log), and per-message Content-Type lock
 * as every other transactional email.
 */
class Account_Email_Changed extends Abstract_Email {
    /**
     * The address the account was changed FROM (and the recipient).
     *
     * @var string
     */
    protected $previous_email = '';

    /**
     * The address the account was changed TO.
     *
     * @var string
     */
    protected $new_email = '';

    /**
     * Constructor. Set defaults for the email-changed notice.
     */
    public function __construct() {
        $this->id            = 'account_email_changed';
        $this->title         = 'Account Email Changed';
        $this->description   = 'Security notice sent to the previous address when a customer changes their account email.';
        $this->subject       = '[{site_title}] Your account email was changed';
        $this->heading       = 'Your account email was changed';
        $this->template_html = 'emails/account-email-changed.php';

        parent::__construct();
    }

    /**
     * Trigger the notice.
     *
     * @param string $previous_email Address the account was changed from.
     * @param string $new_email      Address the account was changed to.
     * @return void
     */
    public function trigger( $previous_email, $new_email = '' ) {
        $previous_email = sanitize_email( (string) $previous_email );
        if ( '' === $previous_email || ! is_email( $previous_email ) ) {
            return;
        }

        $this->previous_email = $previous_email;
        $this->new_email      = sanitize_email( (string) $new_email );
        $this->recipient      = $previous_email;

        $this->send();
    }

    /**
     * Return template arguments for the email-changed template.
     *
     * @return array<string, mixed>
     */
    public function get_template_args() {
        return array(
            'previous_email' => $this->previous_email,
            'new_email'      => $this->new_email,
            'reset_url'      => wp_lostpassword_url(),
            'email_heading'  => $this->get_heading(),
            'email'          => $this,
        );
    }
}
