<?php
/**
 * PayPal Wallet Payment Gateway
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Gateways\PayPal\Concerns\Verifies_Checkout_Nonce;

/**
 * PayPal wallet payment gateway (PayPal, Venmo, Pay Later).
 */
class PayPal_Gateway extends Abstract_Gateway {
    use PayPal_Refund_Capture;
    use Supports_PayPal_Currencies;
    use Verifies_Checkout_Nonce;

    /**
     * Partner Attribution ID (BN Code) sent with every PayPal REST request
     * and the frontend Smart Button SDK load. Hardcoded — merchants do not
     * need to configure this.
     */
    public const BN_CODE = 'mbjtechnolabs_sp';

    /**
     * Filterable PayPal Partner Attribution ID (BN code). Centralised
     * here so all SDK / API call sites (PayPal_API headers, JS SDK
     * params) read through one helper that addons / private builds
     * can override via `tejcart_paypal_bn_code` without forking core.
     *
     * Audit #64 / 05 F-7.
     */
    public static function bn_code(): string {
        return (string) apply_filters( 'tejcart_paypal_bn_code', self::BN_CODE );
    }

    /**
     * PayPal API handler.
     *
     * @var PayPal_API
     */
    private PayPal_API $api;

    /**
     * Shared API instance for use by sibling gateways.
     *
     * @var PayPal_API|null
     */
    private static ?PayPal_API $shared_api = null;

    /**
     * Shared gateway instance for use by sibling classes that need to
     * read settings without spinning up a fresh gateway per request.
     *
     * @var PayPal_Gateway|null
     */
    private static ?PayPal_Gateway $shared_instance = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'tejcart_paypal';
        $this->title       = 'PayPal';
        $this->description = 'Pay with your PayPal account, Venmo, or Pay Later.';
        $this->supports    = array( 'products', 'refunds' );

        parent::__construct();

        $this->api = new PayPal_API( $this );

