<?php
/**
 * Emit standard security response headers (S-3).
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds:
 *   - Strict-Transport-Security  (HSTS, only when is_ssl())
 *   - X-Content-Type-Options
 *   - Referrer-Policy
 *   - Permissions-Policy
 *   - Content-Security-Policy-Report-Only  (CSP, report-only by default
 *     so operators can safely roll out before flipping to enforce)
 *
 * Each header is filterable; the CSP is also tunable via the
 * `tejcart_content_security_policy_extra_directives` filter for sibling
 * gateways (Stripe, Authorize.Net) to widen `script-src` / `frame-src`.
 *
 * PCI-DSS 4.0 §6.4.3 (effective 2025-03-31) requires a documented CSP for
 * pages embedding hosted payment iframes; SAQ-A scope auditors flag the
 * absence at quarterly review.
 */
class Security_Headers {

    public function init(): void {
        add_action( 'send_headers', array( $this, 'emit' ), 20 );
    }

    public function emit(): void {
        if ( is_admin() || ( function_exists( 'headers_sent' ) && headers_sent() ) ) {
            return;
        }

        if ( function_exists( 'is_ssl' ) && is_ssl() ) {
            $hsts = (string) apply_filters(
                'tejcart_strict_transport_security',
                'max-age=31536000; includeSubDomains; preload'
            );
            if ( '' !== $hsts ) {
                header( 'Strict-Transport-Security: ' . $hsts );
            }
        }

        $xcto = (string) apply_filters( 'tejcart_x_content_type_options', 'nosniff' );
        if ( '' !== $xcto ) {
            header( 'X-Content-Type-Options: ' . $xcto );
        }

        $referrer = (string) apply_filters(
            'tejcart_referrer_policy',
            'strict-origin-when-cross-origin'
        );
        if ( '' !== $referrer ) {
            header( 'Referrer-Policy: ' . $referrer );
        }

        // The `payment` allowlist must name every origin whose document calls
        // the Payment Request API, or that origin's wallet sheet is refused
        // with "payment is not allowed in this document". PayPal/Venmo/Card/Pay
        // Later run under *.paypal.com; Apple Pay runs in the top document
        // (self); Google Pay's payment sheet is an https://pay.google.com
        // iframe, so it must be listed explicitly here — script-src/frame-src
        // allowing pay.google.com is not enough on its own.
        $permissions = (string) apply_filters(
            'tejcart_permissions_policy',
            'payment=(self "https://*.paypal.com" "https://*.stripe.com" "https://pay.google.com"), geolocation=(), microphone=(), camera=()'
        );
        if ( '' !== $permissions ) {
            header( 'Permissions-Policy: ' . $permissions );
        }

        $csp_directives = $this->csp_directives();
        if ( ! empty( $csp_directives ) ) {
            $csp_string = implode( '; ', array_map(
                static function ( $name, $value ) {
                    return $name . ' ' . $value;
                },
                array_keys( $csp_directives ),
                $csp_directives
            ) );

            $report_only = (bool) apply_filters( 'tejcart_csp_report_only', true );
            $header_name = $report_only
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            header( $header_name . ': ' . $csp_string );
        }
    }

    /**
     * Build the CSP directive map. Filterable per-directive so siblings
     * can extend `script-src` / `frame-src` without rebuilding the whole
     * policy.
     *
     * @return array<string, string>
     */
    private function csp_directives(): array {
        $defaults = array(
            'default-src'     => "'self'",
            'script-src'      => "'self' https://www.paypal.com https://*.paypal.com https://pay.google.com https://applepay.cdn-apple.com https://static.cloudflareinsights.com 'unsafe-inline'",
            'frame-src'       => 'https://www.paypal.com https://*.paypal.com https://pay.google.com',
            'img-src'         => "'self' data: https:",
            'style-src'       => "'self' 'unsafe-inline'",
            // Fonts: themes and icon libraries routinely inline WOFF/WOFF2 as
            // `data:` URIs and pull web fonts from CDNs (e.g. fonts.gstatic.com).
            // Without an explicit `font-src` these fall back to `default-src
            // 'self'` and the report-only policy flags every `data:` font, so
            // flipping the CSP to enforce would break them. Mirror the `img-src`
            // posture ('self' data: https:) — fonts are a low-risk resource type
            // and this keeps storefront themes working under an enforced policy.
            'font-src'        => "'self' data: https:",
            'connect-src'     => "'self' https://www.paypal.com https://*.paypal.com https://api-m.paypal.com https://api-m.sandbox.paypal.com https://pay.google.com https://google.com https://www.google.com https://cloudflareinsights.com https://*.cloudflareinsights.com",
            'frame-ancestors' => "'self'",
            'base-uri'        => "'self'",
            'form-action'     => "'self' https://www.paypal.com",
        );

        /**
         * Filter the per-directive CSP map.
         *
         * Sibling gateways (Stripe, Authorize.Net) hook this to widen
         * `script-src` and `frame-src` for their hosted-payment iframes.
         *
         * @param array<string, string> $directives
         */
        $directives = (array) apply_filters( 'tejcart_csp_directives', $defaults );

        // Operators can also append extra directives (e.g. `report-uri`)
        // without overriding the curated defaults.
        $extras = (array) apply_filters( 'tejcart_content_security_policy_extra_directives', array() );
        foreach ( $extras as $name => $value ) {
            $directives[ (string) $name ] = (string) $value;
        }

        return $directives;
    }
}
