<?php
/**
 * Email Manager.
 *
 * @package TejCart\Email
 */

declare( strict_types=1 );

namespace TejCart\Email;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Emails\Buyer_Receipt;
use TejCart\Email\Emails\Customer_Invoice;
use TejCart\Email\Emails\Admin_Notification;
use TejCart\Email\Emails\Order_Processing;
use TejCart\Email\Emails\Order_Completed;
use TejCart\Email\Emails\Order_Cancelled;
use TejCart\Email\Emails\Order_Refunded;
use TejCart\Email\Emails\Order_On_Hold;
use TejCart\Email\Emails\Order_Failed_Payment;
use TejCart\Email\Emails\New_Account;
use TejCart\Email\Emails\Password_Reset;
use TejCart\Email\Emails\Low_Stock_Alert_Email;
use TejCart\Email\Emails\Low_Stock_Digest_Email;
use TejCart\Email\Emails\Account_Email_Changed;
use TejCart\Email\Emails\Out_Of_Stock_Email;
use TejCart\Email\Emails\Back_In_Stock_Email;
use TejCart\Email\Emails\Customer_Note;
use TejCart\Email\Emails\Abandoned_Cart_Email;

/**
 * Manages all transactional emails.
 *
 * Emails are dispatched asynchronously: trigger hooks enqueue a job
 * via wp_schedule_single_event() instead of calling wp_mail() inline.
 * A single cron handler ('tejcart_send_email_async') hydrates the
 * subject object (order/product) and invokes the email's trigger()
 * synchronously in the background, keeping checkout response time
 * unaffected by SMTP latency.
 *
 * Synchronous fallbacks remain available via send() and send_now()
 * for admin "send test email" actions and CLI tooling.
 */
class Email_Manager {
    const ASYNC_HOOK = 'tejcart_send_email_async';

    /**
     * Map of order-status hook → email_id, used by hook_emails().
     */

    private const ORDER_EMAIL_HOOKS = array(
        'tejcart_order_status_processing'          => array( 'buyer_receipt', 'order_processing', 'admin_notification' ),
        'tejcart_order_status_completed'           => array( 'order_completed' ),
        'tejcart_order_status_cancelled'           => array( 'order_cancelled' ),
        'tejcart_order_status_refunded'            => array( 'order_refunded' ),
        // Audit #15 / 06 F-H3 — fire the same template the full-refund
        // path uses when a partial refund transitions the order to
        // `partially-refunded`. Previously the customer received no
        // notification for partial refunds, which was a PCI / dispute
        // risk (buyer had no proof the refund was issued until the
        // statement arrived).
        'tejcart_order_status_partially-refunded'  => array( 'order_refunded' ),
        'tejcart_order_status_on-hold'             => array( 'buyer_receipt', 'order_on_hold', 'admin_notification' ),
        'tejcart_order_status_failed'              => array( 'order_failed_payment' ),
    );

    /**
     * @var Abstract_Email[]
     */
    private $emails = array();

    /**
     * Initialize the email system: load classes, instantiate, and hook.
     */
    public function init() {
        $email_classes = array(
            'buyer_receipt'        => Buyer_Receipt::class,
            'customer_invoice'     => Customer_Invoice::class,
            'admin_notification'   => Admin_Notification::class,
            'order_processing'     => Order_Processing::class,
            'order_completed'      => Order_Completed::class,
            'order_cancelled'      => Order_Cancelled::class,
            'order_refunded'       => Order_Refunded::class,
            'order_on_hold'        => Order_On_Hold::class,
            'order_failed_payment' => Order_Failed_Payment::class,
            'new_account'          => New_Account::class,
            'account_email_changed' => Account_Email_Changed::class,
            'password_reset'       => Password_Reset::class,
            'low_stock_alert'      => Low_Stock_Alert_Email::class,
            'low_stock_digest'     => Low_Stock_Digest_Email::class,
            'out_of_stock'         => Out_Of_Stock_Email::class,
            'back_in_stock'        => Back_In_Stock_Email::class,
            'customer_note'        => Customer_Note::class,
            'abandoned_cart'       => Abandoned_Cart_Email::class,
        );

        $email_classes = apply_filters( 'tejcart_email_classes', $email_classes );

        foreach ( $email_classes as $id => $class ) {
            if ( class_exists( $class ) ) {
                $this->emails[ $id ] = new $class();
            }
        }

        $this->hook_emails();

        // F-L5 / #955: ASYNC_HOOK callbacks may now carry an `attempt`
        // counter (defaults to 1 for back-compat with legacy 3-arg
        // schedulers).
        add_action( self::ASYNC_HOOK, array( $this, 'process_async_job' ), 10, 4 );

        // Audit #7 / 03 #3 + 06 F-H2 — apply the configured From / From-Name
        // to outgoing wp_mail() calls. The Settings → Emails fields were
        // collected but never wired to the sender envelope; every
        // transactional message left the store as `wordpress@<host>`,
        // breaking SPF/DKIM alignment and merchant brand. The filter is
        // gated on non-empty / well-formed values so a merchant who left
        // the option blank does not over-write WordPress's default.
        add_filter( 'wp_mail_from',      array( $this, 'filter_wp_mail_from' ), 10, 1 );
        add_filter( 'wp_mail_from_name', array( $this, 'filter_wp_mail_from_name' ), 10, 1 );
    }

