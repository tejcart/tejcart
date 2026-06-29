<?php
/**
 * GDPR / CCPA Data Eraser.
 *
 * Integrates TejCart data sources into WordPress's personal-data erase tool
 * (Tools → Erase Personal Data). Financial records are anonymized in place
 * rather than deleted — most tax authorities require them retained.
 *
 * @package TejCart\Privacy
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace TejCart\Privacy;

use TejCart\Customer\Customer_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register erasers for each user-linked data source.
 */
class Data_Eraser {
    /**
     * Register all erasers via wp_privacy_personal_data_erasers.
     */
    public function init(): void {
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
    }

    /**
     * Register each eraser callback.
     *
     * @param array $erasers Existing erasers.
     * @return array
     */
    public function register_erasers( $erasers ): array {
        $erasers['tejcart-orders']           = array(
            'eraser_friendly_name' => __( 'TejCart Orders', 'tejcart' ),
            'callback'             => array( $this, 'erase_orders' ),
        );
        $erasers['tejcart-customer-profile'] = array(
            'eraser_friendly_name' => __( 'TejCart Customer Profile', 'tejcart' ),
            'callback'             => array( $this, 'erase_customer_profile' ),
        );
        $erasers['tejcart-addresses']        = array(
            'eraser_friendly_name' => __( 'TejCart Address Book', 'tejcart' ),
            'callback'             => array( $this, 'erase_addresses' ),
        );
        $erasers['tejcart-payment-methods']  = array(
            'eraser_friendly_name' => __( 'TejCart Saved Payment Methods', 'tejcart' ),
            'callback'             => array( $this, 'erase_payment_methods' ),
        );
        $erasers['tejcart-wishlist']         = array(
            'eraser_friendly_name' => __( 'TejCart Wishlist', 'tejcart' ),
            'callback'             => array( $this, 'erase_wishlist' ),
        );
        $erasers['tejcart-recently-viewed']  = array(
            'eraser_friendly_name' => __( 'TejCart Recently Viewed', 'tejcart' ),
            'callback'             => array( $this, 'erase_recently_viewed' ),
        );
        $erasers['tejcart-abandoned-carts']  = array(
            'eraser_friendly_name' => __( 'TejCart Abandoned Carts', 'tejcart' ),
            'callback'             => array( $this, 'erase_abandoned_carts' ),
        );
        $erasers['tejcart-downloads']        = array(
            'eraser_friendly_name' => __( 'TejCart Download Grants', 'tejcart' ),
            'callback'             => array( $this, 'erase_downloads' ),
        );
        // Product reviews live in the tejcart_product_reviews custom
        // table. Anonymizes PII columns while preserving the row so
        // product rating aggregates are not affected.
        $erasers['tejcart-reviews']          = array(
            'eraser_friendly_name' => __( 'TejCart Product Reviews', 'tejcart' ),
            'callback'             => array( $this, 'erase_product_reviews' ),
        );
        // Audit #90 / 09 F-011 — _tejcart_saved_cart user_meta is
        // written on logout and carries guest-checkout PII captured
        // before login. Paired exporter at
        // Data_Exporter::export_saved_cart().
        $erasers['tejcart-saved-cart']       = array(
            'eraser_friendly_name' => __( 'TejCart Saved Cart', 'tejcart' ),
            'callback'             => array( $this, 'erase_saved_cart' ),
        );

        return $erasers;
    }

