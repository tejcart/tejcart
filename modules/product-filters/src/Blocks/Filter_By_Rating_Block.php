<?php
declare( strict_types=1 );

namespace TejCart\Product_Filters\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product_Filters\Product_Filter;

class Filter_By_Rating_Block {
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'heading'    => array(
                'type'    => 'string',
                'default' => '',
            ),
            'showCounts' => array(
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
            'heading'    => '',
            'showCounts' => true,
        ) );

        $state   = $filter->parse_url_state();
        $counts  = $filter->get_facet_counts( $state );
        $buckets = $counts['ratings'] ?? array();

        $has_any = false;
        for ( $s = 5; $s >= 1; $s-- ) {
            if ( ( $buckets[ $s ] ?? 0 ) > 0 ) {
                $has_any = true;
                break;
            }
        }

        if ( ! $has_any ) {
            return '';
        }

        $args = array(
            'state'       => $state,
            'buckets'     => $buckets,
            'heading'     => $attributes['heading'],
            'show_counts' => (bool) $attributes['showCounts'],
        );

        ob_start();
        tejcart_get_template( 'blocks/filter-by-rating.php', $args );
        return ob_get_clean();
    }
}
