<?php
/**
 * Admin email triggered when a new dispute opens.
 *
 * Disputes are time-sensitive — Stripe gives merchants ~7 days to upload
 * evidence; PayPal varies by case type but the SLA is short. Emailing
 * the moment a dispute appears ensures the response window isn't lost
 * to "we forgot to check the dashboard" delays.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Email_Notification {
    /**
     * Default colour values for inline email styles.
     *
     * Email clients (Gmail, Outlook) strip <style> blocks, so inline styles
     * are mandatory. The defaults here match core's
     * `templates/emails/parts/styles.php` (`$nx_*` variables). Runtime
     * resolution goes through {@see self::email_text_color()} and
     * {@see self::email_muted_color()} which expose filters for themes
     * and customisers that override the email palette.
     */
    private const EMAIL_TEXT_COLOR_DEFAULT  = '#2b2f33';
    private const EMAIL_MUTED_COLOR_DEFAULT = '#374151';

    /**
     * Send the admin alert. Returns the wp_mail() return value so callers
     * can log delivery failures.
     */
    public function send_admin_alert( Dispute $dispute ): bool {
        $recipients = $this->recipients( $dispute );
        if ( empty( $recipients ) ) {
            return false;
        }

        $subject = sprintf(
            /* translators: 1: gateway label, 2: order id (or 0), 3: site name */
            __( '[%3$s] %1$s dispute opened on order #%2$d', 'tejcart' ),
            $this->gateway_label( $dispute->gateway ),
            (int) $dispute->order_id,
            (string) get_bloginfo( 'name' )
        );

        $body    = $this->render( $dispute, false );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return (bool) wp_mail( $recipients, $subject, $body, $headers );
    }

    /**
     * Send the evidence-due-soon reminder. Hooked to
     * {@see Evidence_Reminder::HOOK} so the same threshold the cron
     * computes drives the email subject line.
     *
     * @param Dispute $dispute
     * @param int     $days_out How many days remain until the deadline.
     */
    public function send_evidence_reminder( Dispute $dispute, int $days_out ): bool {
        if ( $dispute->is_terminal() ) {
            return false;
        }

        $recipients = $this->recipients( $dispute );
        if ( empty( $recipients ) ) {
            return false;
        }

        $subject = sprintf(
            /* translators: 1: gateway label, 2: days remaining, 3: order id, 4: site name */
            _n(
                '[%4$s] %1$s dispute evidence due in %2$d day (order #%3$d)',
                '[%4$s] %1$s dispute evidence due in %2$d days (order #%3$d)',
                max( 1, $days_out ),
                'tejcart'
            ),
            $this->gateway_label( $dispute->gateway ),
            (int) $days_out,
            (int) $dispute->order_id,
            (string) get_bloginfo( 'name' )
        );

        $body    = $this->render( $dispute, true, $days_out );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return (bool) wp_mail( $recipients, $subject, $body, $headers );
    }

    /**
     * @return string[]
     */
    private function recipients( Dispute $dispute ): array {
        $recipient = $this->recipient();
        if ( '' === $recipient ) {
            return array();
        }
        /**
         * Filter the admin recipient list for dispute notifications.
         *
         * @param string[] $recipients
         * @param Dispute  $dispute
         */
        $recipients = apply_filters( 'tejcart_dispute_notification_recipients', array( $recipient ), $dispute );
        return array_values( array_filter( array_map( 'strval', (array) $recipients ) ) );
    }

    private function recipient(): string {
        $configured = (string) get_option( 'tejcart_admin_email', '' );
        if ( '' !== $configured ) {
            return $configured;
        }
        return (string) get_option( 'admin_email', '' );
    }

    private function gateway_label( string $gateway ): string {
        switch ( $gateway ) {
            case 'stripe':
                return 'Stripe';
            case 'paypal':
                return 'PayPal';
            default:
                return ucfirst( $gateway );
        }
    }

    private function render( Dispute $dispute, bool $is_reminder = false, int $days_out = 0 ): string {
        $rows = array();

        $rows[] = $this->row( __( 'Gateway', 'tejcart' ), $this->gateway_label( $dispute->gateway ) );
        $rows[] = $this->row( __( 'Dispute ID', 'tejcart' ), $dispute->external_id );
        if ( $dispute->order_id ) {
            $rows[] = $this->row( __( 'Order', 'tejcart' ), '#' . (int) $dispute->order_id );
        }
        $rows[] = $this->row(
            __( 'Amount', 'tejcart' ),
            sprintf( '%.2f %s', $dispute->amount, $dispute->currency )
        );
        if ( '' !== $dispute->reason ) {
            $rows[] = $this->row( __( 'Reason', 'tejcart' ), $dispute->reason );
        }
        if ( $dispute->evidence_due ) {
            $rows[] = $this->row(
                __( 'Evidence due by', 'tejcart' ),
                $dispute->evidence_due . ' UTC'
            );
        }

        $admin_url = admin_url( 'admin.php?page=tejcart-disputes&dispute=' . (int) $dispute->id );

        $cta = function_exists( 'tejcart_email_button' )
            ? tejcart_email_button( $admin_url, __( 'Open dispute in admin', 'tejcart' ) )
            : sprintf(
                '<p><a href="%1$s" style="display:inline-block;background:%3$s;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px;">%2$s</a></p>',
                esc_url( $admin_url ),
                esc_html__( 'Open dispute in admin', 'tejcart' ),
                esc_attr( function_exists( 'tejcart_email_brand_color' ) ? tejcart_email_brand_color() : '#0073aa' )
            );

        if ( $is_reminder ) {
            $intro = sprintf(
                /* translators: 1: gateway label, 2: days remaining */
                _n(
                    'The evidence deadline for this %1$s dispute is %2$d day away. Submit your response now so it isn\'t lost to the response window closing.',
                    'The evidence deadline for this %1$s dispute is %2$d days away. Submit your response now so it isn\'t lost to the response window closing.',
                    max( 1, $days_out ),
                    'tejcart'
                ),
                $this->gateway_label( $dispute->gateway ),
                (int) $days_out
            );
        } else {
            $intro = sprintf(
                /* translators: %s gateway label */
                __( 'A new %s dispute has been opened against your store. Disputes have short response windows — please review and respond before the evidence deadline.', 'tejcart' ),
                $this->gateway_label( $dispute->gateway )
            );
        }

        return '<p>' . esc_html( $intro ) . '</p>'
            . '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . implode( '', $rows )
            . '</table>'
            . $cta;
    }

    private function row( string $label, string $value ): string {
        return sprintf(
            '<tr><th align="left" style="padding:6px 12px 6px 0;color:%3$s;">%1$s</th><td style="padding:6px 0;color:%4$s;">%2$s</td></tr>',
            esc_html( $label ),
            esc_html( $value ),
            esc_attr( self::email_muted_color() ),
            esc_attr( self::email_text_color() )
        );
    }

    /**
     * Resolve the primary text colour for dispute email inline styles.
     *
     * Matches `$nx_p_style` / `$nx_td_style` in
     * `templates/emails/parts/styles.php`. Filterable so themes or
     * customisers that override the email palette carry through to
     * dispute notifications without a code change.
     */
    private static function email_text_color(): string {
        /**
         * Filter the body-text colour used in dispute notification emails.
         *
         * @param string $color Hex colour string (e.g. '#2b2f33').
         */
        return (string) apply_filters( 'tejcart_dispute_email_text_color', self::EMAIL_TEXT_COLOR_DEFAULT );
    }

    /**
     * Resolve the muted / label colour for dispute email inline styles.
     *
     * Matches the `$nx_muted` variable in
     * `templates/emails/parts/styles.php`. Filterable for the same
     * reason as {@see self::email_text_color()}.
     */
    private static function email_muted_color(): string {
        /**
         * Filter the muted / label colour used in dispute notification emails.
         *
         * @param string $color Hex colour string (e.g. '#374151').
         */
        return (string) apply_filters( 'tejcart_dispute_email_muted_color', self::EMAIL_MUTED_COLOR_DEFAULT );
    }
}
