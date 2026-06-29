<?php
/**
 * Product permalink rewrite handler.
 *
 * Adds SEO-friendly product URLs of the form
 *   /{shop-page-slug}/{product-slug}/
 * by registering a rewrite rule that maps the captured product slug
 * into a `tejcart_product_slug` query var. The [tejcart_products]
 * shortcode picks up that query var and renders the single-product
 * template for the matched product.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product permalink registration.
 */
class Product_Permalinks {
    /**
     * Query var carrying the captured product slug.
     */
    const QUERY_VAR = 'tejcart_product_slug';

    /**
     * Default URL segment used to namespace single-product URLs when the
     * Shop page is configured as the site's front page (where the
     * /{shop-slug}/{product-slug}/ form would otherwise collapse to a
     * bare /{product-slug}/ and collide with attachment / page routing).
     */
    const FRONT_PAGE_PREFIX = 'product';

    /**
     * Resolve the URL segment used to namespace product URLs when the
     * Shop page is the front page. Filterable, falls back to "product".
     *
     * @return string
     */
    public static function get_front_page_prefix(): string {
        $prefix = (string) apply_filters( 'tejcart_product_url_prefix', self::FRONT_PAGE_PREFIX );
        $prefix = trim( trim( $prefix ), '/' );
        return '' === $prefix ? self::FRONT_PAGE_PREFIX : $prefix;
    }

    /**
     * Wire up WordPress hooks.
     */
    public function init(): void {
        add_action( 'init', array( $this, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

        add_action( 'update_option_tejcart_shop_page_id', array( $this, 'flush_rules' ), 10, 0 );
        add_action( 'add_option_tejcart_shop_page_id', array( $this, 'flush_rules' ), 10, 0 );

        add_action( 'update_option_page_on_front', array( $this, 'flush_rules' ), 10, 0 );
        add_action( 'add_option_page_on_front', array( $this, 'flush_rules' ), 10, 0 );
        add_action( 'update_option_show_on_front', array( $this, 'flush_rules' ), 10, 0 );
    }

    /**
     * Declare our query var to WordPress so it survives parse_request.
     *
     * @param string[] $vars Registered query vars.
     * @return string[]
     */
    public function register_query_vars( $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Register the product rewrite rule against the current shop page
     * slug. Does nothing until a shop page has been configured.
     *
     * @return void
     */
    public function register_rewrite_rules(): void {
        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        if ( $shop_page_id <= 0 ) {
            return;
        }

        $shop_page = get_post( $shop_page_id );
        if ( ! $shop_page instanceof \WP_Post || 'publish' !== $shop_page->post_status ) {
            return;
        }

        $slug = $this->get_full_page_slug( $shop_page );
        if ( '' === $slug ) {
            return;
        }

        $quoted = preg_quote( $slug, '/' );

        add_rewrite_rule(
            '^' . $quoted . '/page/([0-9]+)/?$',
            'index.php?page_id=' . $shop_page_id . '&paged=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^' . $quoted . '/([^/]+)/?$',
            'index.php?page_id=' . $shop_page_id . '&' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );

        if ( (int) get_option( 'page_on_front', 0 ) === $shop_page_id ) {
            $prefix        = self::get_front_page_prefix();
            $quoted_prefix = preg_quote( $prefix, '/' );

            add_rewrite_rule(
                '^' . $quoted_prefix . '/([^/]+)/?$',
                'index.php?page_id=' . $shop_page_id . '&' . self::QUERY_VAR . '=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Resolve a page's full URL slug including any parent hierarchy
     * (e.g. "store/shop" for a nested page). Returns an empty string
     * when the slug is unavailable.
     *
     * @param \WP_Post $page The page post.
     * @return string
     */
    private function get_full_page_slug( \WP_Post $page ): string {
        $slugs = array();

        $current = $page;
        while ( $current instanceof \WP_Post ) {
            if ( '' === $current->post_name ) {
                return '';
            }
            array_unshift( $slugs, $current->post_name );
            if ( (int) $current->post_parent <= 0 ) {
                break;
            }
            $current = get_post( (int) $current->post_parent );
        }

        return implode( '/', $slugs );
    }

    /**
     * Re-register rules and flush. Called whenever the shop page
     * setting changes so the live rewrite table reflects the new
     * configuration.
     *
     * @return void
     */
    public function flush_rules(): void {
        $this->register_rewrite_rules();
        flush_rewrite_rules( false );
    }
}
