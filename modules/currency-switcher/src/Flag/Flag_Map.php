<?php
/**
 * ISO 4217 currency code → ISO 3166-1 alpha-2 country code map.
 *
 * Used by the front-end switcher UI to pick the right flag SVG for a
 * given currency. The legacy "first two letters of the currency code"
 * heuristic in {@see \TejCart\Currency_Switcher\Switcher\Shortcodes::flag_url()}
 * gave the right answer for ~70% of currencies and silently fell back
 * to a missing-image for the rest (AED, BHD, KWD, SAR — anything where
 * the country code is not the same as the currency-code prefix).
 *
 * @package TejCart\Currency_Switcher\Flag
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Flag;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Flag_Map {
    /**
     * Currency-code → country-code map. Multi-country currencies (EUR,
     * XAF, XOF, XCD, XPF, ANG) map to the most recognisable single flag
     * for that currency — `eu` for the Eurozone, `cf` for the Central
     * African CFA franc, etc. Sites that want a different default can
     * override via the `tejcart_csw_flag_country_map` filter.
     *
     * @return array<string, string>
     */
    public static function map(): array {
        $map = array(
            'AED' => 'ae', 'AFN' => 'af', 'ALL' => 'al', 'AMD' => 'am', 'ANG' => 'cw',
            'AOA' => 'ao', 'ARS' => 'ar', 'AUD' => 'au', 'AWG' => 'aw', 'AZN' => 'az',
            'BAM' => 'ba', 'BBD' => 'bb', 'BDT' => 'bd', 'BGN' => 'bg', 'BHD' => 'bh',
            'BIF' => 'bi', 'BMD' => 'bm', 'BND' => 'bn', 'BOB' => 'bo', 'BRL' => 'br',
            'BSD' => 'bs', 'BTN' => 'bt', 'BWP' => 'bw', 'BYN' => 'by', 'BZD' => 'bz',
            'CAD' => 'ca', 'CDF' => 'cd', 'CHF' => 'ch', 'CLP' => 'cl', 'CNY' => 'cn',
            'COP' => 'co', 'CRC' => 'cr', 'CUP' => 'cu', 'CVE' => 'cv', 'CZK' => 'cz',
            'DJF' => 'dj', 'DKK' => 'dk', 'DOP' => 'do', 'DZD' => 'dz', 'EGP' => 'eg',
            'ERN' => 'er', 'ETB' => 'et', 'EUR' => 'eu', 'FJD' => 'fj', 'FKP' => 'fk',
            'GBP' => 'gb', 'GEL' => 'ge', 'GHS' => 'gh', 'GIP' => 'gi', 'GMD' => 'gm',
            'GNF' => 'gn', 'GTQ' => 'gt', 'GYD' => 'gy', 'HKD' => 'hk', 'HNL' => 'hn',
            'HTG' => 'ht', 'HUF' => 'hu', 'IDR' => 'id', 'ILS' => 'il', 'INR' => 'in',
            'IQD' => 'iq', 'IRR' => 'ir', 'ISK' => 'is', 'JMD' => 'jm', 'JOD' => 'jo',
            'JPY' => 'jp', 'KES' => 'ke', 'KGS' => 'kg', 'KHR' => 'kh', 'KMF' => 'km',
            'KPW' => 'kp', 'KRW' => 'kr', 'KWD' => 'kw', 'KYD' => 'ky', 'KZT' => 'kz',
            'LAK' => 'la', 'LBP' => 'lb', 'LKR' => 'lk', 'LRD' => 'lr', 'LSL' => 'ls',
            'LYD' => 'ly', 'MAD' => 'ma', 'MDL' => 'md', 'MGA' => 'mg', 'MKD' => 'mk',
            'MMK' => 'mm', 'MNT' => 'mn', 'MOP' => 'mo', 'MRU' => 'mr', 'MUR' => 'mu',
            'MVR' => 'mv', 'MWK' => 'mw', 'MXN' => 'mx', 'MYR' => 'my', 'MZN' => 'mz',
            'NAD' => 'na', 'NGN' => 'ng', 'NIO' => 'ni', 'NOK' => 'no', 'NPR' => 'np',
            'NZD' => 'nz', 'OMR' => 'om', 'PAB' => 'pa', 'PEN' => 'pe', 'PGK' => 'pg',
            'PHP' => 'ph', 'PKR' => 'pk', 'PLN' => 'pl', 'PYG' => 'py', 'QAR' => 'qa',
            'RON' => 'ro', 'RSD' => 'rs', 'RUB' => 'ru', 'RWF' => 'rw', 'SAR' => 'sa',
            'SBD' => 'sb', 'SCR' => 'sc', 'SDG' => 'sd', 'SEK' => 'se', 'SGD' => 'sg',
            'SHP' => 'sh', 'SLL' => 'sl', 'SOS' => 'so', 'SRD' => 'sr', 'SSP' => 'ss',
            'STN' => 'st', 'SYP' => 'sy', 'SZL' => 'sz', 'THB' => 'th', 'TJS' => 'tj',
            'TMT' => 'tm', 'TND' => 'tn', 'TOP' => 'to', 'TRY' => 'tr', 'TTD' => 'tt',
            'TWD' => 'tw', 'TZS' => 'tz', 'UAH' => 'ua', 'UGX' => 'ug', 'USD' => 'us',
            'UYU' => 'uy', 'UZS' => 'uz', 'VES' => 've', 'VND' => 'vn', 'VUV' => 'vu',
            'WST' => 'ws', 'XAF' => 'cf', 'XCD' => 'ag', 'XOF' => 'sn', 'XPF' => 'pf',
            'YER' => 'ye', 'ZAR' => 'za', 'ZMW' => 'zm', 'ZWL' => 'zw',
        );

        /**
         * Override or extend the currency → country mapping used by the
         * flag picker. Useful when a store wants to display a specific
         * regional flag for a multi-country currency (e.g. `xof` →
         * `ci` for Côte d'Ivoire instead of the default `sn`).
         *
         * @param array<string, string> $map Map of uppercase currency
         *                                    code to lowercase ISO 3166-1
         *                                    alpha-2 country code.
         */
        $filtered = apply_filters( 'tejcart_csw_flag_country_map', $map );

        return is_array( $filtered ) ? $filtered : $map;
    }

    /**
     * Resolve a currency code to the lowercase country code its flag
     * should use, or `null` if we have no mapping for it.
     */
    public static function country_for( string $currency_code ): ?string {
        $code = strtoupper( trim( $currency_code ) );
        if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return null;
        }
        $map = self::map();
        return $map[ $code ] ?? null;
    }
}
