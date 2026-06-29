<?php
/**
 * Module bootstrap singleton.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher;

use TejCart\Currency_Switcher\Admin\Admin_Order_Context;
use TejCart\Currency_Switcher\Admin\Admin_Page;
use TejCart\Currency_Switcher\API\Ajax_Refresh;
use TejCart\Currency_Switcher\Checkout\Checkout_Controller;
use TejCart\Currency_Switcher\Checkout\Gateway_Filter;
use TejCart\Currency_Switcher\Conversion\Price_Filters;
use TejCart\Currency_Switcher\Conversion\Source_Conversion;
use TejCart\Currency_Switcher\Frontend\Asset_Loader;
use TejCart\Currency_Switcher\Frontend\Cache_Headers;
use TejCart\Currency_Switcher\Order\Order_Meta_Writer;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;
use TejCart\Currency_Switcher\Switcher\Switcher_Controller;
use TejCart\Currency_Switcher\Switcher\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Singleton wiring every sub-component on `tejcart_init`.
 *
 * Each collaborator is created once and registers its own hooks. Tests
 * exercise the collaborators directly — Plugin itself is just glue.
 *
 * Registers a dedicated `tejcart_csw` cache group so Redis / Memcached
 * drop-ins namespace our hot reads alongside the other TejCart
 * persistent groups (see `\TejCart\Core\Performance::$global_groups`).
 * Also wires option-update listeners that drop the in-process memos
 * the resolver + repository hold, so admin saves are visible to the
 * same request that issued them.
 */
final class Plugin {
    /** Persistent object-cache group for our hot reads. */
    public const CACHE_GROUP = 'tejcart_csw';

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

        $this->register_cache_group();
        $this->register_memo_invalidation();

        ( new Switcher_Controller() )->register();
        ( new Shortcodes() )->register();
        // Source-side conversion must register before the display filters
        // so product/cart/shipping/coupon amounts are already in the
        // active currency by the time anything formats them. Price_Filters
        // now only swaps the currency code / symbol / number formatting —
        // the amount conversion lives in Source_Conversion.
        ( new Source_Conversion() )->register();
        ( new Price_Filters() )->register();
        ( new Order_Meta_Writer() )->register();
        ( new Gateway_Filter() )->register();
        ( new Checkout_Controller() )->register();
        ( new Cache_Headers() )->register();
        ( new Asset_Loader() )->register();
        ( new Ajax_Refresh() )->register();

        if ( is_admin() ) {
            ( new Admin_Page() )->register();
            // The order detail screen pins display to the order's
            // transacted currency; every other admin screen falls
            // through to base. See Admin_Order_Context for the
            // industry-standard rationale.
            ( new Admin_Order_Context() )->register();
        }
    }

    /**
     * Tell the persistent object cache (Redis, Memcached) that our
     * group is global so multi-blog installs share entries and
     * drop-ins can apply per-group eviction / serialization rules.
     */
    private function register_cache_group(): void {
        if ( function_exists( 'wp_cache_add_global_groups' ) ) {
            wp_cache_add_global_groups( array( self::CACHE_GROUP ) );
        }
    }

    /**
     * Whenever an admin saves the currency map or the base currency,
     * drop the per-request memos. Without this, an admin who saved a
     * new rate and reloaded the same request (via a redirect) would
     * still see the stale value because the memo was set before the
     * `update_option` call.
     */
    private function register_memo_invalidation(): void {
        $flush = static function (): void {
            Currency_Repository::flush_shared();
            Currency_Resolver::flush_shared();
        };
        add_action( 'update_option_' . Options::CURRENCIES, $flush );
        add_action( 'add_option_'    . Options::CURRENCIES, $flush );
        add_action( 'update_option_tejcart_currency', $flush );
    }
}
