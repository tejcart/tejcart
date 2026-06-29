<?php
declare( strict_types=1 );

namespace TejCart\Product_Filters\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product_Filters\Product_Filter;

class Filter_By_Attribute_Block {
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'heading'       => array(
                'type'    => 'string',
                'default' => '',
            ),
            'attributeName' => array(
                'type'    => 'string',
                'default' => '',
            ),
            'showCounts'    => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'displayStyle'  => array(
                'type'    => 'string',
                'default' => 'list',
                'enum'    => array( 'list', 'inline' ),
            ),
        );
    }

    /**
     * @param array<string, mixed> $attributes Block attributes.
     * @param string               $content    Inner block content.
     */
    public function render( array $attributes, string $content = '' ): string {
        $filter = Product_Filter::get_instance();
        if ( ! $filter ) {
            return '';
        }

        $attributes = wp_parse_args( $attributes, array(
            'heading'       => '',
            'attributeName' => '',
            'showCounts'    => true,
            'displayStyle'  => 'list',
        ) );

        $state  = $filter->parse_url_state();
        $counts = $filter->get_facet_counts( $state );

        $all_attributes   = $filter->get_filterable_attributes();
        $attribute_counts = $counts['attributes'] ?? array();
        $target_attr      = sanitize_text_field( $attributes['attributeName'] );

        $args = array(
            'state'            => $state,
            'all_attributes'   => $all_attributes,
            'attribute_counts' => $attribute_counts,
            'target_attr'      => $target_attr,
            'heading'          => $attributes['heading'],
            'show_counts'      => (bool) $attributes['showCounts'],
            'display_style'    => in_array( $attributes['displayStyle'], array( 'list', 'inline' ), true )
                                  ? $attributes['displayStyle'] : 'list',
        );

        ob_start();
        tejcart_get_template( 'blocks/filter-by-attribute.php', $args );
        return ob_get_clean();
    }
}
