<?php
/**
 * Abandoned cart recovery.
 *
 * Tracks carts that contain items + a known email but never check out,
 * then sends a sequenced recovery email with a secure restore link.
 *
 * Integration:
 *  - Listens on `tejcart_cart_updated` and the existing checkout
 *    customer-email actions to record / refresh a snapshot.
 *  - Listens on `tejcart_new_order` to mark snapshots recovered.
 *  - Schedules a single WP-Cron event (`tejcart_tier2_abandoned_cart_run`)
 *    that drives the full sequence; the handler uses an option-based
 *    lock to prevent duplicate concurrent runs.
 *  - Provides a public `?tejcart_recover=TOKEN` recovery URL handled on
 *    `template_redirect` that re-hydrates the cart and redirects to the
 *    checkout page.
 *
 * @package TejCart\Tier2\Abandoned_Cart
 */

declare( strict_types=1 );

namespace TejCart\Tier2\Abandoned_Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Abandoned_Cart {
    const CRON_HOOK            = 'tejcart_tier2_abandoned_cart_run';
    const LOCK_OPTION          = 'tejcart_tier2_abandoned_lock';
    const LAST_RUN_OPTION      = 'tejcart_tier2_abandoned_last_run';
    const COOKIE_NAME          = 'tejcart_recover_token';
    const INLINE_INTERVAL_SECS = 3600;

    /**
     * Email sequence: minutes after abandonment, label.
     *
     * Filterable so site owners can extend or shorten the cadence.
     *
     * @var array<int, array{after_minutes:int, template:string}>
     */
    private static function sequence() {
        return apply_filters( 'tejcart_abandoned_cart_sequence', array(
            array( 'after_minutes' => 60,        'template' => 'first' ),
            array( 'after_minutes' => 60 * 24,   'template' => 'second' ),
            array( 'after_minutes' => 60 * 72,   'template' => 'final' ),
        ) );
    }

    public static function init() {
        add_action( 'tejcart_cart_updated',  array( __CLASS__, 'record_snapshot' ), 20 );
        add_action( 'tejcart_add_to_cart',   array( __CLASS__, 'record_snapshot_lite' ), 20 );
        add_action( 'tejcart_new_order',     array( __CLASS__, 'mark_recovered' ), 20, 2 );
        add_action( 'template_redirect',     array( __CLASS__, 'handle_recovery_link' ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );

        // N-H1 (follow-up to F-H7): on sites where WP-Cron is disabled
        // and Action Scheduler isn't available, the hourly event above
        // is registered but never fires — abandoned-cart recovery emails
        // would silently stop. Piggy-back on the `init` action and run
        // the queue inline at most once per hour. The existing 5-minute
        // option lock in process_queue() already guards multi-server
        // concurrency. Merchants with a real external runner can disable
        // the fallback via the `tejcart_abandoned_cart_inline_fallback`
        // filter.
        add_action( 'init', array( __CLASS__, 'maybe_run_inline' ), 99 );
    }

    /**
     * Inline WP-Cron fallback for sites with DISABLE_WP_CRON.
     *
     * Runs the queue at most once per {@see self::INLINE_INTERVAL_SECS}
     * window. Short-circuits cheaply on every other request.
     */
    public static function maybe_run_inline(): void {
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $as_available  = class_exists( '\ActionScheduler' ) || function_exists( 'as_schedule_single_action' );

        /**
         * Filter whether the abandoned-cart queue should run inline when
         * WP-Cron is disabled.
         *
         * @param bool $should_run True when DISABLE_WP_CRON is set and
         *                         Action Scheduler is unavailable.
         */
        if ( ! apply_filters( 'tejcart_abandoned_cart_inline_fallback', $cron_disabled && ! $as_available ) ) {
            return;
        }

        // Cache the last-run sentinel in the object cache so the
        // hot-path early-return is a memory hit, not a wp_options
        // SELECT, when DISABLE_WP_CRON is set (audit 06 F-L5). Real
        // backend rotation still flows through update_option below.
        $cache_key = self::LAST_RUN_OPTION;
        $cache_grp = 'tejcart';
        $cached    = wp_cache_get( $cache_key, $cache_grp );
        $now       = time();
        if ( false !== $cached ) {
            $last_run = (int) $cached;
        } else {
            $last_run = (int) get_option( self::LAST_RUN_OPTION, 0 );
            wp_cache_set( $cache_key, $last_run, $cache_grp, self::INLINE_INTERVAL_SECS );
        }
        if ( $last_run && ( $now - $last_run ) < self::INLINE_INTERVAL_SECS ) {
            return;
        }
        update_option( self::LAST_RUN_OPTION, $now, false );
        wp_cache_set( $cache_key, $now, $cache_grp, self::INLINE_INTERVAL_SECS );
        self::process_queue();
    }

    /**
     * Record a fresh snapshot for the current cart.
     *
     * Skips if no email is known yet (we cannot recover anonymously).
     */
    public static function record_snapshot( $cart = null ) {
        if ( ! is_object( $cart ) ) {
            $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        }
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_items' ) ) {
            return;
        }
        $items = $cart->get_items();
        if ( empty( $items ) ) {
            return;
        }
        $email = self::guess_email();
        if ( empty( $email ) ) {
            return;
        }
        self::write_snapshot( $cart, $email );
    }

