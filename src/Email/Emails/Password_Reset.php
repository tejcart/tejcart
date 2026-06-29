<?php
/**
 * Password Reset Email (branded).
 *
 * Supplements the WordPress default password-reset email by injecting a
 * branded HTML body via the retrieve_password_message filter.
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
 * Provides a branded HTML version of the WP password-reset email.
 */
class Password_Reset extends Abstract_Email {
    /**
     * Context captured from the retrieve_password_message filter, used by
     * the template to render the reset link.
     *
     * @var array{user:?\WP_User,key:string,login:string,url:string}
     */
    protected $object = array(
        'user'  => null,
        'key'   => '',
        'login' => '',
        'url'   => '',
    );

    public function __construct() {
        $this->id            = 'password_reset';
        $this->title         = 'Password Reset';
        $this->description   = 'Branded HTML wrapper for the WordPress password-reset email.';
        $this->subject       = 'Reset your {site_title} password';
        $this->heading       = 'Reset your password';
        $this->template_html = 'emails/password-reset.php';

        parent::__construct();
    }

    /**
     * No direct trigger: the email body is injected via
     * retrieve_password_message(). The object is hydrated there before
     * get_content_html() is called.
     *
     * @param array $context user/key/login/url bundle.
     */
    public function trigger( $context = array() ) {
        if ( is_array( $context ) ) {
            $this->object = array_merge( $this->object, $context );
        }

        // Record the recipient from the hydrated context. The production
        // flow (Email_Manager::brand_password_reset_message) never calls
        // send() on this instance — it returns get_full_html() to core's
        // retrieve_password_message filter — so this is inert there. It
        // exists so the admin "Send test" path can call send() directly
        // and have a non-empty recipient to pass send()'s guard.
        $user = is_array( $this->object ) && isset( $this->object['user'] ) ? $this->object['user'] : null;
        if ( $user instanceof \WP_User && '' !== (string) $user->user_email ) {
            $this->recipient = (string) $user->user_email;
        }
    }

    public function get_template_args() {
        $ctx = is_array( $this->object ) ? $this->object : array();
        return array(
            'user'          => $ctx['user']  ?? null,
            'reset_url'     => $ctx['url']   ?? '',
            'user_login'    => $ctx['login'] ?? '',
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
