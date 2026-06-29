<?php
/**
 * Admin page for managing shipping zones, methods, and costs.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Shipping\Shipping_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders and processes the Shipping Zones admin page.
 *
 * Lets store admins create multiple shipping zones, attach
 * any number of shipping methods (Flat Rate, Free Shipping,
 * Local Pickup) to each zone, and configure their costs.
 */
class Shipping_Zones_Table {
    /**
     * Shipping manager instance.
     *
     * @var Shipping_Manager
     */
    private $manager;

    /**
     * Built-in method type id → label map. Anything beyond this list
     * (carrier-driven methods registered by the bundled shipping module
     * or third-party add-ons) is discovered via the
     * `tejcart_shipping_method_classes` filter at render/save time.
     */
    private const BUILT_IN_METHOD_TYPES = array(
        'flat_rate'     => 'Flat Rate',
        'free_shipping' => 'Free Shipping',
        'local_pickup'  => 'Local Pickup',
        'weight_based'  => 'Weight-Based',
    );

    /**
     * Method-type → label map for UI rendering. Used by the admin-pages
     * enqueue (Admin::enqueue_assets) so the "Add method row" button on
     * the shipping-zone editor can render fresh selects without an
     * inline `<script>` payload, and by the form/list renderers below.
     *
     * Discovers carrier-driven methods (e.g. `carrier_shiprocket`,
     * `carrier_fedex`) by reading the `tejcart_shipping_method_classes`
     * filter — the same map core's Shipping_Manager consults — so any
     * method registered through that filter automatically becomes
     * selectable in a zone. Friendly labels are negotiated through
     * `tejcart_shipping_zone_method_type_labels`, letting the shipping
     * module supply the carrier's brand name (e.g. "Shiprocket") in
     * place of the raw `carrier_<id>` slug.
     *
     * @return array<string,string>
     */
    public static function method_type_labels(): array {
        // Inline literal __() calls (one per built-in method) so the labels
        // are extractable into the POT — a __( $variable ) loop is invisible
        // to the i18n scanner. Translations still run at call time, not at
        // class-load time, because this method is invoked per request.
        $built_in = array(
            'flat_rate'     => __( 'Flat Rate', 'tejcart' ),
            'free_shipping' => __( 'Free Shipping', 'tejcart' ),
            'local_pickup'  => __( 'Local Pickup', 'tejcart' ),
            'weight_based'  => __( 'Weight-Based', 'tejcart' ),
        );

        $classes = apply_filters( 'tejcart_shipping_method_classes', $built_in );
        if ( ! is_array( $classes ) ) {
            $classes = $built_in;
        }

        $labels = $built_in;
        foreach ( array_keys( $classes ) as $id ) {
            $id = (string) $id;
            if ( '' === $id || isset( $labels[ $id ] ) ) {
                continue;
            }
            $labels[ $id ] = self::humanise_method_id( $id );
        }

        $filtered = apply_filters( 'tejcart_shipping_zone_method_type_labels', $labels );
        return is_array( $filtered ) ? $filtered : $labels;
    }

    /**
     * Best-effort label derived from the method id when no friendly
     * label has been registered. Strips a `carrier_` prefix and
     * title-cases the remainder so "carrier_shiprocket" → "Shiprocket".
     */
    private static function humanise_method_id( string $id ): string {
        $base = $id;
        if ( 0 === strpos( $base, 'carrier_' ) ) {
            $base = substr( $base, strlen( 'carrier_' ) );
        }
        $base = trim( str_replace( array( '_', '-' ), ' ', $base ) );
        return '' === $base ? $id : ucwords( $base );
    }

    /**
     * Convenience wrapper used by the form / list / save handlers so
     * they all see the same filter-resolved map within one request.
     *
     * @return array<string,string>
     */
    private function method_types(): array {
        return self::method_type_labels();
    }

    /**
     * True when the method id refers to a carrier-driven method
     * (registered by the bundled shipping module). Carrier rows skip
     * the cost / min_amount / weight-bracket columns because their
     * rates are quoted live from the carrier API.
     */
    private static function is_carrier_method( string $id ): bool {
        return 0 === strpos( $id, 'carrier_' );
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->manager = new Shipping_Manager();
    }

