<?php
/**
 * External CSV import compatibility shim.
 *
 * Detects product CSV files whose header conventions differ from TejCart's
 * canonical export format and rewrites the header row + per-row data into
 * the canonical shape so the existing Product_Import_Export pipeline can
 * consume them without further changes.
 *
 * This bridge is silent: users do not need to know which format they
 * uploaded, and the TejCart export format is unchanged.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stateless CSV header / row translator for foreign product CSV formats.
 */
final class External_CSV_Compat {

    /**
     * Direct 1:1 header mapping. Keys are the lowercased / trimmed foreign
     * header, values are the TejCart canonical column name. Multi-column
     * aggregates (attributes, downloads, images) are handled by
     * {@see canonicalize_headers()} and {@see translate_row()} separately.
     *
     * @var array<string,string>
     */
    private const HEADER_MAP = array(
        'id'                      => 'id',
        'type'                    => 'type',
        'sku'                     => 'sku',
        'name'                    => 'name',
        'short description'       => 'short_description',
        'description'             => 'description',
        'regular price'           => 'price',
        'sale price'              => 'sale_price',
        'stock'                   => 'stock_quantity',
        'tax class'               => 'tax_class',
        'shipping class'          => 'shipping_class',
        'weight (kg)'             => 'weight',
        'weight (g)'              => 'weight',
        'weight (lbs)'            => 'weight',
        'weight (oz)'             => 'weight',
        'length (cm)'             => 'length',
        'length (m)'              => 'length',
        'length (mm)'             => 'length',
        'length (in)'             => 'length',
        'length (yd)'             => 'length',
        'width (cm)'              => 'width',
        'width (m)'               => 'width',
        'width (mm)'              => 'width',
        'width (in)'              => 'width',
        'width (yd)'              => 'width',
        'height (cm)'             => 'height',
        'height (m)'              => 'height',
        'height (mm)'             => 'height',
        'height (in)'             => 'height',
        'height (yd)'             => 'height',
        'categories'              => 'categories',
        'tags'                    => 'tags',
        'images'                  => '__ext_images',
        'parent'                  => 'variation_parent_sku',
        'grouped products'        => 'grouped_children_skus',
        'upsells'                 => 'upsell_skus',
        'cross-sells'             => 'cross_sell_skus',
        'external url'            => 'external_url',
        'button text'             => 'external_button_text',
        'is featured?'            => 'featured',
        'visibility in catalog'   => 'catalog_visibility',
        'sold individually?'      => 'sold_individually',
        'backorders allowed?'     => 'backorders',
        'in stock?'               => '__ext_in_stock',
        'published'               => '__ext_published',
        'tax status'              => '__ext_tax_status',
        'purchase note'           => '__ext_purchase_note',
        'allow customer reviews?' => '__ext_allow_reviews',
        'low stock amount'        => '__ext_low_stock',
        'download limit'          => '__ext_download_limit',
        'download expiry days'    => '__ext_download_expiry',
        'date sale price starts'  => '__ext_sale_starts',
        'date sale price ends'    => '__ext_sale_ends',
        'position'                => '__ext_position',
    );