    /**
     * Lightweight wrapper for the add_to_cart action signature.
     */
    public static function record_snapshot_lite() {
        self::record_snapshot();
    }

    /**
     * Persist (or refresh) a snapshot row keyed by token cookie.
     */
    private static function write_snapshot( $cart, $email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_abandoned_carts';

        $token = isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : '';
        if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            // F-M15 / #949: abandoned-cart marker is a tracking/marketing
            // cookie (not strictly necessary), so respect the cookie
            // consent gate. When consent is required but not yet given,
            // skip the cookie entirely — the module will only resume
            // capturing once the buyer accepts.
            if ( function_exists( 'tejcart_has_cookie_consent' ) && ! tejcart_has_cookie_consent() ) {
                return;
            }
            $token = bin2hex( random_bytes( 32 ) );
            if ( ! headers_sent() ) {
                setcookie(
                    self::COOKIE_NAME,
                    $token,
                    array(
                        'expires'  => time() + ( 86400 * 14 ),
                        'path'     => COOKIEPATH ? COOKIEPATH : '/',
                        'domain'   => COOKIE_DOMAIN,
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    )
                );
            }
        }

        $contents = array();
        foreach ( $cart->get_items() as $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }
            $contents[] = array(
                'product_id' => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
                'quantity'   => method_exists( $item, 'get_quantity' )   ? (int) $item->get_quantity()   : 1,
                'data'       => method_exists( $item, 'get_data' )       ? $item->get_data()             : array(),
            );
        }

        $row = array(
            'token'         => $token,
            'email'         => $email,
            'user_id'       => is_user_logged_in() ? get_current_user_id() : null,
            'cart_contents' => wp_json_encode( $contents ),
            'cart_total'    => method_exists( $cart, 'get_total' )
                ? \TejCart\Money\Currency::to_minor_units(
                    (float) $cart->get_total(),
                    (string) get_option( 'tejcart_currency', 'USD' )
                )
                : 0,
            'currency'      => get_option( 'tejcart_currency', 'USD' ),
            'status'        => 'pending',
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s", $token ) );

        if ( $existing ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update(
                $table,
                $row,
                array( 'id' => (int) $existing ),
                array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->insert(
                $table,
                $row,
                array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
    }

    /**
     * Mark snapshots as recovered when an order from the same email is created.
     */
    public static function mark_recovered( $order_id, $order = null ) {
        global $wpdb;
        $email = '';
        if ( is_object( $order ) && method_exists( $order, 'get_customer_email' ) ) {
            $email = $order->get_customer_email();
        }
        if ( ! $email ) {
            return;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->update(
            $wpdb->prefix . 'tejcart_abandoned_carts',
            array( 'status' => 'recovered', 'recovered_order_id' => (int) $order_id ),
            array( 'email' => $email, 'status' => 'pending' ),
            array( '%s', '%d' ),
            array( '%s', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Cron handler. Sends the next due email in the sequence for each
     * pending snapshot. Wrapped in an option lock so duplicate cron
     * triggers (multi-server, manual ?doing_wp_cron) cannot double-send.
     */
    public static function process_queue() {
        $now  = time();
        $lock = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $lock && ( $now - $lock ) < 300 ) {
            return;
        }
        update_option( self::LOCK_OPTION, $now, false );

        try {
            global $wpdb;
            $table  = $wpdb->prefix . 'tejcart_abandoned_carts';
            $seq    = self::sequence();
            $max    = count( $seq );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows   = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s AND emails_sent < %d ORDER BY updated_at ASC LIMIT 100",
                    'pending',
                    $max
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            foreach ( (array) $rows as $row ) {
                $sent_count = (int) $row['emails_sent'];
                if ( ! isset( $seq[ $sent_count ] ) ) {
                    continue;
                }
                $step      = $seq[ $sent_count ];
                $threshold = strtotime( $row['updated_at'] ) + ( (int) $step['after_minutes'] * 60 );
                if ( $now < $threshold ) {
                    continue;
                }

                $sent = self::send_recovery_email( $row, $step['template'] );

                if ( $sent ) {
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->update(
                        $table,
                        array(
                            'emails_sent'   => $sent_count + 1,
                            'last_email_at' => current_time( 'mysql' ),
                        ),
                        array( 'id' => (int) $row['id'] ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                }
            }
        } finally {
            delete_option( self::LOCK_OPTION );
        }
    }

    /**
     * Send a recovery email for a snapshot row.
     *
     * Routes through the core Abandoned_Cart_Email class so the message
     * gets the branded HTML header/footer, a styled CTA button, and the
     * admin enable/disable toggle shared by all transactional emails.
     */
    private static function send_recovery_email( array $row, $template_key ) {
        $to = sanitize_email( $row['email'] );
        if ( ! $to ) {
            return false;
        }

        $email = null;

        if ( function_exists( 'tejcart' ) && method_exists( tejcart(), 'emails' ) ) {
            $manager = tejcart()->emails();
            if ( $manager && method_exists( $manager, 'get_emails' ) ) {
                $emails = $manager->get_emails();
                $email  = $emails['abandoned_cart'] ?? null;
            }
        }

        if ( ! $email instanceof \TejCart\Email\Emails\Abandoned_Cart_Email ) {
            $email = new \TejCart\Email\Emails\Abandoned_Cart_Email();
        }

        $ok = $email->trigger( $row, (string) $template_key );

        if ( class_exists( '\\TejCart\\Tier2\\Emails\\Template_System' ) ) {
            \TejCart\Tier2\Emails\Template_System::record(
                'abandoned_cart',
                $to,
                $email->get_subject(),
                'abandoned_cart',
                (int) $row['id'],
                $ok ? 'sent' : 'failed'
            );
        }

        return (bool) $ok;
    }

    /**
     * Handle ?tejcart_recover=TOKEN URLs. Validates the token and rehydrates
     * the cart, then redirects to checkout.
     */
    public static function handle_recovery_link() {
        // The token itself is the bearer of authenticity (random 64-hex,
        // looked up against the abandoned-carts table); this is the
        // recovery-link analogue of a password-reset key.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['tejcart_recover'] ) ) {
            return;
        }
        $token = sanitize_text_field( wp_unslash( $_GET['tejcart_recover'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_abandoned_carts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A );

        if ( ! $row ) {
            return;
        }
        if ( 'pending' !== $row['status'] ) {
            wp_safe_redirect( function_exists( 'tejcart_get_page_url' ) ? tejcart_get_page_url( 'cart' ) : home_url( '/' ) );
            exit;
        }

        if ( strtotime( $row['updated_at'] ) < ( time() - ( 86400 * 14 ) ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update( $table, array( 'status' => 'expired' ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
            return;
        }

        $contents = json_decode( $row['cart_contents'], true );
        $cart     = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;

        if ( $cart && is_array( $contents ) ) {
            if ( method_exists( $cart, 'empty_cart' ) ) {
                $cart->empty_cart();
            }
            foreach ( $contents as $item ) {
                if ( empty( $item['product_id'] ) ) {
                    continue;
                }
                $cart->add( (int) $item['product_id'], (int) ( $item['quantity'] ?? 1 ), (array) ( $item['data'] ?? array() ) );
            }
        }

        $checkout_url = function_exists( 'tejcart_get_page_url' ) ? tejcart_get_page_url( 'checkout' ) : home_url( '/' );
        wp_safe_redirect( $checkout_url );
        exit;
    }

    /**
     * Best-effort customer email lookup.
     *
     * Only pulls from $_POST when the request looks like it originated from
     * a TejCart cart/checkout/account form — presence of a TejCart action
     * marker or of one of the known checkout field names. This prevents
     * unrelated plugins/themes that happen to post an `email` field from
     * being silently captured as shoppers.
     */
    private static function guess_email() {
        if ( is_user_logged_in() ) {
            $u = wp_get_current_user();
            if ( $u && ! empty( $u->user_email ) ) {
                return sanitize_email( $u->user_email );
            }
        }

        if ( ! self::is_tejcart_submission() ) {
            return '';
        }

        // Conservative read of address fields posted by the checkout form;
        // each value goes through sanitize_email() before use. Nonce
        // verification is the responsibility of the form handler that
        // accepts the submission — we are only opportunistically capturing
        // the email for abandoned-cart bookkeeping.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( ! empty( $_POST['billing_email'] ) ) {
            return sanitize_email( wp_unslash( $_POST['billing_email'] ) );
        }
        if ( ! empty( $_POST['tejcart_email'] ) ) {
            return sanitize_email( wp_unslash( $_POST['tejcart_email'] ) );
        }
        if ( ! empty( $_POST['email'] ) ) {
            return sanitize_email( wp_unslash( $_POST['email'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return '';
    }

    /**
     * Returns true if the current request appears to be a TejCart form post.
     *
     * Signals we accept:
     *   - A TejCart-namespaced action / nonce marker in the payload.
     *   - A DOING_AJAX request whose "action" starts with "tejcart_".
     *   - A known TejCart checkout field cluster (tejcart_ship_same +
     *     billing_first_name, etc.).
     *
     * The test stays intentionally conservative — a false negative just
     * means we skip email capture for that request, which is always safe.
     */
    private static function is_tejcart_submission() {
        // Detection probe — reads markers from $_POST/$_REQUEST without acting on
        // them. Nonce verification belongs to the form handler downstream.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
            return '' !== $action && 0 === strpos( $action, 'tejcart_' );
        }

        if ( empty( $_POST ) ) {
            return false;
        }

        foreach ( $_POST as $key => $_v ) {
            if ( is_string( $key ) && 0 === strpos( $key, 'tejcart_' ) ) {
                return true;
            }
        }

        return isset( $_POST['billing_first_name'], $_POST['billing_email'] )
            || isset( $_POST['tejcart_process_checkout_nonce'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
    }
}
