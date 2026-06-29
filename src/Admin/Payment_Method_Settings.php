<?php
/**
 * Individual Payment Method Settings Page
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Gateways\Gateway_Registry;
use TejCart\Gateways\PayPal\PayPal_Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the settings page for a specific payment gateway.
 * Handles field rendering and settings persistence.
 */
class Payment_Method_Settings {
    /**
     * Gateway registry instance.
     *
     * @var Gateway_Registry
     */
    private Gateway_Registry $registry;

    /**
     * Constructor.
     *
     * @param Gateway_Registry|null $registry Gateway registry instance.
     */
    public function __construct( ?Gateway_Registry $registry = null ) {
        $this->registry = $registry ?: tejcart()->gateways();
    }

    /**
     * Render the payment method settings page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage payment methods.', 'tejcart' ) );
        }

        $gateway_id = $this->get_current_gateway_id();

        if ( ! $gateway_id ) {
            $this->render_error( __( 'Invalid payment method.', 'tejcart' ) );
            return;
        }

        $gateway = $this->registry->get_gateway( $gateway_id );

        if ( ! $gateway ) {
            $this->render_error( __( 'Payment method not found.', 'tejcart' ) );
            return;
        }

        if ( ! $gateway->is_admin_visible() ) {
            $this->render_error(
                __(
                    'This payment method is not yet available. Complete PayPal seller onboarding first to configure it.',
                    'tejcart'
                )
            );
            return;
        }

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        // Existence check only; save() performs the actual nonce verification.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 'POST' === $request_method && isset( $_POST['tejcart_payment_method_nonce'] ) ) {
            $this->save( $gateway );
        }

        $this->render_settings_form( $gateway );
    }

    /**
     * Save submitted gateway settings.
     *
     * @param Abstract_Gateway $gateway Gateway instance.
     * @return void
     */
    private function save( Abstract_Gateway $gateway ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_payment_method_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_payment_method_nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'tejcart_payment_method_save_' . $gateway->get_id() ) ) {
            add_settings_error(
                'tejcart_payment_methods',
                'tejcart_nonce_error',
                __( 'Security check failed. Please try again.', 'tejcart' ),
                'error'
            );
            return;
        }

        $form_fields = $gateway->get_form_fields();

        foreach ( $form_fields as $field_id => $field ) {
            $field_type = $field['type'] ?? 'text';

            if ( in_array( $field_type, array( 'heading', 'note', 'readonly', 'connection' ), true ) ) {
                continue;
            }

            $field_name = $this->get_field_name( $gateway->get_id(), $field_id );

            // Nonce verified above. Sanitization is delegated to sanitize_field_value()
            // because each field type has its own coercion (bool, int, textarea, key…).
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_value = isset( $_POST[ $field_name ] ) ? wp_unslash( $_POST[ $field_name ] ) : null;

            $sanitized = $this->sanitize_field_value( $field_type, $raw_value );

            // M-01: blank submission for a password field means
            // "keep current value" — the field is rendered with an
            // empty value="" attribute so the secret never appears
            // in the DOM. Wiping the stored value here would let an
            // operator destroy their gateway credentials by clicking
            // Save without retyping them.
            if ( 'password' === $field_type && '' === $sanitized ) {
                continue;
            }

            $gateway->update_option( $field_id, $sanitized );
        }

        $gateway->save_settings();

        add_settings_error(
            'tejcart_payment_methods',
            'tejcart_settings_saved',
            __( 'Payment method settings saved successfully.', 'tejcart' ),
            'updated'
        );
    }

    /**
     * Sanitize a single field value based on its type.
     *
     * @param string $type      Field type.
     * @param mixed  $raw_value Raw value from $_POST (already unslashed) or null.
     * @return string
     */
    public function sanitize_field_value( string $type, $raw_value ): string {
        switch ( $type ) {
            case 'checkbox':
                if ( null === $raw_value ) {
                    return 'no';
                }
                return ( 'yes' === $raw_value || '1' === $raw_value || 'on' === $raw_value ) ? 'yes' : 'no';

            case 'textarea':
                return is_scalar( $raw_value ) ? sanitize_textarea_field( (string) $raw_value ) : '';

            case 'multicheck':

                if ( ! is_array( $raw_value ) ) {
                    return '';
                }
                $clean = array();
                foreach ( $raw_value as $slug ) {
                    if ( ! is_scalar( $slug ) ) {
                        continue;
                    }
                    $slug = strtolower( sanitize_text_field( (string) $slug ) );
                    if ( '' === $slug || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
                        continue;
                    }
                    $clean[ $slug ] = true;
                }
                return implode( ',', array_keys( $clean ) );

            case 'password':
            case 'text':
            case 'select':
            case 'segmented':
            case 'number':
            default:
                return is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
        }
    }

    /**
     * Render the settings form for a gateway.
     *
     * @param Abstract_Gateway $gateway Payment gateway instance.
     * @return void
     */
    private function render_settings_form( Abstract_Gateway $gateway ): void {
        $gateway_id  = $gateway->get_id();
        $form_fields = $gateway->get_form_fields();
        $back_url    = Payment_Methods_List::get_list_url();
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-settings nxc-gateway-config">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php echo esc_html( $gateway->get_title() ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Configure this payment method.', 'tejcart' ); ?></p>
                </div>
            </div>

            <?php settings_errors( 'tejcart_payment_methods' ); ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['tejcart_paypal_connected'] ) ) {
                \TejCart\Admin\Flash_Notice::render(
                    __( 'PayPal is now connected.', 'tejcart' ),
                    __( 'Additional payment methods (Card, Google Pay, Apple Pay, Fastlane) are now available in Settings → Payments.', 'tejcart' ),
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            }
            ?>

            <p>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button">
                    &larr; <?php esc_html_e( 'Back to Payment Methods', 'tejcart' ); ?>
                </a>
            </p>

            <?php if ( empty( $form_fields ) ) : ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e( 'This payment method has no configurable settings.', 'tejcart' ); ?></p>
                </div>
            <?php else : ?>
                <form method="post" action="" class="tejcart-settings-form">
                    <?php wp_nonce_field( 'tejcart_payment_method_save_' . $gateway_id, 'tejcart_payment_method_nonce' ); ?>

                    <table class="form-table tejcart-settings-table" role="presentation">
                        <?php

                        $collapse_group = '';
                        $collapse_open  = false;

                        foreach ( $form_fields as $field_id => $field ) {
                            $type = $field['type'] ?? 'text';

                            if ( 'heading' === $type ) {
                                if ( ! empty( $field['collapsible'] ) ) {
                                    $collapse_group = sanitize_html_class( 'group-' . $field_id );
                                    $collapse_open  = empty( $field['collapsed'] );
                                } else {
                                    $collapse_group = '';
                                    $collapse_open  = false;
                                }
                            }

                            $this->render_field_row( $gateway, $field_id, $field, $collapse_group, $collapse_open );
                        }
                        ?>
                    </table>

                    <?php submit_button( __( 'Save Changes', 'tejcart' ) ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single field row in the settings table.
     *
     * @param Abstract_Gateway $gateway        Gateway instance.
     * @param string           $field_id       Field ID.
     * @param array            $field          Field configuration.
     * @param string           $collapse_group CSS class of the open collapsible group, or ''.
     * @param bool             $collapse_open  Whether the current collapsible group starts expanded.
     * @return void
     */
    private function render_field_row(
        Abstract_Gateway $gateway,
        string $field_id,
        array $field,
        string $collapse_group = '',
        bool $collapse_open = false
    ): void {
        $type = $field['type'] ?? 'text';

        if ( 'heading' === $type ) {
            $is_collapsible = ! empty( $field['collapsible'] );
            $is_collapsed   = $is_collapsible && ! empty( $field['collapsed'] );
            $classes        = 'tejcart-field-heading';
            if ( $is_collapsible ) {
                $classes .= ' is-collapsible';
                if ( $is_collapsed ) {
                    $classes .= ' is-collapsed';
                }
            }
            echo '<tr class="' . esc_attr( $classes ) . '"';
            if ( $is_collapsible ) {
                echo ' data-collapse-target="' . esc_attr( sanitize_html_class( 'group-' . $field_id ) ) . '"';
            }
            echo '>';
            echo '<td colspan="2">';
            if ( $is_collapsible ) {
                echo '<button type="button" class="tejcart-collapse-toggle" aria-expanded="' . ( $is_collapsed ? 'false' : 'true' ) . '">';
                echo '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
                echo '<span class="tejcart-collapse-toggle-label">' . esc_html( $field['title'] ?? '' ) . '</span>';
                echo '</button>';
            } else {
                echo '<h3>' . esc_html( $field['title'] ?? '' ) . '</h3>';
            }
            if ( ! empty( $field['description'] ) ) {
                echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
            }
            echo '</td>';
            echo '</tr>';
            return;
        }

        if ( 'connection' === $type ) {
            echo '<tr class="tejcart-field-connection">';
            echo '<td colspan="2">';
            $this->render_paypal_connection_card( $gateway );
            echo '</td>';
            echo '</tr>';
            return;
        }

        if ( 'note' === $type ) {
            echo '<tr class="tejcart-field-note">';
            echo '<th scope="row">' . esc_html( $field['title'] ?? '' ) . '</th>';
            echo '<td><p class="description">' . wp_kses_post( $field['description'] ?? '' ) . '</p></td>';
            echo '</tr>';
            return;
        }

        $field_name = $this->get_field_name( $gateway->get_id(), $field_id );
        $value      = $gateway->get_option( $field_id, $field['default'] ?? '' );
        $label      = $field['title'] ?? '';
        $desc       = $field['description'] ?? '';

        $row_classes = array();
        $row_attrs   = '';
        if ( '' !== $collapse_group ) {
            $row_classes[] = 'tejcart-collapsible-row';
            $row_classes[] = 'tejcart-collapse-member';
            $row_attrs    .= ' data-collapse-group="' . esc_attr( $collapse_group ) . '"';
            if ( ! $collapse_open ) {
                $row_classes[] = 'is-hidden';
            }
        }

        echo '<tr' . ( $row_classes ? ' class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' : '' ) . $row_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- classes and attrs pre-escaped
        echo '<th scope="row"><label for="' . esc_attr( $field_name ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td>';
        $this->render_field_input( $type, $field_name, $value, $field );
        if ( ! empty( $desc ) ) {
            echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
        }
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Render the "Connect with PayPal" control cell content.
     *
     * Two-state rendering:
     *
     *   Disconnected: outputs a "Click to Connect PayPal" anchor (wired up
     *   to PayPal's partner.js MiniBrowser SDK via data-paypal-button),
     *   a Recommended pill, an OR divider, and a link that expands the
     *   Manual credentials collapsible section below.
     *
     *   Connected: outputs a framed "PayPal Account Connected" status panel
     *   showing the email the admin used at onboarding time, followed by a
     *   "Disconnect PayPal Account" outline button.
     *
     * @param Abstract_Gateway $gateway Gateway instance (expected: PayPal_Gateway).
     * @return void
     */
    public function render_paypal_connection_card( Abstract_Gateway $gateway ): void {
        $onboarding   = PayPal_Onboarding::instance();
        $status       = $onboarding->get_status_snapshot();
        $active_env   = $status['active_environment'];
        $sandbox      = $status['sandbox'];
        $live         = $status['live'];
        $is_connected = ( 'sandbox' === $active_env ) ? $sandbox['connected'] : $live['connected'];

        $sandbox_connected = ! empty( $sandbox['connected'] );
        $live_connected    = ! empty( $live['connected'] );

        if ( $is_connected ) {
            $active_email = ( 'sandbox' === $active_env )
                ? (string) ( $sandbox['email'] ?? '' )
                : (string) ( $live['email'] ?? '' );
            ?>
            <div class="tejcart-paypal-connect is-connected"
                 data-nonce="<?php echo esc_attr( PayPal_Onboarding::ajax_nonce() ); ?>"
                 data-environment="<?php echo esc_attr( $active_env ); ?>"
                 data-sandbox-connected="<?php echo $sandbox_connected ? 'yes' : 'no'; ?>"
                 data-live-connected="<?php echo $live_connected ? 'yes' : 'no'; ?>">
                <div class="tejcart-paypal-connected">
                    <div class="tejcart-paypal-connected__heading">
                        <span class="tejcart-paypal-connected__check" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </span>
                        <strong><?php esc_html_e( 'PayPal Account Connected', 'tejcart' ); ?></strong>
                    </div>
                    <?php if ( '' !== $active_email ) : ?>
                        <div class="tejcart-paypal-connected__account">
                            <span class="tejcart-paypal-connected__account-label">
                                <?php esc_html_e( 'Connected Account:', 'tejcart' ); ?>
                            </span>
                            <span class="tejcart-paypal-connected__account-email">
                                <?php echo esc_html( $active_email ); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tejcart-paypal-connect__primary">
                    <button type="button" class="button tejcart-paypal-test-btn">
                        <?php esc_html_e( 'Test connection', 'tejcart' ); ?>
                    </button>
                    <button type="button" class="button tejcart-paypal-disconnect-btn">
                        <?php esc_html_e( 'Disconnect PayPal Account', 'tejcart' ); ?>
                    </button>
                </div>

                <span class="tejcart-paypal-connection-status-msg" role="status" aria-live="polite"></span>
            </div>
            <?php
            return;
        }

        $sandbox_result = $onboarding->fetch_signup_link( 'sandbox' );
        $live_result    = $onboarding->fetch_signup_link( 'live' );
        $sandbox_url    = is_wp_error( $sandbox_result ) ? '' : (string) $sandbox_result;
        $live_url       = is_wp_error( $live_result ) ? '' : (string) $live_result;

        $sandbox_href = '' !== $sandbox_url ? add_query_arg( 'displayMode', 'minibrowser', $sandbox_url ) : '';
        $live_href    = '' !== $live_url    ? add_query_arg( 'displayMode', 'minibrowser', $live_url )    : '';
        $active_href  = ( 'sandbox' === $active_env ) ? $sandbox_href : $live_href;
        $has_link     = '' !== $active_href;

        $errors = array();
        if ( is_wp_error( $sandbox_result ) ) {
            $errors[] = $sandbox_result->get_error_message();
        }
        if ( is_wp_error( $live_result ) ) {
            $errors[] = $live_result->get_error_message();
        }
        ?>
        <div class="tejcart-paypal-connect"
             data-nonce="<?php echo esc_attr( PayPal_Onboarding::ajax_nonce() ); ?>"
             data-environment="<?php echo esc_attr( $active_env ); ?>"
             data-sandbox-connected="<?php echo $sandbox_connected ? 'yes' : 'no'; ?>"
             data-live-connected="<?php echo $live_connected ? 'yes' : 'no'; ?>">
            <div class="tejcart-paypal-connect__primary">
                <?php if ( $has_link ) : ?>
                    <a href="<?php echo esc_url( $active_href ); ?>"
                       class="button tejcart-paypal-connect-btn"
                       data-paypal-button="true"
                       data-paypal-onboard-complete="tejcartPayPalOnboardComplete"
                       data-signup-sandbox="<?php echo esc_url( $sandbox_href ); ?>"
                       data-signup-live="<?php echo esc_url( $live_href ); ?>">
                        <?php esc_html_e( 'Click to Connect PayPal', 'tejcart' ); ?>
                    </a>
                <?php else : ?>
                    <button type="button"
                            class="button tejcart-paypal-connect-btn is-disabled"
                            disabled>
                        <?php esc_html_e( 'Click to Connect PayPal', 'tejcart' ); ?>
                    </button>
                <?php endif; ?>
                <span class="tejcart-pill tejcart-pill--success">
                    <?php esc_html_e( 'Recommended', 'tejcart' ); ?>
                </span>
            </div>

            <div class="tejcart-paypal-connect__divider" aria-hidden="true">
                <span><?php esc_html_e( 'OR', 'tejcart' ); ?></span>
            </div>

            <p class="tejcart-paypal-connect__manual">
                <a href="#tejcart-paypal-manual-credentials"
                   class="tejcart-paypal-manual-toggle"
                   aria-controls="tejcart-paypal-manual-credentials">
                    <?php esc_html_e( 'Enter credentials manually', 'tejcart' ); ?>
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </a>
            </p>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="tejcart-paypal-connection-status-msg is-error" role="status" aria-live="polite">
                    <?php foreach ( $errors as $err_msg ) : ?>
                        <div><?php echo esc_html( $err_msg ); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <span class="tejcart-paypal-connection-status-msg" role="status" aria-live="polite"></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the input element for a field based on its type.
     *
     * @param string $type       Field type.
     * @param string $field_name HTML name/id attribute.
     * @param mixed  $value      Current value.
     * @param array  $field      Field configuration.
     * @return void
     */
    public function render_field_input( string $type, string $field_name, $value, array $field ): void {
        $value = is_scalar( $value ) ? (string) $value : '';

        switch ( $type ) {
            case 'readonly':
                printf(
                    '<input type="text" id="%1$s" value="%2$s" readonly class="regular-text" />',
                    esc_attr( $field_name ),
                    esc_attr( $value )
                );
                break;

            case 'checkbox':

                $toggle_label = $field['checkbox_label'] ?? __( 'Enable', 'tejcart' );
                printf(
                    '<label for="%1$s" class="tejcart-checkbox-label"><input type="checkbox" id="%1$s" name="%1$s" value="yes"%2$s /> <span>%3$s</span></label>',
                    esc_attr( $field_name ),
                    'yes' === $value ? ' checked' : '',
                    esc_html( $toggle_label )
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="5" class="large-text tejcart-textarea">%2$s</textarea>',
                    esc_attr( $field_name ),
                    esc_textarea( $value )
                );
                break;

            case 'select':
                $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
                printf( '<select id="%1$s" name="%1$s" class="regular-text tejcart-select">', esc_attr( $field_name ) );
                foreach ( $options as $opt_value => $opt_label ) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr( (string) $opt_value ),
                        (string) $opt_value === $value ? ' selected' : '',
                        esc_html( (string) $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'segmented':

                $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
                echo '<div class="tejcart-segmented" role="radiogroup">';
                foreach ( $options as $opt_value => $opt_label ) {
                    $opt_id = $field_name . '_' . sanitize_html_class( (string) $opt_value );
                    printf(
                        '<label for="%1$s" class="tejcart-segmented__option%5$s">' .
                        '<input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s />' .
                        '<span>%6$s</span></label>',
                        esc_attr( $opt_id ),
                        esc_attr( $field_name ),
                        esc_attr( (string) $opt_value ),
                        (string) $opt_value === $value ? ' checked' : '',
                        (string) $opt_value === $value ? ' is-selected' : '',
                        esc_html( (string) $opt_label )
                    );
                }
                echo '</div>';
                break;

            case 'password':
                // M-01: never re-render a stored secret into the value="" attribute.
                // Show a placeholder telling the operator a value is configured;
                // sanitize_field_value() preserves the stored value when the field
                // is submitted blank.
                $placeholder = '' !== $value
                    ? __( 'Configured — leave blank to keep current value', 'tejcart' )
                    : '';
                printf(
                    '<input type="password" id="%1$s" name="%1$s" value="" placeholder="%2$s" class="regular-text" autocomplete="new-password" data-tejcart-secret-field="1" />',
                    esc_attr( $field_name ),
                    esc_attr( $placeholder )
                );
                break;

            case 'multicheck':

                $options    = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
                $selected   = array_filter( array_map( 'trim', explode( ',', $value ) ) );
                $selected   = array_flip( $selected );
                $columns    = isset( $field['columns'] ) ? max( 1, (int) $field['columns'] ) : 2;
                $field_html = '<div class="tejcart-multicheck tejcart-multicheck--cols-' . esc_attr( (string) $columns ) . '" role="group">';
                foreach ( $options as $opt_value => $opt_label ) {
                    $opt_id      = $field_name . '_' . sanitize_html_class( (string) $opt_value );
                    $is_selected = isset( $selected[ (string) $opt_value ] );
                    $field_html .= sprintf(
                        '<label for="%1$s" class="tejcart-multicheck__option%5$s">' .
                        '<input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s />' .
                        '<span>%6$s</span></label>',
                        esc_attr( $opt_id ),
                        esc_attr( $field_name ),
                        esc_attr( (string) $opt_value ),
                        $is_selected ? ' checked' : '',
                        $is_selected ? ' is-selected' : '',
                        esc_html( (string) $opt_label )
                    );
                }
                $field_html .= '</div>';

                $field_html .= sprintf(
                    '<input type="hidden" name="%s__sentinel" value="1" />',
                    esc_attr( $field_name )
                );
                echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values pre-escaped
                break;

            case 'number':
                $attrs = '';
                foreach ( array( 'min', 'max', 'step' ) as $attr ) {
                    if ( isset( $field[ $attr ] ) && '' !== (string) $field[ $attr ] ) {
                        $attrs .= sprintf( ' %s="%s"', $attr, esc_attr( (string) $field[ $attr ] ) );
                    }
                }
                printf(
                    '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text"%3$s />',
                    esc_attr( $field_name ),
                    esc_attr( $value ),
                    $attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values pre-escaped
                );
                break;

            case 'text':
            default:
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                    esc_attr( $field_name ),
                    esc_attr( $value )
                );
                break;
        }
    }

    /**
     * Build the HTML name attribute for a gateway field.
     *
     * @param string $gateway_id Gateway ID.
     * @param string $field_id   Field ID.
     * @return string
     */
    public function get_field_name( string $gateway_id, string $field_id ): string {
        return 'tejcart_gateway_' . $gateway_id . '_' . $field_id;
    }

    /**
     * Render an error message wrapper.
     *
     * @param string $message Message to display.
     * @return void
     */
    private function render_error( string $message ): void {
        $back_url = Payment_Methods_List::get_list_url();
        ?>
        <div class="wrap tejcart-admin-wrap">
            <h1><?php esc_html_e( 'Payment Method Settings', 'tejcart' ); ?></h1>
            <div class="notice notice-error">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <p>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button">
                    &larr; <?php esc_html_e( 'Back to Payment Methods', 'tejcart' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Get the current gateway ID from the query parameter.
     *
     * @return string|null
     */
    private function get_current_gateway_id(): ?string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change
        if ( ! isset( $_GET['gateway'] ) ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $gateway_id = sanitize_text_field( wp_unslash( $_GET['gateway'] ) );

        return '' !== $gateway_id ? $gateway_id : null;
    }
}
