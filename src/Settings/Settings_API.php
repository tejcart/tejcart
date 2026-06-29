<?php
/**
 * Settings API handler.
 *
 * @package TejCart\Settings
 */

declare( strict_types=1 );

namespace TejCart\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides methods for registering, rendering, sanitizing and
 * persisting plugin settings fields and sections.
 */
class Settings_API {
    /**
     * Registered settings fields grouped by section.
     *
     * @var array
     */
    protected $settings_fields = array();

    /**
     * Registered settings sections.
     *
     * @var array
     */
    protected $settings_sections = array();

    /**
     * Add a settings section.
     *
     * @param array $section {
     *     Section configuration.
     *
     *     @type string $id   Unique identifier.
     *     @type string $title Display title.
     *     @type string $desc  Optional description.
     * }
     * @return $this
     */
    public function add_section( $section ) {
        $this->settings_sections[] = $section;
        return $this;
    }

    /**
     * Add a field to a section.
     *
     * @param string $section Section ID the field belongs to.
     * @param array  $field   Field configuration array.
     * @return $this
     */
    public function add_field( $section, $field ) {
        $defaults = array(
            'name'        => '',
            'label'       => '',
            'desc'        => '',
            'type'        => 'text',
            'default'     => '',
            'options'     => array(),
            'placeholder' => '',
            'min'         => '',
            'max'         => '',
            'step'        => '',
            'class'       => '',
        );

        $field = wp_parse_args( $field, $defaults );

        if ( ! isset( $this->settings_fields[ $section ] ) ) {
            $this->settings_fields[ $section ] = array();
        }

        $this->settings_fields[ $section ][] = $field;
        return $this;
    }

    /**
     * Retrieve a TejCart option value.
     *
     * @param string $key     Option key (without prefix).
     * @param mixed  $default Default value when option does not exist.
     * @return mixed
     */
    public function get_option( $key, $default = '' ) {
        return get_option( 'tejcart_' . $key, $default );
    }

    /**
     * Update a TejCart option value.
     *
     * @param string $key   Option key (without prefix).
     * @param mixed  $value New value.
     * @return bool
     */
    public function update_option( $key, $value ) {
        // Autoload=false by default: every setting written through the
        // public Settings API previously autoloaded, so bulk merchant
        // configuration writes could pollute alloptions with bulky
        // values. Hot-path settings that need autoload load through
        // their own dedicated paths (Installer::create_default_options,
        // Setup_Wizard) which still set autoload=yes explicitly.
        return update_option( 'tejcart_' . $key, $value, false );
    }

    /**
     * Delete a TejCart option.
     *
     * @param string $key Option key (without prefix).
     * @return bool
     */
    public function delete_option( $key ) {
        return delete_option( 'tejcart_' . $key );
    }

