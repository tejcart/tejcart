<?php
/**
 * Payment debug observer — one stop for payment-flow telemetry.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscribes to every payment-lifecycle hook fired by core, the three
 * standalone addons (Stripe, Authorize.Net, Subscriptions), and the six
 * bundled modules, and writes a single
 * canonically-shaped debug entry per event on the `payment` channel.
 *
 * Why an observer instead of inline `tejcart_log()` calls in 30 files:
 *
 *   1. Existing payment code already fires `do_action()` at every state
 *      transition (`tejcart_checkout_process`, `tejcart_payment_complete`,
 *      `tejcart_paypal_webhook_unhandled_event`, ...). Hooking those is
 *      additive — we don't touch the hot paths.
 *   2. Support engineers get a single, predictable line shape per event:
 *      timestamp, level, message, then a JSON blob with
 *      `plugin_version`, `request_uri`, `gateway`, `order_id`, `user_id`.
 *   3. Addons that follow the `tejcart_*_payment_*` / `tejcart_*_webhook_*`
 *      convention are picked up automatically once they fire the hook.
 *
 * Volume / cost: each handler runs in microseconds and short-circuits at
 * the `tejcart_log_level` gate when the level is `error` (the production
 * default). On a production store running with the default log level
 * NONE of these handlers ever write to disk — they just dispatch through
 * the existing `Log_Channel` which already gates at the threshold.
 */
final class Payment_Debug_Logger {

	/**
	 * Channel everything writes to. Sub-events stay on the same channel
	 * so support can `tail -f payment-*.log` and see the full flow
	 * without having to reconcile multiple files.
	 */
	private const CHANNEL = 'payment';

	/** Lifecycle phase identifiers — written to the `phase` context field. */
	private const PHASE_CHECKOUT = 'checkout';
	private const PHASE_ORDER    = 'order';
	private const PHASE_GATEWAY  = 'gateway';
	private const PHASE_WEBHOOK  = 'webhook';
	private const PHASE_REFUND   = 'refund';
	private const PHASE_DISPUTE  = 'dispute';

