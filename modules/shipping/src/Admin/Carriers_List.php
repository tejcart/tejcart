<?php
/**
 * Carriers list view.
 *
 * Renders the default Settings → Shipping → Carriers screen as a series
 * of region cards, each holding a table of carriers. The visual language
 * matches `TejCart\Admin\Payment_Methods_List` so a merchant who has
 * already configured a gateway recognises the affordances instantly:
 * brand mark + name on the left, status pill + enable toggle in the
 * middle, "Set up" / "Manage" call-to-action on the right.
 *
 * Click "Manage" / "Set up" to deep-link into
 * Carrier_Configure_Page::render() for a focused per-carrier form.
 *
 * @package TejCart\Shipping_Plugin\Admin
 */

namespace TejCart\Shipping_Plugin\Admin;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Registry;
use TejCart\Shipping_Plugin\Core\Credentials_Vault;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carriers_List {
    private Carrier_Registry $registry;
    private Credentials_Vault $vault;
    private Carrier_State $state;

    public function __construct( Carrier_Registry $registry, Credentials_Vault $vault, Carrier_State $state ) {
        $this->registry = $registry;
        $this->vault    = $vault;
        $this->state    = $state;
    }

    public function render(): void {
        $groups       = $this->registry->grouped_by_region();
        $toggle_nonce = wp_create_nonce( Settings_Page::TOGGLE_NONCE );
        $zone_usage   = self::zone_usage_by_driver();

        ?>
        <div class="tejcart-carriers-list-wrap" data-toggle-nonce="<?php echo esc_attr( $toggle_nonce ); ?>">
            <header class="tejcart-section-header tejcart-carriers-list__header">
                <div class="tejcart-carriers-list__header-text">
                    <h2 class="tejcart-section-title"><?php esc_html_e( 'Shipping carriers', 'tejcart' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Connect carrier accounts to fetch live rates, print labels, and sync tracking. Each carrier stays paused until you flip its toggle.', 'tejcart' ); ?>
                    </p>
                </div>
                <div class="tejcart-carriers-list__search">
                    <label for="tejcart-carriers-search" class="screen-reader-text">
                        <?php esc_html_e( 'Search carriers', 'tejcart' ); ?>
                    </label>
                    <input
                        type="search"
                        id="tejcart-carriers-search"
                        class="tejcart-carriers-list__search-input"
                        placeholder="<?php esc_attr_e( 'Search carriers…', 'tejcart' ); ?>"
                        autocomplete="off"
                    />
                </div>
            </header>

            <?php if ( array() === $groups ) : ?>
                <div class="tejcart-card">
                    <div class="tejcart-card-body">
                        <p><?php esc_html_e( 'No carrier drivers are registered yet.', 'tejcart' ); ?></p>
                    </div>
                </div>
            <?php else : ?>
                <?php foreach ( $groups as $region => $drivers ) : ?>
                    <section
                        class="tejcart-card tejcart-carriers-region"
                        data-region="<?php echo esc_attr( $region ); ?>"
                    >
                        <div class="tejcart-card-header">
                            <h2><?php echo esc_html( $this->region_label( $region ) ); ?></h2>
                            <span class="tejcart-carriers-region__count">
                                <?php
                                printf(
                                    /* translators: %d: number of carriers in the region. */
                                    esc_html( _n( '%d carrier', '%d carriers', count( $drivers ), 'tejcart' ) ),
                                    (int) count( $drivers )
                                );
                                ?>
                            </span>
                        </div>
                        <table class="tejcart-payments-table tejcart-carriers-table widefat">
                            <thead>
                                <tr>
                                    <th class="tejcart-payments-table__col-method" scope="col"><?php esc_html_e( 'Carrier', 'tejcart' ); ?></th>
                                    <th class="tejcart-payments-table__col-enabled" scope="col"><?php esc_html_e( 'Enabled', 'tejcart' ); ?></th>
                                    <th class="tejcart-payments-table__col-desc" scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                    <th class="tejcart-payments-table__col-action" scope="col">
                                        <span class="screen-reader-text"><?php esc_html_e( 'Configure', 'tejcart' ); ?></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $drivers as $driver ) : ?>
                                    <?php $this->render_carrier_row( $driver, $zone_usage ); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="tejcart-carriers-list__empty" hidden>
                <p><?php esc_html_e( 'No carriers match your search.', 'tejcart' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string,array<int,string>> $zone_usage Map of driver_id => list of zone names that reference it.
     */
    private function render_carrier_row( Abstract_Carrier_Driver $driver, array $zone_usage = array() ): void {
        $driver_id    = $driver->id();
        $credentials  = $this->vault->get( $driver_id );
        $is_connected = $this->has_any_credential( $credentials, $driver->credential_fields() );
        $is_enabled   = $is_connected && $this->state->is_enabled( $driver_id );
        $environment  = (string) ( $credentials['environment'] ?? '' );
        $zone_names   = isset( $zone_usage[ $driver_id ] ) && is_array( $zone_usage[ $driver_id ] )
            ? array_values( $zone_usage[ $driver_id ] )
            : array();
        $zone_count   = count( $zone_names );

        $row_classes = array( 'tejcart-payment-method-row', 'tejcart-carrier-row' );
        if ( $is_connected ) {
            $row_classes[] = 'is-connected';
        }
        if ( $is_enabled ) {
            $row_classes[] = 'is-enabled';
        }
        if ( $zone_count > 0 ) {
            $row_classes[] = 'has-zone-usage';
        }

        $configure_url = Carrier_Configure_Page::get_url( $driver_id );
        $toggle_id     = 'tejcart-carrier-toggle-' . sanitize_html_class( $driver_id );

        $search_blob = strtolower( $driver->label() . ' ' . $driver_id );

        ?>
        <tr
            class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
            data-carrier-id="<?php echo esc_attr( $driver_id ); ?>"
            data-search="<?php echo esc_attr( $search_blob ); ?>"
            data-zone-usage="<?php echo esc_attr( (string) $zone_count ); ?>"
            data-zone-names="<?php echo esc_attr( implode( ', ', $zone_names ) ); ?>"
            data-carrier-label="<?php echo esc_attr( $driver->label() ); ?>"
        >
            <td class="tejcart-payment-method-row__method" data-colname="<?php esc_attr_e( 'Carrier', 'tejcart' ); ?>">
                <div class="tejcart-payment-method-row__method-inner">
                    <span class="tejcart-payment-method-row__logo" aria-hidden="true">
                        <span class="tejcart-payment-method-row__initial">
                            <?php echo esc_html( $this->initial_for( $driver->label() ) ); ?>
                        </span>
                    </span>
                    <span class="tejcart-carrier-row__label">
                        <a href="<?php echo esc_url( $configure_url ); ?>" class="tejcart-payment-method-row__title">
                            <?php echo esc_html( $driver->label() ); ?>
                        </a>
                        <code class="tejcart-carrier-row__slug"><?php echo esc_html( $driver_id ); ?></code>
                    </span>
                </div>
            </td>
            <td class="tejcart-payment-method-row__enabled" data-colname="<?php esc_attr_e( 'Enabled', 'tejcart' ); ?>">
                <?php if ( ! $is_connected ) : ?>
                    <span class="tejcart-pill tejcart-pill--neutral">
                        <?php esc_html_e( 'Not connected', 'tejcart' ); ?>
                    </span>
                <?php else : ?>
                    <label class="tejcart-toggle tejcart-carrier-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr( $toggle_id ); ?>"
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
            </td>
            <td class="tejcart-payment-method-row__desc tejcart-carrier-row__status" data-colname="<?php esc_attr_e( 'Status', 'tejcart' ); ?>">
                <?php $this->render_status_cell( $is_connected, $is_enabled, $environment ); ?>
            </td>
            <td class="tejcart-payment-method-row__action" data-colname="<?php esc_attr_e( 'Configure', 'tejcart' ); ?>">
                <a
                    href="<?php echo esc_url( $configure_url ); ?>"
                    class="button <?php echo $is_connected ? 'button-secondary' : 'button-primary'; ?>"
                >
                    <?php echo esc_html( $is_connected ? __( 'Manage', 'tejcart' ) : __( 'Set up', 'tejcart' ) ); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    private function render_status_cell( bool $is_connected, bool $is_enabled, string $environment ): void {
        if ( ! $is_connected ) {
            echo '<span class="tejcart-carrier-row__status-text">' .
                esc_html__( 'Add credentials to start fetching live rates.', 'tejcart' ) .
                '</span>';
            return;
        }

        $env_label = '';
        $env_pill  = '';
        if ( 'live' === $environment || 'production' === $environment ) {
            $env_label = __( 'Live', 'tejcart' );
            $env_pill  = 'tejcart-pill--success';
        } elseif ( '' !== $environment ) {
            $env_label = __( 'Sandbox', 'tejcart' );
            $env_pill  = 'tejcart-pill--warning';
        }

        echo '<div class="tejcart-carrier-row__status-line">';

        if ( '' !== $env_label ) {
            printf(
                '<span class="tejcart-pill %1$s">%2$s</span>',
                esc_attr( $env_pill ),
                esc_html( $env_label )
            );
        }

        $state_label = $is_enabled
            ? __( 'Enabled — rates active at checkout.', 'tejcart' )
            : __( 'Configured but paused. Flip the toggle to start quoting rates.', 'tejcart' );

        echo '<span class="tejcart-carrier-row__status-text">' . esc_html( $state_label ) . '</span>';
        echo '</div>';
    }

    /**
     * @param array<string,string>          $credentials
     * @param array<string,array<string,mixed>> $fields
     */
    private function has_any_credential( array $credentials, array $fields ): bool {
        foreach ( $fields as $field_id => $schema ) {
            $type = (string) ( $schema['type'] ?? 'text' );
            if ( 'checkbox' === $type || 'select' === $type ) {
                // Pure preference fields don't prove a carrier is configured.
                continue;
            }
            if ( '' !== trim( (string) ( $credentials[ $field_id ] ?? '' ) ) ) {
                return true;
            }
        }
        return false;
    }

    private function initial_for( string $label ): string {
        $trimmed = trim( $label );
        if ( '' === $trimmed ) {
            return '?';
        }
        return mb_strtoupper( mb_substr( $trimmed, 0, 1 ) );
    }

    /**
     * Build a driver_id => [zone_name, ...] map by scanning the core
     * `tejcart_shipping_zones` option. Reads the option directly so the
     * shipping module doesn't take a hard dependency on core's
     * Shipping_Manager class loader. Returned in render order;
     * duplicates within a single zone are collapsed.
     *
     * @return array<string,array<int,string>>
     */
    public static function zone_usage_by_driver(): array {
        $raw = get_option( 'tejcart_shipping_zones', array() );
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw     = is_array( $decoded ) ? $decoded : array();
        }
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $out = array();
        foreach ( $raw as $zone ) {
            if ( ! is_array( $zone ) ) {
                continue;
            }
            $zone_name = isset( $zone['name'] ) ? (string) $zone['name'] : '';
            $methods   = isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? $zone['methods'] : array();
            foreach ( $methods as $method ) {
                if ( ! is_array( $method ) ) {
                    continue;
                }
                $id = isset( $method['id'] ) ? (string) $method['id'] : '';
                if ( 0 !== strpos( $id, 'carrier_' ) ) {
                    continue;
                }
                $driver_id = substr( $id, strlen( 'carrier_' ) );
                if ( ! isset( $out[ $driver_id ] ) ) {
                    $out[ $driver_id ] = array();
                }
                if ( '' !== $zone_name && ! in_array( $zone_name, $out[ $driver_id ], true ) ) {
                    $out[ $driver_id ][] = $zone_name;
                }
            }
        }

        return $out;
    }

    private function region_label( string $region ): string {
        $labels = array(
            'aggregator'    => __( 'Multi-carrier aggregators', 'tejcart' ),
            'global'        => __( 'Global carriers', 'tejcart' ),
            'north_america' => __( 'North America', 'tejcart' ),
            'europe'        => __( 'Europe', 'tejcart' ),
            'apac'          => __( 'Asia–Pacific', 'tejcart' ),
            'latam'         => __( 'Latin America', 'tejcart' ),
            'mea'           => __( 'Middle East & Africa', 'tejcart' ),
            'last_mile'     => __( 'Last-mile / same-day', 'tejcart' ),
            'lockers'       => __( 'Pickup-point networks', 'tejcart' ),
            'freight'       => __( 'Freight & LTL', 'tejcart' ),
        );
        return $labels[ $region ] ?? ucfirst( $region );
    }
}
