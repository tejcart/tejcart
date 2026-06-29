<?php
/**
 * AJAX router — registers all nine `wp_ajax_tejcart_ai_content_*`
 * endpoints with shared nonce + capability gating.
 *
 * @package TejCart\AI_Content_Smartsuite\Ajax
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Ajax;

use TejCart\AI_Content_Smartsuite\AI\OpenAI_Client;
use TejCart\AI_Content_Smartsuite\Capabilities;
use TejCart\AI_Content_Smartsuite\Content\Content_Repository;
use TejCart\AI_Content_Smartsuite\Content\Formatter;
use TejCart\AI_Content_Smartsuite\Generator\Bulk_Generator;
use TejCart\AI_Content_Smartsuite\Generator\Generator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ajax_Router {
    public static function register(): void {
        $endpoints = array(
            'validate_api_key'        => 'handle_validate_api_key',
            'fetch_products'          => 'handle_fetch_products',
            'generate_content'        => 'handle_generate_content',
            'regenerate_content'      => 'handle_regenerate_content',
            'save_content'            => 'handle_save_content',
            'apply_content'           => 'handle_apply_content',
            'apply_selected_products' => 'handle_apply_selected_products',
            'bulk_generate'           => 'handle_bulk_generate',
            'check_generation_status' => 'handle_check_generation_status',
            'revert_content'          => 'handle_revert_content',
        );
        foreach ( $endpoints as $action => $callback ) {
            add_action( 'wp_ajax_tejcart_ai_content_' . $action, array( __CLASS__, $callback ) );
        }
    }

    private static function guard(): void {
        if ( ! Capabilities::current_user_can_manage() ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }
        if ( ! check_ajax_referer( Capabilities::NONCE_AJAX, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'tejcart' ) ), 403 );
        }
    }

    private static function require_field(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $field = isset( $_POST['field'] ) ? sanitize_key( (string) wp_unslash( $_POST['field'] ) ) : '';
        if ( ! Content_Repository::is_field( $field ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown field.', 'tejcart' ) ), 400 );
        }
        return $field;
    }

    private static function require_product_id(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $pid = isset( $_POST['product_id'] ) ? absint( (string) wp_unslash( $_POST['product_id'] ) ) : 0;
        if ( $pid <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product.', 'tejcart' ) ), 400 );
        }
        return $pid;
    }

    /* ----------------------- 1. Validate API key ----------------------- */

    public static function handle_validate_api_key(): void {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $client = new OpenAI_Client();
        $result = $client->validate_key( $key );
        if ( ! empty( $result['ok'] ) ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        }
        wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Invalid', 'tejcart' ) ) );
    }

    /* ------------------------ 2. Fetch products ------------------------ */

    public static function handle_fetch_products(): void {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $raw = wp_unslash( (array) $_POST );
        $args = array(
            'category'     => (int) ( $raw['category'] ?? 0 ),
            'product_type' => sanitize_key( (string) ( $raw['product_type'] ?? '' ) ),
            'stock_status' => sanitize_key( (string) ( $raw['stock_status'] ?? '' ) ),
            'search'       => sanitize_text_field( (string) ( $raw['search'] ?? '' ) ),
            'per_page'     => (int) ( $raw['per_page'] ?? 25 ),
            'page'         => (int) ( $raw['page'] ?? 1 ),
            'field'        => self::require_field(),
        );
        $result = Product_Query::run( $args );
        wp_send_json_success( $result );
    }

    /* ---------------------- 3. Generate (single) ---------------------- */

    public static function handle_generate_content(): void {
        self::guard();
        $field      = self::require_field();
        $product_id = self::require_product_id();

        $generator = new Generator();
        $result    = $generator->generate( $product_id, $field );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['error'] ?? __( 'Error.', 'tejcart' ) ) ), 502 );
        }
        wp_send_json_success( array(
            'value' => $result['value'] ?? '',
            'field' => $field,
        ) );
    }

    /**
     * Maximum length (characters) of the `extra` hint field that can be
     * appended to the generation prompt. Capped here to prevent prompt-
     * injection via wall-of-text or instruction-override attacks.
     *
     * F-MODS-007: `extra` was previously sanitised with
     * `sanitize_textarea_field()` (which keeps all printable chars) but
     * not fenced or length-capped, so a user with `tejcart_manage_ai_content`
     * could inject arbitrary instructions into the prompt. A 500-char cap
     * defeats wall-of-text vectors; the Generator should pass `extra`
     * through Prompt_Renderer fencing if it is appended to an untrusted
     * section of the prompt.
     */
    private const EXTRA_MAX_LENGTH = 500;

    /* ------------------- 4. Regenerate (with extra) ------------------- */

    public static function handle_regenerate_content(): void {
        self::guard();
        $field      = self::require_field();
        $product_id = self::require_product_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $extra = isset( $_POST['extra'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['extra'] ) ) : '';

        // F-MODS-007: Hard cap the extra hint to prevent prompt-injection
        // via long arbitrary text. Trim to the cap before passing to the
        // generator so the model never sees more than the allowed length.
        if ( mb_strlen( $extra ) > self::EXTRA_MAX_LENGTH ) {
            $extra = mb_substr( $extra, 0, self::EXTRA_MAX_LENGTH );
        }

        $generator = new Generator();
        $result    = $generator->generate( $product_id, $field, $extra );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['error'] ?? __( 'Error.', 'tejcart' ) ) ), 502 );
        }
        wp_send_json_success( array(
            'value' => $result['value'] ?? '',
            'field' => $field,
        ) );
    }

    /* ----------------------- 5. Save (temp meta) ----------------------- */

    public static function handle_save_content(): void {
        self::guard();
        $field      = self::require_field();
        $product_id = self::require_product_id();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in router dispatch; value is sanitized per-field below via Formatter / wp_kses_post
        $raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
        $value = $raw;

        if ( Content_Repository::FIELD_FAQS === $field ) {
            $decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
            $value   = Formatter::sanitize_faq_input( is_array( $decoded ) ? $decoded : array() );
        } elseif ( Content_Repository::FIELD_NAME === $field ) {
            $value = Formatter::format_name( (string) $raw );
        } elseif ( Content_Repository::FIELD_TAGS === $field ) {
            $parsed = Formatter::format_tags( (string) $raw );
            $value  = $parsed['display'];
        } else {
            $value = wp_kses_post( (string) $raw );
        }

        $ok = Content_Repository::set_temp( $product_id, $field, $value );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'Save failed.', 'tejcart' ) ) );
        }
        wp_send_json_success( array(
            'value' => Content_Repository::get_temp( $product_id, $field ),
            'field' => $field,
        ) );
    }

    /* ------------------------ 6. Apply (single) ------------------------ */

    public static function handle_apply_content(): void {
        self::guard();
        $field      = self::require_field();
        $product_id = self::require_product_id();
        $result     = Content_Repository::apply( $product_id, $field );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Error.', 'tejcart' ) ) ) );
        }
        wp_send_json_success( array( 'product_id' => $product_id, 'field' => $field ) );
    }

    /* -------------------- 7. Apply selected (bulk) -------------------- */

    public static function handle_apply_selected_products(): void {
        self::guard();
        $field = self::require_field();
        $ids   = self::parse_product_ids();

        $applied = 0;
        $failed  = 0;
        foreach ( $ids as $pid ) {
            $r = Content_Repository::apply( $pid, $field );
            if ( ! empty( $r['ok'] ) ) {
                $applied++;
            } else {
                $failed++;
            }
        }
        wp_send_json_success( array(
            'applied' => $applied,
            'failed'  => $failed,
            'total'   => count( $ids ),
            'field'   => $field,
        ) );
    }

    /* ---------------------- 8. Bulk generate ---------------------- */

    public static function handle_bulk_generate(): void {
        self::guard();
        $field = self::require_field();
        $ids   = self::parse_product_ids();

        $bulk   = new Bulk_Generator();
        $result = $bulk->enqueue( $ids, $field );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['error'] ?? __( 'Error.', 'tejcart' ) ) ) );
        }
        wp_send_json_success( array(
            'batch_id' => $result['batch_id'],
            'total'    => $result['total'],
        ) );
    }

    /* --------------------- 9. Bulk status poll --------------------- */

    public static function handle_check_generation_status(): void {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in router dispatch
        $batch_id = isset( $_POST['batch_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['batch_id'] ) ) : '';
        $progress = Bulk_Generator::get_progress( $batch_id );
        if ( null === $progress ) {
            wp_send_json_error( array( 'message' => __( 'Unknown batch.', 'tejcart' ) ), 404 );
        }
        wp_send_json_success( $progress );
    }

    /* ---------------------- 10. Revert (undo apply) ---------------------- */

    public static function handle_revert_content(): void {
        self::guard();
        $field      = self::require_field();
        $product_id = self::require_product_id();
        $result     = Content_Repository::revert( $product_id, $field );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Error.', 'tejcart' ) ) ) );
        }
        wp_send_json_success( array( 'product_id' => $product_id, 'field' => $field ) );
    }

    /**
     * @return int[]
     */
    private static function parse_product_ids(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in router dispatch; each id is sanitized via absint() in the loop below
        $raw = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array();
        if ( ! is_array( $raw ) ) {
            $raw = array( $raw );
        }
        $ids = array();
        foreach ( $raw as $v ) {
            $pid = absint( (string) $v );
            if ( $pid > 0 ) {
                $ids[] = $pid;
            }
        }
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No products selected.', 'tejcart' ) ), 400 );
        }
        return array_values( array_unique( $ids ) );
    }
}
