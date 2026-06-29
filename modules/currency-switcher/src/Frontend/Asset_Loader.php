<?php
/**
 * Enqueues the module's CSS + JS and localises a context object
 * (`tejcartCswPublicData`) so block checkout / theme JS can render
 * dual prices client-side.
 *
 * @package TejCart\Currency_Switcher\Frontend
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Frontend;

use TejCart\Currency_Switcher\Checkout\Checkout_Controller;
use TejCart\Currency_Switcher\Conversion\Converter;
use TejCart\Currency_Switcher\Currency_Catalog;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Options;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a single front-end script handle. Sites that don't enqueue
 * the script still get full server-side conversion — the JS is only
 * needed for the dual-price-mode reference numbers.
 */
final class Asset_Loader {
    public const HANDLE = 'tejcart-csw-public';

    public function register(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 20 );
    }

    public function enqueue(): void {
        if ( ! defined( 'TEJCART_CSW_URL' ) ) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            self::asset_url( 'assets/css/public.css' ),
            array(),
            TEJCART_CSW_VERSION
        );

        wp_register_script(
            self::HANDLE,
            self::asset_url( 'assets/js/public.js' ),
            array(),
            TEJCART_CSW_VERSION,
            true
        );
        wp_localize_script( self::HANDLE, 'tejcartCswPublicData', $this->localised_data() );
        wp_enqueue_script( self::HANDLE );
    }

    public function enqueue_admin( $hook = '' ): void {
        if ( ! defined( 'TEJCART_CSW_URL' ) ) {
            return;
        }
        if ( ! is_string( $hook ) ) {
            return;
        }
        // The Currency tab now renders inside the unified TejCart Settings
        // page; load assets only when the merchant is actually looking at it
        // (the legacy `tejcart_csw_*` standalone hook is gone). Tolerate both
        // `tejcart_page_tejcart-settings` and the toplevel form for sites
        // that re-parent the menu.
        if ( false === strpos( $hook, 'tejcart-settings' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        if ( \TejCart\Currency_Switcher\Admin\Admin_Page::TAB_ID !== $tab ) {
            return;
        }
        wp_enqueue_style(
            self::HANDLE . '-admin',
            self::asset_url( 'assets/css/admin.css' ),
            array(),
            TEJCART_CSW_VERSION
        );
        wp_register_script(
            self::HANDLE . '-admin',
            self::asset_url( 'assets/js/admin.js' ),
            array( 'jquery' ),
            TEJCART_CSW_VERSION,
            true
        );
        wp_localize_script(
            self::HANDLE . '-admin',
            'tejcartCswAdminData',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( Options::NONCE_ACTION ),
                'catalog'  => Currency_Catalog::all(),
                'i18n'     => array(
                    'confirmDelete' => __( 'Remove this currency from the storefront? You can re-add it later.', 'tejcart' ),
                    'fetchFailed'   => __( 'Could not fetch rate — keeping the current value.', 'tejcart' ),
                ),
            )
        );
        wp_enqueue_script( self::HANDLE . '-admin' );
    }

    /**
     * Module-local equivalent of core's `tejcart_asset_url()` — prefers
     * the `.min.css` / `.min.js` sibling when present and `SCRIPT_DEBUG`
     * is off. We can't reuse the core helper directly because it resolves
     * against `TEJCART_PLUGIN_DIR`, but our assets live under the
     * module directory.
     */
    private static function asset_url( string $relative_path ): string {
        $debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
        if ( ! $debug && preg_match( '/\.(css|js)$/', $relative_path ) ) {
            $min_relative = (string) preg_replace( '/\.(css|js)$/', '.min.$1', $relative_path );
            if ( '' !== $min_relative
                && defined( 'TEJCART_CSW_DIR' )
                && file_exists( TEJCART_CSW_DIR . $min_relative )
            ) {
                return TEJCART_CSW_URL . $min_relative;
            }
        }
        return TEJCART_CSW_URL . $relative_path;
    }

    /**
     * Build the localised data blob exposed to front-end JS.
     *
     * @return array<string, mixed>
     */
    public function localised_data(): array {
        $resolver = new Currency_Resolver();
        $repo     = new Currency_Repository();
        $base     = $repo->base_currency();
        $code     = $resolver->current();

        $cfg = $repo->get( $code );
        if ( null === $cfg ) {
            // Active is base currency — no dual-mode UI needed.
            return array(
                'checkoutDualMode' => false,
                'baseCurrency'     => $base,
                'displayCurrency'  => $base,
                'rate'             => 1.0,
            );
        }

        $dual_mode = ! Checkout_Controller::diff_currency_allowed() && $code !== $base;

        return array(
            'checkoutDualMode'   => $dual_mode,
            'baseCurrency'       => $base,
            'displayCurrency'    => $code,
            'rate'               => $cfg->effective_rate(),
            'displaySymbol'      => function_exists( 'tejcart_get_currency_symbol' )
                ? tejcart_get_currency_symbol( $code )
                : $code,
            'displayDecimals'    => $cfg->num_decimals,
            'displayDecimalSep'  => $cfg->decimal_sep,
            'displayThousandSep' => $cfg->thousand_sep,
            'displayCurrencyPos' => $cfg->currency_pos,
            'baseDecimalSep'     => (string) get_option( 'tejcart_decimal_separator', '.' ),
            'baseThousandSep'    => (string) get_option( 'tejcart_thousand_separator', ',' ),
            'noticeMessage'      => sprintf(
                /* translators: 1: base currency code, 2: base currency symbol */
                __( 'You will be charged in %1$s (%2$s).', 'tejcart' ),
                $base,
                function_exists( 'tejcart_get_currency_symbol' ) ? tejcart_get_currency_symbol( $base ) : $base
            ),
        );
    }
}