    /**
     * Render all fields belonging to a section.
     *
     * @param string $section Section ID.
     * @return void
     */
    public function render_fields( $section ) {
        if ( empty( $this->settings_fields[ $section ] ) ) {
            return;
        }

        // Derive the tab id from the section id so each rendered row
        // can carry a stable `id="tejcart-field-{tab}-{name}"` anchor
        // that the Cmd-K settings palette deep-links to.
        $tab_id = preg_replace( '/^tejcart_/', '', (string) $section );

        echo '<table class="form-table tejcart-settings-table">';

        foreach ( $this->settings_fields[ $section ] as $field ) {
            $type   = isset( $field['type'] ) ? $field['type'] : 'text';
            $anchor = $this->build_row_anchor( $tab_id, isset( $field['name'] ) ? (string) $field['name'] : '' );
            $row_id = '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';

            if ( 'heading' === $type ) {
                echo '<tr class="tejcart-field-heading"' . $row_id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td colspan="2">';
                echo '<h3>' . esc_html( $field['label'] ) . '</h3>';
                if ( ! empty( $field['desc'] ) ) {
                    echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
                }
                echo '</td>';
                echo '</tr>';
                continue;
            }

            if ( 'note' === $type ) {
                $allowed_note_html = array(
                    'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
                    'br'     => array(),
                    'code'   => array(),
                    'em'     => array(),
                    'strong' => array(),
                );
                echo '<tr class="tejcart-field-note"' . $row_id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<th scope="row">' . esc_html( $field['label'] ) . '</th>';
                echo '<td><p class="description">' . wp_kses( (string) $field['desc'], $allowed_note_html ) . '</p></td>';
                echo '</tr>';
                continue;
            }

            if ( 'preview' === $type ) {
                echo '<tr class="tejcart-field-preview"' . $row_id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<th scope="row">' . esc_html( $field['label'] ) . '</th>';
                echo '<td>';
                $this->render_preview_slot( $field );
                if ( ! empty( $field['desc'] ) ) {
                    echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
                }
                echo '</td>';
                echo '</tr>';
                continue;
            }

            $value = $this->get_option( $field['name'], $field['default'] );
            $name  = 'tejcart_' . esc_attr( $field['name'] );

            echo '<tr' . $row_id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $field['label'] ) . '</label></th>';
            echo '<td>';
            $this->render_field( $field, $value, $name );
            if ( ! empty( $field['desc'] ) && 'checkbox' !== $type ) {
                echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    /**
     * Build the deep-link anchor for a settings row.
     *
     * Mirrors `Settings_Search_Index::anchor_for()` — kept here to avoid
     * Settings_API depending on the index builder at render time.
     *
     * @param string $tab_id Tab ID (e.g. "general").
     * @param string $name   Field option name without the `tejcart_` prefix.
     * @return string Anchor or empty string when no name is set.
     */
    protected function build_row_anchor( string $tab_id, string $name ): string {
        if ( '' === $tab_id || '' === $name ) {
            return '';
        }
        return 'tejcart-field-' . $tab_id . '-' . $name;
    }

    /**
     * Render the inner markup for a `preview` field row.
     *
     * A `preview` field emits a full-width, labelled container — the
     * *contents* are dispatched by the `preview` key on the field so
     * each tab can surface its own in-context sandbox. Keeping the
     * dispatch here (instead of inline in Settings_Tabs) lets the
     * markup live with the rest of the Settings_API rendering logic.
     *
     * @param array $field Field configuration.
     * @return void
     */
    protected function render_preview_slot( $field ) {
        $preview = isset( $field['preview'] ) ? $field['preview'] : '';

        switch ( $preview ) {
            case 'theme_colors':
                $this->render_theme_colors_preview();
                break;
        }
    }

    /**
     * Live-preview card for the Design → Theme colors tab.
     *
     * Emits a sandboxed sample PDP fragment (primary button, chip,
     * swatch, link, sale badge) plus an accessibility readout. The
     * live-update behavior is wired in `tejcart-admin.js` and listens
     * to `wpColorPicker` change events on `.tejcart-theme-color-input`.
     *
     * The contrast badge renders server-side with the *saved* value so
     * the page never flashes a "Fail" while JS boots. JS then re-renders
     * it on every picker change.
     */
    protected function render_theme_colors_preview() {
        $primary = sanitize_hex_color( (string) get_option( 'tejcart_theme_color_primary', '' ) ) ?: '#111827';
        $accent  = sanitize_hex_color( (string) get_option( 'tejcart_theme_color_accent', '' ) ) ?: $primary;
        $sale    = sanitize_hex_color( (string) get_option( 'tejcart_theme_color_sale', '' ) ) ?: '#d72c0d';

        $foreground = \TejCart\Frontend\Theme_Colors::readable_text_for( $primary );
        $ratio      = \TejCart\Frontend\Theme_Colors::contrast_ratio( $primary, $foreground );
        $aa_pass    = $ratio >= 4.5;

        $style  = '--preview-primary:' . esc_attr( $primary ) . ';';
        $style .= '--preview-primary-fg:' . esc_attr( $foreground ) . ';';
        $style .= '--preview-accent:' . esc_attr( $accent ) . ';';
        $style .= '--preview-sale:' . esc_attr( $sale ) . ';';
        ?>
        <div
            class="tejcart-theme-preview"
            data-tejcart-theme-preview
            style="<?php echo esc_attr( $style ); ?>"
        >
            <div class="tejcart-theme-preview__surface">
                <button type="button" class="tejcart-theme-preview__btn" data-preview-role="primary-button">
                    <?php esc_html_e( 'Add to cart', 'tejcart' ); ?>
                </button>

                <div class="tejcart-theme-preview__chips" role="group" aria-label="<?php esc_attr_e( 'Size', 'tejcart' ); ?>">
                    <span class="tejcart-theme-preview__chip">S</span>
                    <span class="tejcart-theme-preview__chip is-selected" data-preview-role="chip-selected">M</span>
                    <span class="tejcart-theme-preview__chip">L</span>
                </div>

                <div class="tejcart-theme-preview__swatches" role="group" aria-label="<?php esc_attr_e( 'Color', 'tejcart' ); ?>">
                    <span class="tejcart-theme-preview__swatch is-selected" data-preview-role="swatch-selected" style="background:#111827"></span>
                    <span class="tejcart-theme-preview__swatch" style="background:#e5e7eb"></span>
                    <span class="tejcart-theme-preview__swatch" style="background:#b91c1c"></span>
                </div>

                <p class="tejcart-theme-preview__link-row">
                    <a href="#" class="tejcart-theme-preview__link" data-preview-role="link" onclick="return false;">
                        <?php esc_html_e( 'Shipping & returns', 'tejcart' ); ?>
                    </a>
                </p>

                <p class="tejcart-theme-preview__price-row">
                    <span class="tejcart-theme-preview__price-sale" data-preview-role="sale">$49.00</span>
                    <span class="tejcart-theme-preview__price-was">$69.00</span>
                </p>
            </div>

            <div
                class="tejcart-theme-preview__a11y"
                data-tejcart-contrast-report
                data-aa-pass="<?php echo $aa_pass ? '1' : '0'; ?>"
            >
                <span class="tejcart-theme-preview__a11y-label"><?php esc_html_e( 'Button contrast', 'tejcart' ); ?></span>
                <span class="tejcart-theme-preview__a11y-ratio" data-tejcart-contrast-ratio>
                    <?php echo esc_html( number_format( $ratio, 2 ) ); ?>:1
                </span>
                <span class="tejcart-theme-preview__a11y-badge" data-tejcart-contrast-badge>
                    <?php echo $aa_pass ? esc_html__( 'AA pass', 'tejcart' ) : esc_html__( 'Below AA — may be hard to read', 'tejcart' ); ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Build a leading-space-prefixed string of `data-*` attributes from a
     * field's optional `data` map. Used by render_field() so callers can
     * tag inputs / selects for JS hooks (e.g. the country/state swapper)
     * without having to hand-roll a fully escaped attribute string.
     *
     * Returns "" when no data attributes are defined, otherwise a string
     * like ` data-foo="bar" data-baz="qux"` ready to splice into a tag.
     *
     * @param array<string,mixed> $field
     */
    private function render_field_data_attrs( $field ): string {
        if ( empty( $field['data'] ) || ! is_array( $field['data'] ) ) {
            return '';
        }

        $out = '';
        foreach ( $field['data'] as $key => $val ) {
            $key = (string) $key;
            if ( '' === $key || ! preg_match( '/^[a-z][a-z0-9-]*$/', $key ) ) {
                continue;
            }
            $out .= sprintf( ' data-%s="%s"', $key, esc_attr( (string) $val ) );
        }
        return $out;
    }

    /**
     * Render a single field based on its type.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current stored value.
     * @param string $name  HTML name attribute.
     * @return void
     */
    public function render_field( $field, $value = null, $name = null ) {
        if ( null === $value ) {
            $value = $this->get_option( $field['name'], $field['default'] );
        }
        if ( null === $name ) {
            $name = 'tejcart_' . esc_attr( $field['name'] );
        }

        $extra_class = isset( $field['class'] ) ? (string) $field['class'] : '';
        $placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
        $extra_attrs = $this->render_field_data_attrs( $field );

        switch ( $field['type'] ) {
            case 'text':
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text %4$s"%5$s />',
                    esc_attr( $name ),
                    esc_attr( $value ),
                    esc_attr( $placeholder ),
                    esc_attr( $extra_class ),
                    $extra_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_field_data_attrs()
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="%3$s" max="%4$s" step="%5$s" class="small-text %6$s"%7$s />',
                    esc_attr( $name ),
                    esc_attr( $value ),
                    esc_attr( $field['min'] ),
                    esc_attr( $field['max'] ),
                    esc_attr( $field['step'] ),
                    esc_attr( $extra_class ),
                    $extra_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_field_data_attrs()
                );
                break;

            case 'decimal':
                // Audit #8 / 03 #5 — float-typed companion of `number`
                // for money fields. Defaults step to 0.01 so the
                // browser accepts cents, and the sanitiser branch
                // below stores the value as (float) instead of (int).
                $decimal_step = '' !== (string) $field['step'] ? $field['step'] : '0.01';
                printf(
                    '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="%3$s" max="%4$s" step="%5$s" class="small-text %6$s"%7$s />',
                    esc_attr( $name ),
                    esc_attr( $value ),
                    esc_attr( $field['min'] ),
                    esc_attr( $field['max'] ),
                    esc_attr( $decimal_step ),
                    esc_attr( $extra_class ),
                    $extra_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_field_data_attrs()
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="5" placeholder="%2$s" class="regular-text tejcart-textarea %3$s"%5$s>%4$s</textarea>',
                    esc_attr( $name ),
                    esc_attr( $placeholder ),
                    esc_attr( $extra_class ),
                    esc_textarea( $value ),
                    $extra_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_field_data_attrs()
                );
                break;

            case 'select':
                printf(
                    '<select id="%1$s" name="%1$s" class="regular-text tejcart-select %2$s"%3$s>',
                    esc_attr( $name ),
                    esc_attr( $extra_class ),
                    $extra_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_field_data_attrs()
                );
                foreach ( $field['options'] as $opt_value => $opt_label ) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr( $opt_value ),
                        selected( $value, $opt_value, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf(
                    '<label class="tejcart-toggle"><input type="checkbox" id="%1$s" name="%1$s" value="yes"%2$s /><span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span><span class="tejcart-toggle-label">%3$s</span></label>',
                    esc_attr( $name ),
                    checked( $value, 'yes', false ),
                    esc_html( $field['desc'] )
                );
                break;

            case 'password':
                printf(
                    '<input type="password" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" autocomplete="off" />',
                    esc_attr( $name ),
                    esc_attr( $value ),
                    esc_attr( $placeholder )
                );
                break;

            case 'color':
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="tejcart-color-picker regular-text %3$s" />',
                    esc_attr( $name ),
                    esc_attr( $value ),
                    esc_attr( $extra_class )
                );
                break;

            case 'radio':
                echo '<div class="tejcart-radio-group">';
                foreach ( $field['options'] as $opt_value => $opt_label ) {
                    printf(
                        '<label class="tejcart-radio"><input type="radio" name="%1$s" value="%2$s"%3$s /><span>%4$s</span></label>',
                        esc_attr( $name ),
                        esc_attr( $opt_value ),
                        checked( $value, $opt_value, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</div>';
                break;

            case 'readonly':
                printf(
                    '<input type="text" id="%1$s" value="%2$s" class="regular-text" readonly onfocus="this.select();" />',
                    esc_attr( $name ),
                    esc_attr( $value )
                );
                break;

            default:
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                    esc_attr( $name ),
                    esc_attr( $value )
                );
                break;
        }
    }

    /**
     * Sanitize an array of field values based on their types.
     *
     * @param array $fields Array of field configs, each containing at least 'name' and 'type'.
     * @return array Sanitized key => value pairs.
     */
    public function sanitize( $fields ) {
        $sanitized = array();

        foreach ( $fields as $field ) {
            if ( in_array( $field['type'], array( 'readonly', 'heading', 'note', 'preview' ), true ) ) {
                continue;
            }

            $key   = $field['name'];
            $name  = 'tejcart_' . $key;
            // Nonce verified in Settings_Page::save() before this is called.
            // Sanitization happens in the type-specific switch below.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $value = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

            switch ( $field['type'] ) {
                case 'text':
                case 'password':
                    $value = sanitize_text_field( $value );
                    break;

                case 'email':
                    // Audit #49 / 03 #4 — typed sanitisation. Note
                    // sanitize_email returns '' for invalid input,
                    // which is the right shape for a downstream
                    // `is_email()` gate (no garbage round-trips).
                    $value = sanitize_email( $value );
                    break;

                case 'url':
                    $value = esc_url_raw( $value );
                    break;

                case 'textarea':
                    $value = sanitize_textarea_field( $value );
                    break;

                case 'number':
                    $value = intval( $value );
                    if ( isset( $field['min'] ) && '' !== $field['min'] ) {
                        $value = max( (int) $field['min'], $value );
                    }
                    if ( isset( $field['max'] ) && '' !== $field['max'] ) {
                        $value = min( (int) $field['max'], $value );
                    }
                    break;

                case 'decimal':
                    // Audit #8 / 03 #5 — money options must preserve
                    // cents. `(float)` rejects non-numeric strings and
                    // negative-sign abuse the same way `intval()` does
                    // for the integer branch.
                    $value = (float) $value;
                    if ( isset( $field['min'] ) && '' !== $field['min'] ) {
                        $value = max( (float) $field['min'], $value );
                    }
                    if ( isset( $field['max'] ) && '' !== $field['max'] ) {
                        $value = min( (float) $field['max'], $value );
                    }
                    break;

                case 'checkbox':
                    $value = ( 'yes' === $value ) ? 'yes' : 'no';
                    break;

                case 'select':
                case 'radio':
                    $allowed = array_map( 'strval', array_keys( $field['options'] ) );
                    $value   = in_array( (string) $value, $allowed, true ) ? $value : $field['default'];
                    break;

                case 'color':
                    $value = sanitize_hex_color( $value );
                    break;

                // Audit M-37: add missing type arms so wysiwyg/html/
                // multiselect don't fall through to sanitize_text_field
                // which strips all HTML from rich-text fields.
                case 'wysiwyg':
                case 'html':
                    $value = wp_kses_post( $value );
                    break;

                case 'multiselect':
                case 'array':
                    $value = is_array( $value )
                        ? array_map( 'sanitize_text_field', $value )
                        : array();
                    break;

                default:
                    $value = sanitize_text_field( $value );
                    break;
            }

            $sanitized[ $key ] = $value;
        }

        return $sanitized;
    }

    /**
     * Validate and save all fields for a given section.
     *
     * @param string $section Section ID whose fields are being saved.
     * @param array  $data    Optional pre-sanitized data. When empty, data is
     *                        sanitized from $_POST automatically.
     * @return bool True when all values have been persisted.
     */
    public function save( $section, $data = array() ) {
        if ( empty( $this->settings_fields[ $section ] ) ) {
            return false;
        }

        $fields = $this->settings_fields[ $section ];

        if ( empty( $data ) ) {
            $data = $this->sanitize( $fields );
        }

        foreach ( $data as $key => $value ) {
            $this->update_option( $key, $value );
        }

        return true;
    }

    /**
     * Get all registered sections.
     *
     * @return array
     */
    public function get_sections() {
        return $this->settings_sections;
    }

    /**
     * Get all registered fields.
     *
     * @return array
     */
    public function get_fields() {
        return $this->settings_fields;
    }
}
