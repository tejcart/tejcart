<?php
/**
 * Channel registry — the public entry point for module-scoped logging.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger facade.
 *
 * Resolves a {@see Log_Channel} per module slug so every concern in
 * TejCart writes to its own file under `{uploads}/tejcart-logs/`. The
 * registry is cached so repeated calls for the same channel reuse the
 * same correlation id, which is what lets a payment capture flow have
 * a single id from `Place order` through to `Webhook reconciled`.
 *
 * Canonical channels (see {@see canonical_channels()}):
 *
 *   - `payment`   — gateway dispatch, request/response pairs,
 *                   capture/refund, vault token rotation. Sub-channel
 *                   per provider (`payment_paypal`, `payment_stripe`).
 *   - `tax`       — Tax_Manager pipeline and live provider calls.
 *                   Sub-channel per provider (`tax_taxjar`, ...).
 *   - `shipping`  — Shipping_Manager + carrier driver calls.
 *                   Sub-channel per provider (`shipping_fedex`, ...).
 *   - `discount`  — coupon evaluation, eligibility, rollback.
 *   - `cart`      — cart mutation, stock reservation, abandoned cart.
 *   - `checkout`  — validation, place_order pipeline.
 *   - `order`     — lifecycle transitions, refunds, invoicing.
 *   - `webhook`   — inbound webhook verification + dispatch.
 *   - `api`       — REST API requests / rate limiting.
 *   - `cron`      — Action Scheduler job execution.
 *
 * Add-ons MAY register additional channels by simply calling
 * `Logger::channel( 'subscription' )` — the file is created lazily on
 * first write. Channel names are sanitized through `sanitize_key()`
 * (alphanum + underscore + dash) and are not user-controlled.
 */
final class Logger {

	/** @var array<string, Log_Channel> */
	private array $channels = array();

	private static ?Logger $instance = null;

	/** Process-wide singleton. */
	public static function instance(): Logger {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Replace the process-wide singleton.
	 *
	 * #1201: this is the test-substitution seam — production code goes
	 * through `instance()`, but tests / sibling plugins can swap a
	 * fake via `set_instance( $mockLogger )`. Pass null to reset to
	 * lazy construction on the next `instance()` call.
	 *
	 * Container-friendly: `tejcart()->container()->singleton( 'logger', $mock )`
	 * patterns can call this from their factory closure.
	 *
	 * @internal Use in tests and DI overrides only.
	 */
	public static function set_instance( ?Logger $logger ): void {
		self::$instance = $logger;
	}

	/**
	 * Resolve a channel by name. Repeated calls return the same
	 * instance (so correlation ids persist within a request).
	 *
	 * @param string $name Channel name. Sanitized to `[a-z0-9_-]`.
	 */
	public static function channel( string $name ): Log_Channel {
		return self::instance()->get( $name );
	}

	/**
	 * Resolve (and cache) a channel.
	 *
	 * @param string $name Channel name.
	 */
	public function get( string $name ): Log_Channel {
		$key = function_exists( 'sanitize_key' )
			? sanitize_key( $name )
			: strtolower( preg_replace( '/[^a-z0-9_\-]+/i', '_', $name ) ?? '' );
		if ( '' === $key ) {
			$key = 'tejcart';
		}

		if ( ! isset( $this->channels[ $key ] ) ) {
			$this->channels[ $key ] = new Log_Channel( $key );
		}
		return $this->channels[ $key ];
	}

	/**
	 * Replace a channel — useful in tests to swap in a recording fake.
	 *
	 * @internal
	 */
	public function set( string $name, Log_Channel $channel ): void {
		$key = function_exists( 'sanitize_key' )
			? sanitize_key( $name )
			: strtolower( preg_replace( '/[^a-z0-9_\-]+/i', '_', $name ) ?? '' );
		if ( '' === $key ) {
			$key = 'tejcart';
		}
		$this->channels[ $key ] = $channel;
	}

	/**
	 * List the canonical channel names TejCart writes to out of the box.
	 *
	 * Returned in dependency order (payment depends on cart/checkout,
	 * which depend on cart, etc.). Extensions can append via the
	 * `tejcart_logging_channels` filter.
	 *
	 * @return array<int, string>
	 */
	public static function canonical_channels(): array {
		$channels = array(
			'tejcart',
			'cart',
			'checkout',
			'order',
			'payment',
			'discount',
			'tax',
			'shipping',
			'webhook',
			'api',
			'cron',
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the canonical log-channel list.
			 *
			 * @since 1.0.0
			 * @param array<int, string> $channels Default canonical channels.
			 */
			$filtered = apply_filters( 'tejcart_logging_channels', $channels );
			if ( is_array( $filtered ) ) {
				$channels = array_values( array_unique( array_filter( array_map(
					static fn( $c ): string => is_string( $c ) ? $c : '',
					$filtered
				) ) ) );
			}
		}

		return $channels;
	}

	/**
	 * Reset the singleton. Test-only.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
