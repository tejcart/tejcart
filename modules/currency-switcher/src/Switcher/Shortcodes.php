<?php
/**
 * Front-end shortcodes for the currency switcher.
 *
 * @package TejCart\Currency_Switcher\Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Switcher;

use TejCart\Currency_Switcher\Checkout\Checkout_Controller;
use TejCart\Currency_Switcher\Currency_Catalog;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Flag\Flag_Map;
use TejCart\Currency_Switcher\Flag\Flag_SVG;
use TejCart\Currency_Switcher\Frontend\Page_Context;
use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Two visible shortcodes plus the auto-injected single-product hook:
 *
 *  - [tejcart_currency_switcher]         Dropdown form (flag + code)
 *  - [tejcart_currency_switcher_sidebar] Sticky sidebar list
 *
 * The sticky sidebar is also auto-injected in `wp_footer` for any page
 * whose key appears in {@see Options::SIDEBAR_PAGES}.
 */
final class Shortcodes {
    public function register(): void {
        add_shortcode( 'tejcart_currency_switcher',         array( $this, 'render_dropdown' ) );
        add_shortcode( 'tejcart_currency_switcher_sidebar', array( $this, 'render_sidebar' ) );
        add_action( 'wp_footer', array( $this, 'maybe_auto_inject_sidebar' ) );
        add_action( $this->product_action(), array( $this, 'maybe_render_on_product' ), 20 );
    }

    public function render_dropdown( $atts = array() ): string {
        if ( $this->should_suppress_on_checkout() ) {
            return '';
        }
        $atts = shortcode_atts( array( 'class' => '' ), (array) $atts, 'tejcart_currency_switcher' );

        $repo  = new Currency_Repository();
        $extra = $repo->all();
        if ( empty( $extra ) ) {
            return '';
        }

        $base    = $repo->base_currency();
        $codes   = array_merge( array( $base ), array_keys( $extra ) );
        $current = ( new Currency_Resolver() )->current();

        $class = trim( 'tejcart-csw-switcher tejcart-csw-dropdown ' . sanitize_html_class( (string) $atts['class'] ) );
        $nonce = wp_create_nonce( Options::NONCE_ACTION );

        $current_label = self::short_label( $current );
        $current_flag  = self::flag_svg( $current );

        $form  = '<form method="post" class="' . esc_attr( $class ) . '" action="" data-tejcart-csw-dropdown>';
        $form .= '<button type="button" class="tejcart-csw-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false">';
        $form .= '<span class="tejcart-csw-dropdown__flag">' . $current_flag . '</span>';
        $form .= '<span class="tejcart-csw-dropdown__code">' . esc_html( $current_label ) . '</span>';
        $form .= '<span class="tejcart-csw-dropdown__chevron" aria-hidden="true">'
            . '<svg viewBox="0 0 12 8" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '</span>';
        $form .= '</button>';

        $form .= '<ul class="tejcart-csw-dropdown__list" role="listbox" hidden>';
        foreach ( $codes as $code ) {
            $is_active = $code === $current;
            $symbol    = function_exists( 'tejcart_get_currency_symbol' )
                ? (string) tejcart_get_currency_symbol( $code )
                : '';
            $form .= '<li role="option" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" class="tejcart-csw-dropdown__option' . ( $is_active ? ' is-active' : '' ) . '" data-currency="' . esc_attr( $code ) . '">'
                . '<span class="tejcart-csw-dropdown__flag">' . self::flag_svg( $code ) . '</span>'
                . '<span class="tejcart-csw-dropdown__code">' . esc_html( $code ) . '</span>'
                . ( '' !== $symbol
                    ? '<span class="tejcart-csw-dropdown__symbol" aria-hidden="true">' . esc_html( $symbol ) . '</span>'
                    : '<span class="tejcart-csw-dropdown__symbol tejcart-csw-dropdown__symbol--text" aria-hidden="true">' . esc_html( self::full_name( $code ) ) . '</span>'
                )
                . '<span class="tejcart-csw-dropdown__check" aria-hidden="true">'
                . '<svg viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M3 7.5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . '</span>'
                . '</li>';
        }
        $form .= '</ul>';

        // Hidden native select keeps the form usable when JS is disabled
        // and on screen readers that haven't implemented `role="listbox"`
        // correctly. The custom UI mirrors its value via public.js.
        $form .= '<select name="tejcart_csw_selected_currency" class="tejcart-csw-dropdown__select" aria-label="' . esc_attr__( 'Currency', 'tejcart' ) . '">';
        foreach ( $codes as $code ) {
            $form .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr( $code ),
                selected( $code, $current, false ),
                esc_html( self::short_label( $code ) )
            );
        }
        $form .= '</select>';
        $form .= '<input type="hidden" name="tejcart_csw_nonce" value="' . esc_attr( $nonce ) . '" />';
        $form .= '<noscript><button type="submit" class="tejcart-csw-dropdown__submit">' . esc_html__( 'Update', 'tejcart' ) . '</button></noscript>';
        $form .= '</form>';

