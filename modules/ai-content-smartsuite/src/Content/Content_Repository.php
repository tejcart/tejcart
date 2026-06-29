<?php
/**
 * Read / write the temp ("staged") and live AI content fields.
 *
 * @package TejCart\AI_Content_Smartsuite\Content
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Content;

use TejCart\Product\Product_Factory;
use TejCart\Product\Product_Meta;
use TejCart\Product\Product_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Content_Repository {
    public const FIELD_NAME        = 'name';
    public const FIELD_SHORTDESC   = 'shortdesc';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_TAGS        = 'tags';
    public const FIELD_FAQS        = 'faqs';

    public const META_NAME        = '_tejcart_ai_name';
    public const META_SHORTDESC   = '_tejcart_ai_shortdesc';
    public const META_DESCRIPTION = '_tejcart_ai_description';
    public const META_TAGS        = '_tejcart_ai_tags';
    public const META_FAQS        = '_tejcart_ai_faqs';
    public const META_LIVE_FAQS   = '_tejcart_ai_live_faqs';

    public const META_PRE_APPLY_PREFIX = '_tejcart_ai_pre_apply_';

    public const ALL_FIELDS = array(
        self::FIELD_NAME,
        self::FIELD_SHORTDESC,
        self::FIELD_DESCRIPTION,
        self::FIELD_TAGS,
        self::FIELD_FAQS,
    );

    public static function is_field( string $field ): bool {
        return in_array( $field, self::ALL_FIELDS, true );
    }

    public static function meta_key_for( string $field ): string {
        switch ( $field ) {
            case self::FIELD_NAME:        return self::META_NAME;
            case self::FIELD_SHORTDESC:   return self::META_SHORTDESC;
            case self::FIELD_DESCRIPTION: return self::META_DESCRIPTION;
            case self::FIELD_TAGS:        return self::META_TAGS;
            case self::FIELD_FAQS:        return self::META_FAQS;
        }
        return '';
    }

    /**
     * @param mixed $value
     */
    public static function set_temp( int $product_id, string $field, $value ): bool {
        if ( ! self::is_field( $field ) ) {
            return false;
        }
        $key = self::meta_key_for( $field );

        if ( self::FIELD_FAQS === $field ) {
            $faqs = is_array( $value ) ? Formatter::sanitize_faq_input( $value ) : Formatter::decode_faqs( $value );
            return Product_Meta::update( $product_id, $key, Formatter::encode_faqs( $faqs ) );
        }

        return Product_Meta::update( $product_id, $key, (string) $value );
    }

    /**
     * @return string|array<int, array{question:string,answer:string}>|null
     */
    public static function get_temp( int $product_id, string $field ) {
        if ( ! self::is_field( $field ) ) {
            return null;
        }
        $value = Product_Meta::get( $product_id, self::meta_key_for( $field ), true );
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( self::FIELD_FAQS === $field ) {
            return Formatter::decode_faqs( $value );
        }
        return (string) $value;
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public static function apply( int $product_id, string $field ): array {
        if ( ! self::is_field( $field ) ) {
            return array( 'ok' => false, 'message' => __( 'Unknown field.', 'tejcart' ) );
        }
        $temp = self::get_temp( $product_id, $field );
        if ( null === $temp ) {
            return array( 'ok' => false, 'message' => __( 'Nothing to apply — generate first.', 'tejcart' ) );
        }

        $product = Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return array( 'ok' => false, 'message' => __( 'Product not found.', 'tejcart' ) );
        }

        $live_before = self::get_live( $product_id, $field );
        Product_Meta::update( $product_id, self::META_PRE_APPLY_PREFIX . $field, $live_before );

        switch ( $field ) {
            case self::FIELD_NAME:
                $product->set_name( Formatter::format_name( (string) $temp ) );
                $saved = $product->save();
                break;
            case self::FIELD_SHORTDESC:
                $product->set_short_description( wp_kses_post( (string) $temp ) );
                $saved = $product->save();
                break;
            case self::FIELD_DESCRIPTION:
                $product->set_description( wp_kses_post( (string) $temp ) );
                $saved = $product->save();
                break;
            case self::FIELD_TAGS:
                $saved = self::apply_tags( $product_id, (string) $temp );
                break;
            case self::FIELD_FAQS:
                $faqs  = is_array( $temp ) ? $temp : Formatter::decode_faqs( $temp );
                $saved = Product_Meta::update( $product_id, self::META_LIVE_FAQS, Formatter::encode_faqs( $faqs ) );
                break;
            default:
                $saved = false;
        }

        Product_Factory::forget( $product_id );

        if ( ! $saved ) {
            return array( 'ok' => false, 'message' => __( 'Save failed.', 'tejcart' ) );
        }

        /**
         * Fires when an AI-generated field has been applied to a product.
         */
        do_action( 'tejcart_ai_content_after_apply', $product_id, $field );

        return array( 'ok' => true );
    }

    public static function has_pre_apply_snapshot( int $product_id, string $field ): bool {
        if ( ! self::is_field( $field ) ) {
            return false;
        }
        $val = Product_Meta::get( $product_id, self::META_PRE_APPLY_PREFIX . $field, true );
        return null !== $val && '' !== $val;
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public static function revert( int $product_id, string $field ): array {
        if ( ! self::is_field( $field ) ) {
            return array( 'ok' => false, 'message' => __( 'Unknown field.', 'tejcart' ) );
        }

        $snapshot = Product_Meta::get( $product_id, self::META_PRE_APPLY_PREFIX . $field, true );
        if ( null === $snapshot || '' === $snapshot ) {
            return array( 'ok' => false, 'message' => __( 'No previous version to revert to.', 'tejcart' ) );
        }

        $product = Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return array( 'ok' => false, 'message' => __( 'Product not found.', 'tejcart' ) );
        }

        switch ( $field ) {
            case self::FIELD_NAME:
                $product->set_name( (string) $snapshot );
                $saved = $product->save();
                break;
            case self::FIELD_SHORTDESC:
                $product->set_short_description( (string) $snapshot );
                $saved = $product->save();
                break;
            case self::FIELD_DESCRIPTION:
                $product->set_description( (string) $snapshot );
                $saved = $product->save();
                break;
            case self::FIELD_TAGS:
                $saved = self::apply_tags( $product_id, (string) $snapshot );
                break;
            case self::FIELD_FAQS:
                $saved = Product_Meta::update( $product_id, self::META_LIVE_FAQS, (string) $snapshot );
                break;
            default:
                $saved = false;
        }

        Product_Factory::forget( $product_id );

        if ( ! $saved ) {
            return array( 'ok' => false, 'message' => __( 'Revert failed.', 'tejcart' ) );
        }

        Product_Meta::delete( $product_id, self::META_PRE_APPLY_PREFIX . $field );

        return array( 'ok' => true );
    }

    public static function get_live( int $product_id, string $field ): string {
        $product = Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        switch ( $field ) {
            case self::FIELD_NAME:
                return (string) $product->get_name();
            case self::FIELD_SHORTDESC:
                return (string) $product->get_short_description();
            case self::FIELD_DESCRIPTION:
                return (string) $product->get_description();
            case self::FIELD_TAGS:
                $tags  = Product_Taxonomy::get_product_tags( $product_id );
                $names = array();
                foreach ( $tags as $t ) {
                    if ( isset( $t->name ) ) {
                        $names[] = (string) $t->name;
                    }
                }
                return implode( ', ', $names );
            case self::FIELD_FAQS:
                $raw = Product_Meta::get( $product_id, self::META_LIVE_FAQS, true );
                return is_string( $raw ) ? $raw : '';
        }
        return '';
    }

    private static function apply_tags( int $product_id, string $raw ): bool {
        $parsed = Formatter::format_tags( $raw );
        $term_ids = array();

        foreach ( $parsed['list'] as $name ) {
            $existing = get_term_by( 'name', $name, Product_Taxonomy::TAG_TAXONOMY );
            if ( $existing && ! is_wp_error( $existing ) ) {
                $term_ids[] = (int) $existing->term_id;
                continue;
            }
            $result = wp_insert_term( $name, Product_Taxonomy::TAG_TAXONOMY );
            if ( is_wp_error( $result ) ) {
                $data = $result->get_error_data();
                if ( is_array( $data ) && isset( $data['term_id'] ) ) {
                    $term_ids[] = (int) $data['term_id'];
                }
                continue;
            }
            if ( isset( $result['term_id'] ) ) {
                $term_ids[] = (int) $result['term_id'];
            }
        }

        $term_ids = array_values( array_unique( array_filter( $term_ids ) ) );

        return Product_Taxonomy::set_product_tags( $product_id, $term_ids );
    }

    public static function delete_temp( int $product_id, string $field ): bool {
        if ( ! self::is_field( $field ) ) {
            return false;
        }
        return Product_Meta::delete( $product_id, self::meta_key_for( $field ) );
    }
}
