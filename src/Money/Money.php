<?php
/**
 * Money value object — the canonical money representation for TejCart.
 *
 * @package TejCart\Money
 */

declare( strict_types = 1 );

namespace TejCart\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable money value tied to an ISO 4217 currency.
 *
 * The amount is stored as a signed integer in the currency's minor unit
 * (cents for USD/EUR, yen for JPY, mils for KWD/BHD, etc.). All arithmetic
 * is exact — no floats are involved — so totals, refunds, and aggregations
 * never drift across reconciliation reports the way they do under
 * `DECIMAL` columns coerced through PHP `float`.
 *
 * Two Money values from different currencies cannot be added; attempts
 * throw `\InvalidArgumentException` rather than silently producing a
 * wrong number.
 *
 * Float input is intentionally not accepted as a public construction
 * path — the whole point of this class is to keep float drift out of
 * the calculation pipeline. Use {@see Money::from_decimal_string} when
 * the source is a decimal string (e.g. an admin-entered price), or
 * {@see Money::from_minor_units} when the source is already an integer.
 *
 * Inspired by Stripe's `Amount`, the `moneyphp/money` library, and
 * Martin Fowler's "Patterns of Enterprise Application Architecture"
 * Money pattern. We hand-roll rather than depend on `moneyphp/money`
 * because TejCart ships without a Composer runtime (see B2 in CLAUDE.md).
 *
 * ## Rounding note (F-CCM-008)
 *
 * This class uses PHP_ROUND_HALF_EVEN (banker's rounding) throughout.
 * Tax_Manager::round_tax() uses PHP_ROUND_HALF_UP (commercial rounding).
 * The divergence is intentional — see the Tax_Manager class docblock for
 * the full rationale and the `tejcart_tax_round_mode` filter to align them.
 *
 * @psalm-immutable
 */
final class Money implements \JsonSerializable {
	/**
	 * Amount expressed in the currency's minor unit.
	 *
	 * @var int
	 */
	private int $minor;

	/**
	 * Three-letter ISO 4217 currency code, uppercased.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Use the named factories (from_minor_units / from_decimal_string / zero).
	 *
	 * @param int    $minor    Amount in the currency's minor unit.
	 * @param string $currency ISO 4217 code (will be normalised to uppercase).
	 *
	 * @throws \InvalidArgumentException If the currency code is malformed.
	 */
	private function __construct( int $minor, string $currency ) {
		$normalised = strtoupper( trim( $currency ) );
		if ( ! Currency::is_valid_shape( $normalised ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid currency code: %s', esc_html( (string) $currency ) )
			);
		}
		$this->minor    = $minor;
		$this->currency = $normalised;
	}

	/**
	 * Build a Money from an integer amount in the minor unit.
	 *
	 * Examples:
	 *
	 *     Money::from_minor_units( 1234, 'USD' ); // $12.34
	 *     Money::from_minor_units( 1234, 'JPY' ); // ¥1234 (no decimals)
	 *     Money::from_minor_units( 1234, 'KWD' ); // 1.234 KWD
	 *
	 * @param int    $minor    Amount in the currency's minor unit.
	 * @param string $currency ISO 4217 code.
	 * @return self
	 */
	public static function from_minor_units( int $minor, string $currency ): self {
		return new self( $minor, $currency );
	}

	/**
	 * Build a Money from a decimal string ("12.34", "0", "-7.5").
	 *
	 * String input — never float — keeps the migration boundary clean.
	 * The string is parsed with no intermediate float, so even values
	 * that would round-trip badly through `(float)` (e.g. "0.1") survive.
	 *
	 * Trailing fractional digits beyond the currency's precision are
	 * rejected to avoid silent truncation (a $12.345 price for USD is
	 * almost certainly a bug). Callers that genuinely need to round
	 * should do so explicitly via {@see Money::multiply_percent} or
	 * by allocating across line items.
	 *
	 * @param string $value    Decimal string. Optional sign + integer
	 *                         + optional `.` + optional fraction.
	 * @param string $currency ISO 4217 code.
	 * @return self
	 *
	 * @throws \InvalidArgumentException If the string is not a valid
	 *                                   decimal or has too many fraction digits.
	 */
	public static function from_decimal_string( string $value, string $currency ): self {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			throw new \InvalidArgumentException( 'Empty decimal string' );
		}

		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $trimmed, $m ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Not a valid decimal string: %s', esc_html( (string) $value ) )
			);
		}

		$decimals = Currency::decimals( $currency );
		$sign     = '-' === $m[1] ? -1 : 1;
		$integer  = $m[2];
		$fraction = $m[3] ?? '';

		if ( strlen( $fraction ) > $decimals ) {
			$trimmed_fraction = rtrim( $fraction, '0' );
			if ( strlen( $trimmed_fraction ) > $decimals ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Decimal value %s has more fraction digits than %s allows (%d).',
						esc_html( (string) $value ),
						esc_html( strtoupper( $currency ) ),
						(int) $decimals
					)
				);
			}
			$fraction = $trimmed_fraction;
		}

		$fraction = str_pad( $fraction, $decimals, '0', STR_PAD_RIGHT );

		$minor = $sign * (int) ( $integer . $fraction );

		return new self( $minor, $currency );
	}

	/**
	 * Convenience: a zero amount in the given currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return self
	 */
	public static function zero( string $currency ): self {
		return new self( 0, $currency );
	}

	/**
	 * Add another Money in the same currency.
	 *
	 * @param self $other The other amount.
	 * @return self
	 *
	 * @throws \InvalidArgumentException If currencies don't match.
	 */
	public function add( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->minor + $other->minor, $this->currency );
	}

	/**
	 * Subtract another Money in the same currency.
	 *
	 * @param self $other The other amount.
	 * @return self
	 *
	 * @throws \InvalidArgumentException If currencies don't match.
	 */
	public function subtract( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->minor - $other->minor, $this->currency );
	}

	/**
	 * Multiply by an integer factor (e.g. line item quantity).
	 *
	 * @param int $factor Multiplier.
	 * @return self
	 */
	public function multiply( int $factor ): self {
		return new self( $this->minor * $factor, $this->currency );
	}

	/**
	 * Multiply by a percentage rate (e.g. 8.875% tax) using banker's
	 * rounding. Rate is expressed as a decimal string ("0.08875"),
	 * never a float — same reason `from_decimal_string` exists.
	 *
	 * Banker's rounding (round half to even) keeps cumulative rounding
	 * unbiased over many transactions, which matters for tax aggregation.
	 *
	 * @param string $rate Decimal rate string. "0.1" = 10%, "0.08875" = 8.875%.
	 * @return self
	 *
	 * @throws \InvalidArgumentException If the rate is malformed.
	 */
	public function multiply_percent( string $rate ): self {
		$trimmed = trim( $rate );
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $trimmed, $m ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid rate string: %s', esc_html( (string) $rate ) )
			);
		}
		$sign     = '-' === $m[1] ? -1 : 1;
		$integer  = $m[2];
		$fraction = $m[3] ?? '';

		$scale            = strlen( $fraction );
		$rate_numerator   = $sign * (int) ( $integer . $fraction );
		$rate_denominator = (int) ( 10 ** $scale );

		if ( 0 === $rate_denominator ) {
			throw new \InvalidArgumentException( 'Rate scale overflow' );
		}

		$product   = $this->minor * $rate_numerator;
		$rounded   = self::div_round_half_to_even( $product, $rate_denominator );

		return new self( $rounded, $this->currency );
	}

	/**
	 * Allocate this amount across N slots so the slot sums equal the
	 * total exactly. The remainder cents are distributed one per slot
	 * starting at index 0 (Stripe's "ratios" allocation pattern).
	 *
	 *     Money::from_minor_units(100, 'USD')->allocate(3);
	 *     // [Money(34), Money(33), Money(33)]
	 *
	 * @param int $slots Number of slots.
	 * @return self[] Indexed array of Money.
	 *
	 * @throws \InvalidArgumentException If $slots < 1.
	 */
	public function allocate( int $slots ): array {
		if ( $slots < 1 ) {
			throw new \InvalidArgumentException( 'Cannot allocate to fewer than 1 slot' );
		}
		$base      = intdiv( $this->minor, $slots );
		$remainder = $this->minor - ( $base * $slots );
		$out       = array();
		for ( $i = 0; $i < $slots; $i++ ) {
			$extra  = $i < abs( $remainder ) ? ( $remainder <=> 0 ) : 0;
			$out[] = new self( $base + $extra, $this->currency );
		}
		return $out;
	}

	/**
	 * Equality comparison (same currency + same amount).
	 *
	 * @param self $other Other Money.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->currency === $other->currency && $this->minor === $other->minor;
	}

	/**
	 * Whether the value is zero.
	 *
	 * @return bool
	 */
	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	/**
	 * Whether the value is strictly negative.
	 *
	 * @return bool
	 */
	public function is_negative(): bool {
		return $this->minor < 0;
	}

	/**
	 * Raw amount in the currency's minor unit.
	 *
	 * @return int
	 */
	public function as_minor_units(): int {
		return $this->minor;
	}

	/**
	 * Decimal-string representation ("12.34"). No thousands separator,
	 * no currency symbol — formatting for display lives in `format()`.
	 *
	 * @return string
	 */
	public function as_decimal_string(): string {
		$decimals = Currency::decimals( $this->currency );
		$sign     = $this->minor < 0 ? '-' : '';
		$abs      = (string) abs( $this->minor );

		if ( 0 === $decimals ) {
			return $sign . $abs;
		}

		$abs    = str_pad( $abs, $decimals + 1, '0', STR_PAD_LEFT );
		$intp   = substr( $abs, 0, -$decimals );
		$fracp  = substr( $abs, -$decimals );

		return $sign . $intp . '.' . $fracp;
	}

	/**
	 * ISO 4217 currency code (uppercase).
	 *
	 * @return string
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Localised display string ("$12.34", "¥1,234").
	 *
	 * Uses the existing `tejcart_price()` formatter for thousands /
	 * decimal separators and currency-symbol position so every UI
	 * surface in TejCart renders money the same way. Bridging through
	 * `tejcart_price()` keeps backward compatibility with the existing
	 * filter chain (`tejcart_price_format`, `tejcart_currency_symbol`).
	 *
	 * @return string
	 */
	public function format(): string {
		if ( function_exists( 'tejcart_price' ) ) {
			// #1220: pass `$this` directly so tejcart_price() can short-
			// circuit the float round-trip and read decimals from the
			// integer minor units. The legacy float-overload is kept on
			// the function for backward compat with third-party callers.
			return tejcart_price( $this, $this->currency );
		}

		return $this->currency . ' ' . $this->as_decimal_string();
	}

	/**
	 * JSON representation: `{"amount":1234,"currency":"USD"}`.
	 *
	 * REST controllers that emit Money values get a structured payload
	 * that's unambiguous about both magnitude and currency, which lets
	 * downstream consumers (gateways, ERPs, webhooks) avoid float
	 * coercion entirely.
	 *
	 * @return array{amount: int, currency: string, formatted: string}
	 */
	public function jsonSerialize(): array {
		return array(
			'amount'    => $this->minor,
			'currency'  => $this->currency,
			'formatted' => $this->as_decimal_string(),
		);
	}

	/**
	 * Strip inclusive tax from a gross amount and return the net (pre-tax)
	 * amount in the same currency, using banker's rounding.
	 *
	 * VAT-inclusive pricing means the catalogue price already contains the
	 * tax (e.g. €120 includes €20 VAT @ 20%). Converting back to the net
	 * is `net = gross * 10000 / (10000 + rate_bp)` — equivalent to the
	 * float `gross / divisor` form but entirely in integer arithmetic so
	 * three-decimal currencies (KWD, BHD, OMR) and large totals don't
	 * accumulate float-divide drift.
	 *
	 *     Money::from_minor_units(12000, 'EUR')->strip_inclusive_tax(2000);
	 *     // Money(10000, 'EUR') — €100.00 net of 20% VAT
	 *
	 * @param int $rate_basis_points Tax rate in basis points. 20% = 2000,
	 *                               8.875% = 887.5 → pass 888 after
	 *                               banker-rounding at the caller, or
	 *                               accept the 1 bp loss at this scale.
	 *                               Must be non-negative.
	 * @return self The net (pre-tax) amount.
	 *
	 * @throws \InvalidArgumentException If $rate_basis_points is negative.
	 */
	public function strip_inclusive_tax( int $rate_basis_points ): self {
		if ( $rate_basis_points < 0 ) {
			throw new \InvalidArgumentException( 'Tax rate basis points must be non-negative' );
		}
		if ( 0 === $rate_basis_points ) {
			return new self( $this->minor, $this->currency );
		}

		$denominator = 10000 + $rate_basis_points;
		$net_minor   = self::div_round_half_to_even( $this->minor * 10000, $denominator );

		return new self( $net_minor, $this->currency );
	}

	/**
	 * Compute the inclusive tax portion of a gross amount.
	 *
	 * Companion to {@see self::strip_inclusive_tax()}. For a gross amount
	 * containing tax at $rate_basis_points, returns the tax component:
	 * `tax = gross * rate_bp / (10000 + rate_bp)`.
	 *
	 *     Money::from_minor_units(12000, 'EUR')->inclusive_tax_portion(2000);
	 *     // Money(2000, 'EUR') — €20.00 VAT inside a €120.00 gross
	 *
	 * The identity `gross == strip_inclusive_tax(r) + inclusive_tax_portion(r)`
	 * holds exactly for every (gross, rate) pair because both halves are
	 * computed with the same banker's-rounded integer division against the
	 * same denominator. No float can drift them apart.
	 *
	 * @param int $rate_basis_points Tax rate in basis points. Must be non-negative.
	 * @return self The tax portion in the same currency.
	 *
	 * @throws \InvalidArgumentException If $rate_basis_points is negative.
	 */
	public function inclusive_tax_portion( int $rate_basis_points ): self {
		if ( $rate_basis_points < 0 ) {
			throw new \InvalidArgumentException( 'Tax rate basis points must be non-negative' );
		}
		if ( 0 === $rate_basis_points ) {
			return self::zero( $this->currency );
		}
		$net = $this->strip_inclusive_tax( $rate_basis_points );
		return new self( $this->minor - $net->minor, $this->currency );
	}

	/**
	 * Throw if $other is not in the same currency as $this.
	 *
	 * @param self $other Other Money.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assert_same_currency( self $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						'Cannot operate on %s and %s in the same expression',
						$this->currency,
						$other->currency
					)
				)
			);
		}
	}

	/**
	 * Integer division with banker's rounding (round half to even).
	 *
	 * The standard PHP `intdiv` truncates toward zero, which biases
	 * downward for positive amounts — over a long ledger that becomes
	 * a real reconciliation drift. Rounding half to even is unbiased
	 * and is what most accounting standards prescribe for tax.
	 *
	 * @param int $numerator   Dividend.
	 * @param int $denominator Divisor (must be positive).
	 * @return int
	 */
	private static function div_round_half_to_even( int $numerator, int $denominator ): int {
		if ( 0 === $denominator ) {
			throw new \InvalidArgumentException( 'Division by zero' );
		}

		$sign      = ( $numerator < 0 ) === ( $denominator < 0 ) ? 1 : -1;
		$abs_num   = abs( $numerator );
		$abs_den   = abs( $denominator );
		$quotient  = intdiv( $abs_num, $abs_den );
		$remainder = $abs_num - ( $quotient * $abs_den );

		$twice = $remainder * 2;
		if ( $twice > $abs_den ) {
			$quotient += 1;
		} elseif ( $twice === $abs_den ) {
			if ( 1 === ( $quotient & 1 ) ) {
				$quotient += 1;
			}
		}

		return $sign * $quotient;
	}
}
