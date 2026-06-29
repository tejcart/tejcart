<?php
/**
 * Checkout fields renderer.
 *
 * Loops through a fields array and outputs each field with a visible
 * label, proper autocomplete attribute, aria-describedby wiring for
 * error messages, and an error text container for JS-driven validation.
 *
 * @package TejCart\Templates\Checkout
 *
 * @var array $fields Associative array of field_key => field config.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $fields ) || ! is_array( $fields ) ) {
    return;
}

/**
 * Map a billing/shipping field key to the right HTML autocomplete token.
 * Correct autocomplete attributes measurably improve checkout conversion
 * because browsers can autofill the entire address in one tap.
 */
$tejcart_autocomplete_map = array(
    '_first_name' => 'given-name',
    '_last_name'  => 'family-name',
    '_email'      => 'email',
    '_phone'      => 'tel',
    '_company'    => 'organization',
    '_address_1'  => 'address-line1',
    '_address_2'  => 'address-line2',
    '_city'       => 'address-level2',
    '_state'      => 'address-level1',
    '_postcode'   => 'postal-code',
    '_country'    => 'country',
);

$tejcart_section = null;

foreach ( $fields as $field_key => $field ) :

    $type        = isset( $field['type'] ) ? $field['type'] : 'text';
    $label       = isset( $field['label'] ) ? $field['label'] : '';
    $required    = ! empty( $field['required'] );
    $classes     = isset( $field['class'] ) ? (array) $field['class'] : array();
    $value       = isset( $field['value'] ) ? $field['value'] : ( isset( $field['default'] ) ? $field['default'] : '' );
    $options     = isset( $field['options'] ) ? (array) $field['options'] : array();
    $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
    $help_text   = isset( $field['description'] ) ? $field['description'] : '';

    $is_state_field = ( 'state' === $type );
    if ( $is_state_field ) {
        $type = ! empty( $options ) ? 'select' : 'text';
    }

    if ( null === $tejcart_section ) {
        if ( 0 === strpos( (string) $field_key, 'shipping_' ) ) {
            $tejcart_section = 'shipping';
        } elseif ( 0 === strpos( (string) $field_key, 'billing_' ) ) {
            $tejcart_section = 'billing';
        }
    }

    $autocomplete = '';
    foreach ( $tejcart_autocomplete_map as $suffix => $token ) {
        if ( substr( (string) $field_key, -strlen( $suffix ) ) === $suffix ) {
            $section_token = 'shipping' === $tejcart_section ? 'shipping ' : ( 'billing' === $tejcart_section ? 'billing ' : '' );

            $needs_section_prefix = in_array( $token, array( 'address-line1', 'address-line2', 'address-level1', 'address-level2', 'postal-code', 'country' ), true );
            $autocomplete = $needs_section_prefix && $section_token ? $section_token . $token : $token;
            break;
        }
    }

    $classes[] = 'tejcart-form-row';
    $classes[] = 'tejcart-field';

    $tejcart_half_suffixes = array( '_first_name', '_last_name', '_city', '_state' );
    foreach ( $tejcart_half_suffixes as $tejcart_half_suffix ) {
        if ( substr( (string) $field_key, -strlen( $tejcart_half_suffix ) ) === $tejcart_half_suffix ) {
            $classes[] = 'tejcart-form-row--half';
            break;
        }
    }

    $wrapper_class = implode( ' ', array_map( 'esc_attr', $classes ) );

    $input_id       = esc_attr( $field_key );
    $error_id       = esc_attr( $field_key . '_error' );
    $help_id        = esc_attr( $field_key . '_help' );
    $described_by   = array();
    if ( $help_text ) {
        $described_by[] = $help_id;
    }
    $described_by[] = $error_id;

    // Server-side validation errors arrive on `$field['error']` after a
    // failed POST. Render them into the error span at template time so
    // screen readers announce them immediately, and toggle aria-invalid
    // on the input so AT can pick it up via the standard HTML5 hook.
    $server_error      = isset( $field['error'] ) ? (string) $field['error'] : '';
    $aria_invalid_attr = '' !== $server_error ? ' aria-invalid="true"' : '';

    /**
     * Filters the checkout field HTML before rendering.
     *
     * Return a non-empty string to override the default output.
     *
     * @param string $html      Custom HTML (empty by default).
     * @param string $field_key The field key.
     * @param array  $field     The field configuration.
     */
    $custom_html = apply_filters( 'tejcart_checkout_field_html', '', $field_key, $field );

    if ( ! empty( $custom_html ) ) {
        echo wp_kses_post( $custom_html );
        continue;
    }
    ?>

    <?php if ( 'checkbox' === $type ) : ?>

        <div
            class="<?php echo esc_attr( $wrapper_class ); ?> tejcart-form-row-checkbox"
            id="<?php echo esc_attr( $field_key ); ?>_field"
        >
            <label class="tejcart-field-checkbox" for="<?php echo esc_attr( $input_id ); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr( $input_id ); ?>"
                    name="<?php echo esc_attr( $field_key ); ?>"
                    class="tejcart-input-checkbox tejcart-field-checkbox-input"
                    value="1"
                    aria-describedby="<?php echo esc_attr( implode( ' ', $described_by ) ); ?>"
                    <?php checked( ! empty( $value ) ); ?>
                    <?php echo $required ? 'required aria-required="true"' : ''; ?>
                    <?php echo $aria_invalid_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                />
                <span class="tejcart-field-checkbox-label">
                    <?php echo wp_kses( $label, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array(), 'em' => array(), 'br' => array() ) ); ?>
                    <?php if ( $required ) : ?>
                        <abbr class="tejcart-field-required tejcart-required" title="<?php esc_attr_e( 'required', 'tejcart' ); ?>">*</abbr>
                    <?php endif; ?>
                </span>
            </label>

            <?php if ( $help_text ) : ?>
                <span class="tejcart-field-help-text" id="<?php echo esc_attr( $help_id ); ?>">
                    <?php echo esc_html( $help_text ); ?>
                </span>
            <?php endif; ?>

            <span
                class="tejcart-field-error-text tejcart-field-error"
                id="<?php echo esc_attr( $error_id ); ?>"
                role="alert"
                aria-live="polite"
            ><?php echo esc_html( $server_error ); ?></span>
        </div>

    <?php else : ?>

        <div
            class="<?php echo esc_attr( $wrapper_class ); ?>"
            id="<?php echo esc_attr( $field_key ); ?>_field"
            <?php if ( $is_state_field ) : ?>data-tejcart-state-field="<?php echo esc_attr( $field_key ); ?>"<?php endif; ?>
            <?php if ( 'country' === substr( $field_key, -strlen( 'country' ) ) ) : ?>data-tejcart-country-field="<?php echo esc_attr( $field_key ); ?>"<?php endif; ?>
            <?php if ( ! empty( $field['from_saved_address'] ) ) : ?>data-tejcart-default-source="saved-address"<?php endif; ?>
        >

            <label class="tejcart-field-label" for="<?php echo esc_attr( $input_id ); ?>">
                <?php echo esc_html( $label ); ?>
                <?php if ( $required ) : ?>
                    <abbr class="tejcart-field-required tejcart-required" title="<?php esc_attr_e( 'required', 'tejcart' ); ?>">*</abbr>
                <?php endif; ?>
            </label>

            <div class="tejcart-field-input-wrap">
                <?php if ( 'textarea' === $type ) : ?>

                    <textarea
                        id="<?php echo esc_attr( $input_id ); ?>"
                        name="<?php echo esc_attr( $field_key ); ?>"
                        class="tejcart-input tejcart-input-textarea tejcart-field-textarea"
                        rows="4"
                        aria-describedby="<?php echo esc_attr( implode( ' ', $described_by ) ); ?>"
                        <?php echo $required ? 'required aria-required="true"' : ''; ?>
                        <?php if ( $autocomplete ) : ?>autocomplete="<?php echo esc_attr( $autocomplete ); ?>"<?php endif; ?>
                        <?php echo $aria_invalid_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    ><?php echo esc_textarea( $value ); ?></textarea>

                <?php elseif ( 'select' === $type ) :

                    $tejcart_select_placeholder = sprintf(
                        /* translators: %s: lowercased field label (e.g. "country", "state"). */
                        __( 'Select %s', 'tejcart' ),
                        strtolower( $label )
                    );
                    // The state placeholder deliberately stays short and
                    // country-agnostic ("Select state / province"). Embedding
                    // the selected country label here produced strings like
                    // "Select United States (US) state / province" that
                    // overflowed the field and read like the country had
                    // leaked into the state box.
                    if ( $is_state_field ) {
                        $tejcart_select_placeholder = __( 'Select state / province', 'tejcart' );
                    }
                    ?>

                    <select
                        id="<?php echo esc_attr( $input_id ); ?>"
                        name="<?php echo esc_attr( $field_key ); ?>"
                        class="tejcart-input tejcart-input-select tejcart-field-select tejcart-field-input"
                        aria-describedby="<?php echo esc_attr( implode( ' ', $described_by ) ); ?>"
                        <?php echo $required ? 'required aria-required="true"' : ''; ?>
                        <?php if ( $autocomplete ) : ?>autocomplete="<?php echo esc_attr( $autocomplete ); ?>"<?php endif; ?>
                        <?php echo $aria_invalid_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    >
                        <option value=""><?php echo esc_html( $tejcart_select_placeholder ); ?></option>
                        <?php foreach ( $options as $option_value => $option_label ) : ?>
                            <option
                                value="<?php echo esc_attr( $option_value ); ?>"
                                <?php selected( $value, $option_value ); ?>
                            ><?php echo esc_html( $option_label ); ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php else : ?>

                    <input
                        type="<?php echo esc_attr( $type ); ?>"
                        id="<?php echo esc_attr( $input_id ); ?>"
                        name="<?php echo esc_attr( $field_key ); ?>"
                        class="tejcart-input tejcart-input-<?php echo esc_attr( $type ); ?> tejcart-field-input"
                        value="<?php echo esc_attr( $value ); ?>"
                        aria-describedby="<?php echo esc_attr( implode( ' ', $described_by ) ); ?>"
                        <?php echo $required ? 'required aria-required="true"' : ''; ?>
                        <?php if ( $autocomplete ) : ?>autocomplete="<?php echo esc_attr( $autocomplete ); ?>"<?php endif; ?>
                        <?php echo $aria_invalid_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    />

                <?php endif; ?>
            </div>

            <?php if ( $help_text ) : ?>
                <span class="tejcart-field-help-text" id="<?php echo esc_attr( $help_id ); ?>">
                    <?php echo esc_html( $help_text ); ?>
                </span>
            <?php endif; ?>

            <?php if ( $is_state_field ) : ?>
                <?php  ?>
                <span class="tejcart-field-help-text tejcart-field-help-text--state-hint">
                    <?php esc_html_e( 'Select a country first to populate this list.', 'tejcart' ); ?>
                </span>
            <?php endif; ?>

            <span
                class="tejcart-field-error-text tejcart-field-error"
                id="<?php echo esc_attr( $error_id ); ?>"
                role="alert"
                aria-live="polite"
            ><?php echo esc_html( $server_error ); ?></span>

        </div>

    <?php endif; ?>

<?php endforeach; ?>