    /**
     * Detect whether a header row looks like the foreign product format.
     *
     * We require at least one strongly diagnostic header that TejCart's own
     * exporter would never emit. "regular price" is the cheapest signal —
     * TejCart uses "price". The "?"-suffixed booleans ("in stock?",
     * "is featured?") are equally diagnostic.
     *
     * @param string[] $headers Lowercased, trimmed CSV headers.
     */
    public static function is_external_csv( array $headers ): bool {
        $signals = array(
            'regular price',
            'in stock?',
            'is featured?',
            'tax status',
            'visibility in catalog',
            'sold individually?',
            'backorders allowed?',
            'weight (kg)',
            'length (cm)',
            'date sale price starts',
            'allow customer reviews?',
        );

        foreach ( $signals as $signal ) {
            if ( in_array( $signal, $headers, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate a foreign header row to TejCart canonical names.
     *
     * Repeating-group columns (attributes, downloads) become deterministic
     * placeholders that {@see translate_row()} aggregates on the per-row
     * pass. Unknown columns are passed through unchanged so a custom-flagged
     * column survives the round-trip and stays available to userland filters.
     *
     * The returned array preserves the column order of $headers.
     *
     * @param string[] $headers Lowercased, trimmed CSV headers.
     * @return string[] Translated headers in the same order.
     */
    public static function canonicalize_headers( array $headers ): array {
        $translated = array();
        foreach ( $headers as $header ) {
            $translated[] = self::canonicalize_header( $header );
        }
        return $translated;
    }

    /**
     * Translate a single foreign header to its TejCart canonical key.
     */
    private static function canonicalize_header( string $header ): string {
        if ( isset( self::HEADER_MAP[ $header ] ) ) {
            return self::HEADER_MAP[ $header ];
        }

        // Repeating attribute columns: "attribute 1 name", "attribute 1 value(s)",
        // "attribute 1 visible", "attribute 1 global", "attribute 1 default".
        if ( preg_match( '/^attribute\s+(\d+)\s+(name|value\(s\)|visible|global|default)$/', $header, $m ) ) {
            $field = 'value(s)' === $m[2] ? 'value' : $m[2];
            return '__ext_attr_' . (int) $m[1] . '_' . $field;
        }

        // Repeating download columns: "download 1 name", "download 1 url".
        if ( preg_match( '/^download\s+(\d+)\s+(name|url)$/', $header, $m ) ) {
            return '__ext_dl_' . (int) $m[1] . '_' . $m[2];
        }

        // Meta columns ("Meta: foo") are passed through as namespaced keys so
        // userland filters can pick them up without colliding with canonical
        // columns.
        if ( 0 === strncmp( $header, 'meta: ', 6 ) ) {
            return '__ext_meta_' . substr( $header, 6 );
        }

        return $header;
    }

    /**
     * Translate a parsed foreign row into TejCart canonical data.
     *
     * Expects the keys of $data to already have been canonicalized via
     * {@see canonicalize_headers()}. Applies value normalisation and the
     * multi-column aggregations that can only run with the full row in hand.
     *
     * @param array<string,mixed> $data Header-keyed row data.
     * @return array<string,mixed> Same shape with foreign-format quirks normalised.
     */
    public static function translate_row( array $data ): array {
        $data = self::translate_type( $data );
        $data = self::translate_status( $data );
        $data = self::translate_stock_status( $data );
        $data = self::translate_backorders( $data );
        $data = self::translate_boolean_flags( $data );
        $data = self::translate_categories( $data );
        $data = self::translate_separators( $data );
        $data = self::translate_parent( $data );
        $data = self::translate_images( $data );
        $data = self::translate_attributes( $data );
        $data = self::translate_downloads( $data );
        $data = self::translate_visibility( $data );

        // Strip the leftover bridge-only fields so they don't leak into the
        // product row insert/update arrays. The product handler ignores
        // unknown keys anyway, but this keeps debugging tidy.
        foreach ( array_keys( $data ) as $key ) {
            if ( is_string( $key ) && 0 === strncmp( $key, '__ext_', 6 ) ) {
                unset( $data[ $key ] );
            }
        }

        return $data;
    }

    /**
     * Convert comma-separated type tokens ("simple, virtual, downloadable",
     * "variable", "variation", "grouped", "external") into TejCart's single
     * product-type slug.
     */
    private static function translate_type( array $data ): array {
        if ( ! array_key_exists( 'type', $data ) ) {
            return $data;
        }

        $raw    = strtolower( trim( (string) $data['type'] ) );
        $tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        if ( empty( $tokens ) ) {
            $data['type'] = 'physical';
            return $data;
        }

        // Order matters: a "variable subscription" must resolve to "variable",
        // not "subscription". Check the structural types first.
        if ( in_array( 'variation', $tokens, true ) ) {
            $data['type'] = 'variation';
            return $data;
        }
        if ( in_array( 'variable', $tokens, true ) || in_array( 'variable-subscription', $tokens, true ) ) {
            $data['type'] = 'variable';
            return $data;
        }
        if ( in_array( 'grouped', $tokens, true ) ) {
            $data['type'] = 'grouped';
            return $data;
        }
        if ( in_array( 'external', $tokens, true ) ) {
            $data['type'] = 'external';
            return $data;
        }
        if ( in_array( 'bundle', $tokens, true ) ) {
            $data['type'] = 'bundle';
            return $data;
        }

        $is_virtual      = in_array( 'virtual', $tokens, true );
        $is_downloadable = in_array( 'downloadable', $tokens, true );
        if ( $is_downloadable ) {
            $data['type'] = 'digital';
            return $data;
        }
        if ( $is_virtual ) {
            $data['type'] = 'virtual';
            return $data;
        }

        $data['type'] = 'physical';
        return $data;
    }

    /**
     * Map the "Published" tri-state (1 / 0 / -1) to TejCart "status".
     * Trash (-1) is folded into draft since TejCart has no trash bucket.
     */
    private static function translate_status( array $data ): array {
        if ( ! array_key_exists( '__ext_published', $data ) ) {
            return $data;
        }
        $value = trim( (string) $data['__ext_published'] );
        switch ( $value ) {
            case '1':
                $data['status'] = 'publish';
                break;
            case '0':
                $data['status'] = 'draft';
                break;
            case '-1':
                $data['status'] = 'draft';
                break;
            case '':
                // Leave status to the product handler's default.
                break;
            default:
                $data['status'] = 'publish';
        }
        return $data;
    }

    /**
     * Translate "In stock?" 1/0 boolean to TejCart stock_status.
     */
    private static function translate_stock_status( array $data ): array {
        if ( ! array_key_exists( '__ext_in_stock', $data ) ) {
            return $data;
        }
        $value = strtolower( trim( (string) $data['__ext_in_stock'] ) );
        if ( '1' === $value || 'yes' === $value || 'true' === $value ) {
            $data['stock_status'] = 'instock';
        } elseif ( '0' === $value || 'no' === $value || 'false' === $value ) {
            $data['stock_status'] = 'outofstock';
        }
        return $data;
    }

    /**
     * Translate "Backorders allowed?" enum to TejCart's no/notify/yes.
     */
    private static function translate_backorders( array $data ): array {
        if ( ! array_key_exists( 'backorders', $data ) ) {
            return $data;
        }
        $value = strtolower( trim( (string) $data['backorders'] ) );
        switch ( $value ) {
            case '1':
            case 'yes':
                $data['backorders'] = 'yes';
                break;
            case 'notify':
                $data['backorders'] = 'notify';
                break;
            case '0':
            case 'no':
            case '':
                $data['backorders'] = 'no';
                break;
            default:
                $data['backorders'] = 'no';
        }
        return $data;
    }

    /**
     * Normalise 1/0/yes/no boolean string columns to the 1/0 form that
     * Product_Import_Export's row handler already accepts.
     */
    private static function translate_boolean_flags( array $data ): array {
        $bool_keys = array( 'featured', 'sold_individually' );
        foreach ( $bool_keys as $key ) {
            if ( ! array_key_exists( $key, $data ) ) {
                continue;
            }
            $value = strtolower( trim( (string) $data[ $key ] ) );
            $data[ $key ] = in_array( $value, array( '1', 'yes', 'true' ), true ) ? '1' : '0';
        }
        return $data;
    }

    /**
     * Flatten "Parent > Child, Other > Tee" hierarchical category list into
     * a flat comma-separated leaf list. TejCart's term assignment is
     * non-hierarchical at import time and the leaf is what shoppers filter on.
     */
    private static function translate_categories( array $data ): array {
        if ( empty( $data['categories'] ) ) {
            return $data;
        }
        $parts  = array_filter( array_map( 'trim', explode( ',', (string) $data['categories'] ) ) );
        $leaves = array();
        foreach ( $parts as $part ) {
            $segments = array_map( 'trim', explode( '>', $part ) );
            $leaves[] = end( $segments );
        }
        $data['categories'] = implode( ', ', array_filter( $leaves ) );
        return $data;
    }

    /**
     * The foreign format separates SKU lists (grouped/upsells/cross-sells)
     * by commas; TejCart uses pipes. Translate.
     */
    private static function translate_separators( array $data ): array {
        $sku_list_keys = array(
            'grouped_children_skus',
            'upsell_skus',
            'cross_sell_skus',
        );
        foreach ( $sku_list_keys as $key ) {
            if ( empty( $data[ $key ] ) ) {
                continue;
            }
            $skus = array_filter( array_map( 'trim', explode( ',', (string) $data[ $key ] ) ) );
            $data[ $key ] = implode( '|', $skus );
        }
        return $data;
    }

    /**
     * Strip an "id:N" prefix from variation parent references. TejCart
     * resolves the parent by SKU, so anything starting with "id:" is reduced
     * to the numeric tail and treated as an SKU candidate; if that fails the
     * variation handler logs a row-level error, which is the right outcome.
     */
    private static function translate_parent( array $data ): array {
        if ( empty( $data['variation_parent_sku'] ) ) {
            return $data;
        }
        $value = trim( (string) $data['variation_parent_sku'] );
        if ( 0 === strncmp( $value, 'id:', 3 ) ) {
            $data['variation_parent_sku'] = trim( substr( $value, 3 ) );
        } else {
            $data['variation_parent_sku'] = $value;
        }
        return $data;
    }

    /**
     * The foreign format packs every product image into a single
     * comma-separated "Images" column with the featured image first. Split
     * into TejCart's image_url (single featured) + gallery_image_urls
     * (pipe-joined remainder).
     */
    private static function translate_images( array $data ): array {
        if ( ! array_key_exists( '__ext_images', $data ) ) {
            return $data;
        }
        $value = trim( (string) $data['__ext_images'] );
        if ( '' === $value ) {
            return $data;
        }
        $urls = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
        if ( empty( $urls ) ) {
            return $data;
        }
        if ( ! isset( $data['image_url'] ) || '' === trim( (string) $data['image_url'] ) ) {
            $data['image_url'] = array_shift( $urls );
        }
        if ( ! empty( $urls ) && empty( $data['gallery_image_urls'] ) ) {
            $data['gallery_image_urls'] = implode( '|', $urls );
        }
        return $data;
    }

    /**
     * Aggregate attribute columns ("Attribute N name" + "Attribute N value(s)")
     * into TejCart's variation_attributes JSON for variation rows. Parent rows
     * keep the attribute info available on data for downstream consumers but
     * we don't synthesise variation_attributes from them — TejCart's variable
     * parent products derive their attribute axes from the variations
     * themselves, not from CSV input.
     */
    private static function translate_attributes( array $data ): array {
        $is_variation = isset( $data['type'] ) && 'variation' === $data['type'];
        if ( ! $is_variation ) {
            return $data;
        }
        if ( ! empty( $data['variation_attributes'] ) ) {
            return $data;
        }

        $attrs = array();
        foreach ( $data as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            if ( ! preg_match( '/^__ext_attr_(\d+)_name$/', $key, $m ) ) {
                continue;
            }
            $idx     = (int) $m[1];
            $name    = trim( (string) $value );
            $val_key = '__ext_attr_' . $idx . '_value';
            $val     = isset( $data[ $val_key ] ) ? trim( (string) $data[ $val_key ] ) : '';
            if ( '' === $name || '' === $val ) {
                continue;
            }
            // For variations, "Attribute N value(s)" holds a single choice;
            // if an export accidentally pipes multiple values, take the
            // first one (it's still a single-selection axis at this row).
            if ( false !== strpos( $val, '|' ) ) {
                $val = trim( strtok( $val, '|' ) );
            }
            $attrs[ $name ] = $val;
        }

        if ( ! empty( $attrs ) ) {
            $data['variation_attributes'] = wp_json_encode( $attrs );
        }

        return $data;
    }

    /**
     * Aggregate "Download N name" + "Download N URL" repeater columns into
     * TejCart's downloadable_files_json column.
     */
    private static function translate_downloads( array $data ): array {
        if ( ! empty( $data['downloadable_files_json'] ) ) {
            return $data;
        }

        $files = array();
        foreach ( $data as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            if ( ! preg_match( '/^__ext_dl_(\d+)_name$/', $key, $m ) ) {
                continue;
            }
            $idx     = (int) $m[1];
            $name    = trim( (string) $value );
            $url_key = '__ext_dl_' . $idx . '_url';
            $url     = isset( $data[ $url_key ] ) ? trim( (string) $data[ $url_key ] ) : '';
            if ( '' === $url ) {
                continue;
            }
            $files[] = array(
                'name' => '' !== $name ? $name : $url,
                'file' => $url,
            );
        }

        if ( ! empty( $files ) ) {
            $data['downloadable_files_json'] = wp_json_encode( $files );
        }

        return $data;
    }

    /**
     * Normalise catalog visibility values. The foreign format uses the same
     * enum names as TejCart (visible/catalog/search/hidden), so this is
     * mostly defensive trimming.
     */
    private static function translate_visibility( array $data ): array {
        if ( ! array_key_exists( 'catalog_visibility', $data ) ) {
            return $data;
        }
        $value = strtolower( trim( (string) $data['catalog_visibility'] ) );
        if ( '' === $value ) {
            return $data;
        }
        $valid = array( 'visible', 'catalog', 'search', 'hidden' );
        $data['catalog_visibility'] = in_array( $value, $valid, true ) ? $value : 'visible';
        return $data;
    }
}
