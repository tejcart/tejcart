<?php
/**
 * Per-country/state tax rate manager.
 *
 * @package TejCart\Tax
 */

declare( strict_types=1 );

namespace TejCart\Tax;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages tax rates stored as a JSON array in the tejcart_tax_rates option.
 *
 * Each rate entry: {id, country, state, rate, name, priority, compound, shipping, tax_class}
 *
 * Tax classes (standard, reduced, zero) are stored in the tejcart_tax_classes option.
 * Products can be assigned a tax class via product meta `_tax_class`.
 *
 * ## Rounding contract (authoritative)
 *
 * Tax rates here are stored as decimal percentages (e.g. 8.875 for 8.875 %).
 * The calculator that consumes these rates (see `TejCart\Cart\Cart_Calculator`
 * and `TejCart\Order\Order_Factory`) MUST round according to the following
 * rules so the same cart produces the same invoice across requests:
 *
 *  - Intermediate tax amounts are computed in floating point.
 *  - The *final* tax figure stored on a cart line or order row is rounded
 *    to 2 decimals using PHP's `round($value, 2, PHP_ROUND_HALF_UP)` —
 *    "round half away from zero" (standard commercial rounding, matches
 *    invoice expectations in US / EU / UK jurisdictions).
 *  - When a store opts into per-line tax distribution
 *    (`tejcart_calc_taxes_per_line` = 'yes') each line is rounded *before*
 *    being summed, so line-level invoices reconcile to the cart total.
 *  - When per-line is disabled, tax is calculated on the (subtotal - discount
 *    + shipping) aggregate and rounded once at the end.
 *  - Inclusive-tax reversal uses divisor (1 + rate/100) and the same final
 *    round-half-up rule.
 *  - Rate percentages are stored with up to 4 decimal places; callers MUST
 *    NOT re-round the rate itself before applying.
 *
 * Stores that need banker's rounding (round-half-to-even) can filter
 * `tejcart_tax_round_mode` to return one of PHP's `PHP_ROUND_*` constants.
 *
 * ## Intentional rounding divergence (F-CCM-008)
 *
 * `round_tax()` defaults to PHP_ROUND_HALF_UP (commercial rounding) while
 * the `TejCart\Money\Money` value object defaults to PHP_ROUND_HALF_EVEN
 * (banker's rounding). On amounts ending in exactly 0.5 minor units, the
 * two modes produce different results. This divergence is intentional:
 *
 *  - Tax totals on invoices follow commercial rounding because that matches
 *    merchant/customer expectations in US/EU/UK jurisdictions (half-cents
 *    always round up, not to the nearest even cent).
 *  - Money arithmetic stays with banker's rounding to minimise statistical
 *    accumulation of rounding error across large order sets.
 *
 * To align both paths use the `tejcart_tax_round_mode` filter to return
 * PHP_ROUND_HALF_EVEN. Do NOT change the default here without a DB migration
 * and a merchant-facing notice — existing installs already have stored tax
 * totals computed with HALF_UP, and changing the default mid-flight would
 * cause 1-minor-unit discrepancies on boundary amounts.
 */
class Tax_Manager {
    /**
     * Round a computed tax amount using the plugin-wide rounding contract.
     *
     * See the class docblock for the canonical rounding rules. Consumers
     * should prefer this helper so rounding stays consistent across the
     * cart calculator, order factory, and any third-party extensions.
     *
     * @param float  $amount
     * @param string $currency Optional ISO-4217 currency code. When
     *                         supplied, rounds to Currency::decimals
     *                         (JPY = 0, USD = 2, KWD = 3). Default
     *                         '' preserves the legacy 2-decimal
     *                         behaviour for extension callers that
     *                         haven't been updated.
     * @return float
     */
    public static function round_tax( $amount, string $currency = '' ) {
        $mode     = (int) apply_filters( 'tejcart_tax_round_mode', PHP_ROUND_HALF_UP );
        $decimals = 2;
        if ( '' !== $currency && class_exists( '\\TejCart\\Money\\Currency' ) ) {
            $decimals = max( 0, (int) \TejCart\Money\Currency::decimals( $currency ) );
        }
        return round( (float) $amount, $decimals, $mode );
    }

    /**
     * Loaded tax rates.
     *
     * @var array
     */
    private $rates = array();

    /**
     * Loaded tax classes.
     *
     * @var array
     */
    private $tax_classes = array();

    /**
     * Constructor - loads rates and tax classes from the database.
     */
    public function __construct() {
        $stored = get_option( 'tejcart_tax_rates', '[]' );
        $decoded = json_decode( $stored, true );
        $this->rates = is_array( $decoded ) ? $decoded : array();

        $classes_stored       = get_option( 'tejcart_tax_classes', '' );
        $classes_decoded      = json_decode( $classes_stored, true );
        $this->tax_classes    = is_array( $classes_decoded ) ? $classes_decoded : array();

        if ( empty( $this->tax_classes ) ) {
            $this->tax_classes = array(
                array( 'id' => 1, 'name' => 'Standard' ),
                array( 'id' => 2, 'name' => 'Reduced' ),
                array( 'id' => 3, 'name' => 'Zero' ),
            );
            $this->save_tax_classes();
        }
    }

    /**
     * Persist the current rates array back to the database.
     *
     * Autoload=no: rates can be a multi-MB JSON for any merchant with
     * VAT × tax classes × states. Forcing it into `alloptions` on every
     * request was an O(N) memory and CPU cost on every page load —
     * including pages that never touch tax (homepage, blog, search).
     * The Tax_Manager instance memoizes the decoded array per request
     * so the lookup cost stays in PHP memory after the first hit.
     *
     * @return bool
     */
    private function save_rates() {
        return update_option( 'tejcart_tax_rates', wp_json_encode( $this->rates ), false );
    }

    /**
     * Persist the current tax classes array back to the database.
     *
     * @return bool
     */
    private function save_tax_classes() {
        // Autoload=false to match the sibling save_rates() at line 112 —
        // tax classes are small in default installs but custom catalogs
        // (industry-specific VAT) can bloat the autoload pellet.
        return update_option( 'tejcart_tax_classes', wp_json_encode( $this->tax_classes ), false );
    }

    /**
     * Find the best matching tax rate for a country, optional state, and tax class.
     *
     * Priority (most specific wins; ties broken by lowest `priority` value):
     *   1. country + state + exact class
     *   2. country + state + unassigned-class rate (rate's `tax_class` is empty)
     *   3. country-only + exact class
     *   4. country-only + unassigned-class rate
     *   5. default rate (country='*' or '') whose class matches or is unassigned
     *
     * When `$tax_class` is supplied, rates tagged with a *different* class
     * are never considered — applying a Standard-rate row to a Reduced-rate
     * product would misreport the invoice. Rates stored without a
     * `tax_class` (the legacy "all classes" shape) still match as a fallback
     * tier so pre-existing configurations keep working.
     *
     * @param string $country   Country code.
     * @param string $state     Optional state code.
     * @param string $tax_class Optional tax class name (e.g. 'Standard', 'Reduced', 'Zero').
     * @return array|null Matching rate entry or null.
     */
    public function get_tax_rate( $country, $state = '', $tax_class = '' ) {
        $country_state_class_match   = null;
        $country_state_unclass_match = null;
        $country_class_match         = null;
        $country_unclass_match       = null;
        $default_class_match         = null;
        $default_unclass_match       = null;

        $lookup_has_class = ( '' !== (string) $tax_class );

        foreach ( $this->rates as $rate ) {
            $rate_country   = isset( $rate['country'] ) ? $rate['country'] : '';
            $rate_state     = isset( $rate['state'] ) ? $rate['state'] : '';
            $rate_tax_class = isset( $rate['tax_class'] ) ? $rate['tax_class'] : '';

            $class_exact      = $lookup_has_class && $rate_tax_class === $tax_class;
            $class_unassigned = '' === $rate_tax_class;
            $class_eligible   = $class_exact || $class_unassigned || ! $lookup_has_class;

            if ( ! $class_eligible ) {
                continue;
            }

            $is_country_state = ( $rate_country === $country && ! empty( $state ) && $rate_state === $state );
            $is_country_only  = ( $rate_country === $country && ( empty( $rate_state ) || '*' === $rate_state ) );
            $is_default       = ( '*' === $rate_country || '' === $rate_country );

            if ( $is_country_state && $class_exact ) {
                $country_state_class_match = $this->pick_lower_priority( $country_state_class_match, $rate );
            } elseif ( $is_country_state && $class_unassigned ) {
                $country_state_unclass_match = $this->pick_lower_priority( $country_state_unclass_match, $rate );
            }

            if ( $is_country_only && $class_exact ) {
                $country_class_match = $this->pick_lower_priority( $country_class_match, $rate );
            } elseif ( $is_country_only && $class_unassigned ) {
                $country_unclass_match = $this->pick_lower_priority( $country_unclass_match, $rate );
            }

            if ( $is_default && $class_exact ) {
                $default_class_match = $this->pick_lower_priority( $default_class_match, $rate );
            } elseif ( $is_default && $class_unassigned ) {
                $default_unclass_match = $this->pick_lower_priority( $default_unclass_match, $rate );
            }
        }

        if ( null !== $country_state_class_match ) {
            return $country_state_class_match;
        }
        if ( null !== $country_state_unclass_match ) {
            return $country_state_unclass_match;
        }
        if ( null !== $country_class_match ) {
            return $country_class_match;
        }
        if ( null !== $country_unclass_match ) {
            return $country_unclass_match;
        }
        if ( null !== $default_class_match ) {
            return $default_class_match;
        }

        return $default_unclass_match;
    }

    /**
     * Pick whichever of two rate rows has the lower `priority` value
     * (lower = applied first). Missing priorities default to 1.
     *
     * @param array|null $current   Currently-selected rate.
     * @param array      $candidate Rate row under consideration.
     * @return array
     */
    private function pick_lower_priority( $current, array $candidate ): array {
        if ( null === $current ) {
            return $candidate;
        }
        $current_priority   = isset( $current['priority'] ) ? (int) $current['priority'] : 1;
        $candidate_priority = isset( $candidate['priority'] ) ? (int) $candidate['priority'] : 1;
        return ( $candidate_priority < $current_priority ) ? $candidate : $current;
    }

    /**
     * Return all configured tax rates.
     *
     * @return array
     */
    public function get_rates() {
        return $this->rates;
    }

    /**
     * Add a new tax rate.
     *
     * @param array $data {country, state, rate, name, priority, compound, shipping, tax_class}.
     * @return int The new rate ID.
     */
    public function add_rate( $data ) {
        $id = 1;
        foreach ( $this->rates as $rate ) {
            if ( isset( $rate['id'] ) && (int) $rate['id'] >= $id ) {
                $id = (int) $rate['id'] + 1;
            }
        }

        $new_rate = array(
            'id'        => $id,
            'country'   => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '',
            'state'     => isset( $data['state'] ) ? sanitize_text_field( $data['state'] ) : '',
            'rate'      => isset( $data['rate'] ) ? max( 0.0, min( 999.99, (float) $data['rate'] ) ) : 0.0,
            'name'      => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : __( 'Tax', 'tejcart' ),
            'priority'  => isset( $data['priority'] ) ? (int) $data['priority'] : 1,
            'compound'  => isset( $data['compound'] ) ? sanitize_text_field( $data['compound'] ) : 'no',
            'shipping'  => isset( $data['shipping'] ) ? sanitize_text_field( $data['shipping'] ) : 'yes',
            'tax_class' => isset( $data['tax_class'] ) ? sanitize_text_field( $data['tax_class'] ) : '',
        );

        $this->rates[] = $new_rate;
        $this->save_rates();

        return $id;
    }

    /**
     * Update an existing tax rate.
     *
     * @param int   $id   Rate ID.
     * @param array $data Fields to update.
     * @return bool True on success.
     */
    public function update_rate( $id, $data ) {
        foreach ( $this->rates as $index => $rate ) {
            if ( isset( $rate['id'] ) && (int) $rate['id'] === (int) $id ) {
                $allowed = array( 'country', 'state', 'rate', 'name', 'priority', 'compound', 'shipping', 'tax_class' );
                foreach ( $allowed as $key ) {
                    if ( isset( $data[ $key ] ) ) {
                        if ( 'rate' === $key ) {
                            $this->rates[ $index ][ $key ] = max( 0.0, min( 999.99, (float) $data[ $key ] ) );
                        } elseif ( 'priority' === $key ) {
                            $this->rates[ $index ][ $key ] = (int) $data[ $key ];
                        } else {
                            $this->rates[ $index ][ $key ] = sanitize_text_field( $data[ $key ] );
                        }
                    }
                }
                $this->save_rates();
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a tax rate by ID.
     *
     * @param int $id Rate ID.
     * @return bool True on success.
     */
    public function delete_rate( $id ) {
        foreach ( $this->rates as $index => $rate ) {
            if ( isset( $rate['id'] ) && (int) $rate['id'] === (int) $id ) {
                array_splice( $this->rates, $index, 1 );
                $this->save_rates();
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the tax amount for a given amount, location, and optional tax class.
     *
     * @param float  $amount    The taxable amount.
     * @param string $country   Country code.
     * @param string $state     Optional state code.
     * @param string $tax_class Optional tax class name.
     * @param string $currency  Optional ISO-4217 currency code so the
     *                          return value rounds to the right
     *                          decimals (JPY=0, USD=2, KWD=3). Default
     *                          '' keeps the legacy 2-decimal behaviour
     *                          for back-compat with extension callers.
     * @return float The calculated tax.
     */
    public function calculate_tax( $amount, $country, $state = '', $tax_class = '', string $currency = '' ) {
        $rate = $this->get_tax_rate( $country, $state, $tax_class );

        if ( null === $rate ) {
            return 0.0;
        }

        // Route through the canonical rounding helper so the
        // `tejcart_tax_round_mode` filter (banker's rounding, half-down,
        // …) applies uniformly across Tax_Manager::calculate_tax(),
        // Cart_Calculator, and Order_Factory. Calling `round()` directly
        // here would silently bypass the filter for any store that
        // opted out of half-up rounding. The currency arg propagates
        // through round_tax so 0-decimal and 3-decimal currencies get
        // the right precision.
        return self::round_tax( (float) $amount * ( (float) $rate['rate'] / 100 ), $currency );
    }

    /**
     * Get all tax classes.
     *
     * @return array Array of tax class entries [{id, name}].
     */
    public function get_tax_classes() {
        return $this->tax_classes;
    }

    /**
     * Add a new tax class.
     *
     * @param string $name Tax class name (e.g. 'Luxury').
     * @return int The new tax class ID.
     */
    public function add_tax_class( $name ) {
        $id = 1;
        foreach ( $this->tax_classes as $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] >= $id ) {
                $id = (int) $class['id'] + 1;
            }
        }

        $this->tax_classes[] = array(
            'id'   => $id,
            'name' => sanitize_text_field( $name ),
        );

        $this->save_tax_classes();

        return $id;
    }

    /**
     * Delete a tax class by ID.
     *
     * The three built-in classes (Standard, Reduced, Zero) with IDs 1-3
     * cannot be deleted.
     *
     * @param int $id Tax class ID.
     * @return bool True on success, false on failure.
     */
    public function delete_tax_class( $id ) {
        $id = (int) $id;

        if ( $id <= 3 ) {
            return false;
        }

        foreach ( $this->tax_classes as $index => $class ) {
            if ( isset( $class['id'] ) && (int) $class['id'] === $id ) {
                array_splice( $this->tax_classes, $index, 1 );
                $this->save_tax_classes();
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether taxes are enabled.
     *
     * The canonical option key is `tejcart_enable_tax` — that is what the
     * installer seeds and what Cart_Calculator reads on every request. An
     * earlier typo here (`tejcart_tax_enabled`) meant this helper always
     * returned false, silently disagreeing with the calculator. We also
     * honour the legacy typo so sites that had written to it from custom
     * code keep working.
     *
     * @return bool
     */
    public function is_tax_enabled() {
        $primary = get_option( 'tejcart_enable_tax', null );
        if ( null !== $primary ) {
            return 'yes' === $primary;
        }
        return 'yes' === get_option( 'tejcart_tax_enabled', 'no' );
    }

    /**
     * Check whether prices include tax.
     *
     * @return bool
     */
    public function prices_include_tax() {
        return 'yes' === get_option( 'tejcart_prices_include_tax', 'no' );
    }

    /**
     * ISO-3166 alpha-2 country code => English name.
     *
     * Data is loaded from src/Tax/Data/countries.php so the dataset
     * can be grown without touching this class.
     *
     * @return array<string,string>
     */
    public static function get_countries() {
        $data = require __DIR__ . '/Data/countries.php';
        return is_array( $data ) ? $data : array();
    }

    /**
     * Return states/provinces for supported countries.
     *
     * @param string $country Country code.
     * @return array State code => state name pairs.
     */
    public static function get_states( $country ) {
        $loaded = require __DIR__ . '/Data/states.php';
        $states = is_array( $loaded ) ? $loaded : array();

        /**
         * Filter the list of states/provinces for a given country.
         *
         * Themes and companion plugins can hook here to add or override
         * the built-in dataset. Used by checkout state dropdowns and the
         * admin tax-rate editor.
         *
         * @param array  $country_states Map of code => name for $country.
         * @param string $country        ISO-3166 alpha-2 country code.
         */
        $country_states = apply_filters(
            'tejcart_country_states',
            $states[ $country ] ?? array(),
            $country
        );

        return is_array( $country_states ) ? $country_states : array();
    }
}
