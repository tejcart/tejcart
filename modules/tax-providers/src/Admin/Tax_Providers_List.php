<?php
/**
 * Tax providers list view.
 *
 * Renders the Settings → Tax → Providers screen as a card of provider
 * rows. The visual language is borrowed wholesale from the Shipping
 * Carriers list (`TejCart\Shipping_Plugin\Admin\Carriers_List`) so a
 * merchant who has configured a carrier recognises the affordances on
 * sight: brand mark + name on the left, status pill + AJAX toggle in
 * the middle, "Set up" / "Manage" call-to-action on the right.
 *
 * Click "Manage" / "Set up" to deep-link into
 * Tax_Provider_Configure_Page::render() for a focused per-provider form.
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

/**
 * Renders the providers list.
 *
 * Stateless — all data is pulled from each provider's settings, the
 * active-provider option, and the usage tracker. No instance state is
 * cached so the screen always reflects the latest save.
 */
class Tax_Providers_List {
    /**
     * Map of provider id => provider class.
     *
     * @var array<string, class-string<Abstract_Live_Tax_Provider>>
     */
    private array $providers;

    /**
     * @param array<string, class-string<Abstract_Live_Tax_Provider>> $providers
     */
    public function __construct( array $providers ) {
        $this->providers = $providers;
    }

