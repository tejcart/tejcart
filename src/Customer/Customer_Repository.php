<?php
/**
 * Customer row repository.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Order\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create and maintain rows in wp_tejcart_customers.
 *
 * The customers table is the source the admin Customers screen and
 * dashboard stats read from. It is written once on first checkout (per
 * email / per user) and refreshed with the latest billing + shipping
 * snapshot on every subsequent order.
 */
class Customer_Repository {
    /**
     * Upsert a customer row from an order.
     *
     * - Orders placed by a logged-in user are keyed by user_id (UNIQUE).
     * - Guest orders are keyed by email + user_id IS NULL so the same
     *   guest across multiple checkouts collapses into one row.
     *
     * @param Order $order Order whose customer snapshot should be saved.
     * @return int Customer row id, or 0 on failure / missing email.
     */
    public static function upsert_from_order( Order $order ): int {
        global $wpdb;

        $email = strtolower( trim( (string) $order->get_customer_email() ) );
        if ( '' === $email ) {
            return 0;
        }

        $user_id  = (int) $order->get_customer_id();
        $billing  = $order->get_billing_address();
        $shipping = $order->get_shipping_address();

        $first = isset( $billing['first_name'] ) ? sanitize_text_field( (string) $billing['first_name'] ) : '';
        $last  = isset( $billing['last_name'] ) ? sanitize_text_field( (string) $billing['last_name'] ) : '';

        if ( '' === $first && '' === $last ) {
            $parts = preg_split( '/\s+/', trim( (string) $order->get_customer_name() ), 2 );
            $first = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
            $last  = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
        }

        // Audit 07 F-20 / #1465 — capture billing phone so the analytics
        // dispatcher (Mailchimp / Klaviyo) can stop sending an empty
        // `phone` field for customers who supplied one at checkout. The
        // shipping phone is the fallback; sanitize_text_field strips
        // tags but preserves common phone-format punctuation (+, -, (, )).
        $phone_raw = '';
        if ( isset( $billing['phone'] ) && '' !== (string) $billing['phone'] ) {
            $phone_raw = (string) $billing['phone'];
        } elseif ( isset( $shipping['phone'] ) && '' !== (string) $shipping['phone'] ) {
            $phone_raw = (string) $shipping['phone'];
        }
        $phone = mb_substr( sanitize_text_field( $phone_raw ), 0, 40 );

        $table = $wpdb->prefix . 'tejcart_customers';

        // L-3: serialize concurrent upserts for the same (user_id, email)
        // tuple so two webhook-driven orders arriving in parallel can't
        // both pass the SELECT and both INSERT a duplicate guest row.
        // Falls back to the original unguarded path when the locks table
        // is not yet provisioned.
        $lock_handle = 'cust_' . hash( 'sha256', ( $user_id > 0 ? 'u' . $user_id : 'g' . $email ) );
        // Audit M-46 (Product F-011): when locks table is missing,
        // don't fall open — use a brief poll to let the other request
        // finish. This closes the TOCTOU window for concurrent webhooks.
        $use_lock    = class_exists( \TejCart\Core\Lock::class );
        $acquired    = $use_lock ? \TejCart\Core\Lock::claim( $lock_handle, 30, 'customer_upsert' ) : false;
        if ( ! $acquired ) {
            // Another request is mid-upsert for this identity. Brief poll
            // for the row it's about to write rather than racing it.
            for ( $i = 0; $i < 20; $i++ ) {
                usleep( 50 * 1000 );
                if ( ! \TejCart\Core\Lock::is_held( $lock_handle ) ) {
                    break;
                }
            }
        }

        if ( $user_id > 0 ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing_id = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing_id = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE user_id IS NULL AND email = %s LIMIT 1",
                    $email
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        // Audit #34 / 09 F-003 — opt-in encryption-at-rest for
        // address JSON blobs. Address_Crypto::encode() is a no-op
        // unless `tejcart_encrypt_addresses` is filtered to true,
        // so default merchants see identical bytes on disk.
        $billing_json  = ! empty( $billing )
            ? Address_Crypto::encode( (string) wp_json_encode( $billing ) )
            : null;
        $shipping_json = ! empty( $shipping )
            ? Address_Crypto::encode( (string) wp_json_encode( $shipping ) )
            : null;

        if ( $existing_id > 0 ) {
            // On update, preserve a previously-stored address when the new
            // order doesn't carry one (e.g. a webhook-driven order with an
            // empty billing/shipping snapshot). Overwriting with NULL would
            // erase the customer's last-known address from the admin view.
            $update = array(
                'user_id'    => $user_id > 0 ? $user_id : null,
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
            );
            $update_formats = array( '%d', '%s', '%s', '%s' );

            // Audit 07 F-20 / #1465 — only overwrite phone when the new
            // order actually carries one (same preservation rule as the
            // address JSON columns).
            if ( '' !== $phone ) {
                $update['phone']   = $phone;
                $update_formats[]  = '%s';
            }
            if ( null !== $billing_json ) {
                $update['billing_address'] = $billing_json;
                $update_formats[]          = '%s';
            }
            if ( null !== $shipping_json ) {
                $update['shipping_address'] = $shipping_json;
                $update_formats[]           = '%s';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update( $table, $update, array( 'id' => $existing_id ), $update_formats, array( '%d' ) );
            if ( $use_lock ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return $existing_id;
        }

        $data = array(
            'user_id'          => $user_id > 0 ? $user_id : null,
            'email'            => $email,
            'first_name'       => $first,
            'last_name'        => $last,
            'phone'            => $phone,
            'billing_address'  => $billing_json,
            'shipping_address' => $shipping_json,
        );

        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ok = $wpdb->insert( $table, $data, $formats );
        if ( $use_lock ) { \TejCart\Core\Lock::release( $lock_handle ); }
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Insert or update a customer from an import payload.
     *
     * Provides a documented API for bulk-import tools (store migration)
     * so they don't need direct $wpdb access against the customers
     * table. Handles JSON encoding, sanitisation, and fires hooks.
     *
     * @param array<string,mixed> $data   Column data matching the tejcart_customers schema.
     * @param int                 $id     Existing customer row ID for update, or 0 for insert.
     * @return int|\WP_Error Customer row ID on success, WP_Error on failure.
     */
    public static function import( array $data, int $id = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customers';

        $allowed_columns = [
            'user_id', 'email', 'first_name', 'last_name', 'phone',
            'billing_address', 'shipping_address', 'created_at', 'updated_at',
        ];

        $row = array_intersect_key( $data, array_flip( $allowed_columns ) );

        if ( isset( $row['email'] ) ) {
            $row['email'] = strtolower( trim( sanitize_email( (string) $row['email'] ) ) );
        }
        if ( isset( $row['first_name'] ) ) {
            $row['first_name'] = sanitize_text_field( (string) $row['first_name'] );
        }
        if ( isset( $row['last_name'] ) ) {
            $row['last_name'] = sanitize_text_field( (string) $row['last_name'] );
        }

        foreach ( [ 'billing_address', 'shipping_address' ] as $json_key ) {
            if ( array_key_exists( $json_key, $row ) && null !== $row[ $json_key ] && ! is_string( $row[ $json_key ] ) ) {
                $row[ $json_key ] = (string) wp_json_encode( $row[ $json_key ] );
            }
        }

        $row = apply_filters( 'tejcart_customer_import_data', $row, $id, $data );

        if ( $id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update( $table, $row, [ 'id' => $id ] );
            if ( false === $result ) {
                return new \WP_Error( 'db_update_failed', (string) $wpdb->last_error );
            }
            do_action( 'tejcart_customer_imported', $id, $data, 'update' );
            return $id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $wpdb->insert( $table, $row );
        if ( false === $ok ) {
            return new \WP_Error( 'db_insert_failed', (string) $wpdb->last_error );
        }

        $new_id = (int) $wpdb->insert_id;
        do_action( 'tejcart_customer_imported', $new_id, $data, 'insert' );
        return $new_id;
    }

    /**
     * Fetch the customer row owned by a WP user, with JSON address
     * columns decoded into arrays.
     *
     * Used by the checkout autofill fallback that runs when the Tier-2
     * Address_Book has no matching default for the user — the customer
     * row carries the snapshot of the buyer's most recent order, which
     * is the best available default in that case.
     *
     * @param int $user_id WP user ID.
     * @return array{id:int,user_id:?int,email:string,first_name:string,last_name:string,billing_address:array,shipping_address:array}|null
     */
    public static function get_by_user_id( int $user_id ): ?array {
        if ( $user_id <= 0 ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, user_id, email, first_name, last_name, billing_address, shipping_address FROM {$table} WHERE user_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $row ) || empty( $row ) ) {
            return null;
        }

        return array(
            'id'               => (int) $row['id'],
            'user_id'          => isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
            'email'            => (string) ( $row['email'] ?? '' ),
            'first_name'       => (string) ( $row['first_name'] ?? '' ),
            'last_name'        => (string) ( $row['last_name'] ?? '' ),
            'billing_address'  => self::decode_address_json( $row['billing_address'] ?? null ),
            'shipping_address' => self::decode_address_json( $row['shipping_address'] ?? null ),
        );
    }

    /**
     * Decode a stored address JSON blob to an associative array,
     * returning an empty array for null / invalid / anonymized payloads.
     *
     * Anonymized payloads (`{"anonymized":true}`) are treated as empty
     * so a privacy-erased customer's data does not leak back into a
     * later checkout via the autofill fallback.
     *
     * @param mixed $raw Raw column value (string|null).
     * @return array<string, mixed>
     */
    private static function decode_address_json( $raw ): array {
        if ( ! is_string( $raw ) || '' === $raw ) {
            return array();
        }
        // Decrypt before JSON-decoding so rows written by upsert_from_order()
        // via Address_Crypto::encode() round-trip. Address encryption defaults
        // to ON (Audit M-36), so without this the stored ciphertext fails
        // json_decode() and the checkout prefill fallback (inject_customer_
        // defaults) silently returns an empty address — leaving the buyer's
        // country/state unselected on their next checkout. The decode helper
        // is a no-op on plaintext, so stores with encryption disabled (and the
        // plaintext `{"anonymized":true}` marker below) are unaffected.
        $raw = class_exists( Address_Crypto::class )
            ? Address_Crypto::decode( $raw )
            : $raw;
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        if ( ! empty( $decoded['anonymized'] ) ) {
            return array();
        }
        return $decoded;
    }

    /**
     * Anonymize the customer row(s) for a given email.
     *
     * Used by the GDPR / CCPA data eraser. Mirrors the order-table
     * anonymization: PII columns are blanked, billing / shipping JSON
     * blobs are replaced with `{"anonymized":true}`, and the user_id
     * link is preserved so the row remains greppable by admins (it is
     * not deleted because rows with a `user_id` carry the UNIQUE
     * constraint and re-deleting would orphan the wp_users row).
     *
     * @param string $email Email to match (case-insensitive).
     * @return int Number of customer rows updated.
     */
    public static function anonymize_by_email( string $email ): int {
        $email = strtolower( trim( $email ) );
        if ( '' === $email ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_customers';
        $anon  = wp_json_encode( array( 'anonymized' => true ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            array(
                'email'            => '',
                'first_name'       => '',
                'last_name'        => '',
                'billing_address'  => $anon,
                'shipping_address' => $anon,
            ),
            array( 'email' => $email ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%s' )
        );

        return (int) $updated;
    }

    /**
     * Claim any guest customer rows for a newly registered user.
     *
     * Mirrors Guest_Order_Linker for the customers table: when a buyer
     * registers, attach their user_id to the guest row that already
     * carries their email and remove any duplicate guest rows so the
     * UNIQUE(user_id) constraint can't be violated later.
     *
     * @param int    $user_id Newly registered user ID.
     * @param string $email   Email to match against customer rows.
     * @return int Customer row id now owned by the user, or 0 if none.
     */
    public static function link_email_to_user( int $user_id, string $email ): int {
        if ( $user_id <= 0 ) {
            return 0;
        }

        $email = strtolower( trim( $email ) );
        if ( '' === $email ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $user_row_id = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $user_row_id > 0 ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE user_id IS NULL AND email = %s",
                    $email
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            return $user_row_id;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $guest_id = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id IS NULL AND email = %s ORDER BY id ASC LIMIT 1",
                $email
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $guest_id <= 0 ) {
            return 0;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->update(
            $table,
            array( 'user_id' => $user_id ),
            array( 'id' => $guest_id ),
            array( '%d' ),
            array( '%d' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id IS NULL AND email = %s AND id <> %d",
                $email,
                $guest_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $guest_id;
    }

    /**
     * One-shot populate of tejcart_customers from existing orders.
     *
     * Two INSERT...SELECT passes: one for user-linked orders keyed by
     * customer_id (unique), one for guest orders keyed by lowercased
     * email. Each pass picks the most recent order per group so the
     * address snapshot matches the buyer's latest checkout.
     *
     * Safe to call multiple times — the LEFT JOIN guard prevents
     * re-inserting rows that already exist. Callers still typically
     * flag completion via an option so the work isn't repeated on
     * every admin request.
     *
     * @return void
     */
    public static function backfill_from_orders(): void {
        global $wpdb;

        $customers = $wpdb->prefix . 'tejcart_customers';
        $orders    = $wpdb->prefix . 'tejcart_orders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $customers_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $customers ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $orders_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders ) );

        if ( $customers_exists !== $customers || $orders_exists !== $orders ) {
            return;
        }

        // billing_address may be AES-256-GCM ciphertext (not JSON) when
        // address encryption is enabled (`tejcart_encrypt_addresses`, on by
        // default — Audit M-36). Wrap each column in
        // `IF(JSON_VALID(col), col, '{}')` so JSON_EXTRACT never errors on an
        // encrypted row (MySQL raises ER_INVALID_JSON_TEXT otherwise): the
        // name columns fall back to '' for encrypted rows while the raw blob
        // is still copied verbatim and decrypted on read via
        // Address_Crypto::decode().
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "INSERT INTO {$customers}
                 (user_id, email, first_name, last_name, billing_address, shipping_address, created_at, updated_at)
             SELECT o.customer_id,
                    LOWER(o.customer_email),
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.billing_address), o.billing_address, '{}'), '$.first_name')), ''),
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.billing_address), o.billing_address, '{}'), '$.last_name')), ''),
                    o.billing_address,
                    o.shipping_address,
                    o.created_at,
                    o.updated_at
               FROM {$orders} o
               JOIN (
                   SELECT customer_id, MAX(id) AS max_id
                     FROM {$orders}
                    WHERE customer_id IS NOT NULL AND customer_id > 0
                 GROUP BY customer_id
               ) latest
                 ON latest.customer_id = o.customer_id
                AND latest.max_id      = o.id
          LEFT JOIN {$customers} c ON c.user_id = o.customer_id
              WHERE c.id IS NULL"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "INSERT INTO {$customers}
                 (user_id, email, first_name, last_name, billing_address, shipping_address, created_at, updated_at)
             SELECT NULL,
                    LOWER(o.customer_email),
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.billing_address), o.billing_address, '{}'), '$.first_name')), ''),
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.billing_address), o.billing_address, '{}'), '$.last_name')), ''),
                    o.billing_address,
                    o.shipping_address,
                    o.created_at,
                    o.updated_at
               FROM {$orders} o
               JOIN (
                   SELECT LOWER(customer_email) AS email_lc, MAX(id) AS max_id
                     FROM {$orders}
                    WHERE (customer_id IS NULL OR customer_id = 0)
                      AND customer_email <> ''
                 GROUP BY LOWER(customer_email)
               ) latest
                 ON latest.email_lc = LOWER(o.customer_email)
                AND latest.max_id  = o.id
          LEFT JOIN {$customers} c
                 ON c.user_id IS NULL
                AND c.email   = LOWER(o.customer_email)
              WHERE c.id IS NULL"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
}
