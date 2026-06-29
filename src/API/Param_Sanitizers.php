<?php
/**
 * Helpers for REST controller parameter validation.
 *
 * Adds explicit sanitize_callback and validate_callback values to the
 * argument schemas declared in get_collection_params() / get_endpoint_args.
 * The WP REST API enforces `type` / `enum` on its own, but explicit
 * callbacks provide defense-in-depth against type-juggling edge cases and
 * make the intent obvious to a future reader.
 *
 * @package TejCart\API
 */

declare( strict_types=1 );

namespace TejCart\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Param_Sanitizers {
    /**
     * Resolve a sanitize callback for an arg schema.
     *
     * Recognises the common shapes used by TejCart controllers:
     *   - integer / number → absint / floatval
     *   - boolean          → rest_sanitize_boolean
     *   - email format     → sanitize_email
     *   - date format      → sanitize_date
     *   - everything else  → sanitize_text_field
     *
     * @param array $schema An arg schema fragment (must have `type`).
     * @return callable
     */
    public static function sanitizer( array $schema ): callable {
        // OpenAPI schemas allow `type` to be either a string ("integer")
        // or an array of strings (["integer","null"]). Pick the first
        // non-null entry when an array is supplied, falling back to
        // 'string' so casting never warns.
        $raw_type = $schema['type'] ?? 'string';
        if ( is_array( $raw_type ) ) {
            $raw_type = '';
            foreach ( (array) ( $schema['type'] ?? array() ) as $candidate ) {
                if ( is_string( $candidate ) && '' !== $candidate && 'null' !== $candidate ) {
                    $raw_type = $candidate;
                    break;
                }
            }
            if ( '' === $raw_type ) {
                $raw_type = 'string';
            }
        }
        $type     = is_scalar( $raw_type ) ? (string) $raw_type : 'string';
        $raw_fmt  = $schema['format'] ?? '';
        $format   = is_scalar( $raw_fmt ) ? (string) $raw_fmt : '';

        switch ( $type ) {
            case 'integer':
                return 'absint';
            case 'number':
                return static function ( $v ) {
                    return (float) $v;
                };
            case 'boolean':
                return 'rest_sanitize_boolean';
        }

        if ( 'email' === $format ) {
            return 'sanitize_email';
        }
        if ( 'date' === $format || 'date-time' === $format ) {
            return array( __CLASS__, 'sanitize_date' );
        }
        if ( 'uri' === $format || 'url' === $format ) {
            return 'esc_url_raw';
        }

        return 'sanitize_text_field';
    }

    /**
     * Sanitize a Y-m-d / Y-m-d H:i:s date string.
     *
     * Returns the empty string for input that doesn't match either format
     * so that downstream comparisons can rely on a non-malicious shape.
     *
     * @param mixed $value Raw input.
     * @return string
     */
    public static function sanitize_date( $value ): string {
        $value = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( '' === $value ) {
            return '';
        }
        if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
            return $value;
        }
        return '';
    }

    /**
     * Decorate an associative array of arg schemas with sanitize_callback,
     * skipping schemas that already declare one (so callers can override).
     *
     * @param array $params Map of arg-name => schema fragment.
     * @return array
     */
    public static function decorate( array $params ): array {
        foreach ( $params as $name => $schema ) {
            if ( ! is_array( $schema ) ) {
                continue;
            }
            if ( isset( $schema['sanitize_callback'] ) ) {
                continue;
            }
            $params[ $name ]['sanitize_callback'] = self::sanitizer( $schema );
        }
        return $params;
    }
}
