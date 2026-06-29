<?php
/**
 * TejCart Installer - Database and Default Setup
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin activation, deactivation, database table creation,
 * default options, and page creation.
 */
class Installer {
    /**
     * Minimum required PHP version.
     *
     * @var string
     */
    const MIN_PHP_VERSION = '8.2';

    /**
     * Minimum required WordPress version.
     *
     * @var string
     */
    const MIN_WP_VERSION = '6.3';

    /**
     * Option flag marking that the one-time DECIMAL(major-unit) ->
     * BIGINT(minor-unit) money-representation migration has run. See
     * {@see Installer::migrate_money_representation()}.
     *
     * @var string
     */
    const MONEY_MIGRATION_FLAG = 'tejcart_money_minor_units_migrated';

    /**
     * Per-request guard so the "invalid TEJCART_VERSION" warning is logged
     * at most once per request rather than on every schema_version() call.
     *
     * @var bool
     */
    private static bool $warned_invalid_version = false;

    /**
     * Authoritative plugin version used for schema / migration version-gating.
     *
     * The migration cursor (`tejcart_version` and the Tier-2
     * `tejcart_tier2_schema_version` options) MUST be compared against a
     * STABLE plugin version. `TEJCART_VERSION` is publicly overridable — the
     * bootstrap defines it behind `if ( ! defined( 'TEJCART_VERSION' ) )` — so
     * a site that (mis)defines it in wp-config.php, e.g.
     * `define( 'TEJCART_VERSION', time() )` for asset cache-busting, makes the
     * constant change on every request. Used directly as the cursor that would
     * re-run the entire dbDelta + foreign-key migration on EVERY page load
     * (a multi-second per-request cost) and never converge.
     *
     * Guard against that: trust `TEJCART_VERSION` only when it is shaped like a
     * real version (`major.minor[...]`). Otherwise fall back to the
     * un-overridable `Version:` header read from the main plugin file, so the
     * cursor stays stable and the migration runs once and then no-ops as
     * designed. This is defence-in-depth — the correct fix for such a site is
     * still to remove the wp-config override — but it ensures a stray override
     * can never silently pin the schema migration into a per-request loop.
     *
     * @return string A stable, version-shaped string (e.g. '1.0.5').
     */
    public static function schema_version(): string {
        $ver = defined( 'TEJCART_VERSION' ) ? (string) TEJCART_VERSION : '';

        // A genuine plugin version is always at least `major.minor`. A bare
        // integer (a timestamp) or an empty/garbage value fails this and is
        // treated as a misconfiguration.
        if ( 1 === preg_match( '/^\d+\.\d+/', $ver ) ) {
            return $ver;
        }

        $header = self::header_version();

        if ( ! self::$warned_invalid_version && function_exists( 'tejcart_log' ) ) {
            self::$warned_invalid_version = true;
            tejcart_log(
                sprintf(
                    'TEJCART_VERSION is not a valid version string (got "%s"); using the plugin header version "%s" for schema version-gating. Remove any define( \'TEJCART_VERSION\', ... ) override from wp-config.php.',
                    $ver,
                    $header
                ),
                'warning'
            );
        }

        return $header;
    }

    /**
     * Read the authoritative plugin version from the main file's `Version:`
     * header. Cached per-request. This value cannot be overridden from
     * wp-config, so it is the safe fallback for {@see schema_version()}.
     *
     * @return string
     */
    private static function header_version(): string {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }

        $version = '0.0.0';
        if ( defined( 'TEJCART_PLUGIN_FILE' ) && function_exists( 'get_file_data' ) ) {
            $data = get_file_data( TEJCART_PLUGIN_FILE, array( 'Version' => 'Version' ) );
            if ( ! empty( $data['Version'] ) ) {
                $version = (string) $data['Version'];
            }
        }

