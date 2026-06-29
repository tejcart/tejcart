<?php
/**
 * ISO 4217 currency precision lookup.
 *
 * @package TejCart\Money
 */

declare( strict_types = 1 );

namespace TejCart\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper that exposes the number of fractional digits each ISO 4217
 * currency uses. Almost every currency has 2 minor-unit digits; the
 * exceptions are JPY/KRW/CLP (0 decimals) and a handful of Gulf and
 * North-African currencies (3 decimals).
 *
 * Source: ISO 4217 amendment 169 (2024); double-checked against the
 * currency tables Stripe and PayPal publish for their REST APIs so any
 * gateway integration sees the same precision TejCart does.
 */
final class Currency {
	/**
	 * Currencies whose minor units are 1 (i.e. zero fractional digits).
	 *
	 * @var array<int, string>
	 */
	private const ZERO_DECIMAL = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
		'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Currencies whose minor units are 1000 (three fractional digits).
	 *
	 * @var array<int, string>
	 */
	private const THREE_DECIMAL = array(
		'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
	);

	/**
	 * Number of fractional digits for the given ISO 4217 code.
	 *
	 * Anything not in the zero/three lists is assumed two-decimal, which
	 * covers the overwhelming majority of currencies. Unknown codes
	 * default to two as well — better to render `12.34 XYZ` than to
	 * silently hide cents.
	 *
	 * @param string $currency Three-letter ISO 4217 code.
	 * @return int 0, 2, or 3.
	 */
	public static function decimals( string $currency ): int {
		$code = strtoupper( $currency );
		if ( in_array( $code, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		if ( in_array( $code, self::THREE_DECIMAL, true ) ) {
			return 3;
		}
		return 2;
	}

	/**
	 * 10^decimals — the multiplier between major and minor units.
	 *
	 * @param string $currency Three-letter ISO 4217 code.
	 * @return int 1, 100, or 1000.
	 */
	public static function multiplier( string $currency ): int {
		return (int) ( 10 ** self::decimals( $currency ) );
	}

	/**
	 * Re-denominate an integer minor-unit amount from a transacted
	 * currency into the store base currency for consolidated reporting.
	 *
	 * `$fx_rate` is the base→transacted rate (1 base unit = $fx_rate
	 * transacted units), the same convention the Currency Switcher stores
	 * on the order (`fx_rate` column / `_tejcart_csw_fx_rate` meta): a USD
	 * base store displaying EUR at 0.84 records `fx_rate = 0.84`, so a EUR
	 * amount divides by it to recover USD. The conversion is multiplier-
	 * aware so a JPY (0-decimal) ↔ USD (2-decimal) pair re-scales
	 * correctly rather than off by 100×.
	 *
	 * Identity is exact: when the currencies match and `$fx_rate` is 1
	 * (single-currency stores, base-currency orders, Mode-B checkout) the
	 * input minor amount is returned unchanged.
	 *
	 * @param int    $minor             Amount in the transacted currency's minor units.
	 * @param string $transacted_currency Currency the amount is denominated in.
	 * @param string $base_currency       Store base currency (falls back to transacted when empty).
	 * @param float  $fx_rate             base→transacted rate; non-positive coerces to 1.
	 * @return int Amount in the base currency's minor units.
	 */
	public static function to_base_minor( int $minor, string $transacted_currency, string $base_currency, float $fx_rate ): int {
		if ( $fx_rate <= 0.0 ) {
			$fx_rate = 1.0;
		}
		$base_currency = '' !== trim( $base_currency ) ? $base_currency : $transacted_currency;

		// Fast exact path for the common identity case.
		if ( 1.0 === $fx_rate && strtoupper( $transacted_currency ) === strtoupper( $base_currency ) ) {
			return $minor;
		}

		$txn_mult  = self::multiplier( $transacted_currency );
		$base_mult = self::multiplier( $base_currency );
		if ( $txn_mult <= 0 ) {
			$txn_mult = 1;
		}

		$major_base = ( $minor / $txn_mult ) / $fx_rate;

		return (int) round( $major_base * $base_mult, 0, PHP_ROUND_HALF_EVEN );
	}

	/**
	 * Convert a major-unit amount to integer minor units using banker's
	 * rounding (PHP_ROUND_HALF_EVEN, matching the {@see Money} class's
	 * convention).
	 *
	 * Use this for every reconciliation comparison — order total vs.
	 * captured amount, refund cap check, webhook amount vs. local
	 * total, etc. Float subtraction with a 0.01 tolerance is unsafe
	 * for currencies with three decimals (KWD, BHD, OMR) and
	 * meaningless for zero-decimal currencies (JPY, KRW); integer
	 * comparison in minor units is exact across the matrix.
	 *
	 * @param float|string $amount   Major-unit amount.
	 * @param string       $currency ISO 4217 code.
	 * @return int Minor units, e.g. `12.34 USD` → `1234`, `1000 JPY` → `1000`,
	 *             `1.234 KWD` → `1234`.
	 */
	public static function to_minor_units( $amount, string $currency ): int {
		$multiplier = self::multiplier( $currency );
		return (int) round( ( (float) $amount ) * $multiplier, 0, PHP_ROUND_HALF_EVEN );
	}

	/**
	 * Convert integer minor units to a major-unit float for display or
	 * back-compat consumers. Inverse of {@see to_minor_units}.
	 *
	 * Use this when reading SUM aggregates (BIGINT minor units) before
	 * passing to {@see tejcart_price()} or any consumer that expects
	 * major-unit floats. New arithmetic code should stay in integer
	 * minor units and convert only at the display edge.
	 *
	 * @param int    $minor    Integer minor units.
	 * @param string $currency ISO 4217 code.
	 * @return float Major units, e.g. (1234, 'USD') → 12.34, (1000, 'JPY') → 1000.0.
	 */
	public static function from_minor_units( int $minor, string $currency ): float {
		$multiplier = self::multiplier( $currency );
		if ( $multiplier <= 1 ) {
			return (float) $minor;
		}
		return $minor / $multiplier;
	}

	/**
	 * Validate an ISO 4217 code's shape (three uppercase letters).
	 *
	 * Doesn't verify the code is in the official list — gateways and
	 * extensions occasionally need cryptocurrencies or test codes.
	 *
	 * @param string $currency Code to check.
	 * @return bool
	 */
	public static function is_valid_shape( string $currency ): bool {
		return 1 === preg_match( '/^[A-Z]{3}$/', $currency );
	}
}
