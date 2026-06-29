<?php
/**
 * Installer — first-run setup.
 *
 * @package TejCart\AI_Content_Smartsuite\Install
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Install;

use TejCart\AI_Content_Smartsuite\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Installer {
    public const VERSION_OPTION = 'tejcart_ai_content_db_version';

    public static function install(): void {
        $existing = get_option( Settings::OPTION_KEY, null );
        if ( ! is_array( $existing ) ) {
            update_option(
                Settings::OPTION_KEY,
                array(
                    'provider' => Settings::PROVIDER_OPENAI,
                    'model'    => Settings::ALLOWED_MODELS[0],
                    'api_key'  => '',
                    'language' => '',
                    'prompts'  => array(),
                ),
                false
            );
        }

        update_option( self::VERSION_OPTION, TEJCART_VERSION, false );

        // Legacy bookkeeping — earlier versions stored a flag here to
        // bounce the admin to the API settings tab on the next request.
        // The Modules page now owns the post-toggle UX (success notice
        // on the same page), so clear any stale flag from prior installs.
        delete_option( 'tejcart_ai_content_activation_redirect' );
    }

    public static function deactivate(): void {
        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            \TejCart\Core\Action_Scheduler::instance()->cancel( 'tejcart_ai_content_generate_single' );
        }
    }
}