    /**
     * Register the admin submenu page.
     */
    public function register_page() {
        add_submenu_page(
            'tejcart',
            __( 'Shipping Zones', 'tejcart' ),
            __( 'Shipping Zones', 'tejcart' ),
            'manage_options',
            'tejcart-shipping-zones',
            array( $this, 'render_page' )
        );
    }

    /**
     * Process add/update/delete form submissions.
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $is_shipping_page = ( 'tejcart-shipping-zones' === $page ) || ( 'tejcart-settings' === $page && 'shipping' === $tab );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $is_shipping_page
            && isset( $_GET['action'], $_GET['zone_id'], $_GET['_wpnonce'] )
            && 'delete_zone' === $_GET['action'] ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_delete_zone' ) ) {
                $this->manager->delete_zone( (int) wp_unslash( $_GET['zone_id'] ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&deleted=1' ) );
            exit;
        }

        if ( isset( $_POST['tejcart_shipping_zone_action'] )
            && check_admin_referer( 'tejcart_save_shipping_zone', 'tejcart_shipping_zone_nonce' ) ) {
            $zone_id   = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
            $name      = isset( $_POST['zone_name'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_name'] ) ) : '';
            $countries = isset( $_POST['zone_countries'] ) && is_array( $_POST['zone_countries'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zone_countries'] ) )
                : array();

            $postcodes = array();
            if ( isset( $_POST['zone_postcodes'] ) ) {
                $raw_pc   = sanitize_textarea_field( wp_unslash( $_POST['zone_postcodes'] ) );
                $lines    = preg_split( '/\r\n|\r|\n/', $raw_pc );
                $postcodes = array_filter( array_map( 'trim', (array) $lines ) );
            }

            $method_types = $this->method_types();
            $methods      = array();
            if ( isset( $_POST['methods'] ) && is_array( $_POST['methods'] ) ) {
                // Each row's fields are sanitized individually inside the loop below.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                foreach ( wp_unslash( $_POST['methods'] ) as $row ) {
                    $type = isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '';
                    if ( ! isset( $method_types[ $type ] ) ) {
                        continue;
                    }

                    if ( self::is_carrier_method( $type ) ) {
                        // Carrier-driven methods quote live; cost / min_amount /
                        // weight-bracket inputs do not apply. An optional
                        // service_code can pin the row to a specific carrier
                        // service (Plugin::fan_out_carrier_services skips
                        // fan-out when set).
                        $settings = array();
                        if ( isset( $row['service_code'] ) ) {
                            $service_code = sanitize_text_field( (string) $row['service_code'] );
                            if ( '' !== $service_code ) {
                                $settings['service_code'] = $service_code;
                            }
                        }
                    } else {
                        $settings = array(
                            'cost'       => isset( $row['cost'] ) ? (float) $row['cost'] : 0.0,
                            'min_amount' => isset( $row['min_amount'] ) ? (float) $row['min_amount'] : 0.0,
                        );

                        if ( 'weight_based' === $type && isset( $row['rates_text'] ) ) {
                            $rates     = array();
                            $rates_raw = sanitize_textarea_field( (string) $row['rates_text'] );
                            $lines     = preg_split( '/\r\n|\r|\n/', $rates_raw );
                            foreach ( (array) $lines as $line ) {
                                $line = trim( $line );
                                if ( '' === $line ) {
                                    continue;
                                }
                                $parts = array_map( 'trim', explode( '|', $line ) );
                                if ( count( $parts ) < 3 ) {
                                    continue;
                                }
                                $rates[] = array(
                                    'weight_from' => (float) $parts[0],
                                    'weight_to'   => (float) $parts[1],
                                    'cost'        => (float) $parts[2],
                                );
                            }
                            $settings['rates'] = $rates;
                        }
                    }

                    $methods[] = array(
                        'id'       => $type,
                        'title'    => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $method_types[ $type ],
                        'settings' => $settings,
                    );
                }
            }

            $data = array(
                'name'      => $name,
                'countries' => $countries,
                'postcodes' => $postcodes,
                'methods'   => $methods,
            );

            if ( $zone_id > 0 ) {
                $this->manager->update_zone( $zone_id, $data );
                $redirect = admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&updated=1' );
            } else {
                $this->manager->add_zone( $data );
                $redirect = admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&added=1' );
            }

            wp_safe_redirect( $redirect );
            exit;
        }
    }

    /**
     * Render the admin page (list view or edit form).
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header so the body can be composed
     *                      inside another admin screen (Settings → Shipping).
     */
    public function render_page( $embedded = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $zone_id = isset( $_GET['zone_id'] ) ? (int) $_GET['zone_id'] : 0;

        $is_form = ( 'edit' === $action || 'add' === $action );

        if ( ! $embedded ) {
            echo '<div class="wrap tejcart-admin-wrap">';
            echo '<div class="tejcart-page-header"><div class="tejcart-page-header-content">';

            if ( $is_form ) {
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones' ) ) . '" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> ' . esc_html__( 'Back to Zones', 'tejcart' ) . '</a>';
                echo '<h1>' . ( 'edit' === $action ? esc_html__( 'Edit Shipping Zone', 'tejcart' ) : esc_html__( 'Add Shipping Zone', 'tejcart' ) ) . '</h1>';
                echo '<p class="tejcart-page-subtitle">' . esc_html__( 'Select the regions and the shipping methods customers in this zone can choose from.', 'tejcart' ) . '</p>';
                echo '</div></div>';
            } else {
                echo '<h1>' . esc_html__( 'Shipping Zones', 'tejcart' ) . '</h1>';
                echo '<p class="tejcart-page-subtitle">' . esc_html__( 'Assign shipping methods and costs to different regions of the world.', 'tejcart' ) . '</p>';
                echo '</div>';
                echo '<div class="tejcart-page-header-actions">';
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&action=add' ) ) . '" class="button button-primary"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__( 'Add Zone', 'tejcart' ) . '</a>';
                echo '</div>';
                echo '</div>';
            }
        } elseif ( ! $is_form ) {
            echo '<div class="tejcart-page-header-actions tejcart-embedded-actions">';
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&action=add' ) ) . '" class="button button-primary"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__( 'Add Zone', 'tejcart' ) . '</a>';
            echo '</div>';
        } else {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones' ) ) . '" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> ' . esc_html__( 'Back to Zones', 'tejcart' ) . '</a>';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['added'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shipping zone added.', 'tejcart' ) . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shipping zone updated.', 'tejcart' ) . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shipping zone deleted.', 'tejcart' ) . '</p></div>';
        }

        $this->render_disabled_carrier_notice();

        if ( $is_form ) {
            $zone = $zone_id > 0 ? $this->manager->get_zone( $zone_id ) : null;
            $this->render_form( $zone );
        } else {
            $this->render_list();
            $this->render_class_fees_card();
        }

        if ( ! $embedded ) {
            echo '</div>';
        }
    }

