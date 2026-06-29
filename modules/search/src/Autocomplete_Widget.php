<?php
/**
 * Storefront autocomplete search bar.
 *
 * Enqueues a debounced AJAX search bar that calls the
 * `/tejcart/v1/search/suggestions` REST endpoint and renders a
 * dropdown with product thumbnails, names and prices.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autocomplete_Widget {
    public function register(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_filter( 'tejcart_search_form', array( $this, 'render_search_form' ) );
    }

    /**
     * Register (but do not yet enqueue) the autocomplete CSS/JS and the
     * inline config. Both assets are enqueued on demand from
     * {@see render_search_form()} the moment a search form is actually
     * rendered — this removes the need to predict the page context up
     * front, and (crucially) means the stylesheet only ships on pages that
     * really render a search box rather than site-wide.
     *
     * The CSS depends on `tejcart-public` for its design tokens. That
     * handle is registered unconditionally by the core Frontend on every
     * request, so the dependency resolves even when the search box renders
     * on a non-TejCart surface (e.g. a header search form on the homepage).
     */
    public function register_assets(): void {
        $version = defined( 'TEJCART_SEARCH_VERSION' ) ? TEJCART_SEARCH_VERSION : '1.0.0';

        wp_register_style(
            'tejcart-search-autocomplete',
            tejcart_asset_url( 'assets/css/tejcart-search-autocomplete.css' ),
            array( 'tejcart-public' ),
            $version
        );

        $shop_id  = (int) get_option( 'tejcart_shop_page_id', 0 );
        $shop_url = $shop_id > 0 ? (string) get_permalink( $shop_id ) : '';

        wp_register_script(
            'tejcart-search-autocomplete',
            tejcart_asset_url( 'assets/js/tejcart-search-autocomplete.js' ),
            array(),
            $version,
            true
        );

        wp_localize_script(
            'tejcart-search-autocomplete',
            'tejcartSearch',
            array(
                'restUrl'     => esc_url_raw( rest_url( 'tejcart/v1/search/suggestions' ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'placeholder' => __( 'Search products…', 'tejcart' ),
                'noResults'   => __( 'No products found.', 'tejcart' ),
                'viewAll'     => __( 'View all results', 'tejcart' ),
                'debounce'    => (int) get_option( 'tejcart_search_debounce', 300 ),
                'minChars'    => (int) get_option( 'tejcart_search_min_chars', 2 ),
                'limit'       => (int) get_option( 'tejcart_search_max_results', 8 ),
                'shopUrl'     => $shop_url,
            )
        );
    }

    /**
     * Filter the search form markup to add autocomplete attributes
     * and enqueue the JS on demand.
     */
    public function render_search_form( string $form ): string {
        if ( strpos( $form, 'data-tejcart-autocomplete' ) !== false ) {
            return $form;
        }

        $form = (string) preg_replace(
            '/(<input\b[^>]*class="[^"]*\btejcart-search-field\b[^"]*")/si',
            '$1 data-tejcart-autocomplete="true"',
            $form,
            1,
            $count
        );

        if ( ! $count ) {
            $form = (string) preg_replace(
                '/(<input\b[^>]*\btype=["\']search["\'])/si',
                '$1 data-tejcart-autocomplete="true"',
                $form,
                1
            );
        }

        wp_enqueue_style( 'tejcart-search-autocomplete' );
        wp_enqueue_script( 'tejcart-search-autocomplete' );

        return $form;
    }
}
