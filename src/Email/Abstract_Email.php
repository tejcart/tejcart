<?php
/**
 * Abstract Email.
 *
 * @package TejCart\Email
 */

declare( strict_types=1 );

namespace TejCart\Email;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base class for all TejCart emails.
 */
abstract class Abstract_Email {
    /**
     * Email unique identifier.
     *
     * @var string
     */
    protected $id;

    /**
     * Email title (for admin display).
     *
     * @var string
     */
    protected $title;

    /**
     * Email description.
     *
     * @var string
     */
    protected $description;

    /**
     * Email subject line.
     *
     * @var string
     */
    protected $subject;

    /**
     * Email heading.
     *
     * @var string
     */
    protected $heading;

    /**
     * Email preheader (preview text shown after the subject line in
     * mailbox lists). Subclasses set a sensible default; settings can
     * override.
     *
     * @var string
     */
    protected $preheader = '';

    /**
     * Path to the HTML template file.
     *
     * @var string
     */
    protected $template_html;

    /**
     * Recipient email address.
     *
     * @var string
     */
    protected $recipient;

    /**
     * Whether this email is enabled.
     *
     * @var bool
     */
    protected $enabled;

    /**
     * The object this email is about (e.g. order, product).
     *
     * @var mixed
     */
    protected $object;

    /**
     * Constructor. Loads saved settings for this email.
     */
    public function __construct() {
        $settings = get_option( 'tejcart_email_' . $this->id, array() );

        if ( ! empty( $settings['subject'] ) ) {
            $this->subject = $settings['subject'];
        }

        if ( ! empty( $settings['heading'] ) ) {
            $this->heading = $settings['heading'];
        }

        if ( isset( $settings['preheader'] ) && '' !== $settings['preheader'] ) {
            $this->preheader = (string) $settings['preheader'];
        }

        if ( ! empty( $settings['recipient'] ) ) {
            $this->recipient = $settings['recipient'];
        }

        if ( isset( $settings['enabled'] ) ) {
            $this->enabled = (bool) $settings['enabled'];
        } else {
            $this->enabled = true;
        }
    }

    /**
     * Get the email subject with placeholder replacement.
     *
     * @return string
     */
    public function get_subject() {
        $subject = $this->replace_placeholders( $this->subject );

        // Audit M-20: strip CR/LF to prevent email header injection
        // if a placeholder ever resolves to attacker-controlled content
        // containing newlines (e.g. a product name with "\r\nBcc: ...").
        $subject = str_replace( array( "\r", "\n" ), '', $subject );

        return apply_filters( 'tejcart_email_subject', $subject, $this );
    }

    /**
     * Get the email heading with placeholder replacement.
     *
     * @return string
     */
    public function get_heading() {
        $heading = $this->replace_placeholders( $this->heading );

        /**
         * Filter the heading for a TejCart email.
         *
         * Lets the Tier-2 Template_System (and other modules) override
         * the heading shown above the email body with an admin-editable
         * string keyed on the email id.
         *
         * @param string         $heading Heading after placeholder substitution.
         * @param Abstract_Email $email   Email instance.
         */
        return (string) apply_filters( 'tejcart_email_heading', $heading, $this );
    }

    /**
     * Get the email preheader (preview text) with placeholder
     * replacement. Returns the raw string; the header template is
     * responsible for hiding it visually.
     *
     * @return string
     */
    public function get_preheader() {
        $preheader = $this->replace_placeholders( $this->preheader );

        /**
         * Filter the preheader (preview text) for a TejCart email.
         *
         * @param string         $preheader Preheader after placeholder substitution.
         * @param Abstract_Email $email     Email instance.
         */
        return (string) apply_filters( 'tejcart_email_preheader', $preheader, $this );
    }

    /**
     * Get the recipient email address.
     *
     * @return string
     */
    public function get_recipient() {
        return $this->recipient;
    }

