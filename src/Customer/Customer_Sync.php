<?php
/**
 * Customer table writer.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep wp_tejcart_customers in sync with orders and user registrations.
 *
 * The admin Customers screen and dashboard "Customers" tile read from
 * that table exclusively. Without these listeners the table is never
 * written to and the screen stays empty even after many orders.
 */
class Customer_Sync {
    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'tejcart_order_created', array( $this, 'on_order_created' ), 10, 2 );

        // Mirror the address the buyer typed at checkout into their account
        // address book (the `tejcart_{billing|shipping}_*` user_meta the My
        // Account → Addresses screen reads and that prefills future
        // checkouts). Runs at priority 12 — after the customer-row upsert at
        // 10 — and only for logged-in buyers; guests have no account to
        // store addresses against. See sync_address_book().
        add_action( 'tejcart_order_created', array( $this, 'sync_address_book' ), 12, 2 );

        // Backfill the same address-book user_meta once a guest order is
        // claimed by a newly registered user. When a buyer ticks "create an
        // account" at checkout (or registers later after a guest order), the
        // order is created while still a guest (customer_id 0), so
        // sync_address_book() above skips it — the account does not exist yet.
        // Guest_Order_Linker links the order on `user_register` and fires
        // `tejcart_guest_orders_linked`; without this listener the buyer's
        // address never reaches My Account → Addresses and the next checkout
        // falls back to the store's default country/state. See
        // sync_address_book_on_link().
        add_action( 'tejcart_guest_orders_linked', array( $this, 'sync_address_book_on_link' ), 10, 1 );

        add_action( 'user_register', array( $this, 'on_user_register' ), 11, 1 );

        add_action( 'tejcart_checkout_linked_existing_user', array( $this, 'on_linked_existing_user' ), 10, 2 );

        // User-meta autofill. The basic My Account → Addresses screen
        // (see Shortcodes::handle_save_address_post) writes the buyer's
        // current billing / shipping snapshot to user_meta keys, but
        // until this listener landed nothing read it back into the
        // checkout form — so a customer who saved an Indian address on
        // their account page still saw the store's default country
        // (typically US) pre-selected on the checkout. Runs at priority
        // 15 so an explicit Tier-2 Address_Book default (priority 10)
        // still wins, but the most recent user-authored edit beats the
        // older order snapshot returned by inject_customer_defaults.
        add_filter( 'tejcart_checkout_default_address', array( $this, 'inject_user_meta_defaults' ), 15, 3 );

        // Customer-row autofill fallback. Runs at priority 20 so the
        // Tier-2 Address_Book listener (priority 10) wins when the user
        // has a `is_default` entry there, and the user-meta listener
        // (priority 15) wins for an address the buyer just saved on the
        // My Account page; this fills the gap for users who have placed
        // an order but never edited either the address book or account.
        add_filter( 'tejcart_checkout_default_address', array( $this, 'inject_customer_defaults' ), 20, 3 );

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'maybe_backfill' ), 20 );
        }
    }

    /**
     * Handle a newly created order.
     *
     * @param int   $order_id Unused, kept for action signature parity.
     * @param mixed $order    Order object (duck-typed to allow mocks).
     */
    public function on_order_created( $order_id, $order ): void {
        unset( $order_id );
        if ( ! $order instanceof \TejCart\Order\Order ) {
            return;
        }
        Customer_Repository::upsert_from_order( $order );
    }

    /**
     * Persist the order's billing / shipping address into the buyer's
     * account address book so it shows up under My Account → Addresses and
     * prefills their next checkout.
     *
     * The customer-row upsert in {@see on_order_created()} only snapshots
     * the latest order for the admin Customers screen and the priority-20
     * prefill fallback — it does NOT write the curated
     * `tejcart_{billing|shipping}_<field>` user_meta that the account page
     * (see {@see \TejCart\Frontend\Shortcodes::handle_save_address_post()})
     * and the priority-15 prefill listener read. Without this, a logged-in
     * customer who typed a fresh address at checkout never saw it saved to
     * their address list. Mirrors the common store behavior of updating the
     * customer's stored address whenever they place an order.
     *
     * Guests (user_id 0) are skipped — they have no account to write to.
     *
     * @param int   $order_id Unused, kept for action signature parity.
     * @param mixed $order    Order object (duck-typed to allow mocks).
     */
    public function sync_address_book( $order_id, $order ): void {
        unset( $order_id );
        if ( ! $order instanceof \TejCart\Order\Order ) {
            return;
        }

        $user_id = (int) $order->get_customer_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $this->save_address_to_user_meta( $user_id, 'billing', (array) $order->get_billing_address() );
        $this->save_address_to_user_meta( $user_id, 'shipping', (array) $order->get_shipping_address() );
    }

    /**
     * Backfill the account address book after a guest order is linked to a
     * freshly registered user.
     *
     * Fires on `tejcart_guest_orders_linked`, which {@see Guest_Order_Linker}
     * raises from the `user_register` hook whenever one or more guest orders
     * are claimed by a new account. This covers both the "create an account"
     * checkbox at checkout and a standalone registration placed after a guest
     * order — in both flows {@see sync_address_book()} could not run at
     * `tejcart_order_created` time because the order was still a guest order
     * (customer_id 0), leaving the `tejcart_{billing|shipping}_*` user_meta
     * the My Account → Addresses screen reads (and the priority-15 checkout
     * prefill consumes) empty.
     *
     * The buyer's most recent order is the one just linked, so its decoded
     * billing / shipping snapshot is written through the same user_meta writer
     * the per-order sync uses. No-ops cleanly when the order or its address
     * cannot be resolved. During checkout-driven registration the order is
     * still inside the checkout transaction, but the read happens on the same
     * DB connection so the uncommitted row is visible; a rollback unwinds the
     * user, the order, and these meta writes together.
     *
     * @param int $user_id Newly registered user ID that claimed the order(s).
     */
    public function sync_address_book_on_link( $user_id ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 || ! function_exists( 'tejcart_get_customer_orders' ) ) {
            return;
        }

        $orders = tejcart_get_customer_orders( $user_id, 1 );
        if ( empty( $orders ) || ! ( $orders[0] instanceof \TejCart\Order\Order ) ) {
            return;
        }

        $order = $orders[0];
        $this->save_address_to_user_meta( $user_id, 'billing', (array) $order->get_billing_address() );
        $this->save_address_to_user_meta( $user_id, 'shipping', (array) $order->get_shipping_address() );
    }

    /**
     * Write a single billing / shipping address into the account address
     * book user_meta, firing the same `tejcart_customer_address_saved`
     * action the account page does.
     *
     * Skips the write entirely when the address carries neither a street
     * line nor a country — a digital order with an empty shipping address
     * must not blank out an address the buyer curated on their account
     * page. Each value is sanitised the same way the account-page writer
     * sanitises it.
     *
     * @param int    $user_id WordPress user ID (already validated > 0).
     * @param string $type    `billing` or `shipping`.
     * @param array  $address Decoded order address (unprefixed aliases).
     */
    private function save_address_to_user_meta( int $user_id, string $type, array $address ): void {
        if ( $user_id <= 0 || ( 'billing' !== $type && 'shipping' !== $type ) ) {
            return;
        }
        if ( empty( $address ) ) {
            return;
        }

        $has_line1   = '' !== trim( (string) ( $address['address_1'] ?? '' ) );
        $has_country = '' !== trim( (string) ( $address['country'] ?? '' ) );
        if ( ! $has_line1 && ! $has_country ) {
            return;
        }

        $fields = array(
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
            'phone',
        );

        $wrote = false;
        foreach ( $fields as $field ) {
            if ( ! array_key_exists( $field, $address ) ) {
                continue;
            }
            update_user_meta(
                $user_id,
                'tejcart_' . $type . '_' . $field,
                sanitize_text_field( (string) $address[ $field ] )
            );
            $wrote = true;
        }

        if ( $wrote ) {
            /** This action is documented in src/Frontend/Shortcodes.php */
            do_action( 'tejcart_customer_address_saved', $user_id, $type );
        }
    }

    /**
     * Handle a new user registration — claim matching guest rows.
     *
     * @param int $user_id Newly created WP user ID.
     */
    public function on_user_register( $user_id ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return;
        }

        Customer_Repository::link_email_to_user( $user_id, (string) $user->user_email );
    }

    /**
     * Checkout linked this order to an existing WP user by email.
     *
     * @param int   $user_id     Existing WP user ID.
     * @param array $posted_data Sanitized checkout data.
     */
    public function on_linked_existing_user( $user_id, $posted_data ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $email = '';
        if ( is_array( $posted_data ) && ! empty( $posted_data['billing_email'] ) ) {
            $email = (string) $posted_data['billing_email'];
        }
        if ( '' === $email ) {
            $user = get_userdata( $user_id );
            if ( $user && ! empty( $user->user_email ) ) {
                $email = (string) $user->user_email;
            }
        }
        if ( '' === $email ) {
            return;
        }

        Customer_Repository::link_email_to_user( $user_id, $email );
    }

    /**
     * Inject billing / shipping defaults from the user_meta snapshot
     * written by the My Account → Addresses page
     * ({@see \TejCart\Frontend\Shortcodes::handle_save_address_post()}).
     *
     * That screen persists each field directly under
     * `tejcart_{billing|shipping}_<field>` meta keys, which represents
     * the buyer's explicit, most-recent default and is the only source
     * available for a logged-in customer who has saved an address but
     * not yet placed an order — without this listener the checkout
     * falls back to the store's hardcoded default country / state.
     *
     * Keys already populated by an earlier filter listener (e.g. the
     * Tier-2 Address_Book at priority 10) are never overwritten.
     *
     * @param mixed $defaults Existing defaults from prior filter listeners.
     * @param int   $user_id  Current user ID.
     * @param array $context  Render context (`is_billing` / `is_shipping`).
     * @return array
     */
    public function inject_user_meta_defaults( $defaults, $user_id = 0, $context = array() ): array {
        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return $defaults;
        }

        $is_billing  = ! isset( $context['is_billing'] )  || ! empty( $context['is_billing'] );
        $is_shipping = ! isset( $context['is_shipping'] ) || ! empty( $context['is_shipping'] );

        if ( ! $is_billing && ! $is_shipping ) {
            return $defaults;
        }

        if ( ! function_exists( 'tejcart_get_customer_addresses' ) ) {
            return $defaults;
        }

        $addresses = tejcart_get_customer_addresses( $user_id );

        if ( $is_billing && ! empty( $addresses['billing'] ) && is_array( $addresses['billing'] ) ) {
            $defaults = $this->fill_missing( $defaults, $addresses['billing'], 'billing' );
        }
        if ( $is_shipping && ! empty( $addresses['shipping'] ) && is_array( $addresses['shipping'] ) ) {
            $defaults = $this->fill_missing( $defaults, $addresses['shipping'], 'shipping' );
        }

        return $defaults;
    }

    /**
     * Inject billing / shipping defaults from the customer row when no
     * other listener has supplied them. Keys already present on
     * `$defaults` (typically written by the Tier-2 Address_Book) are
     * never overwritten.
     *
     * The customer row carries the snapshot of the user's most recent
     * order, which is the best available fallback for a returning
     * customer who has not curated their address book. Anonymised rows
     * (post-GDPR-erasure) decode to an empty array via
     * {@see Customer_Repository::get_by_user_id()} so erased data does
     * not leak back into a later checkout.
     *
     * @param mixed $defaults Existing defaults from prior filter listeners.
     * @param int   $user_id  Current user ID.
     * @param array $context  Render context (`is_billing` / `is_shipping`).
     * @return array
     */
    public function inject_customer_defaults( $defaults, $user_id = 0, $context = array() ): array {
        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return $defaults;
        }

        $is_billing  = ! isset( $context['is_billing'] )  || ! empty( $context['is_billing'] );
        $is_shipping = ! isset( $context['is_shipping'] ) || ! empty( $context['is_shipping'] );

        $row = Customer_Repository::get_by_user_id( $user_id );

        if ( $row ) {
            if ( $is_billing && ! empty( $row['billing_address'] ) ) {
                $defaults = $this->fill_missing( $defaults, $row['billing_address'], 'billing' );
            }
            if ( $is_shipping && ! empty( $row['shipping_address'] ) ) {
                $defaults = $this->fill_missing( $defaults, $row['shipping_address'], 'shipping' );
            }

            // First-name / last-name on the row are sometimes the only
            // identity we have (older rows without a stored address).
            if ( $is_billing && ! isset( $defaults['billing_first_name'] ) && '' !== $row['first_name'] ) {
                $defaults['billing_first_name'] = $row['first_name'];
            }
            if ( $is_billing && ! isset( $defaults['billing_last_name'] ) && '' !== $row['last_name'] ) {
                $defaults['billing_last_name'] = $row['last_name'];
            }
            if ( $is_billing && ! isset( $defaults['billing_email'] ) && '' !== $row['email'] ) {
                $defaults['billing_email'] = $row['email'];
            }
        }

        // WP user email is the last-ditch fallback so a freshly-registered
        // user with no orders still doesn't have to retype their email.
        if ( $is_billing && ! isset( $defaults['billing_email'] ) && function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( $user && ! empty( $user->user_email ) ) {
                $defaults['billing_email'] = (string) $user->user_email;
            }
        }

        return $defaults;
    }

    /**
     * Copy address keys into the defaults map under a `billing_` /
     * `shipping_` prefix, skipping keys that are already populated.
     *
     * @param array  $defaults Existing defaults.
     * @param array  $address  Decoded address row.
     * @param string $prefix   `billing` or `shipping`.
     * @return array
     */
    private function fill_missing( array $defaults, array $address, string $prefix ): array {
        foreach ( $address as $key => $value ) {
            $field_key = $prefix . '_' . $key;
            if ( array_key_exists( $field_key, $defaults ) ) {
                continue;
            }
            $value = (string) $value;
            if ( '' === $value ) {
                continue;
            }
            $defaults[ $field_key ] = $value;
        }
        return $defaults;
    }

    /**
     * Reconcile tejcart_customers against tejcart_orders.
     *
     * The original implementation latched a `tejcart_customers_backfilled`
     * option forever after the first successful run. That left the admin
     * Customers screen permanently out of sync whenever an order was
     * created by a path that didn't fire `tejcart_order_created` (CLI
     * imports, manual SQL, plugin upgrades that pre-date the listener).
     * The repository's backfill is idempotent (LEFT JOIN-guarded), so
     * here we re-run it on a short transient throttle to self-heal
     * without thrashing the DB on every admin request. The legacy
     * option is preserved so a future migration can drop it cleanly.
     */
    public function maybe_backfill(): void {
        if ( false !== get_transient( 'tejcart_customers_backfill_throttle' ) ) {
            return;
        }

        Customer_Repository::backfill_from_orders();

        set_transient( 'tejcart_customers_backfill_throttle', 1, 5 * MINUTE_IN_SECONDS );

        if ( 'yes' !== get_option( 'tejcart_customers_backfilled', 'no' ) ) {
            update_option( 'tejcart_customers_backfilled', 'yes' );
        }
    }
}
