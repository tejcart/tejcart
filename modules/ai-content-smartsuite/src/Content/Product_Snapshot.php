<?php
/**
 * Reads the fields the prompt templates need out of a TejCart product.
 *
 * @package TejCart\AI_Content_Smartsuite\Content
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Content;

use TejCart\Product\Product_Factory;
use TejCart\Product\Product_Taxonomy;
use TejCart\Product\Product_Types\Abstract_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Snapshot {
    /**
     * @return array<string,string>|null
     */
    public static function for_product( int $product_id ): ?array {
        $product = Product_Factory::get_product( $product_id );
        if ( ! $product instanceof Abstract_Product ) {
            return null;
        }

        return array(
            'product_name'        => (string) $product->get_name(),
            'product_description' => (string) $product->get_description(),
            'product_short_desc'  => (string) $product->get_short_description(),
            'product_tags'        => self::join_term_names( Product_Taxonomy::get_product_tags( $product_id ) ),
            'product_category'    => self::join_term_names( Product_Taxonomy::get_product_categories( $product_id ) ),
            'product_attributes'  => self::build_attributes_string( $product, $product_id ),
        );
    }

    private static function build_attributes_string( Abstract_Product $product, int $product_id ): string {
        $parts = array();

        $weight = (string) $product->get_weight();
        if ( '' !== $weight && '0' !== $weight ) {
            $parts[] = 'Weight: ' . $weight;
        }

        $dims = $product->get_dimensions();
        if ( is_array( $dims ) ) {
            $dim_bits = array();
            foreach ( array( 'length', 'width', 'height' ) as $k ) {
                $v = isset( $dims[ $k ] ) ? (string) $dims[ $k ] : '';
                if ( '' !== $v && '0' !== $v ) {
                    $dim_bits[] = ucfirst( $k ) . ' ' . $v;
                }
            }
            if ( ! empty( $dim_bits ) ) {
                $parts[] = 'Dimensions: ' . implode( ' × ', $dim_bits );
            }
        }

        $type = (string) $product->get_type();
        if ( '' !== $type ) {
            $parts[] = 'Type: ' . $type;
        }

        $brand = self::join_term_names( Product_Taxonomy::get_product_brands( $product_id ) );
        if ( '' !== $brand ) {
            $parts[] = 'Brand: ' . $brand;
        }

        $attrs = $product->get_meta( '_product_attributes' );
        if ( is_array( $attrs ) ) {
            foreach ( $attrs as $attr ) {
                if ( ! is_array( $attr ) ) {
                    continue;
                }
                $name  = isset( $attr['name'] ) ? trim( (string) $attr['name'] ) : '';
                $value = isset( $attr['value'] ) ? trim( (string) $attr['value'] ) : '';
                if ( '' === $name || '' === $value ) {
                    continue;
                }
                $parts[] = $name . ': ' . $value;
            }
        }

        return implode( ', ', $parts );
    }

    /**
     * @param \WP_Term[] $terms
     */
    private static function join_term_names( array $terms ): string {
        $names = array();
        foreach ( $terms as $term ) {
            if ( isset( $term->name ) ) {
                $names[] = (string) $term->name;
            }
        }
        return implode( ', ', $names );
    }
}
