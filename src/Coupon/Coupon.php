<?php
/**
 * Coupon Model
 *
 * @package TejCart\Coupon
 */

declare( strict_types=1 );

namespace TejCart\Coupon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single coupon and provides CRUD operations
 * against the wp_tejcart_coupons table.
 */
class Coupon {
    /**
     * @var int|null
     */
    private $id = null;

    /**
     * @var string
     */
    private $code = '';

    /**
     * Coupon type: percentage, fixed (== fixed_cart), fixed_product, or free_shipping.
     *
     * @var string
     */
    private $type = 'fixed';

    /**
     * When true, this coupon cannot be combined with any other coupon.
     *
     * @var bool
     */
    private $individual_use = false;

    /**
     * When true, sale-priced items are excluded from percentage and
     * fixed_product discount calculations.
     *
     * @var bool
     */
    private $exclude_sale_items = false;

    /**
     * Allow-list of customer emails (exact match, case-insensitive). An
     * empty array means "no restriction". Wildcards are not supported —
     * use `*@example.com` style entries matched by domain suffix.
     *
     * @var string[]
     */
    private $email_restrictions = array();

    /**
     * @var float
     */
    private $amount = 0.0;

    /**
     * Global usage limit. NULL means unlimited.
     *
     * @var int|null
     */
    private $usage_limit = null;

    /**
     * @var int
     */
    private $usage_count = 0;

    /**
     * Per-user usage limit. NULL means unlimited.
     *
     * @var int|null
     */
    private $usage_limit_per_user = null;

    /**
     * @var float|null
     */
    private $minimum_amount = null;

    /**
     * @var float|null
     */
    private $maximum_amount = null;

    /**
     * @var string|null  Datetime string or null.
     */
    private $expires_at = null;

    /**
     * @var string  active or inactive.
     */
    private $status = 'active';

    /**
     * @var string|null
     */
    private $created_at = null;

    /**
     * @var string|null
     */
    private $updated_at = null;

    /**
     * Constructor. Optionally loads an existing coupon by ID.
     *
     * @param int|null $id Coupon ID to load.
     */
    public function __construct( $id = null ) {
        if ( $id ) {
            $this->load( (int) $id );
        }
    }

    /**
     * Load coupon data from the database.
     *
     * @param int $id Coupon ID.
     */
    private function load( $id ) {
        // Audit #87 / 08 #15 — wp_cache the row read so cart pages with
        // auto-apply coupons don't re-run the SELECT on every
        // recalculation. The `tejcart_coupons` cache group is
        // declared global-persistent in Performance::$global_groups,
        // so this is safe against multisite + external object cache.
        $cache_key = 'coupon_' . (int) $id;
        $row       = wp_cache_get( $cache_key, 'tejcart_coupons' );

        if ( false === $row ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tejcart_coupons';

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $row ) {
                wp_cache_set( $cache_key, $row, 'tejcart_coupons', HOUR_IN_SECONDS );
            }
        }

        if ( ! $row ) {
            return;
        }

        $this->id                  = (int) $row->id;
        $this->code                = $row->code;
        $this->type                = $row->type;
        $this->usage_limit         = isset( $row->usage_limit ) && null !== $row->usage_limit ? (int) $row->usage_limit : null;
        $this->usage_count         = (int) $row->usage_count;
        $this->usage_limit_per_user = isset( $row->usage_limit_per_user ) && null !== $row->usage_limit_per_user ? (int) $row->usage_limit_per_user : null;
        $this->individual_use     = ! empty( $row->individual_use );
        $this->exclude_sale_items = ! empty( $row->exclude_sale_items );
        $this->email_restrictions = self::decode_email_restrictions( $row->email_restrictions ?? null );
        $this->expires_at          = $row->expires_at;
        $this->status              = $row->status;
        $this->created_at          = $row->created_at;
        $this->updated_at          = $row->updated_at;