    /**
     * Replace the wp_mail() From: address with the configured store
     * sender, when the merchant has provided a syntactically valid one.
     *
     * @param string $email Default sender email.
     */
    public function filter_wp_mail_from( $email ): string {
        $configured = (string) get_option( 'tejcart_from_email', '' );
        if ( '' === $configured ) {
            return (string) $email;
        }
        $sanitized = sanitize_email( $configured );
        if ( '' === $sanitized || ! is_email( $sanitized ) ) {
            return (string) $email;
        }
        return $sanitized;
    }

    /**
     * Replace the wp_mail() From: name with the configured store
     * sender name, when the merchant has provided one.
     *
     * @param string $name Default sender name.
     */
    public function filter_wp_mail_from_name( $name ): string {
        $configured = (string) get_option( 'tejcart_from_name', '' );
        if ( '' === $configured ) {
            return (string) $name;
        }
        $sanitized = sanitize_text_field( $configured );
        if ( '' === $sanitized ) {
            return (string) $name;
        }
        return $sanitized;
    }

    /**
     * @return Abstract_Email[]
     */
    public function get_emails() {
        return $this->emails;
    }

    /**
     * Hook email triggers to the appropriate actions.
     *
     * Each trigger enqueues an async job rather than sending inline.
     */
    private function hook_emails() {
        foreach ( self::ORDER_EMAIL_HOOKS as $action => $email_ids ) {
            foreach ( $email_ids as $email_id ) {
                if ( ! isset( $this->emails[ $email_id ] ) ) {
                    continue;
                }
                // The refunded template is mapped to BOTH the `refunded`
                // and `partially-refunded` transitions. Without a
                // per-status dedup discriminator a partial refund followed
                // by a full refund within the dedup window would suppress
                // the full-refund email. Use the status as the dedup suffix
                // for that email only, so each distinct refund transition
                // notifies the customer once while webhook replays of the
                // same transition still dedup. Other emails keep an empty
                // suffix to preserve their existing cross-status dedup
                // (e.g. admin_notification on-hold -> processing).
                $dedup_suffix = ( 'order_refunded' === $email_id )
                    ? substr( $action, strlen( 'tejcart_order_status_' ) )
                    : '';
                add_action(
                    $action,
                    function ( $order_id, $order = null ) use ( $email_id, $dedup_suffix ) {
                        $this->queue( $email_id, 'order', (int) $order_id, $dedup_suffix );
                    },
                    10,
                    2
                );
            }
        }

        // Audit #17 / 06 F-H5 — low-stock has TWO `tejcart_product_low_stock`
        // subscribers in core: Email_Manager (this subscription) AND
        // Low_Stock_Alert::queue_low_stock(). The latter handles both the
        // digest summary (default-on) and the per-product fallback. Wiring
        // both fired the per-product mail twice when digest mode was on
        // (one from each subscriber) and confused admins on multi-item
        // orders. Low_Stock_Alert is the single source of truth; the
        // Email_Manager keeps the Low_Stock_Alert_Email class registered
        // (Low_Stock_Alert instantiates and triggers it directly in
        // non-digest mode) but no longer auto-subscribes here.

        if ( isset( $this->emails['new_account'] ) ) {
            add_action(
                'user_register',
                function ( $user_id ) {
                    if ( apply_filters( 'tejcart_send_new_account_email', true, (int) $user_id ) ) {
                        $this->queue_for_user( 'new_account', (int) $user_id );
                    }
                },
                10,
                1
            );
        }

        if ( isset( $this->emails['password_reset'] ) ) {
            add_filter(
                'retrieve_password_message',
                array( $this, 'brand_password_reset_message' ),
                10,
                4
            );
        }

        // Audit #16 / 06 F-H4 — customer-visible note emails. Admin
        // order screen and REST API both produce customer-facing notes,
        // each with its own action signature. Adapt both to a single
        // trigger() shape.
        if ( isset( $this->emails['customer_note'] ) ) {
            $send_customer_note = function ( int $order_id, string $body, $order = null ): void {
                $email = $this->emails['customer_note'] ?? null;
                if ( ! is_object( $email ) || ! method_exists( $email, 'trigger' ) ) {
                    return;
                }
                $email->trigger( $order_id, $body, $order );
            };

            // Admin path: ( $order_id, $content, $order )
            add_action(
                'tejcart_admin_customer_note_added',
                static function ( $order_id, $content = '', $order = null ) use ( $send_customer_note ) {
                    $send_customer_note( (int) $order_id, (string) $content, $order );
                },
                10,
                3
            );

            // REST path: ( $order, $body )
            add_action(
                'tejcart_new_customer_note',
                static function ( $order, $body = '' ) use ( $send_customer_note ) {
                    if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
                        return;
                    }
                    $send_customer_note( (int) $order->get_id(), (string) $body, $order );
                },
                10,
                2
            );
        }
    }

    /**
     * Dispatch an email whose subject is a WP_User (not an order/product).
     *
     * These run inline rather than via the async queue because the WP hooks
     * they respond to (user_register, retrieve_password_message) are not
     * covered by the existing queue subject-type switch.
     *
     * @param string $email_id
     * @param int    $user_id
     */
    public function queue_for_user( string $email_id, int $user_id ): void {
        if ( ! isset( $this->emails[ $email_id ] ) || $user_id <= 0 ) {
            return;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return;
        }

        // Audit #73 / 06 F-M8 — route through the async queue so
        // user_register response isn't blocked on an SMTP round-trip.
        // The synchronous path is preserved via the
        // `tejcart_email_send_sync` filter for tests / wp-cli.
        $this->queue( $email_id, 'user', $user_id );
    }

    /**
     * Swap the default plaintext password-reset message for a branded
     * HTML body sourced from the password_reset template.
     *
     * @param string   $message    The default plaintext message.
     * @param string   $key        Reset key.
     * @param string   $user_login User login.
     * @param \WP_User $user_data  User object.
     * @return string
     */
    public function brand_password_reset_message( $message, $key, $user_login, $user_data ) {
        if ( ! isset( $this->emails['password_reset'] ) ) {
            return $message;
        }

        if ( ! apply_filters( 'tejcart_brand_password_reset_email', true, $user_data ) ) {
            return $message;
        }

        $email = $this->emails['password_reset'];
        if ( ! $email->is_enabled() ) {
            return $message;
        }

        $reset_url = network_site_url(
            'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
            'login'
        );

        $email->trigger( array(
            'user'  => $user_data,
            'key'   => (string) $key,
            'login' => (string) $user_login,
            'url'   => $reset_url,
        ) );

        // Audit #68 / 06 F-M3 — apply Content-Type via phpmailer_init
        // for THIS message only. The previous `wp_mail_content_type`
        // filter was never removed, so any subsequent wp_mail() in
        // the same request (e.g. an SMTP-plugin follow-up) inherited
        // text/html. The single-shot pattern matches
        // Abstract_Email::send() :304-316.
        $set_html = static function ( $phpmailer ) {
            if ( is_object( $phpmailer ) ) {
                $phpmailer->ContentType = 'text/html';
                $phpmailer->CharSet     = 'UTF-8';
            }
        };
        add_action( 'phpmailer_init', $set_html );
        // Audit follow-up — must be the FULL wrapped document (header bar,
        // heading, footer, mobile/dark-mode <style>), not just the body
        // fragment that get_content_html() returns. Otherwise the branded
        // reset email arrives without its heading, brand header, or footer.
        $body = $email->get_full_html();
        remove_action( 'phpmailer_init', $set_html );

        return $body;
    }

    /**
     * Schedule an email job for background dispatch.
     *
     * Uses wp_schedule_single_event() so any external WP-Cron runner
     * (Action Scheduler, system cron hitting wp-cron.php, etc.) will
     * pick it up. Falls back to inline send when scheduling fails.
     *
     * @param string $email_id     Registered email identifier.
     * @param string $subject_type 'order' | 'product'.
     * @param int    $subject_id   ID of the subject object.
     * @param string $dedup_suffix Optional discriminator folded into the
     *                             dedup key so the same email_id can be
     *                             sent once per distinct sub-event (e.g.
     *                             refund status) for one subject.
     */
    public function queue( string $email_id, string $subject_type, int $subject_id, string $dedup_suffix = '' ): bool {
        if ( ! isset( $this->emails[ $email_id ] ) || $subject_id <= 0 ) {
            return false;
        }

        // F-H7 / #930: when WP-Cron is disabled and no external runner
        // is configured (and Action_Scheduler is not actively available),
        // wp_schedule_single_event() can still queue the event but
        // nothing ever fires it — transactional emails would silently
        // never go out. Default `tejcart_email_send_sync` to true in
        // that environment so the receipt is dispatched inline. Merchants
        // running a real cron runner can override the filter to keep
        // the original async behaviour.
        $cron_disabled       = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $action_scheduler_ok = class_exists( '\ActionScheduler' ) || function_exists( 'as_schedule_single_action' );
        $sync_default        = ( $cron_disabled && ! $action_scheduler_ok );

        /**
         * Filter whether to bypass async queueing and send inline.
         *
         * Useful for tests or environments without a working cron.
         *
         * @param bool   $bypass       Default false, unless DISABLE_WP_CRON is
         *                             set and Action Scheduler is unavailable,
         *                             in which case true (F-H7).
         * @param string $email_id
         * @param string $subject_type
         * @param int    $subject_id
         */
        $bypass = (bool) apply_filters( 'tejcart_email_send_sync', $sync_default, $email_id, $subject_type, $subject_id );
        if ( $bypass ) {
            if ( ! $this->reserve_dedup( $email_id, $subject_type, $subject_id, $dedup_suffix ) ) {
                return true;
            }
            $this->dispatch( $email_id, $subject_type, $subject_id );
            return true;
        }

        // Duplicate webhook attempts (Stripe retries on 5xx,
        // PayPal IPN replays) and same-status update_status() calls would
        // historically each enqueue an identical email job. Short-circuit
        // here on a 24-hour transient keyed by (email, subject) so the
        // buyer never receives the receipt twice for the same transition.
        // Operators can blow the dedup window via the
        // tejcart_email_dedup_ttl filter or by deleting the transient.
        if ( ! $this->reserve_dedup( $email_id, $subject_type, $subject_id, $dedup_suffix ) ) {
            return true;
        }

        // S-6: route via the Action_Scheduler facade so when the AS library
        // is available we get its parallel runner / queue table semantics
        // for free; the facade transparently falls back to wp-cron only if
        // AS is genuinely missing.
        $scheduled = \TejCart\Core\Action_Scheduler::instance()->schedule_single(
            time() + 5,
            self::ASYNC_HOOK,
            array( $email_id, $subject_type, $subject_id )
        );

        if ( false === $scheduled ) {
            $this->dispatch( $email_id, $subject_type, $subject_id );
        }

        return true;
    }

    /**
     * Atomic dedup reservation for an email job.
     *
     * Returns true when the caller is the first to claim the (email, subject)
     * tuple, false when a duplicate. Backed by a transient with INSERT-IGNORE
     * semantics on the underlying option row so two concurrent webhook
     * processes can't both win the claim.
     *
     * @param string $email_id
     * @param string $subject_type
     * @param int    $subject_id
     * @param string $suffix Optional discriminator folded into the key.
     */
    private function reserve_dedup( string $email_id, string $subject_type, int $subject_id, string $suffix = '' ): bool {
        /**
         * Filter the dedup window for transactional emails.
         *
         * @param int    $ttl_seconds
         * @param string $email_id
         * @param string $subject_type
         * @param int    $subject_id
         */
        $ttl = (int) apply_filters( 'tejcart_email_dedup_ttl', DAY_IN_SECONDS, $email_id, $subject_type, $subject_id );
        if ( $ttl <= 0 ) {
            return true;
        }

        // Custom option name (not a transient) so we get the INSERT IGNORE
        // semantics of add_option without WordPress's transient-cache layer
        // racing the claim.
        $option_name = 'tejcart_email_dedup_' . substr(
            hash( 'sha256', $email_id . '|' . $subject_type . '|' . $subject_id . '|' . $suffix ),
            0,
            40
        );

        $claimed = add_option( $option_name, time(), '', 'no' );

        if ( $claimed ) {
            if ( function_exists( 'wp_schedule_single_event' ) ) {
                wp_schedule_single_event(
                    time() + $ttl + MINUTE_IN_SECONDS,
                    'tejcart_cleanup_webhook_option',
                    array( $option_name )
                );
            }
            return true;
        }

        // Same key already claimed; check whether the previous claim has
        // expired so a stale option doesn't lock us out forever (cron may
        // not have fired the cleanup yet).
        $previous = (int) get_option( $option_name, 0 );
        if ( $previous > 0 && ( time() - $previous ) > $ttl ) {
            update_option( $option_name, time(), 'no' );
            return true;
        }

        return false;
    }

    /**
     * Cron callback. Hydrates the subject and invokes the email trigger.
     *
     * @param string $email_id
     * @param string $subject_type
     * @param int    $subject_id
     */
    public function process_async_job( $email_id, $subject_type, $subject_id, $attempt = 1 ): void {
        $email_id     = (string) $email_id;
        $subject_type = (string) $subject_type;
        $subject_id   = (int) $subject_id;
        $attempt      = max( 1, (int) $attempt );

        // F-L5 / #955: capture wp_mail failures so we can re-schedule
        // with exponential backoff. wp_mail returns false through
        // Abstract_Email::send() but the existing trigger() methods
        // don't propagate it; the wp_mail_failed action is the
        // canonical signal that PHPMailer threw.
        $failed = false;
        $listener = static function () use ( &$failed ) {
            $failed = true;
        };
        add_action( 'wp_mail_failed', $listener );

        try {
            $this->dispatch( $email_id, $subject_type, $subject_id );
        } catch ( \Throwable $e ) {
            $failed = true;
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Email dispatch threw: ' . $e->getMessage(), 'warning' );
            }
        } finally {
            remove_action( 'wp_mail_failed', $listener );
        }

        if ( ! $failed ) {
            return;
        }

        /**
         * Maximum number of dispatch attempts before declaring the
         * email dead-lettered. Default 4 (~ initial + 3 retries).
         *
         * @param int $max
         */
        $max_attempts = (int) apply_filters( 'tejcart_email_max_attempts', 4 );
        if ( $attempt >= $max_attempts ) {
            /**
             * Fires when an email exhausts its retry budget.
             *
             * @param string $email_id
             * @param string $subject_type
             * @param int    $subject_id
             * @param int    $attempt
             */
            do_action( 'tejcart_email_dead_letter', $email_id, $subject_type, $subject_id, $attempt );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Email %s for %s#%d dead-lettered after %d attempts.', $email_id, $subject_type, $subject_id, $attempt ),
                    'error'
                );
            }
            return;
        }

        // Exponential backoff: 60s, 5m, 30m, 4h (matches the
        // outgoing-webhook delivery schedule for symmetry).
        $backoff = array( 60, 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 4 * HOUR_IN_SECONDS );
        $delay   = isset( $backoff[ $attempt - 1 ] ) ? (int) $backoff[ $attempt - 1 ] : (int) end( $backoff );

        \TejCart\Core\Action_Scheduler::instance()->schedule_single(
            time() + $delay,
            self::ASYNC_HOOK,
            array( $email_id, $subject_type, $subject_id, $attempt + 1 )
        );
    }

    /**
     * Resolve the subject and invoke the email's trigger() method.
     */
    private function dispatch( string $email_id, string $subject_type, int $subject_id ): void {
        if ( ! isset( $this->emails[ $email_id ] ) || $subject_id <= 0 ) {
            return;
        }

        $email = $this->emails[ $email_id ];

        if ( 'order' === $subject_type ) {
            $order = \TejCart\Order\Order_Factory::get_order( $subject_id );
            if ( ! $order ) {
                return;
            }
            $email->trigger( $subject_id, $order );
            return;
        }

        if ( 'product' === $subject_type ) {
            $email->trigger( $subject_id );
            return;
        }

        // Audit #73 / 06 F-M8 — async path for user-subject emails
        // (New_Account). Previously these were sent inline on
        // user_register, blocking the signup response on an SMTP
        // round-trip.
        if ( 'user' === $subject_type ) {
            $user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $subject_id ) : null;
            if ( ! $user ) {
                return;
            }
            $email->trigger( $subject_id, $user );
            return;
        }
    }

    /**
     * Synchronous send fallback (admin "send test email", CLI, etc.).
     *
     * Bypasses the queue entirely. Prefer queue() for production paths.
     *
     * @param string $email_id
     * @param array  $args Positional args forwarded to trigger() (or send() if empty).
     */
    public function send( $email_id, $args = array() ) {
        return $this->send_now( $email_id, $args );
    }

    /**
     * Explicit synchronous dispatch — never queues.
     */
    public function send_now( string $email_id, array $args = array() ): bool {
        if ( ! isset( $this->emails[ $email_id ] ) ) {
            return false;
        }

        $email = $this->emails[ $email_id ];

        if ( ! empty( $args ) ) {
            call_user_func_array( array( $email, 'trigger' ), $args );
        } else {
            $email->send();
        }

        return true;
    }
}
