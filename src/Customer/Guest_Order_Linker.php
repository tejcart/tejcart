<?php
/**
 * Guest order → user linker.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Claim any prior guest orders that share a customer's email address.
 *
 * The link is performed with a single conditional UPDATE that only
 * touches rows whose customer_id is still NULL — already-linked orders
 * (e.g. orders attached to a different account that shared an email
 * historically, or rows linked by an admin) are never overwritten.
 *
 * SECURITY — S-H1 (account-takeover of order history / downloads):
 * Linking is gated on a *verified-email* signal. The previous
 * implementation linked on `user_register`, which fires the instant an
 * account row is created — before the email address has been proven to
 * belong to the registrant. Anyone could register with a victim's email
 * (self-registration is public on many stores) and instantly inherit the
 * victim's guest order history and download access.
 *
 * WordPress core exposes no "email verified" flag at `user_register`
 * time, and TejCart ships no email-verification / double-opt-in
 * primitive of its own. The strongest verification signal universally
 * available is therefore a *successful login* (`wp_login`): WordPress
 * only lets an account authenticate after the registrant has acted on
 * the set-password email WP sends to that address (or has otherwise been
 * granted credentials by an admin/SSO that already trusts the address),
 * so reaching `wp_login` demonstrates control of the inbox the orders
 * were placed under. We therefore defer linking until the account's
 * first authenticated session.
 *
 * This does not break the legitimate checkout "create an account" flow:
 * that path logs the new user in within the same request (firing
 * `wp_login`), so their just-placed order is still linked immediately.
 *
 * Limitation: an admin who manually creates a customer account on the
 * customer's behalf does not link historical guest orders until that
 * customer first logs in. This is the intended, fail-safe behaviour —
 * we prefer a deferred legitimate link over an instant unverified one.
 */
class Guest_Order_Linker {
    /**
     * Wire up the verified-login hook.
     *
     * Note: linking is intentionally NOT wired to `user_register` — see
     * the class docblock (S-H1). `wp_login` is the verified-email gate.
     */
    public function init(): void {
        add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );
    }

    /**
     * Hook callback for `wp_login`. Links guest orders that match the
     * authenticated user's (now verified) email address.
     *
     * @param string       $user_login The user's login username (unused).
     * @param \WP_User|null $user       The authenticated WP_User object.
     */
    public function on_user_login( $user_login, $user = null ): void {
        // `wp_login` passes the WP_User as the second arg; fall back to a
        // lookup by login if a caller fired the action without it.
        if ( ! ( $user instanceof \WP_User ) ) {
            $user = is_string( $user_login ) ? get_user_by( 'login', $user_login ) : null;
        }
        if ( ! ( $user instanceof \WP_User ) || $user->ID <= 0 || empty( $user->user_email ) ) {
            return;
        }

        $user_id = (int) $user->ID;
        $linked  = self::link_guest_orders( $user_id, (string) $user->user_email );

        if ( $linked > 0 ) {
            tejcart_log(
                sprintf( 'Linked %d guest order(s) to user #%d on verified login.', $linked, $user_id ),
                'info'
            );

            /**
             * Fires after guest orders are linked to a user whose email is
             * considered verified (first/subsequent authenticated login).
             *
             * @param int    $user_id Authenticated user ID.
             * @param string $email   Email used for matching.
             * @param int    $linked  Number of orders updated.
             */
            do_action( 'tejcart_guest_orders_linked', $user_id, (string) $user->user_email, $linked );
        }
    }

    /**
     * Atomically claim guest orders for a user.
     *
     * Safe-update guarantees:
     *   1. customer_email = %s          → match by registration email
     *   2. customer_id IS NULL          → never overwrite an existing link
     *   3. UPDATE is a single statement → no read-modify-write race
     *
     * @param int    $user_id WP user ID to assign.
     * @param string $email   Email to match against customer_email.
     * @return int Number of orders updated (0 if none / -1 on DB error).
     */
    public static function link_guest_orders( int $user_id, string $email ): int {
        if ( $user_id <= 0 || '' === $email ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $affected = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$table}
                    SET customer_id = %d,
                        updated_at  = %s
                  WHERE customer_email = %s
                    AND customer_id IS NULL",
                $user_id,
                current_time( 'mysql' ),
                $email
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $affected ) {
            return -1;
        }

        return (int) $affected;
    }
}
