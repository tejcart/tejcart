<?php
/**
 * Schema.org Structured Data output for product pages.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Meta;
use TejCart\Product\Product_Reviews;

/**
 * Outputs JSON-LD structured data in the document head on product pages.
 */
class Schema {
    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_head', array( $this, 'maybe_output_product_schema' ), 20 );
    }

    /**
     * Detect whether the current page contains a product shortcode or block
     * and output the corresponding structured data.
     *
     * @return void
     */
    public function maybe_output_product_schema(): void {
        $post = get_post();

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $product_id = $this->detect_product_id( $post );

        if ( ! $product_id ) {
            return;
        }

        $product = tejcart_get_product( $product_id );

        if ( ! $product || 0 === $product->get_id() ) {
            return;
        }

        $this->render_product_schema( $product );
    }

    /**
     * Output a <script type="application/ld+json"> block for a product.
     *
     * @param \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
     * @return void
     */
    public function render_product_schema( $product ): void {
        $image_url = '';
        $image_id  = $product->get_image_id();
        if ( $image_id ) {
            $image_src = wp_get_attachment_image_url( $image_id, 'full' );
            if ( $image_src ) {
                $image_url = $image_src;
            }
        }

        $currency = get_option( 'tejcart_currency', 'USD' );
        $price    = $product->get_price();

        $stock_status = $product->get_stock_status();
        $availability_map = array(
            'instock'     => 'https://schema.org/InStock',
            'outofstock'  => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/PreOrder',
        );
        $availability = isset( $availability_map[ $stock_status ] )
            ? $availability_map[ $stock_status ]
            : 'https://schema.org/InStock';

        $store_name = get_bloginfo( 'name' );

        // F-FE-010: Use the canonical SEO-friendly permalink (e.g. /shop/slug/) so the
        // JSON-LD Offer.url matches the <link rel="canonical"> emitted by Product_SEO.
        // The old ?product=42 query-string form mismatches canonical and impedes Google
        // rich-result eligibility. get_permalink() respects Product_Permalinks rewrites.
        $product_url = (string) $product->get_permalink();

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_description() ),
            'sku'         => $product->get_sku(),
        );

        $is_variable = $product instanceof \TejCart\Product\Product_Types\Variable_Product;
        if ( $is_variable && method_exists( $product, 'get_variations' ) ) {
            $variation_offers = array();
            $variation_prices = array();

            foreach ( (array) $product->get_variations() as $variation ) {
                if ( ! method_exists( $variation, 'is_purchasable' ) || ! $variation->is_purchasable() ) {
                    continue;
                }
                $v_price = (string) $variation->get_price();
                if ( '' === $v_price ) {
                    continue;
                }

                $variation_prices[] = (float) $v_price;
                $variation_offers[] = array(
                    '@type'         => 'Offer',
                    'url'           => $product_url,
                    'sku'           => (string) $variation->get_sku(),
                    'priceCurrency' => $currency,
                    'price'         => $v_price,
                    'availability'  => $variation->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                );
            }

            if ( ! empty( $variation_offers ) ) {
                $schema['offers'] = array(
                    '@type'         => 'AggregateOffer',
                    'url'           => $product_url,
                    'priceCurrency' => $currency,
                    'offerCount'    => count( $variation_offers ),
                    'lowPrice'      => (string) min( $variation_prices ),
                    'highPrice'     => (string) max( $variation_prices ),
                    'offers'        => $variation_offers,
                    'seller'        => array(
                        '@type' => 'Organization',
                        'name'  => $store_name,
                    ),
                );
            }
        }

        if ( ! isset( $schema['offers'] ) ) {
            $schema['offers'] = array(
                '@type'         => 'Offer',
                'url'           => $product_url,
                'priceCurrency' => $currency,
                'price'         => $price,
                'availability'  => $availability,
                'seller'        => array(
                    '@type' => 'Organization',
                    'name'  => $store_name,
                ),
            );
        }

        if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
            $valid_to = method_exists( $product, 'get_sale_date_to' ) ? (int) $product->get_sale_date_to() : 0;
            $schema['offers']['priceValidUntil'] = $valid_to > 0
                ? gmdate( 'Y-m-d', $valid_to )
                : gmdate( 'Y-m-d', strtotime( '+1 year' ) );
        }

        if ( $image_url ) {
            $schema['image'] = $image_url;
        }

        $cats = \TejCart\Product\Product_Taxonomy::get_product_categories( $product->get_id() );
        if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
            $schema['category'] = $cats[0]->name;
        }

        $brand_terms = \TejCart\Product\Product_Taxonomy::get_product_brands( $product->get_id() );
        if ( ! empty( $brand_terms ) ) {
            $first = $brand_terms[0];
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name'  => $first->name,
            );
        } else {
            $brand = Product_Meta::get( $product->get_id(), '_brand' );
            if ( $brand ) {
                $schema['brand'] = array(
                    '@type' => 'Brand',
                    'name'  => $brand,
                );
            }
        }

        $rating_value = Product_Meta::get( $product->get_id(), '_average_rating' );
        $review_count = Product_Meta::get( $product->get_id(), '_review_count' );

        if ( $rating_value && $review_count ) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating_value,
                'reviewCount' => (string) $review_count,
            );
        }

        if ( 'yes' === get_option( 'tejcart_enable_reviews', 'yes' ) ) {
            $review_items = $this->build_review_schema( $product->get_id() );
            if ( ! empty( $review_items ) ) {
                $schema['review'] = $review_items;
            }
        }

        $gtin = (string) Product_Meta::get( $product->get_id(), '_gtin' );
        if ( '' !== $gtin ) {
            $schema['gtin'] = $gtin;
        }
        $mpn = (string) Product_Meta::get( $product->get_id(), '_mpn' );
        if ( '' !== $mpn ) {
            $schema['mpn'] = $mpn;
        }

        /**
         * Filter the product schema data before output.
         *
         * @since 1.0.0
         * @param array                                            $schema  Schema.org data array.
         * @param \TejCart\Product\Product_Types\Abstract_Product  $product Product instance.
         */
        $schema = apply_filters( 'tejcart_product_schema', $schema, $product );

        // JSON-LD lives inside a <script type="application/ld+json"> block —
        // a JSON context, not HTML text. The four JSON_HEX_* flags hex-escape
        // `<`, `>`, `&`, `'`, `"` inside string values, which is the
        // documented WP coding-standards mitigation for embedding JSON in
        // HTML (and the correct alternative to esc_html() here).
        $json = wp_json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ( function_exists( 'wp_print_inline_script_tag' ) ) {
            wp_print_inline_script_tag( (string) $json, array( 'type' => 'application/ld+json' ) );
            echo "\n";
            return;
        }

        echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON context, escaped via JSON_HEX_* flags above.
    }

    /**
     * Build individual Review schema objects for a product.
     *
     * @param int $product_id Product ID.
     * @return array[] Schema.org Review items (max 10 for performance).
     */
    private function build_review_schema( int $product_id ): array {
        $reviews = Product_Reviews::get_reviews( $product_id, array( 'number' => 10 ) );
        if ( empty( $reviews ) ) {
            return array();
        }

        $items = array();
        foreach ( $reviews as $review ) {
            $rating = (int) ( $review->rating ?? 0 );

            $item = array(
                '@type'         => 'Review',
                'author'        => array(
                    '@type' => 'Person',
                    'name'  => $review->author_name ?? '',
                ),
                'datePublished' => gmdate( 'Y-m-d', strtotime( $review->created_at ?? '' ) ),
                'reviewBody'    => wp_strip_all_tags( $review->content ?? '' ),
            );

            if ( $rating >= 1 && $rating <= 5 ) {
                $item['reviewRating'] = array(
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $rating,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                );
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Detect a product ID from shortcodes or blocks in the current post.
     *
     * @param \WP_Post $post Current post object.
     * @return int Product ID or 0 if none found.
     */
    private function detect_product_id( \WP_Post $post ): int {
        $content = $post->post_content;

        if ( has_shortcode( $content, 'tejcart_product' ) ) {
            if ( preg_match( '/\[tejcart_product\s[^\]]*id=["\']?(\d+)["\']?/', $content, $matches ) ) {
                return absint( $matches[1] );
            }
        }

        if ( has_shortcode( $content, 'tejcart_button' ) ) {
            if ( preg_match( '/\[tejcart_button\s[^\]]*id=["\']?(\d+)["\']?/', $content, $matches ) ) {
                return absint( $matches[1] );
            }
        }

        if ( function_exists( 'parse_blocks' ) ) {
            $blocks = parse_blocks( $content );
            $product_id = $this->find_product_in_blocks( $blocks );
            if ( $product_id ) {
                return $product_id;
            }
        }

        return 0;
    }

    /**
     * Recursively search parsed blocks for a product ID attribute.
     *
     * @param array $blocks Parsed block array.
     * @return int Product ID or 0.
     */
    private function find_product_in_blocks( array $blocks ): int {
        foreach ( $blocks as $block ) {
            if ( isset( $block['blockName'] ) && strpos( $block['blockName'], 'tejcart/' ) === 0 ) {
                if ( ! empty( $block['attrs']['productId'] ) ) {
                    return absint( $block['attrs']['productId'] );
                }
                if ( ! empty( $block['attrs']['id'] ) ) {
                    return absint( $block['attrs']['id'] );
                }
            }

            if ( ! empty( $block['innerBlocks'] ) ) {
                $found = $this->find_product_in_blocks( $block['innerBlocks'] );
                if ( $found ) {
                    return $found;
                }
            }
        }

        return 0;
    }
}
