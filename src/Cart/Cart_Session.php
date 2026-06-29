<?php
/**
 * Cart Session Persistence
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages cart session storage using the wp_tejcart_sessions table.
 *
 * Generates or retrieves a session key via cookie and persists
 * arbitrary session data to the database.
 */
class Cart_Session {
    /**
     * Name of the session cookie.
     *
     * @var string
     */
    const COOKIE_NAME = 'tejcart_session';

    /**
     * Session lifetime in seconds (48 hours).
     *
     * @var int
     */
    const SESSION_EXPIRY = 172800;

    /**
     * Unique session key.
     *
     * @var string
     */
    private $session_key;

    /**
     * Session key minted for the current PHP request.
     *
     * When a request arrives with no session cookie (e.g. a visitor's very
     * first add-to-cart), every Cart_Session instance constructed during that
     * request must converge on ONE key. Otherwise each `new Cart()` mints its
     * own key and emits its own `Set-Cookie`; the browser keeps the last one
     * while the item was persisted under a different key, so the next read
     * returns an empty cart. The REST controllers construct a fresh Cart per
     * call (and hooks resolve the shared cart too), so two-or-more instances
     * per request is the norm, not the exception. Static for the request
     * lifetime; a new PHP request (new cookie state) starts fresh.
     *
     * @var string|null
     */
    private static $request_session_key = null;

    /**
     * In-memory session data.
     *
     * @var array
     */
    private $data = array();

    /**
     * Whether the session data has been loaded from the database.
     *
     * @var bool
     */
    private $loaded = false;

    /**
     * Whether in-memory data has been modified and needs persisting.
     *
     * @var bool
     */
    private bool $dirty = false;

    /**
     * Constructor.
     *
     * Reads the session key from the cookie or generates a new one,
     * then sets the cookie for subsequent requests.
     */
    public function __construct() {
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $this->session_key = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

            if ( ! $this->validate_fingerprint() ) {
                $this->destroy();
                $this->session_key = $this->generate_session_key();
            }
        } elseif ( null !== self::$request_session_key && self::request_key_sharing_enabled() ) {
            // No cookie yet, but an earlier Cart_Session in THIS request
            // already minted a key — reuse it so we don't emit a second,
            // conflicting Set-Cookie and split the cart across two keys.
            $this->session_key = self::$request_session_key;
        } else {
            $this->session_key = $this->generate_session_key();
        }

        // Remember the key for any further Cart_Session built in this request.
        // Skipped under PHPUnit (TEJCART_TESTING), where every test method runs
        // in one long-lived process: a process-lifetime static would otherwise
        // leak one test's key into the next test's freshly-constructed session.
        if ( self::request_key_sharing_enabled() ) {
            self::$request_session_key = $this->session_key;
        }

        $this->set_cookie();

        add_action( 'wp_login', array( $this, 'regenerate_on_login' ), 10, 0 );
        // F-H5 / #928: persist the current cart to user_meta on logout
        // so a returning user with no cookie / a fresh browser still
        // sees their cart. On wp_login we merge it back into whatever
        // guest cart the new session is carrying. Both handlers no-op
        // when there's nothing to do.
        add_action( 'wp_logout',  array( $this, 'persist_cart_on_logout' ), 10, 1 );
        add_action( 'wp_login',   array( $this, 'merge_saved_cart_on_login' ), 20, 2 );

