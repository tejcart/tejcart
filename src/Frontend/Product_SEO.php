<?php
/**
 * Product SEO output: canonical URL + related head tags.
 *
 * Lives alongside Schema.php but renders earlier (priority 5 on wp_head)
 * so crawlers see the canonical before the JSON-LD block.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Types\Abstract_Product;

/**
 * Emits SEO-relevant markup for TejCart product pages.
 */
class Product_SEO {
    /**
     * Hook into WordPress.
     */
    public function init(): void {
        add_action( 'wp_head', array( $this, 'maybe_emit_canonical' ), 5 );
        add_action( 'wp_head', array( $this, 'maybe_emit_social_meta' ), 6 );
    }

    /**
     * Emit a <link rel="canonical"> tag when the current request resolves
     * to a known TejCart product.
     */
    public function maybe_emit_canonical(): void {
        $product = $this->detect_current_product();
        if ( ! $product instanceof Abstract_Product || 0 === $product->get_id() ) {
            return;
        }

        $url = (string) $product->get_permalink();
        if ( '' === $url ) {
            return;
        }

        /**
         * Filter the canonical URL emitted for a product page.
         *
         * Useful for stores that want to collapse variants or paginated
         * views onto a single canonical target.
         *
         * @param string           $url     Resolved canonical URL.
         * @param Abstract_Product $product Product being rendered.
         */
        $url = (string) apply_filters( 'tejcart_product_canonical_url', $url, $product );
        if ( '' === $url ) {
            return;
        }

        echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
    }

    /**
     * Emit minimal Open Graph + Twitter Card tags for a product when no
     * full-fat SEO plugin is already doing the job.
     *
     * Disabled entirely when Yoast / Rank Math / SEOPress / All in One SEO
     * are active (they own og:* and twitter:*) — the filter lets hosts
     * override either way.
     */
    public function maybe_emit_social_meta(): void {
        /**
         * Gate the fallback OG / Twitter Card output.
         *
         * Default: on when no known SEO plugin is detected, off when one is.
         *
         * @param bool $enabled Whether to emit the fallback tags.
         */
        $enabled = (bool) apply_filters(
            'tejcart_seo_meta_enabled',
            ! $this->has_external_seo_plugin()
        );
        if ( ! $enabled ) {
            return;
        }

        $product = $this->detect_current_product();
        if ( ! $product instanceof Abstract_Product || 0 === $product->get_id() ) {
            return;
        }

        $title       = $product->get_name();
        $description = (string) $product->get_short_description();
        if ( '' === $description ) {
            $description = (string) $product->get_description();
        }
        $description = trim( wp_strip_all_tags( $description ) );
        if ( strlen( $description ) > 200 ) {
            $description = substr( $description, 0, 197 ) . '...';
        }

        $url   = (string) $product->get_permalink();
        $image = '';
        if ( method_exists( $product, 'get_image_id' ) && $product->get_image_id() ) {
            $image = (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'tejcart-product-main' );
            if ( '' === $image ) {
                $image = (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'large' );
            }
        }

        $tags = array(
            array( 'property' => 'og:type',        'content' => 'product' ),
            array( 'property' => 'og:title',       'content' => $title ),
            array( 'property' => 'og:description', 'content' => $description ),
            array( 'property' => 'og:url',         'content' => $url ),
            array( 'name'     => 'twitter:card',        'content' => $image ? 'summary_large_image' : 'summary' ),
            array( 'name'     => 'twitter:title',       'content' => $title ),
            array( 'name'     => 'twitter:description', 'content' => $description ),
        );
        if ( '' !== $image ) {
            $tags[] = array( 'property' => 'og:image',       'content' => $image );
            $tags[] = array( 'name'     => 'twitter:image',  'content' => $image );
        }

        foreach ( $tags as $tag ) {
            if ( '' === (string) ( $tag['content'] ?? '' ) ) {
                continue;
            }
            $attr = isset( $tag['property'] ) ? 'property' : 'name';
            $key  = (string) ( $tag[ $attr ] ?? '' );
            printf(
                '<meta %s="%s" content="%s" />' . "\n",
                esc_attr( $attr ),
                esc_attr( $key ),
                esc_attr( (string) $tag['content'] )
            );
        }
    }

