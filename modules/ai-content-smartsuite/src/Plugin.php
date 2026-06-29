<?php
/**
 * AI Content SmartSuite — boot orchestrator.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite;

use TejCart\AI_Content_Smartsuite\Admin\Generator_Page;
use TejCart\AI_Content_Smartsuite\Admin\Settings_Tab;
use TejCart\AI_Content_Smartsuite\Ajax\Ajax_Router;
use TejCart\AI_Content_Smartsuite\Background\Job_Runner;
use TejCart\AI_Content_Smartsuite\Frontend\FAQ_Panel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {
    private static ?Plugin $instance = null;

    private bool $booted = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        Job_Runner::register();

        if ( is_admin() ) {
            Settings_Tab::register();
            Generator_Page::register();
            Ajax_Router::register();
        }

        FAQ_Panel::register();
    }
}