	/**
	 * Wire every observed hook. Idempotent.
	 *
	 * Called by the feature-binding map in
	 * {@see \TejCart\Core\TejCart::default_feature_bindings()}.
	 */
	public function init(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		// Checkout lifecycle ------------------------------------------------
		add_action( 'tejcart_before_checkout_form',           array( $this, 'on_before_checkout_form' ), 5 );
		add_action( 'tejcart_checkout_process',               array( $this, 'on_checkout_process' ), 5 );
		add_action( 'tejcart_checkout_validation',            array( $this, 'on_checkout_validation' ), 5, 1 );
		add_action( 'tejcart_checkout_validate_cart_prices',  array( $this, 'on_checkout_validate_cart_prices' ), 5, 1 );
		add_action( 'tejcart_checkout_update_order_meta',     array( $this, 'on_checkout_update_order_meta' ), 5, 2 );
		add_action( 'tejcart_checkout_order_processed',       array( $this, 'on_checkout_order_processed' ), 5, 2 );
		add_action( 'tejcart_checkout_payment_failed',        array( $this, 'on_checkout_payment_failed' ), 5, 2 );

		// Order lifecycle ---------------------------------------------------
		add_action( 'tejcart_new_order',          array( $this, 'on_new_order' ), 5, 1 );
		add_action( 'tejcart_order_created',      array( $this, 'on_order_created' ), 5, 1 );
		add_action( 'tejcart_order_status_changed', array( $this, 'on_order_status_changed' ), 5, 3 );

		// Payment lifecycle -------------------------------------------------
		// NOTE: `tejcart_payment_gateways_initialized` is intentionally NOT
		// observed here. The action fires on every WordPress request (via
		// `Gateway_Registry::init()` on the `init` hook) including
		// /favicon.ico and unrelated REST calls, so emitting a per-request
		// debug line provides no payment-flow signal — just noise. The
		// registered-gateway list is static and visible in System Status.
		add_action( 'tejcart_before_payment',        array( $this, 'on_before_payment' ), 5, 2 );
		add_action( 'tejcart_payment_complete',      array( $this, 'on_payment_complete' ), 5, 3 );
		add_action( 'tejcart_payment_failed',        array( $this, 'on_payment_failed' ), 5, 3 );

		// PayPal-specific ---------------------------------------------------
		add_action( 'tejcart_paypal_webhook_unhandled_event', array( $this, 'on_paypal_webhook_unhandled' ), 5, 2 );
		add_action( 'tejcart_paypal_vault_event',             array( $this, 'on_paypal_vault_event' ), 5, 3 );
		add_action( 'tejcart_paypal_subscription_event',      array( $this, 'on_paypal_subscription_event' ), 5, 3 );
		add_action( 'tejcart_paypal_dispute_created',         array( $this, 'on_paypal_dispute_created' ), 5, 3 );
		add_action( 'tejcart_paypal_dispute_updated',         array( $this, 'on_paypal_dispute_updated' ), 5, 3 );
		add_action( 'tejcart_paypal_dispute_resolved',        array( $this, 'on_paypal_dispute_resolved' ), 5, 4 );
		add_action( 'tejcart_paypal_event_dead_letter',       array( $this, 'on_paypal_event_dead_letter' ), 5, 2 );
		add_action( 'tejcart_paypal_id_collision',            array( $this, 'on_paypal_id_collision' ), 5, 3 );
		add_action( 'tejcart_paypal_liability_shift_no',      array( $this, 'on_paypal_liability_shift_no' ), 5, 2 );

		// Stripe addon ------------------------------------------------------
		add_action( 'tejcart_stripe_webhook_event',       array( $this, 'on_stripe_webhook_event' ), 5, 3 );
		add_action( 'tejcart_stripe_webhook_processed',   array( $this, 'on_stripe_webhook_processed' ), 5, 2 );
		add_action( 'tejcart_stripe_dispute_created',     array( $this, 'on_stripe_dispute_created' ), 5, 2 );
		add_action( 'tejcart_stripe_dispute_closed',      array( $this, 'on_stripe_dispute_closed' ), 5, 2 );
		add_action( 'tejcart_stripe_secret_decrypt_failed', array( $this, 'on_stripe_secret_decrypt_failed' ), 5 );

		// Authorize.Net addon ----------------------------------------------
		add_action( 'tejcart_authnet_webhook_event',        array( $this, 'on_authnet_webhook_event' ), 5, 2 );
		add_action( 'tejcart_authnet_webhook_processed',    array( $this, 'on_authnet_webhook_processed' ), 5, 2 );
		add_action( 'tejcart_authnet_fraud_held',           array( $this, 'on_authnet_fraud_held' ), 5, 2 );
		add_action( 'tejcart_authnet_subscription_event',   array( $this, 'on_authnet_subscription_event' ), 5, 3 );

		// Refunds & disputes (generic) -------------------------------------
		add_action( 'tejcart_refund_created',         array( $this, 'on_refund_created' ), 5, 2 );
		add_action( 'tejcart_partial_refund_created', array( $this, 'on_partial_refund_created' ), 5, 2 );
		add_action( 'tejcart_order_refund_created',   array( $this, 'on_order_refund_created' ), 5, 2 );
		add_action( 'tejcart_refund_inconsistency',   array( $this, 'on_refund_inconsistency' ), 5, 2 );
	}

	// -------------------------------------------------------------------
	// Checkout phase
	// -------------------------------------------------------------------

	public function on_before_checkout_form(): void {
		$this->emit( 'checkout.form.rendered', self::PHASE_CHECKOUT );
	}

	public function on_checkout_process(): void {
		$this->emit( 'checkout.process.start', self::PHASE_CHECKOUT, array(
			'selected_gateway' => $this->posted_gateway_id(),
		) );
	}

	/**
	 * @param array<string, mixed> $posted_data Sanitized posted data.
	 */
	public function on_checkout_validation( $posted_data ): void {
		$posted = is_array( $posted_data ) ? $posted_data : array();
		$this->emit( 'checkout.validation', self::PHASE_CHECKOUT, array(
			'selected_gateway' => $this->posted_gateway_id(),
			'shipping_method'  => isset( $posted['tejcart_shipping_method'] )
				? (string) $posted['tejcart_shipping_method']
				: '',
			'billing_country'  => isset( $posted['billing_country'] ) ? (string) $posted['billing_country'] : '',
			'shipping_country' => isset( $posted['shipping_country'] ) ? (string) $posted['shipping_country'] : '',
			'create_account'   => ! empty( $posted['create_account'] ),
		) );
	}

