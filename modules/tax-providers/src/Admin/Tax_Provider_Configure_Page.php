<?php
/**
 * Per-provider configuration view.
 *
 * Reached from the providers list by clicking "Set up" / "Manage". This
 * is the focused form a merchant uses to paste API credentials, tune
 * the safety controls (page context, daily cap, address strictness),
 * and inspect usage / circuit-breaker state — everything scoped to a
 * single provider so a "Save" button can't ever clobber an unsaved key
 * for a neighbouring provider.
 *
 * Renders into the same `tejcart-settings-content` panel as the list
 * view, so the surrounding sidebar / sub-nav chrome remains visible.
 *
 * @package TejCart\Tax_Providers\Admin
 */

namespace TejCart\Tax_Providers\Admin;

use TejCart\Tax_Providers\Abstract_Live_Tax_Provider;
use TejCart\Tax_Providers\Tax_Provider_Usage_Tracker;
use TejCart\Tax_Providers\Tax_Providers_Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tax_Provider_Configure_Page {
    /**
     * Stable URL for the configure view, used by the list-row "Manage"
     * link and by the redirect after a successful save.
     *
     * @param string $provider_id Provider id (must exist in the bundled map).
     * @param string $base_url    Base URL for the list view (no query args).
     */
    public static function get_url( string $provider_id, string $base_url ): string {
        return add_query_arg( 'provider', $provider_id, $base_url );
    }

    /**
     * Render the configure card for a provider.
     *
     * @param string                                   $id        Provider id.
     * @param class-string<Abstract_Live_Tax_Provider> $class     Provider class.
     * @param bool                                     $is_active Whether this provider is currently the active calculator.
     * @param string                                   $list_url  URL of the list view (for the breadcrumb back link).
     */
    public function render( string $id, string $class, bool $is_active, string $list_url ): void {
        /** @var Abstract_Live_Tax_Provider $provider */
        $provider     = new $class();
        $settings     = $provider->get_settings();
        $title        = $provider->get_title();
        $is_available = $provider->is_available();
        $is_configured = $provider->is_configured();
        $is_test_mode = $provider->is_test_mode();
        $is_enabled   = ( 'yes' === (string) ( $settings['enabled'] ?? 'no' ) );

        $usage       = Tax_Provider_Usage_Tracker::instance()->get_state( $id );
        $avg_latency = Tax_Provider_Usage_Tracker::instance()->average_latency_ms( $id );
        $breaker     = (int) $usage['breaker_until'] > time();

        $toggle_nonce = wp_create_nonce( Tax_Providers_Bootstrap::TOGGLE_NONCE );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $just_saved = isset( $_GET['updated'] );
        ?>
        <div
            class="tejcart-tax-provider-configure"
            data-provider-id="<?php echo esc_attr( $id ); ?>"
            data-toggle-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"
        >
            <p class="tejcart-tax-provider-configure__breadcrumb">
                <a href="<?php echo esc_url( $list_url ); ?>" class="tejcart-tax-provider-configure__back">
                    <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    <?php esc_html_e( 'Back to providers', 'tejcart' ); ?>
                </a>
            </p>

            <?php settings_errors( 'tejcart_tax_providers' ); ?>

            <?php if ( $just_saved && ! count( get_settings_errors( 'tejcart_tax_providers' ) ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Tax provider settings saved.', 'tejcart' ); ?></p>
                </div>
            <?php endif; ?>

            <section class="tejcart-card tejcart-tax-provider-configure__card">
                <header class="tejcart-card-header tejcart-tax-provider-configure__header">
                    <div class="tejcart-tax-provider-configure__identity">
                        <span class="tejcart-payment-method-row__logo" aria-hidden="true">
                            <span class="tejcart-payment-method-row__initial">
                                <?php echo esc_html( $this->initial_for( $title ) ); ?>
                            </span>
                        </span>
                        <div class="tejcart-tax-provider-configure__heading">
                            <h2><?php echo esc_html( $title ); ?></h2>
                            <code class="tejcart-tax-provider-row__slug"><?php echo esc_html( $id ); ?></code>
                        </div>
                    </div>
                    <div class="tejcart-tax-provider-configure__status">
                        <?php $this->render_status_pills( $is_configured, $is_enabled, $is_active, $is_available, $is_test_mode, $breaker ); ?>
                        <?php if ( $is_configured ) : ?>
                            <label class="tejcart-toggle tejcart-tax-provider-toggle" for="tejcart-tax-provider-toggle-detail">
                                <input
                                    type="checkbox"
                                    id="tejcart-tax-provider-toggle-detail"
                                    class="tejcart-tax-provider-toggle-input"
                                    data-provider-id="<?php echo esc_attr( $id ); ?>"
                                    value="yes"
                                    <?php checked( $is_enabled ); ?>
                                />
                                <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                <span class="screen-reader-text">
                                    <?php
                                    printf(
                                        /* translators: %s: provider title. */
                                        esc_html__( 'Enable %s', 'tejcart' ),
                                        esc_html( $title )
                                    );
                                    ?>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="tejcart-card-body tejcart-tax-provider-configure__body">
                    <?php $this->render_usage_panel( $id, $usage, $avg_latency, $breaker ); ?>

                    <form method="post" class="tejcart-tax-provider-configure__form">
                        <?php wp_nonce_field( 'tejcart_tax_provider_save_' . $id ); ?>
                        <input type="hidden" name="tejcart_tax_provider_save" value="<?php echo esc_attr( $id ); ?>" />

                        <table class="form-table" role="presentation">
                            <?php foreach ( $class::setting_fields() as $field ) : ?>
                                <?php $this->render_field( $field, $settings ); ?>
                            <?php endforeach; ?>
                            <tr>
                                <th scope="row">
                                    <?php esc_html_e( 'Active provider', 'tejcart' ); ?>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="tejcart_tax_provider_set_active" value="1" <?php checked( $is_active ); ?> />
                                        <?php esc_html_e( 'Make this the active live tax provider', 'tejcart' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Only one provider is consulted on each cart calculation. Setting this as active replaces the currently active provider.', 'tejcart' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit tejcart-tax-provider-configure__submit">
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                            </button>
                            <a href="<?php echo esc_url( $list_url ); ?>" class="button button-link">
                                <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                            </a>
                        </p>
                    </form>
                </div>
            </section>
        </div>
        <?php
    }

    private function render_status_pills( bool $is_configured, bool $is_enabled, bool $is_active, bool $is_available, bool $is_test_mode, bool $breaker ): void {
        if ( ! $is_configured ) {
            echo '<span class="tejcart-pill tejcart-pill--neutral">' .
                esc_html__( 'Not configured', 'tejcart' ) .
                '</span>';
            return;
        }

        if ( $is_active && $is_available ) {
            echo '<span class="tejcart-pill tejcart-pill--success">' .
                esc_html__( 'Active', 'tejcart' ) .
                '</span>';
        } elseif ( $is_active ) {
            echo '<span class="tejcart-pill tejcart-pill--error">' .
                esc_html__( 'Active but paused', 'tejcart' ) .
                '</span>';
        } elseif ( $is_enabled ) {
            echo '<span class="tejcart-pill tejcart-pill--warning">' .
                esc_html__( 'Ready', 'tejcart' ) .
                '</span>';
        } else {
            echo '<span class="tejcart-pill tejcart-pill--neutral">' .
                esc_html__( 'Paused', 'tejcart' ) .
                '</span>';
        }

        if ( $is_test_mode ) {
            echo '<span class="tejcart-pill tejcart-pill--warning" title="' .
                esc_attr__( 'Detected from API key prefix.', 'tejcart' ) .
                '">' .
                esc_html__( 'Test mode', 'tejcart' ) .
                '</span>';
        }

        if ( $breaker ) {
            echo '<span class="tejcart-pill tejcart-pill--error">' .
                esc_html__( 'Circuit breaker open', 'tejcart' ) .
                '</span>';
        }
    }

    /**
     * @param array<string,mixed> $usage Snapshot from Tax_Provider_Usage_Tracker::get_state().
     */
    private function render_usage_panel( string $id, array $usage, int $avg_latency, bool $breaker ): void {
        $today    = (int) $usage['day_count'];
        $month    = (int) $usage['month_count'];
        $last_at  = (int) $usage['last_call_at'];
        $err_at   = (int) ( $usage['last_error']['at'] ?? 0 );
        $err_msg  = (string) ( $usage['last_error']['message'] ?? '' );
        $cooldown = max( 0, (int) $usage['breaker_until'] - time() );
        ?>
        <div class="tejcart-tax-usage-panel">
            <div class="tejcart-tax-usage-grid">
                <div class="tejcart-tax-usage-stat">
                    <span class="tejcart-tax-usage-label"><?php esc_html_e( 'Calls today (UTC)', 'tejcart' ); ?></span>
                    <span class="tejcart-tax-usage-value"><?php echo esc_html( number_format_i18n( $today ) ); ?></span>
                </div>
                <div class="tejcart-tax-usage-stat">
                    <span class="tejcart-tax-usage-label"><?php esc_html_e( 'Calls this month', 'tejcart' ); ?></span>
                    <span class="tejcart-tax-usage-value"><?php echo esc_html( number_format_i18n( $month ) ); ?></span>
                </div>
                <div class="tejcart-tax-usage-stat">
                    <span class="tejcart-tax-usage-label"><?php esc_html_e( 'Avg latency', 'tejcart' ); ?></span>
                    <span class="tejcart-tax-usage-value">
                        <?php echo $avg_latency > 0
                            ? esc_html( sprintf( '%d ms', $avg_latency ) )
                            : esc_html__( '—', 'tejcart' ); ?>
                    </span>
                </div>
                <div class="tejcart-tax-usage-stat">
                    <span class="tejcart-tax-usage-label"><?php esc_html_e( 'Last call', 'tejcart' ); ?></span>
                    <span class="tejcart-tax-usage-value">
                        <?php echo $last_at > 0
                            ? esc_html( human_time_diff( $last_at, time() ) . ' ' . __( 'ago', 'tejcart' ) )
                            : esc_html__( '—', 'tejcart' ); ?>
                    </span>
                </div>
            </div>

            <?php if ( $breaker ) : ?>
                <div class="tejcart-notice-inline warning">
                    <?php
                    printf(
                        /* translators: %d: cooldown seconds remaining */
                        esc_html__( 'Circuit breaker is open after repeated failures. The cart is using manual rates and will retry the upstream in %d seconds.', 'tejcart' ),
                        (int) $cooldown
                    );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ( '' !== $err_msg ) : ?>
                <details class="tejcart-tax-usage-error">
                    <summary><?php esc_html_e( 'Last error', 'tejcart' ); ?></summary>
                    <p>
                        <code><?php echo esc_html( $err_msg ); ?></code>
                        <?php if ( $err_at > 0 ) : ?>
                            <em>(<?php echo esc_html( human_time_diff( $err_at, time() ) ); ?> <?php esc_html_e( 'ago', 'tejcart' ); ?>)</em>
                        <?php endif; ?>
                    </p>
                </details>
            <?php endif; ?>

            <form method="post" class="tejcart-tax-usage-reset">
                <?php wp_nonce_field( 'tejcart_tax_provider_reset_' . $id ); ?>
                <input type="hidden" name="tejcart_tax_provider_reset_usage" value="<?php echo esc_attr( $id ); ?>" />
                <button type="submit" class="button button-link"
                        onclick="return confirm('<?php echo esc_js( __( 'Reset the usage counter and clear the circuit breaker?', 'tejcart' ) ); ?>');">
                    <?php esc_html_e( 'Reset counter', 'tejcart' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render a single credential field row.
     *
     * @param array<string, mixed> $field    Field descriptor.
     * @param array<string, mixed> $settings Current settings (decrypted).
     */
    private function render_field( array $field, array $settings ): void {
        $id          = (string) ( $field['id'] ?? '' );
        $label       = (string) ( $field['label'] ?? $id );
        $type        = (string) ( $field['type'] ?? 'text' );
        $description = (string) ( $field['description'] ?? '' );
        $required    = ! empty( $field['required'] );

        $value = $settings[ $id ] ?? '';

        if ( 'heading' === $type ) {
            ?>
            <tr class="tejcart-tax-field-heading">
                <th colspan="2" scope="colgroup">
                    <h3 class="tejcart-tax-field-heading-title"><?php echo esc_html( $label ); ?></h3>
                    <?php if ( '' !== $description ) : ?>
                        <p class="description"><?php echo esc_html( $description ); ?></p>
                    <?php endif; ?>
                </th>
            </tr>
            <?php
            return;
        }
        ?>
        <tr>
            <th scope="row">
                <label for="tejcart_tax_<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <?php if ( $required ) : ?><span class="tejcart-required" aria-hidden="true"> *</span><?php endif; ?>
                </label>
            </th>
            <td>
                <?php if ( 'checkbox' === $type ) : ?>
                    <input type="checkbox" id="tejcart_tax_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" value="1"
                           <?php checked( 'yes', $value ); ?> />
                <?php elseif ( 'password' === $type ) : ?>
                    <input type="password" id="tejcart_tax_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo '' !== (string) $value ? esc_attr__( '••••••••', 'tejcart' ) : ''; ?>" />
                    <?php if ( '' !== (string) $value ) : ?>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the existing value.', 'tejcart' ); ?></p>
                    <?php endif; ?>
                <?php elseif ( 'number' === $type ) : ?>
                    <input type="number" min="0" step="1"
                           id="tejcart_tax_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" class="small-text"
                           value="<?php echo esc_attr( (string) $value ); ?>" />
                <?php elseif ( 'select' === $type ) : ?>
                    <select id="tejcart_tax_<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>">
                        <?php if ( ! empty( $field['allow_empty'] ) ) : ?>
                            <option value="" <?php selected( '', (string) $value ); ?>>
                                <?php esc_html_e( '— Use store default —', 'tejcart' ); ?>
                            </option>
                        <?php endif; ?>
                        <?php foreach ( (array) ( $field['options'] ?? array() ) as $opt_value => $opt_label ) : ?>
                            <option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
                                <?php echo esc_html( (string) $opt_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" id="tejcart_tax_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" class="regular-text"
                           value="<?php echo esc_attr( (string) $value ); ?>" />
                <?php endif; ?>

                <?php if ( '' !== $description ) : ?>
                    <p class="description"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function initial_for( string $label ): string {
        $trimmed = trim( $label );
        if ( '' === $trimmed ) {
            return '?';
        }
        return mb_strtoupper( mb_substr( $trimmed, 0, 1 ) );
    }
}
