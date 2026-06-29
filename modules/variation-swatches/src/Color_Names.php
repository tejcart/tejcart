<?php
/**
 * CSS color-name resolver for zero-configuration swatches.
 *
 * Maps common human color words (and raw hex codes) to hex values so a
 * plain product attribute value like "blue" or "Navy" renders as a real
 * color swatch with no per-term or per-product setup required.
 *
 * @package TejCart\Variation_Swatches
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves attribute values to hex colors using the standard CSS color
 * keyword set plus direct hex parsing.
 */
class Color_Names {

    /**
     * Normalised color-keyword => hex map (lowercase, no separators).
     *
     * Keys are stripped of spaces/dashes/underscores so "light blue",
     * "light-blue" and "lightblue" all resolve to the same entry.
     *
     * @return array<string, string>
     */
    public static function map(): array {
        return array(
            'black'                => '#000000',
            'white'                => '#ffffff',
            'red'                  => '#ff0000',
            'green'                => '#008000',
            'blue'                 => '#0000ff',
            'yellow'               => '#ffff00',
            'orange'               => '#ffa500',
            'purple'               => '#800080',
            'pink'                 => '#ffc0cb',
            'brown'                => '#a52a2a',
            'grey'                 => '#808080',
            'gray'                 => '#808080',
            'silver'               => '#c0c0c0',
            'gold'                 => '#ffd700',
            'beige'                => '#f5f5dc',
            'ivory'                => '#fffff0',
            'cream'                => '#fffdd0',
            'tan'                  => '#d2b48c',
            'navy'                 => '#000080',
            'navyblue'             => '#000080',
            'teal'                 => '#008080',
            'turquoise'            => '#40e0d0',
            'cyan'                 => '#00ffff',
            'aqua'                 => '#00ffff',
            'magenta'             => '#ff00ff',
            'fuchsia'              => '#ff00ff',
            'maroon'               => '#800000',
            'olive'                => '#808000',
            'lime'                 => '#00ff00',
            'indigo'               => '#4b0082',
            'violet'               => '#ee82ee',
            'lavender'             => '#e6e6fa',
            'coral'                => '#ff7f50',
            'salmon'               => '#fa8072',
            'crimson'              => '#dc143c',
            'khaki'                => '#f0e68c',
            'mint'                 => '#98ff98',
            'mintgreen'            => '#98ff98',
            'peach'                => '#ffe5b4',
            'plum'                 => '#dda0dd',
            'rose'                 => '#ff007f',
            'charcoal'             => '#36454f',
            'slate'                => '#708090',
            'slategray'            => '#708090',
            'slategrey'            => '#708090',
            'darkred'              => '#8b0000',
            'darkgreen'            => '#006400',
            'darkblue'             => '#00008b',
            'darkgray'             => '#a9a9a9',
            'darkgrey'             => '#a9a9a9',
            'darkorange'           => '#ff8c00',
            'lightblue'            => '#add8e6',
            'lightgreen'           => '#90ee90',
            'lightgray'            => '#d3d3d3',
            'lightgrey'            => '#d3d3d3',
            'lightpink'            => '#ffb6c1',
            'lightyellow'          => '#ffffe0',
            'skyblue'              => '#87ceeb',
            'royalblue'            => '#4169e1',
            'steelblue'            => '#4682b4',
            'forestgreen'          => '#228b22',
            'seagreen'             => '#2e8b57',
            'olivedrab'            => '#6b8e23',
            'chocolate'            => '#d2691e',
            'sienna'               => '#a0522d',
            'wheat'                => '#f5deb3',
            'tomato'               => '#ff6347',
            'orangered'            => '#ff4500',
            'hotpink'              => '#ff69b4',
            'deeppink'             => '#ff1493',
            'goldenrod'            => '#daa520',
            'mustard'              => '#e1ad01',
            'burgundy'             => '#800020',
            'wine'                 => '#722f37',
            'emerald'              => '#50c878',
            'jade'                 => '#00a86b',
            'amber'                => '#ffbf00',
            'apricot'              => '#fbceb1',
            'aquamarine'           => '#7fffd4',
            'azure'                => '#f0ffff',
            'periwinkle'           => '#ccccff',
            'mauve'                => '#e0b0ff',
            'lilac'                => '#c8a2c8',
            'taupe'                => '#483c32',
            'sand'                 => '#c2b280',
            'stone'                => '#928e85',
            'denim'                => '#1560bd',
            'cobalt'               => '#0047ab',
            'cornflowerblue'       => '#6495ed',
            'powderblue'           => '#b0e0e6',
            'midnightblue'         => '#191970',
            'limegreen'            => '#32cd32',
            'springgreen'          => '#00ff7f',
            'chartreuse'           => '#7fff00',
            'darkviolet'           => '#9400d3',
            'orchid'               => '#da70d6',
            'thistle'              => '#d8bfd8',
            'tomatored'            => '#ff6347',
            'firebrick'            => '#b22222',
            'brick'                => '#b22222',
            'rust'                 => '#b7410e',
            'copper'               => '#b87333',
            'bronze'               => '#cd7f32',
            'pearl'                => '#eae0c8',
            'offwhite'             => '#faf9f6',
            'eggshell'             => '#f0ead6',
            'snow'                 => '#fffafa',
            'jetblack'             => '#0a0a0a',
            'graphite'             => '#383838',
            'gunmetal'             => '#2a3439',
            'aliceblue'            => '#f0f8ff',
            'antiquewhite'         => '#faebd7',
            'blueviolet'           => '#8a2be2',
            'cadetblue'            => '#5f9ea0',
            'darkcyan'             => '#008b8b',
            'darkgoldenrod'        => '#b8860b',
            'darkkhaki'            => '#bdb76b',
            'darkmagenta'          => '#8b008b',
            'darkolivegreen'       => '#556b2f',
            'darkorchid'           => '#9932cc',
            'darksalmon'           => '#e9967a',
            'darkseagreen'         => '#8fbc8f',
            'darkslateblue'        => '#483d8b',
            'darkslategray'        => '#2f4f4f',
            'darkturquoise'        => '#00ced1',
            'deepskyblue'          => '#00bfff',
            'dimgray'              => '#696969',
            'dodgerblue'           => '#1e90ff',
            'gainsboro'            => '#dcdcdc',
            'greenyellow'          => '#adff2f',
            'indianred'            => '#cd5c5c',
            'lawngreen'            => '#7cfc00',
            'lightcoral'           => '#f08080',
            'lightsalmon'          => '#ffa07a',
            'lightseagreen'        => '#20b2aa',
            'lightskyblue'         => '#87cefa',
            'mediumblue'           => '#0000cd',
            'mediumpurple'         => '#9370db',
            'mediumseagreen'       => '#3cb371',
            'palegreen'            => '#98fb98',
            'paleturquoise'        => '#afeeee',
            'palevioletred'        => '#db7093',
            'saddlebrown'          => '#8b4513',
            'sandybrown'           => '#f4a460',
            'seashell'             => '#fff5ee',
            'tealblue'             => '#367588',
        );
    }

    /**
     * Resolve an attribute value to a hex color, or '' when it is not a
     * recognisable color.
     *
     * Accepts CSS color keywords (case/space-insensitive) and raw 3- or
     * 6-digit hex codes (with or without a leading "#").
     *
     * @param string $value Raw attribute value (e.g. "Navy Blue", "#0af").
     * @return string Hex color like "#000080", or '' if not a color.
     */
    public static function to_hex( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( '' === $value ) {
            return '';
        }

        // Raw hex codes, with or without the leading hash.
        $hex = ltrim( $value, '#' );
        if ( preg_match( '/^[0-9a-f]{3}$/', $hex ) || preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
            return '#' . $hex;
        }

        // Named color: normalise away spaces/dashes/underscores.
        $key = (string) preg_replace( '/[\s_-]+/', '', $value );
        $map = self::map();

        return $map[ $key ] ?? '';
    }

    /**
     * Whether a value resolves to a known color.
     */
    public static function is_color( string $value ): bool {
        return '' !== self::to_hex( $value );
    }
}