    /**
     * Render the per-shipping-class surcharge editor below the zones list.
     *
     * Each row stores an extra rate added on top of the zone's base shipping
     * for every cart line whose product carries that class. Persisted as a
     * JSON-encoded { slug: amount } map in option `tejcart_shipping_class_fees`,
     * which Cart_Calculator already reads.
     */
    private function render_class_fees_card(): void {
        if ( isset( $_POST['tejcart_shipping_class_fees_action'] )
            && 'save' === $_POST['tejcart_shipping_class_fees_action']
            && current_user_can( 'manage_options' )
            && check_admin_referer( 'tejcart_save_shipping_class_fees' )
        ) {
            // Nonce verified via check_admin_referer() above. Each element is
            // sanitized in the loop below (sanitize_key on slugs, float cast on amounts).
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $slugs   = isset( $_POST['tejcart_class_slug'] ) ? (array) wp_unslash( $_POST['tejcart_class_slug'] ) : array();
            $amounts = isset( $_POST['tejcart_class_amount'] ) ? (array) wp_unslash( $_POST['tejcart_class_amount'] ) : array();
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            $fees = array();
            foreach ( $slugs as $i => $slug ) {
                $slug   = sanitize_key( (string) $slug );
                $amount = isset( $amounts[ $i ] ) ? (float) $amounts[ $i ] : 0.0;
                if ( '' !== $slug && $amount > 0 ) {
                    $fees[ $slug ] = $amount;
                }
            }

            update_option( 'tejcart_shipping_class_fees', wp_json_encode( $fees ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shipping class fees saved.', 'tejcart' ) . '</p></div>';
        }

        $stored = get_option( 'tejcart_shipping_class_fees', array() );
        if ( is_string( $stored ) ) {
            $stored = json_decode( $stored, true );
        }
        $fees = is_array( $stored ) ? $stored : array();

        $taxonomy = '\\TejCart\\Product\\Product_Taxonomy';
        $terms    = array();
        if ( class_exists( $taxonomy ) ) {
            $maybe_terms = get_terms( array(
                'taxonomy'   => $taxonomy::SHIPPING_CLASS_TAXONOMY,
                'hide_empty' => false,
            ) );
            if ( ! is_wp_error( $maybe_terms ) && is_array( $maybe_terms ) ) {
                $terms = $maybe_terms;
            }
        }

        $currency = tejcart_get_currency_symbol();

        ?>
        <div class="tejcart-card" style="margin-top:24px;">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'Per-class shipping fees', 'tejcart' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Add an extra charge per unit for each product carrying the matching shipping class. Applied on top of the zone’s base rate.', 'tejcart' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy::SHIPPING_CLASS_TAXONOMY ) ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'Manage classes', 'tejcart' ); ?>
                    </a>
                </p>
            </div>
            <div class="tejcart-card-body">
                <?php if ( empty( $terms ) ) : ?>
                    <p>
                        <em><?php esc_html_e( 'No shipping classes have been created yet. Create one first, then return here to set its fee.', 'tejcart' ); ?></em>
                    </p>
                <?php else : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'tejcart_save_shipping_class_fees' ); ?>
                        <input type="hidden" name="tejcart_shipping_class_fees_action" value="save" />
                        <table class="widefat striped" style="max-width:640px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Class', 'tejcart' ); ?></th>
                                    <th style="width:60%;">
                                        <?php
                                        /* translators: %s: currency symbol */
                                        printf( esc_html__( 'Extra fee per unit (%s)', 'tejcart' ), esc_html( $currency ) );
                                        ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $terms as $term ) :
                                    $value = isset( $fees[ $term->slug ] ) ? (float) $fees[ $term->slug ] : 0.0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $term->name ); ?></strong>
                                            <code style="margin-left:6px;color:#6b7280;"><?php echo esc_html( $term->slug ); ?></code>
                                            <input type="hidden" name="tejcart_class_slug[]" value="<?php echo esc_attr( $term->slug ); ?>" />
                                        </td>
                                        <td>
                                            <input type="number" name="tejcart_class_amount[]"
                                                   value="<?php echo esc_attr( $value > 0 ? $value : '' ); ?>"
                                                   step="0.01" min="0" placeholder="0.00"
                                                   class="small-text" />
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="margin-top:14px;">
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( 'Save fees', 'tejcart' ); ?>
                            </button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the zones list table.
     */
    private function render_list() {
        $zones        = $this->manager->get_zones();
        $method_types = $this->method_types();

        echo '<div class="tejcart-card">';
        echo '<div class="tejcart-card-header"><h3><span class="dashicons dashicons-airplane"></span> ' . esc_html__( 'Shipping Zones', 'tejcart' ) . '</h3></div>';

        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Zone Name', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Regions', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Shipping Methods', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $zones ) ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No shipping zones configured yet. Click "Add Zone" to create your first zone.', 'tejcart' ) . '</td></tr>';
        }

        foreach ( $zones as $zone ) {
            $edit_url = admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&action=edit&zone_id=' . (int) $zone['id'] );
            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones&action=delete_zone&zone_id=' . (int) $zone['id'] ),
                'tejcart_delete_zone'
            );

            $method_summary = array();
            if ( ! empty( $zone['methods'] ) ) {
                foreach ( $zone['methods'] as $m ) {
                    $label = isset( $method_types[ $m['id'] ] ) ? $method_types[ $m['id'] ] : self::humanise_method_id( (string) $m['id'] );
                    $cost  = isset( $m['settings']['cost'] ) ? (float) $m['settings']['cost'] : 0.0;
                    $method_summary[] = $label . ( 'flat_rate' === $m['id'] ? ' (' . number_format( $cost, 2 ) . ')' : '' );
                }
            }

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $zone['name'] ) . '</a></strong></td>';
            echo '<td>' . esc_html( implode( ', ', (array) $zone['countries'] ) ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', $method_summary ) ) . '</td>';
            echo '<td><div class="tejcart-row-actions">';
            echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'tejcart' ) . '</a>';
            echo '<span class="tejcart-separator">|</span>';
            echo '<a href="' . esc_url( $delete_url ) . '" class="tejcart-row-action-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this zone?', 'tejcart' ) ) . '\');">' . esc_html__( 'Delete', 'tejcart' ) . '</a>';
            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Render the add/edit zone form.
     *
     * @param array|null $zone Zone data when editing, null when adding.
     */
    private function render_form( $zone ) {
        $is_edit   = is_array( $zone );
        $name      = $is_edit ? $zone['name'] : '';
        $entries   = $is_edit && ! empty( $zone['countries'] ) ? (array) $zone['countries'] : array();
        $postcodes = $is_edit && ! empty( $zone['postcodes'] ) ? implode( "\n", (array) $zone['postcodes'] ) : '';
        $methods   = $is_edit && ! empty( $zone['methods'] ) ? $zone['methods'] : array();
        $zone_id   = $is_edit ? (int) $zone['id'] : 0;

        echo '<form method="post" id="tejcart-shipping-zone-form">';
        wp_nonce_field( 'tejcart_save_shipping_zone', 'tejcart_shipping_zone_nonce' );
        echo '<input type="hidden" name="tejcart_shipping_zone_action" value="save" />';
        echo '<input type="hidden" name="zone_id" value="' . esc_attr( $zone_id ) . '" />';

        echo '<div class="tejcart-form-section">';
        echo '<div class="tejcart-form-section-header">';
        echo '<h2><span class="dashicons dashicons-location"></span> ' . esc_html__( 'Zone Details', 'tejcart' ) . '</h2>';
        echo '<p>' . esc_html__( 'Name and regions this zone covers.', 'tejcart' ) . '</p>';
        echo '</div>';
        echo '<div class="tejcart-form-section-body">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="zone_name">' . esc_html__( 'Zone Name', 'tejcart' ) . '<span class="tejcart-required" aria-hidden="true">*</span></label></th>';
        echo '<td><input type="text" id="zone_name" name="zone_name" class="regular-text" required value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'e.g. European Union', 'tejcart' ) . '" />';
        echo '<p class="description">' . esc_html__( 'Internal name used to identify this zone.', 'tejcart' ) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="zone_region_search">' . esc_html__( 'Countries & States', 'tejcart' ) . '</label></th>';
        echo '<td>';
        $this->render_region_picker( $entries );
        echo '<p class="description">' . esc_html__( 'Search for a country to add it. After picking a country with states or provinces, the dropdown shows those regions so you can target just a state (e.g. only California within the United States).', 'tejcart' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="zone_postcodes">' . esc_html__( 'Postcodes / ZIPs', 'tejcart' ) . '</label></th>';
        echo '<td><textarea id="zone_postcodes" name="zone_postcodes" rows="4" class="large-text code" placeholder="' . esc_attr__( "e.g.\n90210\n902*\n90210...90299", 'tejcart' ) . '">' . esc_textarea( $postcodes ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Optional. One rule per line. Supports exact matches, wildcards (902*) and ranges (90210...90299). Leave blank to accept any postcode in the zone.', 'tejcart' ) . '</p></td></tr>';

        echo '</tbody></table>';
        echo '</div></div>';

        echo '<div class="tejcart-form-section">';
        echo '<div class="tejcart-form-section-header">';
        echo '<h2><span class="dashicons dashicons-airplane"></span> ' . esc_html__( 'Shipping Methods', 'tejcart' ) . '</h2>';
        echo '<p>' . esc_html__( 'Add one or more methods customers in this zone can choose from.', 'tejcart' ) . '</p>';
        echo '</div>';
        echo '<div class="tejcart-form-section-body">';

        echo '<table class="wp-list-table widefat striped" id="tejcart-methods-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Method Type', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Title', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Cost', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Min Amount (Free)', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Weight Brackets', 'tejcart' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        $row_index = 0;
        foreach ( $methods as $m ) {
            $this->render_method_row( $row_index++, $m );
        }

        $this->render_method_row( $row_index, null );

        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="tejcart-add-method-row"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__( 'Add Method', 'tejcart' ) . '</button></p>';

        echo '</div></div>';

        echo '<div class="tejcart-form-footer">';
        submit_button( $is_edit ? __( 'Update Zone', 'tejcart' ) : __( 'Create Zone', 'tejcart' ), 'primary', 'submit', false );
        echo '</div>';
        echo '</form>';
    }

    /**
     * Render the searchable country/state picker that replaces the old
     * "type your ISO codes" text input. Each previously-saved entry is
     * rendered as a removable chip with its own hidden `zone_countries[]`
     * input, so the form still submits a clean array of codes (e.g.
     * `["US", "CA:ON", "GB"]`) — matching the format the save handler
     * and `Shipping_Manager::get_zone_for_address()` already consume.
     *
     * The dropdown is hydrated client-side from
     * `tejcart_admin_pages.shippingZones.regions` (countries + states),
     * so this PHP renderer only needs to seed the existing selections.
     *
     * @param array<int,string> $entries Previously-saved entries (country or "country:state").
     */
    private function render_region_picker( array $entries ): void {
        echo '<div class="tejcart-region-picker" id="tejcart-region-picker" data-tejcart-region-picker>';
        echo '<div class="tejcart-region-picker-control">';
        echo '<div class="tejcart-region-picker-chips" data-region-chips>';
        foreach ( $entries as $entry ) {
            $entry = trim( (string) $entry );
            if ( '' === $entry ) {
                continue;
            }
            $this->render_region_chip( $entry );
        }
        echo '</div>';
        echo '<input type="text" id="zone_region_search" class="tejcart-region-picker-input" data-region-input placeholder="' . esc_attr__( 'Search countries or states…', 'tejcart' ) . '" autocomplete="off" />';
        echo '</div>';
        echo '<div class="tejcart-region-picker-dropdown" data-region-dropdown role="listbox" aria-label="' . esc_attr__( 'Country and state suggestions', 'tejcart' ) . '" hidden></div>';
        echo '</div>';
    }

    /**
     * Render a single selected-region chip plus its hidden form input.
     * Used both at first paint (from saved data) and by the JS picker
     * via a matching `<template>`-less DOM build; keeping both code
     * paths shaped identically means a `View Source` after save looks
     * the same as the JS-built markup, which makes the picker easy to
     * debug.
     *
     * @param string $entry Either an ISO-3166 alpha-2 country code
     *                      ("GB") or a `country:state` pair ("US:CA").
     */
    private function render_region_chip( string $entry ): void {
        $label = $this->region_entry_label( $entry );
        echo '<span class="tejcart-region-chip" data-region-chip data-region-value="' . esc_attr( $entry ) . '">';
        echo '<span class="tejcart-region-chip-label">' . esc_html( $label ) . '</span>';
        echo '<button type="button" class="tejcart-region-chip-x" data-region-remove aria-label="' . esc_attr( sprintf(
            /* translators: %s: country or state name */
            __( 'Remove %s', 'tejcart' ),
            $label
        ) ) . '">&times;</button>';
        echo '<input type="hidden" name="zone_countries[]" value="' . esc_attr( $entry ) . '" />';
        echo '</span>';
    }

    /**
     * Resolve a stored entry to a human label. Falls back to the raw
     * code when the dataset doesn't know the value (e.g. a custom
     * pseudo-region added by a filter) so the chip still renders
     * something the merchant can recognise instead of vanishing.
     */
    private function region_entry_label( string $entry ): string {
        $countries = \TejCart\Tax\Tax_Manager::get_countries();

        if ( false !== strpos( $entry, ':' ) ) {
            [ $country_code, $state_code ] = array_pad( explode( ':', $entry, 2 ), 2, '' );
            $country_code = strtoupper( $country_code );
            $state_code   = strtoupper( $state_code );
            $country_name = $countries[ $country_code ] ?? $country_code;
            $states       = \TejCart\Tax\Tax_Manager::get_states( $country_code );
            $state_name   = isset( $states[ $state_code ] ) ? (string) $states[ $state_code ] : $state_code;
            return $country_name . ' — ' . $state_name;
        }

        $code = strtoupper( $entry );
        return $countries[ $code ] ?? $code;
    }

    /**
     * Render a single method config row inside the form.
     *
     * @param int        $index  Row index.
     * @param array|null $method Existing method config.
     */
    private function render_method_row( $index, $method ) {
        $type         = $method && isset( $method['id'] ) ? (string) $method['id'] : 'flat_rate';
        $title        = $method && isset( $method['title'] ) ? $method['title'] : '';
        $cost         = $method && isset( $method['settings']['cost'] ) ? (float) $method['settings']['cost'] : 0;
        $min_amount   = $method && isset( $method['settings']['min_amount'] ) ? (float) $method['settings']['min_amount'] : 0;
        $rates        = $method && isset( $method['settings']['rates'] ) && is_array( $method['settings']['rates'] ) ? $method['settings']['rates'] : array();
        $service_code = $method && isset( $method['settings']['service_code'] ) ? (string) $method['settings']['service_code'] : '';
        $rates_text   = '';
        foreach ( $rates as $r ) {
            $rates_text .= ( isset( $r['weight_from'] ) ? $r['weight_from'] : 0 ) . '|' .
                           ( isset( $r['weight_to'] ) ? $r['weight_to'] : 0 ) . '|' .
                           ( isset( $r['cost'] ) ? $r['cost'] : 0 ) . "\n";
        }

        $method_types = $this->method_types();
        // A carrier_<id> saved into a zone but missing from the resolved
        // label map means the merchant disabled (or uninstalled) the
        // driver after saving. We must NOT silently drop the selection
        // (that would overwrite live-rate config with a default
        // flat_rate on next save); instead, surface the disabled state
        // so the merchant can re-enable the carrier or remove the row.
        $is_disabled_carrier = self::is_carrier_method( $type ) && ! isset( $method_types[ $type ] );
        if ( '' !== $type && ! isset( $method_types[ $type ] ) ) {
            $base_label = self::humanise_method_id( $type );
            $method_types[ $type ] = $is_disabled_carrier
                /* translators: %s: carrier brand name. */
                ? sprintf( __( '%s — disabled', 'tejcart' ), $base_label )
                : $base_label;
        }

        $is_carrier = self::is_carrier_method( $type );

        $row_classes = array( 'tejcart-shipping-method-row' );
        if ( $is_carrier ) {
            $row_classes[] = 'is-carrier-row';
        }
        if ( $is_disabled_carrier ) {
            $row_classes[] = 'is-disabled-carrier';
        }

        echo '<tr class="' . esc_attr( implode( ' ', $row_classes ) ) . '">';
        echo '<td><select name="methods[' . (int) $index . '][type]" class="tejcart-shipping-method-type">';
        foreach ( $method_types as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td>';
        echo '<td><input type="text" name="methods[' . (int) $index . '][title]" value="' . esc_attr( $title ) . '" /></td>';

        if ( $is_carrier ) {
            // Hidden zero-value submits keep the row shape stable when
            // the merchant flips back to a built-in method type via JS.
            echo '<td colspan="3" class="tejcart-carrier-row__live">';
            echo '<input type="hidden" name="methods[' . (int) $index . '][cost]" value="0" />';
            echo '<input type="hidden" name="methods[' . (int) $index . '][min_amount]" value="0" />';
            echo '<label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">'
                . esc_html__( 'Service code (optional)', 'tejcart' )
                . '</label>';
            echo '<input type="text" name="methods[' . (int) $index . '][service_code]" value="' . esc_attr( $service_code ) . '" placeholder="' . esc_attr__( 'Leave blank to fan out into all carrier services', 'tejcart' ) . '" style="width:100%;max-width:320px;" />';
            echo '<p class="description" style="margin-top:6px;">'
                . esc_html__( 'Live rates are fetched from the carrier at checkout. Cost, min amount and weight brackets do not apply.', 'tejcart' )
                . '</p>';
            echo '</td>';
        } else {
            echo '<td><input type="number" step="0.01" name="methods[' . (int) $index . '][cost]" value="' . esc_attr( $cost ) . '" /></td>';
            echo '<td><input type="number" step="0.01" name="methods[' . (int) $index . '][min_amount]" value="' . esc_attr( $min_amount ) . '" /></td>';
            echo '<td><textarea name="methods[' . (int) $index . '][rates_text]" rows="3" placeholder="from|to|cost">' . esc_textarea( trim( $rates_text ) ) . '</textarea><br /><small>' . esc_html__( 'One bracket per line. Use 0 for unlimited "to".', 'tejcart' ) . '</small></td>';
        }

        echo '<td><a href="#" class="tejcart-remove-row button-link-danger">' . esc_html__( 'Remove', 'tejcart' ) . '</a></td>';
        echo '</tr>';

        if ( $is_disabled_carrier ) {
            $carriers_url = admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=carriers' );
            echo '<tr class="tejcart-shipping-method-row__notice"><td colspan="6">';
            echo '<div class="notice notice-warning inline" style="margin:0;padding:8px 12px;">';
            echo '<p style="margin:0;">';
            printf(
                /* translators: 1: carrier brand name, 2: opening anchor tag, 3: closing anchor tag */
                esc_html__( '%1$s is currently paused, so this row will not quote rates at checkout. %2$sRe-enable the carrier%3$s or remove this row to stop seeing this warning.', 'tejcart' ),
                '<strong>' . esc_html( self::humanise_method_id( $type ) ) . '</strong>',
                '<a href="' . esc_url( $carriers_url ) . '">',
                '</a>'
            );
            echo '</p></div>';
            echo '</td></tr>';
        }
    }

    /**
     * Render a top-of-page admin notice when any zone references a
     * disabled (or de-registered) carrier method. Surfaces the silent
     * failure mode where a merchant pauses a carrier in
     * `Settings → Shipping → Carriers` but doesn't realise a zone is
     * still pointing at it.
     *
     * Detection mirrors {@see render_method_row()}: a `carrier_<id>`
     * method whose key is missing from the filter-resolved labels map
     * is treated as disabled. Aggregates by carrier id so the notice
     * is concise even when several zones share the same carrier.
     */
    private function render_disabled_carrier_notice(): void {
        $zones = $this->manager->get_zones();
        if ( empty( $zones ) ) {
            return;
        }

        $method_types = $this->method_types();
        /** @var array<string,array{label:string,zones:array<int,string>}> $affected */
        $affected     = array();

        foreach ( $zones as $zone ) {
            $zone_name = isset( $zone['name'] ) ? (string) $zone['name'] : '';
            $methods   = isset( $zone['methods'] ) && is_array( $zone['methods'] ) ? $zone['methods'] : array();
            foreach ( $methods as $method ) {
                $id = isset( $method['id'] ) ? (string) $method['id'] : '';
                if ( '' === $id || ! self::is_carrier_method( $id ) || isset( $method_types[ $id ] ) ) {
                    continue;
                }
                if ( ! isset( $affected[ $id ] ) ) {
                    $affected[ $id ] = array(
                        'label' => self::humanise_method_id( $id ),
                        'zones' => array(),
                    );
                }
                if ( '' !== $zone_name && ! in_array( $zone_name, $affected[ $id ]['zones'], true ) ) {
                    $affected[ $id ]['zones'][] = $zone_name;
                }
            }
        }

        if ( array() === $affected ) {
            return;
        }

        $carriers_url = admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=carriers' );
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>' . esc_html__( 'Some shipping zones reference paused carriers.', 'tejcart' ) . '</strong> ';
        echo esc_html__( 'These zones will not quote rates at checkout until the carrier is re-enabled.', 'tejcart' );
        echo '</p><ul style="margin:6px 0 6px 24px;list-style:disc;">';
        foreach ( $affected as $entry ) {
            $zones_text = '' === implode( '', $entry['zones'] )
                ? __( 'unnamed zone', 'tejcart' )
                : implode( ', ', $entry['zones'] );
            printf(
                '<li>%s — <em>%s</em></li>',
                esc_html( $entry['label'] ),
                esc_html( $zones_text )
            );
        }
        echo '</ul><p>';
        printf(
            /* translators: 1: opening anchor tag, 2: closing anchor tag */
            esc_html__( '%1$sManage carriers%2$s to re-enable, or edit the listed zones to remove the orphaned rows.', 'tejcart' ),
            '<a href="' . esc_url( $carriers_url ) . '" class="button button-secondary">',
            '</a>'
        );
        echo '</p></div>';
    }
}