	public function on_checkout_validate_cart_prices( $cart ): void {
		$total = is_object( $cart ) && method_exists( $cart, 'get_total' )
			? (string) $cart->get_total()
			: '';
		$items = is_object( $cart ) && method_exists( $cart, 'get_items' )
			? count( (array) $cart->get_items() )
			: 0;
		$this->emit( 'checkout.cart_prices_validated', self::PHASE_CHECKOUT, array(
			'cart_total' => $total,
			'item_count' => $items,
		) );
	}

	/**
	 * @param int                  $order_id    Newly created order id.
	 * @param array<string, mixed> $posted_data Posted checkout data.
	 */
	public function on_checkout_update_order_meta( $order_id, $posted_data ): void {
		$posted = is_array( $posted_data ) ? $posted_data : array();
		$this->emit( 'checkout.order_meta_saved', self::PHASE_CHECKOUT, array(
			'order_id'        => (int) $order_id,
			'gateway'         => $this->posted_gateway_id(),
			'shipping_method' => isset( $posted['tejcart_shipping_method'] )
				? (string) $posted['tejcart_shipping_method']
				: '',
		) );
	}

	public function on_checkout_order_processed( $order_id, $posted_data ): void {
		$this->emit( 'checkout.order_processed', self::PHASE_CHECKOUT, array(
			'order_id' => (int) $order_id,
			'gateway'  => $this->posted_gateway_id(),
		) );
	}

