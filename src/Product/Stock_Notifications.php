<?php
/**
 * "Notify me when available" out-of-stock signups.
 *
 * When a product is out of stock (and doesn't accept backorders) the
 * single-product template renders a lightweight subscribe form. Signups
 * land in wp_tejcart_stock_notifications. When the same product later
 * transitions back to instock the subscribe list is drained: one email
 * per subscriber + the row is removed.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stock-back-in-stock notification store + dispatcher.
 */
class Stock_Notifications {
    public const TABLE_SUFFIX = 'tejcart_stock_notifications';

    /**
     * Hook listeners on the TejCart product save events.
     */
    public function init(): void {
        add_action( 'tejcart_product_updated', array( $this, 'maybe_dispatch_on_update' ), 10, 2 );

        // Audit L-37 (Product F-020): wire the public unsubscribe
        // handler so the link in back-in-stock emails actually works.
        // CAN-SPAM + GDPR Art. 21 require a working one-click unsub.
        add_action( 'init', array( $this, 'handle_unsubscribe_request' ) );
    }

    /**
     * Handle `?tejcart_unsubscribe_stock=1&token=…` on the public site.
     */
    public function handle_unsubscribe_request(): void {
        if ( empty( $_GET['tejcart_unsubscribe_stock'] ) || empty( $_GET['token'] ) ) {
            return;
        }
        $token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) );
        if ( $this->unsubscribe( $token ) ) {
            // Show a simple confirmation and exit so the buyer doesn't
            // land on a random page. wp_die with a 200 status is the
            // canonical WP pattern for one-click unsubscribe endpoints.
            wp_die(
                esc_html__( 'You have been unsubscribed from back-in-stock notifications.', 'tejcart' ),
                esc_html__( 'Unsubscribed', 'tejcart' ),
                array( 'response' => 200 )
            );
        }
        wp_die(
            esc_html__( 'This unsubscribe link is invalid or has already been used.', 'tejcart' ),
            esc_html__( 'Unsubscribe', 'tejcart' ),
            array( 'response' => 400 )
        );
    }

    /**
     * DB table name with the WP prefix.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Register an email subscription for a product going back in stock.
     *
     * Returns:
     *   - WP_Error on bad input.
     *   - int row id on a fresh subscription.
     *   - int existing row id when the (email, product) pair is already
     *     recorded (idempotent — we don't want duplicate signups).
     *
     * @param string $email
     * @param int    $product_id
     * @return int|\WP_Error
     */
    public function subscribe( string $email, int $product_id ) {
        $email      = trim( strtolower( $email ) );
        $product_id = absint( $product_id );

        if ( $product_id <= 0 || ! function_exists( 'is_email' ) || ! is_email( $email ) ) {
            return new \WP_Error(
                'tejcart_stock_notification_invalid',
                __( 'Please provide a valid email address.', 'tejcart' )
            );
        }

        global $wpdb;
        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND email = %s LIMIT 1",
                $product_id,
                $email
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $existing > 0 ) {
            return $existing;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ok = $wpdb->insert(
            $table,
            array(
                'product_id' => $product_id,
                'email'      => $email,
                'token'      => self::build_token( $product_id, $email ),
                'created_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $ok ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE product_id = %d AND email = %s LIMIT 1",
                    $product_id,
                    $email
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $existing > 0 ) {
                return $existing;
            }

            return new \WP_Error(
                'tejcart_stock_notification_write',
                __( 'Could not record the subscription.', 'tejcart' )
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Every pending subscriber for a product id.
     *
     * @param int $product_id
     * @return array<int, array{id:int, email:string, token:string}>
     */
    public function pending_for_product( int $product_id ): array {
        if ( $product_id <= 0 ) {
            return array();
        }
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, email, token FROM {$table} WHERE product_id = %d ORDER BY id ASC",
                $product_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $rows;
    }

    /**
     * Remove a single subscription by token (unsubscribe link handler).
     *
     * @param string $token
     * @return bool
     */
    public function unsubscribe( string $token ): bool {
        if ( '' === $token ) {
            return false;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->delete( $table, array( 'token' => $token ), array( '%s' ) );
        return (bool) $result;
    }

    /**
     * Fired after every product update. When a product that was
     * outofstock transitions back to instock, drain its subscription
     * list: enqueue one mail per subscriber (via wp_mail) and delete
     * the rows so we never re-notify on a subsequent save.
     *
     * @param int                                            $product_id
     * @param \TejCart\Product\Product_Types\Abstract_Product $product
     */
    public function maybe_dispatch_on_update( $product_id, $product ): void {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_stock_status' ) ) {
            return;
        }
        if ( 'instock' !== $product->get_stock_status() ) {
            return;
        }

        if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
            $qty = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
            if ( $qty <= 0 ) {
                return;
            }
        }

        $subscribers = $this->pending_for_product( (int) $product_id );
        if ( empty( $subscribers ) ) {
            return;
        }

        foreach ( $subscribers as $row ) {
            $this->send_notification( (int) $product_id, (string) $row['email'], (string) $row['token'] );
        }

        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $table, array( 'product_id' => (int) $product_id ), array( '%d' ) );
    }

    /**
     * Send a single "back in stock" email.
     *
     * 06 F-M2 — previously built a plain-text body and called `wp_mail()`
     * directly with no Content-Type, marker header, From override, or
     * HTML template. The send now routes through Back_In_Stock_Email
     * (Abstract_Email subclass), so it picks up the email log marker
     * headers, per-message Content-Type, and admin template-override
     * pipeline. The `tejcart_stock_notification_email` filter is
     * preserved for any integration that fully replaces the payload —
     * when the filter returns a non-default shape, we fall back to the
     * legacy `wp_mail()` path so existing addons keep working.
     */
    private function send_notification( int $product_id, string $email, string $token ): void {
        $product = \TejCart\Product\Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $unsubscribe_url = add_query_arg(
            array(
                'tejcart_unsubscribe_stock' => 1,
                'token'                     => $token,
            ),
            home_url( '/' )
        );

        $default_subject = sprintf(
            /* translators: %s: product name */
            __( 'Back in stock: %s', 'tejcart' ),
            $product->get_name()
        );

        // Backwards-compat filter: integrations that fully customise the
        // payload (to/subject/body/headers) still see the same shape they
        // always have. When the filter returns the unchanged default
        // payload we treat it as "no override" and use the templated
        // Abstract_Email send path.
        $default_body = sprintf(
            /* translators: %s: product name */
            __( 'Good news — %s is back in stock.', 'tejcart' ),
            $product->get_name()
        ) . "\n\n" . $product->get_permalink() . "\n\n" . sprintf(
            /* translators: %s: unsubscribe URL */
            __( 'If you no longer wish to receive these alerts, unsubscribe here: %s', 'tejcart' ),
            $unsubscribe_url
        );

        $defaults = array(
            'to'      => $email,
            'subject' => $default_subject,
            'body'    => $default_body,
            'headers' => array(),
        );

        /**
         * Filter the outgoing stock notification before it's sent.
         *
         * Preserved for backwards compatibility (06 F-M2): when this
         * filter mutates ANY of the four payload keys we honour the
         * override and call `wp_mail()` directly, matching the legacy
         * behaviour exactly. When the filter returns the defaults
         * unchanged we route through Back_In_Stock_Email so the email
         * picks up templating, marker headers, and the email log.
         *
         * @param array $payload { subject, body, headers, to }
         * @param int   $product_id
         */
        $payload = (array) apply_filters(
            'tejcart_stock_notification_email',
            $defaults,
            $product_id
        );

        $overridden = (
            ( $payload['subject'] ?? null ) !== $defaults['subject']
            || ( $payload['body']    ?? null ) !== $defaults['body']
            || ( $payload['headers'] ?? null ) !== $defaults['headers']
            || ( $payload['to']      ?? null ) !== $defaults['to']
        );

        if ( $overridden ) {
            wp_mail(
                (string) ( $payload['to'] ?? $email ),
                (string) ( $payload['subject'] ?? $default_subject ),
                (string) ( $payload['body'] ?? $default_body ),
                (array) ( $payload['headers'] ?? array() )
            );
            return;
        }

        $email_class = new \TejCart\Email\Emails\Back_In_Stock_Email();
        $email_class->trigger( $product_id, $email, $unsubscribe_url );
    }

    /**
     * Cryptographically strong unsubscribe token.
     *
     * 128-bit secret bound to the product/email pair via HMAC, so the token is
     * unpredictable even to an attacker who knows both values. An earlier
     * implementation used uniqid(), whose entropy source is a microtimestamp
     * and therefore guessable within seconds.
     */
    private static function build_token( int $product_id, string $email ): string {
        try {
            $entropy = random_bytes( 16 );
        } catch ( \Throwable $e ) {
            $entropy = hash( 'sha256', wp_generate_password( 64, true, true ), true );
        }
        // The 128-bit random $entropy already makes the token unguessable,
        // and it is stored then looked up verbatim, so no WP-salt keying is
        // required. Key the HMAC with the rotation-stable Key_Manager secret
        // instead of wp_salt() so this persistent token isn't coupled to WP
        // auth-secret rotation (wp.org review).
        $seed = $product_id . '|' . strtolower( $email ) . '|' . $entropy;
        $key  = \TejCart\Security\Key_Manager::is_available()
            ? \TejCart\Security\Key_Manager::hmac_key( 'tejcart|stock-notify|v1' )
            : (string) wp_salt( 'auth' );
        return substr( hash_hmac( 'sha256', $seed, $key ), 0, 40 );
    }
}
