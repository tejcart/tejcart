<?php
/**
 * Admin attribute-term editor for swatch configuration.
 *
 * Hooks into the WordPress term add/edit screens for global product
 * attribute taxonomies (pa_*) to let merchants choose a swatch type
 * (color, image, or label) and supply the corresponding value.
 *
 * @package TejCart\Variation_Swatches\Admin
 */

declare(strict_types=1);

namespace TejCart\Variation_Swatches\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Variation_Swatches\Variation_Swatches;
use TejCart\Product\Global_Attributes;

/**
 * Renders swatch-type and swatch-value fields on attribute term screens
 * and persists them as term meta.
 */
class Attribute_Editor {

    /**
     * Nonce action for swatch meta saves.
     */
    private const NONCE_ACTION = 'tejcart_save_swatch_meta';

    /**
     * Nonce field name.
     */
    private const NONCE_FIELD = '_tejcart_swatch_nonce';

    /**
     * Register hooks for every registered pa_* taxonomy.
     */
    public function init(): void {
        // Hook into each registered global attribute taxonomy.
        add_action( 'admin_init', array( $this, 'register_term_hooks' ) );
    }

    /**
     * Attach add/edit form hooks to every pa_* taxonomy.
     */
    public function register_term_hooks(): void {
        $attributes = class_exists( Global_Attributes::class )
            ? Global_Attributes::get_attributes()
            : array();

        foreach ( $attributes as $attr ) {
            $taxonomy = Global_Attributes::TAXONOMY_PREFIX . $attr['slug'];

            // "Add new term" form — fields below the default inputs.
            add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_fields' ), 10 );

            // "Edit term" form — fields in the term edit table.
            add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_fields' ), 10, 2 );

            // Save hooks.
            add_action( "created_{$taxonomy}", array( $this, 'save_term_meta' ), 10 );
            add_action( "edited_{$taxonomy}", array( $this, 'save_term_meta' ), 10 );

            // Add swatch preview column to the term list table.
            add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_swatch_column' ) );
            add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_swatch_column' ), 10, 3 );
        }
    }

    /**
     * Render swatch fields on the "Add new term" form.
     *
     * @param string $taxonomy Taxonomy slug.
     */
    public function render_add_fields( string $taxonomy ): void {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <div class="form-field tejcart-swatch-fields">
            <label><?php esc_html_e( 'Swatch Type', 'tejcart' ); ?></label>
            <?php $this->render_type_selector( '' ); ?>
        </div>

        <div class="form-field tejcart-swatch-value-field tejcart-swatch-color-field" style="display:none;">
            <label><?php esc_html_e( 'Swatch Color', 'tejcart' ); ?></label>
            <input type="text" name="tejcart_swatch_value_color" value="" class="tejcart-color-picker" />
            <p class="description"><?php esc_html_e( 'Pick a color for this attribute swatch.', 'tejcart' ); ?></p>
        </div>

        <div class="form-field tejcart-swatch-value-field tejcart-swatch-image-field" style="display:none;">
            <label><?php esc_html_e( 'Swatch Image', 'tejcart' ); ?></label>
            <?php $this->render_image_picker( 0 ); ?>
            <p class="description"><?php esc_html_e( 'Select an image from the media library.', 'tejcart' ); ?></p>
        </div>

        <div class="form-field tejcart-swatch-value-field tejcart-swatch-label-field" style="display:none;">
            <label><?php esc_html_e( 'Swatch Label', 'tejcart' ); ?></label>
            <input type="text" name="tejcart_swatch_value_label" value="" />
            <p class="description"><?php esc_html_e( 'Short text label for the button swatch (e.g. "XL", "36").', 'tejcart' ); ?></p>
        </div>

        <?php $this->render_inline_script(); ?>
        <?php
    }