        register_shutdown_function( array( $this, 'maybe_persist' ) );
    }

    /**
     * Persist the live cart payload to user_meta when a logged-in user
     * logs out. Allows a fresh-browser sign-in to restore the cart.
     *
     * @param int $user_id Departing user's ID.
     * @return void
     */
    public function persist_cart_on_logout( $user_id ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }
        $this->maybe_load();
        $cart_payload = isset( $this->data['cart'] ) ? $this->data['cart'] : null;
        if ( ! is_array( $cart_payload ) || empty( $cart_payload ) ) {
            return;
        }
        // Keep the whole session payload (cart + coupons + shipping
        // destination) for high-fidelity restore.
        update_user_meta(
            $user_id,
            '_tejcart_saved_cart',
            array(
                'data'       => $this->data,
                'saved_at'   => time(),
            )
        );
    }

    /**
     * On login, merge a previously-saved cart from user_meta with the
     * incoming guest cart. Guest-cart line items take precedence on
     * collision (the buyer's most recent intent wins), and quantities
     * are summed. Coupons and shipping destination from the saved cart
     * are applied only when the guest cart didn't carry them.
     *
     * @param string  $user_login Login name (unused).
     * @param \WP_User $user      Authenticated user.
     * @return void
     */
    public function merge_saved_cart_on_login( $user_login, $user = null ): void {
        $user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
        if ( $user_id <= 0 ) {
            return;
        }

        $saved = get_user_meta( $user_id, '_tejcart_saved_cart', true );
        if ( ! is_array( $saved ) || empty( $saved['data'] ) || ! is_array( $saved['data'] ) ) {
            return;
        }

        // Audit L-35 (Cart F-017): discard saved carts older than 30
        // days. A buyer who hasn't logged in for months gets stale
        // products/prices re-attached which confuses at checkout.
        $saved_at = isset( $saved['saved_at'] ) ? (int) $saved['saved_at'] : 0;
        $ttl      = (int) apply_filters( 'tejcart_saved_cart_ttl', 30 * DAY_IN_SECONDS );
        if ( $saved_at > 0 && ( time() - $saved_at ) > $ttl ) {
            delete_user_meta( $user_id, '_tejcart_saved_cart' );
            return;
        }

        $saved_data = $saved['data'];

        $this->maybe_load();
        $guest_cart   = isset( $this->data['cart'] )    && is_array( $this->data['cart'] )    ? $this->data['cart']    : array();
        $saved_cart   = isset( $saved_data['cart'] )    && is_array( $saved_data['cart'] )    ? $saved_data['cart']    : array();

        if ( ! empty( $saved_cart ) ) {
            foreach ( $saved_cart as $key => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                // C-H2: the saved line's `data` payload is restored on
                // merge. It carries plugin-internal `_*` keys — most
                // importantly `_price_at_add`, which can be weeks old
                // (saved carts live up to the 30-day TTL above). Strip
                // every `_*` key so Cart_Item::get_price() re-snapshots
                // the live product price instead of quoting a stale one.
                if ( isset( $item['data'] ) && is_array( $item['data'] ) ) {
                    $item['data'] = $this->strip_internal_item_data( $item['data'] );
                }

                if ( isset( $guest_cart[ $key ] ) ) {
                    // Same line — sum quantities.
                    $existing_qty = isset( $guest_cart[ $key ]['quantity'] ) ? (int) $guest_cart[ $key ]['quantity'] : 0;
                    $saved_qty    = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
                    $summed       = $existing_qty + $saved_qty;
                    if ( $summed > 0 ) {
                        $guest_cart[ $key ]['quantity'] = $summed;
                    }
                } else {
                    $guest_cart[ $key ] = $item;
                }
            }

            // C-H1: the guest+saved merge above can sum two legitimate
            // lines into an oversold quantity (e.g. 5 in stock, guest has
            // 4, saved cart has 3 → 7). Re-apply the same validation
            // Cart::add()/update_quantity() enforce: clamp each line to
            // min(summed, MAX_LINE_QUANTITY, effective max, live stock)
            // and drop sold-individually duplicates. Without this the
            // oversold quantity persists and displays until
            // validate_stock() rejects it at submit.
            $guest_cart = $this->clamp_merged_cart( $guest_cart );

            $this->data['cart'] = $guest_cart;
        }

        // Coupons / shipping destination: only adopt the saved values
        // when the guest cart didn't already carry one. The buyer's
        // most recent action (this session) always wins.
        if ( empty( $this->data['coupons'] ) && ! empty( $saved_data['coupons'] ) ) {
            $this->data['coupons'] = $saved_data['coupons'];
        }
        if ( empty( $this->data['shipping_destination'] ) && ! empty( $saved_data['shipping_destination'] ) ) {
            $this->data['shipping_destination'] = $saved_data['shipping_destination'];
        }

        delete_user_meta( $user_id, '_tejcart_saved_cart' );

        // Force persist on shutdown.
        $this->save();
    }

    /**
     * Remove plugin-internal `_*` keys from a cart line's `data` payload.
     *
     * C-H2: these keys (notably `_price_at_add`) are snapshots written by
     * Cart::add() at add-time. Re-injecting a stale snapshot on merge would
     * quote a weeks-old price; dropping them lets Cart_Item::get_price()
     * re-snapshot the live product price.
     *
     * @param array<string,mixed> $data Stored line data.
     * @return array<string,mixed>
     */
    private function strip_internal_item_data( array $data ): array {
        foreach ( array_keys( $data ) as $data_key ) {
            if ( is_string( $data_key ) && '' !== $data_key && '_' === $data_key[0] ) {
                unset( $data[ $data_key ] );
            }
        }
        return $data;
    }

    /**
     * Re-apply Cart::add()/update_quantity() validation to a merged cart.
     *
     * C-H1: clamps each line's quantity to the minimum of the merged
     * quantity, {@see Cart::MAX_LINE_QUANTITY}, the product's effective max
     * purchase quantity, and live stock; and drops duplicate lines of a
     * sold-individually product (keeping the first, capped at qty 1). Lines
     * whose product can no longer be resolved are left as-is — Cart's own
     * load_from_session() drops unknown/unpurchasable products.
     *
     * @param array<string,array<string,mixed>> $cart Merged cart lines keyed by line key.
     * @return array<string,array<string,mixed>>
     */
    private function clamp_merged_cart( array $cart ): array {
        if ( empty( $cart ) || ! class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
            return $cart;
        }

        $max_line        = class_exists( '\\TejCart\\Cart\\Cart' ) ? Cart::MAX_LINE_QUANTITY : 9999;
        $seen_individual = array();

        foreach ( $cart as $key => $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }

            $product_id = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
            $quantity   = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;
            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $product = \TejCart\Product\Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                // Unknown product — leave for Cart::load_from_session() to drop.
                continue;
            }

            // Sold-individually: cap at 1 and drop any further duplicate
            // lines for the same product (mirrors Cart::add()).
            if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
                if ( isset( $seen_individual[ $product_id ] ) ) {
                    unset( $cart[ $key ] );
                    continue;
                }
                $seen_individual[ $product_id ] = true;
                $quantity                       = 1;
            }

            // Hard per-line cap.
            if ( $quantity > $max_line ) {
                $quantity = $max_line;
            }

            // Effective max purchase quantity (own + parent for variations).
            $effective_max = 0;
            if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
                $effective_max = (int) $product->get_max_purchase_quantity();
            }
            $parent_id = isset( $line['data']['parent_id'] ) ? (int) $line['data']['parent_id'] : 0;
            if ( $parent_id > 0 ) {
                $parent_product = \TejCart\Product\Product_Factory::get_product( $parent_id );
                if ( $parent_product && method_exists( $parent_product, 'get_max_purchase_quantity' ) ) {
                    $parent_max = (int) $parent_product->get_max_purchase_quantity();
                    if ( $parent_max > 0 && ( 0 === $effective_max || $parent_max < $effective_max ) ) {
                        $effective_max = $parent_max;
                    }
                }
            }
            if ( $effective_max > 0 && $quantity > $effective_max ) {
                $quantity = $effective_max;
            }

            // Live stock (only when stock is managed and backorders are off).
            if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
                $allows_backorder = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();
                if ( ! $allows_backorder && method_exists( $product, 'get_stock_quantity' ) ) {
                    $stock = (int) $product->get_stock_quantity();
                    if ( $stock >= 0 && $quantity > $stock ) {
                        $quantity = $stock;
                    }
                }
            }

            if ( $quantity <= 0 ) {
                unset( $cart[ $key ] );
                continue;
            }

            $cart[ $key ]['quantity'] = $quantity;
        }

        return $cart;
    }

    /**
     * Get the current session key.
     *
     * @return string
     */
    public function get_session_key() {
        return $this->session_key;
    }

    /**
     * Retrieve a value from the session data.
     *
     * @param string $key     Data key.
     * @param mixed  $default Default value if key does not exist.
     * @return mixed
     */
    public function get( $key, $default = null ) {
        $this->maybe_load();

        return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
    }

    /**
     * Set a value in the session data.
     *
     * @param string $key   Data key.
     * @param mixed  $value Value to store.
     */
    public function set( $key, $value ) {
        $this->maybe_load();

        $this->data[ $key ] = $value;
        $this->dirty = true;
    }

    /**
     * Mark the session as dirty so it will be persisted at shutdown.
     *
     * Does NOT write to the database immediately. The actual write
     * happens in maybe_persist() via the registered shutdown function.
     * Cart mutations (add, remove, update_quantity, apply_coupon,
     * remove_coupon, set_chosen_shipping_method) follow this with an
     * explicit force_save() so an abnormal exit (PHP timeout, fatal,
     * OOM, request abort) cannot lose the buyer's change. For everything
     * else (passive reads, checkout-meta scratch space) deferred shutdown
     * persistence is fine.
     */
    public function save() {
        $this->dirty = true;
    }

    /**
     * Persist session data to the database immediately.
     *
     * Use this for cart mutations, checkout, or any operation that must
     * guarantee the data is written before the response completes — see
     * the contract note on {@see save()}.
     */
    public function force_save(): void {
        $this->persist_to_db();
        $this->dirty = false;
    }

    /**
     * Persist session data if it has been modified.
     *
     * Called automatically at shutdown via register_shutdown_function().
     */
    public function maybe_persist(): void {
        if ( $this->dirty ) {
            $this->persist_to_db();
            $this->dirty = false;
        }
    }

    /**
     * Hard cap on the JSON-encoded session payload size. The underlying
     * `session_value` column is `LONGTEXT`; without a cap a malicious client
     * can stuff arbitrary keys (or huge string values) into the cart's
     * `data[…]` and inflate the row to MySQL's max-packet limit, OOMing the
     * application or trigger-failing the INSERT. 64 KB is more than 100x
     * what a legitimate cart with extras + addresses + coupon state needs.
     * See review finding M-6.
     */
    private const MAX_SESSION_PAYLOAD_BYTES = 65536;

    /**
     * Write the session data to the database.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert behaviour. No
     * explicit transaction needed — a single-statement INSERT is atomic in
     * InnoDB. Session cleanup is handled by WP-Cron (see Action_Scheduler),
     * not inline.
     */
    private function persist_to_db(): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_sessions';
        $expiry = time() + self::SESSION_EXPIRY;

        $this->data['_tejcart_fingerprint'] = $this->generate_fingerprint();

        $payload = wp_json_encode( $this->data );
        if ( false === $payload ) {
            return;
        }

        if ( strlen( $payload ) > self::MAX_SESSION_PAYLOAD_BYTES ) {
            // Refuse to persist an over-cap payload. We also log the
            // event so operators can investigate (a real buyer
            // hitting 64 KB is a bug; an attacker hitting it is
            // expected). The caller (Cart::add etc.) does not see a
            // failure — the in-memory cart still works for the rest
            // of the request — but the bloated state will not be
            // persisted across requests, breaking the attack's
            // amplification step.
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'Cart_Session refused to persist over-cap payload (%d bytes > %d).',
                        strlen( $payload ),
                        self::MAX_SESSION_PAYLOAD_BYTES
                    ),
                    'warning'
                );
            }
            /**
             * Fires when a session payload exceeds the safety cap and
             * is dropped. Listeners can alert ops or capture metrics.
             *
             * @since 1.0.1
             *
             * @param int $bytes Encoded payload size in bytes.
             * @param int $cap   Configured cap in bytes.
             */
            if ( function_exists( 'do_action' ) ) {
                do_action( 'tejcart_session_payload_over_cap', strlen( $payload ), self::MAX_SESSION_PAYLOAD_BYTES );
            }
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "INSERT INTO {$table} ( session_key, session_value, session_expiry )
                 VALUES ( %s, %s, %d )
                 ON DUPLICATE KEY UPDATE session_value = VALUES( session_value ), session_expiry = VALUES( session_expiry )",
                $this->session_key,
                $payload,
                $expiry
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Refresh the persistent-cache copy so other LB nodes see the
        // write on their next maybe_load() without re-reading from DB.
        wp_cache_set(
            'sess_' . $this->session_key,
            $this->data,
            'tejcart_cart_sessions',
            self::SESSION_EXPIRY
        );
    }

    /**
     * Destroy the current session.
     *
     * Removes the database row and clears the cookie.
     */
    public function destroy() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete(
            $table,
            array( 'session_key' => $this->session_key ),
            array( '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        wp_cache_delete( 'sess_' . $this->session_key, 'tejcart_cart_sessions' );

        $this->data   = array();
        $this->loaded = false;

        if ( ! headers_sent() ) {
            setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
    }

    /**
     * Delete expired sessions in bounded batches.
     *
     * Intended to be called via WP-Cron. A single unbounded
     * `DELETE FROM tejcart_sessions WHERE session_expiry < NOW()` on a
     * high-volume install can hold InnoDB metadata locks for hundreds
     * of milliseconds, blocking every concurrent cart load behind it.
     * We loop a bounded `DELETE … LIMIT` so each batch finishes quickly
     * and other queries get a slot, and guard the whole loop with an
     * advisory `Lock::claim` so overlapping cron firings don't fight
     * each other.
     *
     * Filter `tejcart_session_cleanup_batch` to change the per-iteration
     * row cap (default 1000); filter `tejcart_session_cleanup_max_iter`
     * to cap loop iterations (default 50 → up to 50k rows per run).
     *
     * @return int Total rows deleted across all batches in this run.
     */
    public static function cleanup(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        $batch = (int) apply_filters( 'tejcart_session_cleanup_batch', 1000 );
        if ( $batch <= 0 ) {
            $batch = 1000;
        }
        $max_iterations = (int) apply_filters( 'tejcart_session_cleanup_max_iter', 50 );
        if ( $max_iterations <= 0 ) {
            $max_iterations = 50;
        }

        // Advisory lock so a backed-up cron queue (or a second invocation
        // from `wp tejcart cron run`) doesn't run two cleanups in parallel
        // against the same expiring rowset.
        $lock_acquired = false;
        if ( class_exists( '\\TejCart\\Core\\Lock' ) ) {
            $lock_acquired = \TejCart\Core\Lock::claim( 'cleanup_sessions', 300 );
            if ( ! $lock_acquired ) {
                return 0;
            }
        }

        $total      = 0;
        $iterations = 0;
        try {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                $sql_delete = $wpdb->prepare( "DELETE FROM {$table} WHERE session_expiry < %d LIMIT %d", time(), $batch );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                $deleted = (int) $wpdb->query( $sql_delete );
                $total += $deleted;
                $iterations++;
            } while ( $deleted === $batch && $iterations < $max_iterations );
        } finally {
            if ( $lock_acquired && class_exists( '\\TejCart\\Core\\Lock' ) ) {
                \TejCart\Core\Lock::release( 'cleanup_sessions' );
            }
        }

        return $total;
    }

    /**
     * Regenerate the session key when a guest user logs in.
     *
     * Migrates existing session data to a new key and destroys the old one.
     */
    public function regenerate_on_login() {
        $old_key = $this->session_key;

        $this->maybe_load();
        $existing_data = $this->data;

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_sessions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete( $table, array( 'session_key' => $old_key ), array( '%s' ) );
        wp_cache_delete( 'sess_' . $old_key, 'tejcart_cart_sessions' );

        $this->session_key = $this->generate_session_key();
        // Keep the per-request key in sync so a Cart_Session built later in
        // this same request adopts the regenerated key, not the pre-login one.
        if ( self::request_key_sharing_enabled() ) {
            self::$request_session_key = $this->session_key;
        }
        $this->data        = $existing_data;
        $this->set_cookie();
        $this->save();
    }

    /**
     * Whether per-request session-key sharing is active.
     *
     * Disabled under PHPUnit (TEJCART_TESTING) so the process-lifetime static
     * doesn't carry a key across independent test methods. Enabled everywhere
     * else (production + the real-WordPress E2E suite), where each PHP request
     * is its own process and the static is exactly the right scope.
     *
     * @return bool
     */
    private static function request_key_sharing_enabled(): bool {
        return ! ( defined( 'TEJCART_TESTING' ) && TEJCART_TESTING );
    }

    /**
     * Generate a random 64-character hexadecimal session key (256-bit entropy).
     *
     * @return string
     */
    private function generate_session_key() {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Set the session cookie with hardened flags.
     *
     * Required cookie flags (all three are mandatory; see issue #384):
     *
     *   • HttpOnly       — keeps the cookie out of JS/document.cookie so an
     *                      XSS payload can't exfiltrate it.
     *   • SameSite=Lax   — blocks cross-site POSTs from carrying the cookie,
     *                      which is the CSRF foothold the nonce check
     *                      backstops. "Lax" lets top-level GET navigations
     *                      keep the cart, which is what users expect.
     *   • Secure         — only when the request is HTTPS. We do not force
     *                      this on plain-HTTP sites because the cookie
     *                      would never round-trip and the cart would be
     *                      permanently empty; admins running HTTPS get the
     *                      flag automatically via is_ssl().
     *
     * INVARIANT — no state-mutating GETs.
     *
     * SameSite=Lax permits the cart cookie to ride along on top-level
     * GET navigations (a buyer clicking a link to /cart/ keeps their
     * cart, as expected). That privilege is safe ONLY as long as no
     * GET endpoint in TejCart performs a state-mutating side-effect.
     * If a future commit adds a GET handler that mutates cart, order,
     * coupon, or session state, an attacker can craft a `<a href>` or
     * a `<img src>` whose URL fires that side-effect cross-site under
     * the victim's session — a classic CSRF gadget that the nonce
     * cannot stop because the same-origin check on a top-level GET
     * doesn't apply.
     *
     * The current code base honours this invariant:
     *   - Cart mutations go through `wp_ajax_*` POST handlers
     *     (Cart_Ajax) protected by nonce + same-origin checks.
     *   - The non-AJAX cart-form fallback in
     *     `Cart_Ajax::maybe_handle_cart_form_post()` requires
     *     `$_SERVER['REQUEST_METHOD'] === 'POST'`.
     *   - REST cart endpoints (`Cart_Controller`) use POST/PUT/DELETE
     *     for every mutation; the GET routes are read-only.
     *   - Download tokens are bearer-style URLs that authenticate via
     *     a signed token, NOT via the cart cookie.
     *
     * Anything that wants to add a state-mutating endpoint MUST use
     * a non-GET HTTP method (or move to SameSite=Strict / a different
     * cookie). A CI grep guard for `add_action.*wp_ajax_.*GET` is
     * tracked as a separate follow-up.
     *
     * See review finding M-4.
     */
    private function set_cookie() {
        if ( headers_sent() || defined( 'DOING_CRON' ) ) {
            return;
        }

        /**
         * F-M2 / #936: allow the session cookie to be suppressed by
         * extensions enforcing strict consent regimes. Default true
         * because the session cookie is "strictly necessary" (it's
         * what makes the cart persist across pages) and most consent
         * frameworks already exempt that category. Return false to
         * skip the setcookie() call entirely; the cart will then only
         * survive within the current request.
         *
         * @param bool   $allowed Default true.
         * @param string $token   Session token about to be written.
         */
        if ( ! apply_filters( 'tejcart_session_cookie_allowed', true, $this->session_key ) ) {
            return;
        }

        $cookie_args = array(
            'expires'  => time() + self::SESSION_EXPIRY,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        /**
         * Filter the session cookie parameters before they are written.
         *
         * Reverse proxies, load balancers, and TLS-terminating front ends
         * frequently need to override `secure` (the upstream sees plain HTTP
         * while the edge is HTTPS, or vice-versa), `domain`, or `path` so the
         * cookie actually round-trips — otherwise the cart silently resets on
         * the next request. The defaults are unchanged for direct-served
         * sites. Returning a non-array is ignored.
         *
         * @since 1.0.1
         *
         * @param array  $cookie_args  setcookie() options array.
         * @param string $session_key  Session token about to be written.
         */
        $filtered = apply_filters( 'tejcart_session_cookie_args', $cookie_args, $this->session_key );
        if ( is_array( $filtered ) ) {
            $cookie_args = array_merge( $cookie_args, $filtered );
        }

        setcookie( self::COOKIE_NAME, $this->session_key, $cookie_args );
    }

    /**
     * Generate a fingerprint for the current request.
     *
     * SECURITY MODEL — read this before changing anything in here:
     *
     * The fingerprint is **not an authentication primitive**. It binds a
     * session cookie to a stable client signature so a cookie copied to a
     * meaningfully-different environment (different browser family, locale,
     * /24 network) lands on a fresh session instead of inheriting the
     * victim's cart. The inputs (User-Agent, Accept-Language,
     * Accept-Encoding, Sec-CH-UA*) are **client-controlled** and the HMAC
     * over them only protects integrity-at-rest of the stored value — it
     * provides no guarantee about header legitimacy. An attacker who steals
     * both the cookie *and* knows the victim's exact request signature can
     * replay it; the fingerprint exists to make the casual cookie-copy
     * attack fail, not to defeat a determined replay.
     *
     * Authentication of the user is wp_set_auth_cookie() / wp's auth
     * cookies; CSRF protection on cart mutations is the nonce + same-origin
     * check enforced by Cart_Ajax::verify_nonce() and ::verify_origin().
     * This routine is strictly defense-in-depth on top of those.
     *
     * Components combined into the HMAC input:
     *
     *   • User-Agent
     *   • Accept-Language
     *   • Accept-Encoding
     *   • Sec-CH-UA / Sec-CH-UA-Platform (modern client hints)
     *   • Coarse IP prefix — /24 for IPv4, /48 for IPv6. Coarse on purpose
     *     so a customer hopping between cell towers or NAT hops on the
     *     same carrier doesn't get logged out, but a cross-network theft
     *     still fails.
     *
     * Hashed with hash_hmac() under the rotation-stable Key_Manager secret
     * (see fingerprint_key()) so a database leak of the fingerprint column
     * doesn't trivially correlate back to a known browser, and so resetting
     * WordPress salts no longer drops every active cart.
     *
     * The output is prefixed with a "v3:" tag so validate_fingerprint() can
     * tell the current fingerprint apart from a "v2:" session written before
     * the managed-secret migration (those were keyed by wp_salt('auth') and
     * are grandfathered via generate_fingerprint_legacy() so no active cart
     * is dumped on deploy).
     *
     * @return string
     */
    private function generate_fingerprint() {
        return 'v3:' . hash_hmac( 'sha256', $this->fingerprint_parts(), self::fingerprint_key() );
    }

    /**
     * Build the pipe-joined fingerprint preimage shared by the current
     * (v3) and legacy (v2) fingerprint generators.
     *
     * @return string
     */
    private function fingerprint_parts(): string {
        $parts = array(
            isset( $_SERVER['HTTP_USER_AGENT'] )           ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )           : '',
            isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )      ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )      : '',
            isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )      ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) )      : '',
            isset( $_SERVER['HTTP_SEC_CH_UA'] )            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA'] ) )            : '',
            isset( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] )   ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ) )   : '',
            $this->get_ip_prefix(),
        );

        return implode( '|', $parts );
    }

    /**
     * Pre-Key_Manager fingerprint (v2, keyed by wp_salt('auth')).
     * Retained so sessions stamped before the managed-secret migration
     * keep validating instead of logging the customer out on deploy.
     *
     * @return string
     */
    private function generate_fingerprint_legacy() {
        $salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        return 'v2:' . hash_hmac( 'sha256', $this->fingerprint_parts(), $salt );
    }

    /**
     * Rotation-stable HMAC key for the session fingerprint. Uses the
     * plugin-managed {@see \TejCart\Security\Key_Manager} secret so a WP
     * salt reset no longer invalidates active carts. Falls back to
     * wp_salt('auth') only when no managed secret can be resolved.
     *
     * @return string
     */
    private static function fingerprint_key(): string {
        if ( \TejCart\Security\Key_Manager::is_available() ) {
            return \TejCart\Security\Key_Manager::hmac_key( 'tejcart|session-fp|v3' );
        }
        return function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
    }

    /**
     * Coarse IP prefix used inside the session fingerprint.
     *
     * Default: IPv4 → first three octets (x.y.z.0); IPv6 → first 48 bits.
     * The masking is intentional: customers on mobile networks or behind
     * a CGNAT pool routinely change their full address mid-session, but
     * it is unusual for a legitimate session to jump networks entirely.
     *
     * Merchants whose threat model includes in-network attackers (corporate
     * NAT, public Wi-Fi) can opt into strict-mode binding, which uses the
     * full IP for IPv4 and the first 64 bits for IPv6. Strict mode trades
     * some legitimate session-renewal churn (mobile handoff, ISP DHCP
     * rotation) for tighter session-theft resistance, and is enabled by
     * setting the `tejcart_session_fingerprint_mode` option to "strict".
     * The `tejcart_session_fingerprint_mode` filter overrides the option
     * for programmatic control.
     *
     * @return string
     */
    private function get_ip_prefix(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip = filter_var( $ip, FILTER_VALIDATE_IP );
        if ( false === $ip ) {
            return '';
        }

        $mode = (string) get_option( 'tejcart_session_fingerprint_mode', 'coarse' );
        $mode = (string) apply_filters( 'tejcart_session_fingerprint_mode', $mode );
        $mode = in_array( $mode, array( 'coarse', 'strict' ), true ) ? $mode : 'coarse';

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            if ( 'strict' === $mode ) {
                return $ip;
            }
            $parts = explode( '.', $ip );
            return count( $parts ) === 4 ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0' : '';
        }

        $packed = @inet_pton( $ip );
        $bits   = ( 'strict' === $mode ) ? 8 : 6;
        if ( false === $packed || strlen( $packed ) < $bits ) {
            return '';
        }

        return bin2hex( substr( $packed, 0, $bits ) );
    }

    /**
     * Validate the session fingerprint against the stored value.
     *
     * Match logic:
     *
     *   • No stored fingerprint  → brand-new row that hasn't been
     *     persisted yet, allow (persist_to_db() will stamp one).
     *   • Stored "v3:…"          → strict hash_equals() against the
     *     current (managed-secret keyed) fingerprint.
     *   • Stored "v2:…"          → strict hash_equals() against the legacy
     *     wp_salt('auth')-keyed fingerprint (grandfathered; re-stamps to
     *     v3 on the next persist).
     *   • Anything else          → rejected. v1 fingerprints are a
     *     pre-1.0 artifact and never reach production.
     *
     * @return bool True if the session is allowed to continue.
     */
    private function validate_fingerprint() {
        $this->maybe_load();

        /**
         * Allow the environment to short-circuit fingerprint validation.
         *
         * Defense-in-depth only (see generate_fingerprint()'s security note),
         * the binding can produce false rejections behind front ends that
         * rewrite client headers or rotate the upstream IP per request, which
         * destroys the session and empties the cart mid-flow. Return a boolean
         * to force the outcome; return null (default) to run the normal check.
         *
         * @since 1.0.1
         *
         * @param bool|null $override     Forced result, or null for default.
         * @param string    $session_key  Current session token.
         */
        $override = apply_filters( 'tejcart_session_validate_fingerprint', null, $this->session_key );
        if ( is_bool( $override ) ) {
            return $override;
        }

        $stored = isset( $this->data['_tejcart_fingerprint'] ) ? (string) $this->data['_tejcart_fingerprint'] : '';

        if ( '' === $stored ) {
            return true;
        }

        if ( 0 === strpos( $stored, 'v3:' ) ) {
            return hash_equals( $stored, $this->generate_fingerprint() );
        }

        if ( 0 === strpos( $stored, 'v2:' ) ) {
            return hash_equals( $stored, $this->generate_fingerprint_legacy() );
        }

        return false;
    }

    /**
     * Load session data from the database if not already loaded.
     *
     * Behind a load balancer with 8+ PHP workers, every cart action would
     * otherwise hit the wp_tejcart_sessions table. We layer a persistent
     * object-cache lookup in front of the SELECT (Redis/Memcached on
     * production, no-op on file-backed cache) so warm sessions skip the
     * DB entirely while still surviving across worker boundaries.
     */
    private function maybe_load() {
        if ( $this->loaded ) {
            return;
        }

        $cache_key = 'sess_' . $this->session_key;
        $cached    = wp_cache_get( $cache_key, 'tejcart_cart_sessions' );
        if ( is_array( $cached ) ) {
            $this->data   = $cached;
            $this->loaded = true;
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT session_value FROM {$table} WHERE session_key = %s AND session_expiry >= %d LIMIT 1",
                $this->session_key,
                time()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $row ) {
            $decoded    = self::decode_session_payload( (string) $row );
            $this->data = is_array( $decoded ) ? $decoded : array();
            wp_cache_set( $cache_key, $this->data, 'tejcart_cart_sessions', self::SESSION_EXPIRY );
        }

        $this->loaded = true;
    }

    /**
     * Decode a stored session payload.
     *
     * Current format is JSON. Older rows written before the JSON migration
     * may still be PHP-serialized arrays (`a:N:{…}`); those are read once
     * with object instantiation explicitly disabled and are rewritten as
     * JSON on the next save. Anything we cannot safely decode is treated
     * as a corrupt session and dropped.
     *
     * @param string $payload Raw session_value column.
     * @return array<string,mixed>|null
     */
    private static function decode_session_payload( string $payload ) {
        if ( '' === $payload ) {
            return null;
        }

        $first = $payload[0];
        if ( '{' === $first || '[' === $first ) {
            $decoded = json_decode( $payload, true );
            return is_array( $decoded ) ? $decoded : null;
        }

        if ( 0 === strncmp( $payload, 'a:', 2 ) ) {
            /*
             * Legacy serialized cart payload (pre-JSON migration). The
             * `allowed_classes => false` flag prevents object instantiation
             * (POP-gadget mitigation), and the `@` suppresses the E_NOTICE
             * that PHP emits on a truncated/invalid payload — we already
             * handle the failure case by returning null when the result
             * is not an array. This is not a logic-error mute; it is the
             * documented way to "best-effort decode and discard on failure".
             */
            // phpcs:ignore WordPress.PHP.DiscouragedFunctions.unserialize_unserialize
            $unserialized = @unserialize( $payload, array( 'allowed_classes' => false ) );
            return is_array( $unserialized ) ? $unserialized : null;
        }

        return null;
    }
}