        $cached = $version;
        return $cached;
    }

    /**
     * Run on plugin activation.
     *
     * Checks environment requirements, creates database tables,
     * sets default options, creates pages, and flushes rewrite rules.
     */
    public static function activate() {
        if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
            deactivate_plugins( TEJCART_PLUGIN_BASENAME );
            wp_die(
                sprintf(
                    /* translators: %s: minimum PHP version */
                    esc_html__( 'TejCart requires PHP %s or higher. Please upgrade your PHP version.', 'tejcart' ),
                    esc_html( self::MIN_PHP_VERSION )
                ),
                esc_html__( 'Plugin Activation Error', 'tejcart' ),
                array( 'back_link' => true )
            );
        }

        global $wp_version;
        if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
            deactivate_plugins( TEJCART_PLUGIN_BASENAME );
            wp_die(
                sprintf(
                    /* translators: %s: minimum WordPress version */
                    esc_html__( 'TejCart requires WordPress %s or higher. Please upgrade WordPress.', 'tejcart' ),
                    esc_html( self::MIN_WP_VERSION )
                ),
                esc_html__( 'Plugin Activation Error', 'tejcart' ),
                array( 'back_link' => true )
            );
        }

        global $wpdb;
        $db_version = $wpdb->db_version();
        if ( version_compare( $db_version, '5.7.8', '<' ) ) {
            deactivate_plugins( TEJCART_PLUGIN_BASENAME );
            wp_die(
                sprintf(
                    /* translators: %s: current database version */
                    esc_html__( 'TejCart requires MySQL 5.7.8+ or MariaDB 10.2.7+. Your database version is %s.', 'tejcart' ),
                    esc_html( $db_version )
                ),
                esc_html__( 'Plugin Activation Error', 'tejcart' ),
                array( 'back_link' => true )
            );
        }

        $current_version = get_option( 'tejcart_version', '0.0.0' );

        self::create_tables();

        if ( version_compare( $current_version, '1.0.0', '<' ) ) {
            self::create_default_options();
        }

        self::install_pages();

        self::create_roles();

        \TejCart\Core\Capabilities::install();

        if ( 'yes' !== get_option( 'tejcart_customers_backfilled', 'no' ) ) {
            \TejCart\Customer\Customer_Repository::backfill_from_orders();
            update_option( 'tejcart_customers_backfilled', 'yes' );
        }

        update_option( 'tejcart_version', self::schema_version() );

        if ( 'no' === get_option( \TejCart\Admin\Setup_Wizard::COMPLETED_OPTION, 'no' ) ) {
            global $wpdb;

            $products_table = $wpdb->prefix . 'tejcart_products';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) ) === $products_table;
            $has_products = 0;
            if ( $table_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
                // F-CORE-003: use %i (WP 6.2+ identifier placeholder) so the
                // table name is properly quoted even if $wpdb->prefix is exotic.
                $has_products = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $products_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
            if ( $has_products > 0 ) {
                update_option( \TejCart\Admin\Setup_Wizard::COMPLETED_OPTION, 'yes' );
            } else {
                // 1-hour TTL — the redirect flag is one-shot and consumed
                // on the next admin pageload, so a wider window survives
                // slow activation requests on shared hosts where the
                // original 60s could elapse before the redirect fires.
                set_transient( 'tejcart_redirect_to_setup', 1, HOUR_IN_SECONDS );
            }
        }

        if ( class_exists( '\\TejCart\\Frontend\\Product_Permalinks' ) ) {
            ( new \TejCart\Frontend\Product_Permalinks() )->register_rewrite_rules();
        }

        flush_rewrite_rules();
    }

    /**
     * Idempotent, version-gated upgrade routine for the CORE schema and
     * capabilities. Runs on a normal request — see the `plugins_loaded`
     * bootstrap in tejcart.php — NOT on the activation hook.
     *
     * WordPress fires `register_activation_hook()` only on an explicit
     * activate; it is NOT fired when a plugin is updated in place
     * (wp.org auto-update, the "Update Now" link, or re-uploading the
     * zip). A site updated from an older release therefore never re-runs
     * `activate()`, so any core table or column added since the installed
     * version is missing and every query that touches it fails with
     * "Table doesn't exist" / "Unknown column" — breaking the storefront
     * and admin after what looked like a routine update. 1.0.1 alone adds
     * five core tables (customer_segments, product_cooccurrence,
     * product_reviews, review_media, review_votes) and ten
     * tejcart_customers columns (rfm_*, segment, ltv_minor_units,
     * order_count, last_order_at, phone) that are read on the frontend
     * product page and the admin customers / dashboard screens.
     *
     * Mirrors the Tier-2 schema cursor in
     * {@see \TejCart\Tier2\Tier2::boot()}: compares the stored
     * `tejcart_version` option (written by activate()) to TEJCART_VERSION
     * and only runs the heavy dbDelta + capability sync when they differ,
     * so the steady-state per-request cost is a single get_option().
     *
     * `create_tables()` is built entirely from dbDelta plus idempotent,
     * error-suppressed ALTERs, so it is safe to re-run and safe under the
     * brief burst of concurrent first-requests immediately after an
     * update.
     */
    public static function maybe_upgrade(): void {
        global $wpdb;

        // Stable cursor — see schema_version(). Never compare/advance the
        // migration cursor against a raw, overridable TEJCART_VERSION: a
        // wp-config override (e.g. to time()) would otherwise pin this into a
        // multi-second per-request migration loop that never converges.
        $plugin_ver = self::schema_version();

        $stored = (string) get_option( 'tejcart_version', '' );

        // Empty cursor: a brand-new install whose activate() hook owns the
        // first table creation (activation runs synchronously before any
        // later request). Equal cursor: schema already current. Either
        // way there is nothing to migrate on this request.
        if ( '' === $stored || $plugin_ver === $stored ) {
            return;
        }

        // Serialize concurrent upgrade attempts ACROSS requests. Right after
        // an in-place update a busy store fires many parallel first-requests,
        // all of which would otherwise enter maybe_upgrade() at once. The
        // money-representation migration (STEP 1) is only idempotent against
        // *itself across requests* when those requests do not overlap: its
        // gate (the migration flag + the DECIMAL column type) is read before
        // its transaction, and the type that closes the gate is changed later
        // in STEP 2 outside that transaction — so two requests that overlap
        // can both pass the gate and both multiply, storing every money value
        // at 100x. A MySQL session-level advisory lock closes that window. We
        // use GET_LOCK (not Core\Lock) because Core\Lock's own table may not
        // exist yet on this very upgrade and is (re)built by STEP 2.
        //
        // The lock name is namespaced by database + table prefix so sites
        // sharing a MySQL server (GET_LOCK names are server-global) do not
        // block each other, and hashed to stay within MySQL's 64-char limit.
        $lock_name = 'tejcart_up_' . substr( md5( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->base_prefix ), 0, 40 );

        // Wait briefly so a follower runs AFTER the leader finishes (and then
        // finds the cursor already current) rather than in parallel. Returns
        // 1 = acquired, 0 = timed out (held by another request), NULL = error.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 15 ) );

        if ( '0' === (string) $got_lock ) {
            // Another request holds the lock and is performing (or just
            // performed) the upgrade. Do nothing this request.
            return;
        }

        try {
            // Re-read the cursor now that we hold the lock: the leader that
            // held it before us may have completed the upgrade while we
            // waited, so we must not run the migrations a second time. Read
            // the committed value straight from the DB rather than via
            // get_option() — a follower that called get_option() before
            // waiting on the lock has the pre-upgrade `alloptions` snapshot
            // cached in its own request and would otherwise observe a stale
            // (pre-upgrade) cursor and re-run everything.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'tejcart_version' ) );
            if ( '' === $stored || $plugin_ver === $stored ) {
                return;
            }

            self::run_upgrade_steps();
        } catch ( \Throwable $e ) {
            // Failure isolation: a throw mid-migration must NOT advance the
            // version cursor (so the upgrade retries next request) and must
            // not be swallowed silently.
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Schema upgrade failed and will retry next request: ' . $e->getMessage(), 'error' );
            }
        } finally {
            if ( null !== $got_lock ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }
        }
    }

    /**
     * The ordered upgrade steps, run exactly once per release under the
     * advisory lock acquired by {@see Installer::maybe_upgrade()}. Extracted
     * so the lock/retry orchestration stays readable. The version cursor is
     * advanced only after every step returns, so a throw leaves it untouched
     * and the upgrade retries on a later request.
     */
    private static function run_upgrade_steps(): void {
        // STEP 1 — convert legacy DECIMAL (major-unit) money columns to
        // integer minor units IN PLACE, BEFORE create_tables() runs the
        // dbDelta that changes their column type to BIGINT. Doing the
        // multiply while the column is still DECIMAL keeps full sub-unit
        // precision; the subsequent type change is then a lossless integer
        // cast. (1.0.1 reads every money column as minor units, so without
        // this every pre-existing order/refund/line-item would read at
        // 1/100th its value.) Idempotent + crash-safe — see the method.
        self::migrate_money_representation();

        // STEP 2 — rebuild the schema (adds new tables/columns and performs
        // the DECIMAL->BIGINT type change on the now-integer money columns).
        self::create_tables();

        // STEP 3 — backfill the split coupon money columns from the legacy
        // `amount` column dbDelta leaves in place. Must run AFTER
        // create_tables() so the new columns exist. Idempotent.
        self::migrate_coupon_amounts();

        // STEP 3b — backfill the new settlement columns (base_currency,
        // base_total, fx_rate) on tejcart_orders so consolidated, base-
        // currency reporting has authoritative per-order data without a
        // meta join. Must run AFTER create_tables() so the columns exist.
        // Idempotent + chunked. {@see migrate_order_base_currency()}.
        self::migrate_order_base_currency();

        // STEP 3c — backfill the per-component base-currency settlement
        // columns added in 1.0.5 (orders.base_subtotal / base_discount_total
        // / base_shipping_total / base_tax_total, order_items.base_line_total,
        // order_refunds.base_amount) from each row's already-stamped fx_rate
        // (written by STEP 3b). Without this, base-currency reports would read
        // 0 for every order created before the upgrade. Idempotent + chunked.
        self::migrate_order_base_amounts();

        if ( class_exists( '\\TejCart\\Core\\Capabilities' ) ) {
            \TejCart\Core\Capabilities::install();
        }

        // STEP 4 — preserve features that were core in an older release but
        // are now opt-in modules (off by default), so an upgrading store
        // does not silently lose functionality it was using.
        self::preserve_legacy_modules();

        // STEP 5 — seed defaults for options introduced since the installed
        // version (cosmetic: every read site already supplies a matching
        // default, but this persists them so Settings screens show them).
        self::backfill_new_option_defaults();

        update_option( 'tejcart_version', self::schema_version() );
    }

    /**
     * One-time, crash-safe migration of every legacy DECIMAL(20,4)
     * major-unit money column to the integer minor-unit convention 1.0.1
     * reads. Runs BEFORE create_tables()'s dbDelta type change so the
     * multiply happens while the column is still DECIMAL (lossless); the
     * dbDelta DECIMAL->BIGINT cast that follows is then exact.
     *
     * Without this, a store upgraded from a pre-minor-units release would
     * read every existing order/refund/line-item/analytics row at 1/100th
     * (or 1/1000th for three-decimal currencies) of its true value.
     *
     * Safety:
     *  - Skipped once {@see Installer::MONEY_MIGRATION_FLAG} is set, and
     *    skipped when `tejcart_orders.total` is no longer DECIMAL (fresh
     *    install, or already migrated).
     *  - The per-currency multiply and the "done" flag are written inside a
     *    single transaction, so a crash before COMMIT rolls back both and
     *    the next request retries from a clean state — a partial or
     *    double-applied conversion is impossible.
     */
    public static function migrate_money_representation(): void {
        global $wpdb;

        if ( '1' === (string) get_option( self::MONEY_MIGRATION_FLAG, '' ) ) {
            return;
        }

        // Only pre-multiply when the columns are still the legacy DECIMAL
        // shape. A fresh install creates them as BIGINT from the start, and
        // an already-migrated store is BIGINT too — nothing to convert.
        if ( 'decimal' !== self::column_data_type( 'tejcart_orders', 'total' ) ) {
            update_option( self::MONEY_MIGRATION_FLAG, '1', false );
            return;
        }

        $default_currency = (string) get_option( 'tejcart_currency', 'USD' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'START TRANSACTION' );
        try {
            self::premultiply_by_own_currency( 'tejcart_orders', array( 'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'total' ), 'currency', $default_currency );
            self::premultiply_via_order_currency( 'tejcart_order_items', array( 'line_total' ), 'order_id' );
            self::premultiply_via_order_currency( 'tejcart_order_refunds', array( 'amount' ), 'order_id' );
            self::premultiply_by_own_currency( 'tejcart_daily_summary', array( 'revenue', 'refund_total', 'tax_total' ), 'currency', $default_currency );
            self::premultiply_by_own_currency( 'tejcart_product_daily', array( 'revenue' ), 'currency', $default_currency );
            // Tier-2 abandoned carts follow the same convention; their
            // BIGINT type change happens later this request (Tier2::boot on
            // the `init` action, after this plugins_loaded callback), so the
            // column is still DECIMAL here — correct ordering.
            self::premultiply_by_own_currency( 'tejcart_abandoned_carts', array( 'cart_total' ), 'currency', $default_currency );

            // Flag written INSIDE the transaction so the conversion and its
            // completion marker commit atomically.
            update_option( self::MONEY_MIGRATION_FLAG, '1', false );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'ROLLBACK' );
            // Drop the optimistic in-memory option write so the rolled-back
            // flag is not falsely observed later this request.
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( 'alloptions', 'options' );
                wp_cache_delete( self::MONEY_MIGRATION_FLAG, 'options' );
            }
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Money-representation migration rolled back: ' . $e->getMessage() . ' — will retry next request.', 'error' );
            }
        }
    }

    /**
     * Multiply the given money columns of $table by each row's own
     * currency minor-unit factor, in place, while the columns are still
     * DECIMAL. Zero-decimal currencies (JPY, KRW, …) have factor 1 and are
     * skipped. Rows with an empty currency fall back to $fallback_currency.
     *
     * @param string        $table            Unprefixed table name.
     * @param list<string>  $columns          Money columns to convert.
     * @param string        $currency_col     Per-row currency column name.
     * @param string        $fallback_currency Currency for empty-currency rows.
     */
    private static function premultiply_by_own_currency( string $table, array $columns, string $currency_col, string $fallback_currency ): void {
        global $wpdb;

        if ( ! \TejCart\DB\Schema::table_exists( $table ) ) {
            return;
        }
        $full = $wpdb->prefix . $table;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $currencies = (array) $wpdb->get_col( "SELECT DISTINCT {$currency_col} FROM {$full}" );

        foreach ( $currencies as $raw_currency ) {
            $code   = ( '' !== (string) $raw_currency ) ? (string) $raw_currency : $fallback_currency;
            $factor = \TejCart\Money\Currency::multiplier( $code );
            if ( $factor <= 1 ) {
                continue; // zero-decimal currency: minor units == major units.
            }
            $set = implode( ', ', array_map(
                static function ( string $col ) use ( $factor ): string {
                    return "{$col} = ROUND({$col} * {$factor})";
                },
                $columns
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full/$set are internal identifiers + integer factor; currency value is bound.
            $wpdb->query( $wpdb->prepare( "UPDATE {$full} SET {$set} WHERE {$currency_col} = %s", (string) $raw_currency ) );
        }
    }

    /**
     * Multiply money columns of a child table (order_items, order_refunds)
     * that has no currency column of its own, joining to the parent order
     * for the currency. Mirrors {@see premultiply_by_own_currency()}.
     *
     * @param string       $table     Unprefixed child table name.
     * @param list<string> $columns   Money columns to convert.
     * @param string       $order_fk  Column on $table referencing tejcart_orders.id.
     */
    private static function premultiply_via_order_currency( string $table, array $columns, string $order_fk ): void {
        global $wpdb;

        if ( ! \TejCart\DB\Schema::table_exists( $table ) ) {
            return;
        }
        $full   = $wpdb->prefix . $table;
        $orders = $wpdb->prefix . 'tejcart_orders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $currencies = (array) $wpdb->get_col( "SELECT DISTINCT currency FROM {$orders}" );

        foreach ( $currencies as $raw_currency ) {
            $code   = ( '' !== (string) $raw_currency ) ? (string) $raw_currency : 'USD';
            $factor = \TejCart\Money\Currency::multiplier( $code );
            if ( $factor <= 1 ) {
                continue;
            }
            $set = implode( ', ', array_map(
                static function ( string $col ) use ( $factor ): string {
                    return "t.{$col} = ROUND(t.{$col} * {$factor})";
                },
                $columns
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal identifiers + integer factor; currency value is bound.
            $wpdb->query( $wpdb->prepare( "UPDATE {$full} t JOIN {$orders} o ON t.{$order_fk} = o.id SET {$set} WHERE o.currency = %s", (string) $raw_currency ) );
        }
    }

    /**
     * Backfill the split coupon money columns from the legacy `amount`
     * column. 1.0.0 stored a single `amount DECIMAL` (a whole percent for
     * percentage coupons, major-unit money otherwise) plus
     * `minimum_amount`/`maximum_amount`; 1.0.1 splits these into
     * `percentage_basis_points` and `*_minor_units`. dbDelta adds the new
     * columns (NULL) but never migrates the data, so without this every
     * pre-existing coupon would read as a $0 / 0% discount.
     *
     * Runs AFTER create_tables() (new columns must exist) and is idempotent
     * — each UPDATE only touches rows whose target column is still NULL, so
     * it never clobbers a coupon saved under 1.0.1.
     */
    public static function migrate_coupon_amounts(): void {
        global $wpdb;

        if ( ! \TejCart\DB\Schema::table_exists( 'tejcart_coupons' ) ) {
            return;
        }
        // The legacy `amount` column only exists on an upgraded store
        // (dbDelta leaves dropped columns in place). A fresh 1.0.1 install
        // never has it — nothing to backfill.
        if ( ! \TejCart\DB\Schema::column_exists( 'tejcart_coupons', 'amount' ) ) {
            return;
        }

        $coupons = $wpdb->prefix . 'tejcart_coupons';
        $factor  = \TejCart\Money\Currency::multiplier( (string) get_option( 'tejcart_currency', 'USD' ) );

        // Percentage coupons: 1.0.0 `amount` held a whole percent (10.0000
        // == 10%); 1.0.1 stores basis points (1% == 100 bp).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "UPDATE {$coupons} SET percentage_basis_points = ROUND(amount * 100) WHERE type = 'percentage' AND percentage_basis_points IS NULL" );

        // Fixed-value coupons: major units -> integer minor units.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal identifier + integer factor.
        $wpdb->query( "UPDATE {$coupons} SET amount_minor_units = ROUND(amount * {$factor}) WHERE type <> 'percentage' AND amount_minor_units IS NULL" );

        // Spend thresholds are always money, regardless of coupon type.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "UPDATE {$coupons} SET minimum_amount_minor_units = ROUND(minimum_amount * {$factor}) WHERE minimum_amount_minor_units IS NULL AND minimum_amount IS NOT NULL" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "UPDATE {$coupons} SET maximum_amount_minor_units = ROUND(maximum_amount * {$factor}) WHERE maximum_amount_minor_units IS NULL AND maximum_amount IS NOT NULL" );
    }

    /**
     * Option flag marking the order settlement-column backfill complete, so
     * a 100M-row store does not re-scan every id range on each later upgrade.
     */
    private const ORDER_BASE_BACKFILL_FLAG = 'tejcart_orders_base_backfilled';

    /**
     * Option flag marking the 1.0.5 per-component base-amount backfill
     * complete (orders.base_subtotal / …, order_items.base_line_total,
     * order_refunds.base_amount). Separate from {@see self::ORDER_BASE_BACKFILL_FLAG}
     * so installs that already ran the base_total/base_currency/fx_rate
     * backfill still pick up the new columns exactly once.
     */
    private const ORDER_BASE_AMOUNTS_BACKFILL_FLAG = 'tejcart_orders_base_amounts_backfilled';

    /**
     * Backfill the settlement columns (`base_currency`, `base_total`,
     * `fx_rate`) on every pre-existing order so consolidated base-currency
     * reporting reads authoritative per-order data straight from the orders
     * table — no join to `tejcart_order_meta` on the hot reporting path.
     *
     * Data sources, in priority order:
     *
     *  1. The currency-switcher module's historical FX meta, when present:
     *       `_tejcart_csw_base_total`  (major units, base currency)
     *       `_tejcart_csw_fx_rate`     (effective base→display rate)
     *     These are the module's well-known keys; this migration knows about
     *     them the same way `migrate_coupon_amounts()` knows the legacy
     *     `amount` column. Absent meta simply yields the identity default.
     *
     *  2. Identity default for every other row (no module, or orders placed
     *     before the module was enabled): settlement == presentment, i.e.
     *     `base_currency = currency`, `base_total = total`, `fx_rate = 1`.
     *     Such rows were necessarily charged in the store base currency, so
     *     no conversion is implied — the honest "rate unknown" answer.
     *
     * The store base currency is a single option (`tejcart_currency`), so the
     * major→minor unit factor for the meta path is computed once. The meta
     * `_tejcart_csw_base_total` is stored in MAJOR units (see
     * Order_Meta_Writer::fmt()), so it is multiplied by that factor to land
     * in the BIGINT minor-unit column.
     *
     * Idempotent: only touches rows still at the column default
     * (`base_currency = ''`), and short-circuits entirely once the completion
     * flag is set. Chunked by primary-key range so a very large orders table
     * is never updated in a single locking statement.
     */
    public static function migrate_order_base_currency(): void {
        global $wpdb;

        if ( '1' === (string) get_option( self::ORDER_BASE_BACKFILL_FLAG, '' ) ) {
            return;
        }
        if ( ! \TejCart\DB\Schema::table_exists( 'tejcart_orders' ) ) {
            return;
        }
        // Columns are added by create_tables() earlier in run_upgrade_steps();
        // guard anyway so a partial schema state cannot fatal the upgrade.
        if ( ! \TejCart\DB\Schema::column_exists( 'tejcart_orders', 'base_total' ) ) {
            return;
        }

        $orders = $wpdb->prefix . 'tejcart_orders';
        $meta   = $wpdb->prefix . 'tejcart_order_meta';
        $base   = (string) get_option( 'tejcart_currency', 'USD' );
        $factor = \TejCart\Money\Currency::multiplier( $base );

        $has_meta = \TejCart\DB\Schema::table_exists( 'tejcart_order_meta' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$orders}" );

        $chunk = 5000;
        for ( $lo = 0; $lo < $max_id; $lo += $chunk ) {
            $hi = $lo + $chunk;

            if ( $has_meta ) {
                // LEFT JOIN the two CSW meta keys; COALESCE to the identity
                // default when a row has no FX history. CAST the major-unit
                // base_total string to DECIMAL before scaling to minor units.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$orders} o
                            LEFT JOIN {$meta} bt ON bt.order_id = o.id AND bt.meta_key = '_tejcart_csw_base_total'
                            LEFT JOIN {$meta} br ON br.order_id = o.id AND br.meta_key = '_tejcart_csw_base_currency'
                            LEFT JOIN {$meta} fr ON fr.order_id = o.id AND fr.meta_key = '_tejcart_csw_fx_rate'
                            SET o.base_currency = CASE
                                    WHEN br.meta_value IS NOT NULL AND br.meta_value <> '' THEN br.meta_value
                                    ELSE o.currency END,
                                o.fx_rate = CASE
                                    WHEN fr.meta_value IS NOT NULL AND fr.meta_value <> '' AND CAST(fr.meta_value AS DECIMAL(20,10)) > 0
                                        THEN CAST(fr.meta_value AS DECIMAL(20,10))
                                    ELSE 1 END,
                                o.base_total = CASE
                                    WHEN bt.meta_value IS NOT NULL AND bt.meta_value <> ''
                                        THEN ROUND(CAST(bt.meta_value AS DECIMAL(30,10)) * %d)
                                    ELSE o.total END
                          WHERE o.id > %d AND o.id <= %d AND o.base_currency = ''",
                        $factor,
                        $lo,
                        $hi
                    )
                );
            } else {
                // No meta table at all — pure identity default.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$orders}
                            SET base_currency = currency, base_total = total, fx_rate = 1
                          WHERE id > %d AND id <= %d AND base_currency = ''",
                        $lo,
                        $hi
                    )
                );
            }
        }

        update_option( self::ORDER_BASE_BACKFILL_FLAG, '1', false );
    }

    /**
     * Backfill the per-component base-currency settlement columns added in
     * 1.0.5 from each row's `fx_rate` (populated by STEP 3b). Runs once,
     * chunked over the order id space so a large store does not lock the
     * table. `base = ROUND(transacted / fx_rate)`: identity for the common
     * fx_rate = 1 case, and consistent with the base_total figure STEP 3b
     * derived. base_total itself is left untouched (already backfilled).
     */
    private static function migrate_order_base_amounts(): void {
        global $wpdb;

        if ( '1' === (string) get_option( self::ORDER_BASE_AMOUNTS_BACKFILL_FLAG, '' ) ) {
            return;
        }
        if ( ! \TejCart\DB\Schema::table_exists( 'tejcart_orders' )
            || ! \TejCart\DB\Schema::column_exists( 'tejcart_orders', 'base_tax_total' )
        ) {
            return;
        }

        $orders  = $wpdb->prefix . 'tejcart_orders';
        $items   = $wpdb->prefix . 'tejcart_order_items';
        $refunds = $wpdb->prefix . 'tejcart_order_refunds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$orders}" );

        $chunk = 5000;
        for ( $lo = 0; $lo < $max_id; $lo += $chunk ) {
            $hi = $lo + $chunk;

            // Orders: divide each transacted component by the stamped rate.
            // NULLIF guards a pathological fx_rate = 0 (treated as identity).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$orders}
                        SET base_subtotal       = ROUND(subtotal       / COALESCE(NULLIF(fx_rate, 0), 1)),
                            base_discount_total = ROUND(discount_total / COALESCE(NULLIF(fx_rate, 0), 1)),
                            base_shipping_total = ROUND(shipping_total / COALESCE(NULLIF(fx_rate, 0), 1)),
                            base_tax_total      = ROUND(tax_total      / COALESCE(NULLIF(fx_rate, 0), 1))
                      WHERE id > %d AND id <= %d",
                    $lo,
                    $hi
                )
            );

            if ( \TejCart\DB\Schema::column_exists( 'tejcart_order_items', 'base_line_total' ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$items} oi
                            INNER JOIN {$orders} o ON o.id = oi.order_id
                            SET oi.base_line_total = ROUND(oi.line_total / COALESCE(NULLIF(o.fx_rate, 0), 1))
                          WHERE o.id > %d AND o.id <= %d",
                        $lo,
                        $hi
                    )
                );
            }

            if ( \TejCart\DB\Schema::table_exists( 'tejcart_order_refunds' )
                && \TejCart\DB\Schema::column_exists( 'tejcart_order_refunds', 'base_amount' )
            ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$refunds} r
                            INNER JOIN {$orders} o ON o.id = r.order_id
                            SET r.base_amount = ROUND(r.amount / COALESCE(NULLIF(o.fx_rate, 0), 1))
                          WHERE o.id > %d AND o.id <= %d",
                        $lo,
                        $hi
                    )
                );
            }
        }

        update_option( self::ORDER_BASE_AMOUNTS_BACKFILL_FLAG, '1', false );
    }

    /**
     * Re-enable features that shipped in core in an older release but are
     * now opt-in modules (off by default), so a store that was using one
     * does not silently lose it on update.
     *
     * Tax providers (Stripe Tax / TaxJar / Avalara) were core in 1.0.0;
     * 1.0.1 moves them into the `tax-providers` module. If the merchant had
     * a live provider active (`tejcart_active_tax_provider` non-empty),
     * enable the module so live tax calculation keeps working.
     *
     * Bot mitigation (CAPTCHA / Turnstile / hCaptcha / reCAPTCHA) was the
     * always-on `features.bot_gate` core feature; it now ships as the
     * opt-in `captcha` module. If the merchant had a provider configured
     * (`tejcart_bot_gate_provider` other than `none`), enable that module
     * so live bot protection is not silently dropped on update.
     *
     * An explicit merchant module choice already on record is never
     * overridden in either case.
     */
    private static function preserve_legacy_modules(): void {
        if ( ! class_exists( '\\TejCart\\Modules\\Module_Manager' ) ) {
            return;
        }

        $option  = \TejCart\Modules\Module_Manager::OPTION;
        $enabled = get_option( $option, array() );
        if ( ! is_array( $enabled ) ) {
            $enabled = array();
        }
        $changed = false;

        // Tax providers: enable when a live provider is configured.
        $active_tax_provider = (string) get_option( 'tejcart_active_tax_provider', '' );
        if ( '' !== $active_tax_provider && ! array_key_exists( 'tax-providers', $enabled ) ) {
            $enabled['tax-providers'] = true;
            $changed                  = true;
        }

        // Captcha: enable when a bot-mitigation provider is configured.
        $bot_provider = (string) get_option( 'tejcart_bot_gate_provider', 'none' );
        if ( 'none' !== $bot_provider && '' !== $bot_provider && ! array_key_exists( 'captcha', $enabled ) ) {
            $enabled['captcha'] = true;
            $changed            = true;
        }

        if ( $changed ) {
            update_option( $option, $enabled );
        }
    }

    /**
     * Persist defaults for options introduced since the installed version.
     *
     * Every read site already passes a matching default, so this is not
     * required for correctness — it only makes the persisted value visible
     * on Settings screens. `add_option()` is a no-op when the row exists,
     * so a merchant's saved choice is never overwritten.
     */
    private static function backfill_new_option_defaults(): void {
        $defaults = array(
            'tejcart_tax_round_at_subtotal'        => 'no',
            'tejcart_show_checkout_menu'           => 'yes',
            'tejcart_pending_order_timeout'        => 24,
            'tejcart_review_media_enabled'         => 'no',
            'tejcart_review_videos_enabled'        => 'no',
            'tejcart_review_helpful_votes_enabled' => 'no',
        );
        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value );
        }
    }

    /**
     * Lowercased information_schema DATA_TYPE for a column, or '' when the
     * table/column does not exist. Used to detect the legacy DECIMAL money
     * shape before migrating.
     *
     * @param string $table  Unprefixed table name.
     * @param string $column Column name.
     * @return string e.g. 'decimal', 'bigint', or '' if not found.
     */
    private static function column_data_type( string $table, string $column ): string {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $type = $wpdb->get_var( $wpdb->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
            DB_NAME,
            $full,
            $column
        ) );
        return is_string( $type ) ? strtolower( $type ) : '';
    }

    /**
     * Run on plugin deactivation.
     *
     * Flushes rewrite rules and clears scheduled hooks.
     */
    public static function deactivate() {
        flush_rewrite_rules();

        $cron_hooks = array(
            'tejcart_cleanup_sessions',
            'tejcart_process_pending_orders',
            'tejcart_send_queued_emails',
            'tejcart_check_expired_coupons',
            'tejcart_check_pending_orders',
            'tejcart_send_low_stock_notifications',
            'tejcart_cleanup_logs',
            'tejcart_webhook_retry',
            'tejcart_stock_reservation_prune',
            // Recurring PayPal orphan-order sweep scheduled in PayPal_AJAX —
            // previously absent here, so it survived deactivation and kept
            // firing into a dead listener on every cron tick.
            'tejcart_paypal_orphan_order_sweep',
            // Recurring hooks scheduled by Action_Scheduler::schedule_built_in_tasks()
            // that were previously absent from this list — after a
            // deactivate they kept firing into nothing, leaving noisy
            // rows in the WP `cron` option.
            'tejcart_sweep_scheduled_sales',
            'tejcart_co_lock_cleanup',
            'tejcart_webhook_deliveries_cleanup',
            'tejcart_webhook_reconcile',
            // Audit H-20 (Core F-004): three more recurring hooks that
            // were absent — they survived deactivate and fired-and-no-op'd
            // on every WP cron tick.
            'tejcart_locks_sweep',
            'tejcart_partitions_roll',
            'tejcart_tier2_abandoned_cart_run',
        );

        foreach ( $cron_hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
            wp_clear_scheduled_hook( $hook );
        }
    }

    /**
     * Re-run the schema creation routine and report the database error
     * if dbDelta() couldn't create the products table.
     *
     * Returns the captured `$wpdb->last_error` from the recovery attempt
     * (empty string on success). Callers outside the activation path
     * (e.g. the CSV importer) use this to recover from a host where the
     * original activation-time dbDelta() silently failed — historically
     * because of an InnoDB row format that kept the per-index byte
     * limit at 767 and rejected long utf8mb4 unique keys before the
     * schema was tightened.
     *
     * @return string Captured database error, or '' when the products
     *                table exists after the run.
     */
    public static function ensure_tables(): string {
        global $wpdb;

        $previous_suppress = $wpdb->suppress_errors( true );
        $previous_show     = $wpdb->hide_errors();
        $wpdb->last_error  = '';

        self::create_tables();

        $captured = (string) $wpdb->last_error;

        $products_table = $wpdb->prefix . 'tejcart_products';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) ) === $products_table;

        if ( ! $exists ) {
            $minimal_sql = "CREATE TABLE IF NOT EXISTS {$products_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL DEFAULT '',
                slug VARCHAR(191) NOT NULL DEFAULT '',
                type VARCHAR(50) NOT NULL DEFAULT 'physical',
                status VARCHAR(20) NOT NULL DEFAULT 'publish',
                description LONGTEXT NOT NULL,
                short_description TEXT NOT NULL,
                sku VARCHAR(100) DEFAULT NULL,
                price DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
                sale_price DECIMAL(20,4) DEFAULT NULL,
                stock_quantity INT(11) DEFAULT NULL,
                stock_status VARCHAR(20) NOT NULL DEFAULT 'instock',
                manage_stock TINYINT(1) NOT NULL DEFAULT 0,
                backorders VARCHAR(10) NOT NULL DEFAULT 'no',
                sold_individually TINYINT(1) NOT NULL DEFAULT 0,
                min_purchase_quantity INT(10) UNSIGNED NOT NULL DEFAULT 1,
                max_purchase_quantity INT(10) UNSIGNED NOT NULL DEFAULT 0,
                weight DECIMAL(10,4) DEFAULT NULL,
                dimensions LONGTEXT DEFAULT NULL,
                tax_class VARCHAR(50) NOT NULL DEFAULT '',
                shipping_class VARCHAR(100) NOT NULL DEFAULT '',
                catalog_visibility VARCHAR(20) NOT NULL DEFAULT 'visible',
                featured TINYINT(1) NOT NULL DEFAULT 0,
                image_id BIGINT(20) UNSIGNED DEFAULT NULL,
                gallery_ids LONGTEXT DEFAULT NULL,
                downloadable TINYINT(1) NOT NULL DEFAULT 0,
                `virtual` TINYINT(1) NOT NULL DEFAULT 0,
                total_sales BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug),
                UNIQUE KEY sku (sku),
                KEY type (type),
                KEY status (status)
            ) " . $wpdb->get_charset_collate();

            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $minimal_sql );
            if ( '' !== (string) $wpdb->last_error ) {
                $captured = (string) $wpdb->last_error;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) ) === $products_table;
        }

        $wpdb->suppress_errors( $previous_suppress );
        if ( $previous_show ) {
            $wpdb->show_errors();
        }

        return $exists ? '' : $captured;
    }

    /**
     * H-2: best-effort FULLTEXT index. Idempotent — checks
     * information_schema.STATISTICS first.
     */
    private static function maybe_add_fulltext_index( string $table, string $name, string $columns ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        $full = $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
            DB_NAME,
            $full,
            $name
        ) );
        if ( $exists ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $previous = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full = $wpdb->prefix . internal table-name literal; columns/name are caller-supplied DDL identifiers.
        $wpdb->query( sprintf( 'ALTER TABLE %s ADD FULLTEXT INDEX %s (%s)', $full, $name, $columns ) );
        $wpdb->suppress_errors( $previous );
    }

    /**
     * C-5: best-effort `PARTITION BY RANGE COLUMNS(<col>)` on a new
     * tejcart table. Idempotent — checks information_schema.PARTITIONS
     * before issuing the ALTER and silently no-ops on MariaDB or
     * non-InnoDB engines that reject the syntax.
     *
     * Generates ~6 quarterly partitions covering the next 18 months and
     * a MAXVALUE catch-all. The Lock_Sweeper / archival cron is
     * responsible for adding future partitions and dropping old ones.
     */
    private static function maybe_partition( string $table, string $column ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        $full = $wpdb->prefix . $table;
        // Already partitioned?
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $partitioned = $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND PARTITION_NAME IS NOT NULL LIMIT 1',
            DB_NAME,
            $full
        ) );
        if ( $partitioned ) {
            return;
        }

        $year   = (int) gmdate( 'Y' );
        $parts  = array();
        for ( $offset = 0; $offset < 6; $offset++ ) {
            $q_year  = $year + (int) floor( $offset / 4 );
            $q_index = ( $offset % 4 ) + 1;
            $bound_month = ( $q_index * 3 ) + 1; // Q1 → bound month 4
            $bound_year  = $q_year;
            if ( $bound_month > 12 ) {
                $bound_month -= 12;
                $bound_year  += 1;
            }
            $parts[] = sprintf(
                "PARTITION p%dq%d VALUES LESS THAN ('%04d-%02d-01')",
                $q_year,
                $q_index,
                $bound_year,
                $bound_month
            );
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        $sql = sprintf(
            'ALTER TABLE %s PARTITION BY RANGE COLUMNS(%s) (%s)',
            $full,
            $column,
            implode( ",\n", $parts )
        );

        // Suppress errors so an unsupported environment doesn't surface
        // a noisy admin notice. The query failing here is an explicit
        // "we tried" marker — the table still works fine without
        // partitions; we just lose the maintenance benefit.
        $previous = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql composed from internal table-name literal and integer-derived partition bounds.
        $wpdb->query( $sql );
        $wpdb->suppress_errors( $previous );
    }

    /**
     * Reconcile a PRIMARY KEY whose column list changed between releases.
     *
     * dbDelta() can ADD an index but never DROP or MODIFY one. When a
     * table's PRIMARY KEY changes — e.g. F-002 widened the analytics
     * aggregate keys to include `currency` so multi-currency stores keep
     * per-currency totals apart — dbDelta sees the new composite key, fails
     * to match the narrower key already on disk, and issues
     * `ALTER TABLE … ADD PRIMARY KEY (…)` against a table that already has a
     * primary key. MySQL rejects that with errno 1068 ("Multiple primary
     * key defined"), which $wpdb logs on every single re-activation of an
     * install created before the key changed.
     *
     * Rebuild the key up front so the dbDelta pass that follows sees the
     * final shape and no-ops. Idempotent and safe to run on every
     * activation:
     *   - fresh install: the table does not exist yet → no-op (dbDelta
     *     creates the final shape directly);
     *   - already migrated: current key already equals $desired → no-op;
     *   - legacy install: current key differs → one atomic DROP + ADD.
     *
     * For the call sites here the new key is always a superset of the old
     * one, so the rebuild can never collide with an existing row.
     *
     * @param string       $table   Unprefixed table name.
     * @param list<string> $desired Ordered primary-key columns, mirroring
     *                              the CREATE TABLE statement for $table.
     */
    private static function maybe_reconcile_primary_key( string $table, array $desired ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        // A table that does not exist yet is left for dbDelta to create at
        // the final shape — nothing to reconcile.
        if ( ! \TejCart\DB\Schema::table_exists( $table ) ) {
            return;
        }

        $current = \TejCart\DB\Schema::primary_key_columns( $table );

        // MySQL stores column names as declared but matches them
        // case-insensitively; compare on a normalised copy.
        $normalize = static function ( array $cols ): array {
            return array_map( 'strtolower', $cols );
        };
        if ( $normalize( $current ) === $normalize( $desired ) ) {
            return;
        }

        $full    = $wpdb->prefix . $table;
        $columns = implode( ', ', array_map(
            static function ( string $col ): string {
                return '`' . str_replace( '`', '', $col ) . '`';
            },
            $desired
        ) );
        // A table with no primary key at all (degenerate, but possible
        // after a botched manual ALTER) just needs the ADD; otherwise drop
        // the stale key in the same atomic statement.
        $drop = ( array() === $current ) ? '' : 'DROP PRIMARY KEY, ';

        // Best-effort, mirroring maybe_partition(): suppress so a hosting
        // edge case (e.g. insufficient ALTER privilege) does not surface a
        // notice. If the rebuild cannot run, the dbDelta pass below surfaces
        // the original 1068 — no worse than before this guard existed.
        $previous = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full = $wpdb->prefix . internal table-name literal; $columns are internal DDL identifiers, not user input.
        $wpdb->query( "ALTER TABLE {$full} {$drop}ADD PRIMARY KEY ({$columns})" );
        $wpdb->suppress_errors( $previous );
    }

    /**
     * Create all required database tables using dbDelta.
     */
    private static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        $sql_products = "CREATE TABLE {$prefix}tejcart_products (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            slug VARCHAR(255) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL DEFAULT 'physical',
            status VARCHAR(20) NOT NULL DEFAULT 'publish',
            description LONGTEXT NOT NULL,
            short_description TEXT NOT NULL,
            sku VARCHAR(100) DEFAULT NULL,
            price DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
            sale_price DECIMAL(20,4) DEFAULT NULL,
            stock_quantity INT(11) DEFAULT NULL,
            stock_status VARCHAR(20) NOT NULL DEFAULT 'instock',
            manage_stock TINYINT(1) NOT NULL DEFAULT 0,
            backorders VARCHAR(10) NOT NULL DEFAULT 'no',
            sold_individually TINYINT(1) NOT NULL DEFAULT 0,
            min_purchase_quantity INT(10) UNSIGNED NOT NULL DEFAULT 1,
            max_purchase_quantity INT(10) UNSIGNED NOT NULL DEFAULT 0,
            weight DECIMAL(10,4) DEFAULT NULL,
            dimensions JSON DEFAULT NULL,
            tax_class VARCHAR(50) NOT NULL DEFAULT '',
            shipping_class VARCHAR(100) NOT NULL DEFAULT '',
            catalog_visibility VARCHAR(20) NOT NULL DEFAULT 'visible',
            featured TINYINT(1) NOT NULL DEFAULT 0,
            image_id BIGINT(20) UNSIGNED DEFAULT NULL,
            gallery_ids JSON DEFAULT NULL,
            downloadable TINYINT(1) NOT NULL DEFAULT 0,
            `virtual` TINYINT(1) NOT NULL DEFAULT 0,
            total_sales BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug(191)),
            UNIQUE KEY sku (sku),
            KEY type (type),
            KEY status (status),
            KEY stock_status (stock_status),
            KEY featured (featured),
            KEY catalog_visibility (catalog_visibility),
            KEY total_sales (total_sales),
            KEY created_at (created_at),
            KEY status_visibility (status, catalog_visibility, type),
            KEY status_stock_visibility (status, stock_status, catalog_visibility),
            KEY status_featured (status, featured),
            KEY status_manage_stock_qty (status, manage_stock, stock_quantity)
        ) $charset_collate;";

        $sql_product_meta = "CREATE TABLE {$prefix}tejcart_product_meta (
            meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL DEFAULT '',
            meta_value LONGTEXT,
            PRIMARY KEY  (meta_id),
            KEY product_id (product_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";

        // Money columns are integer minor units (BIGINT, signed so refunds
        // and credits can carry a sign). Storage exponent comes from the
        // row's `currency` column via TejCart\Money\Currency::decimals(),
        // so USD totals live in cents, JPY in yen, KWD in fils. See
        // docs/money-representation.md §2.1 for the canonical contract.
        //
        // billing_address / shipping_address are LONGTEXT, NOT JSON. They
        // hold an address blob that Address_Crypto encrypts at rest by
        // default (`tejcart_encrypt_addresses`, Audit M-36) — the AES-256-GCM
        // ciphertext (`tejc1:` prefix) is not valid JSON, and a JSON column
        // rejects it via MariaDB's implicit `CHECK (json_valid(col))`
        // constraint. Keep these LONGTEXT; serialisation/encryption is owned
        // by the application layer, not the column type. The mirror columns
        // on tejcart_customers (and tejcart_addresses in Tier-2) follow the
        // same rule.
        $sql_orders = "CREATE TABLE {$prefix}tejcart_orders (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_key VARCHAR(100) NOT NULL DEFAULT '',
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            subtotal BIGINT NOT NULL DEFAULT 0,
            discount_total BIGINT NOT NULL DEFAULT 0,
            shipping_total BIGINT NOT NULL DEFAULT 0,
            tax_total BIGINT NOT NULL DEFAULT 0,
            total BIGINT NOT NULL DEFAULT 0,
            base_currency VARCHAR(10) NOT NULL DEFAULT '',
            base_total BIGINT NOT NULL DEFAULT 0,
            base_subtotal BIGINT NOT NULL DEFAULT 0,
            base_discount_total BIGINT NOT NULL DEFAULT 0,
            base_shipping_total BIGINT NOT NULL DEFAULT 0,
            base_tax_total BIGINT NOT NULL DEFAULT 0,
            fx_rate DECIMAL(20,10) NOT NULL DEFAULT 1,
            payment_method VARCHAR(100) DEFAULT NULL,
            transaction_id VARCHAR(255) DEFAULT NULL,
            coupon_code VARCHAR(100) DEFAULT NULL,
            customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            customer_email VARCHAR(200) NOT NULL DEFAULT '',
            customer_name VARCHAR(200) NOT NULL DEFAULT '',
            billing_address LONGTEXT,
            shipping_address LONGTEXT,
            customer_note TEXT,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_key (order_key),
            KEY status (status),
            KEY customer_id (customer_id),
            KEY customer_email (customer_email(191)),
            KEY created_at (created_at),
            KEY order_number (order_number),
            KEY customer_created (customer_id, created_at),
            KEY customer_status (customer_id, status),
            KEY status_created (status, created_at),
            KEY currency_created (currency, created_at),
            KEY coupon_code (coupon_code, customer_email(64))
        ) $charset_collate;";
        // PR #8 of the perf roadmap: `currency_created` covers the
        // Daily_Summary aggregation query (`WHERE currency = X AND
        // created_at BETWEEN day_start AND day_end`). Leading with
        // currency keeps the index dense and collapses the date-range
        // scan to a contiguous read per currency. SQL line comments
        // inside the CREATE TABLE literal break dbDelta's parser
        // (it treats each `-- ...` line as a column definition and
        // emits a corrupt `ALTER TABLE ADD COLUMN -- ...`), so this
        // commentary lives in PHP, not SQL.
        // Audit F-002 (high-volume readiness): `status_created`
        // (status, created_at) covers the admin order-list query
        // (`WHERE status = X ORDER BY created_at DESC`). Without the
        // composite, MySQL satisfies the status equality OR the
        // created_at ordering — not both — and filesorts the matched
        // set, which degrades as the orders table grows into the
        // millions on a high-volume store.

        // unit_price stays DECIMAL(20,4) — per-unit prices legitimately need
        // sub-minor-unit precision ("1000 fasteners @ $0.0125"). The charged
        // line amount lives in line_total (BIGINT minor units) and is the
        // source of truth for what the customer is billed. See
        // docs/money-representation.md §2.4.
        $sql_order_items = "CREATE TABLE {$prefix}tejcart_order_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            quantity INT(11) NOT NULL DEFAULT 1,
            unit_price DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
            line_total BIGINT NOT NULL DEFAULT 0,
            base_line_total BIGINT NOT NULL DEFAULT 0,
            meta JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id)
        ) $charset_collate;";

        $sql_order_meta = "CREATE TABLE {$prefix}tejcart_order_meta (
            meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL DEFAULT '',
            meta_value LONGTEXT,
            PRIMARY KEY  (meta_id),
            KEY order_id (order_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";

        $sql_customers = "CREATE TABLE {$prefix}tejcart_customers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            email VARCHAR(200) NOT NULL DEFAULT '',
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            billing_address LONGTEXT,
            shipping_address LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            rfm_recency_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            rfm_frequency_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            rfm_monetary_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            rfm_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ltv_minor_units BIGINT NOT NULL DEFAULT 0,
            segment VARCHAR(30) NOT NULL DEFAULT '',
            order_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_order_at DATETIME DEFAULT NULL,
            rfm_updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id),
            KEY email (email(191)),
            KEY segment (segment),
            KEY rfm_score (rfm_score)
        ) $charset_collate;";

        $sql_customer_segments = "CREATE TABLE {$prefix}tejcart_customer_segments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            slug VARCHAR(200) NOT NULL DEFAULT '',
            type VARCHAR(20) NOT NULL DEFAULT 'custom',
            rules JSON DEFAULT NULL,
            priority INT NOT NULL DEFAULT 10,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug(191)),
            KEY type_status (type, status)
        ) $charset_collate;";

        // The legacy `amount` column was overloaded: fixed-currency amounts
        // for type=fixed/fixed_product, percentage 0–100 for type=percentage.
        // That cannot become Money because percentages are not money. PR 3
        // of #375 splits it into two disjoint, type-discriminated columns
        // (amount_minor_units / percentage_basis_points) so the Money API
        // never sees a percent. minimum/maximum are always money. See
        // docs/money-representation.md §2.5.
        $sql_coupons = "CREATE TABLE {$prefix}tejcart_coupons (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'fixed',
            amount_minor_units BIGINT DEFAULT NULL,
            percentage_basis_points SMALLINT UNSIGNED DEFAULT NULL,
            usage_limit INT(11) DEFAULT NULL,
            usage_count INT(11) NOT NULL DEFAULT 0,
            usage_limit_per_user INT UNSIGNED DEFAULT NULL,
            minimum_amount_minor_units BIGINT DEFAULT NULL,
            maximum_amount_minor_units BIGINT DEFAULT NULL,
            individual_use TINYINT(1) NOT NULL DEFAULT 0,
            exclude_sale_items TINYINT(1) NOT NULL DEFAULT 0,
            email_restrictions TEXT DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_coupon_usage = "CREATE TABLE {$prefix}tejcart_coupon_usage (
            coupon_id BIGINT(20) UNSIGNED NOT NULL,
            customer_email VARCHAR(189) NOT NULL,
            usage_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (coupon_id, customer_email),
            KEY coupon_id (coupon_id)
        ) $charset_collate;";

        $sql_sessions = "CREATE TABLE {$prefix}tejcart_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            session_value LONGTEXT NOT NULL,
            session_expiry BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY session_key (session_key),
            KEY session_expiry (session_expiry)
        ) $charset_collate;";

        $sql_term_relationships = "CREATE TABLE {$prefix}tejcart_term_relationships (
            product_id BIGINT(20) UNSIGNED NOT NULL,
            term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY  (product_id, term_taxonomy_id),
            KEY term_taxonomy_id (term_taxonomy_id)
        ) $charset_collate;";

        $sql_webhook_deliveries = "CREATE TABLE {$prefix}tejcart_webhook_deliveries (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            delivery_id CHAR(64) NOT NULL,
            webhook_id VARCHAR(64) NOT NULL,
            event VARCHAR(64) NOT NULL,
            status_code SMALLINT UNSIGNED DEFAULT NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY delivery_id (delivery_id),
            KEY webhook_event (webhook_id, event, created_at)
        ) $charset_collate;";

        $sql_stock_reservations = "CREATE TABLE {$prefix}tejcart_stock_reservations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            qty INT(11) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY product_session (product_id, session_id),
            KEY product_expires (product_id, expires_at),
            KEY session_expires (session_id, expires_at),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        // Refund amounts inherit the parent order's currency (read via the
        // order_id join); stored as integer minor units like the rest of
        // the money pipeline.
        $sql_order_refunds = "CREATE TABLE {$prefix}tejcart_order_refunds (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            transaction_ref VARCHAR(191) DEFAULT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            base_amount BIGINT NOT NULL DEFAULT 0,
            reason TEXT,
            items LONGTEXT,
            refunded_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY transaction_ref (transaction_ref),
            KEY order_id (order_id, created_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_api_keys = "CREATE TABLE {$prefix}tejcart_api_keys (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            description VARCHAR(200) DEFAULT '',
            permissions VARCHAR(10) NOT NULL DEFAULT 'read',
            consumer_key CHAR(64) NOT NULL,
            consumer_secret CHAR(64) NOT NULL,
            truncated_key VARCHAR(16) NOT NULL DEFAULT '',
            last_access DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY consumer_key (consumer_key),
            KEY user_id (user_id)
        ) $charset_collate;";

        $sql_download_permissions = "CREATE TABLE {$prefix}tejcart_download_permissions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            file_index INT(10) UNSIGNED NOT NULL DEFAULT 0,
            customer_email VARCHAR(200) NOT NULL DEFAULT '',
            access_granted DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            access_expires DATETIME DEFAULT NULL,
            downloads_remaining INT(11) DEFAULT NULL,
            download_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY customer_email (customer_email(191))
        ) $charset_collate;";

        $sql_stock_notifications = "CREATE TABLE {$prefix}tejcart_stock_notifications (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            token VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY product_email (product_id, email),
            KEY token (token)
        ) $charset_collate;";

        $sql_order_status_log = "CREATE TABLE {$prefix}tejcart_order_status_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            from_status VARCHAR(40) NOT NULL DEFAULT '',
            to_status VARCHAR(40) NOT NULL DEFAULT '',
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            reason TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id, created_at),
            KEY actor_id (actor_id)
        ) $charset_collate;";

        // S-4: lock primitive — replaces wp_options-resident webhook claims,
        // checkout idempotency, and PayPal capture locks. INSERT IGNORE on
        // a UNIQUE PRIMARY KEY gives the same atomic-claim semantics
        // without the alloptions cache churn that 70k/day add_option calls
        // imposed at scale.
        $sql_locks = "CREATE TABLE {$prefix}tejcart_locks (
            lock_key CHAR(64) NOT NULL,
            payload VARCHAR(255) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (lock_key),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        // C-2: PayPal events buffer — webhook entrypoint persists raw payloads
        // and returns 200 OK immediately; an Action Scheduler worker hydrates
        // and processes the row asynchronously. Decouples PayPal's 25s
        // response window from the in-process fulfilment work.
        $sql_paypal_events = "CREATE TABLE {$prefix}tejcart_paypal_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(128) NOT NULL,
            event_type VARCHAR(80) NOT NULL DEFAULT '',
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY status_received (status, received_at)
        ) $charset_collate;";

        // C-3: precomputed daily aggregates so the admin reports dashboard
        // stops doing live SUM() / GROUP BY over the 100M-row orders table
        // on every load.
        // revenue / refund_total / tax_total are SUM aggregates of the
        // corresponding BIGINT minor-units columns on tejcart_orders, so
        // storing them in major-unit DECIMAL here would round-trip
        // through float twice (write + read) for no reason. Keeping
        // them BIGINT preserves end-to-end integer precision.
        // F-007: new_customer_count / repeat_customer_count / avg_order_value
        // were declared but never populated by `Daily_Summary::rebuild_bucket()`
        // — they always read as the default 0. Dropped from the schema until
        // a writer is added. F-012: the secondary `day_currency` index is a
        // verbatim duplicate of `PRIMARY KEY (day, currency)` — removed.
        $sql_daily_summary = "CREATE TABLE {$prefix}tejcart_daily_summary (
            day DATE NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            revenue BIGINT NOT NULL DEFAULT 0,
            order_count INT UNSIGNED NOT NULL DEFAULT 0,
            refund_total BIGINT NOT NULL DEFAULT 0,
            refund_count INT UNSIGNED NOT NULL DEFAULT 0,
            coupon_count INT UNSIGNED NOT NULL DEFAULT 0,
            tax_total BIGINT NOT NULL DEFAULT 0,
            base_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            base_revenue BIGINT NOT NULL DEFAULT 0,
            base_refund_total BIGINT NOT NULL DEFAULT 0,
            base_tax_total BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (day, currency)
        ) $charset_collate;";

        // F-002: `currency` is now part of the primary key so a multi-currency
        // store can keep per-currency best-sellers totals separated. The
        // `Daily_Summary::read_top_products()` reader filters on this column.
        $sql_product_daily = "CREATE TABLE {$prefix}tejcart_product_daily (
            day DATE NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            qty INT UNSIGNED NOT NULL DEFAULT 0,
            revenue BIGINT NOT NULL DEFAULT 0,
            base_revenue BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (day, product_id, currency),
            KEY product_day (product_id, day)
        ) $charset_collate;";

        // M-2: request correlation — persist the X-Request-Id used during
        // checkout / capture so support can stitch buyer-reported issues
        // across the cart, payment, webhook, and fulfilment legs.
        $sql_request_log = "CREATE TABLE {$prefix}tejcart_request_log (
            request_id CHAR(36) NOT NULL,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            paypal_order_id VARCHAR(64) DEFAULT NULL,
            session_key VARCHAR(64) DEFAULT NULL,
            event_kind VARCHAR(40) NOT NULL DEFAULT '',
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (request_id),
            KEY order_id (order_id),
            KEY paypal_order_id (paypal_order_id),
            KEY recorded_at (recorded_at)
        ) $charset_collate;";

        // M-5 / S-3 audit: admin audit log for price / coupon / refund changes.
        $sql_admin_audit = "CREATE TABLE {$prefix}tejcart_admin_audit (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(64) NOT NULL DEFAULT '',
            target_type VARCHAR(40) NOT NULL DEFAULT '',
            target_id BIGINT(20) UNSIGNED DEFAULT NULL,
            details JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY actor_action (actor_id, action),
            KEY target (target_type, target_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_product_cooccurrence = "CREATE TABLE {$prefix}tejcart_product_cooccurrence (
            product_a_id BIGINT(20) UNSIGNED NOT NULL,
            product_b_id BIGINT(20) UNSIGNED NOT NULL,
            cooccurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
            frequency DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (product_a_id, product_b_id),
            KEY product_b (product_b_id),
            KEY frequency (frequency)
        ) $charset_collate;";

        $sql_product_reviews = "CREATE TABLE {$prefix}tejcart_product_reviews (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            author_name VARCHAR(200) NOT NULL DEFAULT '',
            author_email VARCHAR(200) NOT NULL DEFAULT '',
            author_ip VARCHAR(45) NOT NULL DEFAULT '',
            author_agent VARCHAR(255) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            content LONGTEXT NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
            verified_purchase TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            parent_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY status (status),
            KEY user_id (user_id),
            KEY product_status (product_id, status),
            KEY product_rating (product_id, status, rating),
            KEY parent_id (parent_id),
            KEY author_email (author_email(191))
        ) $charset_collate;";

        $sql_review_media = "CREATE TABLE {$prefix}tejcart_review_media (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT(20) UNSIGNED NOT NULL,
            media_type VARCHAR(10) NOT NULL DEFAULT 'photo',
            source_type VARCHAR(10) NOT NULL DEFAULT 'upload',
            attachment_id BIGINT(20) UNSIGNED DEFAULT NULL,
            embed_url VARCHAR(2083) DEFAULT NULL,
            file_path_encrypted TEXT DEFAULT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY comment_id (comment_id),
            KEY status (status),
            KEY comment_status (comment_id, status)
        ) $charset_collate;";

        $sql_review_votes = "CREATE TABLE {$prefix}tejcart_review_votes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT(20) UNSIGNED NOT NULL,
            vote TINYINT NOT NULL,
            voter_ip VARCHAR(45) NOT NULL DEFAULT '',
            voter_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY comment_id (comment_id),
            KEY comment_vote (comment_id, vote),
            KEY voter_user (voter_user_id, comment_id),
            KEY voter_ip (voter_ip(40), comment_id)
        ) $charset_collate;";

        // dbDelta() can ADD an index but never DROP or MODIFY one, so a
        // PRIMARY KEY whose columns changed between releases would make the
        // pass below emit "Multiple primary key defined" (MySQL 1068) on
        // every re-activation. F-002 widened the two analytics aggregate
        // keys to include `currency`; reconcile them to the final shape
        // first so dbDelta sees a matching key and no-ops. Mirrors the
        // PRIMARY KEY declarations on tejcart_daily_summary / tejcart_product_daily
        // above — keep the column tuples in sync if either key ever changes.
        self::maybe_reconcile_primary_key( 'tejcart_daily_summary', array( 'day', 'currency' ) );
        self::maybe_reconcile_primary_key( 'tejcart_product_daily', array( 'day', 'product_id', 'currency' ) );

        dbDelta( $sql_products );
        dbDelta( $sql_product_meta );
        dbDelta( $sql_orders );
        dbDelta( $sql_order_items );
        dbDelta( $sql_order_meta );
        dbDelta( $sql_customers );
        dbDelta( $sql_customer_segments );
        dbDelta( $sql_coupons );
        dbDelta( $sql_coupon_usage );
        dbDelta( $sql_sessions );
        dbDelta( $sql_term_relationships );
        dbDelta( $sql_webhook_deliveries );
        dbDelta( $sql_stock_reservations );
        dbDelta( $sql_order_refunds );
        dbDelta( $sql_api_keys );
        dbDelta( $sql_download_permissions );
        dbDelta( $sql_stock_notifications );
        dbDelta( $sql_order_status_log );
        dbDelta( $sql_locks );
        dbDelta( $sql_paypal_events );
        dbDelta( $sql_daily_summary );
        dbDelta( $sql_product_daily );
        dbDelta( $sql_request_log );
        dbDelta( $sql_admin_audit );
        dbDelta( $sql_product_cooccurrence );
        dbDelta( $sql_product_reviews );
        dbDelta( $sql_review_media );
        dbDelta( $sql_review_votes );

        // H-2: FULLTEXT index on customer name + email so admin search
        // can use MATCH...AGAINST instead of middle-wildcard LIKE on
        // 100M-row order tables. Suppressed errors — older MyISAM /
        // pre-5.6 InnoDB installs skip silently.
        self::maybe_add_fulltext_index(
            'tejcart_orders',
            'ft_customer_search',
            'customer_name, customer_email'
        );

        // C-5: best-effort partition the new high-growth tables by quarter
        // so range-scan queries stay fast as rows accumulate. Silent
        // no-op when partitioning is unsupported (older MariaDB,
        // non-InnoDB engines) — operators in those environments fall
        // through to the un-partitioned default and rely on archival.
        self::maybe_partition( 'tejcart_paypal_events', 'received_at' );
        self::maybe_partition( 'tejcart_request_log',   'recorded_at' );
        self::maybe_partition( 'tejcart_admin_audit',   'created_at' );
        // DB-010 note: tejcart_locks can NOT be RANGE-partitioned by
        // expires_at because PARTITION requires every unique key
        // (including PRIMARY KEY) to contain the partition column,
        // and tejcart_locks's atomic-claim contract requires
        // PRIMARY KEY (lock_key) alone. The existing
        // `KEY expires_at` index plus the LIMIT 5000 sweep in
        // Core\Lock::sweep_expired() is the correct shape at scale.

        self::add_composite_indexes();

        self::add_foreign_keys();
    }

    /**
     * MySQL does not support IF NOT EXISTS for CREATE INDEX, so we check
     * information_schema.STATISTICS before creating each index.
     */
    private static function add_composite_indexes() {
        global $wpdb;

        $indexes = array(
            array(
                'table'   => 'tejcart_sessions',
                'name'    => 'idx_session_lookup',
                'columns' => 'session_key, session_expiry',
            ),
            // Note: an inline `status_created (status, created_at)` index is
            // already declared on tejcart_orders in create_tables(), so no
            // separate composite index is added here for that column pair.
            array(
                'table'   => 'tejcart_orders',
                'name'    => 'idx_order_customer',
                'columns' => 'customer_email(191), status',
            ),
            array(
                'table'   => 'tejcart_order_items',
                'name'    => 'idx_order_items_order',
                'columns' => 'order_id, product_id',
            ),
            array(
                'table'   => 'tejcart_products',
                'name'    => 'idx_product_type_status',
                'columns' => 'type, status',
            ),
            array(
                'table'   => 'tejcart_coupons',
                'name'    => 'idx_coupon_code_status',
                'columns' => 'code, status',
            ),

            array(
                'table'   => 'tejcart_product_meta',
                'name'    => 'idx_meta_key_value',
                'columns' => 'meta_key(32), meta_value(32)',
            ),
            // High-volume / flash-sale cleanup path: release_session(),
            // release_for_order() and release_quantity_for_order() all key
            // by session_id. Without a leading-session_id index the cleanup
            // is a full scan once the reservations table has >10k active
            // rows during a peak event.
            array(
                'table'   => 'tejcart_stock_reservations',
                'name'    => 'idx_reservations_session',
                'columns' => 'session_id, expires_at',
            ),
        );

        // Route every raw CREATE INDEX through the Schema::index_exists()
        // helper instead of inline information_schema lookups (DB-007).
        // The behaviour is identical but the convention stays uniform
        // across the codebase and the helper has its own caching.
        $commentmeta_table = $wpdb->commentmeta;
        if ( ! \TejCart\DB\Schema::index_exists( 'commentmeta', 'idx_tejcart_review_lookup' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "CREATE INDEX idx_tejcart_review_lookup ON {$commentmeta_table} (meta_key(20), meta_value(20))" );
        }

        $products_table = $wpdb->prefix . 'tejcart_products';
        if ( ! \TejCart\DB\Schema::index_exists( 'tejcart_products', 'ft_product_search' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "ALTER TABLE {$products_table} ADD FULLTEXT INDEX ft_product_search (name, description, short_description, sku)" );
        }

        foreach ( $indexes as $idx ) {
            if ( \TejCart\DB\Schema::index_exists( $idx['table'], $idx['name'] ) ) {
                continue;
            }
            $table = $wpdb->prefix . $idx['table'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "CREATE INDEX {$idx['name']} ON {$table} ({$idx['columns']})" );
        }
    }

    /**
     * Add foreign key constraints to tables.
     *
     * These are added separately since dbDelta does not support FK constraints.
     */
    private static function add_foreign_keys() {
        global $wpdb;

        $prefix = $wpdb->prefix;

        $foreign_keys = array(
            'fk_product_meta_product_id' => "ALTER TABLE {$prefix}tejcart_product_meta
                ADD CONSTRAINT fk_product_meta_product_id
                FOREIGN KEY (product_id) REFERENCES {$prefix}tejcart_products(id)
                ON DELETE CASCADE",

            'fk_order_items_order_id' => "ALTER TABLE {$prefix}tejcart_order_items
                ADD CONSTRAINT fk_order_items_order_id
                FOREIGN KEY (order_id) REFERENCES {$prefix}tejcart_orders(id)
                ON DELETE CASCADE",

            'fk_order_items_product_id' => "ALTER TABLE {$prefix}tejcart_order_items
                ADD CONSTRAINT fk_order_items_product_id
                FOREIGN KEY (product_id) REFERENCES {$prefix}tejcart_products(id)
                ON DELETE RESTRICT",

            'fk_order_meta_order_id' => "ALTER TABLE {$prefix}tejcart_order_meta
                ADD CONSTRAINT fk_order_meta_order_id
                FOREIGN KEY (order_id) REFERENCES {$prefix}tejcart_orders(id)
                ON DELETE CASCADE",

            // No fk_orders_customer_id here. tejcart_orders.customer_id
            // holds the WordPress user_id (see Customer_Repository's
            // `LEFT JOIN tejcart_customers c ON c.user_id = o.customer_id`),
            // not the auto-incremented tejcart_customers.id, so an FK
            // pointing at tejcart_customers(id) would fail on every
            // logged-in checkout whose tejcart_customers row had not been
            // backfilled yet (the admin clicking a PayPal Express button
            // on a fresh site is the canonical trip-wire).

            'fk_order_refunds_order_id' => "ALTER TABLE {$prefix}tejcart_order_refunds
                ADD CONSTRAINT fk_order_refunds_order_id
                FOREIGN KEY (order_id) REFERENCES {$prefix}tejcart_orders(id)
                ON DELETE CASCADE",

            'fk_order_status_log_order_id' => "ALTER TABLE {$prefix}tejcart_order_status_log
                ADD CONSTRAINT fk_order_status_log_order_id
                FOREIGN KEY (order_id) REFERENCES {$prefix}tejcart_orders(id)
                ON DELETE CASCADE",

            // Download permissions belong to a parent order. Without
            // this FK, deleting the order leaves dangling permission
            // rows that the file-download endpoint will still honour
            // (DB-008 in the 2026-05-22 audit).
            'fk_download_permissions_order_id' => "ALTER TABLE {$prefix}tejcart_download_permissions
                ADD CONSTRAINT fk_download_permissions_order_id
                FOREIGN KEY (order_id) REFERENCES {$prefix}tejcart_orders(id)
                ON DELETE CASCADE",
        );

        foreach ( $foreign_keys as $constraint_name => $sql ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $fk_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM information_schema.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                    DB_NAME,
                    $constraint_name
                )
            );

            if ( ! $fk_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( $sql );

                // Audit M-43 (Core F-015): capture FK creation failures
                // (MyISAM silently rejects FKs; some MariaDB configs
                // no-op). Persist to an option so Tools → Status can
                // surface them.
                if ( '' !== $wpdb->last_error ) {
                    $warnings   = (array) get_option( 'tejcart_schema_warnings', array() );
                    $warnings[] = sprintf( '[%s] FK %s: %s', gmdate( 'Y-m-d H:i:s' ), $constraint_name, $wpdb->last_error );
                    if ( count( $warnings ) > 50 ) {
                        $warnings = array_slice( $warnings, -50 );
                    }
                    update_option( 'tejcart_schema_warnings', $warnings, false );
                }
            }
        }
    }

    /**
     * Create the TejCart 'customer' role if it does not already exist.
     *
     * Records ownership in the `tejcart_owns_customer_role` option so the
     * uninstaller only removes the role when TejCart was the one that
     * created it. If another plugin already defined `customer`, we leave
     * it alone on both ends.
     */
    private static function create_roles() {
        if ( ! get_role( 'customer' ) ) {
            add_role(
                'customer',
                __( 'Customer', 'tejcart' ),
                array(
                    'read' => true,
                )
            );
            update_option( 'tejcart_owns_customer_role', 'yes', false );
        }
    }

    /**
     * Set default plugin options.
     */
    private static function create_default_options() {
        $defaults = array(
            'tejcart_currency'                => 'USD',
            'tejcart_currency_position'       => 'left',
            'tejcart_thousand_separator'      => ',',
            'tejcart_decimal_separator'       => '.',
            'tejcart_num_decimals'            => 2,
            'tejcart_enable_tax'              => 'no',
            'tejcart_prices_include_tax'      => 'no',
            'tejcart_tax_round_at_subtotal'   => 'no',
            'tejcart_enable_shipping'         => 'no',
            'tejcart_guest_checkout'          => 'yes',
            'tejcart_enable_registration'     => 'yes',
            'tejcart_create_account_default'  => 'no',
            'tejcart_enable_cart_drawer'      => 'yes',
            'tejcart_redirect_after_add'      => 'no',
            'tejcart_enable_order_notes'      => 'yes',
            'tejcart_show_checkout_menu'      => 'yes',
            'tejcart_api_enabled'             => 'yes',
            'tejcart_store_address'           => '',
            'tejcart_store_city'              => '',
            'tejcart_store_postcode'          => '',
            'tejcart_store_country'           => 'US',
            'tejcart_weight_unit'             => 'kg',
            'tejcart_dimension_unit'          => 'cm',
            'tejcart_low_stock_threshold'     => 5,
            'tejcart_pending_order_timeout'   => 24,
            'tejcart_hide_out_of_stock'       => 'no',
            'tejcart_from_name'               => get_bloginfo( 'name' ),
            'tejcart_from_email'              => get_bloginfo( 'admin_email' ),
            'tejcart_enable_reviews'              => 'yes',
            'tejcart_review_media_enabled'        => 'no',
            'tejcart_review_videos_enabled'       => 'no',
            'tejcart_review_helpful_votes_enabled' => 'yes',
        );

        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value );
        }
    }

    /**
     * Create the canonical TejCart store pages on plugin activation and
     * persist each one's ID into its matching wp_options row so the
     * Settings page dropdowns come pre-selected.
     *
     * Pages installed (in fallback-menu order):
     *   - Shop               (tejcart_shop_page_id)
     *   - Cart               (tejcart_cart_page_id)
     *   - Checkout           (tejcart_checkout_page_id)
     *   - Thank You          (tejcart_thankyou_page_id)
     *   - My Account         (tejcart_myaccount_page_id)
     *   - Terms & Conditions (tejcart_terms_page_id)
     *
     * Each page gets an explicit `menu_order` so that themes falling back to
     * `wp_page_menu()` (when no nav menu has been assigned) render the
     * storefront pages in checkout-funnel order rather than alphabetically.
     *
     * Only creates pages that don't already have a valid option pointer.
     * Safe to re-run if activation fires twice during the same install.
     *
     * @return void
     */
    private static function install_pages(): void {
        $pages = array(
            array(
                'title'   => __( 'Shop', 'tejcart' ),
                'slug'    => 'shop',
                'content' => '<!-- wp:shortcode -->[tejcart_products]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_shop_page_id',
            ),
            array(
                'title'   => __( 'Cart', 'tejcart' ),
                'slug'    => 'cart',
                'content' => '<!-- wp:shortcode -->[tejcart_cart]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_cart_page_id',
            ),
            array(
                'title'   => __( 'Checkout', 'tejcart' ),
                'slug'    => 'checkout',
                'content' => '<!-- wp:shortcode -->[tejcart_checkout]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_checkout_page_id',
            ),
            array(
                'title'   => __( 'Thank You', 'tejcart' ),
                'slug'    => 'thank-you',
                'content' => '<!-- wp:shortcode -->[tejcart_thankyou]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_thankyou_page_id',
            ),
            array(
                'title'   => __( 'My Account', 'tejcart' ),
                'slug'    => 'my-account',
                'content' => '<!-- wp:shortcode -->[tejcart_account]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_myaccount_page_id',
            ),
            array(
                'title'   => __( 'Terms and Conditions', 'tejcart' ),
                'slug'    => 'terms-and-conditions',
                'content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Please add your store\'s terms and conditions here. This page is linked to from checkout.', 'tejcart' ) . '</p><!-- /wp:paragraph -->',
                'option'  => 'tejcart_terms_page_id',
            ),
        );

        foreach ( $pages as $index => $page ) {
            $existing_id = (int) get_option( $page['option'], 0 );
            if ( $existing_id > 0 ) {
                $existing_post = get_post( $existing_id );
                if ( $existing_post instanceof \WP_Post
                    && 'page' === $existing_post->post_type
                    && 'publish' === $existing_post->post_status ) {
                    continue;
                }
            }

            $new_id = wp_insert_post(
                array(
                    'post_title'     => $page['title'],
                    'post_content'   => $page['content'],
                    'post_status'    => 'publish',
                    'post_type'      => 'page',
                    'post_name'      => $page['slug'],
                    'menu_order'     => $index + 1,
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                ),
                true
            );

            if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
                update_option( $page['option'], $new_id );
            }
        }
    }
}
