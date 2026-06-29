<?php
/**
 * Saved Payment Methods - PayPal vaulting support.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages saved payment methods (tokens) for customers.
 *
 * Stores references to vaulted PayPal accounts in user meta,
 * allowing customers to reuse saved payment methods at checkout.
 */
class Payment_Methods {
    /**
     * User meta key for storing saved payment methods.
     *
     * @var string
     */
    const META_KEY = 'tejcart_saved_payment_methods';

    /**
     * The single instance of this class.
     *
     * @var Payment_Methods|null
     */
    private static $instance = null;

    /**
     * Returns the single instance of this class.
     *
     * @return Payment_Methods
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the process-wide singleton. Test-substitution seam (#1242).
     *
     * Pass null to reset to lazy construction on the next `instance()`
     * call. Tests / DI overrides can hand in a fake to exercise call
     * sites that resolve through `Payment_Methods::instance()`.
     *
     * @internal Use in tests and DI overrides only.
     * @param Payment_Methods|null $instance Instance to install, or null to clear.
     */
    public static function set_instance( ?Payment_Methods $instance ): void {
        self::$instance = $instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Initialize hooks for payment methods management.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_ajax_tejcart_delete_payment_method', array( $this, 'ajax_delete_method' ) );
        add_action( 'wp_ajax_tejcart_set_default_payment_method', array( $this, 'ajax_set_default_method' ) );

        add_filter( 'tejcart_account_tabs', array( $this, 'add_account_tab' ) );
        add_filter( 'tejcart_is_valid_account_tab', array( $this, 'validate_tab' ), 10, 2 );
        add_action( 'tejcart_account_tab_content', array( $this, 'render_tab_content' ) );

        add_action( 'wp', array( $this, 'maybe_handle_vault_request' ) );
    }

    /**
     * Add Payment Methods tab to the account navigation.
     *
     * @param array $tabs Existing account tabs.
     * @return array Modified tabs.
     */
    public function add_account_tab( array $tabs ): array {
        $new_tabs = array();
        foreach ( $tabs as $slug => $label ) {
            if ( 'account-details' === $slug ) {
                $new_tabs['payment-methods'] = __( 'Payment Methods', 'tejcart' );
            }
            $new_tabs[ $slug ] = $label;
        }

        if ( ! isset( $new_tabs['payment-methods'] ) ) {
            $new_tabs['payment-methods'] = __( 'Payment Methods', 'tejcart' );
        }

        return $new_tabs;
    }

    /**
     * Validate the payment-methods tab slug.
     *
     * @param bool   $is_valid    Whether the tab is valid.
     * @param string $current_tab The tab slug being checked.
     * @return bool
     */
    public function validate_tab( bool $is_valid, string $current_tab ): bool {
        if ( 'payment-methods' === $current_tab ) {
            return true;
        }
        return $is_valid;
    }

    /**
     * Render the payment methods tab content.
     *
     * @param string $current_tab The active tab slug.
     * @return void
     */
    public function render_tab_content( string $current_tab ): void {
        if ( 'payment-methods' !== $current_tab ) {
            return;
        }

        $customer_id  = get_current_user_id();
        $methods      = $this->get_saved_methods( $customer_id );
        $notice       = $this->consume_vault_notice( $customer_id );
        $can_add      = class_exists( '\\TejCart\\Gateways\\PayPal\\PayPal_Gateway' )
            && \TejCart\Gateways\PayPal\PayPal_Gateway::is_onboarded();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( null === $notice && isset( $_GET['vault_status'] ) && 'cancelled' === $_GET['vault_status'] ) {
            $notice = array(
                'type'    => 'error',
                'message' => __( 'You cancelled the payment method setup.', 'tejcart' ),
            );
        }

        if ( ! empty( $methods ) ) {
            $this->enqueue_account_script();
        }

        tejcart_get_template( 'account/payment-methods.php', array(
            'customer_id'     => $customer_id,
            'methods'         => $methods,
            'vault_notice'    => $notice,
            'can_add_method'  => $can_add,
            'add_method_url'  => $this->build_vault_url( 'start' ),
            'add_nonce'       => wp_create_nonce( 'tejcart_vault_start' ),
        ) );
    }

