<?php
/**
 * Order data object.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single order and provides CRUD operations against
 * the wp_tejcart_orders table.
 */
class Order {
    /**
     * Order ID.
     *
     * @var int
     */
    protected $id = 0;

    /**
     * Order data array matching wp_tejcart_orders columns.
     *
     * Money columns (subtotal, discount_total, shipping_total, tax_total,
     * total) are stored as **integer minor units** in the row's currency.
     * Float input through legacy setters is normalised at the boundary via
     * {@see TejCart\Money\Currency::to_minor_units()} so the in-memory
     * model matches what hits the BIGINT columns. See
     * docs/money-representation.md §2.1.
     *
     * @var array
     */
    protected $data = array(
        'order_key'        => '',
        'order_number'     => '',
        'status'           => 'pending',
        'currency'         => '',
        'subtotal'         => 0,
        'discount_total'   => 0,
        'shipping_total'   => 0,
        'tax_total'        => 0,
        'total'            => 0,
        'payment_method'   => '',
        'transaction_id'   => '',
        'coupon_code'      => '',
        'customer_id'      => 0,
        'customer_email'   => '',
        'customer_name'    => '',
        'billing_address'  => '',
        'shipping_address' => '',
        'customer_note'    => '',
        'ip_address'       => '',
        'created_at'       => '',
        'updated_at'       => '',
        // Consolidated base-currency settlement columns. `total`/etc. above
        // are denominated in `currency` (what the buyer was charged); these
        // mirror them in the store base currency for cross-currency reporting
        // and are derived in {@see self::sync_base_amounts()} on every save.
        'base_currency'       => '',
        'fx_rate'             => '1',
        'base_subtotal'       => 0,
        'base_discount_total' => 0,
        'base_shipping_total' => 0,
        'base_tax_total'      => 0,
        'base_total'          => 0,
    );

    /**
     * Column slots that store integer minor units. Used by {@see read()}
     * and {@see from_data()} to cast DB strings to int on hydration.
     */
    private const MONEY_SLOTS = array(
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'total',
    );

    /**
     * Constructor.
     *
     * @param int $id Optional. If provided, loads the order from the database.
     */
    public function __construct( $id = 0 ) {
        if ( $id ) {
            $this->id = absint( $id );
            $this->read();
        }
    }

    /**
     * Create an Order instance from a raw DB row array without a DB query.
     *
     * Used by Order_Manager::get_orders() to hydrate batch-fetched results,
     * eliminating the N+1 query pattern.
     *
     * @param array $row Associative array matching wp_tejcart_orders columns.
     * @return static
     */
    public static function from_data( array $row ): static {
        $instance     = new static();
        $instance->id = isset( $row['id'] ) ? (int) $row['id'] : 0;
        foreach ( $instance->data as $key => $default ) {
            if ( isset( $row[ $key ] ) ) {
                $instance->data[ $key ] = in_array( $key, self::MONEY_SLOTS, true )
                    ? (int) $row[ $key ]
                    : $row[ $key ];
            }
        }
        return $instance;
    }