    /**
     * Delete the `_tejcart_saved_cart` user_meta for the given email.
     *
     * Mirrors `Cart_Session::merge_saved_cart_on_login()` which deletes
     * this same key after restoring the cart — so on the next login
     * there is nothing to restore. Audit #90 / 09 F-011.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public function erase_saved_cart( $email_address, $page = 1 ): array {
        unset( $page );

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return $this->noop();
        }

        $had = (bool) get_user_meta( (int) $user->ID, '_tejcart_saved_cart', true );
        delete_user_meta( (int) $user->ID, '_tejcart_saved_cart' );

        return array(
            'items_removed'  => $had,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Anonymise the user's TejCart product reviews. Comment body is
     * redacted; identity columns are blanked. The comment row itself
     * survives so the per-product star average / count don't suddenly
     * drop (which would otherwise leak that the user existed).
     *
     * Audit #37 / 09 F-006.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public function erase_product_reviews( $email_address, $page = 1 ): array {
        global $wpdb;

        $result = array(
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );

        $email = sanitize_email( (string) $email_address );
        if ( '' === $email ) {
            return $result;
        }

        $table    = $wpdb->prefix . 'tejcart_product_reviews';
        $page     = max( 1, (int) $page );
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $review_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE author_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $email,
                $per_page,
                $offset
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $review_ids ) || empty( $review_ids ) ) {
            return $result;
        }

        foreach ( $review_ids as $review_id ) {
            $review_id = (int) $review_id;
            if ( $review_id <= 0 ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $updated = $wpdb->update(
                $table,
                array(
                    'author_name'  => '',
                    'author_email' => '',
                    'author_ip'    => '',
                    'author_agent' => '',
                    'content'      => __( '[Review content removed at user request.]', 'tejcart' ),
                ),
                array( 'id' => $review_id )
            );
            if ( false !== $updated ) {
                $result['items_removed'] = true;
            } else {
                $result['items_retained'] = true;
                $result['messages'][]     = (string) $wpdb->last_error;
            }
        }

        $result['done'] = count( $review_ids ) < $per_page;
        return $result;
    }

    /**
     * Anonymize PII on orders belonging to the given email. Financial
     * records and line items are retained for tax compliance.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_orders( $email_address, $page = 1 ): array {
        global $wpdb;

        unset( $page );

        $email  = sanitize_email( (string) $email_address );
        $limit  = 50;
        $table  = $wpdb->prefix . 'tejcart_orders';
        $items_removed_or_retained = 0;

        // Always read the FIRST page of still-matching orders — no OFFSET.
        // Each batch we anonymize below sets customer_email='' so those rows
        // drop out of this WHERE, and WordPress re-invokes the eraser
        // (page 2, 3, …) until `done` is true; the anonymization is itself the
        // cursor. An OFFSET here would skip the rows the previous page's
        // anonymization shifted into the offset window, leaving subjects with
        // more than 50 orders only half-erased.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d",
            $email,
            $limit
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        foreach ( (array) $order_ids as $order_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array(
                    'customer_email'   => '',
                    'customer_name'    => '[deleted]',
                    'customer_id'      => null,
                    'billing_address'  => wp_json_encode( array( 'anonymized' => true ) ),
                    'shipping_address' => wp_json_encode( array( 'anonymized' => true ) ),
                    'ip_address'       => '',
                    'customer_note'    => '',
                ),
                array( 'id' => (int) $order_id ),
                array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            $items_removed_or_retained++;
        }

        $done = count( (array) $order_ids ) < $limit;

        return array(
            'items_removed'  => false,
            'items_retained' => $items_removed_or_retained > 0,
            'messages'       => $items_removed_or_retained > 0
                ? array( __( 'Order PII anonymized; financial records retained for tax compliance.', 'tejcart' ) )
                : array(),
            'done'           => $done,
        );
    }

    /**
     * Anonymize the customer-profile row (wp_tejcart_customers) for the
     * given email. The row drives the admin Customers screen and the
     * checkout autofill fallback — leaving the JSON address blobs in
     * place would let erased PII reappear at the next checkout.
     *
     * Mirrors the order-table anonymization shape: email / name blanked,
     * billing & shipping JSON replaced with `{"anonymized":true}`. The
     * row itself is retained (not deleted) because:
     *  - `user_id` is UNIQUE — deletion would orphan the wp_users link
     *    if the underlying WP user remains.
     *  - Admin reporting expects a stable customer ID lineage.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_customer_profile( $email_address, $page = 1 ): array {
        unset( $page );

        $email = strtolower( trim( sanitize_email( (string) $email_address ) ) );
        if ( '' === $email ) {
            return $this->noop();
        }

        $updated = Customer_Repository::anonymize_by_email( $email );

        return array(
            'items_removed'  => false,
            'items_retained' => $updated > 0,
            'messages'       => $updated > 0
                ? array( __( 'Customer profile anonymized; row retained for reporting integrity.', 'tejcart' ) )
                : array(),
            'done'           => true,
        );
    }

    /**
     * Delete address-book entries for the user.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_addresses( $email_address, $page = 1 ): array {
        global $wpdb;

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return $this->noop();
        }

        $table = $wpdb->prefix . 'tejcart_addresses';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = (int) $wpdb->delete( $table, array( 'user_id' => (int) $user->ID ), array( '%d' ) );

        return array(
            'items_removed'  => $deleted > 0,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Delete saved payment methods.
     *
     * Best-effort revokes the vault token at PayPal before wiping local data.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_payment_methods( $email_address, $page = 1 ): array {
        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return $this->noop();
        }

        $methods = get_user_meta( (int) $user->ID, 'tejcart_saved_payment_methods', true );
        $methods = is_array( $methods ) ? $methods : array();

        $paypal_available = class_exists( \TejCart\Gateways\PayPal\PayPal_Gateway::class );

        foreach ( $methods as $method ) {
            if ( ! is_array( $method ) ) {
                continue;
            }

            // The stored token_id is AES-GCM ciphertext; PayPal needs the
            // DECRYPTED vault token to revoke it. The previous code passed the
            // ciphertext (or, worse, the internal method UUID), so the revoke
            // silently failed and the token kept living at PayPal — a GDPR
            // Art. 17 erasure failure. get_token_id() decrypts (and tolerates
            // legacy plaintext).
            $plain_token = \TejCart\Customer\Payment_Methods::get_token_id( $method );

            if ( $paypal_available && '' !== $plain_token ) {
                try {
                    $api = \TejCart\Gateways\PayPal\PayPal_Gateway::get_shared_api();
                    if ( $api && method_exists( $api, 'delete_payment_token' ) ) {
                        $api->delete_payment_token( $plain_token );
                    }
                } catch ( \Throwable $e ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( 'PayPal token revoke failed during erasure: ' . $e->getMessage(), 'warning' );
                    }
                }
            }

            // Remove the webhook lookup-index usermeta rows that point at this
            // token (both variants delete_method() maintains) so no orphaned
            // PII-linking metadata survives the erasure.
            $token_hash = (string) ( $method['token_hash'] ?? '' );
            if ( '' !== $token_hash ) {
                delete_user_meta( (int) $user->ID, '_tejcart_pp_token_' . $token_hash );
            }
            if ( '' !== $plain_token ) {
                delete_user_meta( (int) $user->ID, '_tejcart_pp_token_' . \TejCart\Security\Crypto::hash( $plain_token ) );
            }
        }

        delete_user_meta( (int) $user->ID, 'tejcart_saved_payment_methods' );

        return array(
            'items_removed'  => ! empty( $methods ),
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Delete wishlist.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_wishlist( $email_address, $page = 1 ): array {
        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return $this->noop();
        }

        $had = (bool) get_user_meta( (int) $user->ID, '_tejcart_wishlist', true );
        delete_user_meta( (int) $user->ID, '_tejcart_wishlist' );

        return array(
            'items_removed'  => $had,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Recently-viewed is cookie-only; clear the cookie for the current
     * request. Browser-side cookies on other devices expire naturally.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_recently_viewed( $email_address, $page = 1 ): array {
        $had = ! empty( $_COOKIE['tejcart_recently_viewed'] );
        if ( $had && ! headers_sent() ) {
            setcookie( 'tejcart_recently_viewed', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN );
        }
        unset( $_COOKIE['tejcart_recently_viewed'] );

        return array(
            'items_removed'  => $had,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Delete abandoned carts.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_abandoned_carts( $email_address, $page = 1 ): array {
        global $wpdb;

        $email = sanitize_email( (string) $email_address );
        $table = $wpdb->prefix . 'tejcart_abandoned_carts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return $this->noop();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = (int) $wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) );

        return array(
            'items_removed'  => $deleted > 0,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Revoke download grants for the user (clear user_id association).
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function erase_downloads( $email_address, $page = 1 ): array {
        global $wpdb;

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return $this->noop();
        }

        $table = $wpdb->prefix . 'tejcart_download_permissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return $this->noop();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = (int) $wpdb->update(
            $table,
            array( 'user_id' => null, 'access_expires' => current_time( 'mysql', 1 ) ),
            array( 'user_id' => (int) $user->ID ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        return array(
            'items_removed'  => $updated > 0,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * Default empty response used when no matching data exists.
     */
    private function noop(): array {
        return array(
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }
}
