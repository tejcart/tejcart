<?php
/**
 * REST API consumer key / secret store + authenticator.
 *
 * @package TejCart\API
 */

declare( strict_types=1 );

namespace TejCart\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data store + authenticator for consumer-key/secret pairs used to
 * authenticate REST requests.
 *
 * Keys are hashed at rest (SHA-256) so a DB leak does not expose
 * working credentials; the plaintext secret is shown exactly once
 * when the key is first generated.
 */
class API_Keys {
    public const PERMISSION_READ       = 'read';
    public const PERMISSION_WRITE      = 'write';
    public const PERMISSION_READ_WRITE = 'read_write';

    /**
     * F-SEC-002: request-scoped permission level for the authenticated API key.
     *
     * Stored as a private static property instead of $GLOBALS so that no
     * external plugin can escalate a read-only key by writing to a super-global.
     * Reset to null at the start of each authenticate() call so stale values
     * from a previous (e.g. wp-cron) context cannot bleed through.
     *
     * @var string|null
     */
    private static ?string $current_key_permissions = null;

    /**
     * Register the REST authentication hook.
     *
     * @return void
     */
    public function init(): void {
        add_filter( 'determine_current_user', array( $this, 'authenticate' ), 10 );
        add_filter( 'rest_authentication_errors', array( $this, 'enforce_permissions' ), 99 );
    }

