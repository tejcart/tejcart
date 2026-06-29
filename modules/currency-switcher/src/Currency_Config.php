<?php
/**
 * Immutable per-currency configuration value object.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents one configured currency entry from the `tejcart_csw_options`
 * array. Built via {@see self::from_array()} which sanitises and validates
 * every field — callers should never construct directly with raw input.
 */
final class Currency_Config {
    /**
     * @param string $code         ISO 4217 three-letter code (uppercase).
     * @param float  $rate         Conversion rate from base currency.
     * @param string $rate_type    Options::RATE_TYPE_AUTO or RATE_TYPE_FIXED.
     * @param float  $fee          Exchange fee.
     * @param string $fee_type     Options::FEE_FIXED or FEE_PERCENTAGE.
     * @param string $currency_pos Symbol position (Options::POS_*).
     * @param string $thousand_sep Thousand separator (single char).
     * @param string $decimal_sep  Decimal separator (single char).
     * @param int    $num_decimals 0–4.
     */
    public function __construct(
        public readonly string $code,
        public readonly float  $rate,
        public readonly string $rate_type,
        public readonly float  $fee,
        public readonly string $fee_type,
        public readonly string $currency_pos,
        public readonly string $thousand_sep,
        public readonly string $decimal_sep,
        public readonly int    $num_decimals,
    ) {}

    /**
     * Hydrate from a stored array (already-trusted source). For untrusted
     * input (admin POST), sanitise first via the admin layer.
     *
     * @param array<string, mixed> $row
     */
    public static function from_array( array $row ): self {
        $code = strtoupper( (string) ( $row['code'] ?? '' ) );
        if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
            // Fall back to a benign placeholder rather than fatal — option
            // rows can be hand-edited and we don't want a single broken row
            // to crash the whole frontend.
            $code = 'XXX';
        }

        $rate_type = (string) ( $row['rate_type'] ?? Options::RATE_TYPE_AUTO );
        if ( ! in_array( $rate_type, array( Options::RATE_TYPE_AUTO, Options::RATE_TYPE_FIXED ), true ) ) {
            $rate_type = Options::RATE_TYPE_AUTO;
        }

        $fee_type = (string) ( $row['fee_type'] ?? Options::FEE_FIXED );
        if ( ! in_array( $fee_type, array( Options::FEE_FIXED, Options::FEE_PERCENTAGE ), true ) ) {
            $fee_type = Options::FEE_FIXED;
        }

        $currency_pos = (string) ( $row['currency_pos'] ?? Options::POS_LEFT );
        if ( ! in_array(
            $currency_pos,
            array( Options::POS_LEFT, Options::POS_RIGHT, Options::POS_LEFT_SPACE, Options::POS_RIGHT_SPACE ),
            true
        ) ) {
            $currency_pos = Options::POS_LEFT;
        }

        // If the admin didn't pin a `num_decimals` value, fall back to
        // the ISO 4217 minor-unit count for this currency — JPY/KRW
        // become 0, KWD/BHD/OMR become 3, everything else 2. Without
        // this fallback the default of 2 silently truncates precision
        // currencies (1.234 KWD → 1.23 KWD) on every conversion.
        if ( array_key_exists( 'num_decimals', $row ) && '' !== (string) $row['num_decimals'] ) {
            $num_decimals = (int) $row['num_decimals'];
        } else {
            $num_decimals = class_exists( Currency::class ) ? Currency::decimals( $code ) : 2;
        }
        if ( $num_decimals < 0 ) {
            $num_decimals = 0;
        } elseif ( $num_decimals > 4 ) {
            $num_decimals = 4;
        }

        // Reject pathological rates: zero or negative would silently
        // make every product look free (or negative) in this currency.
        // Fall back to 1.0 so the row at least doesn't poison conversions
        // — admins still see the row in the UI and can correct it.
        $rate = (float) ( $row['rate'] ?? 1.0 );
        if ( $rate <= 0.0 ) {
            $rate = 1.0;
        }

        $fee = (float) ( $row['fee'] ?? 0.0 );

        return new self(
            $code,
            $rate,
            $rate_type,
            $fee,
            $fee_type,
            $currency_pos,
            (string) ( $row['thousand_sep'] ?? ',' ),
            (string) ( $row['decimal_sep'] ?? '.' ),
            $num_decimals,
        );
    }

    /**
     * Effective rate including the configured fee.
     *
     * percentage:  rate + (rate * fee / 100)
     * fixed:       rate + fee
     */
    public function effective_rate(): float {
        $rate = ( Options::FEE_PERCENTAGE === $this->fee_type )
            ? $this->rate + ( $this->rate * $this->fee / 100.0 )
            : $this->rate + $this->fee;

        // A negative fee on a low rate (e.g. -100% percentage fee, or a
        // hand-typed -1.0 fixed fee on a 0.5 rate) could push the
        // effective rate to zero or below. Guard so callers can rely on
        // a positive divisor for reverse conversions and refunds.
        return $rate > 0.0 ? $rate : $this->rate;
    }

    /** @return array<string, mixed> */
    public function to_array(): array {
        return array(
            'code'         => $this->code,
            'rate'         => $this->rate,
            'rate_type'    => $this->rate_type,
            'fee'          => $this->fee,
            'fee_type'     => $this->fee_type,
            'currency_pos' => $this->currency_pos,
            'thousand_sep' => $this->thousand_sep,
            'decimal_sep'  => $this->decimal_sep,
            'num_decimals' => $this->num_decimals,
        );
    }
}
