<?php
/**
 * Locale catalogue shown in the Prompt Templates sub-section.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Languages {
    /**
     * Ordered (locale => language-name) map.
     *
     * @return array<string,string>
     */
    public static function all(): array {
        return array(
            'en_US' => 'English',
            'en_GB' => 'English (British)',
            'es_ES' => 'Spanish',
            'es_MX' => 'Spanish (Latin American)',
            'fr_FR' => 'French',
            'de_DE' => 'German',
            'it_IT' => 'Italian',
            'pt_PT' => 'Portuguese',
            'pt_BR' => 'Portuguese (Brazilian)',
            'nl_NL' => 'Dutch',
            'sv_SE' => 'Swedish',
            'no_NO' => 'Norwegian',
            'da_DK' => 'Danish',
            'fi_FI' => 'Finnish',
            'pl_PL' => 'Polish',
            'cs_CZ' => 'Czech',
            'hu_HU' => 'Hungarian',
            'ro_RO' => 'Romanian',
            'el_GR' => 'Greek',
            'tr_TR' => 'Turkish',
            'ru_RU' => 'Russian',
            'uk_UA' => 'Ukrainian',
            'ar'    => 'Arabic',
            'he_IL' => 'Hebrew',
            'fa_IR' => 'Persian',
            'hi_IN' => 'Hindi',
            'bn_BD' => 'Bengali',
            'ur'    => 'Urdu',
            'ta_IN' => 'Tamil',
            'th'    => 'Thai',
            'vi'    => 'Vietnamese',
            'id_ID' => 'Indonesian',
            'ms_MY' => 'Malay',
            'tl'    => 'Tagalog',
            'ja'    => 'Japanese',
            'ko_KR' => 'Korean',
            'zh_CN' => 'Chinese (Simplified)',
            'zh_TW' => 'Chinese (Traditional)',
        );
    }

    public static function label_for( string $locale ): string {
        $map = self::all();
        return $map[ $locale ] ?? 'English';
    }

    public static function is_english( string $locale ): bool {
        return in_array( $locale, array( 'en_US', 'en_GB' ), true );
    }

    public static function default_locale(): string {
        $wp = function_exists( 'determine_locale' ) ? determine_locale() : 'en_US';
        if ( '' === (string) $wp ) {
            return 'en_US';
        }
        $map = self::all();
        if ( array_key_exists( $wp, $map ) ) {
            return $wp;
        }
        $lang = strtolower( substr( (string) $wp, 0, 2 ) );
        foreach ( $map as $code => $_label ) {
            if ( strtolower( substr( $code, 0, 2 ) ) === $lang ) {
                return $code;
            }
        }
        return 'en_US';
    }
}