        self::register_ajax_handlers();
        self::register_checkout_visibility();
    }

    /**
     * Hide the inline PayPal payment method from the checkout
     * payment-methods list when the merchant has switched off the
     * "Checkout Payment Section" placement (button_checkout = no).
     *
     * The PayPal Smart Button *is* the inline PayPal payment method, so
     * the toggle cannot merely hide the button without stranding a buyer
     * who selects PayPal (the generic Place Order button is hidden for
     * smart-button gateways, so they would see neither a button nor a
     * way to pay). Instead we drop PayPal from the selectable methods
     * entirely — buyers still reach PayPal through the express buttons,
     * which read is_available() directly and are unaffected by this
     * filter.
     */
    private static function register_checkout_visibility(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;
        add_filter( 'tejcart_available_payment_gateways', array( __CLASS__, 'filter_out_when_checkout_section_disabled' ) );
    }

    /**
     * Drop PayPal from the available-gateways map when the "Checkout
     * Payment Section" placement is disabled.
     *
     * @param array<string, Abstract_Gateway> $available Available gateways keyed by id.
     * @return array<string, Abstract_Gateway>
     */
    public static function filter_out_when_checkout_section_disabled( array $available ): array {
        $gateway = $available['tejcart_paypal'] ?? null;
        if ( $gateway instanceof self && 'yes' !== $gateway->get_option( 'button_checkout', 'yes' ) ) {
            unset( $available['tejcart_paypal'] );
        }
        return $available;
    }

    /**
     * Register the PayPal SDK v6 AJAX handlers exactly once.
     *
     * Also wires up the Partner Referrals onboarding handlers
     * ({@see PayPal_Onboarding}) so the "Connect with PayPal" button on the
     * gateway settings page has an endpoint to talk to.
     */
    private static function register_ajax_handlers(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;
        ( new PayPal_AJAX() )->register();
        PayPal_Onboarding::register();
    }

    /**
     * Get the shared PayPal API instance.
     *
     * Creates a PayPal_Gateway instance on first call and caches the API object
     * so that sibling gateways (Card, Google Pay, etc.) can reuse the same
     * credentials and API connection.
     *
     * @internal Sibling-gateway plumbing only. External callers should
     *           resolve the gateway via `tejcart()->gateways()->get('tejcart_paypal')`
     *           and call `get_api()` on the result so tests can swap
     *           the bound implementation via the Container (ARC-004).
     *
     * @return PayPal_API
     */
    public static function get_shared_api(): PayPal_API {
        return self::get_shared_instance()->api;
    }

    /**
     * Get (or lazily create) the shared PayPal_Gateway instance so
     * sibling classes can read settings without re-instantiating.
     *
     * @internal Sibling-gateway plumbing only. External callers should
     *           go through the DI Container — `tejcart()->gateways()->
     *           get('tejcart_paypal')` — so test scaffolds can swap the
     *           bound implementation (ARC-004).
     */
    public static function get_shared_instance(): PayPal_Gateway {
        if ( null === self::$shared_instance ) {
            self::$shared_instance = new self();
            self::$shared_api      = self::$shared_instance->api;
        }
        return self::$shared_instance;
    }

    /**
     * Sentinel returned by {@see find_order_id_by_paypal_id} when more
     * than one TejCart order claims the same PayPal id. Callers should
     * treat it distinctly from `0` (not found) — e.g. return HTTP 409
     * instead of 404 — so the collision surfaces in observability.
     */
    public const PAYPAL_ID_COLLISION = -1;

    /**
     * Look up a TejCart order ID by its PayPal order or capture ID.
     * Shared by PayPal_AJAX and PayPal_Webhook.
     *
     * M-7: when more than one TejCart order claims the same PayPal id
     * (which should never happen — see {@see record_paypal_id_meta()}
     * which refuses such writes), fire a security alert so operators
     * can investigate. Returns `self::PAYPAL_ID_COLLISION` (-1) in that
     * ambiguous state so callers can fail closed AND distinguish
     * collision from a missing record.
     */
    public static function find_order_id_by_paypal_id( string $paypal_id ): int {
        if ( '' === $paypal_id ) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $matches = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$table} WHERE meta_key IN ('_paypal_order_id', '_paypal_capture_id') AND meta_value = %s",
                $paypal_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( ! is_array( $matches ) || empty( $matches ) ) {
            return 0;
        }

        $matches = array_map( 'intval', $matches );
        if ( count( $matches ) > 1 ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'PayPal id collision: one paypal_id maps to multiple TejCart orders [%s]. Fulfilment refused.',
                        implode( ',', $matches )
                    ),
                    'critical'
                );
            }
            /**
             * Fires when a PayPal order/capture id resolves to more than
             * one TejCart order. Hook this from a notification plugin
             * (Slack, email, sentry) — collisions never happen in normal
             * operation and indicate either a bug or a deliberate
             * importer-side write-collision attempt.
             *
             * @param string $paypal_id  The colliding PayPal id.
             * @param int[]  $order_ids  All TejCart order ids that match.
             */
            do_action( 'tejcart_paypal_id_collision', $paypal_id, $matches );
            return self::PAYPAL_ID_COLLISION;
        }

        return (int) $matches[0];
    }

    /**
     * Persist a `_paypal_order_id` or `_paypal_capture_id` meta on an
     * order, refusing the write when an existing row already maps that
     * value to a different order.
     *
     * Use this in place of `tejcart_update_order_meta()` for those two
     * keys so a privileged caller (admin importer, sibling plugin, REST
     * API key with write scope) cannot copy a PayPal id onto a higher-
     * numbered order and steal subsequent webhook captures (M-7).
     *
     * Returns true on success, WP_Error on collision.
     *
     * @param int    $order_id Target TejCart order id.
     * @param string $key      Meta key — must be `_paypal_order_id` or
     *                         `_paypal_capture_id`.
     * @param string $value    PayPal id to record.
     * @return true|\WP_Error
     */
    public static function record_paypal_id_meta( int $order_id, string $key, string $value ) {
        if ( $order_id <= 0 ) {
            return new \WP_Error( 'tejcart_paypal_meta_invalid_order', 'Invalid order id.' );
        }
        if ( ! in_array( $key, array( '_paypal_order_id', '_paypal_capture_id' ), true ) ) {
            return new \WP_Error( 'tejcart_paypal_meta_invalid_key', 'Unsupported meta key.' );
        }
        if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9-]+$/', $value ) ) {
            return new \WP_Error( 'tejcart_paypal_meta_invalid_value', 'PayPal id shape invalid.' );
        }

        // Empty value: this is a clear write (e.g. PayPal_AJAX:765
        // wipes a stale id when the previous merchant account differs).
        // Skip the collision check.
        if ( '' !== $value ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tejcart_order_meta';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT order_id FROM {$table}
                      WHERE meta_key IN ('_paypal_order_id', '_paypal_capture_id')
                        AND meta_value = %s
                        AND order_id <> %d
                      LIMIT 1",
                    $value,
                    $order_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

            if ( $existing > 0 ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf(
                            'Refused to write %s=%s on order %d: already owned by order %d.',
                            $key,
                            $value,
                            $order_id,
                            $existing
                        ),
                        'critical'
                    );
                }
                /** @see find_order_id_by_paypal_id for action contract. */
                do_action( 'tejcart_paypal_id_collision', $value, array( $existing, $order_id ) );
                return new \WP_Error(
                    'tejcart_paypal_meta_collision',
                    sprintf( 'Another order (%d) already claims this PayPal id.', $existing )
                );
            }
        }

        tejcart_update_order_meta( $order_id, $key, $value );
        return true;
    }

    /**
     * Persist the PayPal capture ID, environment flag, and order currency
     * onto the order's meta so the admin can render a deep link to the
     * matching PayPal activity page (live vs. sandbox host) and so future
     * features (reconciliation, multi-currency reporting) have the
     * captured currency available without touching the gateway.
     *
     * Stores three meta keys:
     *  - `_paypal_capture_id`  — the PayPal capture/transaction ID
     *  - `_paypal_environment` — "live" or "sandbox"
     *  - `_paypal_currency`    — ISO-4217 currency code copied from the order
     *
     * @param int    $order_id       TejCart order ID.
     * @param string $transaction_id PayPal capture ID.
     * @return true|\WP_Error True on success; WP_Error when the capture id is
     *                        already owned by another order (collision). The
     *                        caller MUST NOT promote the order to a fulfilled
     *                        state (processing/completed) on WP_Error — doing
     *                        so leaves a paid order with no `_paypal_capture_id`
     *                        of its own, which is un-refundable and is wrongly
     *                        picked up by the orphan sweeper's IS NULL filter.
     */
    public static function record_transaction_meta( int $order_id, string $transaction_id ) {
        if ( $order_id <= 0 || '' === $transaction_id ) {
            return new \WP_Error( 'tejcart_paypal_meta_invalid', 'Missing order id or capture id.' );
        }

        $transaction_id = sanitize_text_field( $transaction_id );

        // M-7: refuse the write when another order already owns this PayPal
        // capture id, and propagate that refusal so the caller can route the
        // order to on-hold instead of silently promoting it to processing.
        // (record_paypal_id_meta already logs the collision at 'critical'.)
        $recorded = self::record_paypal_id_meta( $order_id, '_paypal_capture_id', $transaction_id );
        if ( is_wp_error( $recorded ) ) {
            return $recorded;
        }

        tejcart_update_order_meta(
            $order_id,
            '_paypal_environment',
            self::current_environment()
        );

        if ( function_exists( 'tejcart_get_order' ) ) {
            $order = tejcart_get_order( $order_id );
            if ( $order && method_exists( $order, 'get_currency' ) ) {
                $currency = strtoupper( (string) $order->get_currency() );
                if ( '' !== $currency ) {
                    tejcart_update_order_meta( $order_id, '_paypal_currency', $currency );
                }
            }
        }

        return true;
    }

    /**
     * Resolve the active PayPal environment ("live" or "sandbox") from
     * the persisted gateway settings. Reads `wp_options` directly so it
     * is safe to call from contexts that should not instantiate the
     * gateway (e.g. admin renderers, REST callbacks).
     *
     * @return string "live" or "sandbox".
     */
    public static function current_environment(): string {
        $settings = get_option( 'tejcart_gateway_tejcart_paypal', array() );
        if ( ! is_array( $settings ) ) {
            return 'sandbox';
        }

        return ( ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes' ) ? 'sandbox' : 'live';
    }

    /**
     * Build the merchant-facing PayPal transaction details URL for the
     * given capture/transaction ID and environment flag. Returns an
     * empty string for unrecognised inputs so the caller can decide
     * whether to render a plain text fallback.
     *
     * @param string $transaction_id PayPal capture/transaction ID.
     * @param string $environment    "live" or "sandbox".
     * @return string Absolute URL or empty string when inputs are invalid.
     */
    public static function get_transaction_url( string $transaction_id, string $environment ): string {
        $transaction_id = trim( $transaction_id );
        if ( '' === $transaction_id || ! preg_match( '/^[A-Za-z0-9-]+$/', $transaction_id ) ) {
            return '';
        }

        $host = ( 'live' === $environment )
            ? 'https://www.paypal.com'
            : 'https://www.sandbox.paypal.com';

        return $host . '/activity/payment/' . rawurlencode( $transaction_id );
    }

    /**
     * Define admin settings fields.
     */
    public function init_form_fields(): void {
        $settings = new PayPal_Settings();
        $this->form_fields = $settings->get_form_fields();
    }

    /**
     * Check whether the gateway is available for use.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ( ! $this->enabled ) {
            return false;
        }

        if ( empty( $this->get_client_id() ) ) {
            return false;
        }

        return true;
    }

    /**
     * Register (or reuse) the site's PayPal webhook subscription for the
     * currently active environment and persist the returned webhook ID into
     * the gateway settings.
     *
     * Reuses an existing webhook subscription with the same URL if one is
     * already registered, so repeated calls are idempotent.
     *
     * @return true|\WP_Error True on success, WP_Error describing the
     *                        failure otherwise.
     */
    public function register_webhook_for_current_env() {
        $webhook_url = rest_url( 'tejcart/v1/webhook/paypal' );

        if ( 0 !== strpos( $webhook_url, 'https://' ) ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_https',
                __( 'PayPal requires an HTTPS webhook URL. Enable HTTPS on this site before registering.', 'tejcart' )
            );
        }

        $existing = $this->api->list_webhooks();
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        $webhook_id = '';
        foreach ( (array) ( $existing['webhooks'] ?? array() ) as $hook ) {
            if ( isset( $hook['url'], $hook['id'] ) && $hook['url'] === $webhook_url ) {
                $webhook_id = (string) $hook['id'];
                break;
            }
        }

        if ( '' === $webhook_id ) {
            $created = $this->api->create_webhook( $webhook_url );
            if ( is_wp_error( $created ) ) {
                return $created;
            }
            $webhook_id = (string) ( $created['id'] ?? '' );
        }

        if ( '' === $webhook_id ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_empty',
                __( 'PayPal did not return a webhook ID.', 'tejcart' )
            );
        }

        $this->update_option( 'webhook_id', $webhook_id );
        $this->save_settings();

        return true;
    }

    /**
     * Whether the store has completed PayPal seller onboarding.
     *
     * Seller onboarding is considered complete as soon as a PayPal REST API
     * client ID is available for the currently selected environment. We use
     * the merchant's stored client ID as the canonical "onboarded" signal.
     *
     * Reads directly from wp_options so it can be called cheaply from any
     * context (including other gateways' constructors) without instantiating
     * a fresh PayPal_Gateway object.
     *
     * @return bool
     */
    public static function is_onboarded(): bool {
        $settings = get_option( 'tejcart_gateway_tejcart_paypal', array() );

        if ( ! is_array( $settings ) ) {
            return false;
        }

        $sandbox_mode = ( ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes' );
        $client_id    = $sandbox_mode
            ? ( $settings['sandbox_client_id'] ?? '' )
            : ( $settings['client_id'] ?? '' );

        /**
         * Filter whether PayPal seller onboarding is considered complete.
         *
         * @param bool  $onboarded Whether onboarding is complete.
         * @param array $settings  Raw PayPal gateway settings.
         */
        return (bool) apply_filters(
            'tejcart_paypal_is_onboarded',
            ! empty( $client_id ),
            $settings
        );
    }

    /**
     * Check whether a sibling PPCP gateway (Google Pay, Apple Pay, Fastlane,
     * Card) is enabled in its own settings option. Used to drive the
     * Smart-Buttons SDK component list and storefront eligibility checks now
     * that the per-method master switches have been retired in favour of the
     * gateway-level toggle as the single source of truth.
     *
     * @param string $gateway_id Gateway identifier (e.g. tejcart_googlepay).
     * @return bool
     */
    public static function is_sibling_gateway_enabled( string $gateway_id ): bool {
        $settings = get_option( 'tejcart_gateway_' . $gateway_id, array() );
        return is_array( $settings ) && ( $settings['enabled'] ?? 'no' ) === 'yes';
    }

    /**
     * Whether the current cart can be paid for via the PayPal express
     * flow. Sibling plugins veto via the `tejcart_paypal_express_allowed`
     * filter (e.g. country lock-outs, age gates). Subscription carts
     * are NOT vetoed here — `PayPal_AJAX::create_express_order()` fires
     * `tejcart_checkout_validation` so the Subscriptions PayPal_Bridge
     * can mint a vault block, and fires `tejcart_checkout_order_processed`
     * after capture so the Checkout_Integration creates the
     * Subscription rows.
     *
     * Templates use this to hide cart-page / drawer / top-of-checkout
     * express buttons; `PayPal_AJAX::create_express_order()` fires the
     * same filter server-side so a stale browser tab cannot pop a
     * disallowed click through.
     */
    public static function cart_supports_express(): bool {
        if ( ! function_exists( 'tejcart_get_cart' ) ) {
            return true;
        }
        $cart = tejcart_get_cart();
        if ( ! $cart || ! method_exists( $cart, 'get_items' ) ) {
            return true;
        }
        $items = array();
        foreach ( $cart->get_items() as $cart_item ) {
            if ( ! is_object( $cart_item ) ) {
                continue;
            }
            $items[] = array(
                'product_id'   => method_exists( $cart_item, 'get_product_id' ) ? (int) $cart_item->get_product_id() : 0,
                'variation_id' => method_exists( $cart_item, 'get_variation_id' ) ? (int) $cart_item->get_variation_id() : 0,
                'quantity'     => method_exists( $cart_item, 'get_quantity' ) ? (int) $cart_item->get_quantity() : 1,
            );
        }
        /** @see PayPal_AJAX::create_express_order() for the filter contract. */
        $allowed = apply_filters( 'tejcart_paypal_express_allowed', true, $items, 0 );
        return ! is_wp_error( $allowed );
    }

    /**
     * Whether the given product can be paid for via the PDP "Buy Now"
     * express button. Fires the same `tejcart_paypal_express_allowed`
     * filter as the cart-level helper so the bridge has one extension
     * point regardless of source.
     *
     * @param mixed $product Product instance (any concrete Abstract_Product).
     */
    public static function product_supports_express( $product ): bool {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return true;
        }
        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            return true;
        }
        $items = array(
            array( 'product_id' => $product_id, 'quantity' => 1 ),
        );
        /** @see PayPal_AJAX::create_express_order() for the filter contract. */
        $allowed = apply_filters( 'tejcart_paypal_express_allowed', true, $items, $product_id );
        return ! is_wp_error( $allowed );
    }

    /**
     * Process a payment for the given order.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }

        // Defence-in-depth (L-5): the upstream caller has already verified a
        // nonce — either tejcart_process_checkout (standard checkout form
        // submit) or tejcart_paypal (PayPal AJAX create_order, which mints
        // $_POST['_wpnonce'] as a tejcart_paypal nonce instead). Accept
        // either action so the AJAX flow isn't rejected here while still
        // refusing a present-but-bogus nonce. A future caller path that
        // forgets to gate upstream would otherwise expose the saved-method
        // and save-method POST inputs read below.
        // Audit #11 / 05 F-2 — extracted into a shared trait so the
        // four sibling gateways (Card, ApplePay, GooglePay, Fastlane)
        // mount the same defence-in-depth check.
        $nonce_failure = $this->require_checkout_nonce();
        if ( null !== $nonce_failure ) {
            return $nonce_failure;
        }

        /**
         * Fires before a payment is processed.
         *
         * @param int    $order_id Order ID.
         * @param object $order    Order object.
         */
        do_action( 'tejcart_before_payment', $order_id, $order );

        $vault_token_id = '';
        // Nonce verified in checkout submission upstream of this handler.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $saved_method_id = isset( $_POST['tejcart_paypal_saved_method'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_paypal_saved_method'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( '' !== $saved_method_id && is_user_logged_in() ) {
            $methods = \TejCart\Customer\Payment_Methods::instance()->get_saved_methods( get_current_user_id() );
            foreach ( $methods as $method ) {
                if ( ( $method['id'] ?? '' ) === $saved_method_id ) {
                    $vault_token_id = \TejCart\Customer\Payment_Methods::get_token_id( $method );
                    break;
                }
            }
        }

        // Nonce verified in checkout submission upstream of this handler.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $save_method = is_user_logged_in()
            && '' === $vault_token_id
            && ! empty( $_POST['tejcart_paypal_save_method'] )
            && ( $this->get_option( 'save_payment_methods', 'yes' ) === 'yes' );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $paypal_order = $this->api->create_order( $order, $vault_token_id, $save_method );

        if ( '' !== $vault_token_id ) {
            tejcart_update_order_meta( $order_id, '_paypal_used_vault_token', $vault_token_id );
        }

        if ( $save_method ) {
            tejcart_update_order_meta( $order_id, '_paypal_save_method_intent', 'yes' );
            tejcart_update_order_meta( $order_id, '_paypal_save_method_user', (int) get_current_user_id() );
        }

        if ( is_wp_error( $paypal_order ) ) {
            $message = $paypal_order->get_error_message();
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( sprintf( 'PayPal create_order failed for order #%d: %s', $order_id, $message ), 'error' );
            }
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => $message,
            );
        }

        if ( empty( $paypal_order['id'] ) || ! preg_match( '/^[A-Za-z0-9-]+$/', (string) $paypal_order['id'] ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'PayPal returned an invalid order identifier.', 'tejcart' ),
            );
        }

        // Vault-token / saved-method flow: PayPal charges the stored
        // payment method server-side and the create_order response carries
        // status=COMPLETED with the capture already inside
        // purchase_units[0].payments.captures[0]. There is no buyer
        // redirect because no approval is needed — short-circuit straight
        // to the thank-you page after recording the capture.
        $order_status = strtoupper( (string) ( $paypal_order['status'] ?? '' ) );
        if ( 'COMPLETED' === $order_status ) {
            return $this->finalize_vault_capture( $order_id, $order, $paypal_order );
        }

        $approval_url = '';

        if ( ! empty( $paypal_order['links'] ) ) {
            foreach ( $paypal_order['links'] as $link ) {
                if ( ! isset( $link['rel'], $link['href'] ) ) {
                    continue;
                }
                // PayPal Orders v2 returns the buyer-redirect link as
                // `approve` for the legacy PayPal-checkout flow and as
                // `payer-action` for the vault flow (save_method=true,
                // status=PAYER_ACTION_REQUIRED) and 3DS step-ups. Either
                // rel value is the URL we need to send the buyer to.
                if ( 'approve' === $link['rel'] || 'payer-action' === $link['rel'] ) {
                    $approval_url = (string) $link['href'];
                    break;
                }
            }
        }

        if ( empty( $approval_url ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'PayPal did not return an approval URL. Please try again.', 'tejcart' ),
            );
        }

        self::record_paypal_id_meta( $order_id, '_paypal_order_id', (string) $paypal_order['id'] );

        // Bind the order to the session/identity that created it so the
        // buyer-driven capture / cancel / update-shipping AJAX endpoints can
        // verify ownership. The express-button flow persists this inside
        // PayPal_AJAX::create_order(); the standard checkout reaches PayPal
        // only through here, so without it a guest — or a "create account"
        // buyer whose email matched an existing customer (the order is
        // linked to that customer_id but the session is never authenticated)
        // — would have no stored hash to fall back to and 403 at capture.
        PayPal_AJAX::persist_session_owner( $order_id );

        return array(
            'result'   => 'success',
            'redirect' => $approval_url,
        );
    }

    /**
     * Finalise a vault-token order whose create_order response already
     * carries `status=COMPLETED` and the capture payload inline.
     *
     * Charging a stored payment instrument on behalf of the buyer does not
     * require an approval redirect: PayPal accepts our breakdown, captures
     * synchronously, and returns the capture in the same response. Here we
     * mirror the post-capture bookkeeping that PayPal_AJAX::capture_order()
     * performs for the wallet-redirect flow — record `_paypal_order_id`
     * and `_paypal_capture_id`, persist payer/fraud meta, sanity-check the
     * captured amount, add the capture note, promote the order to
     * `processing`, and hand the buyer the thank-you redirect.
     *
     * We MUST flip the status here. The capture already happened inside
     * create_order, so we return a (non-empty) thank-you redirect — and
     * Checkout::process() only auto-promotes pending → processing when the
     * gateway returns an EMPTY redirect (its guard for the async wallet
     * flow). Leaving the transition to the caller therefore stranded paid
     * saved-method orders in `pending`: no receipt email, no stock
     * decrement, the cart never cleared. Firing the transition here runs
     * the same `tejcart_order_status_processing` listeners every other
     * synchronous gateway relies on.
     *
     * @param int                  $order_id     Local TejCart order id.
     * @param \TejCart\Order\Order $order        Local order instance.
     * @param array<string, mixed> $paypal_order PayPal Orders v2 response
     *                                           with `status=COMPLETED`.
     * @return array<string, string>             Same shape as `process_payment()`.
     */
    private function finalize_vault_capture( int $order_id, $order, array $paypal_order ): array {
        self::record_paypal_id_meta( $order_id, '_paypal_order_id', (string) $paypal_order['id'] );

        $captures   = $paypal_order['purchase_units'][0]['payments']['captures'] ?? array();
        $capture    = is_array( $captures ) && isset( $captures[0] ) && is_array( $captures[0] )
            ? $captures[0]
            : array();
        $capture_id = isset( $capture['id'] ) ? sanitize_text_field( (string) $capture['id'] ) : '';

        $capture_status = strtoupper( (string) ( $capture['status'] ?? '' ) );
        if ( in_array( $capture_status, array( 'DECLINED', 'FAILED' ), true ) ) {
            $reason = (string) ( $capture['status_details']['reason'] ?? '' );
            $message = '' !== $reason
                ? sprintf(
                    /* translators: %s: PayPal decline reason code */
                    __( 'Your saved PayPal payment was declined (%s). Please try a different method.', 'tejcart' ),
                    $reason
                )
                : __( 'Your saved PayPal payment was declined. Please try a different method.', 'tejcart' );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'PayPal vault capture %s on order #%d: %s', $capture_status, $order_id, $reason ),
                    'error'
                );
            }
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => $message,
            );
        }

        // Tracks whether the capture id could be authoritatively bound to this
        // order. A collision (capture id already owned by another order) must
        // block the pending → processing promotion below, exactly like an
        // amount mismatch, so we never fulfil a paid-but-unrefundable order.
        $capture_meta_error = false;
        if ( '' !== $capture_id ) {
            $recorded = self::record_transaction_meta( $order_id, $capture_id );
            if ( is_wp_error( $recorded ) ) {
                $capture_meta_error = true;
                if ( $order && method_exists( $order, 'update_status' ) && 'on-hold' !== $order->get_status() ) {
                    $order->update_status(
                        'on-hold',
                        __( 'PayPal capture id collision — order placed on hold for manual review.', 'tejcart' )
                    );
                }
            }
        }

        $payer = $paypal_order['payer'] ?? array();
        if ( is_array( $payer ) ) {
            if ( ! empty( $payer['email_address'] ) ) {
                tejcart_update_order_meta( $order_id, '_paypal_payer_email', sanitize_email( (string) $payer['email_address'] ) );
            }
            if ( ! empty( $payer['payer_id'] ) ) {
                tejcart_update_order_meta( $order_id, '_paypal_payer_id', sanitize_text_field( (string) $payer['payer_id'] ) );
            }
        }
        if ( isset( $paypal_order['payment_source'] ) && is_array( $paypal_order['payment_source'] ) ) {
            $funding = array_key_first( $paypal_order['payment_source'] );
            if ( $funding ) {
                tejcart_update_order_meta( $order_id, '_paypal_funding_source', sanitize_text_field( (string) $funding ) );
            }
        }

        $processor = is_array( $capture['processor_response'] ?? null ) ? $capture['processor_response'] : array();
        foreach ( array(
            '_paypal_avs_code'           => $processor['avs_code'] ?? '',
            '_paypal_cvv_code'           => $processor['cvv_code'] ?? '',
            '_paypal_processor_response' => $processor['response_code'] ?? '',
            '_paypal_seller_protection'  => $capture['seller_protection']['status'] ?? '',
        ) as $meta_key => $value ) {
            if ( '' !== (string) $value ) {
                tejcart_update_order_meta( $order_id, $meta_key, sanitize_text_field( (string) $value ) );
            }
        }

        $mismatch = false;
        if ( $order && method_exists( $order, 'get_total' ) && method_exists( $order, 'get_currency' ) ) {
            $captured_amount   = isset( $capture['amount']['value'] ) ? (float) $capture['amount']['value'] : 0.0;
            $captured_currency = isset( $capture['amount']['currency_code'] ) ? strtoupper( (string) $capture['amount']['currency_code'] ) : '';
            $order_total       = (float) $order->get_total();
            $order_currency    = strtoupper( (string) $order->get_currency() );

            $captured_minor = \TejCart\Money\Currency::to_minor_units( $captured_amount, $order_currency );
            $expected_minor = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );

            if ( $captured_minor !== $expected_minor || '' === $captured_currency || $captured_currency !== $order_currency ) {
                $mismatch = true;
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf(
                            'PayPal vault capture mismatch on order #%d: captured %s %s, expected %s %s.',
                            $order_id,
                            $captured_amount,
                            $captured_currency,
                            $order_total,
                            $order_currency
                        ),
                        'error'
                    );
                }
                if ( method_exists( $order, 'update_status' ) ) {
                    $order->update_status(
                        'on-hold',
                        sprintf(
                            /* translators: 1: captured amount, 2: captured currency, 3: expected total, 4: expected currency */
                            __( 'PayPal capture mismatch: received %1$s %2$s, expected %3$s %4$s. Order placed on hold for manual review.', 'tejcart' ),
                            $captured_amount,
                            $captured_currency,
                            $order_total,
                            $order_currency
                        )
                    );
                }
            }
        }

        // Amounts reconcile (or the order cannot expose them for checking):
        // record the capture on the timeline and promote pending → processing.
        // Unlike the wallet flow there is no later capture_order AJAX hop to
        // move the status, and Checkout::process() will not auto-promote
        // because we return a non-empty redirect below — so without this the
        // paid order would linger in `pending`. The transition fires the
        // shared tejcart_order_status_processing listeners (receipt email,
        // stock decrement, cart cleanup, sales counter). Mirrors the success
        // tail of PayPal_AJAX::capture_order(). The `processing` guard keeps
        // it idempotent if a fast capture webhook already advanced the row.
        if ( ! $mismatch && ! $capture_meta_error && $order && method_exists( $order, 'update_status' ) && 'processing' !== $order->get_status() ) {
            if ( method_exists( $order, 'add_note' ) ) {
                // Name the actual wallet (Google Pay / Apple Pay / Venmo) the
                // buyer used; the funding source is persisted earlier in this
                // flow, so get_payment_method_title() resolves it.
                $captured_via = method_exists( $order, 'get_payment_method_title' ) && '' !== (string) $order->get_payment_method_title()
                    ? (string) $order->get_payment_method_title()
                    : __( 'PayPal', 'tejcart' );
                $order->add_note(
                    sprintf(
                        /* translators: 1: payment method / wallet name, 2: PayPal capture ID. */
                        __( 'Payment captured via %1$s. Capture ID: %2$s.', 'tejcart' ),
                        $captured_via,
                        '' !== $capture_id ? $capture_id : __( '(unknown)', 'tejcart' )
                    )
                );
            }
            $order->update_status( 'processing' );
        }

        $redirect = ( $order && method_exists( $order, 'get_checkout_return_url' ) )
            ? (string) $order->get_checkout_return_url()
            : (string) tejcart_get_thankyou_url( $order_id );

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    /**
     * Process a refund.
     *
     * Returns the PayPal refund ID (a non-empty string) on success so
     * {@see \TejCart\Order\Order_Manager::process_refund()} can persist it
     * as the local refund row's `transaction_ref` for reconciliation and
     * idempotency. Falls back to `true` if PayPal accepted the refund but
     * did not echo an ID (older sandbox responses).
     *
     * Refund body lives in {@see PayPal_Refund_Capture::process_refund}
     * (the trait imported above) so the four sibling gateways
     * (Card / ApplePay / GooglePay / Fastlane) get identical guards.
     * This hook provides the API client.
     */
    protected function get_paypal_api(): PayPal_API {
        return $this->api;
    }

    /**
     * Output the PayPal button container on the checkout page.
     *
     * Renders three logically-distinct controls for logged-in customers:
     *
     *   1. A radiogroup of saved PayPal vault tokens (when any exist),
     *      with "Use a new payment method" as the first option so the
     *      default selection still matches a guest checkout.
     *   2. A "Save this payment method for future purchases" checkbox.
     *      The server-side already refuses to save when a vault token
     *      is being reused (see `process_payment()`), but the row is
     *      also hidden client-side via JS the moment a saved method is
     *      selected so the UI doesn't lie to the buyer. The wrapper
     *      carries `data-tejcart-paypal-save-row` so the checkout JS
     *      can find it without coupling to a class name.
     *   3. The Smart Buttons container.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }

        if ( is_user_logged_in() && class_exists( '\\TejCart\\Customer\\Payment_Methods' ) ) {
            $saved = \TejCart\Customer\Payment_Methods::instance()->get_saved_methods( get_current_user_id() );
            if ( ! empty( $saved ) ) {
                echo '<fieldset class="tejcart-paypal-saved-methods" data-tejcart-paypal-saved-methods>';
                echo '<legend>' . esc_html__( 'Pay with a saved method:', 'tejcart' ) . '</legend>';
                echo '<label><input type="radio" name="tejcart_paypal_saved_method" value="" checked> '
                    . esc_html__( 'Use a new payment method', 'tejcart' ) . '</label>';
                foreach ( $saved as $method ) {
                    $method_id    = (string) ( $method['id'] ?? '' );
                    $method_label = (string) ( $method['label'] ?? '' );
                    $method_type  = (string) ( $method['type'] ?? 'paypal' );
                    if ( '' === $method_id ) {
                        continue;
                    }
                    printf(
                        '<label><input type="radio" name="tejcart_paypal_saved_method" value="%1$s"> %2$s</label>',
                        esc_attr( $method_id ),
                        esc_html( '' !== $method_label ? $method_label : ucfirst( $method_type ) )
                    );
                }
                echo '</fieldset>';
            }

            echo '<p class="tejcart-paypal-save-method" data-tejcart-paypal-save-row><label>'
                . '<input type="checkbox" name="tejcart_paypal_save_method" value="1"> '
                . esc_html__( 'Save this payment method for future purchases', 'tejcart' )
                . '</label></p>';
        }

        echo '<div id="tejcart-paypal-button-container"></div>';
    }

    /**
     * Build the JS params array consumed by assets/js/tejcart-paypal.js.
     *
     * Public so Frontend::enqueue_assets() can localize it on every page
     * (the SDK needs these params on product / cart / side-cart pages,
     * not only inside the checkout payment section).
     *
     * @return array
     */
    public function get_script_params(): array {
        $button_height_raw = (string) $this->get_option( 'button_height', '' );
        $button_height     = '';
        if ( '' !== $button_height_raw && is_numeric( $button_height_raw ) ) {
            $h = (int) $button_height_raw;
            if ( $h >= 25 && $h <= 55 ) {
                $button_height = (string) $h;
            }
        }

        $disable_funding_list = array_values(
            array_filter(
                array_map( 'trim', explode( ',', (string) $this->get_option( 'disable_funding', '' ) ) )
            )
        );
        $disable_cards_list = array_values(
            array_filter(
                array_map( 'trim', explode( ',', (string) $this->get_option( 'disable_cards', '' ) ) )
            )
        );

        // F-PPCP-006: use format_amount() with the store currency rather than
        // number_format(..., 2) so that JPY (0 dp) and KWD/BHD/OMR (3 dp)
        // carts display the correct amount on the Smart Button overlay.
        $script_currency = function_exists( 'tejcart_get_setting' )
            ? strtoupper( (string) tejcart_get_setting( 'currency', 'USD' ) )
            : 'USD';
        $order_total    = '0.00';
        if ( function_exists( 'tejcart_get_cart' ) ) {
            $cart = tejcart_get_cart();
            if ( $cart ) {
                $cart_total = (float) $cart->get_total();
                if ( $cart_total > 0 ) {
                    $order_total = PayPal_API::format_amount( $cart_total, $script_currency );
                }
            }
        }

        $needs_shipping = self::resolve_script_needs_shipping();

        $allowed_countries = array();
        if ( function_exists( 'tejcart_get_setting' ) ) {
            $raw_countries = tejcart_get_setting( 'shipping_countries', '' );
            if ( is_string( $raw_countries ) && '' !== $raw_countries ) {
                $allowed_countries = array_values(
                    array_filter(
                        array_map(
                            static function ( $code ) {
                                $code = strtoupper( trim( (string) $code ) );
                                return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
                            },
                            explode( ',', $raw_countries )
                        )
                    )
                );
            } elseif ( is_array( $raw_countries ) ) {
                foreach ( $raw_countries as $code ) {
                    $code = strtoupper( trim( (string) $code ) );
                    if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
                        $allowed_countries[] = $code;
                    }
                }
            }
        }

        // PayPal v6 SDK is rolling under /v6/core; expose
        // an override hook so a release runbook can pin to a specific
        // PayPal-published revision URL when needed. Default empty
        // means the JS layer uses the standard /v6/core endpoint.
        $sdk_url_default = '';
        /**
         * Filter the PayPal Web SDK script URL the JS layer loads.
         *
         * Pass a fully-qualified PayPal-published URL to pin the SDK
         * version. Leave empty to use the rolling /v6/core endpoint.
         *
         * @param string                $url        Override URL or '' for default.
         * @param bool                  $is_sandbox Whether sandbox mode is active.
         * @param \TejCart\Gateways\PayPal\PayPal_Gateway $gateway
         */
        $sdk_url = (string) apply_filters( 'tejcart_paypal_sdk_url', $sdk_url_default, $this->is_sandbox(), $this );

        return array(
            'client_id'       => $this->get_client_id(),
            'currency'        => tejcart_get_setting( 'currency', 'USD' ),
            'locale'          => (string) $this->get_option( 'locale', '' ),
            'merchant_id'     => $this->get_merchant_id(),
            'bn_code'         => self::bn_code(),
            'is_sandbox'      => $this->is_sandbox(),
            /**
             * Toggle verbose client-side wallet diagnostics (Google Pay
             * lifecycle logging via gpLog()). Off by default; defaults to
             * WP_DEBUG so staging/dev consoles get the trace without exposing
             * it to live shoppers. Operators can also flip it at runtime from
             * the browser console with `window.tejcartGooglePayDebug = true`.
             *
             * @param bool $debug Whether verbose wallet logging is enabled.
             */
            'debug'           => (bool) apply_filters( 'tejcart_paypal_debug', defined( 'WP_DEBUG' ) && WP_DEBUG ),
            'sdk_url'         => $sdk_url,
            'enable_venmo'    => ( $this->get_option( 'enable_venmo', 'yes' ) === 'yes' ),
            // H-5: Pay Later messaging is forbidden by PayPal partner policy
            // on the order-received / thank-you page. Suppress unconditionally
            // there even if the merchant left the toggle on.
            'enable_paylater' => (
                ( $this->get_option( 'enable_paylater', 'no' ) === 'yes' )
                && ! self::is_order_received_page()
            ),
            'enable_google_pay' => self::is_sibling_gateway_enabled( 'tejcart_googlepay' ),
            'enable_apple_pay'  => self::is_sibling_gateway_enabled( 'tejcart_applepay' ),
            'enable_fastlane'   => self::is_sibling_gateway_enabled( 'tejcart_fastlane' ),

            'button_layout'   => $this->get_option( 'button_layout', 'vertical' ),
            'button_color'    => $this->get_option( 'button_color', 'gold' ),
            'button_shape'    => $this->get_option( 'button_shape', 'rect' ),
            'button_label'    => $this->get_option( 'button_label', 'paypal' ),
            'button_tagline'  => (
                'yes' === $this->get_option( 'button_tagline', 'no' )
                && 'horizontal' === $this->get_option( 'button_layout', 'vertical' )
                && 'gold' === $this->get_option( 'button_color', 'gold' )
            ),
            'button_height'   => $button_height,
            'store_name'      => tejcart_get_setting( 'store_name', get_bloginfo( 'name' ) ),

            'button_product_page'      => ( $this->get_option( 'button_product_page', 'yes' ) === 'yes' ),
            'button_cart_page'         => ( $this->get_option( 'button_cart_page', 'yes' ) === 'yes' ),
            'button_express_checkout'  => ( $this->get_option( 'button_express_checkout', 'yes' ) === 'yes' ),
            'button_side_cart'         => ( $this->get_option( 'button_side_cart', 'yes' ) === 'yes' ),
            'button_checkout'          => ( $this->get_option( 'button_checkout', 'yes' ) === 'yes' ),
            'disable_funding' => $disable_funding_list,
            'disable_cards'   => $disable_cards_list,
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'tejcart_paypal' ),
            'cart_nonce'      => wp_create_nonce( 'tejcart_nonce' ),
            'order_total'     => $order_total,
            'needs_shipping'  => $needs_shipping,
            'shipping_allowed_countries' => $allowed_countries,
            'apple_pay_style'  => $this->get_apple_pay_style(),
            'google_pay_style' => $this->get_google_pay_style(),
        );
    }

    /**
     * Decide the `needs_shipping` flag passed to the PayPal JS layer.
     *
     * The flag controls whether the SDK wires `onShippingAddressChange`
     * and `onShippingOptionsChange`, which in turn drive PayPal's
     * shipping address sheet and shipping-method picker inside the
     * wallet popup. Three rules, in order:
     *
     *  1. Global kill-switch — when `tejcart_enable_shipping !== 'yes'`
     *     shipping is off across the entire store, so the JS must not
     *     even hint at it (a store with no zones returns COUNTRY_ERROR
     *     from `update_shipping`, which the buyer can never resolve).
     *
     *  2. Cart-side decision is authoritative when the cart has items —
     *     a gift-card-only / digital-only / virtual-only cart returns
     *     `false` from `Cart::needs_shipping()` and that MUST flow
     *     through to PayPal so the popup does not ask for a shipping
     *     method. Without this rule, the merchant sells a $25 gift card
     *     and the buyer is forced through PayPal's address sheet.
     *
     *  3. Empty cart / no cart context — fall back to the
     *     `tejcart_store_has_shippable_products` filter (default `true`).
     *     PDP / category-page express buttons fire before anything is
     *     in the cart; we still want Apple Pay / Google Pay wallet
     *     sheets to wire shipping callbacks when the store sells
     *     physical SKUs.
     */
    public static function resolve_script_needs_shipping(): bool {
        if ( 'yes' !== get_option( 'tejcart_enable_shipping', 'no' ) ) {
            return false;
        }

        if ( function_exists( 'tejcart_get_cart' ) ) {
            $cart = tejcart_get_cart();
            if ( $cart ) {
                $cart_has_items = method_exists( $cart, 'is_empty' )
                    ? ! $cart->is_empty()
                    : ( method_exists( $cart, 'get_items' ) && ! empty( $cart->get_items() ) );
                if ( $cart_has_items && method_exists( $cart, 'needs_shipping' ) ) {
                    return (bool) $cart->needs_shipping();
                }
            }
        }

        return (bool) apply_filters( 'tejcart_store_has_shippable_products', true );
    }

    /**
     * Build the Google Pay style + per-page placement params for the JS layer.
     *
     * The settings live on the Google Pay gateway record so the merchant can
     * tune the wallet without touching the parent PayPal gateway. Falls back
     * to the parent PayPal placement toggles when the Google Pay gateway has
     * not been saved yet (fresh install / pre-onboarding state) so existing
     * deployments do not silently lose buttons.
     *
     * @return array
     */
    private function get_google_pay_style(): array {
        $settings = get_option( 'tejcart_gateway_tejcart_googlepay', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $allowed_colors = array( 'default', 'black', 'white' );
        $color          = isset( $settings['button_color'] ) ? (string) $settings['button_color'] : 'black';
        if ( ! in_array( $color, $allowed_colors, true ) ) {
            $color = 'black';
        }

        $allowed_types = array( 'book', 'buy', 'checkout', 'donate', 'order', 'pay', 'plain', 'subscribe' );
        $type          = isset( $settings['button_type'] ) ? (string) $settings['button_type'] : 'buy';
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'buy';
        }

        $allowed_size_modes = array( 'fill', 'static' );
        $size_mode          = isset( $settings['button_size_mode'] ) ? (string) $settings['button_size_mode'] : 'fill';
        if ( ! in_array( $size_mode, $allowed_size_modes, true ) ) {
            $size_mode = 'fill';
        }

        $radius = isset( $settings['button_radius'] ) && '' !== $settings['button_radius']
            ? (int) $settings['button_radius']
            : 6;
        if ( $radius < 0 || $radius > 100 ) {
            $radius = 6;
        }

        $locale = isset( $settings['button_locale'] ) ? (string) $settings['button_locale'] : '';
        $locale = trim( $locale );
        if ( strlen( $locale ) > 35 ) {
            $locale = substr( $locale, 0, 35 );
        }

        $placement = function ( string $key, string $default = 'yes' ) use ( $settings ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                return ( 'yes' === $default );
            }
            return ( 'yes' === (string) $settings[ $key ] );
        };

        return array(
            'color'                    => $color,
            'type'                     => $type,
            'size_mode'                => $size_mode,
            'radius'                   => $radius,
            'locale'                   => $locale,
            'button_product_page'      => $placement( 'button_product_page' ),
            'button_cart_page'         => $placement( 'button_cart_page' ),
            'button_express_checkout'  => $placement( 'button_express_checkout' ),
            'button_side_cart'         => $placement( 'button_side_cart' ),
            'button_checkout'          => $placement( 'button_checkout' ),
        );
    }

    /**
     * Build the Apple Pay style + required-field params for the JS layer.
     *
     * @return array
     */
    private function get_apple_pay_style(): array {
        $settings = get_option( 'tejcart_gateway_tejcart_applepay', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $billing_required = array();
        if ( ( $settings['billing_required'] ?? 'yes' ) === 'yes' ) {
            $billing_required[] = 'postalAddress';
        }
        if ( ( $settings['email_required'] ?? 'yes' ) === 'yes' ) {
            $billing_required[] = 'email';
        }
        if ( ( $settings['phone_required'] ?? 'no' ) === 'yes' ) {
            $billing_required[] = 'phone';
        }

        $shipping_required = array();
        if ( ( $settings['shipping_required'] ?? 'no' ) === 'yes' ) {
            $shipping_required[] = 'postalAddress';
        }

        $height = (int) ( $settings['button_height'] ?? 44 );
        if ( $height < 30 || $height > 64 ) {
            $height = 44;
        }
        $radius = (int) ( $settings['button_radius'] ?? 4 );
        if ( $radius < 0 || $radius > 50 ) {
            $radius = 4;
        }

        return array(
            'style'                   => (string) ( $settings['button_style'] ?? 'black' ),
            'type'                    => (string) ( $settings['button_type'] ?? 'plain' ),
            'locale'                  => (string) ( $settings['button_language'] ?? '' ),
            'height'                  => $height,
            'radius'                  => $radius,
            'requiredBillingFields'   => $billing_required,
            'requiredShippingFields'  => $shipping_required,
        );
    }

    /**
     * Check if the gateway is in sandbox mode.
     *
     * @return bool
     */
    public function is_sandbox(): bool {
        return ( $this->get_option( 'sandbox_mode', 'yes' ) === 'yes' );
    }

    /**
     * Get the active client ID based on the current mode.
     *
     * @return string
     */
    public function get_client_id(): string {
        if ( $this->is_sandbox() ) {
            return $this->get_option( 'sandbox_client_id', '' );
        }

        return $this->get_option( 'client_id', '' );
    }

    /**
     * Get the active merchant / payer ID based on the current mode.
     *
     * PayPal_Onboarding::persist_credentials() writes the sandbox
     * payer_id to `sandbox_merchant_id` and the live payer_id to
     * `merchant_id`, mirroring the sandbox/live split for client
     * credentials. The frontend SDK params handoff and any downstream
     * API calls that need the merchant_id must respect that split —
     * hitting `merchant_id` directly in sandbox would always return
     * empty.
     *
     * @return string
     */
    public function get_merchant_id(): string {
        if ( $this->is_sandbox() ) {
            return (string) $this->get_option( 'sandbox_merchant_id', '' );
        }

        return (string) $this->get_option( 'merchant_id', '' );
    }

    /**
     * Get the active client secret based on the current mode.
     *
     * Decrypts the stored value via Crypto::decrypt(). Pre-1.0.1 plaintext
     * rows pass through unchanged (Crypto::decrypt is a no-op for inputs
     * that don't carry the ciphertext prefix), so existing installs
     * continue to work; the next save will re-write the value as
     * AES-256-GCM ciphertext via the M-3 save-side hook.
     *
     * @return string
     */
    public function get_client_secret(): string {
        $opt_key = $this->is_sandbox() ? 'sandbox_client_secret' : 'client_secret';
        $stored  = (string) $this->get_option( $opt_key, '' );
        if ( '' === $stored ) {
            return '';
        }
        return \TejCart\Security\Crypto::decrypt( $stored );
    }

    /**
     * Get the PayPal API instance.
     *
     * @return PayPal_API
     */
    public function get_api(): PayPal_API {
        return $this->api;
    }

    /**
     * Settings keys that hold sensitive credentials and must be encrypted
     * at rest. M-3 (see review): a DB-leak of `client_secret` is a direct
     * pivot to merchant impersonation at PayPal — refund routing, capture
     * authority, dispute history. The webhook ID and merchant ID are
     * non-secret PayPal-side identifiers and stay plaintext.
     */
    private const SECRET_OPTION_KEYS = array(
        'client_secret',
        'sandbox_client_secret',
    );

    /**
     * Persist gateway settings, encrypting credential fields at rest with
     * AES-256-GCM via the shared Crypto helper.
     *
     * Lazy migration: any existing plaintext value is encrypted on the
     * next save, and `Crypto::is_encrypted()` is checked so re-saves do
     * not double-wrap. On a host without openssl the call fails closed
     * via `Crypto::encrypt_required`, mirroring the M-2 webhook /
     * tax-provider posture.
     *
     * @return bool True if the option was persisted, false on crypto
     *              failure (the previous in-memory settings remain).
     */
    public function save_settings(): bool {
        foreach ( self::SECRET_OPTION_KEYS as $secret_key ) {
            if ( ! isset( $this->settings[ $secret_key ] ) ) {
                continue;
            }
            $value = (string) $this->settings[ $secret_key ];
            if ( '' === $value || \TejCart\Security\Crypto::is_encrypted( $value ) ) {
                continue;
            }
            try {
                $this->settings[ $secret_key ] = \TejCart\Security\Crypto::encrypt_required( $value );
            } catch ( \TejCart\Security\Crypto_Exception $e ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf(
                            'PayPal_Gateway::save_settings refusing to persist "%s" as plaintext (%s).',
                            $secret_key,
                            $e->getMessage()
                        ),
                        'error'
                    );
                }
                return false;
            }
        }

        return parent::save_settings();
    }

    /**
     * True when the current request is the configured order-received /
     * thank-you page. PayPal partner policy forbids Pay Later messaging on
     * this page; H-5 suppresses the SDK flag here.
     */
    public static function is_order_received_page(): bool {
        if ( ! function_exists( 'is_page' ) ) {
            return false;
        }
        $thanks = (int) get_option( 'tejcart_thankyou_page_id', 0 );
        if ( $thanks <= 0 ) {
            return false;
        }
        return is_page( $thanks );
    }
}
