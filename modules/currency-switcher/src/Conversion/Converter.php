<?php
/**
 * Core conversion engine: base-amount → display-amount and back.
 *
 * @package TejCart\Currency_Switcher\Conversion
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Conversion;

use TejCart\Currency_Switcher\Currency_Config;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of truth for FX conversion. Every price filter routes
 * through {@see self::convert()}; reverse-conversion (refunds, FX
 * reports) routes through {@see self::reverse()}.
 *
 * Errors are swallowed and the original amount is returned — currency
 * bugs should never break the storefront. This is the same fail-soft
 * stance the WC plugin took and the TejCart "Conversion functions are
 * wrapped in try/catch and fall back to the original price on any
 * error" spec line.
 */
final class Converter {
    private Currency_Resolver $resolver;
    private Currency_Repository $repo;
    private Price_Adjustment $adjustment;

    public function __construct(
        ?Currency_Resolver $resolver = null,
        ?Currency_Repository $repo = null,
        ?Price_Adjustment $adjustment = null
    ) {
        $this->resolver   = $resolver ?? new Currency_Resolver();
        $this->repo       = $repo ?? new Currency_Repository();
        $this->adjustment = $adjustment ?? new Price_Adjustment();
    }

    /**
     * Convert a base-currency amount to the supplied (or active)
     * display currency. Returns the input unchanged when:
     *
     *  - the target is the base currency, or
     *  - the target isn't configured, or
     *  - any internal error is raised
     *
     * Conversion goes through integer-minor-unit arithmetic so the
     * stored display amount snaps cleanly to the target currency's
     * minor-unit grid (3 digits for KWD/BHD/OMR, 0 for JPY/KRW, 2 for
     * the rest). The multiplication itself is unavoidably floating
     * point — there is no integer-only way to apply a fractional FX
     * rate — but we round to the target's minor unit immediately and
     * keep all subsequent comparisons in integer space.
     */
    public function convert( float $amount, ?string $target = null ): float {
        try {
            $code = $target ?? $this->resolver->current();
            if ( $code === $this->repo->base_currency() ) {
                return $amount;
            }
            $cfg = $this->repo->get( $code );
            if ( null === $cfg ) {
                return $amount;
            }

            $rate = $cfg->effective_rate();
            if ( $rate <= 0.0 ) {
                return $amount;
            }

            // Snap the converted value to the target currency's
            // minor-unit grid via banker's rounding before charm-pricing
            // runs, so rounding bias is removed up front.
            // Audit M-18: use Currency::multiplier() for consistency
            // with the rest of the money stack. The old 10**num_decimals
            // was numerically identical but diverged from the canonical
            // source if Currency ever added a special case.
            $multiplier = class_exists( '\\TejCart\\Money\\Currency' )
                ? \TejCart\Money\Currency::multiplier( $cfg->code )
                : max( 1, (int) ( 10 ** $cfg->num_decimals ) );
            $minor     = (int) round( $amount * $rate * $multiplier, 0, PHP_ROUND_HALF_EVEN );
            $converted = $minor / $multiplier;

            $converted = $this->adjustment->apply( $converted, $cfg->num_decimals, $amount );
            return round( $converted, $cfg->num_decimals );
        } catch ( \Throwable $e ) {
            unset( $e );
            return $amount;
        }
    }

    /**
     * Reverse a display-currency amount back into base currency,
     * applying the effective rate (no rounding rule reversal — charm
     * pricing is by construction lossy and cannot be undone).
     */
    public function reverse( float $amount, ?string $target = null ): float {
        try {
            $code = $target ?? $this->resolver->current();
            if ( $code === $this->repo->base_currency() ) {
                return $amount;
            }
            $cfg = $this->repo->get( $code );
            if ( null === $cfg ) {
                return $amount;
            }
            $rate = $cfg->effective_rate();
            if ( $rate <= 0.0 ) {
                return $amount;
            }
            return $amount / $rate;
        } catch ( \Throwable $e ) {
            unset( $e );
            return $amount;
        }
    }

    public function active_config(): ?Currency_Config {
        return $this->resolver->current_config();
    }

    public function resolver(): Currency_Resolver {
        return $this->resolver;
    }

    public function repository(): Currency_Repository {
        return $this->repo;
    }
}
