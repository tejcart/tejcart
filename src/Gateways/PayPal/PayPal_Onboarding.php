<?php
/**
 * PayPal Seller Onboarding (Partner Referrals)
 *
 * Implements the PayPal "Connect with PayPal" flow via the Partner Referrals
 * API. Tejcart is onboarded as a PayPal Commerce Platform (PPCP) partner, so
 * merchants can grant us permission to charge on their behalf without ever
 * touching a client ID or secret manually.
 *
 * Flow summary:
 *   1. Merchant clicks "Connect with PayPal" on the gateway settings page.
 *   2. JS POSTs to {@see self::ajax_generate_signup_link()}, which calls the
 *      Tejcart partner proxy to create a Partner Referrals signup link with
 *      the requested products / capabilities (PPCP, ADVANCED_VAULTING,
 *      APPLE_PAY, GOOGLE_PAY).
 *   3. The signup link is opened in the PayPal MiniBrowser popup.
 *   4. When the merchant finishes, PayPal sends the parent window a postMessage
 *      containing `sharedId` + `authCode`. JS forwards it to
 *      {@see self::ajax_login_seller()}.
 *   5. We exchange the auth code for an access token, then call the Partner
 *      Referrals credentials endpoint to obtain the merchant's own REST
 *      client_id / client_secret / payer_id.
 *   6. Credentials are persisted into the PayPal gateway settings for the
 *      current environment (sandbox or live).
 *   7. As a fallback, {@see self::maybe_handle_return_url()} captures
 *      `merchantIdInPayPal` when PayPal redirects the seller back to the
 *      settings page directly (i.e. the popup closed before the postMessage
 *      fired).
 *
 * This implements PayPal's recommended Partner Connect onboarding so
 * merchants get a "one click" sandbox-or-live connection experience.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the PayPal Partner Referrals seller onboarding flow.
 */
class PayPal_Onboarding {
    /**
     * Tejcart's sandbox partner merchant ID at PayPal.
     */
    public const SANDBOX_PARTNER_MERCHANT_ID = 'K6QLN2LPGQRHL';

    /**
     * Tejcart's live partner merchant ID at PayPal.
     */
    public const LIVE_PARTNER_MERCHANT_ID = 'GT5R877JNBPLL';

    /**
     * Default partner proxy that wraps the PayPal Partner Referrals API.
     * This endpoint holds the partner BN credentials and returns a signup
     * link for the merchant's PayPal onboarding flow.
     *
     * SECURITY NOTE: this default routes onboarding traffic through
     * tejcart.com — a third-party host that Tejcart does not
     * control. Merchants who require a fully first-party onboarding
     * path should override the URL using the
     * `tejcart_paypal_onboarding_proxy_url` filter, or self-host the
     * referenced PHP shim. The proxy host is logged at info level on
     * every onboarding kickoff so operators have forensic visibility.
     */
    public const DEFAULT_ONBOARDING_PROXY_URL = 'https://tejcart.com/ppcp-seller-onboarding/seller-onboarding.php';

    /**
     * Legacy fixed PKCE code verifier used for the full Partner
     * Referrals handshake. Must match the value the mbjtechnolabs
     * onboarding proxy uses internally when minting the code_challenge
     * it sends to PayPal, otherwise the subsequent auth-code →
     * access-token exchange fails with a PKCE mismatch.
     *
     * M-6: this is retained as a fall-back for the default
     * mbjtechnolabs proxy, which mints the code_challenge from this
     * exact verifier and would reject a per-session value. Operators
     * who self-host a compatible onboarding shim (or coordinate with
     * a proxy that accepts a forwarded code_challenge) should opt
     * into per-session PKCE via the
     * `tejcart_paypal_onboarding_pkce_per_session` filter; the new
     * path generates a fresh verifier per onboarding kickoff and
     * sends only the derived code_challenge to the proxy. See
     * {@see self::generate_pkce_pair()} and the
     * `tejcart_paypal_onboarding_payload` filter.
     */
    private const LEGACY_CODE_VERIFIER = 'a1233wtergfsdt4365tzrshgfbaewa36AGa1233wtergfsdt4365tzrshgfbaewa36AG';

    /**
     * Transient prefix for per-session PKCE verifiers. One entry per
     * (user_id, environment); set with a 30-minute TTL on signup-link
     * generation, consumed by the auth-code exchange.
     */
    private const PKCE_VERIFIER_TRANSIENT_PREFIX = 'tejcart_paypal_pkce_';

    /**
     * Transient key prefix for the pre-generated Partner Referrals signup
     * URLs. One entry per environment.
     */
    private const SIGNUP_LINK_TRANSIENT_PREFIX = 'tejcart_paypal_signup_link_';

    /**
     * PayPal live REST API base URL.
     */
    private const LIVE_API = 'https://api-m.paypal.com';

    /**
     * PayPal sandbox REST API base URL.
     */
    private const SANDBOX_API = 'https://api-m.sandbox.paypal.com';

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get the shared instance.
     */
    public static function instance(): self {
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
     * sites that resolve through `PayPal_Onboarding::instance()`.
     *
     * @internal Use in tests and DI overrides only.
     */
    public static function set_instance( ?self $instance ): void {
        self::$instance = $instance;
    }

    /**
     * Register WordPress hooks. Safe to call multiple times — each hook is
     * only attached once because the AJAX hook names are unique and WP's
     * action system tolerates duplicates on distinct callables.
     */
    public static function register(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        $inst = self::instance();

        add_action( 'wp_ajax_tejcart_paypal_onboarding_signup_link', array( $inst, 'ajax_generate_signup_link' ) );
        add_action( 'wp_ajax_tejcart_paypal_onboarding_login_seller', array( $inst, 'ajax_login_seller' ) );
        add_action( 'wp_ajax_tejcart_paypal_onboarding_disconnect', array( $inst, 'ajax_disconnect' ) );
        add_action( 'wp_ajax_tejcart_paypal_onboarding_status', array( $inst, 'ajax_refresh_status' ) );

        add_action( 'admin_init', array( $inst, 'maybe_handle_return_url' ) );

        // Pin TLS settings on every PayPal HTTP request site-wide
        // (onboarding, checkout token, client token, /v2/checkout, the
        // webhook verify call, etc.) via the canonical WP HTTP filter.
        // This single registration is what fixes "Response could not
        // be parsed" — the WP Requests library mis-translates cURL
        // errno 60 (CA store missing the intermediate that signs
        // PayPal's Fastly-served edge cert) into that misleading
        // message. Pinning sslcertificates at WP's bundled Mozilla CA
        // file resolves it for every call site at once.
        add_filter( 'http_request_args', array( $inst, 'filter_paypal_http_args' ), 10, 2 );
    }

    /**
     * AJAX handler: build a Partner Referrals signup link.
     *
     * Expects POST:
     *   - environment: 'sandbox' | 'live'
     *   - products:    'ppcp' | 'express_checkout' | 'google_pay' | 'apple_pay' (optional, default 'ppcp')
     *   - _wpnonce:    tejcart_paypal_onboarding
     *
     * Responds with JSON { signup_url: string }.
     */
    public function ajax_generate_signup_link(): void {
        $this->require_admin_nonce();

        // Nonce verified by require_admin_nonce() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $environment = $this->sanitize_environment( isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '' );
        $product     = $this->sanitize_product( isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : 'ppcp' );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $body      = $this->build_signup_payload( $environment, $product );
        $proxy_url = $this->get_onboarding_proxy_url();

        // Forensic visibility: the proxy host (third-party by default —
        // see DEFAULT_ONBOARDING_PROXY_URL) is logged on every kickoff so
        // an operator can prove which host their onboarding payload
        // transited through.
        $proxy_host = (string) wp_parse_url( $proxy_url, PHP_URL_HOST );
        if ( '' !== $proxy_host && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'PayPal onboarding kickoff routed via %s (env=%s, product=%s).', $proxy_host, $environment, $product ),
                'info'
            );
        }

