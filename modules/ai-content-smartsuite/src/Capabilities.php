<?php
/**
 * Module capability gates.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite;

use TejCart\Core\Capabilities as Core_Caps;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Capabilities {
    /** Umbrella admin cap required for every AI Content screen / endpoint. */
    public const MANAGE = Core_Caps::MANAGE_STORE;

    /**
     * Nonce action shared by every authenticated AJAX endpoint the
     * module exposes (Generator page, Settings validate-key script,
     * Ajax_Router). Centralised here so a future capability split
     * (e.g. moving Settings under a different gate) doesn't break the
     * cross-class reference Settings_Tab → Generator_Page::NONCE_AJAX.
     */
    public const NONCE_AJAX = 'tejcart_ai_content_ajax';

    public static function current_user_can_manage(): bool {
        return function_exists( 'tejcart_can' ) && tejcart_can( self::MANAGE );
    }
}
