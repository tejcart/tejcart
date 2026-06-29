<?php
/**
 * Frontend view-tracker — fires the `tejcart_view_product` action that
 * the dispatcher's `on_view_item` listener consumes.
 *
 * Core registers the `tejcart_product` post type and renders the
 * single-product template via `[tejcart_products]`, but it does not
 * itself emit a `tejcart_view_product` action — historically that hook
 * was filled in by analytics extensions. This class is that filler:
 * it hooks `template_redirect` and detects a single-product render,
 * then fires the action with the product id.
 *
 * Bots, REST/AJAX, feed, and admin requests are filtered out so the
 * GA4 / Meta CAPI streams aren't polluted with non-human pageviews.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class View_Tracker {
    /**
     * Hook registration. Idempotent.
     */
    public function register(): void {
        add_action( 'template_redirect', array( $this, 'maybe_fire_view_product' ), 30 );
    }

    /**
     * Detect a single-product render and emit `tejcart_view_product`.
     */
    public function maybe_fire_view_product(): void {
        if ( ! $this->is_single_product_request() ) {
            return;
        }
        if ( $this->is_bot_request() ) {
            return;
        }

        $product_id = $this->resolve_product_id();
        if ( $product_id <= 0 ) {
            return;
        }

        // Per-request guard so a redirect chain that re-enters this
        // listener doesn't double-fire.
        static $seen = array();
        if ( isset( $seen[ $product_id ] ) ) {
            return;
        }
        $seen[ $product_id ] = true;

        do_action( 'tejcart_view_product', $product_id );
    }

    private function is_single_product_request(): bool {
        if ( is_admin() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        if ( function_exists( 'is_feed' ) && is_feed() ) {
            return false;
        }

        // Audit H-24 (Bundled-Modules F-004): TejCart does not
        // register_post_type('tejcart_product') — products are in
        // custom tables. is_singular('tejcart_product') never matched.
        // Mirror Recently_Viewed::resolve_current_product_id(): check
        // the tejcart_product_slug query var first, then fall back to
        // the filtered post-type allowlist.
        if ( function_exists( 'get_query_var' ) ) {
            $slug = (string) get_query_var( 'tejcart_product_slug', '' );
            if ( '' !== $slug ) {
                return true;
            }
        }
        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_queried_object();
            if ( $post && ! empty( $post->post_type ) ) {
                $product_types = (array) apply_filters( 'tejcart_product_post_types', array( 'tejcart_product', 'product' ) );
                return in_array( $post->post_type, $product_types, true );
            }
        }
        return false;
    }

    private function resolve_product_id(): int {
        if ( function_exists( 'get_query_var' ) ) {
            $slug = (string) get_query_var( 'tejcart_product_slug', '' );
            if ( '' !== $slug && class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
                $resolved = \TejCart\Product\Product_Factory::get_by_slug( $slug );
                if ( $resolved && method_exists( $resolved, 'get_id' ) ) {
                    return (int) $resolved->get_id();
                }
            }
        }
        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_queried_object();
            if ( $post && ! empty( $post->ID ) ) {
                $product_types = (array) apply_filters( 'tejcart_product_post_types', array( 'tejcart_product', 'product' ) );
                if ( in_array( $post->post_type ?? '', $product_types, true ) ) {
                    return (int) $post->ID;
                }
            }
        }
        return 0;
    }

    /**
     * Crude UA-based bot filter. Errs on the side of letting traffic
     * through; merchants who want stricter filtering can subscribe to
     * `tejcart_analytics_is_bot_request` and return true to drop the
     * pageview.
     */
    private function is_bot_request(): bool {
        $ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_bot   = '' === $ua;
        if ( ! $is_bot && '' !== $ua ) {
            $is_bot = (bool) preg_match(
                '/(bot|crawler|spider|crawling|facebookexternalhit|preview|monitor|pingdom|gtmetrix|lighthouse|headless)/i',
                $ua
            );
        }

        if ( function_exists( 'apply_filters' ) ) {
            /**
             * Filter whether the analytics view-tracker treats a
             * request as a bot/automation hit and skips it.
             *
             * @param bool   $is_bot     Default heuristic result.
             * @param string $user_agent Raw UA string.
             */
            $is_bot = (bool) apply_filters( 'tejcart_analytics_is_bot_request', $is_bot, $ua );
        }
        return $is_bot;
    }
}