    /**
     * Read order data from the database.
     */
    protected function read() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_orders';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $this->id ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $row ) {
            $this->id = (int) $row['id'];
            foreach ( $this->data as $key => $default ) {
                if ( isset( $row[ $key ] ) ) {
                    $this->data[ $key ] = in_array( $key, self::MONEY_SLOTS, true )
                        ? (int) $row[ $key ]
                        : $row[ $key ];
                }
            }
        } else {
            $this->id = 0;
        }
    }

    /**
     * Get order ID.
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get order key.
     *
     * @return string
     */
    public function get_order_key() {
        return $this->data['order_key'];
    }

    /**
     * Get order number.
     *
     * @return string
     */
    public function get_order_number() {
        return $this->data['order_number'];
    }

    /**
     * Get order status.
     *
     * @return string
     */
    public function get_status() {
        return $this->data['status'];
    }

    /**
     * Get currency code.
     *
     * @return string
     */
    public function get_currency() {
        return $this->data['currency'];
    }

    /**
     * Get subtotal as a major-unit float (back-compat).
     *
     * New code should prefer {@see get_subtotal_money()} for type safety.
     * The float is rendered from the canonical integer minor units, so no
     * precision is lost — but cross-currency math via this float is unsafe
     * because the result is rounded to the order's currency precision.
     *
     * @return float
     */
    public function get_subtotal() {
        return $this->minor_to_float( (int) $this->data['subtotal'] );
    }

    /**
     * Get discount total as a major-unit float (back-compat).
     *
     * @return float
     */
    public function get_discount_total() {
        return $this->minor_to_float( (int) $this->data['discount_total'] );
    }

    /**
     * Get shipping total as a major-unit float (back-compat).
     *
     * @return float
     */
    public function get_shipping_total() {
        return $this->minor_to_float( (int) $this->data['shipping_total'] );
    }

    /**
     * Get tax total as a major-unit float (back-compat).
     *
     * @return float
     */
    public function get_tax_total() {
        return $this->minor_to_float( (int) $this->data['tax_total'] );
    }

    /**
     * Get order total as a major-unit float (back-compat).
     *
     * Applies the tejcart_order_get_total filter. New code should prefer
     * {@see get_total_money()}; this float is for extension back-compat.
     *
     * @return float
     */
    public function get_total() {
        $total = $this->minor_to_float( (int) $this->data['total'] );
        return (float) apply_filters( 'tejcart_order_get_total', $total, $this );
    }

    /**
     * Get subtotal as Money in the order's currency.
     *
     * @return \TejCart\Money\Money
     */
    public function get_subtotal_money(): \TejCart\Money\Money {
        return \TejCart\Money\Money::from_minor_units( (int) $this->data['subtotal'], $this->effective_currency() );
    }

    /**
     * Get discount total as Money in the order's currency.
     *
     * @return \TejCart\Money\Money
     */
    public function get_discount_total_money(): \TejCart\Money\Money {
        return \TejCart\Money\Money::from_minor_units( (int) $this->data['discount_total'], $this->effective_currency() );
    }

    /**
     * Get shipping total as Money in the order's currency.
     *
     * @return \TejCart\Money\Money
     */
    public function get_shipping_total_money(): \TejCart\Money\Money {
        return \TejCart\Money\Money::from_minor_units( (int) $this->data['shipping_total'], $this->effective_currency() );
    }

    /**
     * Get tax total as Money in the order's currency.
     *
     * @return \TejCart\Money\Money
     */
    public function get_tax_total_money(): \TejCart\Money\Money {
        return \TejCart\Money\Money::from_minor_units( (int) $this->data['tax_total'], $this->effective_currency() );
    }

    /**
     * Get order total as Money in the order's currency. Does NOT apply
     * the `tejcart_order_get_total` filter (that filter's payload is
     * float-typed; new filters should listen for Money-aware events).
     *
     * @return \TejCart\Money\Money
     */
    public function get_total_money(): \TejCart\Money\Money {
        return \TejCart\Money\Money::from_minor_units( (int) $this->data['total'], $this->effective_currency() );
    }

    /**
     * Resolve the order's currency, falling back to the shop default
     * when the order hasn't been hydrated with one yet.
     */
    private function effective_currency(): string {
        $c = isset( $this->data['currency'] ) ? trim( (string) $this->data['currency'] ) : '';
        if ( '' !== $c ) {
            return strtoupper( $c );
        }
        if ( function_exists( 'tejcart_get_currency' ) ) {
            $shop = (string) tejcart_get_currency();
            if ( '' !== $shop ) {
                return strtoupper( $shop );
            }
        }
        if ( function_exists( 'get_option' ) ) {
            return strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) );
        }
        return 'USD';
    }

    /**
     * Convert raw integer minor units to a major-unit float for the
     * back-compat accessor surface. Uses the order's currency precision.
     */
    private function minor_to_float( int $minor ): float {
        $currency = $this->effective_currency();
        $multi    = max( 1, (int) \TejCart\Money\Currency::multiplier( $currency ) );
        return $minor / $multi;
    }

    /**
     * Normalise a money input (Money|float|int|numeric-string) to an
     * integer in the order's currency minor units. Used by every money
     * setter so callers can keep passing legacy floats without losing
     * the int-cents invariant of the storage column.
     */
    private function money_to_minor( $value ): int {
        if ( $value instanceof \TejCart\Money\Money ) {
            return $value->as_minor_units();
        }
        if ( is_int( $value ) ) {
            // Ambiguity: an integer could be either minor units (the
            // canonical form) or a whole major-unit amount ("set_total(5)"
            // for $5). The legacy contract was major units; we honour it
            // for int input and multiply by the currency factor. Callers
            // that want minor-unit safety should pass Money instead.
            $multi = max( 1, (int) \TejCart\Money\Currency::multiplier( $this->effective_currency() ) );
            return $value * $multi;
        }
        return (int) \TejCart\Money\Currency::to_minor_units( $value, $this->effective_currency() );
    }

    /**
     * Get payment method.
     *
     * @return string
     */
    public function get_payment_method() {
        return $this->data['payment_method'];
    }

    /**
     * Get transaction ID.
     *
     * @return string
     */
    public function get_transaction_id() {
        return $this->data['transaction_id'];
    }

    /**
     * Get coupon code.
     *
     * @return string
     */
    public function get_coupon_code() {
        return $this->data['coupon_code'];
    }

    /**
     * Get customer ID.
     *
     * @return int
     */
    public function get_customer_id() {
        return (int) $this->data['customer_id'];
    }

    /**
     * Get customer email.
     *
     * @return string
     */
    public function get_customer_email() {
        return $this->data['customer_email'];
    }

    /**
     * Get customer name.
     *
     * @return string
     */
    public function get_customer_name() {
        return $this->data['customer_name'];
    }

    /**
     * Get billing address as decoded JSON array.
     *
     * @return array
     */
    public function get_billing_address() {
        return $this->decode_address_blob( $this->data['billing_address'], 'billing_' );
    }

    /**
     * Get shipping address as decoded JSON array.
     *
     * @return array
     */
    public function get_shipping_address() {
        return $this->decode_address_blob( $this->data['shipping_address'], 'shipping_' );
    }

    /**
     * Decode a stored address column and expose unprefixed aliases.
     *
     * Checkout, PayPal express, and the wallet seed paths persist address
     * fields with a `billing_` / `shipping_` prefix on every key
     * (`billing_first_name`, `shipping_address_1`, …). The admin "new order"
     * form persists them unprefixed (`first_name`, `address_1`, …). Every
     * `format_address()` helper in the codebase (Order, Order_Admin, Invoice)
     * reads the unprefixed form, so addresses written by checkout used to
     * render blank in the admin, emails, and invoices. Mirroring the
     * unprefixed alias here is the single fix point that keeps both
     * conventions readable without rewriting the writers or losing the
     * prefixed keys consumers may also rely on.
     *
     * @param mixed  $raw    Stored value (JSON string or array).
     * @param string $prefix `billing_` or `shipping_`.
     * @return array
     */
    private function decode_address_blob( $raw, string $prefix ): array {
        if ( is_string( $raw ) && '' !== $raw ) {
            // Audit #34 / 09 F-003 — decrypt before JSON-decoding so
            // rows written under `tejcart_encrypt_addresses=true`
            // round-trip. The decode helper is a no-op on plaintext
            // input, so default-OFF merchants are unaffected.
            $decrypted = class_exists( \TejCart\Customer\Address_Crypto::class )
                ? \TejCart\Customer\Address_Crypto::decode( $raw )
                : $raw;
            $decoded   = json_decode( $decrypted, true );
            $address   = is_array( $decoded ) ? $decoded : array();
        } elseif ( is_array( $raw ) ) {
            $address = $raw;
        } else {
            return array();
        }

        if ( empty( $address ) ) {
            return array();
        }

        $aliases = array(
            'first_name', 'last_name', 'company', 'email', 'phone',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        );
        foreach ( $aliases as $key ) {
            $prefixed = $prefix . $key;
            if ( ! array_key_exists( $key, $address ) && array_key_exists( $prefixed, $address ) ) {
                $address[ $key ] = $address[ $prefixed ];
            }
        }

        return $address;
    }

    /**
     * Get customer note.
     *
     * @return string
     */
    public function get_customer_note() {
        return $this->data['customer_note'];
    }

    /**
     * Get IP address.
     *
     * @return string
     */
    public function get_ip_address() {
        return $this->data['ip_address'];
    }

    /**
     * Get created at timestamp.
     *
     * @return string
     */
    public function get_created_at() {
        return $this->data['created_at'];
    }

    /**
     * Get the order creation date, formatted for display.
     *
     * Alias used by account templates.
     *
     * @return string
     */
    public function get_date_created() {
        $raw = $this->data['created_at'];
        if ( empty( $raw ) ) {
            return '';
        }
        return date_i18n( get_option( 'date_format' ), strtotime( $raw ) );
    }

    /**
     * Get billing address formatted as HTML for display.
     *
     * @return string HTML-formatted address or empty string.
     */
    public function get_formatted_billing_address() {
        return $this->format_address( $this->get_billing_address() );
    }

    /**
     * Get shipping address formatted as HTML for display.
     *
     * @return string HTML-formatted address or empty string.
     */
    public function get_formatted_shipping_address() {
        return $this->format_address( $this->get_shipping_address() );
    }

    /**
     * Whether the order's shipping address matches its billing address.
     *
     * Returns true when the two addresses are identical across the
     * lines that matter for delivery (name, street, city, state,
     * postcode, country). Used by emails / invoices / admin screens
     * to suppress the redundant shipping block.
     *
     * @return bool
     */
    public function shipping_matches_billing(): bool {
        $billing  = $this->get_billing_address();
        $shipping = $this->get_shipping_address();

        $keys = array(
            'first_name', 'last_name', 'company', 'address_1',
            'address_2', 'city', 'state', 'postcode', 'country',
        );

        foreach ( $keys as $key ) {
            $b = isset( $billing[ $key ] ) ? trim( (string) $billing[ $key ] ) : '';
            $s = isset( $shipping[ $key ] ) ? trim( (string) $shipping[ $key ] ) : '';
            if ( $b !== $s ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the human-readable payment method title.
     *
     * @return string
     */
    public function get_payment_method_title() {
        $method = $this->get_payment_method();
        if ( '' === (string) $method ) {
            return '';
        }

        // For the PayPal gateway, surface the actual wallet the buyer used
        // (Google Pay / Apple Pay / Venmo) instead of the generic "PayPal".
        // The funding source is recorded on the order at capture time.
        if ( 'tejcart_paypal' === $method && function_exists( 'tejcart_get_order_meta' ) ) {
            $funding = strtolower( (string) tejcart_get_order_meta( (int) $this->get_id(), '_paypal_funding_source' ) );
            $wallet_titles = array(
                'google_pay' => __( 'Google Pay', 'tejcart' ),
                'googlepay'  => __( 'Google Pay', 'tejcart' ),
                'apple_pay'  => __( 'Apple Pay', 'tejcart' ),
                'applepay'   => __( 'Apple Pay', 'tejcart' ),
                'venmo'      => __( 'Venmo', 'tejcart' ),
            );
            if ( isset( $wallet_titles[ $funding ] ) ) {
                return $wallet_titles[ $funding ];
            }
        }

        // Prefer the live gateway's filtered title — that's the source of
        // truth and reflects merchant-customised labels too.
        if ( function_exists( 'tejcart' ) ) {
            $gw = tejcart()->gateways()->get_gateway( $method );
            if ( $gw && method_exists( $gw, 'get_title' ) ) {
                $title = (string) $gw->get_title();
                if ( '' !== $title ) {
                    return $title;
                }
            }
        }

        // Hard-coded fallbacks for slugs we ship out of the box, so the
        // label still reads correctly when the gateway plugin happens to
        // be deactivated post-purchase.
        $titles = array(
            'paypal'           => __( 'PayPal', 'tejcart' ),
            'tejcart_paypal'   => __( 'PayPal', 'tejcart' ),
            'cod'              => __( 'Cash on delivery', 'tejcart' ),
            'bank_transfer'    => __( 'Bank transfer', 'tejcart' ),
            'check'            => __( 'Check', 'tejcart' ),
        );
        if ( isset( $titles[ $method ] ) ) {
            return $titles[ $method ];
        }

        // Final fallback: drop the "tejcart" namespace prefix and
        // title-case every remaining word so a deactivated sibling
        // gateway slug still reads sensibly (e.g. "tejcart_authorize_net"
        // → "Authorize Net" rather than "TejCart authorize net").
        $stripped = preg_replace( '/^tejcart[_\s-]+/i', '', (string) $method );
        return ucwords( str_replace( array( '_', '-' ), ' ', null !== $stripped ? $stripped : (string) $method ) );
    }

    /**
     * Get the billing email (alias for get_customer_email).
     *
     * @return string
     */
    public function get_billing_email() {
        return $this->get_customer_email();
    }

    /**
     * Get every order note from order meta, oldest first.
     *
     * Returns both internal notes (refund / status-change audit trail) and
     * customer-visible notes. Each entry is an associative array with the
     * keys `date`, `content`, `is_customer_note`, `author`.
     *
     * @return array<int, array{date: string, content: string, is_customer_note: bool, author: int}>
     */
    public function get_notes(): array {
        if ( ! $this->id ) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = '_order_note' ORDER BY meta_id ASC",
            $this->id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( ! $rows ) {
            return array();
        }

        $notes = array();
        foreach ( $rows as $row ) {
            // F-CCM-016: new rows are stored as JSON; legacy rows used maybe_serialize.
            // Try JSON first; fall back to maybe_unserialize with allowed_classes => false
            // for existing serialized rows (prevents POP-gadget attacks on legacy data).
            $raw       = $row->meta_value;
            $note_data = null;
            if ( is_string( $raw ) && '' !== $raw ) {
                $json = json_decode( $raw, true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
                    $note_data = $json;
                } else {
                    $note_data = maybe_unserialize( $raw, array( 'allowed_classes' => false ) );
                }
            }
            if ( ! is_array( $note_data ) ) {
                continue;
            }
            $notes[] = array(
                'date'             => isset( $note_data['date'] ) ? (string) $note_data['date'] : '',
                'content'          => isset( $note_data['note'] ) ? (string) $note_data['note'] : '',
                'is_customer_note' => ! empty( $note_data['is_customer_note'] ),
                'author'           => isset( $note_data['author'] ) ? (int) $note_data['author'] : 0,
            );
        }

        return $notes;
    }

    /**
     * Get customer-visible order notes from order meta.
     *
     * @return array Array of note objects with get_date() and get_content() methods.
     */
    public function get_customer_notes() {
        if ( ! $this->id ) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = '_order_note' ORDER BY meta_id ASC",
            $this->id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( ! $rows ) {
            return array();
        }

        $notes = array();
        foreach ( $rows as $row ) {
            // F-CCM-016: JSON-first decode with serialization fallback (mirrors get_notes()).
            $raw_val   = $row->meta_value;
            $note_data = null;
            if ( is_string( $raw_val ) && '' !== $raw_val ) {
                $json = json_decode( $raw_val, true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
                    $note_data = $json;
                } else {
                    $note_data = maybe_unserialize( $raw_val, array( 'allowed_classes' => false ) );
                }
            }
            if ( ! is_array( $note_data ) ) {
                continue;
            }
            if ( empty( $note_data['is_customer_note'] ) ) {
                continue;
            }
            $notes[] = new class( $note_data ) {
                /** @var array<string, mixed> */
                private array $data;
                /** @param array<string, mixed> $data */
                public function __construct( array $data ) {
                    $this->data = $data;
                }
                public function get_date(): string {
                    return isset( $this->data['date'] )
                        ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $this->data['date'] ) )
                        : '';
                }
                public function get_content(): string {
                    return $this->data['note'] ?? '';
                }
            };
        }

        return $notes;
    }

    /**
     * Get updated at timestamp.
     *
     * @return string
     */
    public function get_updated_at() {
        return $this->data['updated_at'];
    }

    /**
     * Get the URL the customer is returned to after PayPal approval.
     *
     * @return string
     */
    public function get_checkout_return_url() {
        $url = tejcart_get_thankyou_url( $this->get_id() );

        return apply_filters( 'tejcart_checkout_return_url', $url, $this );
    }

    /**
     * Get the URL the customer is sent to if they cancel at PayPal.
     *
     * @return string
     */
    public function get_checkout_cancel_url() {
        $url = tejcart_get_page_url( 'checkout' );

        return apply_filters( 'tejcart_checkout_cancel_url', $url, $this );
    }

    /**
     * Set a data property.
     *
     * @param string $key   Data key.
     * @param mixed  $value Value.
     */
    public function set( $key, $value ) {
        if ( ! array_key_exists( $key, $this->data ) ) {
            return;
        }
        if ( in_array( $key, self::MONEY_SLOTS, true ) ) {
            // Currency must be set first for the conversion to use the
            // correct minor-unit factor. Order_Factory's loop iterates
            // $data in key order, so callers should put `currency` ahead
            // of the money slots (Checkout already does this).
            $this->data[ $key ] = $this->money_to_minor( $value );
            return;
        }
        // Audit #34 / 09 F-003 — address-blob encryption at rest.
        // Centralised here so every caller of `Order::set('billing_address',
        // ...)` / `set('shipping_address', ...)` (Order_Factory,
        // PayPal_AJAX, Orders_Controller, …) routes through the encoder.
        // `tejcart_encrypt_addresses` defaults ON (Audit M-36), so the value
        // stored is AES-256-GCM ciphertext (`tejc1:` prefix); the
        // billing_/shipping_address columns are LONGTEXT (never JSON) so the
        // ciphertext is accepted. Merchants who filter the option OFF keep
        // their plaintext JSON bytes unchanged.
        if ( ( 'billing_address' === $key || 'shipping_address' === $key )
            && is_string( $value ) && '' !== $value
            && class_exists( \TejCart\Customer\Address_Crypto::class )
        ) {
            $value = \TejCart\Customer\Address_Crypto::encode( $value );
        }
        $this->data[ $key ] = $value;
    }

    /**
     * Set status.
     *
     * @param string $status Order status slug.
     */
    public function set_status( $status ) {
        $this->data['status'] = sanitize_text_field( $status );
    }

    /**
     * Set currency.
     *
     * @param string $currency Currency code.
     */
    public function set_currency( $currency ) {
        $this->data['currency'] = sanitize_text_field( $currency );
    }

    /**
     * Set subtotal. Accepts Money, float, int (whole major units), or
     * numeric string; normalised to integer minor units in the order's
     * currency before storage.
     *
     * @param \TejCart\Money\Money|float|int|string $subtotal Subtotal amount.
     */
    public function set_subtotal( $subtotal ) {
        $this->data['subtotal'] = $this->money_to_minor( $subtotal );
    }

    /**
     * Set discount total. See {@see set_subtotal()} for accepted input.
     *
     * @param \TejCart\Money\Money|float|int|string $discount_total Discount amount.
     */
    public function set_discount_total( $discount_total ) {
        $this->data['discount_total'] = $this->money_to_minor( $discount_total );
    }

    /**
     * Set shipping total. See {@see set_subtotal()} for accepted input.
     *
     * @param \TejCart\Money\Money|float|int|string $shipping_total Shipping amount.
     */
    public function set_shipping_total( $shipping_total ) {
        $this->data['shipping_total'] = $this->money_to_minor( $shipping_total );
    }

    /**
     * Set tax total. See {@see set_subtotal()} for accepted input.
     *
     * @param \TejCart\Money\Money|float|int|string $tax_total Tax amount.
     */
    public function set_tax_total( $tax_total ) {
        $this->data['tax_total'] = $this->money_to_minor( $tax_total );
    }

    /**
     * Set order total. See {@see set_subtotal()} for accepted input.
     *
     * @param \TejCart\Money\Money|float|int|string $total Order total.
     */
    public function set_total( $total ) {
        $this->data['total'] = $this->money_to_minor( $total );
    }

    /**
     * Set payment method.
     *
     * @param string $payment_method Payment method identifier.
     */
    public function set_payment_method( $payment_method ) {
        $this->data['payment_method'] = sanitize_text_field( $payment_method );
    }

    /**
     * Set transaction ID.
     *
     * @param string $transaction_id Transaction ID from payment gateway.
     */
    public function set_transaction_id( $transaction_id ) {
        $this->data['transaction_id'] = sanitize_text_field( $transaction_id );
    }

    /**
     * Set coupon code.
     *
     * @param string $coupon_code Coupon code.
     */
    public function set_coupon_code( $coupon_code ) {
        $this->data['coupon_code'] = sanitize_text_field( $coupon_code );
    }

    /**
     * Set customer ID.
     *
     * @param int $customer_id WordPress user ID.
     */
    public function set_customer_id( $customer_id ) {
        $this->data['customer_id'] = absint( $customer_id );
    }

    /**
     * Set customer email.
     *
     * 08 #14 — normalised to lowercase at write time so the
     * Customers_Table admin aggregate can match the `customer_email(191)`
     * index without wrapping the column in `LOWER()`. Lowercasing at the
     * boundary keeps the canonical mailbox form on disk while preserving
     * RFC 5321 (which is case-insensitive in practice for every modern
     * mailbox — local-part case-sensitivity is a theoretical wart no
     * production provider honours).
     *
     * @param string $email Customer email.
     */
    public function set_customer_email( $email ) {
        $sanitized = sanitize_email( $email );
        $this->data['customer_email'] = '' === $sanitized ? '' : strtolower( $sanitized );
    }

    /**
     * Set customer name.
     *
     * @param string $name Customer name.
     */
    public function set_customer_name( $name ) {
        $this->data['customer_name'] = sanitize_text_field( $name );
    }

    /**
     * Set billing address.
     *
     * @param array $address Billing address array.
     */
    public function set_billing_address( $address ) {
        $this->data['billing_address'] = is_array( $address ) ? wp_json_encode( $address ) : $address;
    }

    /**
     * Set shipping address.
     *
     * @param array $address Shipping address array.
     */
    public function set_shipping_address( $address ) {
        $this->data['shipping_address'] = is_array( $address ) ? wp_json_encode( $address ) : $address;
    }

    /**
     * Set customer note.
     *
     * @param string $note Customer note.
     */
    public function set_customer_note( $note ) {
        $this->data['customer_note'] = sanitize_textarea_field( $note );
    }

    /**
     * Set IP address.
     *
     * @param string $ip IP address.
     */
    public function set_ip_address( $ip ) {
        $this->data['ip_address'] = sanitize_text_field( $ip );
    }

    /**
     * Get order items.
     *
     * Queries wp_tejcart_order_items for this order.
     *
     * @return array Array of order item objects.
     */
    public function get_items() {
        global $wpdb;

        if ( ! $this->id ) {
            return array();
        }

        $table = $wpdb->prefix . 'tejcart_order_items';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $this->id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $items ) ) {
            $items = array();
        }

        /**
         * Filter order items.
         *
         * @param array  $items Order items.
         * @param \TejCart\Order\Order $order The order object.
         */
        return apply_filters( 'tejcart_order_get_items', $items, $this );
    }

    /**
     * Update order status.
     *
     * Validates the new status, fires status-specific and general actions,
     * and saves the order.
     *
     * @param string $new_status New status slug.
     * @param string $note       Optional note for the status change.
     * @return bool True on success, false on failure.
     */
    public function update_status( $new_status, $note = '' ) {
        if ( ! Order_Status::is_valid( $new_status ) ) {
            return false;
        }

        $old_status = $this->get_status();

        if ( $old_status === $new_status ) {
            return true;
        }

        if ( '' !== $old_status && ! Order_Status::is_valid_transition( $old_status, $new_status ) ) {
            tejcart_log(
                "Order #{$this->id}: blocked invalid transition '{$old_status}' → '{$new_status}'.",
                'warning'
            );
            return false;
        }

        /**
         * F-L7 / #957: cancellable pre-status-change filter. Return
         * a WP_Error (or false) to block the transition. Fraud
         * screening, hold queues, and external order-mgmt addons
         * hook here to deny or defer a status flip.
         *
         * @param true|\WP_Error $proceed     Pass-through or WP_Error/false to block.
         * @param Order          $order
         * @param string         $old_status
         * @param string         $new_status
         * @param string         $note
         */
        $gate = apply_filters( 'tejcart_pre_status_change', true, $this, $old_status, $new_status, $note );
        if ( is_wp_error( $gate ) || false === $gate ) {
            return false;
        }

        if ( $this->id ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tejcart_orders';

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->update(
                $table,
                array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $this->id, 'status' => $old_status ),
                array( '%s', '%s' ),
                array( '%d', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $result === 0 ) {
                // Refresh the row so listeners see the *actual*
                // current status, not the stale in-memory snapshot. A
                // dead-letter / retry handler needs to know whether the
                // winner moved the order to `processing` (no-op the
                // duplicate webhook), `cancelled` (the merchant rejected
                // it), or something else entirely.
                $current_status = (string) $old_status;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $live_status = $wpdb->get_var(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $this->id )
                );
                if ( is_string( $live_status ) && '' !== $live_status ) {
                    $current_status = $live_status;
                    $this->set_status( $current_status );
                }

                tejcart_log(
                    sprintf(
                        "Order #%d: status race condition. Expected '%s', actual '%s', attempted '%s'.",
                        (int) $this->id,
                        (string) $old_status,
                        $current_status,
                        (string) $new_status
                    ),
                    'warning'
                );

                // Persist the failed transition as an order
                // note + fire a dedicated action so admin notices can
                // surface conflicts (e.g. a webhook arriving after a
                // manual cancel). Best-effort — a logging failure must
                // not bury the original race-detected return.
                $conflict_note = sprintf(
                    /* translators: 1: expected status, 2: actual current status, 3: attempted status */
                    __( 'Status transition conflict: expected "%1$s" but row is now "%2$s"; attempted "%3$s" was discarded.', 'tejcart' ),
                    (string) $old_status,
                    $current_status,
                    (string) $new_status
                );
                // Route through add_note() — order notes live in
                // tejcart_order_meta under the `_order_note` key, not a
                // standalone `tejcart_order_notes` table (which never
                // existed; the old raw insert here emitted a "table doesn't
                // exist" DB error on every status-race conflict). add_note()
                // is the same path get_notes() reads back, so the conflict
                // surfaces in the order timeline as an internal note.
                $this->add_note( $conflict_note );

                /**
                 * Fires when an order status transition was rejected
                 * because the row's current status no longer matched
                 * the expected `$old_status` (concurrent writer won).
                 *
                 * Webhook handlers and async jobs can listen here to
                 * decide whether to retry, dead-letter, or no-op based
                 * on the actual current status of the row.
                 *
                 * @param int    $order_id
                 * @param string $expected_old_status
                 * @param string $attempted_new_status
                 * @param string $current_status      Live status the row is in now.
                 * @param Order  $order
                 */
                do_action(
                    'tejcart_order_status_transition_conflict',
                    (int) $this->id,
                    (string) $old_status,
                    (string) $new_status,
                    $current_status,
                    $this
                );
                return false;
            }

            // Append an immutable changelog row. Best-effort: a log-write
            // failure must not block the transition itself.
            $log_table = $wpdb->prefix . 'tejcart_order_status_log';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->insert(
                $log_table,
                array(
                    'order_id'    => $this->id,
                    'from_status' => (string) $old_status,
                    'to_status'   => (string) $new_status,
                    'actor_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
                    'reason'      => is_string( $note ) ? $note : '',
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%d', '%s', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        $this->set_status( $new_status );

        /**
         * Fires when an order transitions to a specific status.
         *
         * @param int                  $order_id The order ID.
         * @param \TejCart\Order\Order $order    The order object.
         */
        do_action( "tejcart_order_status_{$new_status}", $this->get_id(), $this );

        /**
         * Fires whenever an order status changes.
         *
         * @param string               $old_status Previous status.
         * @param string               $new_status New status.
         * @param \TejCart\Order\Order $order      The order object.
         */
        do_action( 'tejcart_order_status_changed', $old_status, $new_status, $this );

        // Always record a timeline entry for the transition so admins see
        // payment / refund / cancellation activity even when the caller
        // (a webhook, a CLI command, a sibling plugin) forgot to supply a
        // human-readable reason.
        $timeline_note = is_string( $note ) && '' !== trim( $note )
            ? $note
            : sprintf(
                /* translators: 1: previous status, 2: new status. */
                __( 'Order status changed from %1$s to %2$s.', 'tejcart' ),
                ucfirst( str_replace( '_', ' ', (string) $old_status ) ),
                ucfirst( str_replace( '_', ' ', (string) $new_status ) )
            );
        $this->add_note( $timeline_note );

        return true;
    }

    /**
     * Check whether the order needs payment.
     *
     * An order needs payment when its status is pending and total is greater
     * than zero.
     *
     * @return bool
     */
    public function needs_payment() {
        $needs_payment = ( 'pending' === $this->get_status() && $this->get_total() > 0 );

        /**
         * Filter whether an order needs payment.
         *
         * @param bool                 $needs_payment Whether the order needs payment.
         * @param \TejCart\Order\Order $order         The order object.
         */
        return (bool) apply_filters( 'tejcart_order_needs_payment', $needs_payment, $this );
    }

    /**
     * Save the order to the database (INSERT or UPDATE).
     *
     * Generates an order_key and order_number for new orders.
     *
     * @return bool True on success, false on failure.
     */
    public function save() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_orders';
        $now   = current_time( 'mysql' );

        $this->data['updated_at'] = $now;

        // Derive the base-currency settlement columns from the transacted
        // amounts + fx_rate before every write so reporting always has
        // authoritative, multiplier-correct base figures (identity for
        // single-currency stores). Idempotent on update.
        $this->sync_base_amounts();

        // Guest checkouts have no customer row. Persist NULL (not 0) so the
        // fk_orders_customer_id FOREIGN KEY (customer_id REFERENCES tejcart_customers.id
        // ON DELETE SET NULL) is satisfied. wpdb emits SQL NULL for null values
        // regardless of the %d format hint.
        $payload = $this->data;
        if ( empty( $payload['customer_id'] ) ) {
            $payload['customer_id'] = null;
        }

        if ( $this->id ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->update(
                $table,
                $payload,
                array( 'id' => $this->id ),
                $this->get_data_formats(),
                array( '%d' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            if ( empty( $payload['order_key'] ) ) {
                // 24 Bytes (192 bits) of entropy puts the token
                // beyond the brute-force window OWASP recommends for
                // high-value identifiers, since order keys are part of the
                // ownership check on the thank-you page and Pay-for-Order.
                $payload['order_key']     = 'nxc_' . bin2hex( random_bytes( 24 ) );
                $this->data['order_key']  = $payload['order_key'];
            }

            if ( empty( $payload['order_number'] ) ) {
                $payload['order_number']    = $this->generate_order_number();
                $this->data['order_number'] = $payload['order_number'];
            }

            if ( empty( $payload['created_at'] ) ) {
                $payload['created_at']    = $now;
                $this->data['created_at'] = $now;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->insert(
                $table,
                $payload,
                $this->get_data_formats()
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( false !== $result ) {
                $this->id = (int) $wpdb->insert_id;

                // Record an "Order created" timeline entry at the deepest
                // shared chokepoint, so EVERY order — Order_Factory,
                // direct admin instantiation, sibling-plugin paths — gets
                // an audit row even if the action listener can't be
                // resolved on this request.
                $this->add_note(
                    sprintf(
                        /* translators: 1: order total, 2: payment method title or slug. */
                        __( 'Order created. Total: %1$s. Payment method: %2$s.', 'tejcart' ),
                        function_exists( 'tejcart_price' )
                            ? wp_strip_all_tags( (string) tejcart_price( $this->get_total(), (string) $this->get_currency() ) )
                            : (string) $this->get_total(),
                        method_exists( $this, 'get_payment_method_title' ) && '' !== (string) $this->get_payment_method_title()
                            ? (string) $this->get_payment_method_title()
                            : ( '' !== (string) $this->get_payment_method() ? (string) $this->get_payment_method() : __( 'unknown', 'tejcart' ) )
                    )
                );
            }
        }

        if ( false === $result ) {
            return false;
        }

        /**
         * Fires after an order object is saved.
         *
         * @param \TejCart\Order\Order $order The order object.
         */
        do_action( 'tejcart_after_order_object_save', $this );

        return true;
    }

    /**
     * Delete the order from the database.
     *
     * @return bool True on success, false on failure.
     */
    public function delete() {
        global $wpdb;

        if ( ! $this->id ) {
            return false;
        }

        $table  = $wpdb->prefix . 'tejcart_orders';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->delete(
            $table,
            array( 'id' => $this->id ),
            array( '%d' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false !== $result ) {
            $this->id   = 0;
            $this->data = array_map( function () {
                return '';
            }, $this->data );
        }

        return false !== $result;
    }

    /**
     * Get order meta.
     *
     * @param string $key Meta key.
     * @return mixed
     */
    public function get_meta( $key ) {
        return Order_Meta::get( $this->id, $key );
    }

    /**
     * Update order meta.
     *
     * @param string $key   Meta key.
     * @param mixed  $value Meta value.
     * @return bool
     */
    public function update_meta( $key, $value ) {
        return Order_Meta::update( $this->id, $key, $value );
    }

    /**
     * Delete order meta.
     *
     * @param string $key Meta key.
     * @return bool
     */
    public function delete_meta( $key ) {
        return Order_Meta::delete( $this->id, $key );
    }

    /**
     * Add a note to the order.
     *
     * Stored in order meta with the special key _order_note.
     *
     * @param string $note             Note content.
     * @param bool   $is_customer_note Whether this note is visible to the customer.
     * @return bool
     */
    public function add_note( $note, $is_customer_note = false ) {
        if ( ! $this->id || empty( $note ) ) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_order_meta';

        // F-CCM-016: store new note rows as JSON rather than PHP-serialized arrays.
        // JSON is portable, queryable, and immune to POP-gadget risks even without
        // `allowed_classes => false`. get_notes() handles both formats during the
        // transition: JSON-first with a maybe_unserialize fallback for legacy rows.
        $note_data = wp_json_encode( array(
            'note'             => sanitize_textarea_field( $note ),
            'is_customer_note' => (bool) $is_customer_note,
            'date'             => current_time( 'mysql' ),
            'author'           => get_current_user_id(),
        ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $result = $wpdb->insert(
            $table,
            array(
                'order_id'   => $this->id,
                'meta_key'   => '_order_note',
                'meta_value' => $note_data,
            ),
            array( '%d', '%s', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return false !== $result;
    }

    /**
     * Format an address array as HTML lines.
     *
     * @param array $address Address data array.
     * @return string HTML-formatted address.
     */
    protected function format_address( array $address ): string {
        if ( empty( $address ) || ! array_filter( $address ) ) {
            return '';
        }

        $parts = array_filter( array(
            isset( $address['first_name'] )
                ? esc_html( trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ) )
                : '',
            ! empty( $address['company'] ) ? esc_html( $address['company'] ) : '',
            ! empty( $address['address_1'] ) ? esc_html( $address['address_1'] ) : '',
            ! empty( $address['address_2'] ) ? esc_html( $address['address_2'] ) : '',
            ! empty( $address['city'] )
                ? esc_html( $address['city'] . ( ! empty( $address['state'] ) ? ', ' . $address['state'] : '' ) . ' ' . ( $address['postcode'] ?? '' ) )
                : '',
            ! empty( $address['country'] ) ? esc_html( $address['country'] ) : '',
        ) );

        $html = implode( '<br>', $parts );

        if ( ! empty( $address['phone'] ) ) {
            $html .= '<br>' . esc_html( $address['phone'] );
        }
        if ( ! empty( $address['email'] ) ) {
            $html .= '<br>' . esc_html( $address['email'] );
        }

        return $html;
    }

    /**
     * Generate a cryptographically secure, non-sequential order number.
     *
     * Format: NXC-{timestamp_base36}-{random_base36} e.g. NXC-2M5KR7-A3BX9P
     *
     * @return string
     */
    protected function generate_order_number() {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_orders';
        $max_tries = 5;

        for ( $i = 0; $i < $max_tries; $i++ ) {
            $time_component   = strtoupper( base_convert( (string) time(), 10, 36 ) );
            $random_bytes     = random_bytes( 4 );
            $random_component = strtoupper( substr( base_convert( bin2hex( $random_bytes ), 16, 36 ), 0, 6 ) );

            $random_component = str_pad( $random_component, 6, '0', STR_PAD_LEFT );

            $order_number = 'NXC-' . $time_component . '-' . $random_component;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $exists = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE order_number = %s",
                    $order_number
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! $exists ) {
                return $order_number;
            }
        }

        return 'NXC-' . strtoupper( base_convert( (string) time(), 10, 36 ) ) . '-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
    }

    /**
     * Get format strings for $wpdb insert/update, matching $this->data keys.
     *
     * @return array
     */
    /**
     * The base (store) currency this order's settlement columns are
     * denominated in. Falls back to the configured store currency.
     */
    public function get_base_currency(): string {
        $code = isset( $this->data['base_currency'] ) ? trim( (string) $this->data['base_currency'] ) : '';
        if ( '' !== $code ) {
            return $code;
        }
        return function_exists( 'get_option' ) ? (string) get_option( 'tejcart_currency', 'USD' ) : 'USD';
    }

    /**
     * The base→transacted FX rate stamped on this order (1 for
     * single-currency / base-currency orders).
     */
    public function get_fx_rate(): float {
        $rate = isset( $this->data['fx_rate'] ) ? (float) $this->data['fx_rate'] : 1.0;
        return $rate > 0.0 ? $rate : 1.0;
    }

    /**
     * Recompute the base-currency settlement columns from the transacted
     * money columns, the order currency, and the stamped fx_rate.
     *
     * Runs on every {@see self::save()} so the base figures can never drift
     * from the charged figures. For single-currency stores (and any order
     * whose currency equals the base with fx_rate 1) this is an exact
     * identity copy — {@see \TejCart\Money\Currency::to_base_minor()} short-
     * circuits — so it imposes no behavioural change on those installs.
     */
    private function sync_base_amounts(): void {
        $store_base = function_exists( 'get_option' ) ? (string) get_option( 'tejcart_currency', 'USD' ) : 'USD';

        $base_currency = isset( $this->data['base_currency'] ) ? trim( (string) $this->data['base_currency'] ) : '';
        if ( '' === $base_currency ) {
            $base_currency = $store_base;
        }
        $this->data['base_currency'] = $base_currency;

        $fx_rate = isset( $this->data['fx_rate'] ) ? (float) $this->data['fx_rate'] : 1.0;
        if ( $fx_rate <= 0.0 ) {
            $fx_rate = 1.0;
        }
        $this->data['fx_rate'] = (string) $fx_rate;

        $txn_currency = isset( $this->data['currency'] ) && '' !== trim( (string) $this->data['currency'] )
            ? (string) $this->data['currency']
            : $store_base;

        $map = array(
            'subtotal'       => 'base_subtotal',
            'discount_total' => 'base_discount_total',
            'shipping_total' => 'base_shipping_total',
            'tax_total'      => 'base_tax_total',
            'total'          => 'base_total',
        );
        foreach ( $map as $src => $dst ) {
            $this->data[ $dst ] = \TejCart\Money\Currency::to_base_minor(
                (int) $this->data[ $src ],
                $txn_currency,
                $base_currency,
                $fx_rate
            );
        }
    }

    protected function get_data_formats() {
        return array(
            '%s', // order_key
            '%s', // order_number
            '%s', // status
            '%s', // currency
            '%d', // subtotal (minor units)
            '%d', // discount_total (minor units)
            '%d', // shipping_total (minor units)
            '%d', // tax_total (minor units)
            '%d', // total (minor units)
            '%s', // payment_method
            '%s', // transaction_id
            '%s', // coupon_code
            '%d', // customer_id
            '%s', // customer_email
            '%s', // customer_name
            '%s', // billing_address
            '%s', // shipping_address
            '%s', // customer_note
            '%s', // ip_address
            '%s', // created_at
            '%s', // updated_at
            '%s', // base_currency
            '%s', // fx_rate (DECIMAL, passed as numeric string)
            '%d', // base_subtotal (minor units, base currency)
            '%d', // base_discount_total (minor units, base currency)
            '%d', // base_shipping_total (minor units, base currency)
            '%d', // base_tax_total (minor units, base currency)
            '%d', // base_total (minor units, base currency)
        );
    }
}