    /**
     * Render the list.
     *
     * @param string $base_url     Base admin URL (without `provider=` arg) used
     *                            for "Set up" / "Manage" links so the screen
     *                            works both when embedded under Settings → Tax
     *                            and when reached via the legacy direct slug.
     * @param string $active_id   Active provider id from the option.
     */
    public function render( string $base_url, string $active_id ): void {
        $toggle_nonce = wp_create_nonce( Tax_Providers_Bootstrap::TOGGLE_NONCE );
        $active_nonce = wp_create_nonce( Tax_Providers_Bootstrap::SET_ACTIVE_NONCE );
        ?>
        <div
            class="tejcart-tax-providers-list-wrap"
            data-toggle-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"
            data-active-nonce="<?php echo esc_attr( $active_nonce ); ?>"
        >
            <header class="tejcart-section-header tejcart-tax-providers-list__header">
                <div class="tejcart-tax-providers-list__header-text">
                    <h2 class="tejcart-section-title"><?php esc_html_e( 'Tax providers', 'tejcart' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Connect a live tax provider to take over from the manual rate table. Only one provider is consulted on each cart calculation; failures fall back automatically to the manual rates configured under Settings → Tax → Rates.', 'tejcart' ); ?>
                    </p>
                </div>
                <div class="tejcart-tax-providers-list__search">
                    <label for="tejcart-tax-providers-search" class="screen-reader-text">
                        <?php esc_html_e( 'Search providers', 'tejcart' ); ?>
                    </label>
                    <input
                        type="search"
                        id="tejcart-tax-providers-search"
                        class="tejcart-tax-providers-list__search-input"
                        placeholder="<?php esc_attr_e( 'Search providers…', 'tejcart' ); ?>"
                        autocomplete="off"
                    />
                </div>
            </header>

            <?php if ( array() === $this->providers ) : ?>
                <div class="tejcart-card">
                    <div class="tejcart-card-body">
                        <p><?php esc_html_e( 'No tax providers are registered yet.', 'tejcart' ); ?></p>
                    </div>
                </div>
            <?php else : ?>
                <section class="tejcart-card tejcart-tax-providers-region" data-region="bundled">
                    <div class="tejcart-card-header">
                        <h2><?php esc_html_e( 'Live tax services', 'tejcart' ); ?></h2>
                        <span class="tejcart-tax-providers-region__count">
                            <?php
                            printf(
                                /* translators: %d: number of providers. */
                                esc_html( _n( '%d provider', '%d providers', count( $this->providers ), 'tejcart' ) ),
                                (int) count( $this->providers )
                            );
                            ?>
                        </span>
                    </div>
                    <table class="tejcart-payments-table tejcart-tax-providers-table widefat">
                        <thead>
                            <tr>
                                <th class="tejcart-payments-table__col-method" scope="col"><?php esc_html_e( 'Provider', 'tejcart' ); ?></th>
                                <th class="tejcart-payments-table__col-enabled" scope="col"><?php esc_html_e( 'Enabled', 'tejcart' ); ?></th>
                                <th class="tejcart-payments-table__col-desc" scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                <th class="tejcart-payments-table__col-action" scope="col">
                                    <span class="screen-reader-text"><?php esc_html_e( 'Configure', 'tejcart' ); ?></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $this->providers as $id => $class ) : ?>
                                <?php $this->render_provider_row( $id, $class, $base_url, $active_id ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <div class="tejcart-tax-providers-list__empty" hidden>
                <p><?php esc_html_e( 'No providers match your search.', 'tejcart' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * @param class-string<Abstract_Live_Tax_Provider> $class
     */
    private function render_provider_row( string $id, string $class, string $base_url, string $active_id ): void {
        /** @var Abstract_Live_Tax_Provider $provider */
        $provider     = new $class();
        $settings     = $provider->get_settings();
        $title        = $provider->get_title();
        $is_active    = ( $active_id === $id );
        $is_available = $provider->is_available();
        $is_configured = $provider->is_configured();
        $is_test_mode = $provider->is_test_mode();
        $is_enabled   = ( 'yes' === (string) ( $settings['enabled'] ?? 'no' ) );

        $usage   = Tax_Provider_Usage_Tracker::instance()->get_state( $id );
        $breaker = (int) $usage['breaker_until'] > time();

        $row_classes = array( 'tejcart-payment-method-row', 'tejcart-tax-provider-row' );
        if ( $is_configured ) {
            $row_classes[] = 'is-connected';
        }
        if ( $is_enabled ) {
            $row_classes[] = 'is-enabled';
        }
        if ( $is_active ) {
            $row_classes[] = 'is-active';
        }

        $configure_url  = Tax_Provider_Configure_Page::get_url( $id, $base_url );
        $toggle_id      = 'tejcart-tax-provider-toggle-' . sanitize_html_class( $id );
        $search_blob    = strtolower( $title . ' ' . $id );

        ?>
        <tr
            class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
            data-provider-id="<?php echo esc_attr( $id ); ?>"
            data-search="<?php echo esc_attr( $search_blob ); ?>"
        >
            <td class="tejcart-payment-method-row__method" data-colname="<?php esc_attr_e( 'Provider', 'tejcart' ); ?>">
                <div class="tejcart-payment-method-row__method-inner">
                    <span class="tejcart-payment-method-row__logo" aria-hidden="true">
                        <span class="tejcart-payment-method-row__initial">
                            <?php echo esc_html( $this->initial_for( $title ) ); ?>
                        </span>
                    </span>
                    <span class="tejcart-tax-provider-row__label">
                        <a href="<?php echo esc_url( $configure_url ); ?>" class="tejcart-payment-method-row__title">
                            <?php echo esc_html( $title ); ?>
                        </a>
                        <code class="tejcart-tax-provider-row__slug"><?php echo esc_html( $id ); ?></code>
                    </span>
                </div>
            </td>
            <td class="tejcart-payment-method-row__enabled" data-colname="<?php esc_attr_e( 'Enabled', 'tejcart' ); ?>">
                <?php if ( ! $is_configured ) : ?>
                    <span class="tejcart-pill tejcart-pill--neutral">
                        <?php esc_html_e( 'Not configured', 'tejcart' ); ?>
                    </span>
                <?php else : ?>
                    <label class="tejcart-toggle tejcart-tax-provider-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr( $toggle_id ); ?>"
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
            </td>
            <td class="tejcart-payment-method-row__desc tejcart-tax-provider-row__status" data-colname="<?php esc_attr_e( 'Status', 'tejcart' ); ?>">
                <?php $this->render_status_cell( $is_configured, $is_enabled, $is_active, $is_available, $is_test_mode, $breaker ); ?>
            </td>
            <td class="tejcart-payment-method-row__action" data-colname="<?php esc_attr_e( 'Configure', 'tejcart' ); ?>">
                <div class="tejcart-tax-provider-row__actions">
                    <?php if ( $is_configured && $is_enabled && ! $is_active ) : ?>
                        <button
                            type="button"
                            class="button button-link tejcart-tax-provider-make-active"
                            data-provider-id="<?php echo esc_attr( $id ); ?>"
                        >
                            <?php esc_html_e( 'Make active', 'tejcart' ); ?>
                        </button>
                    <?php endif; ?>
                    <a
                        href="<?php echo esc_url( $configure_url ); ?>"
                        class="button <?php echo $is_configured ? 'button-secondary' : 'button-primary'; ?>"
                    >
                        <?php echo esc_html( $is_configured ? __( 'Manage', 'tejcart' ) : __( 'Set up', 'tejcart' ) ); ?>
                    </a>
                </div>
            </td>
        </tr>
        <?php
    }

    private function render_status_cell( bool $is_configured, bool $is_enabled, bool $is_active, bool $is_available, bool $is_test_mode, bool $breaker ): void {
        if ( ! $is_configured ) {
            echo '<span class="tejcart-tax-provider-row__status-text">' .
                esc_html__( 'Add credentials to start calculating live tax.', 'tejcart' ) .
                '</span>';
            return;
        }

        echo '<div class="tejcart-tax-provider-row__status-line">';

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

        $state_label = $this->state_label( $is_active, $is_available, $is_enabled );
        echo '<span class="tejcart-tax-provider-row__status-text">' . esc_html( $state_label ) . '</span>';

        echo '</div>';
    }

    private function state_label( bool $is_active, bool $is_available, bool $is_enabled ): string {
        if ( $is_active && $is_available ) {
            return __( 'Calculating live tax for every checkout.', 'tejcart' );
        }
        if ( $is_active ) {
            return __( 'Selected as active but disabled — cart is using manual rates.', 'tejcart' );
        }
        if ( $is_enabled ) {
            return __( 'Configured and enabled. Click "Make active" to start calculating.', 'tejcart' );
        }
        return __( 'Configured but paused. Flip the toggle to enable it.', 'tejcart' );
    }

    private function initial_for( string $label ): string {
        $trimmed = trim( $label );
        if ( '' === $trimmed ) {
            return '?';
        }
        return mb_strtoupper( mb_substr( $trimmed, 0, 1 ) );
    }
}
