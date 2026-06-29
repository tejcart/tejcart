<?php
/**
 * Inject the AI-generated FAQ accordion into the single-product tab strip
 * (`tejcart_single_product_tabs`), slotted at priority 25 so it lands
 * between "Additional information" (priority 20) and "Reviews"
 * (priority 30).
 *
 * Also emits a schema.org `FAQPage` JSON-LD block in the document head
 * so Google can show FAQ rich snippets in search results — a high-value
 * SEO win for high-volume merchants — and exposes a stable `id` per
 * question so individual FAQs are deep-linkable.
 *
 * @package TejCart\AI_Content_Smartsuite\Frontend
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Frontend;

use TejCart\AI_Content_Smartsuite\Content\Content_Repository;
use TejCart\AI_Content_Smartsuite\Content\Formatter;
use TejCart\Product\Product_Meta;
use TejCart\Product\Product_Types\Abstract_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FAQ_Panel {
    /** Tab slot — between Additional Info (20) and Reviews (30). */
    public const TAB_PRIORITY = 25;

    /**
     * Per-product FAQ cache so `wp_head` can emit JSON-LD without
     * hitting the meta table a second time.
     *
     * @var array<int, array<int, array{question:string, answer:string}>>
     */
    private static array $json_ld_cache = array();

    /**
     * One-shot guard so the schema.org FAQ block is emitted at most once
     * per request. It is hooked on both `wp_head` (covers stores that
     * map products onto a real post type, primed during the `wp` action)
     * and `wp_footer` (covers the default shortcode-rendered product
     * page, where the product id is only known once `add_tab()` has run
     * inside the_content — i.e. after `wp_head` has already flushed).
     */
    private static bool $json_ld_printed = false;

    public static function register(): void {
        add_filter( 'tejcart_single_product_tabs', array( __CLASS__, 'add_tab' ), 10, 2 );
        // Pre-populate the per-product cache and enqueue our CSS/JS on
        // `wp` priority 20 — the canonical earliest hook with
        // conditional tags + queried object resolved. This lets the
        // `wp_head` JSON-LD emission and the `wp_enqueue_scripts` queue
        // see the data on stores that map products onto a registered
        // post type. The default standalone storefront renders products
        // via the `[tejcart_product]` shortcode (the plugin does not
        // call register_post_type()), so priming cannot resolve the
        // product here — the `wp_footer` JSON-LD hook below is the
        // fallback that covers that path.
        add_action( 'wp', array( __CLASS__, 'prime_for_current_request' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ), 20 );
        add_action( 'wp_head', array( __CLASS__, 'maybe_print_json_ld' ), 30 );
        // Footer fallback: by the time the_content has rendered, the
        // shortcode has fired the `tejcart_single_product_tabs` filter
        // and `add_tab()` has seeded the cache. The shared
        // `$json_ld_printed` guard prevents a double emission when the
        // head path already ran.
        add_action( 'wp_footer', array( __CLASS__, 'maybe_print_json_ld' ), 5 );
    }

    /**
     * On a single-product front-end request served by a registered
     * product post type, look up FAQs for the queried product once and
     * seed the JSON-LD cache. Idempotent and cheap when no FAQs are
     * configured. The plugin does not call `register_post_type()` for
     * its own products, so this only matches stores that map products
     * onto an actual post type (filterable via
     * `tejcart_product_post_types`); the shortcode-rendered default is
     * handled by `add_tab()` + the `wp_footer` JSON-LD fallback.
     */
    public static function prime_for_current_request(): void {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
            return;
        }
        $queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
        $product_id = 0;
        if ( $queried instanceof \WP_Post ) {
            $product_types = (array) apply_filters( 'tejcart_product_post_types', array( 'tejcart_product', 'product' ) );
            if ( ! in_array( $queried->post_type, $product_types, true ) ) {
                return;
            }
            $product_id = (int) $queried->ID;
        } elseif ( is_object( $queried ) && method_exists( $queried, 'get_id' ) ) {
            $product_id = (int) $queried->get_id();
        }
        if ( $product_id <= 0 ) {
            return;
        }
        if ( isset( self::$json_ld_cache[ $product_id ] ) ) {
            return;
        }
        $faqs = self::get_faqs_for( $product_id );
        if ( empty( $faqs ) ) {
            return;
        }
        self::$json_ld_cache[ $product_id ] = $faqs;
    }

    /**
     * Enqueue front-end CSS/JS during `wp_enqueue_scripts` so the
     * `<link rel="stylesheet">` tag lands in `<head>` instead of being
     * appended in the body (which causes a flash-of-unstyled-content
     * for the accordion). Only enqueues when the current product
     * actually has FAQs.
     */
    public static function maybe_enqueue_assets(): void {
        if ( empty( self::$json_ld_cache ) ) {
            return;
        }
        self::enqueue_assets();
    }

    /**
     * @param array<string,array<string,mixed>> $tabs
     * @param mixed                              $product
     * @return array<string,array<string,mixed>>
     */
    public static function add_tab( $tabs, $product ): array {
        $tabs = is_array( $tabs ) ? $tabs : array();

        $product_id = self::resolve_product_id( $product );
        if ( $product_id <= 0 ) {
            return $tabs;
        }

        $faqs = self::get_faqs_for( $product_id );
        if ( empty( $faqs ) ) {
            return $tabs;
        }

        self::$json_ld_cache[ $product_id ] = $faqs;

        $tabs['tejcart_ai_faqs'] = array(
            'title'    => __( 'FAQs', 'tejcart' ),
            'priority' => self::TAB_PRIORITY,
            'callback' => static function () use ( $faqs, $product_id ): void {
                self::enqueue_assets();
                echo self::render_body( $faqs, $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            },
        );

        return $tabs;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private static function get_faqs_for( int $product_id ): array {
        $raw = Product_Meta::get( $product_id, Content_Repository::META_LIVE_FAQS, true );
        if ( empty( $raw ) ) {
            return array();
        }
        return Formatter::decode_faqs( $raw );
    }

    /**
     * @param array<int, array{question:string, answer:string}> $faqs
     */
    public static function render_body( array $faqs, int $product_id ): string {
        $base_id = 'tejcart-faq-' . $product_id;
        $clean   = array();
        foreach ( $faqs as $faq ) {
            $q = isset( $faq['question'] ) ? trim( (string) $faq['question'] ) : '';
            $a = isset( $faq['answer'] )   ? trim( (string) $faq['answer'] )   : '';
            if ( '' !== $q && '' !== $a ) {
                $clean[] = array( 'question' => $q, 'answer' => $a );
            }
        }
        $count = count( $clean );
        if ( 0 === $count ) {
            return '';
        }

        ob_start();
        ?>
        <section class="tejcart-faq"
                 data-tejcart-faq
                 aria-label="<?php esc_attr_e( 'Frequently asked questions', 'tejcart' ); ?>">
            <header class="tejcart-faq__header">
                <h3 class="tejcart-faq__title"><?php esc_html_e( 'Frequently asked questions', 'tejcart' ); ?></h3>
                <p class="tejcart-faq__lede">
                    <?php
                    printf(
                        esc_html(
                            /* translators: %d number of FAQ items */
                            _n( '%d common question, answered.', '%d common questions, answered.', $count, 'tejcart' )
                        ),
                        (int) $count
                    );
                    ?>
                </p>
            </header>

            <ol class="tejcart-faq__list" role="list">
                <?php foreach ( $clean as $i => $faq ) :
                    $num      = $i + 1;
                    $item_id  = $base_id . '-' . $num;
                    $panel_id = $item_id . '-panel';
                    ?>
                    <li class="tejcart-faq__item" id="<?php echo esc_attr( $item_id ); ?>">
                        <?php $btn_id = $item_id . '-btn'; ?>
                        <button type="button"
                                id="<?php echo esc_attr( $btn_id ); ?>"
                                class="tejcart-faq__question"
                                aria-expanded="false"
                                aria-controls="<?php echo esc_attr( $panel_id ); ?>"
                                data-tejcart-faq-toggle>
                            <span class="tejcart-faq__num" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $num ) ); ?></span>
                            <span class="tejcart-faq__qtext"><?php echo wp_kses_post( $faq['question'] ); ?></span>
                            <span class="tejcart-faq__chevron" aria-hidden="true">
                                <svg viewBox="0 0 12 8" width="14" height="9" focusable="false" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 1.5L6 6.5L11 1.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </button>
                        <div id="<?php echo esc_attr( $panel_id ); ?>"
                             class="tejcart-faq__answer"
                             role="region"
                             aria-labelledby="<?php echo esc_attr( $btn_id ); ?>"
                             hidden>
                            <div class="tejcart-faq__answer-inner"><?php echo wp_kses_post( wpautop( $faq['answer'] ) ); ?></div>
                            <div class="tejcart-faq__permalink-wrap">
                                <a class="tejcart-faq__permalink"
                                   href="#<?php echo esc_attr( $item_id ); ?>"
                                   data-tejcart-faq-permalink
                                   aria-label="<?php esc_attr_e( 'Copy link to this question', 'tejcart' ); ?>">
                                    <svg viewBox="0 0 16 16" width="13" height="13" focusable="false" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M6.5 9.5l3-3M5.5 4.5L7 3a3 3 0 014.243 4.243L9.75 8.75M10.5 11.5L9 13a3 3 0 01-4.243-4.243L6.25 7.25" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span><?php esc_html_e( 'Link to this question', 'tejcart' ); ?></span>
                                </a>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Emit a schema.org `FAQPage` JSON-LD block. Cached during
     * `add_tab()`, so we don't re-read meta.
     */
    public static function maybe_print_json_ld(): void {
        if ( self::$json_ld_printed ) {
            return;
        }
        if ( empty( self::$json_ld_cache ) ) {
            return;
        }

        $entities = array();
        foreach ( self::$json_ld_cache as $faqs ) {
            foreach ( $faqs as $faq ) {
                $q = isset( $faq['question'] ) ? trim( wp_strip_all_tags( (string) $faq['question'] ) ) : '';
                $a = isset( $faq['answer'] )   ? trim( (string) $faq['answer'] )   : '';
                if ( '' === $q || '' === $a ) {
                    continue;
                }
                $entities[] = array(
                    '@type'          => 'Question',
                    'name'           => $q,
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => wp_strip_all_tags( $a, true ),
                    ),
                );
            }
        }

        if ( empty( $entities ) ) {
            return;
        }

        self::$json_ld_printed = true;

        $doc = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );

        echo "\n<script type=\"application/ld+json\" data-tejcart-faq-jsonld=\"1\">"
            . wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . "</script>\n";
    }

    private static function resolve_product_id( $product ): int {
        if ( $product instanceof Abstract_Product ) {
            return (int) $product->get_id();
        }
        if ( is_numeric( $product ) ) {
            return (int) $product;
        }
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            return (int) $product->get_id();
        }
        return 0;
    }

    private static function enqueue_assets(): void {
        $handle = 'tejcart-ai-faq-frontend';

        if ( wp_style_is( $handle, 'enqueued' ) ) {
            return;
        }

        $debug    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
        $css_path = $debug ? 'assets/css/faq-frontend.css' : 'assets/css/faq-frontend.min.css';
        $js_path  = $debug ? 'assets/js/faq-frontend.js'   : 'assets/js/faq-frontend.min.js';

        if ( ! file_exists( TEJCART_AI_CONTENT_DIR . $css_path ) ) {
            $css_path = 'assets/css/faq-frontend.css';
        }
        if ( ! file_exists( TEJCART_AI_CONTENT_DIR . $js_path ) ) {
            $js_path = 'assets/js/faq-frontend.js';
        }

        wp_enqueue_style(
            $handle,
            TEJCART_AI_CONTENT_URL . $css_path,
            array(),
            TEJCART_VERSION
        );
        wp_enqueue_script(
            $handle,
            TEJCART_AI_CONTENT_URL . $js_path,
            array(),
            TEJCART_VERSION,
            true
        );
    }
}