        // The legacy `amount` column was overloaded across fixed-currency
        // and percent semantics. The new schema splits it into two
        // disjoint columns chosen by `type`; the in-memory float keeps
        // the back-compat shape (0–100 for percentage, major-unit
        // currency for fixed types) so extension code that reads
        // get_amount() continues to work without change. See
        // docs/money-representation.md §2.5.
        $currency           = self::shop_currency();
        $multi              = max( 1, (int) \TejCart\Money\Currency::multiplier( $currency ) );
        $amount_minor       = isset( $row->amount_minor_units ) && null !== $row->amount_minor_units ? (int) $row->amount_minor_units : null;
        $percent_bp         = isset( $row->percentage_basis_points ) && null !== $row->percentage_basis_points ? (int) $row->percentage_basis_points : null;
        $min_minor          = isset( $row->minimum_amount_minor_units ) && null !== $row->minimum_amount_minor_units ? (int) $row->minimum_amount_minor_units : null;
        $max_minor          = isset( $row->maximum_amount_minor_units ) && null !== $row->maximum_amount_minor_units ? (int) $row->maximum_amount_minor_units : null;

        if ( 'percentage' === $this->type && null !== $percent_bp ) {
            $this->amount = $percent_bp / 100.0;
        } elseif ( null !== $amount_minor ) {
            $this->amount = $amount_minor / $multi;
        } else {
            $this->amount = 0.0;
        }