    /**
     * Whether an SEO plugin is installed that already emits og: / twitter:
     * meta tags so TejCart should stay out of the way.
     *
     * @return bool
     */
    private function has_external_seo_plugin(): bool {
        return defined( 'WPSEO_VERSION' )
            || class_exists( '\\RankMath' )
            || defined( 'SEOPRESS_VERSION' )
            || class_exists( '\\AIOSEO\\Plugin\\AIOSEO' )
            || defined( 'THE_SEO_FRAMEWORK_VERSION' );
    }

    /**
     * Resolve the product for the current request.
     *
     * Looks for (in order):
     *   1. The `tejcart_product_slug` query var populated by the pretty
     *      permalink rewrite — /{shop}/{product-slug}/.
     *   2. The legacy `?product=<id>` query string.
     *   3. A product referenced by a shortcode or block in the current post
     *      body (mirrors Schema::detect_product_id so the canonical shows
     *      up on dedicated product landing pages too).
     *
     * @return Abstract_Product|null
     */
    private function detect_current_product(): ?Abstract_Product {
        if ( function_exists( 'get_query_var' ) ) {
            $slug = (string) get_query_var( Product_Permalinks::QUERY_VAR, '' );
            if ( '' !== $slug ) {
                $product = \TejCart\Product\Product_Factory::get_by_slug( $slug );
                if ( $product ) {
                    return $product;
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['product'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $pid = absint( wp_unslash( $_GET['product'] ) );
            if ( $pid > 0 ) {
                $product = tejcart_get_product( $pid );
                if ( $product ) {
                    return $product;
                }
            }
        }

        if ( function_exists( 'get_post' ) ) {
            $post = get_post();
            if ( $post instanceof \WP_Post ) {
                $pid = $this->detect_product_in_post( $post );
                if ( $pid > 0 ) {
                    $product = tejcart_get_product( $pid );
                    if ( $product ) {
                        return $product;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a product ID embedded in the post's content (shortcode / block).
     *
     * @param \WP_Post $post Current post object.
     * @return int Product ID or 0.
     */
    private function detect_product_in_post( \WP_Post $post ): int {
        $content = (string) $post->post_content;

        if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'tejcart_product' ) ) {
            if ( preg_match( '/\[tejcart_product\s[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
                return absint( $m[1] );
            }
        }

        if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'tejcart_button' ) ) {
            if ( preg_match( '/\[tejcart_button\s[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
                return absint( $m[1] );
            }
        }

        if ( function_exists( 'parse_blocks' ) ) {
            $blocks = parse_blocks( $content );
            $found  = $this->find_product_in_blocks( is_array( $blocks ) ? $blocks : array() );
            if ( $found > 0 ) {
                return $found;
            }
        }

        return 0;
    }

    /**
     * Recursively look for a product ID in a parsed-block tree.
     *
     * @param array $blocks Blocks array from parse_blocks().
     * @return int
     */
    private function find_product_in_blocks( array $blocks ): int {
        foreach ( $blocks as $block ) {
            if ( isset( $block['blockName'] ) && 0 === strpos( (string) $block['blockName'], 'tejcart/' ) ) {
                if ( ! empty( $block['attrs']['productId'] ) ) {
                    return absint( $block['attrs']['productId'] );
                }
                if ( ! empty( $block['attrs']['id'] ) ) {
                    return absint( $block['attrs']['id'] );
                }
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $found = $this->find_product_in_blocks( $block['innerBlocks'] );
                if ( $found > 0 ) {
                    return $found;
                }
            }
        }
        return 0;
    }
}
