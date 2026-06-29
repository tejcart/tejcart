<?php
/**
 * Flag renderer for the currency switcher UI.
 *
 * Renders an `<img>` tag pointing at the SVG file in
 * `assets/css/flag/<cc>.svg`. The flag set ships 270+ ISO 3166-1
 * alpha-2 codes plus a handful of supranational flags (EU, Arab
 * League, ASEAN, CEFTA). When a country code has no matching file,
 * we fall back to an inline placeholder SVG (gray box + uppercase
 * code) so the UI never breaks on an unknown flag.
 *
 * @package TejCart\Currency_Switcher\Flag
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Flag;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Flag_SVG {
    /**
     * Cached list of available flag codes (lowercase basename minus `.svg`).
     *
     * @var string[]|null
     */
    private static $available = null;

    /**
     * Render the flag markup for a country code. Returns an `<img>`
     * tag when the SVG file exists, otherwise a placeholder inline SVG.
     */
    public static function render( string $country_code ): string {
        $cc = strtolower( trim( $country_code ) );
        if ( '' === $cc || ! preg_match( '/^[a-z0-9-]{2,8}$/', $cc ) ) {
            return self::placeholder( $country_code );
        }

        if ( ! self::file_exists( $cc ) ) {
            return self::placeholder( $cc );
        }

        $url = self::flag_url( $cc );
        if ( '' === $url ) {
            return self::placeholder( $cc );
        }

        return sprintf(
            '<img class="tejcart-csw-flag-svg" src="%s" alt="" aria-hidden="true" focusable="false" loading="lazy" decoding="async" width="60" height="40" />',
            htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' )
        );
    }

    /**
     * Lowercase country codes that have a ready-to-serve SVG flag file.
     * Filtered to standard 2-letter ISO 3166-1 alpha-2 codes — the
     * subdivision and supranational files (`gb-eng`, `arab`, `eu`, …)
     * are still served by {@see self::render()} when requested directly.
     *
     * @return string[]
     */
    public static function supported(): array {
        $codes = array_filter(
            self::available(),
            static fn( string $code ): bool => 1 === preg_match( '/^[a-z]{2}$/', $code )
        );
        sort( $codes );
        return array_values( $codes );
    }

    /**
     * Whether the SVG file for the given (already-normalised) country
     * code is shipped with the module.
     */
    private static function file_exists( string $cc ): bool {
        return in_array( $cc, self::available(), true );
    }

    /**
     * Public URL for a flag SVG. Uses `TEJCART_CSW_URL` (set in the
     * module bootstrap) and is filterable via `tejcart_csw_flag_url`
     * so themes can swap in a CDN.
     */
    private static function flag_url( string $cc ): string {
        $url = defined( 'TEJCART_CSW_URL' )
            ? TEJCART_CSW_URL . 'assets/css/flag/' . $cc . '.svg'
            : '';
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'tejcart_csw_flag_url', $url, $cc, $cc );
            if ( is_string( $filtered ) ) {
                $url = $filtered;
            }
        }
        return $url;
    }

    /**
     * Discover the shipped flag set by scanning `assets/css/flag/`.
     * The result is cached for the request.
     *
     * @return string[]
     */
    private static function available(): array {
        if ( null !== self::$available ) {
            return self::$available;
        }
        $dir = self::flag_dir();
        if ( '' === $dir || ! is_dir( $dir ) ) {
            self::$available = array();
            return self::$available;
        }
        $files = glob( $dir . '*.svg' );
        if ( ! is_array( $files ) ) {
            self::$available = array();
            return self::$available;
        }
        $codes = array();
        foreach ( $files as $file ) {
            $codes[] = strtolower( basename( $file, '.svg' ) );
        }
        self::$available = $codes;
        return self::$available;
    }

    private static function flag_dir(): string {
        if ( defined( 'TEJCART_CSW_DIR' ) ) {
            return rtrim( TEJCART_CSW_DIR, '/\\' ) . '/assets/css/flag/';
        }
        // Fallback when the module bootstrap hasn't run (e.g. unit
        // tests that autoload the class directly): resolve relative
        // to this file's location.
        return dirname( __DIR__, 2 ) . '/assets/css/flag/';
    }

    private static function placeholder( string $cc ): string {
        $label = strtoupper( substr( trim( $cc ), 0, 2 ) );
        if ( '' === $label ) {
            $label = '??';
        }
        return '<svg class="tejcart-csw-flag-svg" viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" preserveAspectRatio="xMidYMid slice">'
            . '<rect width="60" height="40" fill="#e2e8f0"/>'
            . '<text x="30" y="25" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14" font-weight="700" fill="#475569">'
            . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
            . '</text></svg>';
    }
}
