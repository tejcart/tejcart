<?php
/**
 * Settings repository — single source of truth for the `tejcart_ai_content_settings`
 * option, with at-rest encryption of the API key.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite;

use TejCart\AI_Content_Smartsuite\AI\Provider_Registry;
use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings {
    public const OPTION_KEY = 'tejcart_ai_content_settings';

    public const PROVIDER_OPENAI = 'openai';

    public const ALLOWED_MODELS = array(
        'gpt-4o-mini',
        'gpt-4o',
        'gpt-4.1-mini',
        'gpt-4.1-nano',
        'gpt-4.1',
        'gpt-4-turbo',
        'gpt-3.5-turbo',
    );

    /**
     * Filtered model allowlist. Addons can extend via
     * `tejcart_ai_content_allowed_models`.
     *
     * @return string[]
     */
    public static function allowed_models(): array {
        $models = (array) apply_filters( 'tejcart_ai_content_allowed_models', self::ALLOWED_MODELS );
        return array_values( array_filter( $models, 'is_string' ) );
    }

    /**
     * @return array{
     *     provider:string,
     *     model:string,
     *     api_key:string,
     *     language:string,
     *     prompts:array<string,string>
     * }
     */
    public static function get(): array {
        $raw = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $stored_key = (string) ( $raw['api_key'] ?? '' );
        $api_key    = '';
        if ( '' !== $stored_key ) {
            try {
                $api_key = Crypto::decrypt( $stored_key );
            } catch ( \Throwable $e ) {
                $api_key = '';
                tejcart_log(
                    'AI Content: failed to decrypt stored API key',
                    'error',
                    array( 'source' => 'ai_content_smartsuite' )
                );
            }
        }

        $defaults_prompts = Default_Prompts::all();
        $prompts          = array();
        $raw_prompts      = is_array( $raw['prompts'] ?? null ) ? $raw['prompts'] : array();
        foreach ( $defaults_prompts as $k => $default ) {
            $val           = isset( $raw_prompts[ $k ] ) ? (string) $raw_prompts[ $k ] : '';
            $prompts[ $k ] = '' !== $val ? $val : $default;
        }

        $allowed = self::allowed_models();
        $model   = (string) ( $raw['model'] ?? ( $allowed[0] ?? self::ALLOWED_MODELS[0] ) );
        if ( ! in_array( $model, $allowed, true ) ) {
            $model = $allowed[0] ?? self::ALLOWED_MODELS[0];
        }

        $language = (string) ( $raw['language'] ?? '' );
        if ( '' === $language ) {
            $language = Languages::default_locale();
        }

        $provider = self::resolve_provider( (string) ( $raw['provider'] ?? '' ) );

        $temperature = isset( $raw['temperature'] ) ? (float) $raw['temperature'] : 0.7;
        $temperature = max( 0.0, min( 2.0, $temperature ) );

        return array(
            'provider'    => $provider,
            'model'       => $model,
            'api_key'     => $api_key,
            'language'    => $language,
            'prompts'     => $prompts,
            'temperature' => $temperature,
        );
    }

    /**
     * Validate a submitted/stored provider slug against the active
     * provider catalogue. Falls back to OpenAI when the value is
     * missing or refers to an inactive (e.g. "Coming Soon") provider.
     */
    private static function resolve_provider( string $candidate ): string {
        $candidate = trim( $candidate );
        if ( '' === $candidate ) {
            return self::PROVIDER_OPENAI;
        }
        if ( ! class_exists( Provider_Registry::class ) ) {
            return self::PROVIDER_OPENAI;
        }
        $active = Provider_Registry::active_slugs();
        if ( in_array( $candidate, $active, true ) ) {
            return $candidate;
        }
        return self::PROVIDER_OPENAI;
    }

    public static function has_api_key(): bool {
        $raw = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $raw ) ) {
            return false;
        }
        return '' !== (string) ( $raw['api_key'] ?? '' );
    }

    /**
     * @param array<string,mixed> $input  Raw POST body.
     * @return array{ok:bool,message:string}
     */
    public static function save_api( array $input ): array {
        $current = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        $allowed = self::allowed_models();
        $model   = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : '';
        if ( ! in_array( $model, $allowed, true ) ) {
            $model = $allowed[0] ?? self::ALLOWED_MODELS[0];
        }

        $new_key   = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
        $clear_key = ! empty( $input['clear_api_key'] );

        $stored_key = (string) ( $current['api_key'] ?? '' );
        if ( $clear_key ) {
            $stored_key = '';
        } elseif ( '' !== $new_key ) {
            try {
                $stored_key = Crypto::encrypt( $new_key );
            } catch ( \Throwable $e ) {
                return array(
                    'ok'      => false,
                    'message' => __( 'Could not encrypt the API key. Please check server configuration.', 'tejcart' ),
                );
            }
            if ( ! Crypto::is_encrypted( $stored_key ) ) {
                tejcart_log(
                    'AI Content: API key stored without encryption — openssl unavailable',
                    'warning',
                    array( 'source' => 'ai_content_smartsuite' )
                );
            }
        }

        // Honour the submitted provider slug when it maps to an active
        // entry in the provider registry (built-in or addon-registered);
        // otherwise fall back to OpenAI. Without this the dropdown was
        // decorative — the form's `provider` field was silently
        // discarded on every save.
        $submitted_provider  = isset( $input['provider'] ) ? sanitize_text_field( (string) $input['provider'] ) : '';
        $current['provider'] = self::resolve_provider( $submitted_provider );
        $current['model']    = $model;
        $current['api_key']  = $stored_key;

        $current['daily_token_budget']   = max( 0, (int) ( $input['daily_token_budget'] ?? ( $current['daily_token_budget'] ?? 0 ) ) );
        $current['hourly_request_limit'] = max( 0, (int) ( $input['hourly_request_limit'] ?? ( $current['hourly_request_limit'] ?? 0 ) ) );

        $temp = isset( $input['temperature'] ) ? (float) $input['temperature'] : 0.7;
        $current['temperature'] = max( 0.0, min( 2.0, round( $temp, 1 ) ) );

        update_option( self::OPTION_KEY, $current, false );

        return array(
            'ok'      => true,
            'message' => __( 'API settings saved.', 'tejcart' ),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,message:string}
     */
    public static function save_prompts( array $input ): array {
        $current = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        $lang = isset( $input['language'] ) ? sanitize_text_field( (string) $input['language'] ) : '';
        if ( ! array_key_exists( $lang, Languages::all() ) ) {
            $lang = Languages::default_locale();
        }
        $current['language'] = $lang;

        $raw     = is_array( $input['prompts'] ?? null ) ? $input['prompts'] : array();
        $prompts = array();
        foreach ( Default_Prompts::keys() as $k ) {
            $val           = isset( $raw[ $k ] ) ? (string) wp_unslash( $raw[ $k ] ) : '';
            $prompts[ $k ] = '' !== trim( $val ) ? sanitize_textarea_field( $val ) : Default_Prompts::get( $k );
        }
        $current['prompts'] = $prompts;

        update_option( self::OPTION_KEY, $current, false );

        return array(
            'ok'      => true,
            'message' => __( 'Prompt templates saved.', 'tejcart' ),
        );
    }

    public static function reset_prompts(): void {
        $current = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }
        $current['prompts'] = Default_Prompts::all();
        update_option( self::OPTION_KEY, $current, false );
    }
}