    /**
     * Table name helper.
     *
     * @return string
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_api_keys';
    }

    /**
     * Generate a new consumer key / secret.
     *
     * Returns an array with the PLAINTEXT credentials in addition to
     * the stored record. The caller must display these once and then
     * discard the plaintext — we never store them.
     *
     * @param int    $user_id     WP user ID to associate with the key.
     * @param string $description Friendly label.
     * @param string $permissions read | write | read_write.
     * @return array{id:int, consumer_key:string, consumer_secret:string, truncated_key:string, permissions:string, description:string}
     */
    public static function create( int $user_id, string $description, string $permissions ): array {
        global $wpdb;

        $permissions   = self::normalize_permissions( $permissions );
        $plain_key     = 'ck_' . bin2hex( random_bytes( 16 ) );
        $plain_secret  = 'cs_' . bin2hex( random_bytes( 20 ) );
        $truncated_key = substr( $plain_key, -7 );

        $hashed_key    = self::hash_credential( $plain_key );
        $hashed_secret = self::hash_credential( $plain_secret );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            self::table(),
            array(
                'user_id'         => $user_id,
                'description'     => sanitize_text_field( $description ),
                'permissions'     => $permissions,
                'consumer_key'    => $hashed_key,
                'consumer_secret' => $hashed_secret,
                'truncated_key'   => $truncated_key,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return array(
            'id'              => (int) $wpdb->insert_id,
            'consumer_key'    => $plain_key,
            'consumer_secret' => $plain_secret,
            'truncated_key'   => $truncated_key,
            'permissions'     => $permissions,
            'description'     => $description,
        );
    }

    /**
     * Revoke (soft-delete) an API key.
     *
     * @param int $id Key ID.
     * @return bool
     */
    public static function revoke( int $id ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $affected = $wpdb->update(
            self::table(),
            array( 'revoked_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );

        return false !== $affected;
    }

    /**
     * Fetch every active (non-revoked) key for display.
     *
     * @return array
     */
    public static function list_keys(): array {
        global $wpdb;

        $table = self::table();
        // $table is the plugin-owned `{$wpdb->prefix}tejcart_api_keys` table; no user input.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( "SELECT id, user_id, description, permissions, truncated_key, last_access, created_at FROM {$table} WHERE revoked_at IS NULL ORDER BY created_at DESC", ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Authenticator. If a valid `Authorization: Basic` header carries a
     * live consumer key/secret, return the associated user ID; otherwise
     * leave the default behaviour intact.
     *
     * @param int|false $user_id Result from previous filters.
     * @return int|false
     */
    public function authenticate( $user_id ) {
        // F-SEC-002: reset per-request so stale permissions from a prior
        // context (e.g. wp-cron processing multiple requests in the same
        // PHP process) cannot bleed into this authentication check.
        self::$current_key_permissions = null;

        if ( ! empty( $user_id ) ) {
            return $user_id;
        }

        if ( ! self::request_targets_tejcart() ) {
            return $user_id;
        }

        [ $key, $secret ] = self::extract_credentials();
        if ( '' === $key || '' === $secret ) {
            return $user_id;
        }

        // Per-(key, IP) brute-force throttle. After 10 failed attempts
        // inside a 5-minute window from the SAME source IP, lock that
        // bucket for the rest of the window so an attacker cannot
        // enumerate the secret. The bucket key is the keyed hash of
        // the consumer key joined to the requesting IP, so:
        //   - Probing a non-existent key still consumes a bucket and
        //     cannot be used to enumerate which keys exist.
        //   - An attacker who has obtained a victim's plaintext consumer
        //     key (e.g. via log scraping, DB leak) cannot lock the
        //     legitimate integration out by sending bad-secret requests
        //     from elsewhere — each attacker IP fills only its own
        //     bucket, the merchant's normal traffic IP is unaffected.
        // See review finding H-1 for the DoS-amplification threat model.
        $hashed_key      = self::hash_credential( $key );
        $salt_key        = self::salt_hash_credential( $key );
        $legacy_key      = self::legacy_hash_credential( $key );
        $client_ip       = (string) \TejCart\Security\Rate_Limiter::get_client_ip();
        $throttle_bucket = 'api_key:' . $hashed_key . '|' . $client_ip;
        // Per-(key, IP) bucket prevents enumeration of any single key.
        // The global per-IP bucket below prevents an attacker from
        // probing N different keys for N × 10 effective attempts before
        // any single bucket trips.
        // Audit M-13 (API-Security M-7): switched from separate
        // is_rate_limited() + record() to atomic check_and_record()
        // to close the TOCTOU window between check and record.
        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'api_key_auth', $throttle_bucket, 10, 300 ) ) {
            return $user_id;
        }
        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'api_key_auth_global', $client_ip, 50, 300 ) ) {
            return $user_id;
        }

        global $wpdb;

        $table = self::table();
        // $table is the plugin-owned `{$wpdb->prefix}tejcart_api_keys` table; no user input.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // M-05: lookup tries the new HMAC-keyed hash first; rows that
        // were inserted before the migration use the legacy SHA-256
        // form, so a second lookup against `legacy_key` covers them
        // until the next successful auth lazily rewrites the row.
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, user_id, permissions, consumer_key, consumer_secret FROM {$table} WHERE consumer_key = %s AND revoked_at IS NULL LIMIT 1",
                $hashed_key
            ),
            ARRAY_A
        );
        if ( ! $row && $salt_key !== $hashed_key ) {
            // Pre-managed-secret rows: consumer_key was HMAC-keyed by
            // wp_salt('auth'). A matched row is lazily rewritten below.
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id, user_id, permissions, consumer_key, consumer_secret FROM {$table} WHERE consumer_key = %s AND revoked_at IS NULL LIMIT 1",
                    $salt_key
                ),
                ARRAY_A
            );
        }
        if ( ! $row ) {
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id, user_id, permissions, consumer_key, consumer_secret FROM {$table} WHERE consumer_key = %s AND revoked_at IS NULL LIMIT 1",
                    $legacy_key
                ),
                ARRAY_A
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            // Already recorded by check_and_record above.
            return $user_id;
        }

        $stored_secret = (string) $row['consumer_secret'];
        $hashed_secret = self::hash_credential( $secret );
        $salt_secret   = self::salt_hash_credential( $secret );
        $legacy_secret = self::legacy_hash_credential( $secret );

        $matched_new    = hash_equals( $stored_secret, $hashed_secret );
        $matched_legacy = ! $matched_new
            && ( hash_equals( $stored_secret, $salt_secret ) || hash_equals( $stored_secret, $legacy_secret ) );

        if ( ! $matched_new && ! $matched_legacy ) {
            // Already recorded by check_and_record above.
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'API key auth failed for key id %d.', (int) $row['id'] ),
                    'warning'
                );
            }
            return $user_id;
        }

        // Successful auth — clear any pending failed-attempt counter so a
        // legitimate user is not penalised for an earlier typo.
        \TejCart\Security\Rate_Limiter::reset( 'api_key_auth', $throttle_bucket );

        // M-05: if either column matched the legacy form, lazily
        // rewrite the row in the new HMAC-keyed format. Idempotent —
        // already-migrated rows compare equal and skip the write.
        if ( $matched_legacy || $stored_secret !== $hashed_secret || (string) $row['consumer_key'] !== $hashed_key ) {
            self::maybe_rewrite_legacy_row(
                (int) $row['id'],
                (string) $row['consumer_key'],
                $hashed_key,
                $stored_secret,
                $hashed_secret
            );
        }

        // F-SEC-002: store in private static, not a mutable super-global.
        self::$current_key_permissions = $row['permissions'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            self::table(),
            array( 'last_access' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $row['id'] ),
            array( '%s' ),
            array( '%d' )
        );

        return (int) $row['user_id'];
    }

    /**
     * Reject writes from read-only keys.
     *
     * @param \WP_Error|null|bool $error Previous auth errors.
     * @return \WP_Error|null|bool
     */
    public function enforce_permissions( $error ) {
        if ( is_wp_error( $error ) ) {
            return $error;
        }

        // F-SEC-002: read from private static, not the mutable super-global.
        if ( empty( self::$current_key_permissions ) ) {
            return $error;
        }

        if ( ! self::request_targets_tejcart() ) {
            return $error;
        }

        $method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        $permissions = (string) self::$current_key_permissions;
        $is_write    = in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
        $is_read     = 'GET' === $method || 'HEAD' === $method || 'OPTIONS' === $method;

        if ( $is_write && self::PERMISSION_READ === $permissions ) {
            return new \WP_Error(
                'tejcart_rest_key_read_only',
                __( 'This API key is read-only.', 'tejcart' ),
                array( 'status' => 401 )
            );
        }

        if ( $is_read && self::PERMISSION_WRITE === $permissions ) {
            return new \WP_Error(
                'tejcart_rest_key_write_only',
                __( 'This API key is write-only.', 'tejcart' ),
                array( 'status' => 401 )
            );
        }

        return $error;
    }

    /**
     * Extract consumer key / secret from the current request.
     *
     * @return string[] Tuple of [ key, secret ].
     */
    private static function extract_credentials(): array {
        $auth = '';
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
        } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
        }

        if ( $auth && 0 === stripos( $auth, 'basic ' ) ) {
            $decoded = base64_decode( substr( $auth, 6 ), true );
            if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
                [ $key, $secret ] = explode( ':', $decoded, 2 );
                return array( trim( (string) $key ), trim( (string) $secret ) );
            }
        }

        /**
         * Query-string credentials are disabled by default. They leak into
         * web-server access logs, reverse-proxy logs, browser history,
         * and Referer headers, even on HTTPS. Operators that need to opt in
         * for a one-off integration test can return true from
         * `tejcart_allow_insecure_api_key_query`; on non-HTTPS requests the
         * fallback stays disabled regardless of the filter.
         */
        if ( ! is_ssl() ) {
            return array( '', '' );
        }

        if ( ! apply_filters( 'tejcart_allow_insecure_api_key_query', false ) ) {
            return array( '', '' );
        }

        // F-SEC-007: credentials are binary-safe opaque tokens — using
        // sanitize_text_field() would strip HTML entities and normalise
        // whitespace, silently mangling any future key format that contains
        // those byte sequences.  wp_unslash() is sufficient: the values are
        // never emitted to the DOM and are immediately hashed via hash_equals().
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $key    = isset( $_GET['consumer_key'] )    ? wp_unslash( $_GET['consumer_key'] )    : '';
        $secret = isset( $_GET['consumer_secret'] ) ? wp_unslash( $_GET['consumer_secret'] ) : '';
        // phpcs:enable

        return array( (string) $key, (string) $secret );
    }

    /**
     * Whether the current request is targeting a TejCart REST route.
     *
     * @return bool
     */
    private static function request_targets_tejcart(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rest_route = isset( $_REQUEST['rest_route'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['rest_route'] ) ) : '';
        $uri        = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return ( '' !== $rest_route && 0 === strpos( $rest_route, '/tejcart/' ) )
            || false !== strpos( $uri, '/wp-json/tejcart/' );
    }

    /**
     * Coerce an untrusted permission string into a valid constant.
     *
     * @param string $permissions Raw input.
     * @return string
     */
    public static function normalize_permissions( string $permissions ): string {
        $allowed = array( self::PERMISSION_READ, self::PERMISSION_WRITE, self::PERMISSION_READ_WRITE );
        return in_array( $permissions, $allowed, true ) ? $permissions : self::PERMISSION_READ;
    }

    /**
     * Keyed hash for consumer key/secret at-rest storage.
     *
     * Keyed by the rotation-stable {@see \TejCart\Security\Key_Manager}
     * secret — NOT `wp_salt('auth')` — so resetting WordPress salts no
     * longer invalidates issued API credentials (wp.org review). A DB
     * exfiltration on its own still cannot build a precomputed table for
     * the public ck_/cs_ format. Only when no managed secret can be
     * resolved do we fall back to the stable WP-salt HMAC
     * {@see self::salt_hash_credential()}.
     *
     * @param string $plain Plaintext credential.
     * @return string
     */
    private static function hash_credential( string $plain ): string {
        if ( \TejCart\Security\Key_Manager::is_available() ) {
            return hash_hmac( 'sha256', $plain, \TejCart\Security\Key_Manager::hmac_key( 'tejcart|api-keys|v2' ) );
        }
        return self::salt_hash_credential( $plain );
    }

    /**
     * Legacy WP-salt-keyed credential hash (`wp_salt('auth')`). Retained
     * as a verify-time fallback so credentials issued before the
     * managed-secret switch keep authenticating; a successful match
     * lazily rewrites the row to {@see self::hash_credential()}.
     *
     * @param string $plain Plaintext credential.
     * @return string
     */
    private static function salt_hash_credential( string $plain ): string {
        $salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        if ( '' === $salt ) {
            // wp_salt unavailable (extremely rare — WP not bootstrapped).
            // Bare hash here is no worse than the oldest legacy format.
            return hash( 'sha256', $plain );
        }
        return hash_hmac( 'sha256', $plain, $salt );
    }

    /**
     * M-05: legacy bare-SHA-256 hash. Retained for the migration
     * grace window so credentials issued before the HMAC switch
     * continue to authenticate. Successful auth lazily rewrites the
     * row to the new format.
     *
     * @param string $plain Plaintext credential.
     * @return string
     */
    private static function legacy_hash_credential( string $plain ): string {
        return hash( 'sha256', $plain );
    }

    /**
     * M-05: lazy migration write. Called on the first successful auth
     * after the HMAC switch — overwrites consumer_key / consumer_secret
     * with the keyed-hash form so the next request hits the primary
     * lookup path. Idempotent: skips the UPDATE when the row already
     * matches the new format.
     *
     * @param int    $id            Row primary key.
     * @param string $stored_key    consumer_key as currently stored.
     * @param string $hashed_key    Expected new consumer_key.
     * @param string $stored_secret consumer_secret as currently stored.
     * @param string $hashed_secret Expected new consumer_secret.
     */
    private static function maybe_rewrite_legacy_row( int $id, string $stored_key, string $hashed_key, string $stored_secret, string $hashed_secret ): void {
        if ( $stored_key === $hashed_key && $stored_secret === $hashed_secret ) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            self::table(),
            array(
                'consumer_key'    => $hashed_key,
                'consumer_secret' => $hashed_secret,
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }
}