	public function on_checkout_payment_failed( $order_id, $error ): void {
		$message = '';
		$code    = '';
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = (string) $error->get_error_message();
		}
		if ( is_object( $error ) && method_exists( $error, 'get_error_code' ) ) {
			$code = (string) $error->get_error_code();
		}
		$this->emit( 'checkout.payment_failed', self::PHASE_CHECKOUT, array(
			'order_id'   => (int) $order_id,
			'gateway'    => $this->posted_gateway_id(),
			'error_code' => $code,
			'error'      => $message,
		), 'warning' );
	}

	// -------------------------------------------------------------------
	// Order phase
	// -------------------------------------------------------------------

	public function on_new_order( $order ): void {
		$this->emit( 'order.created', self::PHASE_ORDER, $this->order_summary( $order ) );
	}

	public function on_order_created( $order ): void {
		$this->emit( 'order.created.legacy_hook', self::PHASE_ORDER, $this->order_summary( $order ) );
	}

	public function on_order_status_changed( $old_status, $new_status, $order = null ): void {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$this->emit( 'order.status_changed', self::PHASE_ORDER, array(
			'order_id'   => $order_id,
			'old_status' => (string) $old_status,
			'new_status' => (string) $new_status,
			'gateway'    => $this->gateway_for_order( $order, $order_id ),
		) );
	}

	// -------------------------------------------------------------------
	// Payment / gateway phase
	// -------------------------------------------------------------------

	public function on_before_payment( $order_id, $order = null ): void {
		// `tejcart_before_payment` fires as ( $order_id, $order ) from every
		// gateway's process_payment(); the second arg is the order, not the
		// gateway. Resolve the gateway off the order's payment method (the
		// same path the sibling payment_complete / payment_failed handlers
		// use) instead of calling get_id() on the order — which returned the
		// order ID and mislabelled the gateway as e.g. "12" in the log.
		$this->emit( 'gateway.dispatch', self::PHASE_GATEWAY, array(
			'order_id' => (int) $order_id,
			'gateway'  => $this->gateway_for_order( $order, (int) $order_id ),
		) );
	}

	public function on_payment_complete( $order_id, $order = null, $transaction_id = '' ): void {
		$this->emit( 'gateway.payment_complete', self::PHASE_GATEWAY, array(
			'order_id'       => (int) $order_id,
			'gateway'        => $this->gateway_for_order( $order, (int) $order_id ),
			'transaction_id' => self::redact_transaction_id( (string) $transaction_id ),
		) );
	}

	public function on_payment_failed( $order_id, $order = null, $transaction_id = '' ): void {
		$this->emit( 'gateway.payment_failed', self::PHASE_GATEWAY, array(
			'order_id'       => (int) $order_id,
			'gateway'        => $this->gateway_for_order( $order, (int) $order_id ),
			'transaction_id' => self::redact_transaction_id( (string) $transaction_id ),
		), 'warning' );
	}

	// -------------------------------------------------------------------
	// PayPal webhook phase
	// -------------------------------------------------------------------

	public function on_paypal_webhook_unhandled( $event_type, $event ): void {
		$this->emit( 'webhook.paypal.unhandled', self::PHASE_WEBHOOK, array(
			'gateway'    => 'paypal',
			'event_type' => (string) $event_type,
			'event_id'   => is_array( $event ) && isset( $event['id'] ) ? self::redact_transaction_id( (string) $event['id'] ) : '',
		), 'notice' );
	}

	public function on_paypal_vault_event( $event_type, $token_id, $resource ): void {
		$this->emit( 'webhook.paypal.vault', self::PHASE_WEBHOOK, array(
			'gateway'    => 'paypal',
			'event_type' => (string) $event_type,
			'token_id'   => self::redact_transaction_id( (string) $token_id ),
		) );
	}

	public function on_paypal_subscription_event( $event_type, $subscription_id, $resource ): void {
		$this->emit( 'webhook.paypal.subscription', self::PHASE_WEBHOOK, array(
			'gateway'         => 'paypal',
			'event_type'      => (string) $event_type,
			'subscription_id' => self::redact_transaction_id( (string) $subscription_id ),
		) );
	}

	public function on_paypal_dispute_created( $order_id, $dispute_id, $resource ): void {
		$this->emit( 'dispute.paypal.created', self::PHASE_DISPUTE, array(
			'gateway'    => 'paypal',
			'order_id'   => (int) $order_id,
			'dispute_id' => self::redact_transaction_id( (string) $dispute_id ),
		), 'warning' );
	}

	public function on_paypal_dispute_updated( $order_id, $dispute_id, $resource ): void {
		$this->emit( 'dispute.paypal.updated', self::PHASE_DISPUTE, array(
			'gateway'    => 'paypal',
			'order_id'   => (int) $order_id,
			'dispute_id' => self::redact_transaction_id( (string) $dispute_id ),
		) );
	}

	public function on_paypal_dispute_resolved( $order_id, $dispute_id, $outcome, $resource ): void {
		$this->emit( 'dispute.paypal.resolved', self::PHASE_DISPUTE, array(
			'gateway'    => 'paypal',
			'order_id'   => (int) $order_id,
			'dispute_id' => self::redact_transaction_id( (string) $dispute_id ),
			'outcome'    => (string) $outcome,
		) );
	}

	public function on_paypal_event_dead_letter( $row_id, $reason ): void {
		$this->emit( 'webhook.paypal.dead_letter', self::PHASE_WEBHOOK, array(
			'gateway' => 'paypal',
			'row_id'  => (int) $row_id,
			'reason'  => (string) $reason,
		), 'error' );
	}

	public function on_paypal_id_collision( $paypal_id, $existing_order_id, $incoming_order_id ): void {
		$this->emit( 'webhook.paypal.id_collision', self::PHASE_WEBHOOK, array(
			'gateway'           => 'paypal',
			'paypal_id'         => self::redact_transaction_id( (string) $paypal_id ),
			'existing_order_id' => (int) $existing_order_id,
			'incoming_order_id' => (int) $incoming_order_id,
		), 'warning' );
	}

	public function on_paypal_liability_shift_no( $order_id, $resource ): void {
		$this->emit( 'gateway.paypal.no_liability_shift', self::PHASE_GATEWAY, array(
			'gateway'  => 'paypal',
			'order_id' => (int) $order_id,
		), 'notice' );
	}

	// -------------------------------------------------------------------
	// Stripe webhook phase
	// -------------------------------------------------------------------

	public function on_stripe_webhook_event( $event_type, $object, $event ): void {
		$this->emit( 'webhook.stripe.event', self::PHASE_WEBHOOK, array(
			'gateway'    => 'stripe',
			'event_type' => (string) $event_type,
			'event_id'   => is_array( $event ) && isset( $event['id'] ) ? self::redact_transaction_id( (string) $event['id'] ) : '',
		) );
	}

	public function on_stripe_webhook_processed( $event_type, $event ): void {
		$this->emit( 'webhook.stripe.processed', self::PHASE_WEBHOOK, array(
			'gateway'    => 'stripe',
			'event_type' => (string) $event_type,
			'event_id'   => is_array( $event ) && isset( $event['id'] ) ? self::redact_transaction_id( (string) $event['id'] ) : '',
		) );
	}

	public function on_stripe_dispute_created( $dispute, $order ): void {
		$this->emit( 'dispute.stripe.created', self::PHASE_DISPUTE, array(
			'gateway'    => 'stripe',
			'order_id'   => is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
			'dispute_id' => is_array( $dispute ) && isset( $dispute['id'] ) ? self::redact_transaction_id( (string) $dispute['id'] ) : '',
		), 'warning' );
	}

	public function on_stripe_dispute_closed( $dispute, $order ): void {
		$this->emit( 'dispute.stripe.closed', self::PHASE_DISPUTE, array(
			'gateway'    => 'stripe',
			'order_id'   => is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
			'dispute_id' => is_array( $dispute ) && isset( $dispute['id'] ) ? self::redact_transaction_id( (string) $dispute['id'] ) : '',
		) );
	}

	public function on_stripe_secret_decrypt_failed(): void {
		$this->emit( 'gateway.stripe.secret_decrypt_failed', self::PHASE_GATEWAY, array(
			'gateway' => 'stripe',
		), 'error' );
	}

	// -------------------------------------------------------------------
	// Authorize.Net webhook phase
	// -------------------------------------------------------------------

	public function on_authnet_webhook_event( $event_type, $event ): void {
		$this->emit( 'webhook.authnet.event', self::PHASE_WEBHOOK, array(
			'gateway'    => 'authorize_net',
			'event_type' => (string) $event_type,
		) );
	}

	public function on_authnet_webhook_processed( $event_type, $event ): void {
		$this->emit( 'webhook.authnet.processed', self::PHASE_WEBHOOK, array(
			'gateway'    => 'authorize_net',
			'event_type' => (string) $event_type,
		) );
	}

	public function on_authnet_fraud_held( $order_id, $event ): void {
		$this->emit( 'gateway.authnet.fraud_held', self::PHASE_GATEWAY, array(
			'gateway'  => 'authorize_net',
			'order_id' => (int) $order_id,
		), 'warning' );
	}

	public function on_authnet_subscription_event( $event_type, $subscription_id, $event ): void {
		$this->emit( 'webhook.authnet.subscription', self::PHASE_WEBHOOK, array(
			'gateway'         => 'authorize_net',
			'event_type'      => (string) $event_type,
			'subscription_id' => self::redact_transaction_id( (string) $subscription_id ),
		) );
	}

	// -------------------------------------------------------------------
	// Refunds
	// -------------------------------------------------------------------

	/**
	 * Hooked to `tejcart_refund_created`, which fires with
	 * `( $order_id, $amount, $reason, $order )` — there is no refund id at
	 * this point, only the order being refunded.
	 */
	public function on_refund_created( $order_id, $amount = null, $reason = '', $order = null ): void {
		$this->emit( 'refund.created', self::PHASE_REFUND, array(
			'order_id' => (int) $order_id,
		) );
	}

	/**
	 * Hooked to `tejcart_partial_refund_created`, which fires with
	 * `( $order_id, $refund, $order )` where `$refund` is the
	 * {@see Order_Refund} object.
	 */
	public function on_partial_refund_created( $order_id, $refund = null, $order = null ): void {
		$this->emit( 'refund.partial_created', self::PHASE_REFUND, array(
			'refund_id' => self::refund_id( $refund ),
			'order_id'  => (int) $order_id,
		) );
	}

	/**
	 * Hooked to `tejcart_order_refund_created`, which fires with
	 * `( $refund, $order_id, $reason )` where `$refund` is the
	 * {@see Order_Refund} object.
	 */
	public function on_order_refund_created( $refund = null, $order_id = 0, $reason = '' ): void {
		$this->emit( 'refund.order_refund_created', self::PHASE_REFUND, array(
			'refund_id' => self::refund_id( $refund ),
			'order_id'  => (int) $order_id,
		) );
	}

	/**
	 * Coerce a refund argument — which may be an {@see Order_Refund} object
	 * or a scalar id — into an integer refund id without tripping an
	 * "object could not be converted to int" warning.
	 *
	 * @param mixed $refund Order_Refund instance or scalar id.
	 */
	private static function refund_id( $refund ): int {
		if ( $refund instanceof \TejCart\Order\Order_Refund ) {
			return (int) $refund->id;
		}
		return is_scalar( $refund ) ? (int) $refund : 0;
	}

	public function on_refund_inconsistency( $order_id, $reason ): void {
		$this->emit( 'refund.inconsistency', self::PHASE_REFUND, array(
			'order_id' => (int) $order_id,
			'reason'   => (string) $reason,
		), 'error' );
	}

	// -------------------------------------------------------------------
	// Shared emitter
	// -------------------------------------------------------------------

	/**
	 * Write a single payment-debug line.
	 *
	 * Always passes through the {@see Log_Channel} pipeline so the existing
	 * `tejcart_log_level` gate, the `tejcart_log_writer` injection point,
	 * and the {@see Redactor} all apply unmodified.
	 *
	 * @param string               $event   Stable event identifier
	 *                                      (e.g. `gateway.payment_complete`).
	 * @param string               $phase   Lifecycle phase: checkout / order /
	 *                                      gateway / webhook / refund / dispute.
	 * @param array<string, mixed> $context Event-specific fields.
	 * @param string               $level   PSR-3 level. Defaults to `debug` —
	 *                                      production sites running at the
	 *                                      `error` floor will drop these at
	 *                                      the gate.
	 */
	private function emit( string $event, string $phase, array $context = array(), string $level = 'debug' ): void {
		if ( ! function_exists( 'tejcart_log_level_passes' ) || ! tejcart_log_level_passes( $level ) ) {
			return;
		}

		$base = Request_Context::base( array(
			'event' => $event,
			'phase' => $phase,
		) );

		$channel = \tejcart_logger( self::CHANNEL )->with_context( $base );

		$message = sprintf( '[%s] %s', $phase, $event );
		$channel->log( $level, $message, $context );
	}

	/**
	 * Read the buyer's selected gateway off the place-order POST without
	 * relying on a nonce (the AJAX entry validates it upstream).
	 */
	private function posted_gateway_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- debug logging only, no state change.
		if ( empty( $_POST['tejcart_payment_method'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- debug logging only, no state change.
		$raw = sanitize_text_field( wp_unslash( $_POST['tejcart_payment_method'] ) );
		return function_exists( 'sanitize_text_field' )
			? (string) sanitize_text_field( (string) $raw )
			: substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw ) ?? '', 0, 64 );
	}

	/**
	 * Try several common shapes to recover the gateway id from an order
	 * reference. Returns the gateway slug or '' if not resolvable.
	 *
	 * @param mixed $order    Order instance, id, or null.
	 * @param int   $order_id Fallback id.
	 */
	private function gateway_for_order( $order, int $order_id ): string {
		if ( is_object( $order ) ) {
			if ( method_exists( $order, 'get_payment_method' ) ) {
				return (string) $order->get_payment_method();
			}
			if ( method_exists( $order, 'get_gateway' ) ) {
				return (string) $order->get_gateway();
			}
		}
		if ( $order_id > 0 && function_exists( 'tejcart_get_order' ) ) {
			$resolved = tejcart_get_order( $order_id );
			if ( is_object( $resolved ) && method_exists( $resolved, 'get_payment_method' ) ) {
				return (string) $resolved->get_payment_method();
			}
		}
		return '';
	}

	/**
	 * Build a small, redacted summary of an order suitable for a debug
	 * log line. Never dumps the full order object — buyer addresses are
	 * captured elsewhere, and the wider payload would explode the log.
	 *
	 * @param mixed $order Order instance.
	 * @return array<string, mixed>
	 */
	private function order_summary( $order ): array {
		if ( ! is_object( $order ) ) {
			return array( 'order_id' => is_numeric( $order ) ? (int) $order : 0 );
		}
		$id     = method_exists( $order, 'get_id' )             ? (int) $order->get_id()             : 0;
		$total  = method_exists( $order, 'get_total' )          ? (string) $order->get_total()       : '';
		$ccy    = method_exists( $order, 'get_currency' )       ? (string) $order->get_currency()    : '';
		$status = method_exists( $order, 'get_status' )         ? (string) $order->get_status()      : '';
		$method = method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '';
		return array(
			'order_id'       => $id,
			'order_total'    => $total,
			'order_currency' => $ccy,
			'order_status'   => $status,
			'gateway'        => $method,
		);
	}

	/**
	 * Mask all but the final 6 characters of a gateway transaction id.
	 * Preserves enough tail to correlate with the gateway dashboard
	 * without exposing the full id (some carry buyer-identifiable
	 * suffixes per gateway docs).
	 *
	 * Mirrors the legacy `Security\Log_Redactor::transaction_id()` to
	 * keep call sites portable.
	 *
	 * @param string $id Raw transaction id.
	 */
	public static function redact_transaction_id( string $id ): string {
		$id = trim( $id );
		if ( '' === $id ) {
			return '';
		}
		if ( strlen( $id ) <= 6 ) {
			return str_repeat( '*', strlen( $id ) );
		}
		return str_repeat( '*', strlen( $id ) - 6 ) . substr( $id, -6 );
	}
}