    /**
     * Render swatch fields on the "Edit term" form.
     *
     * @param \WP_Term $term     Term being edited.
     * @param string   $taxonomy Taxonomy slug.
     */
    public function render_edit_fields( $term, string $taxonomy ): void {
        $swatch = Variation_Swatches::get_term_swatch( (int) $term->term_id );

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <tr class="form-field tejcart-swatch-fields">
            <th scope="row"><label><?php esc_html_e( 'Swatch Type', 'tejcart' ); ?></label></th>
            <td>
                <?php $this->render_type_selector( $swatch['type'] ); ?>
                <p class="description"><?php esc_html_e( 'Choose how this attribute value is displayed on the storefront.', 'tejcart' ); ?></p>
            </td>
        </tr>

        <tr class="form-field tejcart-swatch-value-field tejcart-swatch-color-field"<?php echo 'color' !== $swatch['type'] ? ' style="display:none;"' : ''; ?>>
            <th scope="row"><label><?php esc_html_e( 'Swatch Color', 'tejcart' ); ?></label></th>
            <td>
                <input type="text" name="tejcart_swatch_value_color" value="<?php echo esc_attr( 'color' === $swatch['type'] ? $swatch['value'] : '' ); ?>" class="tejcart-color-picker" />
                <p class="description"><?php esc_html_e( 'Pick a color for this attribute swatch.', 'tejcart' ); ?></p>
            </td>
        </tr>

        <tr class="form-field tejcart-swatch-value-field tejcart-swatch-image-field"<?php echo 'image' !== $swatch['type'] ? ' style="display:none;"' : ''; ?>>
            <th scope="row"><label><?php esc_html_e( 'Swatch Image', 'tejcart' ); ?></label></th>
            <td>
                <?php $this->render_image_picker( 'image' === $swatch['type'] ? (int) $swatch['value'] : 0 ); ?>
                <p class="description"><?php esc_html_e( 'Select an image from the media library.', 'tejcart' ); ?></p>
            </td>
        </tr>

        <tr class="form-field tejcart-swatch-value-field tejcart-swatch-label-field"<?php echo 'label' !== $swatch['type'] ? ' style="display:none;"' : ''; ?>>
            <th scope="row"><label><?php esc_html_e( 'Swatch Label', 'tejcart' ); ?></label></th>
            <td>
                <input type="text" name="tejcart_swatch_value_label" value="<?php echo esc_attr( 'label' === $swatch['type'] ? $swatch['value'] : '' ); ?>" />
                <p class="description"><?php esc_html_e( 'Short text label for the button swatch (e.g. "XL", "36").', 'tejcart' ); ?></p>
            </td>
        </tr>

        <?php $this->render_inline_script(); ?>
        <?php
    }