    /**
     * Get the HTML content for the email body.
     *
     * @return string
     */
    public function get_content_html() {
        ob_start();
        tejcart_get_template(
            $this->template_html,
            $this->get_template_args()
        );
        $html = (string) ob_get_clean();

        /**
         * Filter the rendered HTML body of a TejCart email.
         *
         * Lets the Tier-2 Template_System (and other modules) inject or
         * fully override the rendered body with admin-editable content
         * keyed on the email id. Return the original $html to leave the
         * template-rendered body untouched.
         *
         * @param string         $html  Rendered HTML body.
         * @param Abstract_Email $email Email instance.
         */
        return (string) apply_filters( 'tejcart_email_content_html', $html, $this );
    }

    /**
     * Get the complete, ready-to-send HTML message: the document header
     * + rendered body + footer, with any filtered `<style>` block
     * prepended.
     *
     * Both Abstract_Email::send() and out-of-band senders (e.g. the
     * Email_Manager filter that injects the branded password-reset body
     * into core's retrieve_password_message) must use this so the
     * delivered email is always wrapped in the full document scaffolding
     * — header bar, heading, footer, and the mobile/dark-mode `<style>`
     * block — rather than a bare body fragment.
     *
     * @return string
     */
    public function get_full_html() {
        $html_content = $this->get_email_header() . $this->get_content_html() . $this->get_email_footer();

        $styles = apply_filters( 'tejcart_email_styles', '', $this );
        if ( ! empty( $styles ) ) {
            $html_content = '<style>' . $styles . '</style>' . $html_content;
        }

        return $html_content;
    }

    /**
     * Get the plain text content for the email body.
     *
     * Strips HTML tags from the HTML content and formats it
     * for plain text email clients.
     *
     * @return string
     */
    public function get_content_plain() {
        $html = $this->get_content_html();

        return $this->get_plain_text( $html );
    }

    /**
     * Convert HTML to readable plain text.
     *
     * Converts links to "text (URL)" format, replaces table rows
     * with delimited text, strips remaining tags, and normalises
     * whitespace so the result is comfortable to read in a plain
     * text email client.
     *
     * @param string $html The HTML string to convert.
     * @return string The plain text version.
     */
    public function get_plain_text( $html ) {
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $html );

        $text = preg_replace_callback(
            '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is',
            function ( $matches ) {
                return "\n\n" . strtoupper( wp_strip_all_tags( $matches[1] ) ) . "\n" . str_repeat( '-', 40 ) . "\n";
            },
            $text
        );

