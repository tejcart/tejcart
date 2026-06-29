<?php
/**
 * Admin page for managing per-country/state tax rates.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Tax\Tax_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders and processes the Tax Rates admin page.
 *
 * Displays a table of all configured tax rates and provides
 * a form to add new rates plus edit/delete links for existing ones.
 */
class Tax_Rates_Table {
    /**
     * Tax Manager instance.
     *
     * @var Tax_Manager
     */
    private $tax_manager;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->tax_manager = new Tax_Manager();
        ( new Tax_Rates_IO() )->init();
    }

    /**
     * Register the admin menu page.
     */
    public function register_page() {
        add_submenu_page(
            'tejcart',
            __( 'Tax Rates', 'tejcart' ),
            __( 'Tax Rates', 'tejcart' ),
            'manage_options',
            'tejcart-tax-rates',
            array( $this, 'render_page' )
        );
    }

    /**
     * Process form submissions (add, edit, delete).
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['action'], $_GET['rate_id'], $_GET['_wpnonce'] ) && 'delete_rate' === $_GET['action'] ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_delete_rate' ) ) {
                $this->tax_manager->delete_rate( (int) wp_unslash( $_GET['rate_id'] ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&deleted=1' ) );
            exit;
        }

        if ( isset( $_POST['tejcart_tax_rate_action'] ) && check_admin_referer( 'tejcart_save_tax_rate', 'tejcart_tax_rate_nonce' ) ) {
            $data = array(
                'country'  => isset( $_POST['tax_country'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_country'] ) ) : '',
                'state'    => isset( $_POST['tax_state'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_state'] ) ) : '',
                'rate'     => isset( $_POST['tax_rate'] ) ? (float) $_POST['tax_rate'] : 0.0,
                'name'     => isset( $_POST['tax_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_name'] ) ) : '',
                'priority' => isset( $_POST['tax_priority'] ) ? (int) $_POST['tax_priority'] : 1,
                'compound' => isset( $_POST['tax_compound'] ) ? 'yes' : 'no',
                'shipping' => isset( $_POST['tax_shipping'] ) ? 'yes' : 'no',
                'tax_class' => isset( $_POST['tax_class'] ) ? sanitize_text_field( wp_unslash( $_POST['tax_class'] ) ) : '',
            );

            if ( 'update' === $_POST['tejcart_tax_rate_action'] && ! empty( $_POST['tax_rate_id'] ) ) {
                $this->tax_manager->update_rate( (int) $_POST['tax_rate_id'], $data );
            } else {
                $this->tax_manager->add_rate( $data );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&saved=1' ) );
            exit;
        }
    }

    /**
     * Render the Tax Rates admin page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header so the body can be composed
     *                      inside another admin screen (e.g. Settings → Tax).
     */
    public function render_page( $embedded = false ) {
        $this->tax_manager = new Tax_Manager();
        $rates             = $this->tax_manager->get_rates();
        $countries         = Tax_Manager::get_countries();
        $tax_classes       = $this->tax_manager->get_tax_classes();

        $editing    = false;
        $edit_rate  = null;
        // Read-only edit-form prefill from query string; the actual rate save below has its own nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['action'], $_GET['rate_id'] ) && 'edit_rate' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
            $edit_id = (int) $_GET['rate_id'];
            foreach ( $rates as $r ) {
                if ( isset( $r['id'] ) && (int) $r['id'] === $edit_id ) {
                    $editing   = true;
                    $edit_rate = $r;
                    break;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Tax Rates', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Define per-country and per-state tax rates applied at checkout.', 'tejcart' ); ?></p>
                </div>
                <div class="tejcart-page-header-actions">
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&action=export_csv' ), 'tejcart_export_tax_rates' ) ); ?>">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'tejcart' ); ?>
                    </a>
                    <button type="button" class="button" onclick="document.getElementById('tejcart-tax-import-wrap').hidden = !document.getElementById('tejcart-tax-import-wrap').hidden;">
                        <span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import CSV', 'tejcart' ); ?>
                    </button>
                </div>
            </div>
        <?php else : ?>
            <div class="tejcart-page-header-actions tejcart-embedded-actions">
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&action=export_csv' ), 'tejcart_export_tax_rates' ) ); ?>">
                    <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'tejcart' ); ?>
                </a>
                <button type="button" class="button" onclick="document.getElementById('tejcart-tax-import-wrap').hidden = !document.getElementById('tejcart-tax-import-wrap').hidden;">
                    <span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import CSV', 'tejcart' ); ?>
                </button>
            </div>
        <?php endif; ?>

            <div id="tejcart-tax-import-wrap" class="tejcart-card" hidden>
                <div class="tejcart-card-header">
                    <h3><?php esc_html_e( 'Import tax rates from CSV', 'tejcart' ); ?></h3>
                </div>
                <form method="post" enctype="multipart/form-data" style="padding:16px;">
                    <?php wp_nonce_field( 'tejcart_import_tax_rates', 'tejcart_tax_rate_nonce' ); ?>
                    <input type="hidden" name="tejcart_tax_rate_action" value="import_csv" />
                    <p>
                        <label for="tax_rates_csv_file"><strong><?php esc_html_e( 'CSV file', 'tejcart' ); ?></strong></label><br>
                        <input type="file" id="tax_rates_csv_file" name="tax_rates_csv" accept=".csv" required />
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="import_mode" value="append" checked />
                            <?php esc_html_e( 'Append to existing rates', 'tejcart' ); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="import_mode" value="replace_all" onchange="return confirm('<?php echo esc_js( __( 'Replace ALL existing tax rates?', 'tejcart' ) ); ?>');" />
                            <?php esc_html_e( 'Replace all existing rates', 'tejcart' ); ?>
                        </label>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'tejcart' ); ?></button>
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Expected columns: country, state, postcodes, cities, rate, name, priority, compound, shipping, tax_class.', 'tejcart' ); ?>
                    </p>
                </form>
            </div>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $_GET['saved'] ) ) {
                \TejCart\Admin\Flash_Notice::render(
                    __( 'Tax rate saved.', 'tejcart' ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $_GET['deleted'] ) ) {
                \TejCart\Admin\Flash_Notice::render(
                    __( 'Tax rate deleted.', 'tejcart' ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            }
            $io_notice = get_transient( 'tejcart_tax_io_notice' );
            if ( is_array( $io_notice ) ) {
                delete_transient( 'tejcart_tax_io_notice' );
                $io_tone = 'error' === (string) ( $io_notice['type'] ?? '' )
                    ? \TejCart\Admin\Flash_Notice::TONE_ERROR
                    : \TejCart\Admin\Flash_Notice::TONE_SUCCESS;
                \TejCart\Admin\Flash_Notice::render(
                    (string) ( $io_notice['message'] ?? '' ),
                    '',
                    $io_tone
                );
            }
            ?>

            <div class="tejcart-card">
                <div class="tejcart-card-header">
                    <h3><span class="dashicons dashicons-chart-pie"></span> <?php esc_html_e( 'Configured Rates', 'tejcart' ); ?></h3>
                </div>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Country', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'State', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Rate %', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Tax Name', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Tax Class', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Priority', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Compound', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Shipping', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'tejcart' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rates ) ) : ?>
                            <tr>
                                <td colspan="9"><?php esc_html_e( 'No tax rates configured yet.', 'tejcart' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $rates as $rate ) : ?>
                                <tr>
                                    <td><?php echo esc_html( isset( $countries[ $rate['country'] ] ) ? $countries[ $rate['country'] ] : $rate['country'] ); ?></td>
                                    <td><?php echo esc_html( ! empty( $rate['state'] ) ? $rate['state'] : '*' ); ?></td>
                                    <td><?php echo esc_html( $rate['rate'] ); ?>%</td>
                                    <td><?php echo esc_html( $rate['name'] ); ?></td>
                                    <td><?php echo esc_html( ! empty( $rate['tax_class'] ) ? $rate['tax_class'] : esc_html__( 'Standard', 'tejcart' ) ); ?></td>
                                    <td><?php echo (int) $rate['priority']; ?></td>
                                    <td><?php echo 'yes' === $rate['compound'] ? esc_html__( 'Yes', 'tejcart' ) : esc_html__( 'No', 'tejcart' ); ?></td>
                                    <td><?php echo 'yes' === $rate['shipping'] ? esc_html__( 'Yes', 'tejcart' ) : esc_html__( 'No', 'tejcart' ); ?></td>
                                    <td>
                                        <div class="tejcart-row-actions">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&action=edit_rate&rate_id=' . (int) $rate['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'tejcart' ); ?></a>
                                            <span class="tejcart-separator">|</span>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates&action=delete_rate&rate_id=' . (int) $rate['id'] ), 'tejcart_delete_rate' ) ); ?>"
                                               class="tejcart-row-action-delete"
                                               onclick="return confirm('<?php esc_attr_e( 'Delete this tax rate?', 'tejcart' ); ?>');">
                                                <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="tejcart-form-section">
                <div class="tejcart-form-section-header">
                    <h2><span class="dashicons <?php echo $editing ? 'dashicons-edit' : 'dashicons-plus-alt2'; ?>"></span> <?php echo $editing ? esc_html__( 'Edit Tax Rate', 'tejcart' ) : esc_html__( 'Add New Tax Rate', 'tejcart' ); ?></h2>
                    <p><?php esc_html_e( 'Higher-priority rates override lower ones for the same region.', 'tejcart' ); ?></p>
                </div>
                <div class="tejcart-form-section-body">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates' ) ); ?>">
                        <?php wp_nonce_field( 'tejcart_save_tax_rate', 'tejcart_tax_rate_nonce' ); ?>

                        <input type="hidden" name="tejcart_tax_rate_action" value="<?php echo $editing ? 'update' : 'add'; ?>" />
                        <?php if ( $editing ) : ?>
                            <input type="hidden" name="tax_rate_id" value="<?php echo (int) $edit_rate['id']; ?>" />
                        <?php endif; ?>

                        <?php
                        $selected_country = $editing ? (string) $edit_rate['country'] : '';
                        $selected_state   = $editing ? (string) $edit_rate['state']   : '';
                        // Initial state options based on the rate's current
                        // country so the field renders correctly on first
                        // paint; the locale.js swapper rebuilds it whenever
                        // the country dropdown changes.
                        $initial_states = ( '' !== $selected_country && '*' !== $selected_country )
                            ? Tax_Manager::get_states( $selected_country )
                            : array();
                        ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="tax_country"><?php esc_html_e( 'Country', 'tejcart' ); ?></label></th>
                                <td>
                                    <select name="tax_country" id="tax_country" class="tejcart-country-select" data-tejcart-state-pair="tax_rate">
                                        <option value="*"><?php esc_html_e( 'All Countries (*)', 'tejcart' ); ?></option>
                                        <?php foreach ( $countries as $code => $name ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>"
                                                <?php selected( $selected_country, $code ); ?>>
                                                <?php echo esc_html( $name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Select the country this rate applies to.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tax_state"><?php esc_html_e( 'State / Region', 'tejcart' ); ?></label></th>
                                <td>
                                    <?php if ( ! empty( $initial_states ) ) : ?>
                                        <select name="tax_state" id="tax_state" class="regular-text" data-tejcart-state-pair="tax_rate">
                                            <option value=""><?php esc_html_e( '— Entire country —', 'tejcart' ); ?></option>
                                            <?php foreach ( $initial_states as $code => $name ) : ?>
                                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected_state, $code ); ?>>
                                                    <?php echo esc_html( $name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <input type="text" name="tax_state" id="tax_state" class="regular-text"
                                               value="<?php echo esc_attr( $selected_state ); ?>"
                                               placeholder="<?php esc_attr_e( 'Leave blank for entire country', 'tejcart' ); ?>"
                                               data-tejcart-state-pair="tax_rate" />
                                    <?php endif; ?>
                                    <p class="description"><?php esc_html_e( 'Pick a state/region or leave as “Entire country” for a country-wide rate. The list updates when you change the country.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tax_rate"><?php esc_html_e( 'Rate %', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" name="tax_rate" id="tax_rate" step="0.0001" min="0"
                                           value="<?php echo $editing ? esc_attr( $edit_rate['rate'] ) : '0'; ?>" />
                                    <p class="description"><?php esc_html_e( 'Percentage applied to taxable amounts (e.g. 8.25).', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tax_name"><?php esc_html_e( 'Tax Name', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="text" name="tax_name" id="tax_name" class="regular-text"
                                           value="<?php echo $editing ? esc_attr( $edit_rate['name'] ) : esc_attr__( 'Tax', 'tejcart' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Label displayed to customers (e.g. VAT, GST, Sales Tax).', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tax_class"><?php esc_html_e( 'Tax Class', 'tejcart' ); ?></label></th>
                                <td>
                                    <?php $selected_class = $editing && isset( $edit_rate['tax_class'] ) ? (string) $edit_rate['tax_class'] : ''; ?>
                                    <select name="tax_class" id="tax_class" class="regular-text">
                                        <option value="" <?php selected( '', $selected_class ); ?>><?php esc_html_e( 'Standard / All classes', 'tejcart' ); ?></option>
                                        <?php
                                        foreach ( $tax_classes as $tc ) :
                                            $tc_name = isset( $tc['name'] ) ? (string) $tc['name'] : '';
                                            if ( '' === $tc_name || 'Standard' === $tc_name ) {
                                                continue;
                                            }
                                            ?>
                                            <option value="<?php echo esc_attr( $tc_name ); ?>" <?php selected( $tc_name, $selected_class ); ?>>
                                                <?php echo esc_html( $tc_name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Apply this rate only to products in the selected tax class. “Standard / All classes” applies to products with no specific class.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tax_priority"><?php esc_html_e( 'Priority', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" name="tax_priority" id="tax_priority" step="1" min="1"
                                           value="<?php echo $editing ? (int) $edit_rate['priority'] : 1; ?>" />
                                    <p class="description"><?php esc_html_e( 'Only one matching rate per priority is used.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Compound', 'tejcart' ); ?></th>
                                <td>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" name="tax_compound" id="tax_compound" value="yes" <?php checked( $editing && 'yes' === $edit_rate['compound'] ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Apply this rate on top of other taxes', 'tejcart' ); ?></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Shipping', 'tejcart' ); ?></th>
                                <td>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" name="tax_shipping" id="tax_shipping" value="yes" <?php checked( ! $editing || 'yes' === $edit_rate['shipping'] ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Apply this rate to shipping costs', 'tejcart' ); ?></span>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <div class="tejcart-form-actions">
                            <?php submit_button( $editing ? __( 'Update Rate', 'tejcart' ) : __( 'Add Rate', 'tejcart' ), 'primary', 'submit', false ); ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
