<?php
/**
 * Breadcrumbs with Schema.org BreadcrumbList markup.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Meta;
use TejCart\Product\Product_Taxonomy;

/**
 * Renders breadcrumb navigation with structured data.
 */
class Breadcrumbs {
    /**
     * Register the breadcrumbs shortcode.
     *
     * @return void
     */
    public function init(): void {
        add_shortcode( 'tejcart_breadcrumbs', array( $this, 'shortcode' ) );
    }

    /**
     * Shortcode callback for [tejcart_breadcrumbs].
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Breadcrumb HTML.
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
            ),
            $atts,
            'tejcart_breadcrumbs'
        );

        return $this->render( absint( $atts['product_id'] ) );
    }

    /**
     * Render breadcrumb HTML with Schema.org BreadcrumbList markup.
     *
     * Structure: Home > Shop > Category > Product Name
     *
     * @param int $product_id Optional product ID to include in the trail.
     * @return string Breadcrumb HTML.
     */
    public function render( int $product_id = 0 ): string {
        /**
         * Filter breadcrumb default settings.
         *
         * @since 1.0.0
         * @param array $defaults Default breadcrumb configuration.
         */
        $defaults = apply_filters( 'tejcart_breadcrumb_defaults', array(
            'separator'       => ' / ',
            'wrapper_before'  => '<nav class="tejcart-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'tejcart' ) . '">',
            'wrapper_after'   => '</nav>',
            'item_before'     => '<span class="tejcart-breadcrumb-item">',
            'item_after'      => '</span>',
            'active_before'   => '<span class="tejcart-breadcrumb-item tejcart-breadcrumb-active" aria-current="page">',
            'active_after'    => '</span>',
        ) );

        /**
         * Filter the breadcrumb home URL.
         *
         * @since 1.0.0
         * @param string $home_url Home URL.
         */
        $home_url = apply_filters( 'tejcart_breadcrumb_home_url', home_url( '/' ) );

        $crumbs = array();
        $position = 1;

        $crumbs[] = array(
            'name'     => __( 'Home', 'tejcart' ),
            'url'      => $home_url,
            'position' => $position++,
        );

        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id ) {
            $shop_url = get_permalink( $shop_page_id );
            if ( $shop_url ) {
                $crumbs[] = array(
                    'name'     => __( 'Shop', 'tejcart' ),
                    'url'      => $shop_url,
                    'position' => $position++,
                );
            }
        }

        if ( ! $product_id ) {
            $post = get_post();
            if ( $post instanceof \WP_Post ) {
                $content = $post->post_content;
                if ( has_shortcode( $content, 'tejcart_product' ) ) {
                    if ( preg_match( '/\[tejcart_product\s[^\]]*id=["\']?(\d+)["\']?/', $content, $matches ) ) {
                        $product_id = absint( $matches[1] );
                    }
                }
            }
        }

        if ( $product_id ) {
            $product = tejcart_get_product( $product_id );

            if ( $product && $product->get_id() ) {
                // F-FE-011: Use the taxonomy API (consistent with Schema.php and single-product.php)
                // instead of the legacy _category meta field, which may be absent when categories
                // are stored only as tejcart_product_cat taxonomy terms.
                $cats = Product_Taxonomy::get_product_categories( $product->get_id() );
                if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
                    $category_name = $cats[0]->name;
                    $category_slug = $cats[0]->slug ?? sanitize_title( $category_name );
                    $category_url  = $shop_page_id
                        ? add_query_arg( 'category', $category_slug, get_permalink( $shop_page_id ) )
                        : '';

                    $crumbs[] = array(
                        'name'     => $category_name,
                        'url'      => $category_url,
                        'position' => $position++,
                    );
                }

                $crumbs[] = array(
                    'name'     => $product->get_name(),
                    'url'      => '',
                    'position' => $position++,
                );
            }
        }

        $last_index = count( $crumbs ) - 1;
        $items_html = array();
        $json_items = array();

        foreach ( $crumbs as $index => $crumb ) {
            $is_last = ( $index === $last_index );
            $name    = esc_html( $crumb['name'] );

            $list_item = array(
                '@type'    => 'ListItem',
                'position' => $crumb['position'],
                'name'     => $crumb['name'],
            );
            if ( $crumb['url'] ) {
                $list_item['item'] = $crumb['url'];
            }
            $json_items[] = $list_item;

            if ( $is_last || empty( $crumb['url'] ) ) {
                $items_html[] = $defaults['active_before'] . $name . $defaults['active_after'];
            } else {
                $items_html[] = $defaults['item_before']
                    . '<a href="' . esc_url( $crumb['url'] ) . '">' . $name . '</a>'
                    . $defaults['item_after'];
            }
        }

        $breadcrumb_schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $json_items,
        );

        $output  = $defaults['wrapper_before'];
        $output .= implode( $defaults['separator'], $items_html );
        $output .= $defaults['wrapper_after'];

        // Emit the BreadcrumbList structured data. JSON-LD is page-level
        // structured data (type application/ld+json), not an enqueueable JS
        // file, so it is printed inline — but via the core helper
        // wp_get_inline_script_tag() (WP 5.9+) so it picks up any CSP nonce /
        // script-attribute filters the site has configured. The JSON_HEX_*
        // flags prevent the encoded payload from breaking out of the tag.
        $breadcrumb_json = (string) wp_json_encode( $breadcrumb_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( function_exists( 'wp_get_inline_script_tag' ) ) {
            $output .= wp_get_inline_script_tag( $breadcrumb_json, array( 'type' => 'application/ld+json' ) ) . "\n";
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode + JSON_HEX_* flags prevent breakout of the containing <script> tag.
            $output .= '<script type="application/ld+json">' . $breadcrumb_json . '</script>' . "\n";
        }

        return $output;
    }
}