        $this->minimum_amount = null !== $min_minor ? $min_minor / $multi : null;
        $this->maximum_amount = null !== $max_minor ? $max_minor / $multi : null;
    }

    /**
     * Resolve the shop currency once, defaulting to USD when the option
     * helper isn't bootstrapped (typical inside unit tests).
     */
    private static function shop_currency(): string {
        if ( function_exists( 'tejcart_get_currency' ) ) {
            $c = (string) tejcart_get_currency();
            if ( '' !== $c ) {
                return strtoupper( $c );
            }
        }
        if ( function_exists( 'get_option' ) ) {
            return strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) );
        }
        return 'USD';
    }

    /**
     * Coupon discount amount as Money for fixed-currency types, or null
     * for percentage / free-shipping coupons (which carry no monetary
     * face value). Use {@see get_percentage_basis_points()} for percent
     * coupons.
     */
    public function get_amount_money(): ?\TejCart\Money\Money {
        if ( ! in_array( $this->type, array( 'fixed', 'fixed_product' ), true ) ) {
            return null;
        }
        return \TejCart\Money\Money::from_decimal_string(
            number_format( (float) $this->amount, 4, '.', '' ),
            self::shop_currency()
        );
    }

    /**
     * Percentage as basis points (1% == 100 bp) for percentage coupons,
     * or null for other types. Stored as an integer so percentage math
     * never round-trips through a float.
     */
    public function get_percentage_basis_points(): ?int {
        if ( 'percentage' !== $this->type ) {
            return null;
        }
        return (int) round( (float) $this->amount * 100.0 );
    }

    /**
     * Minimum spend as Money, or null when no minimum is configured.
     */
    public function get_minimum_amount_money(): ?\TejCart\Money\Money {
        if ( null === $this->minimum_amount ) {
            return null;
        }
        return \TejCart\Money\Money::from_decimal_string(
            number_format( (float) $this->minimum_amount, 4, '.', '' ),
            self::shop_currency()
        );
    }

    /**
     * Maximum spend as Money, or null when no maximum is configured.
     */
    public function get_maximum_amount_money(): ?\TejCart\Money\Money {
        if ( null === $this->maximum_amount ) {
            return null;
        }
        return \TejCart\Money\Money::from_decimal_string(
            number_format( (float) $this->maximum_amount, 4, '.', '' ),
            self::shop_currency()
        );
    }

    /**
     * Decode the stored `email_restrictions` column into an array.
     *
     * Accepts JSON-encoded arrays and comma-separated strings for
     * backward compatibility.
     *
     * @param mixed $raw Column value.
     * @return string[]
     */
    private static function decode_email_restrictions( $raw ): array {
        if ( null === $raw || '' === $raw ) {
            return array();
        }

        $decoded = json_decode( (string) $raw, true );
        if ( is_array( $decoded ) ) {
            return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $decoded ) ) ) );
        }

        $parts = array_map( 'trim', explode( ',', (string) $raw ) );
        return array_values( array_filter( array_map( 'strtolower', $parts ) ) );
    }

    /**
     * @return int|null
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * @return string
     */
    public function get_code() {
        return $this->code;
    }

    /**
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * @return float
     */
    public function get_amount() {
        return $this->amount;
    }

    /**
     * @return int|null
     */
    public function get_usage_limit() {
        return $this->usage_limit;
    }

    /**
     * @return int
     */
    public function get_usage_count() {
        return $this->usage_count;
    }

    /**
     * @return int|null
     */
    public function get_usage_limit_per_user() {
        return $this->usage_limit_per_user;
    }

    /**
     * @return float|null
     */
    public function get_minimum_amount() {
        return $this->minimum_amount;
    }

    /**
     * @return float|null
     */
    public function get_maximum_amount() {
        return $this->maximum_amount;
    }

    /**
     * @return string|null
     */
    public function get_expires_at() {
        return $this->expires_at;
    }

    /**
     * @return string
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function get_created_at() {
        return $this->created_at;
    }

    /**
     * @return string|null
     */
    public function get_updated_at() {
        return $this->updated_at;
    }

    /**
     * @param string $code
     * @return self
     */
    public function set_code( $code ) {
        $this->code = sanitize_text_field( $code );
        return $this;
    }

    /**
     * @param string $type percentage|fixed|fixed_product|free_shipping or any
     *                     additional type ID registered via the
     *                     `tejcart_coupon_types` filter (e.g. "bogo",
     *                     "tiered_percentage").
     * @return self
     */
    public function set_type( $type ) {
        $core_types = array( 'percentage', 'fixed', 'fixed_product', 'free_shipping' );

        /**
         * Filter the registered coupon discount types.
         *
         * Third-party plugins (Smart Coupons, Advanced Coupons clones, BOGO
         * extensions) extend the whitelist here so custom types persist
         * through `set_type()` rather than silently falling back to "fixed".
         *
         * @param string[] $types Whitelisted type IDs.
         */
        $valid = (array) apply_filters( 'tejcart_coupon_types', $core_types );

        $this->type = in_array( $type, $valid, true ) ? $type : 'fixed';
        return $this;
    }

    /**
     * @return bool
     */
    public function is_individual_use(): bool {
        return $this->individual_use;
    }

    /**
     * @param bool $flag
     * @return self
     */
    public function set_individual_use( bool $flag ): self {
        $this->individual_use = $flag;
        return $this;
    }

    /**
     * @return bool
     */
    public function excludes_sale_items(): bool {
        return $this->exclude_sale_items;
    }

    /**
     * @param bool $flag
     * @return self
     */
    public function set_exclude_sale_items( bool $flag ): self {
        $this->exclude_sale_items = $flag;
        return $this;
    }

    /**
     * @return string[]
     */
    public function get_email_restrictions(): array {
        return $this->email_restrictions;
    }

    /**
     * Set the allowed-emails list. Accepts an array or a comma-separated
     * string. Emails are lower-cased and trimmed; empty entries removed.
     *
     * @param array|string $emails
     * @return self
     */
    public function set_email_restrictions( $emails ): self {
        if ( is_string( $emails ) ) {
            $emails = array_map( 'trim', explode( ',', $emails ) );
        }

        if ( ! is_array( $emails ) ) {
            $emails = array();
        }

        $this->email_restrictions = array_values(
            array_filter(
                array_map(
                    function ( $email ) {
                        return strtolower( trim( (string) $email ) );
                    },
                    $emails
                )
            )
        );

        return $this;
    }

    /**
     * Check whether a given email is allowed by the email restrictions list.
     *
     * An empty restriction list means "any email is allowed". Entries of
     * the form `*@example.com` match any email on that domain.
     *
     * @param string $email
     * @return bool
     */
    public function is_email_allowed( string $email ): bool {
        if ( empty( $this->email_restrictions ) ) {
            return true;
        }

        $email = strtolower( trim( $email ) );
        // Audit M-7 (Cart F-010): empty email means the buyer is a
        // guest on the cart page who hasn't entered their email yet.
        // Defer the restriction check to checkout (where it's re-
        // validated at Checkout.php:316-332) instead of blocking the
        // coupon apply with a confusing "not available for your email"
        // message when no email has been provided. This follows the
        // same common store pattern.
        if ( '' === $email ) {
            return true;
        }

        foreach ( $this->email_restrictions as $allowed ) {
            if ( $allowed === $email ) {
                return true;
            }

            if ( 0 === strpos( $allowed, '*@' ) ) {
                $suffix = substr( $allowed, 1 );
                if ( str_ends_with( $email, $suffix ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param float $amount
     * @return self
     */
    public function set_amount( $amount ) {
        $this->amount = max( 0.0, (float) $amount );
        return $this;
    }

    /**
     * @param int|null $limit
     * @return self
     */
    public function set_usage_limit( $limit ) {
        $this->usage_limit = ( null !== $limit && '' !== $limit ) ? absint( $limit ) : null;
        return $this;
    }

    /**
     * @param int $count
     * @return self
     */
    public function set_usage_count( $count ) {
        $this->usage_count = absint( $count );
        return $this;
    }

    /**
     * @param int|null $limit
     * @return self
     */
    public function set_usage_limit_per_user( $limit ) {
        $this->usage_limit_per_user = ( null !== $limit && '' !== $limit ) ? absint( $limit ) : null;
        return $this;
    }

    /**
     * @param float|null $amount
     * @return self
     */
    public function set_minimum_amount( $amount ) {
        $this->minimum_amount = ( null !== $amount && '' !== $amount ) ? max( 0.0, (float) $amount ) : null;
        return $this;
    }

    /**
     * @param float|null $amount
     * @return self
     */
    public function set_maximum_amount( $amount ) {
        $this->maximum_amount = ( null !== $amount && '' !== $amount ) ? max( 0.0, (float) $amount ) : null;
        return $this;
    }

    /**
     * @param string|null $date
     * @return self
     */
    public function set_expires_at( $date ) {
        $this->expires_at = ! empty( $date ) ? sanitize_text_field( $date ) : null;
        return $this;
    }

    /**
     * @param string $status active|inactive
     * @return self
     */
    public function set_status( $status ) {
        $this->status = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
        return $this;
    }

    /**
     * Save the coupon to the database (INSERT or UPDATE).
     *
     * @return bool True on success.
     */
    public function save() {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_coupons';
        $currency = self::shop_currency();
        $multi    = max( 1, (int) \TejCart\Money\Currency::multiplier( $currency ) );

        // Discriminator-aware projection onto the disjoint storage columns.
        // percentage coupons fill percentage_basis_points and leave
        // amount_minor_units NULL; fixed and fixed_product coupons do the
        // inverse; free_shipping carries neither. The Money API never sees
        // a percentage value.
        $amount_minor_units      = null;
        $percentage_basis_points = null;

        if ( 'percentage' === $this->type ) {
            $percentage_basis_points = max( 0, (int) round( (float) $this->amount * 100.0 ) );
        } elseif ( in_array( $this->type, array( 'fixed', 'fixed_product' ), true ) ) {
            $amount_minor_units = (int) \TejCart\Money\Currency::to_minor_units(
                max( 0.0, (float) $this->amount ),
                $currency
            );
        }

        $min_minor = ( null !== $this->minimum_amount )
            ? (int) \TejCart\Money\Currency::to_minor_units( max( 0.0, (float) $this->minimum_amount ), $currency )
            : null;
        $max_minor = ( null !== $this->maximum_amount )
            ? (int) \TejCart\Money\Currency::to_minor_units( max( 0.0, (float) $this->maximum_amount ), $currency )
            : null;

        $data = array(
            'code'                       => $this->code,
            'type'                       => $this->type,
            'amount_minor_units'         => $amount_minor_units,
            'percentage_basis_points'    => $percentage_basis_points,
            'usage_limit'                => $this->usage_limit,
            'usage_count'                => $this->usage_count,
            'usage_limit_per_user'       => $this->usage_limit_per_user,
            'minimum_amount_minor_units' => $min_minor,
            'maximum_amount_minor_units' => $max_minor,
            'individual_use'             => $this->individual_use ? 1 : 0,
            'exclude_sale_items'         => $this->exclude_sale_items ? 1 : 0,
            'email_restrictions'         => empty( $this->email_restrictions )
                ? null
                : (string) wp_json_encode( $this->email_restrictions ),
            'expires_at'                 => $this->expires_at,
            'status'                     => $this->status,
        );

        // Per-column nullability — each NULL entry tells the loop below to
        // emit a literal SQL NULL instead of binding a value.
        $format = array(
            '%s', // code
            '%s', // type
            null === $amount_minor_units ? null : '%d',
            null === $percentage_basis_points ? null : '%d',
            null === $this->usage_limit ? null : '%d',
            '%d', // usage_count
            null === $this->usage_limit_per_user ? null : '%d',
            null === $min_minor ? null : '%d',
            null === $max_minor ? null : '%d',
            '%d', // individual_use
            '%d', // exclude_sale_items
            null === $data['email_restrictions'] ? null : '%s',
            null === $this->expires_at ? null : '%s',
            '%s', // status
        );

        $clean_data   = array();
        $clean_format = array();
        $keys         = array_keys( $data );

        foreach ( $keys as $i => $key ) {
            $fmt = array_values( $format )[ $i ];
            if ( null === $fmt ) {
                $clean_data[ $key ]   = null;
                $clean_format[]       = null;
            } else {
                $clean_data[ $key ]   = $data[ $key ];
                $clean_format[]       = $fmt;
            }
        }

        if ( $this->id ) {
            $set_parts    = array();
            $values       = array();
            $clean_keys   = array_keys( $clean_data );
            $clean_fmts   = array_values( $clean_format );

            foreach ( $clean_data as $col => $val ) {
                if ( null === $val ) {
                    $set_parts[] = "`{$col}` = NULL";
                } else {
                    // F-CCM-014: use the per-column format (%d/%s) from the $clean_format
                    // array built above rather than an always-%s inline ternary. This makes
                    // the prepared-statement type semantically precise (integer columns bind
                    // as integers, string columns as strings) so PHPCS and static analysis
                    // can verify correctness.
                    $idx         = array_search( $col, $clean_keys, true );
                    $placeholder = ( false !== $idx && isset( $clean_fmts[ $idx ] ) )
                        ? $clean_fmts[ $idx ]
                        : '%s';
                    $set_parts[] = "`{$col}` = " . $placeholder;
                    $values[]    = $val;
                }
            }

            $values[] = $this->id;
            $sql      = "UPDATE {$table} SET " . implode( ', ', $set_parts ) . " WHERE id = %d";

            // $table is from $wpdb->prefix; column names in $set_parts come from the hardcoded $data keys.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

            if ( false !== $result ) {
                // Audit #87: invalidate the row cache wired up in
                // load() so subsequent reads pick up the new data.
                wp_cache_delete( 'coupon_' . (int) $this->id, 'tejcart_coupons' );

                /**
                 * Fires after a coupon row is successfully updated.
                 *
                 * F-M9 / #943 — Tier-2 Advanced Coupons listens here to
                 * persist its rule rows when an admin saves a coupon
                 * (previously inert because the hook was never fired).
                 *
                 * @param int    $coupon_id
                 * @param Coupon $coupon
                 */
                do_action( 'tejcart_admin_coupon_saved', $this->id, $this );
            }

            return false !== $result;
        }

        $columns     = array();
        $placeholders = array();
        $values      = array();

        foreach ( $clean_data as $col => $val ) {
            $columns[] = "`{$col}`";
            if ( null === $val ) {
                $placeholders[] = 'NULL';
            } else {
                $placeholders[] = '%s';
                $values[]       = $val;
            }
        }

        $sql = "INSERT INTO {$table} (" . implode( ', ', $columns ) . ") VALUES (" . implode( ', ', $placeholders ) . ")";

        // $table is from $wpdb->prefix; column names in $columns come from the hardcoded $data keys.
        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( $sql );
        }

        if ( false !== $result ) {
            $this->id = (int) $wpdb->insert_id;
            // Audit #87: paranoia — INSERT shouldn't have a stale cache
            // entry (no row existed before), but invalidate anyway to
            // tolerate code paths that pre-create the id externally.
            wp_cache_delete( 'coupon_' . (int) $this->id, 'tejcart_coupons' );
            /** @see filter docs above on the UPDATE branch. */
            do_action( 'tejcart_admin_coupon_saved', $this->id, $this );
            return true;
        }

        return false;
    }

    /**
     * Delete this coupon from the database.
     *
     * @return bool True on success.
     */
    public function delete() {
        if ( ! $this->id ) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_coupons';

        $code_lc = strtolower( (string) $this->code );

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $this->id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false !== $result ) {
            // Audit #87: drop the row cache so any subsequent
            // `new Coupon( $deleted_id )` lookup doesn't resurrect
            // ghost data from the wp_cache layer.
            wp_cache_delete( 'coupon_' . (int) $this->id, 'tejcart_coupons' );

            if ( '' !== $code_lc ) {
                $auto_codes = array_map( 'strtolower', (array) get_option( 'tejcart_auto_apply_coupons', array() ) );
                $cleaned    = array_values( array_diff( $auto_codes, array( $code_lc ) ) );
                if ( $cleaned !== $auto_codes ) {
                    update_option( 'tejcart_auto_apply_coupons', $cleaned );
                }
            }

            $this->id = null;
            return true;
        }

        return false;
    }

    /**
     * Check whether the coupon is currently valid for use.
     *
     * @param string $email Optional customer email for per-user limit check.
     * @return true|\WP_Error True if valid, WP_Error with reason otherwise.
     */
    public function is_valid( $email = '', $cart_subtotal = null ) {
        if ( ! $this->id ) {
            return new \WP_Error( 'invalid_coupon', __( 'This coupon does not exist.', 'tejcart' ) );
        }

        if ( 'active' !== $this->status ) {
            return new \WP_Error( 'coupon_inactive', __( 'This coupon is not active.', 'tejcart' ) );
        }

        if ( ! empty( $this->expires_at ) ) {
            // C-H3: expires_at is stored as a SITE-LOCAL wall-clock string.
            // The admin form (Menu.php) writes the date the merchant picked
            // plus ' 23:59:59' with no timezone conversion, so a merchant in
            // UTC-7 who sets "expires 2026-06-30" means end-of-day in their
            // own timezone. Parsing it as UTC (as this code previously did)
            // expired the coupon 7h early for that merchant and disagreed
            // with the admin list table, which renders it site-local.
            // Parse against wp_timezone() so the wall-clock the merchant
            // entered is interpreted in the site's timezone, then compare
            // against time() (UTC seconds) via getTimestamp().
            try {
                $expires_dt = new \DateTimeImmutable(
                    (string) $this->expires_at,
                    function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' )
                );
                if ( time() > $expires_dt->getTimestamp() ) {
                    return new \WP_Error( 'coupon_expired', __( 'This coupon has expired.', 'tejcart' ) );
                }
            } catch ( \Throwable $e ) {
                // Unparseable expires_at — treat as no expiry rather
                // than rejecting a legitimate coupon on bad data.
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log( sprintf(
                        'Coupon %d has malformed expires_at "%s"; treating as no expiry.',
                        (int) $this->id,
                        $this->expires_at
                    ), 'warning' );
                }
            }
        }

        if ( null !== $this->usage_limit && $this->usage_count >= $this->usage_limit ) {
            return new \WP_Error( 'coupon_usage_limit', __( 'This coupon has reached its usage limit.', 'tejcart' ) );
        }

        if ( ! $this->is_email_allowed( (string) $email ) ) {
            return new \WP_Error(
                'coupon_email_not_allowed',
                __( 'This coupon is not available for your email address.', 'tejcart' )
            );
        }

        if ( null !== $this->usage_limit_per_user && ! empty( $email ) ) {
            $user_count = $this->get_usage_count_for_user( $email );
            if ( $user_count >= $this->usage_limit_per_user ) {
                return new \WP_Error(
                    'coupon_usage_limit_per_user',
                    __( 'You have already used this coupon the maximum number of times.', 'tejcart' )
                );
            }
        }

        // minimum_amount/maximum_amount are stored in the shop (base) currency,
        // but $cart_subtotal is the ACTIVE-currency subtotal. Route the gates
        // through conversion filters (the currency switcher hooks them) so the
        // comparison — and the amount shown in the error message — are in the
        // same currency as the cart. Otherwise a $50 minimum compared against a
        // €46 subtotal wrongly rejects a qualifying buyer. Passthrough on a
        // single-currency store.
        $min_spend = ( null === $this->minimum_amount )
            ? null
            : (float) apply_filters( 'tejcart_coupon_min_spend', (float) $this->minimum_amount, $this );
        $max_spend = ( null === $this->maximum_amount )
            ? null
            : (float) apply_filters( 'tejcart_coupon_max_spend', (float) $this->maximum_amount, $this );

        if ( null !== $cart_subtotal && null !== $min_spend && $cart_subtotal < $min_spend ) {
            return new \WP_Error( 'coupon_minimum_amount', sprintf(
                /* translators: %s: minimum cart spend required for the coupon, formatted with currency. */
                __( 'This coupon requires a minimum spend of %s.', 'tejcart' ),
                tejcart_price( $min_spend )
            ) );
        }

        if ( null !== $cart_subtotal && null !== $max_spend && $max_spend > 0 && $cart_subtotal > $max_spend ) {
            return new \WP_Error( 'coupon_maximum_amount', sprintf(
                /* translators: %s: maximum cart spend allowed for the coupon, formatted with currency. */
                __( 'This coupon cannot be used on orders above %s.', 'tejcart' ),
                tejcart_price( $max_spend )
            ) );
        }

        return true;
    }

    /**
     * Get the number of times a specific user has used this coupon.
     *
     * Looks up completed/processing orders with this coupon code
     * and the given customer email.
     *
     * @param string $email Customer email address.
     * @return int Usage count for this user.
     */
    public function get_usage_count_for_user( $email ) {
        if ( empty( $email ) || ! $this->id ) {
            return 0;
        }

        global $wpdb;

        $usage_table = $wpdb->prefix . 'tejcart_coupon_usage';
        $email_norm  = strtolower( (string) $email );

        // $usage_table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT usage_count FROM {$usage_table} WHERE coupon_id = %d AND customer_email = %s LIMIT 1",
                $this->id,
                $email_norm
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( null !== $count ) {
            return (int) $count;
        }

        if ( empty( $this->code ) ) {
            return 0;
        }

        $orders_table = $wpdb->prefix . 'tejcart_orders';

        // $orders_table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            // F-CCM-007: use $email_norm (lowercase) in the fallback orders-table scan to
            // match the normalised email used in the usage-table lookup above. Without
            // this, a case-sensitive collation (utf8mb4_bin) would miss orders placed
            // with a differently-cased email, underreporting usage and allowing
            // per-user limit bypass via letter-case variation.
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE coupon_code = %s AND customer_email = %s AND status NOT IN ('cancelled','refunded')",
                $this->code,
                $email_norm
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $count;
    }

    /**
     * Atomically reserve one per-user usage of this coupon.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE with a conditional guard so
     * two concurrent checkouts for the same email cannot both squeeze
     * past the per-user limit. Always writes a row when an email is
     * provided so the dedup table stays authoritative — even when no
     * per-user limit is configured. This keeps the cold-fall-through
     * scan in get_usage_count_for_user() truly cold.
     *
     * @param string $email Customer email (normalised to lowercase).
     * @return bool True on success / no limit, false if the limit is reached.
     */
    public function reserve_usage_for_user( $email ) {
        if ( ! $this->id ) {
            return true;
        }

        $email = strtolower( sanitize_email( (string) $email ) );
        if ( '' === $email ) {
            return true;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'tejcart_coupon_usage';
        $has_limit    = null !== $this->usage_limit_per_user;
        $limit        = $has_limit ? (int) $this->usage_limit_per_user : 0;

        // When no per-user limit is configured, drop the IF guard so the
        // counter increments unconditionally. The INSERT path still seeds
        // a row at usage_count = 1 the first time. When a limit IS set, the
        // conditional guard prevents two concurrent checkouts from both
        // squeezing past the limit.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $has_limit ) {
            $affected = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "INSERT INTO {$table} (coupon_id, customer_email, usage_count)
                     VALUES (%d, %s, 1)
                     ON DUPLICATE KEY UPDATE
                         usage_count = IF(usage_count < %d, usage_count + 1, usage_count)",
                    $this->id,
                    $email,
                    $limit
                )
            );
        } else {
            $affected = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "INSERT INTO {$table} (coupon_id, customer_email, usage_count)
                     VALUES (%d, %s, 1)
                     ON DUPLICATE KEY UPDATE
                         usage_count = usage_count + 1",
                    $this->id,
                    $email
                )
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $affected ) {
            return false;
        }

        if ( ! $has_limit ) {
            // No limit configured — INSERT/UPDATE both report > 0 when the
            // counter advanced. We only return false on hard DB error.
            return true;
        }

        return $affected > 0;
    }

    /**
     * Increment the global usage count by one, atomically enforcing the usage limit.
     *
     * Uses a conditional UPDATE that only increments when usage_count is still
     * below the limit, preventing race conditions where concurrent checkouts could
     * exceed the allowed usage.
     *
     * @return bool True on success, false if the limit has been reached or on DB error.
     */
    public function increment_usage() {
        if ( ! $this->id ) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_coupons';

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $affected = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$table}
                 SET usage_count = usage_count + 1
                 WHERE id = %d
                   AND ( usage_limit IS NULL OR usage_count < usage_limit )",
                $this->id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $affected ) {
            return false;
        }

        if ( 0 === $affected ) {
            return false;
        }

        $this->usage_count++;
        return true;
    }

    /**
     * Look up a coupon by its case-insensitive code. Returns null when no
     * matching row exists. Used by the order coupon-rollback path so a
     * failed/refunded order can locate every coupon that was applied to
     * it without re-implementing the schema lookup.
     *
     * @param string $code
     */
    public static function get_by_code( string $code ): ?self {
        $code = trim( $code );
        if ( '' === $code ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            return null;
        }

        $coupon = new self( (int) $row->id );
        return $coupon->get_id() ? $coupon : null;
    }

    /**
     * Decrement the global usage count by one when an order that consumed
     * the coupon transitions to a non-redeemed state (failed, cancelled,
     * refunded). Clamped at zero so a malformed double-rollback can't
     * corrupt the counter.
     *
     * Without this rollback, payment failures and refunds permanently
     * exhaust the coupon's `usage_limit`. Idempotency is the caller's
     * responsibility: see Order_Coupon_Rollback, which uses an order-meta
     * marker to ensure each order rolls back its applied coupons only once.
     *
     * @return bool True on success, false on DB error.
     */
    public function decrement_usage(): bool {
        if ( ! $this->id ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $affected = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$table}
                    SET usage_count = GREATEST(0, usage_count - 1)
                  WHERE id = %d",
                $this->id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $affected ) {
            return false;
        }

        $this->usage_count = max( 0, $this->usage_count - 1 );
        return true;
    }

    /**
     * Decrement a per-user usage row, mirroring decrement_usage() above.
     * Clamped at zero. Returns true on success, false on DB error.
     *
     * @param string $email Customer email (normalised to lowercase).
     */
    public function release_usage_for_user( $email ): bool {
        if ( ! $this->id ) {
            return false;
        }

        $email = strtolower( sanitize_email( (string) $email ) );
        if ( '' === $email ) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupon_usage';

        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $affected = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$table}
                    SET usage_count = GREATEST(0, usage_count - 1)
                  WHERE coupon_id = %d AND customer_email = %s",
                $this->id,
                $email
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return false !== $affected;
    }

    /**
     * Return all coupon data as an associative array.
     *
     * @return array
     */
    public function to_array() {
        return array(
            'id'                   => $this->id,
            'code'                 => $this->code,
            'type'                 => $this->type,
            'amount'               => $this->amount,
            'usage_limit'          => $this->usage_limit,
            'usage_count'          => $this->usage_count,
            'usage_limit_per_user' => $this->usage_limit_per_user,
            'minimum_amount'       => $this->minimum_amount,
            'maximum_amount'       => $this->maximum_amount,
            'individual_use'       => $this->individual_use,
            'exclude_sale_items'   => $this->exclude_sale_items,
            'email_restrictions'   => $this->email_restrictions,
            'expires_at'           => $this->expires_at,
            'status'               => $this->status,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        );
    }

    /**
     * Create a Coupon instance from an import payload and persist it.
     *
     * Provides a documented API for bulk-import tools (store migration)
     * so they don't need direct $wpdb access against the coupons table.
     *
     * @param array<string,mixed> $data Column data. Expected keys: code, type, amount, etc.
     * @param int                 $id   Existing coupon ID for update, or 0 for insert.
     * @return int|\WP_Error Coupon ID on success, WP_Error on failure.
     */
    public static function import( array $data, int $id = 0 ) {
        $coupon = new self();

        if ( $id > 0 ) {
            $coupon->id = $id;
        }

        $setters = [
            'code'                => 'set_code',
            'type'                => 'set_type',
            'amount'              => 'set_amount',
            'usage_limit'         => 'set_usage_limit',
            'usage_count'         => 'set_usage_count',
            'usage_limit_per_user' => 'set_usage_limit_per_user',
            'minimum_amount'      => 'set_minimum_amount',
            'maximum_amount'      => 'set_maximum_amount',
            'individual_use'      => 'set_individual_use',
            'exclude_sale_items'  => 'set_exclude_sale_items',
            'email_restrictions'  => 'set_email_restrictions',
            'expires_at'          => 'set_expires_at',
            'status'              => 'set_status',
        ];

        foreach ( $setters as $key => $method ) {
            if ( array_key_exists( $key, $data ) && method_exists( $coupon, $method ) ) {
                $coupon->$method( $data[ $key ] );
            }
        }

        $data = apply_filters( 'tejcart_coupon_import_data', $data, $id );

        $ok = $coupon->save();
        if ( ! $ok ) {
            return new \WP_Error( 'coupon_save_failed', 'Failed to save imported coupon.' );
        }

        $coupon_id = $coupon->get_id();

        do_action( 'tejcart_coupon_imported', $coupon_id, $data, $id > 0 ? 'update' : 'insert' );

        return (int) $coupon_id;
    }
}
