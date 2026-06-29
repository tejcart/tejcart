<?php
/**
 * Network safety helpers.
 *
 * Shared SSRF-protection utilities used by outbound requests (webhooks, image
 * sideload, etc.). Keeps the private-range / cloud-metadata allowlist in a
 * single place so every caller gets the same coverage.
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static helpers for validating outbound URLs and IPs.
 */
class Network {
    /**
     * Hostnames that must never be contacted.
     *
     * @var string[]
     */
    private const BLOCKED_HOSTS = array(
        'localhost',
        'localhost.localdomain',
        '0',
        '0.0.0.0',
        '::',
        '::1',
    );

    /**
     * DNS suffixes used by split-horizon / internal-only zones.
     *
     * @var string[]
     */
    private const BLOCKED_SUFFIXES = array(
        '.local',
        '.localhost',
        '.internal',
        '.intranet',
        '.corp',
        '.lan',
        '.home',
    );

    /**
     * Known cloud-metadata / link-local endpoints.
     *
     * @var string[]
     */
    private const METADATA_IPS = array(
        '169.254.169.254',
        '100.100.100.200',
        'fd00:ec2::254',
    );

    /**
     * Whether the given IP (v4 or v6) is in a private, reserved, or loopback range.
     *
     * Delegates to PHP's built-in filter flags, which cover:
     *   - IPv4: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 and other reserved.
     *   - IPv6: ::1/128, fc00::/7, fe80::/10 and other reserved.
     *
     * Returns true for inputs that are not a valid IP so callers fail closed.
     *
     * @param string $ip IP address string.
     * @return bool
     */
    public static function is_private_ip( string $ip ): bool {
        if ( '' === $ip ) {
            return true;
        }

        if ( in_array( $ip, self::METADATA_IPS, true ) ) {
            return true;
        }

        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return false === $public;
    }

    /**
     * Whether the given URL is safe to contact from the server.
     *
     * Rejects:
     *   - Non-http(s) schemes (file://, ftp://, gopher://, …).
     *   - Blocked hostnames / suffixes (localhost, .internal, etc.).
     *   - Hostnames that resolve to any private, reserved, or link-local IP.
     *   - Hostnames that fail to resolve at all.
     *
     * @param string $url  URL to check.
     * @param array  $args Optional overrides:
     *                     - schemes (string[]): allowlist, default ['http','https'].
     * @return bool
     */
    public static function is_safe_remote_url( string $url, array $args = array() ): bool {
        if ( '' === $url ) {
            return false;
        }

        $schemes = isset( $args['schemes'] ) && is_array( $args['schemes'] )
            ? array_map( 'strtolower', $args['schemes'] )
            : array( 'http', 'https' );

        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) ) {
            return false;
        }

        if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), $schemes, true ) ) {
            return false;
        }

        $host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
        if ( '' === $host ) {
            return false;
        }

        $bare_host = trim( $host, '[]' );

        if ( in_array( $bare_host, self::BLOCKED_HOSTS, true ) ) {
            return false;
        }

        foreach ( self::BLOCKED_SUFFIXES as $suffix ) {
            if ( substr( $bare_host, -strlen( $suffix ) ) === $suffix ) {
                return false;
            }
        }

        $ips = self::resolve_host( $bare_host );
        if ( empty( $ips ) ) {
            return false;
        }

        foreach ( $ips as $ip ) {
            if ( self::is_private_ip( $ip ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a host to its A/AAAA addresses.
     *
     * Returns the literal value for IPs, otherwise uses `dns_get_record` so the
     * lookup has an explicit timeout path (unlike `gethostbyname`). Filterable
     * via `tejcart_resolve_host` so tests can stub the lookup deterministically.
     *
     * @param string $host Hostname or IP literal.
     * @return string[] IP addresses, empty array on failure.
     */
    public static function resolve_host( string $host ): array {
        if ( '' === $host ) {
            return array();
        }

        /**
         * Short-circuit host resolution.
         *
         * Return an array of IPs to skip the system resolver, or null to
         * continue with the default lookup. Intended for tests and hosts
         * that want to inject a pinned IP.
         *
         * @param array|null $ips  IP list (string[]), or null to fall through.
         * @param string     $host Hostname being resolved.
         */
        $override = apply_filters( 'tejcart_resolve_host', null, $host );
        if ( is_array( $override ) ) {
            return array_values( array_filter( array_map( 'strval', $override ) ) );
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return array( $host );
        }

        $ips = array();
        foreach ( array( DNS_A, DNS_AAAA ) as $type ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $records = @dns_get_record( $host, $type );
            if ( ! is_array( $records ) ) {
                continue;
            }
            foreach ( $records as $rec ) {
                if ( ! empty( $rec['ip'] ) ) {
                    $ips[] = (string) $rec['ip'];
                } elseif ( ! empty( $rec['ipv6'] ) ) {
                    $ips[] = (string) $rec['ipv6'];
                }
            }
        }

        return $ips;
    }
}
