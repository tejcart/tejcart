<?php
/**
 * Single-product / single-field AI generation.
 *
 * @package TejCart\AI_Content_Smartsuite\Generator
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Generator;

use TejCart\AI_Content_Smartsuite\AI\OpenAI_Client;
use TejCart\AI_Content_Smartsuite\AI\Prompt_Renderer;
use TejCart\AI_Content_Smartsuite\Content\Content_Repository;
use TejCart\AI_Content_Smartsuite\Content\Formatter;
use TejCart\AI_Content_Smartsuite\Content\Product_Snapshot;
use TejCart\AI_Content_Smartsuite\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Generator {
    public const FIELD_PROMPT_KEYS = array(
        Content_Repository::FIELD_NAME        => 'name_prompt',
        Content_Repository::FIELD_SHORTDESC   => 'short_desc_prompt',
        Content_Repository::FIELD_DESCRIPTION => 'description_prompt',
        Content_Repository::FIELD_TAGS        => 'tags_prompt',
        Content_Repository::FIELD_FAQS        => 'faqs_prompt',
    );

    private OpenAI_Client $client;

    public function __construct( ?OpenAI_Client $client = null ) {
        $this->client = $client ?? new OpenAI_Client();
    }

    /**
     * @return array{ok:bool, value?:mixed, display?:string, error?:string}
     */
    public function generate( int $product_id, string $field, string $extra_instruction = '' ): array {
        if ( ! Content_Repository::is_field( $field ) ) {
            return array( 'ok' => false, 'error' => __( 'Unknown field.', 'tejcart' ) );
        }

        $snapshot = Product_Snapshot::for_product( $product_id );
        if ( null === $snapshot ) {
            return array( 'ok' => false, 'error' => __( 'Product not found.', 'tejcart' ) );
        }

        $settings    = Settings::get();
        $prompt_key  = self::FIELD_PROMPT_KEYS[ $field ];
        $template    = (string) ( $settings['prompts'][ $prompt_key ] ?? '' );
        if ( '' === $template ) {
            return array( 'ok' => false, 'error' => __( 'Prompt template is empty.', 'tejcart' ) );
        }

        $rendered = Prompt_Renderer::render( $template, $snapshot );
        $extra    = trim( $extra_instruction );
        if ( '' !== $extra ) {
            $rendered .= "\n\nAdditional instruction: " . $extra;
        }

        $response = $this->client->complete(
            $rendered,
            array(
                'product_id' => $product_id,
                'field'      => $field,
            )
        );

        if ( empty( $response['ok'] ) ) {
            return array( 'ok' => false, 'error' => (string) ( $response['error'] ?? __( 'Unknown error.', 'tejcart' ) ) );
        }

        $raw = (string) ( $response['content'] ?? '' );

        switch ( $field ) {
            case Content_Repository::FIELD_NAME:
                $clean = Formatter::format_name( $raw );
                Content_Repository::set_temp( $product_id, $field, $clean );
                $payload = array( 'value' => $clean, 'display' => $clean );
                break;
            case Content_Repository::FIELD_SHORTDESC:
            case Content_Repository::FIELD_DESCRIPTION:
                $clean = Formatter::format_html_body( $raw );
                Content_Repository::set_temp( $product_id, $field, $clean );
                $payload = array( 'value' => $clean, 'display' => $clean );
                break;
            case Content_Repository::FIELD_TAGS:
                $parsed = Formatter::format_tags( $raw );
                Content_Repository::set_temp( $product_id, $field, $parsed['display'] );
                $payload = array( 'value' => $parsed['display'], 'display' => $parsed['display'], 'list' => $parsed['list'] );
                break;
            case Content_Repository::FIELD_FAQS:
                $faqs = Formatter::format_faqs( $raw );
                Content_Repository::set_temp( $product_id, $field, $faqs );
                $payload = array( 'value' => $faqs, 'display' => $faqs );
                break;
            default:
                $payload = array( 'value' => $raw, 'display' => $raw );
        }

        /**
         * Fires after a successful single-field generation.
         */
        do_action( 'tejcart_ai_content_after_generate', $product_id, $field, $payload['value'] );

        return array_merge( array( 'ok' => true ), $payload );
    }
}