        return $form;
    }

    public function render_sidebar( $atts = array() ): string {
        if ( $this->should_suppress_on_checkout() ) {
            return '';
        }

        $repo  = new Currency_Repository();
        $extra = $repo->all();
        if ( empty( $extra ) ) {
            return '';
        }

        $atts = shortcode_atts( array( 'position' => '' ), (array) $atts, 'tejcart_currency_switcher_sidebar' );

        $position = (string) $atts['position'];
        if ( '' === $position ) {
            $position = (string) get_option( Options::SIDEBAR_POSITION, 'right' );
        }
        if ( ! in_array( $position, array( 'left', 'right' ), true ) ) {
            $position = 'right';
        }

        $design = (string) get_option( Options::SIDEBAR_DESIGN, 'card' );
        if ( ! in_array( $design, array( 'card', 'compact' ), true ) ) {
            $design = 'card';
        }

        $base    = $repo->base_currency();
        $current = ( new Currency_Resolver() )->current();
        $codes   = array_merge( array( $base ), array_keys( $extra ) );

        $show_flag   = 1 === (int) get_option( Options::SHOW_FLAG, 1 );
        $show_code   = 1 === (int) get_option( Options::SHOW_CODE, 1 );
        $show_symbol = 1 === (int) get_option( Options::SHOW_SYMBOL, 1 );

        $html  = '<div class="tejcart-csw-sidebar tejcart-csw-sidebar--' . esc_attr( $position ) . ' tejcart-csw-sidebar--' . esc_attr( $design ) . '" role="region" aria-label="' . esc_attr__( 'Currency switcher', 'tejcart' ) . '" data-tejcart-csw-sidebar>';
        // Mobile-only FAB pill. Hidden on desktop via CSS; opens the
        // sidebar list as a bottom sheet on touch viewports.
        $html .= '<button type="button" class="tejcart-csw-sidebar__fab" aria-haspopup="dialog" aria-expanded="false" aria-label="' . esc_attr__( 'Open currency switcher', 'tejcart' ) . '">';
        if ( $show_flag ) {
            $html .= '<span class="tejcart-csw-sidebar__fab-flag">' . self::flag_svg( $current ) . '</span>';
        }
        $html .= '<span class="tejcart-csw-sidebar__fab-code">' . esc_html( $current ) . '</span>';
        $html .= '<span class="tejcart-csw-sidebar__fab-chevron" aria-hidden="true">'
            . '<svg viewBox="0 0 12 8" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '</span>';
        $html .= '</button>';
        $html .= '<div class="tejcart-csw-sidebar__sheet-header" aria-hidden="true">'
            . '<span class="tejcart-csw-sidebar__sheet-grabber"></span>'
            . '<span class="tejcart-csw-sidebar__sheet-title">' . esc_html__( 'Select currency', 'tejcart' ) . '</span>'
            . '<button type="button" class="tejcart-csw-sidebar__sheet-close" aria-label="' . esc_attr__( 'Close', 'tejcart' ) . '">'
            . '<svg viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M3 3l8 8 M11 3l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
            . '</button>'
            . '</div>';
        $html .= '<ul class="tejcart-csw-sidebar__list">';
        foreach ( $codes as $code ) {
            $is_active = $code === $current;
            $symbol    = function_exists( 'tejcart_get_currency_symbol' )
                ? tejcart_get_currency_symbol( $code )
                : '';
            $url = wp_nonce_url(
                add_query_arg( 'tejcart_csw_currency', $code, home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) ),
                Options::NONCE_SWITCH,
                '_tejcart_csw_nonce'
            );

            $html .= '<li class="tejcart-csw-sidebar__item' . ( $is_active ? ' is-active' : '' ) . '">';
            $html .= '<a href="' . esc_url( $url ) . '" rel="nofollow" aria-label="' . esc_attr( sprintf(
                /* translators: %s: currency code */
                __( 'Switch currency to %s', 'tejcart' ),
                $code
            ) ) . '">';
            if ( $show_flag ) {
                $html .= '<span class="tejcart-csw-sidebar__flag">' . self::flag_svg( $code ) . '</span>';
            }
            if ( $show_code ) {
                $html .= '<span class="tejcart-csw-sidebar__code">' . esc_html( $code ) . '</span>';
            }
            if ( $show_symbol && '' !== $symbol ) {
                $html .= '<span class="tejcart-csw-sidebar__symbol">' . esc_html( $symbol ) . '</span>';
            }
            $html .= '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    public function maybe_auto_inject_sidebar(): void {
        if ( $this->should_suppress_on_checkout() ) {
            return;
        }
        $pages = (array) get_option( Options::SIDEBAR_PAGES, array( 'shop', 'cart', 'checkout' ) );
        if ( ! $this->current_page_matches( $pages ) ) {
            return;
        }
        // Avoid stacking two switchers on the single-product page when the
        // product-page dropdown is also enabled — the inline dropdown wins.
        // Merchants who actually want both can disable the product-page
        // dropdown in TejCart → Settings → Currency → Display Options.
        if ( $this->is_product_page()
            && 1 === (int) get_option( Options::PRODUCT_PAGE_ENABLE, 1 )
        ) {
            return;
        }
        echo $this->render_sidebar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function maybe_render_on_product(): void {
        $enabled = 1 === (int) get_option( Options::PRODUCT_PAGE_ENABLE, 1 );
        if ( ! $enabled ) {
            return;
        }
        // render_dropdown() returns '' when no extra currencies are configured,
        // so the merchant doesn't get a useless single-option dropdown stuck
        // beside the product price until at least one alternate currency is
        // added in TejCart → Settings → Currency.
        echo $this->render_dropdown( array( 'class' => 'tejcart-csw-switcher--product' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * The currency switcher must not appear on cart/checkout/pay-for-order
     * pages when "Checkout in different currency" is disabled — switching
     * currency on those surfaces is misleading because the customer will
     * be charged in the store base currency regardless.
     */
    private function should_suppress_on_checkout(): bool {
        if ( Checkout_Controller::diff_currency_allowed() ) {
            return false;
        }
        return Page_Context::is_checkout_page()
            || Page_Context::is_cart_page()
            || Page_Context::is_pay_for_order_page();
    }

    /**
     * Map the configured product-page slot to a real core action hook
     * emitted by `templates/product/single-product.php`. Falls back to
     * the after-price action if the option holds an unknown value.
     */
    private function product_action(): string {
        $slot = (string) get_option( Options::SWITCHER_POSITION, 'after_price' );
        return match ( $slot ) {
            'before_title' => 'tejcart_before_product_title',
            'after_title'  => 'tejcart_after_product_title',
            'before_price' => 'tejcart_before_product_price',
            'before_cart'  => 'tejcart_before_product_cart',
            'after_cart'   => 'tejcart_after_product_cart',
            default        => 'tejcart_after_product_price',
        };
    }

    /**
     * Inline SVG flag for a currency. Returns an empty string if no
     * country mapping exists AND the `tejcart_csw_flag_url` filter
     * yields no override — callers concatenate this directly into HTML,
     * so the output is intentionally escaped at the SVG layer rather
     * than at the wrapper.
     */
    public static function flag_svg( string $currency_code ): string {
        $country = Flag_Map::country_for( $currency_code );
        if ( null === $country ) {
            // Last-ditch fallback to the legacy 2-char prefix.
            $country = strtolower( substr( $currency_code, 0, 2 ) );
        }

        /**
         * Allow third parties to swap the inline SVG markup for a
         * particular currency. Return any string to override — return
         * an empty string to fall back to the placeholder rendering.
         *
         * @param string|null $svg           Default SVG markup (may be empty).
         * @param string      $country       Resolved ISO 3166 alpha-2 country code.
         * @param string      $currency_code Original ISO 4217 currency code.
         */
        $override = apply_filters( 'tejcart_csw_flag_svg', null, $country, $currency_code );
        if ( is_string( $override ) && '' !== $override ) {
            return $override;
        }

        return Flag_SVG::render( $country );
    }

    /**
     * Legacy URL-based flag resolver — kept for back-compat with sites
     * that filter `tejcart_csw_flag_url` to point at a CDN. Internal
     * code now uses {@see self::flag_svg()} instead.
     */
    public static function flag_url( string $currency_code ): string {
        $country = Flag_Map::country_for( $currency_code );
        if ( null === $country ) {
            $country = strtolower( substr( $currency_code, 0, 2 ) );
        }
        $url = defined( 'TEJCART_CSW_URL' )
            ? TEJCART_CSW_URL . 'assets/css/flag/' . $country . '.svg'
            : '';
        return (string) apply_filters( 'tejcart_csw_flag_url', $url, $country, $currency_code );
    }

    /**
     * Pretty label for a currency, e.g. `USD · $`. Falls back to the
     * raw code if the catalogue doesn't know the currency.
     */
    private static function short_label( string $code ): string {
        $catalog = Currency_Catalog::all();
        $code    = strtoupper( $code );
        $entry   = $catalog[ $code ] ?? null;
        if ( null === $entry ) {
            return $code;
        }
        return $code;
    }

    private static function full_name( string $code ): string {
        $catalog = Currency_Catalog::all();
        $code    = strtoupper( $code );
        return isset( $catalog[ $code ]['name'] ) ? (string) $catalog[ $code ]['name'] : $code;
    }

    /**
     * Resolve the active page against the merchant's selected slugs.
     * Delegates to {@see Page_Context} — the single source of truth for
     * TejCart-native page detection. The previous implementation used
     * WooCommerce template tags (`is_shop()` / `is_product()` / …) that
     * never resolve in standalone TejCart, leaving the sidebar silently
     * inert on every non-front page.
     *
     * @param array<int, string> $pages
     */
    private function current_page_matches( array $pages ): bool {
        $pages = array_map( 'strval', $pages );

        if ( in_array( 'home', $pages, true ) && Page_Context::is_front_page() ) {
            return true;
        }
        if ( in_array( 'shop', $pages, true ) && Page_Context::is_shop_page() ) {
            return true;
        }
        if ( in_array( 'product', $pages, true ) && Page_Context::is_product_page() ) {
            return true;
        }
        if ( in_array( 'category', $pages, true ) && Page_Context::is_product_category_page() ) {
            return true;
        }
        if ( in_array( 'cart', $pages, true ) && Page_Context::is_cart_page() ) {
            return true;
        }
        if ( in_array( 'checkout', $pages, true ) && Page_Context::is_checkout_page() ) {
            return true;
        }
        return false;
    }

    private function is_product_page(): bool {
        return Page_Context::is_product_page();
    }
}