        $request_args = array(
            'method'  => 'POST',
            'body'    => $body,
            'timeout' => 20,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        );
        $response = wp_remote_post( $proxy_url, $request_args );
        $this->debug_log_http( 'signup_link (ajax)', $proxy_url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            tejcart_log( 'PayPal onboarding signup link request failed: ' . $response->get_error_message(), 'error' );
            wp_send_json_error(
                array( 'message' => __( 'Could not reach PayPal. Please check your connection and try again.', 'tejcart' ) ),
                502
            );
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $status = $parsed['status'];
        $raw    = $parsed['raw'];
        $data   = $parsed['parse_ok'] ? $parsed['decoded'] : null;

        $debug = array(
            'proxy'  => $this->get_onboarding_proxy_url(),
            'status' => $status,
            'body'   => PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ),
        );

        if ( $status >= 400 || ! $parsed['parse_ok'] ) {
            tejcart_log(
                sprintf( 'PayPal onboarding signup link HTTP %d: %s', $status, PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ) )
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'error'
            );
            wp_send_json_error(
                array(
                    'message' => __( 'PayPal returned an error while starting onboarding. Please try again.', 'tejcart' ),
                    'debug'   => $debug,
                ),
                502
            );
        }

        $signup_url = $this->extract_signup_link( $data );

        if ( '' === $signup_url ) {
            tejcart_log( 'PayPal onboarding signup link missing from proxy response: ' . PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ), 'error' );
            wp_send_json_error(
                array(
                    'message' => __( 'PayPal did not return a signup link. Please try again.', 'tejcart' ),
                    'debug'   => $debug,
                ),
                502
            );
        }

        wp_send_json_success(
            array(
                'signup_url'  => $signup_url,
                'environment' => $environment,
            )
        );
    }

    /**
     * AJAX handler: finalize onboarding after PayPal's postMessage fires.
     *
     * Expects POST:
     *   - shared_id:   string
     *   - auth_code:   string
     *   - environment: 'sandbox' | 'live'
     *   - _wpnonce:    tejcart_paypal_onboarding
     */
    public function ajax_login_seller(): void {
        $this->require_admin_nonce();

        // Nonce verified by require_admin_nonce() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $shared_id   = isset( $_POST['shared_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shared_id'] ) ) : '';
        $auth_code   = isset( $_POST['auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';
        $environment = $this->sanitize_environment( isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '' );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( '' === $shared_id || '' === $auth_code ) {
            wp_send_json_error(
                array( 'message' => __( 'Missing onboarding data from PayPal. Please try connecting again.', 'tejcart' ) ),
                400
            );
        }

        $access_token = $this->exchange_auth_code( $environment, $shared_id, $auth_code );
        if ( is_wp_error( $access_token ) ) {
            tejcart_log(
                'PayPal onboarding access token error: '
                    . $access_token->get_error_message()
                    . PayPal_API::format_response_diagnostics( $access_token ),
                'error'
            );
            wp_send_json_error( array( 'message' => $access_token->get_error_message() ), 502 );
        }

        $credentials = $this->fetch_seller_credentials( $environment, $access_token );
        if ( is_wp_error( $credentials ) ) {
            tejcart_log(
                'PayPal onboarding credentials error: '
                    . $credentials->get_error_message()
                    . PayPal_API::format_response_diagnostics( $credentials ),
                'error'
            );
            wp_send_json_error( array( 'message' => $credentials->get_error_message() ), 502 );
        }

        $merchant_id          = isset( $credentials['payer_id'] ) ? (string) $credentials['payer_id'] : '';
        $credentials['email'] = $this->fetch_seller_email( $environment, $access_token, $merchant_id );

        $this->persist_credentials( $environment, $credentials );
        $this->clear_token_caches();
        $this->auto_register_webhook();

        wp_send_json_success(
            array(
                'message'     => __( 'PayPal connected successfully.', 'tejcart' ),
                'environment' => $environment,
                'status'      => $this->get_status_snapshot(),
            )
        );
    }

    /**
     * Register (or reuse) the PayPal webhook for the freshly-connected
     * environment. Failures are logged but do not block onboarding — the
     * merchant can retry from the admin if needed.
     */
    private function auto_register_webhook(): void {
        $gateway = new PayPal_Gateway();
        $result  = $gateway->register_webhook_for_current_env();

        if ( is_wp_error( $result ) ) {
            tejcart_log(
                'PayPal webhook auto-registration failed: ' . $result->get_error_message(),
                'error'
            );
        }
    }

    /**
     * AJAX handler: clear stored credentials for the active environment.
     */
    public function ajax_disconnect(): void {
        $this->require_admin_nonce();

        // Nonce verified by require_admin_nonce() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $environment = $this->sanitize_environment( isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '' );
        $this->clear_credentials( $environment );
        $this->clear_token_caches();

        wp_send_json_success(
            array(
                'message'     => __( 'PayPal disconnected.', 'tejcart' ),
                'environment' => $environment,
                'status'      => $this->get_status_snapshot(),
            )
        );
    }

    /**
     * AJAX handler: return the current connection status snapshot for
     * re-rendering the card without a full page reload.
     */
    public function ajax_refresh_status(): void {
        $this->require_admin_nonce();

        wp_send_json_success(
            array( 'status' => $this->get_status_snapshot() )
        );
    }

    /**
     * Handle the `merchantIdInPayPal` query parameter PayPal appends when it
     * redirects the seller back to the store. This is the fallback path for
     * when the popup was closed or blocked before the postMessage flow could
     * finish — we can still persist the merchant ID so the capability check
     * can catch up on the next API call.
     */
    public function maybe_handle_return_url(): void {
        if ( empty( $_GET['merchantIdInPayPal'] ) || empty( $_GET['tejcart_paypal_onboarding'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_paypal_onboarding_return' ) ) {
            return;
        }

        $merchant_id         = sanitize_text_field( wp_unslash( $_GET['merchantIdInPayPal'] ) );
        // sanitize_environment() returns a strict allow-listed value.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $environment         = $this->sanitize_environment( isset( $_GET['environment'] ) ? wp_unslash( $_GET['environment'] ) : '' );
        $email_confirmed     = isset( $_GET['isEmailConfirmed'] ) ? sanitize_text_field( wp_unslash( $_GET['isEmailConfirmed'] ) ) : '';
        $permissions_granted = isset( $_GET['permissionsGranted'] ) ? sanitize_text_field( wp_unslash( $_GET['permissionsGranted'] ) ) : '';
        $consent_status      = isset( $_GET['consentStatus'] ) ? sanitize_text_field( wp_unslash( $_GET['consentStatus'] ) ) : '';

        $settings = $this->get_settings();

        if ( 'sandbox' === $environment ) {
            $settings['sandbox_merchant_id'] = $merchant_id;
        } else {
            $settings['merchant_id'] = $merchant_id;
        }

        $settings['onboarding_email_confirmed']     = ( 'true' === $email_confirmed ) ? 'yes' : 'no';
        $settings['onboarding_permissions_granted'] = ( 'true' === $permissions_granted ) ? 'yes' : 'no';
        $settings['onboarding_consent_status']      = $consent_status;
        $settings['onboarding_environment']         = $environment;
        $settings['onboarding_completed_at']        = time();

        update_option( 'tejcart_gateway_tejcart_paypal', $settings, false );
        $this->clear_token_caches();

        $clean_url = remove_query_arg(
            array(
                'merchantIdInPayPal',
                'merchantId',
                'permissionsGranted',
                'consentStatus',
                'productIntentId',
                'productIntentID',
                'isEmailConfirmed',
                'accountStatus',
                'riskStatus',
                'tejcart_paypal_onboarding',
                'environment',
                '_wpnonce',
            )
        );
        $clean_url = add_query_arg( array( 'tejcart_paypal_connected' => '1' ), $clean_url );

        wp_safe_redirect( $clean_url );
        exit;
    }

    /**
     * Build a snapshot of the current PayPal connection state, per environment,
     * suitable for rendering the connection card or returning as JSON.
     *
     * @return array{
     *     sandbox: array{connected:bool,merchant_id:string,client_id_masked:string},
     *     live: array{connected:bool,merchant_id:string,client_id_masked:string},
     *     active_environment: string,
     *     email_confirmed: bool,
     *     permissions_granted: bool
     * }
     */
    public function get_status_snapshot(): array {
        $settings    = $this->get_settings();
        $active_env  = ( ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes' ) ? 'sandbox' : 'live';
        $sandbox_cid = (string) ( $settings['sandbox_client_id'] ?? '' );
        $live_cid    = (string) ( $settings['client_id'] ?? '' );

        return array(
            'sandbox' => array(
                'connected'        => '' !== $sandbox_cid,
                'merchant_id'      => (string) ( $settings['sandbox_merchant_id'] ?? '' ),
                'client_id_masked' => $this->mask( $sandbox_cid ),
                'email'            => (string) ( $settings['sandbox_email'] ?? '' ),
            ),
            'live'    => array(
                'connected'        => '' !== $live_cid,
                'merchant_id'      => (string) ( $settings['merchant_id'] ?? '' ),
                'client_id_masked' => $this->mask( $live_cid ),
                'email'            => (string) ( $settings['live_email'] ?? '' ),
            ),
            'active_environment'  => $active_env,
            'email_confirmed'     => ( ( $settings['onboarding_email_confirmed'] ?? '' ) === 'yes' ),
            'permissions_granted' => ( ( $settings['onboarding_permissions_granted'] ?? '' ) === 'yes' ),
        );
    }

    /**
     * Build the nonce value used by the onboarding UI for AJAX calls.
     */
    public static function ajax_nonce(): string {
        return wp_create_nonce( 'tejcart_paypal_onboarding' );
    }

    /**
     * Build the nonce + URL used by the return URL fallback handler.
     */
    public static function build_return_url( string $environment ): string {
        $base  = add_query_arg(
            array(
                'page'                     => \Tejcart\Admin\PayPal_Manage_Page::PAGE_SLUG,
                'tab'                      => 'api_connection',
                'tejcart_paypal_onboarding' => '1',
                'environment'              => 'sandbox' === $environment ? 'sandbox' : 'live',
            ),
            admin_url( 'admin.php' )
        );
        return wp_nonce_url( $base, 'tejcart_paypal_onboarding_return' );
    }

    /**
     * Fetch a PayPal Partner Referrals signup link for the requested
     * environment. Successful responses are cached in a transient so
     * repeat page loads don't hit the proxy on every render.
     *
     * This is the server-side equivalent of {@see ajax_generate_signup_link()}
     * and is what the PayPal Connection tab calls when pre-rendering the
     * Connect button — no client-side AJAX round trip is needed.
     *
     * @param string $environment 'sandbox' or 'live'.
     * @param string $product     'ppcp' | 'express_checkout' | 'google_pay' | 'apple_pay'.
     * @param bool   $force       Bypass the transient cache and fetch fresh.
     * @return string|\WP_Error Signup URL on success, WP_Error describing
     *                          the failure otherwise. The error is always
     *                          safe to surface to manage_options users.
     */
    public function fetch_signup_link( string $environment, string $product = 'ppcp', bool $force = false ) {
        $environment = $this->sanitize_environment( $environment );
        $product     = $this->sanitize_product( $product );
        $cache_key   = self::SIGNUP_LINK_TRANSIENT_PREFIX . $environment . '_' . $product;

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $proxy_url    = $this->get_onboarding_proxy_url();
        $request_args = array(
            'method'    => 'POST',
            'body'      => $this->build_signup_payload( $environment, $product ),
            'timeout'   => 20,
            'sslverify' => true,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        );
        $response = wp_remote_post( $proxy_url, $request_args );
        $this->debug_log_http( 'signup_link (' . $environment . ')', $proxy_url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            tejcart_log(
                sprintf( 'PayPal signup link fetch failed (%s): %s', $environment, $response->get_error_message() ),
                'error'
            );
            return new \WP_Error(
                'tejcart_paypal_signup_network',
                sprintf(
                    /* translators: 1: environment, 2: underlying error. */
                    __( 'Could not reach the PayPal onboarding service (%1$s): %2$s', 'tejcart' ),
                    $environment,
                    $response->get_error_message()
                ),
                array(
                    'proxy_url'   => $proxy_url,
                    'environment' => $environment,
                )
            );
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $status = $parsed['status'];
        $raw    = $parsed['raw'];
        $data   = $parsed['parse_ok'] ? $parsed['decoded'] : null;

        if ( $status >= 400 || ! $parsed['parse_ok'] ) {
            tejcart_log(
                sprintf( 'PayPal signup link HTTP %d (%s): %s', $status, $environment, PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ) )
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'error'
            );
            return new \WP_Error(
                'tejcart_paypal_signup_http',
                sprintf(
                    /* translators: 1: environment, 2: HTTP status. */
                    __( 'PayPal onboarding service returned HTTP %2$d for %1$s environment.', 'tejcart' ),
                    $environment,
                    $status
                ),
                array(
                    'proxy_url'   => $proxy_url,
                    'environment' => $environment,
                    'status'      => $status,
                    'body'        => PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ),
                )
            );
        }

        $inner_code = isset( $data['http_code'] ) ? (int) $data['http_code'] : 0;
        if ( $inner_code >= 400 ) {
            tejcart_log(
                sprintf(
                    'PayPal signup link inner HTTP %d (%s): %s',
                    $inner_code,
                    $environment,
                    isset( $data['body'] ) ? substr( (string) $data['body'], 0, 500 ) : ''
                ),
                'error'
            );
            return new \WP_Error(
                'tejcart_paypal_signup_inner_http',
                sprintf(
                    /* translators: 1: environment, 2: HTTP status. */
                    __( 'PayPal responded with HTTP %2$d for %1$s onboarding.', 'tejcart' ),
                    $environment,
                    $inner_code
                ),
                array(
                    'proxy_url'   => $proxy_url,
                    'environment' => $environment,
                    'status'      => $inner_code,
                    'body'        => isset( $data['body'] ) ? substr( (string) $data['body'], 0, 500 ) : '',
                )
            );
        }

        $url = $this->extract_signup_link( $data );
        if ( '' === $url ) {
            tejcart_log(
                sprintf( 'PayPal signup link missing from proxy response (%s): %s', $environment, PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ) ),
                'error'
            );
            return new \WP_Error(
                'tejcart_paypal_signup_empty',
                sprintf(
                    /* translators: %s: environment. */
                    __( 'PayPal onboarding service did not return a signup link for the %s environment.', 'tejcart' ),
                    $environment
                ),
                array(
                    'proxy_url'   => $proxy_url,
                    'environment' => $environment,
                    'body'        => PayPal_API::redact_for_log( substr( $raw, 0, 500 ) ),
                )
            );
        }

        set_transient( $cache_key, $url, HOUR_IN_SECONDS );

        return $url;
    }

    /**
     * Build the request body sent to the Tejcart partner proxy.
     *
     * @param string $environment sandbox|live
     * @param string $product     ppcp|express_checkout|google_pay|apple_pay
     * @return array<string, mixed>
     */
    private function build_signup_payload( string $environment, string $product ): array {
        $current_user = wp_get_current_user();
        $email        = is_email( $current_user->user_email ?? '' ) ? $current_user->user_email : '';
        $return_url   = self::build_return_url( $environment );

        $products     = array( 'ppcp' === $product ? 'PPCP' : 'EXPRESS_CHECKOUT' );
        $capabilities = array();

        if ( 'google_pay' === $product || 'apple_pay' === $product ) {
            $products[]   = 'PAYMENT_METHODS';
            $capabilities = array( 'GOOGLE_PAY', 'APPLE_PAY' );
        }

        $payload = array(
            'email'                  => $email,
            'sandbox'                => ( 'sandbox' === $environment ) ? 'yes' : 'no',
            'return_url'             => $return_url,
            'return_url_description' => __( 'Return to your Tejcart store.', 'tejcart' ),
            'products'               => $products,
        );

        if ( ! empty( $capabilities ) ) {
            $payload['capabilities'] = $capabilities;
        }

        // M-6: optionally mint a fresh PKCE pair per onboarding
        // kickoff. The verifier is stored in a 30-minute transient
        // and consumed during the auth-code exchange; the challenge
        // is forwarded to the proxy so it can substitute it for the
        // legacy fixed challenge. Disabled by default because the
        // bundled mbjtechnolabs proxy uses its own hardcoded verifier
        // and would reject a forwarded challenge. Operators who self-
        // host a compatible shim can opt in.
        if ( $this->is_per_session_pkce_enabled() ) {
            $pair = self::generate_pkce_pair();
            self::store_pkce_verifier( $environment, $pair['verifier'] );
            $payload['code_challenge']        = $pair['challenge'];
            $payload['code_challenge_method'] = 'S256';
        }

        /**
         * Filter the Partner Referrals payload before it is sent to the proxy.
         *
         * @param array  $payload     Request body.
         * @param string $environment sandbox|live
         * @param string $product     Requested product id.
         */
        return apply_filters( 'tejcart_paypal_onboarding_payload', $payload, $environment, $product );
    }

    /**
     * M-6: opt-in for per-session PKCE.
     */
    private function is_per_session_pkce_enabled(): bool {
        /**
         * Filter whether the onboarding flow generates a fresh PKCE
         * pair per kickoff and forwards the code_challenge to the
         * proxy.
         *
         * Default: false — the bundled third-party onboarding proxy
         * uses its own hardcoded verifier (see
         * {@see self::LEGACY_CODE_VERIFIER}) and would reject a
         * forwarded challenge. Operators who self-host a compatible
         * shim, or who coordinate with a proxy operator that accepts
         * `code_challenge` / `code_challenge_method` parameters,
         * should return true here.
         *
         * @param bool $enabled Whether per-session PKCE is enabled.
         */
        return (bool) apply_filters( 'tejcart_paypal_onboarding_pkce_per_session', false );
    }

    /**
     * M-6: generate a fresh PKCE pair (RFC 7636, S256).
     *
     * @return array{verifier:string, challenge:string}
     */
    private static function generate_pkce_pair(): array {
        $verifier_bytes = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $verifier  = rtrim( strtr( base64_encode( $verifier_bytes ), '+/', '-_' ), '=' );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
        return array( 'verifier' => $verifier, 'challenge' => $challenge );
    }

    /**
     * M-6: persist the per-session PKCE verifier for the auth-code
     * exchange. 30-minute TTL bounds the window in which a stolen
     * transient row would be useful.
     */
    private static function store_pkce_verifier( string $environment, string $verifier ): void {
        $key = self::pkce_transient_key( $environment );
        if ( '' === $key ) {
            return;
        }
        set_transient( $key, $verifier, 30 * MINUTE_IN_SECONDS );
    }

    /**
     * M-6: retrieve and consume the per-session PKCE verifier.
     */
    private static function consume_pkce_verifier( string $environment ): string {
        $key = self::pkce_transient_key( $environment );
        if ( '' === $key ) {
            return '';
        }
        $verifier = get_transient( $key );
        if ( ! is_string( $verifier ) || '' === $verifier ) {
            return '';
        }
        delete_transient( $key );
        return $verifier;
    }

    /**
     * Transient name for the PKCE verifier — keyed on (user_id,
     * environment) so a sandbox onboarding doesn't trample a live one.
     */
    private static function pkce_transient_key( string $environment ): string {
        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id <= 0 ) {
            return '';
        }
        return self::PKCE_VERIFIER_TRANSIENT_PREFIX . $user_id . '_' . sanitize_key( $environment );
    }

    /**
     * Extract the signup URL from a proxy response. The response shape
     * varies by proxy vendor, so we look in every place a Partner
     * Referrals URL has historically shown up:
     *
     *   - top-level:   signup_url, action_url, url, href
     *   - top-level:   links[].rel == 'action_url'|'signup_url'
     *   - nested:      data.signup_url, data.action_url, data.links[]
     *   - wrapped:     body (JSON string) → links[].rel == 'action_url'
     *                  — this is the shape the mbjtechnolabs proxy
     *                    returns: { body: "<stringified JSON>",
     *                    headers: "...", http_code: 201, result: "success" }
     *
     * @param array<string, mixed> $data Proxy response payload.
     */
    private function extract_signup_link( array $data ): string {
        $url = $this->find_signup_link_in( $data );
        if ( '' !== $url ) {
            return $url;
        }
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            $url = $this->find_signup_link_in( $data['data'] );
            if ( '' !== $url ) {
                return $url;
            }
        }

        if ( isset( $data['body'] ) && is_string( $data['body'] ) ) {
            $inner = json_decode( $data['body'], true );
            if ( is_array( $inner ) ) {
                $url = $this->find_signup_link_in( $inner );
                if ( '' !== $url ) {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * Inner helper for {@see self::extract_signup_link()}.
     *
     * Every candidate URL is validated against
     * {@see self::is_paypal_signup_url()} before being returned. The
     * proxy that produces this payload is third-party infrastructure;
     * a compromise of the proxy must not redirect merchants to an
     * attacker-controlled credential-collection page.
     *
     * @param array<string, mixed> $node Response node to search.
     * @return string
     */
    private function find_signup_link_in( array $node ): string {
        foreach ( array( 'signup_url', 'action_url', 'url', 'href' ) as $key ) {
            if ( ! empty( $node[ $key ] ) && is_string( $node[ $key ] ) && self::is_paypal_signup_url( $node[ $key ] ) ) {
                return $node[ $key ];
            }
        }
        if ( ! empty( $node['links'] ) && is_array( $node['links'] ) ) {
            foreach ( $node['links'] as $link ) {
                if ( ! is_array( $link ) || empty( $link['href'] ) || ! is_string( $link['href'] ) ) {
                    continue;
                }
                $rel = isset( $link['rel'] ) ? (string) $link['rel'] : '';
                if ( in_array( $rel, array( 'action_url', 'signup_url' ), true )
                    && self::is_paypal_signup_url( $link['href'] ) ) {
                    return $link['href'];
                }
            }

            // Fall back to ANY link with a real PayPal host. The previous
            // implementation used a bare `stripos(..., 'paypal.com')` which
            // would happily match `evil-paypal.com.attacker.com`. The
            // strict host check below rejects every such spoof.
            foreach ( $node['links'] as $link ) {
                if ( is_array( $link ) && ! empty( $link['href'] ) && is_string( $link['href'] )
                    && self::is_paypal_signup_url( $link['href'] ) ) {
                    return $link['href'];
                }
            }
        }
        return '';
    }

    /**
     * Strict-host whitelist for PayPal onboarding signup URLs.
     *
     * The signup link is rendered to the merchant as a clickable button
     * in the wp-admin onboarding card. If the third-party proxy that
     * sources this URL is ever compromised — or if a future
     * proxy-response format quirk injects an attacker-controlled string
     * — we must NOT navigate the merchant to a non-PayPal host. Returns
     * true only for `https://` URLs whose host is exactly `paypal.com`,
     * `sandbox.paypal.com`, or a subdomain of either (case-insensitive,
     * trailing dot stripped).
     *
     * @param string $url
     */
    private static function is_paypal_signup_url( string $url ): bool {
        $url = trim( $url );
        if ( '' === $url ) {
            return false;
        }
        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
            return false;
        }
        $host = strtolower( (string) $parts['host'] );
        $host = rtrim( $host, '.' );
        foreach ( array( 'paypal.com', 'sandbox.paypal.com' ) as $allowed ) {
            if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exchange the sharedId + authCode from the MiniBrowser postMessage for a
     * PayPal access token we can use to call the credentials endpoint.
     *
     * @return string|\WP_Error
     */
    private function exchange_auth_code( string $environment, string $shared_id, string $auth_code ) {
        // M-6: prefer the per-session verifier minted in
        // build_signup_payload() if the operator has opted into the
        // new path. Falls back to the legacy fixed verifier so the
        // bundled mbjtechnolabs proxy continues to work.
        $verifier = self::consume_pkce_verifier( $environment );
        if ( '' === $verifier ) {
            $verifier = self::LEGACY_CODE_VERIFIER;
        }

        // No trailing slash on /v1/oauth2/token — PayPal's load balancer
        // serves a redirect chain for the slashed variant that the WP HTTP
        // transport reports as a generic "Response could not be parsed"
        // WP_Error.
        $token_url = $this->api_base( $environment ) . '/v1/oauth2/token';

        // Pre-encode the body as a URL-encoded string instead of letting
        // wp_remote_post() serialize an array. The matching token call in
        // PayPal_API::request_access_token() does the same and never trips
        // the "Response could not be parsed" Requests-level transport
        // error; the array-body path does, almost certainly because the
        // Requests cURL transport switches encoding behaviour when a
        // body array is paired with an explicit Content-Type header and
        // the resulting wire request confuses PayPal's edge into
        // returning a malformed reply.
        //
        // Force HTTP/1.1 as well: WP defaults to HTTP/1.0 historically
        // but recent cURL builds negotiate HTTP/2, and "Response could
        // not be parsed" is a known WP HTTP API symptom when an HTTP/2
        // upgrade is mid-stream-truncated by an upstream LB.
        $body_encoded = http_build_query(
            array(
                'grant_type'    => 'authorization_code',
                'code'          => $auth_code,
                'code_verifier' => $verifier,
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $request_args = array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization'                 => 'Basic ' . base64_encode( $shared_id . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth
                'Content-Type'                  => 'application/x-www-form-urlencoded',
                'Accept'                        => 'application/json',
                'PayPal-Partner-Attribution-Id' => \TejCart\Gateways\PayPal\PayPal_Gateway::bn_code(),
            ),
            'body'    => $body_encoded,
            'timeout' => 20,
        );
        // sslverify / sslcertificates / httpversion are pinned by the
        // gateway-wide http_request_args filter installed in register().
        $response = wp_remote_post( $token_url, $request_args );
        $this->debug_log_http( 'exchange_auth_code (' . $environment . ')', $token_url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $body   = $parsed['parse_ok'] ? $parsed['decoded'] : array();
        $status = $parsed['status'];

        // Distinguish parse failure from auth failure so the on-call operator
        // can tell "PayPal returned an HTML error page" apart from "PayPal
        // rejected the auth code". Both share the same WP_Error code
        // (tejcart_paypal_onboarding_token) for back-compat with the AJAX
        // handler's caller, but the data payload now always carries the
        // status/content-type/body-excerpt triplet so the log line at
        // ajax_login_seller() surfaces the upstream symptom.
        if ( ! $parsed['parse_ok'] ) {
            $message = $status >= 400
                ? sprintf(
                    /* translators: %d: HTTP status code. */
                    __( 'PayPal returned a non-JSON response (HTTP %d) while exchanging the onboarding auth code.', 'tejcart' ),
                    $status
                )
                : __( 'PayPal returned a non-JSON response while exchanging the onboarding auth code.', 'tejcart' );
            return new \WP_Error(
                'tejcart_paypal_onboarding_token',
                $message,
                array(
                    'status'       => $status,
                    'content_type' => $parsed['content_type'],
                    'body_length'  => $parsed['body_length'],
                    'body_excerpt' => $parsed['body_excerpt'],
                )
            );
        }

        if ( $status >= 400 || empty( $body['access_token'] ) ) {
            $message = isset( $body['error_description'] )
                ? (string) $body['error_description']
                : __( 'PayPal could not authenticate the onboarding request.', 'tejcart' );
            return new \WP_Error(
                'tejcart_paypal_onboarding_token',
                $message,
                array(
                    'status'       => $status,
                    'content_type' => $parsed['content_type'],
                    'body_length'  => $parsed['body_length'],
                    'body_excerpt' => $parsed['body_excerpt'],
                )
            );
        }

        return (string) $body['access_token'];
    }

    /**
     * Call the Partner Referrals credentials endpoint to obtain the merchant's
     * own REST client_id, client_secret and payer_id.
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function fetch_seller_credentials( string $environment, string $access_token ) {
        $partner_id = 'sandbox' === $environment
            ? self::SANDBOX_PARTNER_MERCHANT_ID
            : self::LIVE_PARTNER_MERCHANT_ID;

        // Same trailing-slash hazard as /v1/oauth2/token — keep the path
        // canonical so the WP HTTP transport gets a parseable response.
        $url = $this->api_base( $environment )
            . '/v1/customer/partners/' . rawurlencode( $partner_id )
            . '/merchant-integrations/credentials';

        $request_args = array(
            'method'  => 'GET',
            'headers' => array(
                'Authorization'                 => 'Bearer ' . $access_token,
                'Content-Type'                  => 'application/json',
                'Accept'                        => 'application/json',
                'PayPal-Partner-Attribution-Id' => \TejCart\Gateways\PayPal\PayPal_Gateway::bn_code(),
            ),
            'timeout' => 20,
        );
        $response = wp_remote_get( $url, $request_args );
        $this->debug_log_http( 'fetch_seller_credentials (' . $environment . ')', $url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $body   = $parsed['parse_ok'] ? $parsed['decoded'] : array();
        $status = $parsed['status'];

        if ( $status >= 400 || ! $parsed['parse_ok'] || empty( $body['client_id'] ) || empty( $body['client_secret'] ) ) {
            return new \WP_Error(
                'tejcart_paypal_onboarding_credentials',
                __( 'PayPal did not return merchant credentials. Please retry the connection.', 'tejcart' ),
                array(
                    'status'       => $status,
                    'content_type' => $parsed['content_type'],
                    'body_length'  => $parsed['body_length'],
                    'body_excerpt' => $parsed['body_excerpt'],
                )
            );
        }

        return $body;
    }

    /**
     * Look up the merchant's primary PayPal email after onboarding. Returns
     * the email on success or an empty string on any failure (the connection
     * card hides the email row when blank rather than showing a misleading
     * value).
     *
     * Strategy, in order:
     *   1. Partner Referrals "Show seller status" endpoint
     *      (GET /v1/customer/partners/{partner_id}/merchant-integrations/{merchant_id}).
     *      This is the canonical Partner Referrals path — its `primary_email`
     *      field is the merchant's actual PayPal account email and is always
     *      populated regardless of the OAuth scopes the merchant granted.
     *   2. OpenID Connect userinfo
     *      (GET /v1/identity/oauth2/userinfo?schema=paypalv1.1).
     *      Only works when the auth-code grant included the `openid email`
     *      scope, which Partner Referrals doesn't include by default.
     */
    private function fetch_seller_email( string $environment, string $access_token, string $merchant_id ): string {
        $email = $this->fetch_seller_email_via_seller_status( $environment, $access_token, $merchant_id );
        if ( '' !== $email ) {
            return $email;
        }
        return $this->fetch_seller_email_via_userinfo( $environment, $access_token );
    }

    /**
     * Resolve the merchant's primary email by calling the Partner Referrals
     * "Show seller status" endpoint. The response shape is documented at
     * https://developer.paypal.com/docs/api/partner-referrals/v1/#show-seller-status .
     */
    private function fetch_seller_email_via_seller_status( string $environment, string $access_token, string $merchant_id ): string {
        if ( '' === $merchant_id ) {
            return '';
        }

        $partner_id = ( 'sandbox' === $environment )
            ? self::SANDBOX_PARTNER_MERCHANT_ID
            : self::LIVE_PARTNER_MERCHANT_ID;

        $url = $this->api_base( $environment )
            . '/v1/customer/partners/' . rawurlencode( $partner_id )
            . '/merchant-integrations/' . rawurlencode( $merchant_id );

        $request_args = array(
            'method'  => 'GET',
            'headers' => array(
                'Authorization'                 => 'Bearer ' . $access_token,
                'Accept'                        => 'application/json',
                'PayPal-Partner-Attribution-Id' => \TejCart\Gateways\PayPal\PayPal_Gateway::bn_code(),
            ),
            'timeout' => 15,
        );
        $response = wp_remote_get( $url, $request_args );
        $this->debug_log_http( 'seller_status (' . $environment . ')', $url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            tejcart_log( 'PayPal seller status lookup failed: ' . $response->get_error_message(), 'warning' );
            return '';
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $status = $parsed['status'];
        if ( $status >= 400 ) {
            tejcart_log(
                sprintf( 'PayPal seller status HTTP %d', $status )
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'warning'
            );
            return '';
        }

        if ( ! $parsed['parse_ok'] ) {
            tejcart_log(
                'PayPal seller status: non-JSON response'
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'warning'
            );
            return '';
        }
        $body = $parsed['decoded'];

        if ( ! empty( $body['primary_email'] ) && is_string( $body['primary_email'] ) && is_email( $body['primary_email'] ) ) {
            return sanitize_email( $body['primary_email'] );
        }

        // Some PayPal sandbox responses return the email under `tracking_id`
        // when the partner used the seller's email as the tracking id.
        if ( ! empty( $body['tracking_id'] ) && is_string( $body['tracking_id'] ) && is_email( $body['tracking_id'] ) ) {
            return sanitize_email( $body['tracking_id'] );
        }

        return '';
    }

    /**
     * Fallback email lookup via the OpenID Connect userinfo endpoint. Only
     * succeeds when the merchant access token carries the `openid email`
     * scope.
     */
    private function fetch_seller_email_via_userinfo( string $environment, string $access_token ): string {
        $url          = $this->api_base( $environment ) . '/v1/identity/oauth2/userinfo?schema=paypalv1.1';
        $request_args = array(
            'method'  => 'GET',
            'headers' => array(
                'Authorization'                 => 'Bearer ' . $access_token,
                'Accept'                        => 'application/json',
                'PayPal-Partner-Attribution-Id' => \TejCart\Gateways\PayPal\PayPal_Gateway::bn_code(),
            ),
            'timeout' => 15,
        );
        $response = wp_remote_get( $url, $request_args );
        $this->debug_log_http( 'userinfo_email (' . $environment . ')', $url, $request_args, $response );

        if ( is_wp_error( $response ) ) {
            tejcart_log( 'PayPal seller email userinfo lookup failed: ' . $response->get_error_message(), 'warning' );
            return '';
        }

        $parsed = PayPal_API::decode_json_response( $response );
        $status = $parsed['status'];
        if ( $status >= 400 ) {
            tejcart_log(
                sprintf( 'PayPal seller email userinfo HTTP %d', $status )
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'warning'
            );
            return '';
        }

        if ( ! $parsed['parse_ok'] ) {
            tejcart_log(
                'PayPal seller email userinfo: non-JSON response'
                    . PayPal_API::format_response_diagnostics( $parsed ),
                'warning'
            );
            return '';
        }
        $body = $parsed['decoded'];

        if ( ! empty( $body['email'] ) && is_string( $body['email'] ) && is_email( $body['email'] ) ) {
            return sanitize_email( $body['email'] );
        }

        if ( ! empty( $body['emails'] ) && is_array( $body['emails'] ) ) {
            $fallback = '';
            foreach ( $body['emails'] as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['value'] ) || ! is_string( $entry['value'] ) ) {
                    continue;
                }
                if ( ! is_email( $entry['value'] ) ) {
                    continue;
                }
                if ( ! empty( $entry['primary'] ) ) {
                    return sanitize_email( $entry['value'] );
                }
                if ( '' === $fallback ) {
                    $fallback = sanitize_email( $entry['value'] );
                }
            }
            if ( '' !== $fallback ) {
                return $fallback;
            }
        }

        return '';
    }

    /**
     * Persist the fetched credentials into the PayPal gateway settings.
     *
     * @param array<string, mixed> $credentials Credentials returned by the partner proxy.
     */
    private function persist_credentials( string $environment, array $credentials ): void {
        $settings = $this->get_settings();

        $seller_email = isset( $credentials['email'] ) && is_string( $credentials['email'] ) && is_email( $credentials['email'] )
            ? sanitize_email( $credentials['email'] )
            : '';

        // M-3: encrypt the client_secret in the same call as persistence
        // so the onboarding success path never writes plaintext to
        // wp_options. The save-side protection in PayPal_Gateway only
        // fires on the admin-form save_settings() path; onboarding goes
        // direct to update_option().
        $secret_plain = sanitize_text_field( (string) ( $credentials['client_secret'] ?? '' ) );
        $secret_at_rest = '';
        if ( '' !== $secret_plain ) {
            try {
                $secret_at_rest = \TejCart\Security\Crypto::encrypt_required( $secret_plain );
            } catch ( \TejCart\Security\Crypto_Exception $e ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        'PayPal onboarding: refusing to persist client_secret as plaintext (' . $e->getMessage() . ').',
                        'error'
                    );
                }
                // Bail without writing the partial state — caller will
                // surface the failure on the next is_connected() check.
                return;
            }
        }

        if ( 'sandbox' === $environment ) {
            $settings['sandbox_mode']          = 'yes';
            $settings['sandbox_client_id']     = sanitize_text_field( (string) ( $credentials['client_id'] ?? '' ) );
            $settings['sandbox_client_secret'] = $secret_at_rest;
            $settings['sandbox_email']         = $seller_email;
            if ( ! empty( $credentials['payer_id'] ) ) {
                $settings['sandbox_merchant_id'] = sanitize_text_field( (string) $credentials['payer_id'] );
            }
        } else {
            $settings['sandbox_mode']  = 'no';
            $settings['client_id']     = sanitize_text_field( (string) ( $credentials['client_id'] ?? '' ) );
            $settings['client_secret'] = $secret_at_rest;
            $settings['live_email']    = $seller_email;
            if ( ! empty( $credentials['payer_id'] ) ) {
                $settings['merchant_id'] = sanitize_text_field( (string) $credentials['payer_id'] );
            }
        }

        if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
            $settings['enabled'] = 'yes';
        }

        $settings['onboarding_environment']  = $environment;
        $settings['onboarding_completed_at'] = time();

        update_option( 'tejcart_gateway_tejcart_paypal', $settings, false );

        $this->enable_sibling_gateway( 'tejcart_googlepay' );
        $this->enable_sibling_gateway( 'tejcart_card' );
    }

    /**
     * Flip a sibling gateway's `enabled` flag to 'yes' in its wp_options
     * row, idempotently. Used from persist_credentials() to auto-activate
     * Google Pay and the Advanced Card Payments gateway the moment the
     * merchant completes PayPal onboarding.
     *
     * @param string $gateway_id Gateway identifier, e.g. 'tejcart_googlepay'.
     * @return void
     */
    private function enable_sibling_gateway( string $gateway_id ): void {
        $option_key = 'tejcart_gateway_' . $gateway_id;
        $settings   = get_option( $option_key, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        if ( ( $settings['enabled'] ?? 'no' ) === 'yes' ) {
            return;
        }
        $settings['enabled'] = 'yes';
        // Keep autoload off to match persist_credentials() and the plugin's
        // gateway-option hardening — these rows are only read on payment /
        // admin requests, never on every front-end page load.
        update_option( $option_key, $settings, false );
    }

    /**
     * Wipe stored credentials for the requested environment.
     */
    private function clear_credentials( string $environment ): void {
        $settings = $this->get_settings();

        if ( 'sandbox' === $environment ) {
            $settings['sandbox_client_id']     = '';
            $settings['sandbox_client_secret'] = '';
            $settings['sandbox_merchant_id']   = '';
            $settings['sandbox_email']         = '';
        } else {
            $settings['client_id']     = '';
            $settings['client_secret'] = '';
            $settings['merchant_id']   = '';
            $settings['live_email']    = '';
        }

        update_option( 'tejcart_gateway_tejcart_paypal', $settings, false );
    }

    /**
     * Drop any cached access / client tokens so the next API call uses
     * the newly-stored credentials.
     */
    private function clear_token_caches(): void {
        // Audit H-14 (PPCP F-007): the old code deleted
        // `tejcart_paypal_access_token_sandbox` etc. — un-fingerprinted
        // base keys that PayPal_API never writes (it appends a
        // credential fingerprint). Use a wildcard $wpdb delete
        // against `_transient_tejcart_paypal_access_token_%` so both
        // the old (pre-fingerprint) AND the fingerprinted transients
        // are cleared. This matters on disconnect / credential-rotate /
        // "Test connection" — the stale token must not survive.
        global $wpdb;
        foreach ( array( 'access_token', 'client_token' ) as $type ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_tejcart_paypal_' . $type . '_%',
                    '_transient_timeout_tejcart_paypal_' . $type . '_%'
                )
            );
        }

        delete_transient( self::SIGNUP_LINK_TRANSIENT_PREFIX . 'sandbox' );
        delete_transient( self::SIGNUP_LINK_TRANSIENT_PREFIX . 'live' );
    }

    /**
     * Load raw PayPal gateway settings from the DB (may be empty array).
     *
     * @return array<string, mixed>
     */
    private function get_settings(): array {
        $raw = get_option( 'tejcart_gateway_tejcart_paypal', array() );
        return is_array( $raw ) ? $raw : array();
    }

    /**
     * Resolve the PayPal REST API base for the given environment.
     */
    private function api_base( string $environment ): string {
        return ( 'sandbox' === $environment ) ? self::SANDBOX_API : self::LIVE_API;
    }

    /**
     * Mask a client ID so we can safely display it in the admin UI.
     * Shows the first 4 and last 4 characters.
     */
    private function mask( string $value ): string {
        $value = trim( $value );
        $len   = strlen( $value );
        if ( 0 === $len ) {
            return '';
        }
        if ( $len <= 10 ) {
            return str_repeat( '•', max( 0, $len - 2 ) ) . substr( $value, -2 );
        }
        return substr( $value, 0, 4 ) . str_repeat( '•', 8 ) . substr( $value, -4 );
    }

    /**
     * Resolve the onboarding proxy URL.
     *
     * @return string
     */
    private function get_onboarding_proxy_url(): string {
        /**
         * Filter the partner proxy URL used for PayPal seller onboarding.
         *
         * @param string $url Default proxy URL.
         */
        return (string) apply_filters( 'tejcart_paypal_onboarding_proxy_url', self::DEFAULT_ONBOARDING_PROXY_URL );
    }

    /**
     * Normalise an environment value.
     *
     * @param mixed $raw Raw input from the request.
     */
    private function sanitize_environment( $raw ): string {
        $raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        return ( 'live' === $raw ) ? 'live' : 'sandbox';
    }

    /**
     * Normalise the requested Partner Referrals product bundle.
     *
     * @param mixed $raw Raw input from the request.
     */
    private function sanitize_product( $raw ): string {
        $raw     = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        $allowed = array( 'ppcp', 'express_checkout', 'google_pay', 'apple_pay' );
        return in_array( $raw, $allowed, true ) ? $raw : 'ppcp';
    }

    /**
     * `http_request_args` filter — pin a known-good CA bundle and
     * force HTTP/1.1 on every PayPal API call.
     *
     * This applies site-wide (onboarding, checkout token, client
     * token, /v2/checkout/orders, webhook signature verification, the
     * Tejcart partner proxy, …) so a single fix benefits every call
     * site without duplicating request args at each call. Targets
     * matched by hostname suffix so sandbox/live/Fastly endpoints all
     * pick up the same hardening.
     *
     * @param array<string,mixed> $args wp_remote_* request args.
     * @param string              $url  Full request URL.
     * @return array<string,mixed>
     */
    public function filter_paypal_http_args( $args, $url ) {
        if ( ! is_array( $args ) || ! is_string( $url ) || '' === $url ) {
            return $args;
        }
        if ( ! $this->is_paypal_http_host( $url ) ) {
            return $args;
        }

        // HTTP/1.1 — "Response could not be parsed" is a known WP
        // Requests symptom when an HTTP/2 upgrade is mid-stream-
        // truncated; pinning HTTP/1.1 sidesteps the negotiation.
        $args['httpversion'] = '1.1';

        $sslverify = $this->should_verify_ssl();
        $args['sslverify'] = $sslverify;

        if ( $sslverify ) {
            $ca_bundle = $this->resolve_ca_bundle_path();
            if ( '' !== $ca_bundle && file_exists( $ca_bundle ) && is_readable( $ca_bundle ) ) {
                $args['sslcertificates'] = $ca_bundle;
            }
        }

        return $args;
    }

    /**
     * Whether a URL targets a host the PayPal stack talks to. Matches
     * the live + sandbox PayPal API hosts and the Tejcart partner
     * onboarding proxy. Hostname suffix match so `api-m.paypal.com`,
     * `api.sandbox.paypal.com`, `api-3t.paypal.com`, etc. all qualify
     * without enumerating every PayPal subdomain.
     */
    private function is_paypal_http_host( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }
        $suffixes = array(
            '.paypal.com',
            '.paypalobjects.com',
            'tejcart.com',
        );
        foreach ( $suffixes as $suffix ) {
            if ( '.' === $suffix[0] ) {
                if ( str_ends_with( $host, $suffix ) || $host === substr( $suffix, 1 ) ) {
                    return true;
                }
            } elseif ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether onboarding HTTPS calls should verify the upstream TLS
     * certificate.
     *
     * Defaults to `true` everywhere except localhost / *.local / *.test
     * dev environments, where a broken or stale CA store on the
     * developer's workstation routinely surfaces as cURL errno 60 (the
     * WP Requests library mis-renders that as "Response could not be
     * parsed"). Localhost detection mirrors `wp_http_supports()`'s
     * heuristic — `home_url()` is checked against the loopback
     * addresses and the conventional dev TLDs (`.local`, `.test`,
     * `.localhost`).
     *
     * Power users can pin the value via:
     *   - `define( 'TEJCART_PAYPAL_ONBOARDING_SSL_VERIFY', false );`
     *   - `add_filter( 'tejcart_paypal_onboarding_sslverify', '__return_false' );`
     *
     * Both override the localhost autodetection. The constant takes
     * precedence over the filter.
     */
    private function should_verify_ssl(): bool {
        if ( defined( 'TEJCART_PAYPAL_ONBOARDING_SSL_VERIFY' ) ) {
            return (bool) constant( 'TEJCART_PAYPAL_ONBOARDING_SSL_VERIFY' );
        }

        $is_local = false;
        if ( function_exists( 'home_url' ) ) {
            $host = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
            $host = strtolower( $host );
            if ( '' !== $host ) {
                $is_local = (
                    'localhost' === $host
                    || '127.0.0.1' === $host
                    || '::1' === $host
                    || str_ends_with( $host, '.local' )
                    || str_ends_with( $host, '.test' )
                    || str_ends_with( $host, '.localhost' )
                );
            }
        }

        $default = ! $is_local;

        /**
         * Filter whether the PayPal onboarding HTTPS calls should
         * verify the upstream TLS certificate.
         *
         * Defaults to true on production hosts and false on
         * localhost / *.local / *.test dev environments. Returning
         * false sets the `sslverify` arg on the underlying
         * `wp_remote_post()` call to false.
         *
         * @param bool $verify Whether to verify the TLS certificate.
         */
        return (bool) apply_filters( 'tejcart_paypal_onboarding_sslverify', $default );
    }

    /**
     * Resolve the CA bundle to pin onto the onboarding HTTPS calls.
     *
     * Defaults to WordPress's bundled Mozilla CA file
     * (`wp-includes/certificates/ca-bundle.crt`), which is what the WP
     * Requests library uses by default but which a few mis-configured
     * hosts manage to override. Pinning explicitly resolves
     * "SSL certificate problem: unable to get local issuer certificate"
     * (cURL errno 60 / X509_V_ERR_UNABLE_TO_GET_ISSUER_CERT_LOCALLY) on
     * those sites — that error is what the WP Requests transport
     * mis-renders as "Response could not be parsed".
     *
     * Filterable via `tejcart_paypal_onboarding_ca_bundle` so operators
     * with corporate-CA / private-PKI environments can swap in their
     * own bundle. Returning an empty string disables the explicit pin
     * and falls back to whichever trust store cURL was compiled with.
     */
    private function resolve_ca_bundle_path(): string {
        $default = ( defined( 'ABSPATH' ) && defined( 'WPINC' ) )
            ? ABSPATH . WPINC . '/certificates/ca-bundle.crt'
            : '';
        /**
         * Filter the CA bundle file used to verify PayPal's TLS cert
         * during onboarding HTTP calls.
         *
         * @param string $path Absolute path to a PEM-format CA bundle.
         */
        return (string) apply_filters( 'tejcart_paypal_onboarding_ca_bundle', $default );
    }

    /**
     * Emit a verbose `debug`-level log line capturing the full HTTP
     * request and response for an onboarding endpoint. No-op unless the
     * operator has set `tejcart_log_level=debug`. Bearer tokens, basic
     * auth, the PKCE verifier, the auth code, and any returned secrets
     * are redacted before serialization so the log file is safe to
     * share with support.
     *
     * Wired into every wp_remote_* call in this class so a debug-level
     * operator chasing onboarding failures gets URL, method, headers,
     * body, response status, content-type, and (BOM-stripped, secret-
     * redacted) response body in a single line per round-trip.
     *
     * @param string                      $context  Short label for the call site, e.g. "exchange_auth_code (sandbox)".
     * @param string                      $url      Request URL.
     * @param array<string,mixed>         $args     wp_remote_* args (method, headers, body, timeout, …).
     * @param array<string,mixed>|\WP_Error $response wp_remote_* return value.
     */
    private function debug_log_http( string $context, string $url, array $args, $response ): void {
        if ( ! function_exists( 'tejcart_log_level_passes' ) || ! tejcart_log_level_passes( 'debug' ) ) {
            return;
        }

        $method       = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
        $request_dump = (string) wp_json_encode( $this->redact_request_for_log( $args ) );

        if ( is_wp_error( $response ) ) {
            // Surface every scrap the WP HTTP transport gave us: code,
            // every error message (rare, but Requests can attach more
            // than one), and most importantly $e->getData() — the WP
            // Requests library attaches the raw upstream payload to its
            // exception when it can't parse it, so the actual PayPal
            // bytes show up here as the WP_Error's data.
            $error_codes   = $response->get_error_codes();
            $error_messages = $response->get_error_messages();
            $error_data    = array();
            foreach ( $error_codes as $code ) {
                $datum = $response->get_error_data( $code );
                if ( null !== $datum ) {
                    $error_data[ $code ] = $datum;
                }
            }
            $raw_payload_dump = '';
            foreach ( $error_data as $datum ) {
                if ( is_string( $datum ) && '' !== $datum ) {
                    $raw_payload_dump = $this->redact_response_body_for_log( $datum );
                    break;
                }
            }

            tejcart_log(
                sprintf(
                    '[PayPal Onboarding] %s — %s %s — request=%s — transport error: codes=%s messages=%s data=%s raw=%s',
                    $context,
                    $method,
                    $url,
                    $request_dump,
                    wp_json_encode( $error_codes ),
                    wp_json_encode( $error_messages ),
                    wp_json_encode( $error_data ),
                    '' === $raw_payload_dump ? '<none>' : $raw_payload_dump
                ),
                'debug',
                array( 'source' => 'paypal' )
            );
            return;
        }

        $status     = (int) wp_remote_retrieve_response_code( $response );
        $status_msg = function_exists( 'wp_remote_retrieve_response_message' )
            ? (string) wp_remote_retrieve_response_message( $response )
            : '';
        $headers    = function_exists( 'wp_remote_retrieve_headers' )
            ? wp_remote_retrieve_headers( $response )
            : array();
        // wp_remote_retrieve_headers() returns a Requests_Utility_CaseInsensitiveDictionary
        // on real WP and a plain array on stubbed envs — normalise so it
        // serializes cleanly into the log line.
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }
        if ( ! is_array( $headers ) ) {
            $headers = array();
        }
        $raw = (string) wp_remote_retrieve_body( $response );

        tejcart_log(
            sprintf(
                '[PayPal Onboarding] %s — %s %s — request=%s — response: HTTP %d %s headers=%s body=%s',
                $context,
                $method,
                $url,
                $request_dump,
                $status,
                $status_msg,
                wp_json_encode( $headers ),
                $this->redact_response_body_for_log( $raw )
            ),
            'debug',
            array( 'source' => 'paypal' )
        );
    }

    /**
     * Redact bearer tokens, basic-auth credentials, the PKCE verifier,
     * and the auth code before a request payload is serialized into the
     * debug log.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function redact_request_for_log( array $args ): array {
        $sensitive_headers = array( 'authorization', 'paypal-auth-assertion' );
        $sensitive_body    = array( 'access_token', 'client_secret', 'refresh_token', 'code', 'code_verifier' );

        if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
            foreach ( $args['headers'] as $name => $val ) {
                if ( in_array( strtolower( (string) $name ), $sensitive_headers, true ) ) {
                    $args['headers'][ $name ] = '***';
                }
            }
        }

        if ( isset( $args['body'] ) ) {
            if ( is_array( $args['body'] ) ) {
                foreach ( $sensitive_body as $key ) {
                    if ( array_key_exists( $key, $args['body'] ) ) {
                        $args['body'][ $key ] = '***';
                    }
                }
            } elseif ( is_string( $args['body'] ) ) {
                foreach ( $sensitive_body as $key ) {
                    $args['body'] = (string) preg_replace(
                        '/(' . preg_quote( $key, '/' ) . ')=[^&]*/i',
                        '$1=***',
                        $args['body']
                    );
                }
            }
        }

        return $args;
    }

    /**
     * Render an HTTP response body for the debug log. JSON bodies are
     * decoded, secret-sensitive fields are redacted, and the result is
     * re-encoded so the log line is one structured payload. Non-JSON
     * bodies (HTML error pages from a CDN, plain text) are truncated to
     * 2KB so a 502 page from an upstream proxy can't blow up the log
     * file. Empty bodies are rendered as `<empty>` so an operator can
     * tell "PayPal returned nothing" from "we never made the call".
     */
    private function redact_response_body_for_log( string $body ): string {
        if ( '' === $body ) {
            return '<empty>';
        }

        $clean = $body;
        if ( 0 === strncmp( $clean, "\xEF\xBB\xBF", 3 ) ) {
            $clean = substr( $clean, 3 );
        }
        $decoded = json_decode( trim( $clean ), true );

        if ( is_array( $decoded ) ) {
            return (string) wp_json_encode( $this->redact_response_keys( $decoded ) );
        }

        // Debug-only path — bump the cap to 16KB so an HTML error page
        // from an upstream LB / WAF reaches the log intact instead of
        // being truncated to a useless 2KB snippet. JSON bodies are
        // already key-redacted above, so the only remaining risk is
        // disk pressure from a runaway HTML payload.
        if ( strlen( $body ) > 16000 ) {
            return substr( $body, 0, 16000 ) . '…[truncated]';
        }
        return $body;
    }

    /**
     * Recursively replace secret-sensitive fields in a decoded response.
     *
     * @param array<int|string,mixed> $payload
     * @return array<int|string,mixed>
     */
    private function redact_response_keys( array $payload ): array {
        $sensitive = array( 'access_token', 'client_secret', 'refresh_token', 'client_token', 'id_token' );
        $out       = array();
        foreach ( $payload as $k => $v ) {
            if ( is_string( $k ) && in_array( strtolower( $k ), $sensitive, true ) ) {
                $out[ $k ] = '***';
                continue;
            }
            $out[ $k ] = is_array( $v ) ? $this->redact_response_keys( $v ) : $v;
        }
        return $out;
    }

    /**
     * Require manage_options + a valid onboarding nonce or send a 403.
     */
    private function require_admin_nonce(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tejcart' ) ), 403 );
        }
        if ( ! check_ajax_referer( 'tejcart_paypal_onboarding', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'tejcart' ) ), 403 );
        }
    }
}