    /**
     * Persist swatch type and value on term create/edit.
     *
     * @param int $term_id Term ID.
     */
    public function save_term_meta( int $term_id ): void {
        if ( ! isset( $_POST[ self::NONCE_FIELD ] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
        ) {
            return;
        }

        if ( ! current_user_can( 'manage_tejcart' ) ) {
            return;
        }

        $type = isset( $_POST['tejcart_swatch_type'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_swatch_type'] ) )
            : '';

        if ( '' === $type ) {
            delete_term_meta( $term_id, Variation_Swatches::META_TYPE );
            delete_term_meta( $term_id, Variation_Swatches::META_VALUE );
            return;
        }

        if ( ! in_array( $type, Variation_Swatches::TYPES, true ) ) {
            return;
        }

        $value = '';
        switch ( $type ) {
            case Variation_Swatches::TYPE_COLOR:
                $raw = isset( $_POST['tejcart_swatch_value_color'] )
                    ? sanitize_text_field( wp_unslash( $_POST['tejcart_swatch_value_color'] ) )
                    : '';
                // Validate hex colour.
                if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $raw ) ) {
                    $value = $raw;
                }
                break;

            case Variation_Swatches::TYPE_IMAGE:
                $value = isset( $_POST['tejcart_swatch_value_image'] )
                    ? (string) absint( wp_unslash( $_POST['tejcart_swatch_value_image'] ) )
                    : '';
                // Must be a real attachment.
                if ( '0' === $value || '' === $value ) {
                    $value = '';
                }
                break;

            case Variation_Swatches::TYPE_LABEL:
                $value = isset( $_POST['tejcart_swatch_value_label'] )
                    ? sanitize_text_field( wp_unslash( $_POST['tejcart_swatch_value_label'] ) )
                    : '';
                break;
        }

        update_term_meta( $term_id, Variation_Swatches::META_TYPE, $type );
        update_term_meta( $term_id, Variation_Swatches::META_VALUE, $value );
    }

    /**
     * Add a "Swatch" column to the term list table.
     *
     * @param array<string, string> $columns Existing columns.
     * @return array<string, string>
     */
    public function add_swatch_column( array $columns ): array {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'name' === $key ) {
                $new['tejcart_swatch'] = esc_html__( 'Swatch', 'tejcart' );
            }
        }
        return $new;
    }

    /**
     * Render the swatch preview in the term list table column.
     *
     * @param string $content    Existing column content.
     * @param string $column     Column name.
     * @param int    $term_id    Term ID.
     * @return string
     */
    public function render_swatch_column( string $content, string $column, int $term_id ): string {
        if ( 'tejcart_swatch' !== $column ) {
            return $content;
        }

        $swatch = Variation_Swatches::get_term_swatch( $term_id );
        if ( '' === $swatch['type'] || '' === $swatch['value'] ) {
            return '&mdash;';
        }

        switch ( $swatch['type'] ) {
            case Variation_Swatches::TYPE_COLOR:
                return sprintf(
                    '<span style="display:inline-block;width:28px;height:28px;border-radius:4px;border:1px solid #ccc;background:%s;" title="%s"></span>',
                    esc_attr( $swatch['value'] ),
                    esc_attr( $swatch['value'] )
                );

            case Variation_Swatches::TYPE_IMAGE:
                $url = wp_get_attachment_image_url( (int) $swatch['value'], 'thumbnail' );
                if ( $url ) {
                    return sprintf(
                        '<img src="%s" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:4px;border:1px solid #ccc;" />',
                        esc_url( $url )
                    );
                }
                return '&mdash;';

            case Variation_Swatches::TYPE_LABEL:
                return sprintf(
                    '<span style="display:inline-block;padding:2px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;">%s</span>',
                    esc_html( $swatch['value'] )
                );

            default:
                return '&mdash;';
        }
    }

    /**
     * Render the swatch type <select>.
     *
     * @param string $selected Currently selected type.
     */
    private function render_type_selector( string $selected ): void {
        ?>
        <select name="tejcart_swatch_type" id="tejcart-swatch-type">
            <option value=""><?php esc_html_e( 'Default (dropdown)', 'tejcart' ); ?></option>
            <option value="color"<?php selected( $selected, 'color' ); ?>><?php esc_html_e( 'Color', 'tejcart' ); ?></option>
            <option value="image"<?php selected( $selected, 'image' ); ?>><?php esc_html_e( 'Image', 'tejcart' ); ?></option>
            <option value="label"<?php selected( $selected, 'label' ); ?>><?php esc_html_e( 'Label / Button', 'tejcart' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render the image picker (hidden input + preview + buttons).
     *
     * @param int $attachment_id Current attachment ID (0 if none).
     */
    private function render_image_picker( int $attachment_id ): void {
        $url = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
        ?>
        <div class="tejcart-swatch-image-picker">
            <input type="hidden" name="tejcart_swatch_value_image" value="<?php echo esc_attr( (string) $attachment_id ); ?>" class="tejcart-swatch-image-id" />
            <div class="tejcart-swatch-image-preview" style="margin-bottom:8px;">
                <?php if ( $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #ccc;" />
                <?php endif; ?>
            </div>
            <button type="button" class="button tejcart-swatch-upload-btn"><?php esc_html_e( 'Select Image', 'tejcart' ); ?></button>
            <button type="button" class="button tejcart-swatch-remove-btn"<?php echo $attachment_id < 1 ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'tejcart' ); ?></button>
        </div>
        <?php
    }

    /**
     * Render the inline JavaScript for toggling swatch value fields
     * and driving the media uploader.
     */
    private function render_inline_script(): void {
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $rendered = true;
        ?>
        <script>
        (function(){
            'use strict';

            /* Toggle value fields based on type selector. */
            var typeSelect = document.getElementById('tejcart-swatch-type');
            if (!typeSelect) return;

            function toggleFields() {
                var val = typeSelect.value;
                document.querySelectorAll('.tejcart-swatch-value-field').forEach(function(el){
                    el.style.display = 'none';
                });
                if (val === 'color')  document.querySelectorAll('.tejcart-swatch-color-field').forEach(function(el){ el.style.display = ''; });
                if (val === 'image')  document.querySelectorAll('.tejcart-swatch-image-field').forEach(function(el){ el.style.display = ''; });
                if (val === 'label')  document.querySelectorAll('.tejcart-swatch-label-field').forEach(function(el){ el.style.display = ''; });
            }
            typeSelect.addEventListener('change', toggleFields);

            /* Initialise WP color picker. */
            if (typeof jQuery !== 'undefined' && jQuery.fn.wpColorPicker) {
                jQuery('.tejcart-color-picker').wpColorPicker();
            }

            /* Media uploader for image swatches. */
            document.querySelectorAll('.tejcart-swatch-upload-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    var container = btn.closest('.tejcart-swatch-image-picker');
                    if (!container) return;

                    var frame = wp.media({
                        title: '<?php echo esc_js( __( 'Select Swatch Image', 'tejcart' ) ); ?>',
                        button: { text: '<?php echo esc_js( __( 'Use Image', 'tejcart' ) ); ?>' },
                        multiple: false,
                        library: { type: 'image' }
                    });

                    frame.on('select', function(){
                        var attachment = frame.state().get('selection').first().toJSON();
                        var thumbUrl   = (attachment.sizes && attachment.sizes.thumbnail)
                            ? attachment.sizes.thumbnail.url
                            : attachment.url;

                        container.querySelector('.tejcart-swatch-image-id').value = attachment.id;
                        container.querySelector('.tejcart-swatch-image-preview').innerHTML =
                            '<img src="' + thumbUrl + '" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #ccc;" />';
                        container.querySelector('.tejcart-swatch-remove-btn').style.display = '';
                    });

                    frame.open();
                });
            });

            /* Remove image. */
            document.querySelectorAll('.tejcart-swatch-remove-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    var container = btn.closest('.tejcart-swatch-image-picker');
                    if (!container) return;
                    container.querySelector('.tejcart-swatch-image-id').value = '0';
                    container.querySelector('.tejcart-swatch-image-preview').innerHTML = '';
                    btn.style.display = 'none';
                });
            });
        })();
        </script>
        <?php
    }
}
