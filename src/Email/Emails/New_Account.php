<?php
/**
 * New Account Welcome Email.
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
 * Sent to a customer when their account is created.
 */
class New_Account extends Abstract_Email {
    public function __construct() {
        $this->id            = 'new_account';
        $this->title         = 'New Account';
        $this->description   = 'Sent to a customer when they register a TejCart shop account.';
        $this->subject       = 'Welcome to {site_title}';
        $this->heading       = 'Your new account';
        $this->template_html = 'emails/new-account.php';

        parent::__construct();
    }

    /**
     * @param int          $user_id The new user's ID.
     * @param \WP_User|null $user   Optional pre-resolved user object.
     */
    public function trigger( $user_id, $user = null ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        if ( ! $user ) {
            $user = get_user_by( 'id', $user_id );
        }
        if ( ! $user ) {
            return;
        }

        $this->object    = $user;
        $this->recipient = (string) $user->user_email;

        $this->send();
    }

    public function get_template_args() {
        return array(
            'user'          => $this->object,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
