<?php
/**
 * Frontend payment-button telemetry endpoint.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts a small JSON beacon from the storefront when a buyer interacts
 * with a payment surface — clicks the "Place Order" button, taps the
 * PayPal / Apple Pay / Google Pay button, opens the saved-cards picker,
 * etc. — and writes it to the `payment` channel as a `debug` line.
 *
 * The endpoint is intentionally narrow:
 *
 *   - Accepts only a fixed enum of `event` names so a misbehaving page
 *     script can't fill the log with arbitrary strings.
 *   - Rate-limited per IP via the existing {@see \TejCart\Security\Rate_Limiter}.
 *   - No-ops at the `tejcart_log_level_passes()` gate when the merchant
 *     hasn't opted into verbose logging. The JS beacon is also gated
 *     server-side at enqueue time so the file is never even loaded on
 *     production-default stores — defence in depth.
 *
 * Why an endpoint at all instead of relying on server hooks alone:
 *
 *   PayPal Smart Buttons / Apple Pay / Google Pay run a multi-step JS
 *   handshake (`createOrder`, `onApprove`, `onError`) before any
 *   server-side checkout AJAX fires. When a buyer cancels at the wallet
 *   sheet — or the wallet itself returns an error to JS — there is no
 *   server hook to subscribe to. The telemetry beacon captures that
 *   path explicitly, which is exactly the "I clicked PayPal and nothing
 *   happened" support case this feature is for.
 */
final class Payment_Telemetry_REST {

	private const ROUTE     = '/debug/telemetry';
	private const NAMESPACE_PATH = 'tejcart/v1';

	/** Allowed `event` values. Anything else is rejected with 400. */
	private const ALLOWED_EVENTS = array(
		'page.view',
		'button.click',
		'button.render',
		'wallet.created_order',
		'wallet.approved',
		'wallet.error',
		'wallet.cancel',
		'wallet.shipping_change',
		'card.form.ready',
		'card.form.submit',
		'card.form.error',
		'apple_pay.session_start',
		'apple_pay.session_cancel',
		'google_pay.payment_data_request',
		'google_pay.payment_data_error',
		'fastlane.lookup',
		'fastlane.authenticated',
		'fastlane.skipped',
		'pay_for_order.opened',
		'pay_for_order.submitted',
	);

	/** Allowed gateway slugs — keeps unknown values out of the log. */
	private const ALLOWED_GATEWAYS = array(
		'paypal',
		'paypal_card',
		'paypal_apple_pay',
		'paypal_google_pay',
		'paypal_fastlane',
		'paypal_venmo',
		'stripe',
		'stripe_apm',
		'authorize_net',
		'authorize_net_echeck',
		'authorize_net_google_pay',
		'cod',
		'bank_transfer',
		'check',
		'',
	);

	public function init(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'rest_api_init',  array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_script' ) );
	}

