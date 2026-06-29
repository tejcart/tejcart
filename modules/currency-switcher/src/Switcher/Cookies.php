<?php
/**
 * Cookie helpers for the manual + geo currency cookies.
 *
 * @package TejCart\Currency_Switcher\Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Switcher;

use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes the two currency cookies with consistent flags:
 *
 *  - 30 day lifetime
 *  - HttpOnly
 *  - Secure when SSL is in use
 *  - SameSite=Lax
 *  - path/domain from COOKIEPATH / COOKIE_DOMAIN
 *
 * Mirrors the new value into `$_COOKIE` and drops the resolver memo so
 * the same request — e.g. an AJAX response that immediately renders
 * cart fragments — picks up the new currency without an extra roundtrip.
 */
final class Cookies {
    public const LIFETIME_DAYS = 30;

    public function set_currency( string $code ): bool {
        return $this->write( Options::COOKIE_CURRENCY, $code );
    }

    public function set_geo_currency( string $code ): bool {
        return $this->write( Options::COOKIE_CURRENCY_GEO, $code );
    }

    /**
     * Common setcookie wrapper.
     *
     * The `$_COOKIE` superglobal is only mirrored after we've confirmed
     * that the response cookie can actually be written. Updating
     * `$_COOKIE` even when `headers_sent()` is true creates a
     * "split-brain" state where the in-process resolver sees the new
     * currency but no Set-Cookie ever reaches the browser, so the next
     * page load reverts — silently breaking the switch from the
     * customer's point of view while looking correct to the server.
     */
    private function write( string $name, string $code ): bool {
        $code = strtoupper( $code );
        if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return false;
        }

        if ( headers_sent() ) {
            return false;
        }

        $expires = time() + ( self::LIFETIME_DAYS * DAY_IN_SECONDS );
        $secure  = ( function_exists( 'is_ssl' ) && is_ssl() );

        /**
         * Filter the SameSite attribute on the currency-switcher cookie.
         *
         * Default `Lax` works for most stores but breaks cross-site
         * redirect POSTs returning from 3DS challenges, hosted-checkout
         * payment providers, and bank-redirect flows — those checkout
         * flows would lose the buyer's chosen currency between leaving
         * the store and returning. Override to `None` when the cookie
         * must survive a cross-site redirect (requires Secure, which we
         * already set under HTTPS).
         *
         * @param string $samesite One of `Lax`, `Strict`, `None`.
         */
        $samesite = (string) apply_filters( 'tejcart_csw_cookie_samesite', 'Lax' );
        if ( ! in_array( $samesite, array( 'Lax', 'Strict', 'None' ), true ) ) {
            $samesite = 'Lax';
        }
        // SameSite=None requires Secure per RFC 6265bis; fall back to
        // Lax over plain HTTP to avoid the browser silently rejecting
        // the cookie.
        if ( 'None' === $samesite && ! $secure ) {
            $samesite = 'Lax';
        }

        $sent = setcookie(
            $name,
            $code,
            array(
                'expires'  => $expires,
                'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => $samesite,
            )
        );

        if ( $sent ) {
            // Mirror to $_COOKIE only after the Set-Cookie header was
            // accepted, so the resolver's view of the world stays in
            // sync with what the browser will receive.
            $_COOKIE[ $name ] = $code;
            Currency_Resolver::flush_shared();
        }

        return $sent;
    }
}