        $text = preg_replace_callback(
            '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ( $matches ) {
                $url  = $matches[1];
                $link_text = wp_strip_all_tags( $matches[2] );
                if ( $link_text === $url ) {
                    return $url;
                }
                return $link_text . ' (' . $url . ')';
            },
            $text
        );

        $text = preg_replace_callback(
            '/<tr[^>]*>(.*?)<\/tr>/is',
            function ( $matches ) {
                $cells = array();
                preg_match_all( '/<(?:td|th)[^>]*>(.*?)<\/(?:td|th)>/is', $matches[1], $cell_matches );
                if ( ! empty( $cell_matches[1] ) ) {
                    foreach ( $cell_matches[1] as $cell ) {
                        $cells[] = trim( wp_strip_all_tags( $cell ) );
                    }
                }
                return implode( ' | ', $cells ) . "\n";
            },
            $text
        );

        $text = preg_replace( '/<\/p>/i', "\n\n", $text );
        $text = preg_replace( '/<p[^>]*>/i', '', $text );

        $text = preg_replace( '/<li[^>]*>/i', '- ', $text );
        $text = preg_replace( '/<\/li>/i', "\n", $text );

        $text = wp_strip_all_tags( $text );

        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        $lines = explode( "\n", $text );
        $lines = array_map( 'rtrim', $lines );
        $text  = implode( "\n", $lines );

        return trim( $text );
    }

    /**
     * Send the email.
     *
     * Builds a multipart/alternative MIME message containing both
     * a plain text part and an HTML part so that email clients can
     * choose the best version to display.
     *
     * @return bool Whether the email was sent successfully.
     */
    public function send() {
        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }

        $subject      = $this->get_subject();
        $html_content = $this->get_full_html();

        $plain_content = $this->get_content_plain();

        $marker_id = '';
        if ( property_exists( $this, 'id' ) ) {
            $ref_id    = $this->id;
            $marker_id = is_string( $ref_id ) ? $ref_id : '';
        }

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'X-TejCart-Mail: ' . ( '' !== $marker_id ? $marker_id : '1' ),
        );

        // Resend context: emit email-id + the bound object id/type so the
        // Tier-2 log can resolve the row back to a resendable order
        // (see Template_System::log_outgoing()). Earlier versions wrote
        // null object_type/id into the log row, which permanently broke
        // the "Resend" admin action.
        if ( '' !== $marker_id ) {
            $headers[] = 'X-TejCart-Email-Id: ' . $marker_id;
        }
        if ( property_exists( $this, 'object' ) && $this->object instanceof \TejCart\Order\Order ) {
            $object_id = (int) $this->object->get_id();
            if ( $object_id > 0 ) {
                $headers[] = 'X-TejCart-Object-Type: order';
                $headers[] = 'X-TejCart-Object-Id: ' . $object_id;
            }
        }

        // Attach the plain-text alternative via PHPMailer so it builds the
        // multipart/alternative envelope itself. Hand-rolling the MIME body
        // and passing it to wp_mail() does not work: PHPMailer treats the
        // body as a single part, so the boundary lines leak into the
        // delivered message.
        $alt_body_setter = static function ( $phpmailer ) use ( $plain_content ) {
            if ( is_object( $phpmailer ) ) {
                $phpmailer->AltBody = $plain_content;
            }
        };

        add_action( 'phpmailer_init', $alt_body_setter );

        try {
            $sent = wp_mail( $this->get_recipient(), $subject, $html_content, $headers );
        } finally {
            remove_action( 'phpmailer_init', $alt_body_setter );
        }

        if ( $sent ) {
            $email_id = property_exists( $this, 'id' ) && is_string( $this->id ) ? $this->id : '';
            $order    = property_exists( $this, 'order' ) && $this->order instanceof \TejCart\Order\Order
                ? $this->order
                : null;

            /**
             * Fires after a TejCart email is dispatched via wp_mail().
             *
             * Order-scoped subscribers (Order_Activity_Logger) write a
             * timeline note when $order is non-null. Non-order emails
             * (low-stock alerts, password resets) still fire the action
             * with $order = null so generic listeners can react.
             *
             * @param string                    $email_id  Email subclass identifier (e.g. "buyer_receipt").
             * @param string                    $recipient Recipient address.
             * @param string                    $subject   Subject line.
             * @param \TejCart\Order\Order|null $order     Order context if the email is order-scoped.
             */
            do_action( 'tejcart_email_sent', $email_id, $this->get_recipient(), $subject, $order );
        }

        return $sent;
    }

    /**
     * Check whether this email is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) $this->enabled;
    }

    /**
     * Return template arguments. Override in subclasses.
     *
     * @return array
     */
    public function get_template_args() {
        return array();
    }

    /**
     * Get the email header markup.
     *
     * @return string
     */
    private function get_email_header() {
        ob_start();
        tejcart_get_template(
            'emails/email-header.php',
            array(
                'email_heading' => $this->get_heading(),
                'preheader'     => $this->get_preheader(),
            )
        );
        return ob_get_clean();
    }

    /**
     * Get the email footer markup.
     *
     * @return string
     */
    private function get_email_footer() {
        ob_start();
        tejcart_get_template( 'emails/email-footer.php' );
        return ob_get_clean();
    }

    /**
     * Replace placeholders in a string.
     *
     * @param string $string The string containing placeholders.
     * @return string
     */
    protected function replace_placeholders( $string ) {
        $order_number = '';

        if ( $this->object && is_callable( array( $this->object, 'get_order_number' ) ) ) {
            $order_number = $this->object->get_order_number();
        }

        $replacements = array(
            '{order_number}' => $order_number,
            '{site_title}'   => get_bloginfo( 'name' ),
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $string );
    }
}
