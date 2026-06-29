<?php
/**
 * AI provider catalogue.
 *
 * @package TejCart\AI_Content_Smartsuite\AI
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Provider_Registry {
    /**
     * @return array<string, array{label:string, status:string}>
     */
    public static function all(): array {
        $providers = array(
            'openai' => array(
                'label'  => 'OpenAI',
                'status' => 'active',
            ),
            'gemini' => array(
                'label'  => 'Google Gemini (Coming Soon)',
                'status' => 'coming_soon',
            ),
            'deepseek' => array(
                'label'  => 'DeepSeek (Coming Soon)',
                'status' => 'coming_soon',
            ),
        );

        /**
         * Filter the AI provider catalogue.
         *
         * Addons can register additional providers (or mark a built-in
         * one as `coming_soon` / `inactive`) by adding entries to the
         * returned map. Each entry must be an array with at least:
         *
         *   label  string  Human-readable name shown in the dropdown.
         *   status string  'active' to enable; any other value disables it.
         *
         * The map is keyed by stable provider slug (`openai`, `gemini`,
         * etc.) which is what Settings persists in `Settings::get('provider')`.
         *
         * @param array<string, array{label:string, status:string}> $providers
         */
        $providers = (array) apply_filters( 'tejcart_ai_content_providers', $providers );

        // Defensive: drop entries that aren't well-formed so downstream
        // callers (Settings tab, dropdown rendering) can iterate safely.
        $clean = array();
        foreach ( $providers as $slug => $entry ) {
            if ( ! is_string( $slug ) || '' === $slug ) {
                continue;
            }
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $clean[ $slug ] = array(
                'label'  => isset( $entry['label'] ) ? (string) $entry['label'] : (string) $slug,
                'status' => isset( $entry['status'] ) ? (string) $entry['status'] : 'coming_soon',
            );
        }
        return $clean;
    }

    /**
     * Active provider slugs only — convenience for callers that need to
     * gate on selection.
     *
     * @return string[]
     */
    public static function active_slugs(): array {
        $out = array();
        foreach ( self::all() as $slug => $entry ) {
            if ( 'active' === ( $entry['status'] ?? '' ) ) {
                $out[] = $slug;
            }
        }
        return $out;
    }
}
