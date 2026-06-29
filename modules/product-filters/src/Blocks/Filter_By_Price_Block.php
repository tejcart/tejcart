<?php
declare( strict_types=1 );

namespace TejCart\Product_Filters\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product_Filters\Product_Filter;

class Filter_By_Price_Block {
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'heading'        => array(
                'type'    => 'string',
                'default' => '',
            ),
            'showHistogram'  => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'showInputs'     => array(
                'type'    => 'boolean',
                'default' => true,
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
            'showHistogram' => true,
            'showInputs'    => true,
        ) );

        $state  = $filter->parse_url_state();
        $counts = $filter->get_facet_counts( $state );
        $range  = $counts['price_range'] ?? array( 'min' => 0, 'max' => 0 );

        if ( $range['max'] <= 0 ) {
            return '';
        }

        $args = array(
            'state'          => $state,
            'range'          => $range,
            'histogram'      => $counts['price_histogram'] ?? array( 'buckets' => array(), 'max_count' => 0, 'bucket_width' => 0 ),
            'heading'        => $attributes['heading'],
            'show_histogram' => (bool) $attributes['showHistogram'],
            'show_inputs'    => (bool) $attributes['showInputs'],
        );

        ob_start();
        tejcart_get_template( 'blocks/filter-by-price.php', $args );
        return ob_get_clean();
    }
}
