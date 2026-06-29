<?php
/**
 * ISO-4217 currency catalog used by the admin Currencies picker.
 *
 * The list mirrors {@see \TejCart\API\Controllers\Data_Controller::currency_dataset()}
 * so the admin dropdown shows the same set of "first-class" currencies the
 * rest of the plugin already supports. Extensions can extend / trim the
 * list via the `tejcart_csw_supported_currencies` filter — the filter must
 * return a map of `CODE => [ name, symbol ]`.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Currency_Catalog {
    /**
     * @return array<string, array{name:string, symbol:string}>
     */
    public static function all(): array {
        $catalog = array(
            'USD' => array( 'name' => 'United States dollar', 'symbol' => '$' ),
            'EUR' => array( 'name' => 'Euro',                 'symbol' => "\u{20AC}" ),
            'GBP' => array( 'name' => 'Pound sterling',       'symbol' => "\u{00A3}" ),
            'JPY' => array( 'name' => 'Japanese yen',         'symbol' => "\u{00A5}" ),
            'AUD' => array( 'name' => 'Australian dollar',    'symbol' => 'A$' ),
            'CAD' => array( 'name' => 'Canadian dollar',      'symbol' => 'C$' ),
            'CHF' => array( 'name' => 'Swiss franc',          'symbol' => 'CHF' ),
            'CNY' => array( 'name' => 'Chinese yuan',         'symbol' => "\u{00A5}" ),
            'SEK' => array( 'name' => 'Swedish krona',        'symbol' => 'kr' ),
            'NZD' => array( 'name' => 'New Zealand dollar',   'symbol' => 'NZ$' ),
            'MXN' => array( 'name' => 'Mexican peso',         'symbol' => 'Mex$' ),
            'SGD' => array( 'name' => 'Singapore dollar',     'symbol' => 'S$' ),
            'HKD' => array( 'name' => 'Hong Kong dollar',     'symbol' => 'HK$' ),
            'NOK' => array( 'name' => 'Norwegian krone',      'symbol' => 'kr' ),
            'KRW' => array( 'name' => 'South Korean won',     'symbol' => "\u{20A9}" ),
            'TRY' => array( 'name' => 'Turkish lira',         'symbol' => "\u{20BA}" ),
            'INR' => array( 'name' => 'Indian rupee',         'symbol' => "\u{20B9}" ),
            'RUB' => array( 'name' => 'Russian ruble',        'symbol' => "\u{20BD}" ),
            'BRL' => array( 'name' => 'Brazilian real',       'symbol' => 'R$' ),
            'ZAR' => array( 'name' => 'South African rand',   'symbol' => 'R' ),
            'PLN' => array( 'name' => 'Polish zloty',         'symbol' => "z\u{0142}" ),
            'PHP' => array( 'name' => 'Philippine peso',      'symbol' => "\u{20B1}" ),
            'CZK' => array( 'name' => 'Czech koruna',         'symbol' => "K\u{010D}" ),
            'DKK' => array( 'name' => 'Danish krone',         'symbol' => 'kr' ),
            'HUF' => array( 'name' => 'Hungarian forint',     'symbol' => 'Ft' ),
            'ILS' => array( 'name' => 'Israeli new shekel',   'symbol' => "\u{20AA}" ),
            'MYR' => array( 'name' => 'Malaysian ringgit',    'symbol' => 'RM' ),
            'THB' => array( 'name' => 'Thai baht',            'symbol' => "\u{0E3F}" ),
            'TWD' => array( 'name' => 'New Taiwan dollar',    'symbol' => 'NT$' ),
            'AED' => array( 'name' => 'UAE dirham',           'symbol' => "\u{062F}.\u{0625}" ),
            'SAR' => array( 'name' => 'Saudi riyal',          'symbol' => "\u{FDFC}" ),
            'KWD' => array( 'name' => 'Kuwaiti dinar',        'symbol' => "\u{062F}.\u{0643}" ),
            'BHD' => array( 'name' => 'Bahraini dinar',       'symbol' => 'BD' ),
            'OMR' => array( 'name' => 'Omani rial',           'symbol' => "\u{FDFC}" ),
            'JOD' => array( 'name' => 'Jordanian dinar',      'symbol' => 'JD' ),
            'QAR' => array( 'name' => 'Qatari riyal',         'symbol' => "\u{FDFC}" ),
            'EGP' => array( 'name' => 'Egyptian pound',       'symbol' => "E\u{00A3}" ),
            'NGN' => array( 'name' => 'Nigerian naira',       'symbol' => "\u{20A6}" ),
            'KES' => array( 'name' => 'Kenyan shilling',      'symbol' => 'KSh' ),
            'GHS' => array( 'name' => 'Ghanaian cedi',        'symbol' => "GH\u{20B5}" ),
            'PKR' => array( 'name' => 'Pakistani rupee',      'symbol' => "\u{20A8}" ),
            'BDT' => array( 'name' => 'Bangladeshi taka',     'symbol' => "\u{09F3}" ),
            'LKR' => array( 'name' => 'Sri Lankan rupee',     'symbol' => 'Rs' ),
            'VND' => array( 'name' => 'Vietnamese dong',      'symbol' => "\u{20AB}" ),
            'IDR' => array( 'name' => 'Indonesian rupiah',    'symbol' => 'Rp' ),
            'RON' => array( 'name' => 'Romanian leu',         'symbol' => 'lei' ),
            'BGN' => array( 'name' => 'Bulgarian lev',        'symbol' => "\u{043B}\u{0432}" ),
            'UAH' => array( 'name' => 'Ukrainian hryvnia',    'symbol' => "\u{20B4}" ),
            'CLP' => array( 'name' => 'Chilean peso',         'symbol' => 'CL$' ),
            'COP' => array( 'name' => 'Colombian peso',       'symbol' => 'COL$' ),
            'ARS' => array( 'name' => 'Argentine peso',       'symbol' => 'AR$' ),
            'PEN' => array( 'name' => 'Peruvian sol',         'symbol' => 'S/' ),
            'TND' => array( 'name' => 'Tunisian dinar',       'symbol' => 'DT' ),
            'IQD' => array( 'name' => 'Iraqi dinar',          'symbol' => "\u{0639}.\u{062F}" ),
        );

        /**
         * Filter the ISO-4217 catalog offered by the admin Currencies picker.
         *
         * @param array<string, array{name:string, symbol:string}> $catalog
         */
        $filtered = apply_filters( 'tejcart_csw_supported_currencies', $catalog );

        if ( ! is_array( $filtered ) || empty( $filtered ) ) {
            return $catalog;
        }

        $clean = array();
        foreach ( $filtered as $code => $entry ) {
            $code = strtoupper( (string) $code );
            if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || ! is_array( $entry ) ) {
                continue;
            }
            $clean[ $code ] = array(
                'name'   => isset( $entry['name'] ) ? (string) $entry['name'] : $code,
                'symbol' => isset( $entry['symbol'] ) ? (string) $entry['symbol'] : $code,
            );
        }
        return $clean ?: $catalog;
    }

    /**
     * Build a `<select>` option label for one currency.
     *
     * Format: `EUR — Euro (€)`. The em-dash is stable so a JS catalog
     * keyed by code can still split the label client-side.
     */
    public static function format_option_label( string $code ): string {
        $catalog = self::all();
        $code    = strtoupper( $code );
        $entry   = $catalog[ $code ] ?? null;
        if ( null === $entry ) {
            return $code;
        }
        return sprintf( '%s — %s (%s)', $code, $entry['name'], $entry['symbol'] );
    }

    /**
     * True when `$code` is in the (possibly filtered) supported catalog.
     */
    public static function is_supported( string $code ): bool {
        $catalog = self::all();
        return isset( $catalog[ strtoupper( $code ) ] );
    }
}