	/**
	 * Register the `POST /wp-json/tejcart/v1/debug/telemetry` route.
	 *
	 * SECURITY NOTE: this endpoint is intentionally public (no login
	 * required) because guest checkout must be able to beacon
	 * client-side payment errors. The four-layer defence is:
	 *   1. `tejcart_log_level >= info` gate in permission_callback
	 *   2. `wp_rest` nonce (X-WP-Nonce header, same-origin only)
	 *   3. Per-IP rate limit (10/min, see M-34)
	 *   4. Event-type allowlist + Redactor scrub on every payload
	 *
	 * Open to unauthenticated requests (so guest checkout can beacon) —
	 * the nonce check + rate limit + event allowlist + secret-redactor
	 * combination is the actual security boundary.
	 */
	public function register_route(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route(
			self::NAMESPACE_PATH,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission_callback' ),
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	/**
	 * Gate for the public telemetry endpoint.
	 *
	 * The endpoint is intentionally unauthenticated — guest-checkout
	 * pages need to beacon without a logged-in user — so the real
	 * security boundary is the handler (nonce, rate limit, allowlist).
	 * This callback exists in lieu of __return_true so the registration
	 * site reads as "the gating lives elsewhere" rather than "we forgot
	 * to add one", and so we can short-circuit the request before
	 * dispatch when verbose logging is off (defence-in-depth: a future
	 * refactor that drops the level gate from handle() still won't
	 * accept work).
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		// F-SEC-004: gate at 'info' to match client_logging_enabled() and the
		// documented contract in CLAUDE.md ("tejcart_log_level >= info").
		// The in-handler level check (tejcart_log_level_passes('debug')) still
		// guards actual debug-only emission, so the two-level design is intact:
		//   Level info  → endpoint open, handler ignores non-debug payloads.
		//   Level debug → endpoint open, handler records everything.
		//   Level warn+ → endpoint closed (403), JS beacon is not enqueued.
		return function_exists( 'tejcart_log_level_passes' ) && tejcart_log_level_passes( 'info' );
	}

	/**
	 * Decide whether to enqueue the JS beacon on this pageload. We only
	 * load it when the merchant has actually turned on verbose logging
	 * (level `info` or `debug`) — keeping it off the wire entirely on
	 * default-config stores is both a perf win and a privacy posture.
	 */
	public function maybe_enqueue_script(): void {
		if ( ! function_exists( 'wp_register_script' ) || ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		if ( ! self::client_logging_enabled() ) {
			return;
		}

		$handle = 'tejcart-payment-telemetry';

		$src = function_exists( 'tejcart_asset_url' )
			? tejcart_asset_url( 'assets/js/payment-telemetry.js' )
			: ( defined( 'TEJCART_PLUGIN_URL' )
				? TEJCART_PLUGIN_URL . 'assets/js/payment-telemetry.js'
				: '' );
		if ( '' === $src ) {
			return;
		}

		$version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : null;
		wp_register_script( $handle, $src, array(), $version, true );

		$endpoint = function_exists( 'rest_url' )
			? rest_url( self::NAMESPACE_PATH . self::ROUTE )
			: '';
		$nonce    = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';

		wp_localize_script(
			$handle,
			'TEJCART_PAYMENT_TELEMETRY',
			array(
				'endpoint'      => $endpoint,
				'nonce'         => $nonce,
				'pluginVersion' => defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : 'unknown',
				'allowedEvents' => array_values( self::ALLOWED_EVENTS ),
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * Endpoint callback.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		// Verify the wp_rest nonce sent by the enqueued JS beacon
		// (maybe_enqueue_script() above). The endpoint is unauthenticated
		// so guest checkout can beacon, but we still bind every request
		// to a site-issued nonce so a third-party page cannot drive the
		// endpoint cross-origin.
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			// `navigator.sendBeacon()` — the beacon's preferred transport —
			// cannot set custom headers, so the JS falls back to the
			// WordPress-standard `_wpnonce` request parameter (query string
			// for the sendBeacon path, body for the fetch path). Accept it
			// here so beacons aren't rejected on every modern browser.
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_REST_Response( array( 'status' => 'rejected', 'reason' => 'invalid_nonce' ), 403 );
		}

		// Cheap level-gate check — short-circuit before doing any work
		// when the merchant hasn't turned on verbose logging.
		if ( ! function_exists( 'tejcart_log_level_passes' ) || ! tejcart_log_level_passes( 'debug' ) ) {
			return new \WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'logging_disabled' ), 200 );
		}

		// Per-IP rate limit so a runaway script can't fill the log.
		// 60 events / minute is generous for an honest checkout (1-2 per
		// flow) and unforgiving for a misbehaving page script.
		// Audit M-34 (API M-1): tightened from 60/min/IP to 10/min/IP.
		// A residential-proxy botnet at the old limit could write 60k
		// lines/min into the payment log channel. The JS beacon emits
		// 1-2 events per checkout; 10/min is generous for real usage.
		if ( class_exists( \TejCart\Security\Rate_Limiter::class ) ) {
			$ip = \TejCart\Security\Rate_Limiter::get_client_ip();
			if ( \TejCart\Security\Rate_Limiter::check_and_record( 'payment_telemetry', $ip, 10, 60 ) ) {
				return new \WP_REST_Response( array( 'status' => 'rate_limited' ), 429 );
			}
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$event = isset( $params['event'] ) ? (string) $params['event'] : '';
		if ( ! in_array( $event, self::ALLOWED_EVENTS, true ) ) {
			return new \WP_REST_Response( array( 'status' => 'rejected', 'reason' => 'unknown_event' ), 400 );
		}

		$gateway = isset( $params['gateway'] ) ? strtolower( (string) $params['gateway'] ) : '';
		if ( ! in_array( $gateway, self::ALLOWED_GATEWAYS, true ) ) {
			$gateway = 'unknown';
		}

		$page_url = isset( $params['page_url'] ) ? Request_Context::scrub_uri( (string) $params['page_url'] ) : '';
		$button   = isset( $params['button_id'] ) ? self::sanitize_short( (string) $params['button_id'] ) : '';
		$order_id = isset( $params['order_id'] ) ? (int) $params['order_id'] : 0;

		$extra = array();
		if ( isset( $params['extra'] ) && is_array( $params['extra'] ) ) {
			// Truncate the extra blob hard — clients should not be
			// shovelling raw response bodies through this endpoint.
			$extra = self::sanitize_extra( $params['extra'] );
		}

		$base = Request_Context::base( array(
			'event' => 'telemetry.' . $event,
			'phase' => 'client',
		) );

		\tejcart_logger( 'payment' )
			->with_context( $base )
			->debug(
				sprintf( '[client] %s', $event ),
				array(
					'gateway'   => $gateway,
					'button_id' => $button,
					'page_url'  => $page_url,
					'order_id'  => $order_id,
					'extra'     => $extra,
				)
			);

		return new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Whether the verbose JS beacon should be loaded for this request.
	 * Tied to the existing `tejcart_log_level` option — the JS only ever
	 * fires when the operator has switched logging to `info` or `debug`.
	 */
	public static function client_logging_enabled(): bool {
		if ( ! function_exists( 'tejcart_log_level_passes' ) ) {
			return false;
		}
		// Anything at `info` or `debug` qualifies. We pick `info` as the
		// threshold so merchants who turn the dial up one notch (the
		// common "let's debug this customer's checkout" workflow) get
		// the JS beacon without having to go all the way to `debug`.
		return tejcart_log_level_passes( 'info' );
	}

	/**
	 * Allow the public allowed-events list to be inspected (e.g. by
	 * tests, or by an extension wiring custom telemetry).
	 *
	 * @return array<int, string>
	 */
	public static function allowed_events(): array {
		return self::ALLOWED_EVENTS;
	}

	/**
	 * Sanitize a short identifier (button id, dom id, …). Strips
	 * non-printables and caps at 64 chars.
	 */
	private static function sanitize_short( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $value ) ?? '';
		return substr( $value, 0, 64 );
	}

	/**
	 * Cap the size + depth of the `extra` blob clients may attach.
	 *
	 * @param array<int|string, mixed> $extra
	 * @return array<int|string, mixed>
	 */
	private static function sanitize_extra( array $extra ): array {
		$out   = array();
		$count = 0;
		foreach ( $extra as $k => $v ) {
			if ( $count >= 16 ) {
				break;
			}
			$count++;
			$key = is_string( $k ) ? substr( preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $k ) ?? '', 0, 64 ) : (string) $k;
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $v ) ) {
				// One level of nesting only.
				$out[ $key ] = array();
				$nested      = 0;
				foreach ( $v as $nk => $nv ) {
					if ( $nested >= 16 || ! is_scalar( $nv ) ) {
						continue;
					}
					$nested++;
					$out[ $key ][ (string) $nk ] = is_string( $nv ) ? substr( $nv, 0, 256 ) : $nv;
				}
				continue;
			}
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $key ] = is_string( $v ) ? substr( $v, 0, 256 ) : $v;
			}
		}
		return $out;
	}
}
