<?php
/**
 * Per-carrier configuration view.
 *
 * Reached from the carriers list by clicking "Set up" / "Manage". This
 * is the focused form a merchant uses to paste an API key, choose
 * live vs. sandbox, and run a test connection — everything scoped to a
 * single driver so a "Save" button can't ever clobber an unsaved key
 * for a neighbouring carrier (the failure mode the old single-form
 * screen had).
 *
 * Renders into the same `tejcart-settings-content` panel as the list
 * view, so the surrounding sidebar / sub-nav chrome remains visible
 * and merchants can navigate back to the list (or jump to another
 * carrier) without a full page reload feeling.
 *
 * @package TejCart\Shipping_Plugin\Admin
 */

namespace TejCart\Shipping_Plugin\Admin;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Credentials_Vault;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carrier_Configure_Page {
    private Credentials_Vault $vault;
    private Carrier_State $state;

    public function __construct( Credentials_Vault $vault, Carrier_State $state ) {
        $this->vault = $vault;
        $this->state = $state;
    }

    /**
     * Stable URL for the configure view, used by the list-row "Manage"
     * link and by the redirect after a successful save.
     */
    public static function get_url( string $driver_id ): string {
        return add_query_arg(
            array(
                'page'    => Settings_Page::PARENT_PAGE,
                'tab'     => Settings_Page::PARENT_TAB,
                'section' => Settings_Page::SECTION_KEY,
                'driver'  => $driver_id,
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function get_list_url(): string {
        return add_query_arg(
            array(
                'page'    => Settings_Page::PARENT_PAGE,
                'tab'     => Settings_Page::PARENT_TAB,
                'section' => Settings_Page::SECTION_KEY,
            ),
            admin_url( 'admin.php' )
        );
    }

    public function render( Abstract_Carrier_Driver $driver ): void {
        $driver_id   = $driver->id();
        $credentials = $this->vault->get( $driver_id );
        $fields      = $driver->credential_fields();

        $has_any_credential = false;
        foreach ( $fields as $field_id => $schema ) {
            $type = (string) ( $schema['type'] ?? 'text' );
            if ( 'checkbox' === $type || 'select' === $type ) {
                continue;
            }
            if ( '' !== trim( (string) ( $credentials[ $field_id ] ?? '' ) ) ) {
                $has_any_credential = true;
                break;
            }
        }

        $is_enabled  = $has_any_credential && $this->state->is_enabled( $driver_id );
        $environment = (string) ( $credentials['environment'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $updated = isset( $_GET['updated'] );

        $action       = esc_url( admin_url( 'admin-post.php' ) );
        $back_url     = self::get_list_url();
        $toggle_nonce = wp_create_nonce( Settings_Page::TOGGLE_NONCE );
        $test_nonce   = wp_create_nonce( Settings_Page::TEST_NONCE );

        ?>
        <div
            class="tejcart-carrier-configure"
            data-carrier-id="<?php echo esc_attr( $driver_id ); ?>"
            data-toggle-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"
            data-test-nonce="<?php echo esc_attr( $test_nonce ); ?>"
        >
            <p class="tejcart-carrier-configure__breadcrumb">
                <a href="<?php echo esc_url( $back_url ); ?>" class="tejcart-carrier-configure__back">
                    <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    <?php esc_html_e( 'Back to carriers', 'tejcart' ); ?>
                </a>
            </p>

            <?php if ( $updated ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Carrier settings saved.', 'tejcart' ); ?></p>
                </div>
            <?php endif; ?>

            <section class="tejcart-card tejcart-carrier-configure__card">
                <header class="tejcart-card-header tejcart-carrier-configure__header">
                    <div class="tejcart-carrier-configure__identity">
                        <span class="tejcart-payment-method-row__logo" aria-hidden="true">
                            <span class="tejcart-payment-method-row__initial">
                                <?php echo esc_html( mb_strtoupper( mb_substr( $driver->label(), 0, 1 ) ) ); ?>
                            </span>
                        </span>
                        <div class="tejcart-carrier-configure__heading">
                            <h2><?php echo esc_html( $driver->label() ); ?></h2>
                            <code class="tejcart-carrier-row__slug"><?php echo esc_html( $driver_id ); ?></code>
                        </div>
                    </div>
                    <div class="tejcart-carrier-configure__status">
                        <?php $this->render_status_pill( $has_any_credential, $is_enabled, $environment ); ?>
                        <?php if ( $has_any_credential ) : ?>
                            <label class="tejcart-toggle tejcart-carrier-toggle" for="tejcart-carrier-toggle-detail">
                                <input
                                    type="checkbox"
                                    id="tejcart-carrier-toggle-detail"
                                    class="tejcart-carrier-toggle-input"
                                    data-carrier-id="<?php echo esc_attr( $driver_id ); ?>"
                                    value="yes"
                                    <?php checked( $is_enabled ); ?>
                                />
                                <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                <span class="screen-reader-text">
                                    <?php
                                    printf(
                                        /* translators: %s: carrier label. */
                                        esc_html__( 'Enable %s', 'tejcart' ),
                                        esc_html( $driver->label() )
                                    );
                                    ?>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>
                </header>

                <form
                    method="post"
                    action="<?php echo esc_attr( $action ); ?>"
                    class="tejcart-carrier-configure__form"
                >
                    <input type="hidden" name="action" value="<?php echo esc_attr( Settings_Page::SAVE_ACTION ); ?>" />
                    <input type="hidden" name="driver" value="<?php echo esc_attr( $driver_id ); ?>" />
                    <?php wp_nonce_field( Settings_Page::NONCE_NAME ); ?>

                    <div class="tejcart-card-body tejcart-carrier-configure__body">
                        <?php foreach ( $fields as $field_id => $schema ) : ?>
                            <?php $this->render_field( $driver_id, $field_id, $schema, $credentials ); ?>
                        <?php endforeach; ?>
                    </div>

                    <footer class="tejcart-carrier-configure__footer">
                        <div class="tejcart-carrier-configure__footer-actions">
                            <?php if ( $driver->supports_test_connection() ) : ?>
                                <button
                                    type="button"
                                    class="button button-secondary tejcart-carrier-test-button"
                                    data-carrier-id="<?php echo esc_attr( $driver_id ); ?>"
                                >
                                    <?php esc_html_e( 'Test connection', 'tejcart' ); ?>
                                </button>
                                <span class="tejcart-carrier-configure__test-result" aria-live="polite"></span>
                            <?php endif; ?>
                        </div>
                        <div class="tejcart-carrier-configure__footer-save">
                            <a href="<?php echo esc_url( $back_url ); ?>" class="button button-link">
                                <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                            </a>
                            <?php submit_button( __( 'Save changes', 'tejcart' ), 'primary', 'submit', false ); ?>
                        </div>
                    </footer>
                </form>
            </section>
        </div>
        <?php
    }

    private function render_status_pill( bool $has_any_credential, bool $is_enabled, string $environment ): void {
        if ( ! $has_any_credential ) {
            echo '<span class="tejcart-pill tejcart-pill--neutral">' .
                esc_html__( 'Not connected', 'tejcart' ) .
                '</span>';
            return;
        }

        if ( ! $is_enabled ) {
            echo '<span class="tejcart-pill tejcart-pill--warning">' .
                esc_html__( 'Paused', 'tejcart' ) .
                '</span>';
            return;
        }

        // No environment value = the driver has no live/sandbox split
        // (e.g. Shiprocket, DPD, Evri, Royal Mail). Don't render a pill
        // at all — the enabled toggle already signals "connected and
        // active", and a Sandbox/Live tag would be meaningless.
        if ( '' === $environment ) {
            return;
        }

        $is_live = ( 'live' === $environment || 'production' === $environment );
        if ( $is_live ) {
            echo '<span class="tejcart-pill tejcart-pill--success">' .
                esc_html__( 'Live', 'tejcart' ) .
                '</span>';
        } else {
            echo '<span class="tejcart-pill tejcart-pill--warning">' .
                esc_html__( 'Sandbox', 'tejcart' ) .
                '</span>';
        }
    }

    /**
     * @param array<string,mixed>  $schema
     * @param array<string,string> $credentials
     */
    private function render_field( string $driver_id, string $field_id, array $schema, array $credentials ): void {
        $type     = (string) ( $schema['type'] ?? 'text' );
        $title    = (string) ( $schema['title'] ?? $field_id );
        $help     = (string) ( $schema['description'] ?? '' );
        $secret   = ! empty( $schema['secret'] );
        $required = ! empty( $schema['required'] );
        $current  = (string) ( $credentials[ $field_id ] ?? ( $schema['default'] ?? '' ) );
        $name     = sprintf( 'carriers[%s][%s]', $driver_id, $field_id );
        $id_attr  = sprintf( 'carriers-%s-%s', $driver_id, $field_id );

        $row_classes = array( 'tejcart-carrier-field' );
        $row_classes[] = 'tejcart-carrier-field--' . sanitize_html_class( $type );
        if ( $secret ) {
            $row_classes[] = 'tejcart-carrier-field--secret';
        }

        echo '<div class="' . esc_attr( implode( ' ', $row_classes ) ) . '">';
        echo '<label for="' . esc_attr( $id_attr ) . '" class="tejcart-carrier-field__label">' .
            esc_html( $title ) .
            ( $required ? ' <span class="tejcart-carrier-field__required" aria-hidden="true">*</span>' : '' ) .
            '</label>';
        echo '<div class="tejcart-carrier-field__control">';

        if ( 'select' === $type && isset( $schema['options'] ) && is_array( $schema['options'] ) ) {
            $this->render_environment_or_select( $name, $id_attr, $current, $schema['options'], $field_id );
        } elseif ( 'checkbox' === $type ) {
            $this->render_checkbox( $name, $id_attr, $current, $title );
        } elseif ( 'textarea' === $type ) {
            $this->render_textarea( $name, $id_attr, $current );
        } else {
            $this->render_input( $name, $id_attr, $current, $type, $secret );
        }

        if ( '' !== $help ) {
            echo '<p class="tejcart-carrier-field__help">' . esc_html( $help ) . '</p>';
        }

        echo '</div></div>';
    }

    /**
     * @param array<string|int,string> $options
     */
    private function render_environment_or_select( string $name, string $id_attr, string $current, array $options, string $field_id ): void {
        // Render the ubiquitous Live / Sandbox environment field as a
        // segmented control regardless of which two labels the driver
        // ships (Sandbox / Test / Staging / CIE — drivers all use
        // different vocabulary but the user-facing concept is the same).
        $is_environment = ( 'environment' === $field_id && 2 === count( $options ) );

        if ( $is_environment ) {
            echo '<div class="tejcart-carrier-segmented" role="radiogroup">';
            $i = 0;
            foreach ( $options as $value => $label ) {
                $value      = (string) $value;
                $is_active  = ( $current === $value ) || ( '' === $current && 0 === $i );
                $option_id  = $id_attr . '-' . sanitize_html_class( $value );
                $is_live    = ( 'live' === $value || 'production' === $value );
                $tone_class = $is_live ? 'is-live' : 'is-sandbox';
                printf(
                    '<label class="tejcart-carrier-segmented__option %1$s%2$s" for="%3$s"><input type="radio" name="%4$s" id="%3$s" value="%5$s"%6$s /><span>%7$s</span></label>',
                    esc_attr( $tone_class ),
                    $is_active ? ' is-active' : '',
                    esc_attr( $option_id ),
                    esc_attr( $name ),
                    esc_attr( $value ),
                    $is_active ? ' checked' : '',
                    esc_html( (string) $label )
                );
                $i++;
            }
            echo '</div>';
            return;
        }

        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id_attr ) . '" class="tejcart-carrier-field__select">';
        foreach ( $options as $value => $label ) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr( (string) $value ),
                selected( $current, (string) $value, false ),
                esc_html( (string) $label )
            );
        }
        echo '</select>';
    }

    private function render_checkbox( string $name, string $id_attr, string $current, string $title ): void {
        printf(
            '<label class="tejcart-carrier-field__checkbox" for="%1$s"><input type="checkbox" name="%2$s" id="%1$s" value="yes"%3$s /><span>%4$s</span></label>',
            esc_attr( $id_attr ),
            esc_attr( $name ),
            checked( 'yes', $current, false ),
            esc_html( $title )
        );
    }

    private function render_textarea( string $name, string $id_attr, string $current ): void {
        printf(
            '<textarea name="%1$s" id="%2$s" class="tejcart-carrier-field__textarea" rows="8" spellcheck="false" autocomplete="off">%3$s</textarea>',
            esc_attr( $name ),
            esc_attr( $id_attr ),
            esc_textarea( $current )
        );
    }

    private function render_input( string $name, string $id_attr, string $current, string $type, bool $secret ): void {
        $input_type  = 'password' === $type ? 'password' : 'text';
        $placeholder = $secret && '' !== $current ? '••••••••' : '';

        if ( $secret ) {
            echo '<div class="tejcart-carrier-field__secret-wrap">';
        }

        printf(
            '<input type="%1$s" name="%2$s" id="%3$s" value="%4$s" placeholder="%5$s" class="tejcart-carrier-field__input regular-text" autocomplete="off" spellcheck="false" />',
            esc_attr( $input_type ),
            esc_attr( $name ),
            esc_attr( $id_attr ),
            esc_attr( $secret ? '' : $current ),
            esc_attr( $placeholder )
        );

        if ( $secret ) {
            printf(
                '<button type="button" class="button button-link tejcart-carrier-field__reveal" data-target="%1$s" aria-pressed="false">%2$s</button>',
                esc_attr( $id_attr ),
                esc_html__( 'Show', 'tejcart' )
            );
            echo '</div>';
        }
    }
}