    /**
     * Enqueue the account-page JS that powers the saved-payment-method
     * actions (delete / set default). Registered once per request.
     *
     * @return void
     */
    private function enqueue_account_script(): void {
        if ( wp_script_is( 'tejcart-account', 'enqueued' ) ) {
            return;
        }

        $version    = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_enqueue_script(
            'tejcart-account',
            tejcart_asset_url( 'assets/js/tejcart-account.js' ),
            array(),
            $version,
            true
        );

        wp_localize_script(
            'tejcart-account',
            'tejcart_account_params',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'i18n'     => array(
                    'confirm_delete' => __( 'Are you sure you want to remove this payment method?', 'tejcart' ),
                    'delete_error'   => __( 'Error removing payment method.', 'tejcart' ),
                    'default_error'  => __( 'Error updating default method.', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Get all saved payment methods for a customer.
     *
     * @param int $customer_id WordPress user ID.
     * @return array Array of saved payment method data.
     */
    public function get_saved_methods( int $customer_id ): array {
        $methods = get_user_meta( $customer_id, self::META_KEY, true );
        return is_array( $methods ) ? $methods : array();
    }

    /**
     * Return the decrypted PayPal vault token ID for a saved method record.
     * Accepts both legacy plaintext and TejCart-encrypted values.
     *
     * @param array $method Saved method record.
     * @return string
     */
    public static function get_token_id( array $method ): string {
        $stored = (string) ( $method['token_id'] ?? '' );
        return '' === $stored ? '' : Crypto::decrypt( $stored );
    }

    /**
     * Save a new payment method for a customer.
     *
     * @param int   $customer_id WordPress user ID.
     * @param array $data        Payment method data. Expected keys:
     *                           - token_id (string): Vault token from PayPal.
     *                           - type (string): Payment type (paypal, card, venmo).
     *                           - label (string): Display label (e.g. email or last 4 digits).
     *                           - is_default (bool): Whether this is the default method.
     * @return array The saved method data including generated ID.
     */
    public function save_method( int $customer_id, array $data ): array {
        $methods = $this->get_saved_methods( $customer_id );

        $raw_token = sanitize_text_field( $data['token_id'] ?? '' );

        // Crypto::encrypt_required() throws on runtime failure AND on
        // hosts that don't have the openssl extension at all (review
        // finding H-5). Refuse to persist the vault token in either
        // case — there is no meaningful recovery from "the host's
        // OpenSSL just stopped working" and storing plaintext would
        // silently violate PCI-style controls.
        $encrypted_token = '';
        if ( '' !== $raw_token ) {
            try {
                $encrypted_token = Crypto::encrypt_required( $raw_token );
            } catch ( \TejCart\Security\Crypto_Exception $e ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        'Payment_Methods::save_method aborting: vault token encryption failed (' . $e->getMessage() . ').',
                        'error'
                    );
                }
                return array();
            }
        }

        $method = array(
            'id'         => wp_generate_uuid4(),
            'token_id'   => $encrypted_token,
            'token_hash' => '' !== $raw_token ? Crypto::hash( $raw_token ) : '',
            'type'       => sanitize_text_field( $data['type'] ?? 'paypal' ),
            'label'      => sanitize_text_field( $data['label'] ?? '' ),
            'is_default' => ! empty( $data['is_default'] ),
            'created_at' => current_time( 'mysql' ),
        );

        if ( $method['is_default'] ) {
            foreach ( $methods as &$existing ) {
                $existing['is_default'] = false;
            }
            unset( $existing );
        }

        if ( empty( $methods ) ) {
            $method['is_default'] = true;
        }

        $methods[] = $method;
        update_user_meta( $customer_id, self::META_KEY, $methods );

        // Index hook for the PayPal VAULT.PAYMENT-TOKEN.DELETED
        // webhook: maintain a one-row-per-token user_meta entry
        // keyed by `_tejcart_pp_token_<sha>` so the webhook can
        // resolve a token back to a user via an indexed
        // meta_key lookup instead of a leading-wildcard LIKE scan
        // of every meta_value on the site. Both the hash and the
        // (now-encrypted) raw token id are recorded so the webhook
        // can match either shape PayPal emits.
        if ( '' !== $method['token_hash'] ) {
            update_user_meta(
                $customer_id,
                '_tejcart_pp_token_' . $method['token_hash'],
                $method['id']
            );
        }
        if ( '' !== $raw_token ) {
            // Same index, keyed by Crypto::hash of the raw token_id,
            // so the webhook's two-LIKE pattern (token_hash OR
            // token_id) collapses to two PRIMARY-KEY lookups on
            // meta_key.
            update_user_meta(
                $customer_id,
                '_tejcart_pp_token_' . Crypto::hash( $raw_token ),
                $method['id']
            );
        }

        return $method;
    }

    /**
     * Delete a saved payment method.
     *
     * @param int    $customer_id WordPress user ID.
     * @param string $token_id    The payment method UUID (internal id, not PayPal token).
     * @return bool True if deleted, false if not found.
     */
    public function delete_method( int $customer_id, string $token_id ): bool {
        $methods  = $this->get_saved_methods( $customer_id );
        $filtered = array();
        $found    = false;
        $was_default = false;

        foreach ( $methods as $method ) {
            if ( $method['id'] === $token_id ) {
                $found       = true;
                $was_default = ! empty( $method['is_default'] );

                $plain_token = self::get_token_id( $method );
                if ( '' !== $plain_token && class_exists( '\\TejCart\\Gateways\\PayPal\\PayPal_Gateway' ) ) {
                    $api    = \TejCart\Gateways\PayPal\PayPal_Gateway::get_shared_api();
                    $result = $api->delete_payment_token( $plain_token );
                    if ( is_wp_error( $result ) && function_exists( 'tejcart_log' ) ) {
                        tejcart_log( 'PayPal vault revoke failed: ' . $result->get_error_message(), 'warning' );
                    }
                }

                // Drop the parallel index rows so the user_meta
                // lookup in PayPal_Webhook::handle_vault_event sees
                // a clean state. We delete on both possible keys —
                // the stored token_hash and (if available) the
                // hash of the raw token_id — so the index doesn't
                // leak rows across rotated tokens.
                if ( ! empty( $method['token_hash'] ) ) {
                    delete_user_meta( $customer_id, '_tejcart_pp_token_' . $method['token_hash'] );
                }
                if ( '' !== $plain_token ) {
                    delete_user_meta( $customer_id, '_tejcart_pp_token_' . Crypto::hash( $plain_token ) );
                }

                continue;
            }
            $filtered[] = $method;
        }

        if ( ! $found ) {
            return false;
        }

        if ( $was_default && ! empty( $filtered ) ) {
            $filtered[0]['is_default'] = true;
        }

        update_user_meta( $customer_id, self::META_KEY, $filtered );
        return true;
    }

    /**
     * Get the customer's default payment method.
     *
     * @param int $customer_id WordPress user ID.
     * @return array|null Default method data or null if none saved.
     */
    public function get_default_method( int $customer_id ): ?array {
        $methods = $this->get_saved_methods( $customer_id );

        foreach ( $methods as $method ) {
            if ( ! empty( $method['is_default'] ) ) {
                return $method;
            }
        }

        return ! empty( $methods ) ? $methods[0] : null;
    }

    /**
     * Set a payment method as the default.
     *
     * @param int    $customer_id WordPress user ID.
     * @param string $method_id   The payment method UUID.
     * @return bool True if updated, false if not found.
     */
    public function set_default_method( int $customer_id, string $method_id ): bool {
        $methods = $this->get_saved_methods( $customer_id );
        $found   = false;

        foreach ( $methods as &$method ) {
            if ( $method['id'] === $method_id ) {
                $method['is_default'] = true;
                $found = true;
            } else {
                $method['is_default'] = false;
            }
        }
        unset( $method );

        if ( ! $found ) {
            return false;
        }

        update_user_meta( $customer_id, self::META_KEY, $methods );
        return true;
    }

    /**
     * Front controller for the standalone vault flow. Dispatches based on
     * the `tejcart_vault_action` query var. Both legs require a logged-in
     * customer; everything else is rejected silently and the user is sent
     * back to the My Account → Payment Methods tab with an error notice.
     */
    public function maybe_handle_vault_request(): void {
        if ( is_admin() || ! is_user_logged_in() ) {
            return;
        }

        // Read-only dispatch; downstream handlers verify their own nonces.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $action = isset( $_REQUEST['tejcart_vault_action'] )
            ? sanitize_key( wp_unslash( $_REQUEST['tejcart_vault_action'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( '' === $action ) {
            return;
        }

        if ( 'start' === $action ) {
            $this->start_vault_flow();
        } elseif ( 'return' === $action ) {
            $this->handle_vault_return();
        }
    }

    /**
     * Leg 1: create a setup token at PayPal and redirect the customer to
     * the PayPal approval URL. Triggered by the "Add payment method" form
     * on the My Account → Payment Methods tab.
     */
    private function start_vault_flow(): void {
        $nonce = isset( $_POST['tejcart_vault_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_vault_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_vault_start' ) ) {
            $this->redirect_to_payment_methods( __( 'Security check failed. Please try again.', 'tejcart' ), 'error' );
        }

        if ( ! class_exists( '\\TejCart\\Gateways\\PayPal\\PayPal_Gateway' ) ) {
            $this->redirect_to_payment_methods( __( 'PayPal is not available on this site.', 'tejcart' ), 'error' );
        }

        if ( ! \TejCart\Gateways\PayPal\PayPal_Gateway::is_onboarded() ) {
            $this->redirect_to_payment_methods( __( 'PayPal is not connected on this site yet.', 'tejcart' ), 'error' );
        }

        $source = isset( $_POST['vault_source'] )
            ? sanitize_key( wp_unslash( $_POST['vault_source'] ) )
            : 'paypal';
        // PayPal's v3 "Vault Without Purchase" redirect flow only supports
        // paypal and venmo as payment_source. card requires inline hosted
        // fields collected during checkout — sending an "empty" card setup
        // token gets rejected by the API ("Request is not well-formed").
        if ( ! in_array( $source, array( 'paypal', 'venmo' ), true ) ) {
            if ( 'card' === $source ) {
                $this->redirect_to_payment_methods(
                    __( 'Credit and debit cards are saved during checkout, not from this page. Add an item to your cart, then choose to save the card when paying.', 'tejcart' ),
                    'info'
                );
            }
            $source = 'paypal';
        }

        $api = \TejCart\Gateways\PayPal\PayPal_Gateway::get_shared_api();

        $return_url = $this->build_vault_url( 'return', array( 'source' => $source ) );
        $cancel_url = $this->build_vault_url( '', array( 'vault_status' => 'cancelled' ) );

        // PayPal's v3 vault setup-tokens endpoint rejects non-HTTPS
        // return/cancel URLs for Venmo with a generic "INVALID_PARAMETER"
        // error that surfaces to the buyer as the unhelpful "Could not
        // start vault flow: Request is not well-formed…". Trap the
        // HTTP-only case early and give the merchant an actionable
        // message instead of bouncing the request off PayPal first.
        if ( self::vault_source_requires_https( $source, $return_url ) ) {
            $this->redirect_to_payment_methods(
                __( 'Venmo can only be saved from a site served over HTTPS. Enable HTTPS for this site and try again, or save your Venmo account at checkout instead.', 'tejcart' ),
                'error'
            );
        }

        $brand_name = (string) get_bloginfo( 'name' );

        $result = $api->create_setup_token(
            $source,
            array(),
            array(
                'return_url'  => $return_url,
                'cancel_url'  => $cancel_url,
                'brand_name'  => $brand_name,
                'locale'      => self::normalize_locale_for_paypal( get_locale() ),
            )
        );

        if ( is_wp_error( $result ) ) {
            $this->redirect_to_payment_methods(
                sprintf(
                    /* translators: %s: PayPal error message */
                    __( 'Could not start vault flow: %s', 'tejcart' ),
                    $result->get_error_message()
                ),
                'error'
            );
        }

        $approve_url = '';
        foreach ( (array) ( $result['links'] ?? array() ) as $link ) {
            if ( isset( $link['rel'], $link['href'] ) && 'approve' === $link['rel'] ) {
                $approve_url = (string) $link['href'];
                break;
            }
        }

        if ( '' === $approve_url ) {
            $this->redirect_to_payment_methods(
                __( 'PayPal did not return an approval URL. Please try again.', 'tejcart' ),
                'error'
            );
        }

        $approve_host = wp_parse_url( $approve_url, PHP_URL_HOST );
        if ( ! is_string( $approve_host ) || ! self::is_paypal_redirect_host( $approve_host ) ) {
            $this->redirect_to_payment_methods(
                __( 'PayPal returned an unexpected approval URL. Please try again.', 'tejcart' ),
                'error'
            );
        }

        $token_id = (string) ( $result['id'] ?? '' );
        if ( '' !== $token_id ) {
            // Audit #85 / 08 #13 — sweep any vault session keys this
            // user left behind on abandoned flows (the original
            // transient TTL is 15 minutes but the buyer may have
            // started several attempts in quick succession). Caps the
            // per-user transient inode count to one in flight at a
            // time.
            $user_id = (int) get_current_user_id();
            $this->sweep_user_vault_sessions( $user_id );

            $session_key = $this->vault_session_key( $user_id, $token_id );
            set_transient(
                $session_key,
                array( 'source' => $source, 'created' => time() ),
                15 * MINUTE_IN_SECONDS
            );
            $this->track_pending_vault_session( $user_id, $session_key );
        }

        add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_paypal_redirect_hosts' ) );
        wp_safe_redirect( $approve_url );
        exit;
    }

    /**
     * PayPal-controlled hosts that the vault approval flow may redirect to.
     *
     * @return string[]
     */
    private static function paypal_redirect_hosts(): array {
        return array( 'www.paypal.com', 'www.sandbox.paypal.com' );
    }

    /**
     * Whitelist PayPal-controlled hosts for the vault approval redirect so
     * wp_safe_redirect() will follow through to PayPal instead of bouncing to
     * the home URL. Hooked into 'allowed_redirect_hosts' just before redirecting.
     *
     * @param string[] $hosts
     * @return string[]
     */
    public static function allow_paypal_redirect_hosts( $hosts ) {
        $hosts = is_array( $hosts ) ? $hosts : array();
        return array_values( array_unique( array_merge( $hosts, self::paypal_redirect_hosts() ) ) );
    }

    /**
     * Validate that a host returned in a PayPal API response actually belongs
     * to a PayPal-owned domain before redirecting the user to it.
     */
    private static function is_paypal_redirect_host( string $host ): bool {
        return in_array( strtolower( $host ), self::paypal_redirect_hosts(), true );
    }

    /**
     * Whether the given vault `payment_source` requires the return URL to
     * be HTTPS. PayPal's v3 setup-tokens endpoint rejects HTTP return /
     * cancel URLs for Venmo with a generic `INVALID_PARAMETER` error in
     * every environment (sandbox and live). PayPal (non-Venmo) is more
     * forgiving in sandbox and is left alone here.
     *
     * Returns true when the caller should bail with an HTTPS-required
     * message before sending the setup-token request.
     *
     * @param string $source     `payment_source` key (`paypal`, `venmo`, …).
     * @param string $return_url Return URL that will be sent to PayPal.
     * @return bool
     */
    private static function vault_source_requires_https( string $source, string $return_url ): bool {
        if ( 'venmo' !== $source ) {
            return false;
        }
        $scheme = wp_parse_url( $return_url, PHP_URL_SCHEME );
        return 'https' !== strtolower( (string) $scheme );
    }

    /**
     * Normalize a WordPress locale (gettext form, e.g. `en_US`) to the BCP-47
     * form PayPal's v3 vault setup-token endpoint accepts (e.g. `en-US`).
     * PayPal rejects the underscore form with INVALID_PARAMETER_SYNTAX. Any
     * locale that doesn't match `xx-XX` / `xxx-XX` falls back to `en-US`
     * rather than risking another 400 from a region-only or variant tag.
     *
     * @param string $wp_locale Result of get_locale().
     * @return string BCP-47 locale guaranteed to satisfy PayPal's schema.
     */
    private static function normalize_locale_for_paypal( string $wp_locale ): string {
        $candidate = str_replace( '_', '-', $wp_locale );
        if ( preg_match( '/^[a-z]{2,3}-[A-Z]{2}$/', $candidate ) ) {
            return $candidate;
        }
        return 'en-US';
    }

    /**
     * Leg 2: PayPal redirected the customer back here after they approved
     * the vault setup token. Verify the token, exchange it for a permanent
     * vault payment token, and persist it on the customer's record.
     */
    private function handle_vault_return(): void {
        // Token id is the bearer of authenticity, see set_transient guard below.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $setup_token = isset( $_GET['approval_token_id'] )
            ? sanitize_text_field( wp_unslash( $_GET['approval_token_id'] ) )
            : '';
        if ( '' === $setup_token ) {
            $setup_token = isset( $_GET['token'] )
                ? sanitize_text_field( wp_unslash( $_GET['token'] ) )
                : '';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( '' === $setup_token ) {
            $this->redirect_to_payment_methods(
                __( 'PayPal did not return a setup token. Please try again.', 'tejcart' ),
                'error'
            );
        }

        $user_id     = (int) get_current_user_id();
        $session_key = $this->vault_session_key( $user_id, $setup_token );
        $session     = get_transient( $session_key );
        if ( ! is_array( $session ) ) {
            $this->redirect_to_payment_methods(
                __( 'This vault session has expired. Please start again.', 'tejcart' ),
                'error'
            );
        }
        delete_transient( $session_key );
        $this->untrack_pending_vault_session( $user_id, $session_key );

        if ( ! class_exists( '\\TejCart\\Gateways\\PayPal\\PayPal_Gateway' ) ) {
            $this->redirect_to_payment_methods( __( 'PayPal is not available on this site.', 'tejcart' ), 'error' );
        }

        $api = \TejCart\Gateways\PayPal\PayPal_Gateway::get_shared_api();

        $exchanged = $api->create_payment_token( $setup_token );
        if ( is_wp_error( $exchanged ) ) {
            $this->redirect_to_payment_methods(
                sprintf(
                    /* translators: %s: PayPal error message */
                    __( 'Could not save your payment method: %s', 'tejcart' ),
                    $exchanged->get_error_message()
                ),
                'error'
            );
        }

        $token_id = sanitize_text_field( $exchanged['id'] ?? '' );
        if ( '' === $token_id ) {
            $this->redirect_to_payment_methods(
                __( 'PayPal did not return a vault token. Please try again.', 'tejcart' ),
                'error'
            );
        }

        [ $type, $label ] = $this->derive_label_from_payment_source( $exchanged['payment_source'] ?? array() );

        $this->save_method(
            get_current_user_id(),
            array(
                'token_id' => $token_id,
                'type'     => $type ?: ( $session['source'] ?? 'paypal' ),
                'label'    => $label,
            )
        );

        $this->redirect_to_payment_methods(
            __( 'Payment method saved.', 'tejcart' ),
            'success'
        );
    }

    /**
     * Build a label and type tuple from a PayPal vault payment_source body.
     * Mirrors the helper used by PayPal_AJAX::save_payment_token() so the
     * standalone flow stores methods in the same shape as the checkout flow.
     *
     * @param array $payment_source PayPal `payment_source` body.
     * @return array{0:string,1:string} [ type, label ]
     */
    private function derive_label_from_payment_source( array $payment_source ): array {
        if ( ! empty( $payment_source['paypal']['email_address'] ) ) {
            return array( 'paypal', sanitize_email( $payment_source['paypal']['email_address'] ) );
        }
        if ( ! empty( $payment_source['card']['last_digits'] ) ) {
            $brand = $payment_source['card']['brand'] ?? 'Card';
            return array( 'card', sprintf( '%s •••• %s', $brand, $payment_source['card']['last_digits'] ) );
        }
        if ( ! empty( $payment_source['venmo']['email_address'] ) ) {
            return array( 'venmo', sanitize_email( $payment_source['venmo']['email_address'] ) );
        }
        return array( 'paypal', '' );
    }

    /**
     * Build a fully-qualified URL on the My Account page for one of the
     * vault flow legs (start / return / cancelled).
     *
     * @param string $action      Vault action: 'start', 'return', or '' for plain.
     * @param array  $extra_args  Extra query args to merge in.
     * @return string
     */
    private function build_vault_url( string $action, array $extra_args = array() ): string {
        $page_id = (int) get_option( 'tejcart_myaccount_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : home_url( '/my-account/' );

        $args = array( 'tab' => 'payment-methods' );
        if ( '' !== $action ) {
            $args['tejcart_vault_action'] = $action;
        }
        foreach ( $extra_args as $key => $value ) {
            $args[ $key ] = $value;
        }

        return add_query_arg( $args, $base );
    }

    /**
     * Persist a one-shot notice in user meta and redirect back to the My
     * Account → Payment Methods tab. The template reads (and clears) the
     * notice on next render.
     *
     * @param string $message Human-readable notice.
     * @param string $type    'success' | 'error'.
     */
    private function redirect_to_payment_methods( string $message, string $type = 'success' ): void {
        update_user_meta(
            get_current_user_id(),
            'tejcart_vault_notice',
            array( 'type' => $type, 'message' => $message, 'time' => time() )
        );
        wp_safe_redirect( $this->build_vault_url( '' ) );
        exit;
    }

    /**
     * Build the transient key used to bind a setup token to the current
     * user, so a malicious actor cannot redirect a victim to a return URL
     * carrying a setup token they didn't initiate.
     *
     * @param int    $user_id        Current user ID.
     * @param string $setup_token_id Setup token returned by PayPal.
     * @return string
     */
    private function vault_session_key( int $user_id, string $setup_token_id ): string {
        return 'tejcart_vault_session_' . md5( $user_id . '|' . $setup_token_id );
    }

    /**
     * User-meta key tracking the in-flight vault session transient
     * keys for a given user. Keeps the per-user transient inode count
     * bounded across abandoned flows — without this we relied
     * exclusively on the 15-minute TTL to free wp_options rows.
     */
    private const PENDING_VAULT_META_KEY = '_tejcart_pending_vault_sessions';

    /**
     * Maximum tracked in-flight sessions per user. Higher than 1 so
     * concurrent vault attempts in two tabs don't blow each other
     * away on the second attempt, but bounded so a misbehaving client
     * can't pump unbounded entries through this list.
     */
    private const PENDING_VAULT_LIMIT = 3;

    /**
     * Record a new in-flight vault session key against the user so a
     * later sweep_user_vault_sessions() can find and delete it on
     * the next vault attempt (audit #85 / 08 #13).
     */
    private function track_pending_vault_session( int $user_id, string $session_key ): void {
        if ( $user_id <= 0 || '' === $session_key ) {
            return;
        }
        $pending   = (array) get_user_meta( $user_id, self::PENDING_VAULT_META_KEY, true );
        $pending   = array_values( array_filter( array_map( 'strval', $pending ) ) );
        $pending[] = $session_key;
        // Dedupe AFTER the append so an already-tracked key doesn't double-up,
        // while still moving it to the most-recent slot (array_unique keeps
        // the first occurrence, so re-walk from the right to preserve recency).
        $pending = array_values( array_unique( $pending ) );

        if ( count( $pending ) > self::PENDING_VAULT_LIMIT ) {
            $pending = array_slice( $pending, -self::PENDING_VAULT_LIMIT );
        }

        update_user_meta( $user_id, self::PENDING_VAULT_META_KEY, $pending );
    }

    /**
     * Remove a single completed session key from the pending index
     * (called from the successful-return path after the transient is
     * deleted, so the index doesn't accumulate stale entries).
     */
    private function untrack_pending_vault_session( int $user_id, string $session_key ): void {
        if ( $user_id <= 0 || '' === $session_key ) {
            return;
        }
        $pending = (array) get_user_meta( $user_id, self::PENDING_VAULT_META_KEY, true );
        $pending = array_values( array_filter(
            $pending,
            static function ( $key ) use ( $session_key ) { return $key !== $session_key; }
        ) );
        if ( empty( $pending ) ) {
            delete_user_meta( $user_id, self::PENDING_VAULT_META_KEY );
        } else {
            update_user_meta( $user_id, self::PENDING_VAULT_META_KEY, $pending );
        }
    }

    /**
     * Delete every tracked vault-session transient for this user (and
     * clear the index). Called at the start of a new vault attempt so
     * abandoned flows from the same user are reclaimed proactively
     * rather than waiting for the 15-minute TTL.
     */
    private function sweep_user_vault_sessions( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        $pending = (array) get_user_meta( $user_id, self::PENDING_VAULT_META_KEY, true );
        foreach ( $pending as $session_key ) {
            if ( is_string( $session_key ) && '' !== $session_key ) {
                delete_transient( $session_key );
            }
        }
        delete_user_meta( $user_id, self::PENDING_VAULT_META_KEY );
    }

    /**
     * Read and clear the one-shot vault flow notice for the current user.
     * Returns null when there's nothing to surface.
     *
     * @param int $user_id Current user ID.
     * @return array|null
     */
    public function consume_vault_notice( int $user_id ): ?array {
        $notice = get_user_meta( $user_id, 'tejcart_vault_notice', true );
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return null;
        }
        delete_user_meta( $user_id, 'tejcart_vault_notice' );
        return $notice;
    }

    /**
     * AJAX handler to delete a saved payment method.
     *
     * @return void
     */
    public function ajax_delete_method(): void {
        check_ajax_referer( 'tejcart_payment_methods', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Please log in.', 'tejcart' ) ) );
        }

        $method_id   = isset( $_POST['method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['method_id'] ) ) : '';
        $customer_id = get_current_user_id();

        if ( ! $method_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid payment method.', 'tejcart' ) ) );
        }

        $deleted = $this->delete_method( $customer_id, $method_id );

        if ( $deleted ) {
            wp_send_json_success( array( 'message' => __( 'Payment method removed.', 'tejcart' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Payment method not found.', 'tejcart' ) ) );
        }
    }

    /**
     * AJAX handler to set a payment method as default.
     *
     * @return void
     */
    public function ajax_set_default_method(): void {
        check_ajax_referer( 'tejcart_payment_methods', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Please log in.', 'tejcart' ) ) );
        }

        $method_id   = isset( $_POST['method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['method_id'] ) ) : '';
        $customer_id = get_current_user_id();

        if ( ! $method_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid payment method.', 'tejcart' ) ) );
        }

        $updated = $this->set_default_method( $customer_id, $method_id );

        if ( $updated ) {
            wp_send_json_success( array( 'message' => __( 'Default payment method updated.', 'tejcart' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Payment method not found.', 'tejcart' ) ) );
        }
    }
}
