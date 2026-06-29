<?php
/**
 * ISO 4217 currency code enum.
 *
 * @package TejCart\Money
 */

declare( strict_types = 1 );

namespace TejCart\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP 8.1 backed enum covering the ISO 4217 currencies TejCart's gateways
 * and tax providers transact in. Complements {@see Currency}, which
 * remains the canonical authority for *all* codes (including
 * cryptocurrencies, test codes, and unknown future ISO additions).
 *
 * Use this enum where a new API surface wants a compile-time guarantee
 * that callers can't pass a typo. For permissive surfaces that genuinely
 * need to accept arbitrary three-letter codes — gateways forwarding a
 * crypto code, tax providers receiving merchant-defined codes — keep
 * accepting `string` and validate via {@see Currency::is_valid_shape()}.
 *
 * The list is intentionally bounded: ISO 4217 has 180+ codes, and
 * enumerating all of them would couple this enum to upstream ISO
 * amendments. The subset here covers every currency exposed by Stripe,
 * PayPal, Adyen, Authorize.Net, TaxJar, and Avalara as of 2026-Q2 —
 * which is the universe of currencies a TejCart store can actually
 * charge in. Anything outside this list falls back to the string +
 * {@see Currency::is_valid_shape()} path.
 *
 * @since 1.4.0
 */
enum Currency_Code: string {
	// Major reserve / G10 currencies.
	case USD = 'USD';
	case EUR = 'EUR';
	case GBP = 'GBP';
	case JPY = 'JPY';
	case CHF = 'CHF';
	case AUD = 'AUD';
	case CAD = 'CAD';
	case NZD = 'NZD';
	case SEK = 'SEK';
	case NOK = 'NOK';
	case DKK = 'DKK';

	// Asia-Pacific.
	case CNY = 'CNY';
	case HKD = 'HKD';
	case SGD = 'SGD';
	case TWD = 'TWD';
	case KRW = 'KRW';
	case INR = 'INR';
	case IDR = 'IDR';
	case MYR = 'MYR';
	case THB = 'THB';
	case PHP_ = 'PHP'; // `PHP` collides with the language token; suffix avoids it.
	case VND = 'VND';

	// Americas.
	case MXN = 'MXN';
	case BRL = 'BRL';
	case ARS = 'ARS';
	case CLP = 'CLP';
	case COP = 'COP';
	case PEN = 'PEN';
	case UYU = 'UYU';

	// EMEA.
	case PLN = 'PLN';
	case CZK = 'CZK';
	case HUF = 'HUF';
	case RON = 'RON';
	case BGN = 'BGN';
	case TRY = 'TRY';
	case ZAR = 'ZAR';
	case ILS = 'ILS';
	case AED = 'AED';
	case SAR = 'SAR';
	case QAR = 'QAR';

	// Gulf three-decimal currencies (precision matters — see Currency::THREE_DECIMAL).
	case BHD = 'BHD';
	case KWD = 'KWD';
	case OMR = 'OMR';
	case JOD = 'JOD';
	case TND = 'TND';

	/**
	 * Resolve a raw string to the matching enum case, or null if outside
	 * the supported set.
	 *
	 * Use this at API boundaries that want to *prefer* a typed code but
	 * fall through to {@see Currency::is_valid_shape()} for codes outside
	 * the enumerated set.
	 *
	 *     $code = Currency_Code::try_from_string( $input );
	 *     if ( $code !== null ) {
	 *         // typed path
	 *     } elseif ( Currency::is_valid_shape( $input ) ) {
	 *         // permissive path (crypto, test codes, etc.)
	 *     }
	 *
	 * @param string $value Three-letter code (case-insensitive).
	 * @return self|null
	 */
	public static function try_from_string( string $value ): ?self {
		return self::tryFrom( strtoupper( trim( $value ) ) );
	}

	/**
	 * Number of fractional digits for this currency.
	 *
	 * @return int 0, 2, or 3.
	 */
	public function decimals(): int {
		return Currency::decimals( $this->value );
	}

	/**
	 * 10^decimals — multiplier between major and minor units.
	 *
	 * @return int
	 */
	public function multiplier(): int {
		return Currency::multiplier( $this->value );
	}
}
