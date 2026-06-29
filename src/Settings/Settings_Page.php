<?php
/**
 * Settings page controller.
 *
 * @package TejCart\Settings
 */

declare( strict_types=1 );

namespace TejCart\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the TejCart settings admin page and orchestrates
 * tab navigation, field rendering and persistence.
 */
class Settings_Page {
    /**
     * Tabs manager instance.
     *
     * @var Settings_Tabs
     */
    protected $tabs;

    /**
     * Settings API instance.
     *
     * @var Settings_API
     */
    protected $api;

    /**
     * Constructor.
     *
     * @param Settings_Tabs|null $tabs Tabs instance (created automatically when omitted).
     * @param Settings_API|null  $api  API instance (created automatically when omitted).
     */
    public function __construct( ?Settings_Tabs $tabs = null, ?Settings_API $api = null ) {
        $this->tabs = $tabs ?: new Settings_Tabs();
        $this->api  = $api  ?: new Settings_API();
    }

    /**
     * Hook into WordPress: register the admin menu page and settings.
     *
     * @return void
     */
    public function init() {
        add_action( 'tejcart_settings_page', array( $this, 'render' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_export_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_import_settings' ) );
    }

    /**
     * Register sections and fields for the current tab with the
     * WordPress Settings API.
     *
     * @return void
     */
    public function register_settings() {
        $current_tab = $this->tabs->get_current_tab();

        $this->add_settings_sections( $current_tab );
        $this->add_settings_fields( $current_tab );
    }

    /**
     * Register a WordPress settings section for the given tab.
     *
     * @param string $tab_id Tab ID.
     * @return void
     */
    public function add_settings_sections( $tab_id ) {
        $tab = $this->tabs->get_tab( $tab_id );

        if ( ! $tab ) {
            return;
        }

        $section_id = 'tejcart_' . $tab_id;

        $this->api->add_section( array(
            'id'    => $section_id,
            'title' => $tab['label'],
            'desc'  => '',
        ) );

        add_settings_section(
            $section_id,
            '',
            '__return_false',
            'tejcart-settings-' . $tab_id
        );
    }

    /**
     * Register individual settings fields for the given tab with
     * both the internal API and the WordPress Settings API.
     *
     * @param string $tab_id Tab ID.
     * @return void
     */
    public function add_settings_fields( $tab_id ) {
        $fields     = $this->tabs->get_tab_fields( $tab_id );
        $section_id = 'tejcart_' . $tab_id;

        foreach ( $fields as $field ) {
            $this->api->add_field( $section_id, $field );

            $option_name = 'tejcart_' . $field['name'];

            $field_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
            $args       = array(
                'sanitize_callback' => function ( $value ) use ( $field_type ) {
                    return $this->sanitize_field_value( $value, $field_type );
                },
            );
            if ( array_key_exists( 'default', $field ) ) {
                $args['default'] = $field['default'];
            }
            register_setting(
                'tejcart-settings-' . $tab_id,
                $option_name,
                $args
            );

            add_settings_field(
                $option_name,
                isset( $field['label'] ) ? $field['label'] : '',
                array( $this, 'render_wp_field' ),
                'tejcart-settings-' . $tab_id,
                $section_id,
                array(
                    'field'      => $field,
                    'section_id' => $section_id,
                )
            );
        }
    }

    /**
     * Callback used by add_settings_field() to render a single field.
     *
     * @param array $args Arguments passed from add_settings_field().
     * @return void
     */
    public function render_wp_field( $args ) {
        $field = $args['field'];
        $this->api->render_field( $field );
    }

    /**
     * Sanitize a settings field value before persisting, dispatched by the
     * field's declared type. Textarea / WYSIWYG / URL / email / colour /
     * numeric / boolean / multiselect fields each need a different sanitiser;
     * a one-size-fits-all sanitize_text_field() strips newlines from textareas
     * and silently mangles URLs, hex colours, and integers.
     *
     * Complex values (arrays of options, JSON blobs) are persisted by the
     * plugin's own settings handlers, not via the WP Settings API.
     *
     * @param mixed  $value      Raw value from the request.
     * @param string $field_type Declared `type` of the field (see Settings_Tabs).
     * @return mixed Sanitized value safe to store.
     */
    public function sanitize_field_value( $value, string $field_type = 'text' ) {
        switch ( $field_type ) {
            case 'email':
                return sanitize_email( wp_unslash( (string) $value ) );

            case 'url':
                return esc_url_raw( wp_unslash( (string) $value ) );

            case 'textarea':
                return sanitize_textarea_field( wp_unslash( (string) $value ) );

            case 'wysiwyg':
            case 'html':
                return wp_kses_post( wp_unslash( (string) $value ) );

            case 'number':
            case 'int':
                return (int) $value;

            case 'float':
            case 'decimal':
                return (float) $value;

            case 'checkbox':
                $bool = is_string( $value )
                    ? in_array( strtolower( $value ), array( 'yes', '1', 'true', 'on' ), true )
                    : (bool) $value;
                return $bool ? 'yes' : 'no';

            case 'color':
                $raw = trim( (string) wp_unslash( $value ) );
                if ( '' === $raw ) {
                    return '';
                }
                if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $raw ) ) {
                    return '';
                }
                $hex = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $raw ) : null;
                return is_string( $hex ) ? $hex : '';

            case 'multiselect':
                if ( is_array( $value ) ) {
                    return array_values( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $value ) ) );
                }
                return array();

            case 'code':
                // Code snippets (CSS / JS) must not be set via the Settings UI —
                // they should be admin-set via filters. Drop the input.
                return '';

            case 'text':
            case 'select':
            case 'radio':
            default:
                if ( is_array( $value ) ) {
                    return array_map( fn( $v ) => sanitize_text_field( wp_unslash( (string) $v ) ), $value );
                }
                if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
                    return $value;
                }
                return sanitize_text_field( wp_unslash( (string) $value ) );
        }
    }

    /**
     * Render the full settings page: tab navigation, form and submit button.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        // Existence check only; save() performs the actual nonce verification.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 'POST' === $request_method && isset( $_POST['tejcart_settings_nonce'] ) ) {
            $this->save();
        }

        $current_tab = $this->tabs->get_current_tab();
        $all_tabs    = $this->tabs->get_tabs();
        $groups      = $this->tabs->get_groups();
        $current     = isset( $all_tabs[ $current_tab ] ) ? $all_tabs[ $current_tab ] : null;

        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-settings tejcart-settings-wrap">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Settings', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle"><?php esc_html_e( 'Configure your store preferences and integrations.', 'tejcart' ); ?></p>
                </div>
                <div class="nxc-page-header__actions">
                    <button type="button" class="nxc-btn tejcart-settings-mobile-toggle" aria-controls="tejcart-settings-sidebar" aria-expanded="false">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        <span><?php esc_html_e( 'Settings menu', 'tejcart' ); ?></span>
                    </button>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <?php settings_errors( 'tejcart_settings' ); ?>

            <div class="tejcart-settings-layout">
                <div class="tejcart-settings-sidebar-backdrop" hidden></div>

                <aside id="tejcart-settings-sidebar" class="tejcart-settings-sidebar" aria-label="<?php esc_attr_e( 'Settings navigation', 'tejcart' ); ?>">
                    <div class="tejcart-settings-search">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input
                            type="search"
                            id="tejcart-settings-search-input"
                            placeholder="<?php esc_attr_e( 'Search settings…', 'tejcart' ); ?>"
                            autocomplete="off"
                            spellcheck="false"
                            aria-label="<?php esc_attr_e( 'Search settings', 'tejcart' ); ?>"
                            aria-autocomplete="list"
                            aria-controls="tejcart-settings-inline-results"
                            role="combobox"
                            aria-expanded="false"
                        />
                        <kbd class="tejcart-settings-search-kbd" aria-hidden="true"><?php echo esc_html( _x( '⌘K', 'Keyboard shortcut for the settings search palette', 'tejcart' ) ); ?></kbd>
                    </div>

                    <div
                        id="tejcart-settings-inline-results"
                        class="tejcart-settings-inline-results"
                        role="listbox"
                        aria-label="<?php esc_attr_e( 'Settings search results', 'tejcart' ); ?>"
                        hidden
                    ></div>

                    <p class="tejcart-settings-inline-empty" hidden data-tejcart-inline-empty>
                        <?php esc_html_e( 'No settings match your search.', 'tejcart' ); ?>
                    </p>

                    <nav class="tejcart-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'tejcart' ); ?>">
                        <?php foreach ( $groups as $group_id => $group ) : ?>
                            <div class="tejcart-settings-nav-group" data-group="<?php echo esc_attr( $group_id ); ?>">
                                <h3 class="tejcart-settings-nav-group-label"><?php echo esc_html( $group['label'] ); ?></h3>
                                <ul class="tejcart-settings-nav-list">
                                    <?php foreach ( $group['tabs'] as $tab_id ) :
                                        $tab = $all_tabs[ $tab_id ];
                                        $is_active = $current_tab === $tab_id;
                                        ?>
                                        <li>
                                            <a
                                                href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=' . $tab_id ) ); ?>"
                                                class="tejcart-settings-nav-item<?php echo $is_active ? ' is-active' : ''; ?>"
                                                data-tab="<?php echo esc_attr( $tab_id ); ?>"
                                                data-search="<?php echo esc_attr( strtolower( $tab['label'] . ' ' . ( isset( $tab['desc'] ) ? $tab['desc'] : '' ) ) ); ?>"
                                                <?php echo $is_active ? 'aria-current="page"' : ''; ?>
                                            >
                                                <?php if ( ! empty( $tab['icon'] ) ) : ?>
                                                    <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
                                                <?php endif; ?>
                                                <span class="tejcart-settings-nav-item-label"><?php echo esc_html( $tab['label'] ); ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                        <p class="tejcart-settings-nav-empty" hidden><?php esc_html_e( 'No settings match your search.', 'tejcart' ); ?></p>
                    </nav>
                </aside>

                <section class="tejcart-settings-main" aria-labelledby="tejcart-settings-main-title">
                    <header class="tejcart-settings-main-header">
                        <?php if ( $current && ! empty( $current['icon'] ) ) : ?>
                            <span class="tejcart-settings-main-icon dashicons <?php echo esc_attr( $current['icon'] ); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <div class="tejcart-settings-main-heading">
                            <h2 id="tejcart-settings-main-title"><?php echo esc_html( $current ? $current['label'] : __( 'Settings', 'tejcart' ) ); ?></h2>
                            <?php if ( $current && ! empty( $current['desc'] ) ) : ?>
                                <p class="tejcart-settings-main-desc"><?php echo esc_html( $current['desc'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    </header>

                    <div class="tejcart-settings-panel">
                        <?php
                        $advanced_section       = $this->get_advanced_section();
                        $tax_section            = $this->get_tax_section();
                        $shipping_subnav_items  = $this->get_shipping_sub_nav_items();
                        $shipping_section       = $this->get_shipping_section( $shipping_subnav_items );
                        $module_subnav          = $this->get_module_subnav_items( $current_tab );
                        $module_section         = '' !== $module_subnav ? $this->get_module_section( $current_tab, $module_subnav ) : '';
                        $has_module_owner       = '' !== $module_subnav && has_action( 'tejcart_settings_render_tab_' . $current_tab );
                        if ( 'payments' === $current_tab ) :
                        ?>
                            <div class="tejcart-settings-content tejcart-payments-tab-content">
                                <p class="tejcart-settings-content-intro"><?php esc_html_e( 'Installed payment methods are listed below. Click "Manage" to configure a method.', 'tejcart' ); ?></p>
                                <?php ( new \TejCart\Admin\Payment_Methods_List() )->render(); ?>
                            </div>
                        <?php elseif ( 'advanced' === $current_tab && '' !== $advanced_section ) : ?>
                            <?php $this->render_advanced_sub_nav( $advanced_section ); ?>
                            <div class="tejcart-settings-content tejcart-advanced-sub-content">
                                <?php $this->render_advanced_section( $advanced_section ); ?>
                            </div>
                        <?php elseif ( 'tax' === $current_tab && '' !== $tax_section ) : ?>
                            <?php $this->render_tax_sub_nav( $tax_section ); ?>
                            <div class="tejcart-settings-content tejcart-tax-sub-content tejcart-settings-embedded-page">
                                <?php $this->render_tax_section( $tax_section ); ?>
                            </div>
                        <?php elseif ( 'shipping' === $current_tab && '' !== $shipping_section ) : ?>
                            <?php $this->render_shipping_sub_nav( $shipping_subnav_items, $shipping_section ); ?>
                            <div class="tejcart-settings-content tejcart-shipping-sub-content tejcart-settings-embedded-page">
                                <?php $this->render_shipping_section( $shipping_section ); ?>
                            </div>
                        <?php elseif ( $has_module_owner ) : ?>
                            <?php $this->render_module_sub_nav( $current_tab, $module_subnav, $module_section ); ?>
                            <div class="tejcart-settings-content tejcart-module-tab-content tejcart-settings-embedded-page">
                                <?php
                                /**
                                 * Allow a module to render the body of a Settings tab it owns.
                                 *
                                 * Modules register their tab via `tejcart_settings_tabs` /
                                 * `tejcart_settings_tab_groups`, declare their sub-nav via
                                 * `tejcart_settings_subnav_items_{tab}`, and render the body via
                                 * the action below. The currently-selected sub-section is passed
                                 * as the first argument; an empty string means the default view.
                                 *
                                 * @param string $section Selected sub-section key (or '').
                                 * @param string $tab     Tab ID being rendered.
                                 */
                                do_action( 'tejcart_settings_render_tab_' . $current_tab, $module_section, $current_tab );
                                ?>
                            </div>
                        <?php else : ?>
                            <?php if ( 'advanced' === $current_tab ) : ?>
                                <?php $this->render_advanced_sub_nav( '' ); ?>
                            <?php elseif ( 'tax' === $current_tab ) : ?>
                                <?php $this->render_tax_sub_nav( '' ); ?>
                            <?php elseif ( 'shipping' === $current_tab ) : ?>
                                <?php $this->render_shipping_sub_nav( $shipping_subnav_items, '' ); ?>
                            <?php endif; ?>
                            <form method="post" action="" class="tejcart-settings-form" data-tejcart-settings-form>
                                <?php
                                wp_nonce_field( 'tejcart_settings_save', 'tejcart_settings_nonce' );
                                ?>
                                <input type="hidden" name="tejcart_current_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

                                <?php
                                $section_id = 'tejcart_' . $current_tab;
                                $this->api->render_fields( $section_id );
                                ?>

                                <div class="tejcart-settings-footer tejcart-settings-save-bar" data-tejcart-save-bar>
                                    <span class="tejcart-settings-save-status" data-tejcart-save-status>
                                        <span class="tejcart-save-status-idle"><?php esc_html_e( 'All changes saved.', 'tejcart' ); ?></span>
                                        <span class="tejcart-save-status-dirty">
                                            <span class="tejcart-save-status-dot" aria-hidden="true"></span>
                                            <?php esc_html_e( 'You have unsaved changes.', 'tejcart' ); ?>
                                        </span>
                                    </span>
                                    <div class="tejcart-settings-save-actions">
                                        <button type="button" class="button button-secondary tejcart-settings-discard" data-tejcart-discard>
                                            <?php esc_html_e( 'Discard', 'tejcart' ); ?>
                                        </button>
                                        <?php submit_button( __( 'Save Changes', 'tejcart' ), 'primary', 'submit', false ); ?>
                                    </div>
                                </div>
                            </form>

                            <?php if ( 'emails' === $current_tab ) : ?>
                                <div class="tejcart-settings-content tejcart-settings-embedded-page">
                                    <?php ( new \TejCart\Admin\Email_Admin_Page() )->render_inline(); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php
                    if ( 'advanced' === $current_tab && '' === $advanced_section ) {
                        $this->render_import_export_section();
                    }
                    ?>
                </section>
            </div>

            <?php $this->render_search_palette(); ?>
        </div>
        <?php
    }

    /**
     * Render the Cmd-K settings search palette shell.
     *
     * The palette is hidden by default and shown by the admin JS when
     * the user presses ⌘K / Ctrl+K / `/`, or clicks the sidebar search
     * input. All result rows are rendered client-side from the index
     * localised onto `tejcartSettingsSearch`; the PHP shell only emits
     * the dialog skeleton + i18n strings so the UI is keyboard- and
     * screen-reader-accessible from the first paint.
     *
     * @return void
     */
    protected function render_search_palette() {
        ?>
        <div
            id="tejcart-settings-palette"
            class="tejcart-settings-palette"
            role="dialog"
            aria-modal="true"
            aria-labelledby="tejcart-settings-palette-title"
            hidden
        >
            <div class="tejcart-settings-palette__backdrop" data-tejcart-palette-close></div>
            <div class="tejcart-settings-palette__dialog">
                <h2 id="tejcart-settings-palette-title" class="screen-reader-text">
                    <?php esc_html_e( 'Search settings', 'tejcart' ); ?>
                </h2>

                <div class="tejcart-settings-palette__input-row">
                    <span class="dashicons dashicons-search tejcart-settings-palette__icon" aria-hidden="true"></span>
                    <input
                        type="search"
                        id="tejcart-settings-palette-input"
                        class="tejcart-settings-palette__input"
                        placeholder="<?php esc_attr_e( 'Search every setting…', 'tejcart' ); ?>"
                        autocomplete="off"
                        spellcheck="false"
                        aria-label="<?php esc_attr_e( 'Search settings', 'tejcart' ); ?>"
                        aria-autocomplete="list"
                        aria-controls="tejcart-settings-palette-results"
                        role="combobox"
                        aria-expanded="true"
                    />
                    <button
                        type="button"
                        class="tejcart-settings-palette__close"
                        data-tejcart-palette-close
                        aria-label="<?php esc_attr_e( 'Close search', 'tejcart' ); ?>"
                    >
                        <span aria-hidden="true">Esc</span>
                    </button>
                </div>

                <div
                    id="tejcart-settings-palette-results"
                    class="tejcart-settings-palette__results"
                    role="listbox"
                    aria-label="<?php esc_attr_e( 'Search results', 'tejcart' ); ?>"
                ></div>

                <div class="tejcart-settings-palette__empty" hidden data-tejcart-palette-empty>
                    <?php esc_html_e( 'No settings match your search.', 'tejcart' ); ?>
                </div>

                <footer class="tejcart-settings-palette__footer" aria-hidden="true">
                    <span><kbd>↑</kbd><kbd>↓</kbd> <?php esc_html_e( 'navigate', 'tejcart' ); ?></span>
                    <span><kbd>↵</kbd> <?php esc_html_e( 'open', 'tejcart' ); ?></span>
                    <span><kbd>Esc</kbd> <?php esc_html_e( 'close', 'tejcart' ); ?></span>
                </footer>
            </div>
        </div>
        <?php
    }

    /**
     * Render the import/export settings section on the advanced tab.
     *
     * @return void
     */
    public function render_import_export_section() {
        ?>
        <div class="tejcart-import-export-section">
            <h2 class="tejcart-section-title"><?php esc_html_e( 'Import / Export Settings', 'tejcart' ); ?></h2>

            <div class="tejcart-ie-grid">
                <div class="tejcart-card">
                    <div class="tejcart-card-header">
                        <h3><?php esc_html_e( 'Export Settings', 'tejcart' ); ?></h3>
                    </div>
                    <div class="tejcart-card-body">
                        <p><?php esc_html_e( 'Download a JSON file containing all TejCart settings. You can use this file to import settings into another site.', 'tejcart' ); ?></p>
                        <div class="tejcart-notice-inline warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php
                            /* F-PCA-008: Credential warning on the export UI. */
                            esc_html_e( 'Note: API credentials and signing secrets (PayPal, Stripe, webhooks) are redacted in the export file by default. Re-enter them after importing on the destination site.', 'tejcart' );
                            ?>
                        </div>
                        <form method="post" action="">
                            <?php wp_nonce_field( 'tejcart_export_settings', 'tejcart_export_nonce' ); ?>
                            <input type="hidden" name="action" value="tejcart_export_settings" />
                            <?php submit_button( __( 'Export Settings', 'tejcart' ), 'secondary', 'tejcart_export_btn', false ); ?>
                        </form>
                    </div>
                </div>

                <div class="tejcart-card">
                    <div class="tejcart-card-header">
                        <h3><?php esc_html_e( 'Import Settings', 'tejcart' ); ?></h3>
                    </div>
                    <div class="tejcart-card-body">
                        <div class="tejcart-notice-inline warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e( 'Warning: Importing settings will overwrite all existing TejCart settings. This action cannot be undone.', 'tejcart' ); ?>
                        </div>
                        <form method="post" action="" enctype="multipart/form-data">
                            <?php wp_nonce_field( 'tejcart_import_settings', 'tejcart_import_nonce' ); ?>
                            <input type="hidden" name="action" value="tejcart_import_settings" />
                            <div class="tejcart-file-upload">
                                <input type="file" name="tejcart_import_file" accept=".json" required />
                            </div>
                            <?php submit_button( __( 'Import Settings', 'tejcart' ), 'secondary', 'tejcart_import_btn', false ); ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Resolve the current Advanced sub-section from the query string.
     *
     * Empty string means "show the Advanced settings form"; any non-empty
     * value maps to one of the moved developer/maintenance pages.
     *
     * @return string
     */
    protected function get_advanced_section() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $allowed = array( 'api-keys', 'webhooks', 'tools', 'scheduled-actions', 'import-export', 'logs', 'system-status' );
        // Module-contributed sections (e.g. the `captcha` module's
        // Settings → Advanced → Captcha screen) register themselves through
        // the same sub-nav filter so they are valid section keys here too.
        $allowed = array_merge( $allowed, array_keys( $this->get_advanced_extra_sections() ) );
        return in_array( $section, $allowed, true ) ? $section : '';
    }

    /**
     * Render the horizontal sub-nav for the Advanced tab.
     *
     * @param string $current Current sub-section key, or '' for the default settings form.
     * @return void
     */
    protected function render_advanced_sub_nav( $current ) {
        $base  = admin_url( 'admin.php?page=tejcart-settings&tab=advanced' );
        $items = array(
            ''               => __( 'Settings', 'tejcart' ),
            'api-keys'       => __( 'API Keys', 'tejcart' ),
            'webhooks'       => __( 'Webhooks', 'tejcart' ),
            'tools'              => __( 'Tools', 'tejcart' ),
            'scheduled-actions'  => __( 'Scheduled Actions', 'tejcart' ),
            'import-export'      => __( 'Import / Export', 'tejcart' ),
            'logs'               => __( 'Logs', 'tejcart' ),
            'system-status'      => __( 'System Status', 'tejcart' ),
        );
        // Append module-contributed sections (e.g. the `captcha` module).
        foreach ( $this->get_advanced_extra_sections() as $key => $label ) {
            $items[ $key ] = $label;
        }
        ?>
        <nav class="tejcart-settings-subnav" aria-label="<?php esc_attr_e( 'Advanced sections', 'tejcart' ); ?>">
            <?php foreach ( $items as $key => $label ) :
                $url = '' === $key ? $base : add_query_arg( 'section', $key, $base );
                $is_current = $current === $key;
                ?>
                <a
                    href="<?php echo esc_url( $url ); ?>"
                    class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                ><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Resolve the current Tax sub-section from the query string.
     *
     * Empty string means "show the Tax settings form"; any non-empty value
     * maps to one of the embedded tax tools.
     *
     * @return string
     */
    protected function get_tax_section() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $items   = $this->get_tax_sub_nav_items();
        return ( '' !== $section && isset( $items[ $section ] ) ) ? $section : '';
    }

    /**
     * Sub-nav items for the Tax tab.
     *
     * The default "Settings" and built-in "Tax Rates" views are always present.
     * Modules (e.g. the bundled `tax-providers` module's live calculators
     * screen) inject additional sections via the
     * `tejcart_settings_tax_sub_nav_items` filter.
     *
     * @return array<string,string> Map of section key => label (empty key = default Settings view).
     */
    protected function get_tax_sub_nav_items() {
        $items = array(
            ''      => __( 'Settings', 'tejcart' ),
            'rates' => __( 'Tax Rates', 'tejcart' ),
        );

        /**
         * Filter additional sub-sections for the Tax settings tab.
         *
         * Return a map of `section_key => label`. The empty-string key and
         * the built-in `rates` key are reserved and will be ignored if
         * supplied here.
         *
         * @param array<string,string> $extra Section key => label map.
         */
        $extra = apply_filters( 'tejcart_settings_tax_sub_nav_items', array() );
        if ( is_array( $extra ) ) {
            foreach ( $extra as $key => $label ) {
                $key = (string) $key;
                if ( '' === $key || 'rates' === $key ) {
                    continue;
                }
                $items[ $key ] = (string) $label;
            }
        }

        return $items;
    }

    /**
     * Render the horizontal sub-nav for the Tax tab.
     *
     * @param string $current Current sub-section key, or '' for the default settings form.
     * @return void
     */
    protected function render_tax_sub_nav( $current ) {
        $base  = admin_url( 'admin.php?page=tejcart-settings&tab=tax' );
        $items = $this->get_tax_sub_nav_items();
        ?>
        <nav class="tejcart-settings-subnav" aria-label="<?php esc_attr_e( 'Tax sections', 'tejcart' ); ?>">
            <?php foreach ( $items as $key => $label ) :
                $url = '' === $key ? $base : add_query_arg( 'section', $key, $base );
                $is_current = $current === $key;
                ?>
                <a
                    href="<?php echo esc_url( $url ); ?>"
                    class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                ><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Sub-nav items for the Shipping tab.
     *
     * Mirrors the Tax tab layout: the default "Settings" view (empty key) and
     * the built-in "Shipping Zones" view are always present. Modules (e.g. the
     * bundled shipping module's carrier credentials page) inject additional
     * sections via the `tejcart_settings_shipping_sub_nav_items` filter.
     *
     * @return array<string,string> Map of section key => label (empty key = default Settings view).
     */
    protected function get_shipping_sub_nav_items() {
        $items = array(
            ''      => __( 'Settings', 'tejcart' ),
            'zones' => __( 'Shipping Zones', 'tejcart' ),
        );

        /**
         * Filter additional sub-sections for the Shipping settings tab.
         *
         * Return a map of `section_key => label`. The empty-string key and the
         * built-in `zones` key are reserved and will be ignored if supplied
         * here.
         *
         * @param array<string,string> $extra Section key => label map.
         */
        $extra = apply_filters( 'tejcart_settings_shipping_sub_nav_items', array() );
        if ( is_array( $extra ) ) {
            foreach ( $extra as $key => $label ) {
                $key = (string) $key;
                if ( '' === $key || 'zones' === $key ) {
                    continue;
                }
                $items[ $key ] = (string) $label;
            }
        }

        return $items;
    }

    /**
     * Resolve the current Shipping sub-section from `?section=`.
     *
     * @param array<string,string> $items Sub-nav items registered for the tab.
     * @return string Section key validated against $items; '' for default Zones view.
     */
    protected function get_shipping_section( array $items ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        return ( '' !== $section && isset( $items[ $section ] ) ) ? $section : '';
    }

    /**
     * Render the horizontal sub-nav for the Shipping tab.
     *
     * Defensively hidden when only a single section is registered, so an
     * unexpected one-item sub-nav never renders alone. With the built-in
     * "Settings" + "Shipping Zones" pair this always shows.
     *
     * @param array<string,string> $items   Sub-nav items.
     * @param string               $current Currently-selected section key.
     * @return void
     */
    protected function render_shipping_sub_nav( array $items, $current ) {
        if ( count( $items ) <= 1 ) {
            return;
        }

        $base = admin_url( 'admin.php?page=tejcart-settings&tab=shipping' );
        ?>
        <nav class="tejcart-settings-subnav" aria-label="<?php esc_attr_e( 'Shipping sections', 'tejcart' ); ?>">
            <?php foreach ( $items as $key => $label ) :
                $url = '' === $key ? $base : add_query_arg( 'section', $key, $base );
                $is_current = $current === $key;
                ?>
                <a
                    href="<?php echo esc_url( $url ); ?>"
                    class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                ><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Render the body of a Shipping sub-section by delegating to the existing
     * page class.
     *
     * The empty section renders the settings form (handled inline above); the
     * built-in `zones` section renders the Shipping Zones table; non-empty
     * module-contributed sections are rendered via the
     * `tejcart_settings_render_shipping_section_{section}` action.
     *
     * @param string $section Sub-section key.
     * @return void
     */
    protected function render_shipping_section( $section ) {
        if ( 'zones' === $section ) {
            ( new \TejCart\Admin\Shipping_Zones_Table() )->render_page( true );
            return;
        }

        /**
         * Render the body of a module-contributed Shipping sub-section.
         *
         * @param string $section Sub-section key.
         */
        do_action( 'tejcart_settings_render_shipping_section_' . $section, $section );
    }

    /**
     * Resolve the sub-nav items registered by a module-owned tab.
     *
     * Modules register sub-sections via the
     * `tejcart_settings_subnav_items_{tab}` filter. The filter must return a
     * map of `section_key => label` (use an empty string key for the default
     * "Settings" view). Returning an empty array means the tab has no
     * sub-nav and will render its body unframed.
     *
     * @param string $tab Tab ID.
     * @return array<string,string>|string Map of section key => label, or '' when none registered.
     */
    protected function get_module_subnav_items( $tab ) {
        /**
         * Filter the sub-nav items for a module-owned settings tab.
         *
         * @param array<string,string> $items Map of section key => label.
         * @param string               $tab   Tab ID.
         */
        $items = apply_filters( 'tejcart_settings_subnav_items_' . $tab, array(), $tab );
        if ( ! is_array( $items ) || empty( $items ) ) {
            return '';
        }

        $clean = array();
        foreach ( $items as $key => $label ) {
            $clean[ (string) $key ] = (string) $label;
        }
        return $clean;
    }

    /**
     * Resolve the current sub-section for a module-owned tab from `?section=`.
     *
     * @param string                $tab   Tab ID.
     * @param array<string,string>  $items Sub-nav items registered for the tab.
     * @return string Section key (validated against $items); '' for default view.
     */
    protected function get_module_section( $tab, array $items ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        return ( '' !== $section && isset( $items[ $section ] ) ) ? $section : '';
    }

    /**
     * Render the horizontal sub-nav for a module-owned tab.
     *
     * @param string                $tab     Tab ID.
     * @param array<string,string>  $items   Sub-nav items.
     * @param string                $current Currently-selected section key.
     * @return void
     */
    protected function render_module_sub_nav( $tab, array $items, $current ) {
        $base = admin_url( 'admin.php?page=tejcart-settings&tab=' . $tab );
        ?>
        <nav class="tejcart-settings-subnav" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tab label */ __( '%s sections', 'tejcart' ), $tab ) ); ?>">
            <?php foreach ( $items as $key => $label ) :
                $url = '' === $key ? $base : add_query_arg( 'section', $key, $base );
                $is_current = $current === $key;
                ?>
                <a
                    href="<?php echo esc_url( $url ); ?>"
                    class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                ><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Render the body of a Tax sub-section by delegating to the existing
     * page class.
     *
     * @param string $section Sub-section key.
     * @return void
     */
    protected function render_tax_section( $section ) {
        if ( 'rates' === $section ) {
            ( new \TejCart\Admin\Tax_Rates_Table() )->render_page( true );
            return;
        }

        /**
         * Render the body of a module-contributed Tax sub-section.
         *
         * @param string $section Sub-section key.
         */
        do_action( 'tejcart_settings_render_tax_section_' . $section, $section );
    }

    /**
     * Render the body of an Advanced sub-section by delegating to the
     * existing page class.
     *
     * @param string $section Sub-section key.
     * @return void
     */
    protected function render_advanced_section( $section ) {
        switch ( $section ) {
            case 'api-keys':
                ( new \TejCart\Admin\API_Keys_Page() )->render( true );
                break;
            case 'webhooks':
                \TejCart\Core\Outgoing_Webhooks::instance()->render_admin_page( true );
                break;
            case 'tools':
                ( new \TejCart\Admin\Tools_Page() )->render( true );
                break;
            case 'scheduled-actions':
                ( new \TejCart\Admin\Scheduled_Actions_Page() )->render( true );
                break;
            case 'import-export':
                ( new \TejCart\Admin\Product_Import_Export() )->render_page( true );
                break;
            case 'logs':
                ( new \TejCart\Admin\Log_Viewer() )->render( true );
                break;
            case 'system-status':
                ( new \TejCart\Admin\System_Status() )->render( true );
                break;
            default:
                /**
                 * Render the body of a module-contributed Advanced sub-section.
                 *
                 * Mirrors the Tax-tab seam (`tejcart_settings_render_tax_section_*`).
                 * The `captcha` module hooks
                 * `tejcart_settings_render_advanced_section_captcha`.
                 *
                 * @param string $section Sub-section key.
                 */
                do_action( 'tejcart_settings_render_advanced_section_' . $section, $section );
                break;
        }
    }

    /**
     * Module-contributed Advanced sub-sections, as a `section_key => label`
     * map. Collected from the `tejcart_settings_advanced_sub_nav_items`
     * filter so optional modules (e.g. `captcha`) can mount a section under
     * Settings → Advanced without core hard-coding them. Core's own
     * sections are intentionally excluded so they can never be clobbered.
     *
     * @return array<string, string>
     */
    protected function get_advanced_extra_sections(): array {
        $extra = apply_filters( 'tejcart_settings_advanced_sub_nav_items', array() );
        if ( ! is_array( $extra ) ) {
            return array();
        }
        $reserved = array( '', 'api-keys', 'webhooks', 'tools', 'scheduled-actions', 'import-export', 'logs', 'system-status' );
        $clean    = array();
        foreach ( $extra as $key => $label ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || in_array( $key, $reserved, true ) ) {
                continue;
            }
            $clean[ $key ] = (string) $label;
        }
        return $clean;
    }

    /**
     * Process the submitted POST data: verify the nonce, sanitize
     * and persist field values via Settings_API.
     *
     * @return void
     */
    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_settings_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_settings_nonce'] ) ), 'tejcart_settings_save' ) ) {
            add_settings_error(
                'tejcart_settings',
                'tejcart_nonce_error',
                __( 'Security check failed. Please try again.', 'tejcart' ),
                'error'
            );
            return;
        }

        $tab_id     = isset( $_POST['tejcart_current_tab'] ) ? sanitize_key( wp_unslash( $_POST['tejcart_current_tab'] ) ) : 'general';
        $section_id = 'tejcart_' . $tab_id;

        $result = $this->api->save( $section_id );

        if ( $result ) {
            add_settings_error(
                'tejcart_settings',
                'tejcart_settings_saved',
                __( 'Settings saved successfully.', 'tejcart' ),
                'updated'
            );
        } else {
            add_settings_error(
                'tejcart_settings',
                'tejcart_settings_error',
                __( 'No settings were found to save.', 'tejcart' ),
                'error'
            );
        }
    }

    /**
     * Handle settings export on admin_init.
     *
     * @return void
     */
    public function handle_export_settings() {
        if ( ! isset( $_POST['action'] ) || 'tejcart_export_settings' !== $_POST['action'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_export_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_export_nonce'] ) ), 'tejcart_export_settings' ) ) {
            return;
        }

        $this->export_settings();
    }

    /**
     * Known option keys that contain live API credentials or signing secrets.
     *
     * These are redacted from the settings export by default so a merchant
     * who shares the JSON file for support purposes does not inadvertently
     * expose live payment credentials. Pass the exported file through the
     * `tejcart_export_settings_option` filter with `$redact = false` to
     * include them explicitly (e.g. for a full site migration).
     *
     * F-PCA-008: Mirrors the `scrub_secrets_on_import()` pattern that already
     * exists on the import path.
     *
     * @var string[]
     */
    protected const EXPORT_SECRET_KEYS = array(
        'tejcart_paypal_secret',
        'tejcart_paypal_client_secret',
        'tejcart_paypal_webhook_secret',
        'tejcart_stripe_secret_key',
        'tejcart_stripe_webhook_secret',
        'tejcart_authorize_net_transaction_key',
        'tejcart_webhook_secret',
    );

    /**
     * Collect all tejcart_* options and output as a JSON download.
     *
     * F-PCA-008: Credential-bearing option keys listed in EXPORT_SECRET_KEYS
     * are redacted from the export by default. Hooks `tejcart_export_settings_option`
     * so merchants/site-owners can opt in to including them for a full migration.
     * A credential-warning notice is shown in the export UI
     * (see render_import_export_section()).
     *
     * @return void
     */
    public function export_settings() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'tejcart_' ) . '%'
            ),
            ARRAY_A
        );

        $settings = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $option_name  = $row['option_name'];
                $option_value = maybe_unserialize( $row['option_value'], array( 'allowed_classes' => false ) );

                // F-PCA-008: Redact known secret keys by default.
                $is_secret = in_array( $option_name, self::EXPORT_SECRET_KEYS, true );

                /**
                 * Filter whether a given tejcart_* option is included (unredacted)
                 * in the settings export.
                 *
                 * Return false to omit the key entirely; return true to include it
                 * with its real value. Return null (default) to use the built-in
                 * redaction rules: known secrets are replaced with a placeholder,
                 * all other keys are included as-is.
                 *
                 * @since 1.x.0
                 *
                 * @param bool|null $include      Whether to include unredacted. null = default behaviour.
                 * @param string    $option_name  The option key (e.g. 'tejcart_paypal_secret').
                 * @param mixed     $option_value The stored option value.
                 */
                $override = apply_filters( 'tejcart_export_settings_option', null, $option_name, $option_value );

                if ( false === $override ) {
                    // Caller explicitly excluded this key.
                    continue;
                }

                if ( true !== $override && $is_secret ) {
                    // Replace secret value with a placeholder so the key is visible
                    // in the file (helps identify what will need re-entering) but
                    // the credential is not exposed.
                    $settings[ $option_name ] = '**REDACTED**';
                    continue;
                }

                $settings[ $option_name ] = $option_value;
            }
        }

        $filename = 'tejcart-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Expires: 0' );

        echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Handle settings import on admin_init.
     *
     * @return void
     */
    public function handle_import_settings() {
        if ( ! isset( $_POST['action'] ) || 'tejcart_import_settings' !== $_POST['action'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_import_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_import_nonce'] ) ), 'tejcart_import_settings' ) ) {
            add_settings_error(
                'tejcart_settings',
                'tejcart_import_nonce_error',
                __( 'Security check failed. Please try again.', 'tejcart' ),
                'error'
            );
            return;
        }

        if ( ! isset( $_FILES['tejcart_import_file'] ) || empty( $_FILES['tejcart_import_file']['tmp_name'] ) ) {
            add_settings_error(
                'tejcart_settings',
                'tejcart_import_no_file',
                __( 'Please select a JSON file to import.', 'tejcart' ),
                'error'
            );
            return;
        }

        // import_settings() validates is_uploaded_file() and JSON-decodes the
        // contents; tmp_name and name are sanitized via type casting/pathinfo.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $count = $this->import_settings( wp_unslash( $_FILES['tejcart_import_file'] ) );

        if ( is_wp_error( $count ) ) {
            add_settings_error(
                'tejcart_settings',
                'tejcart_import_error',
                $count->get_error_message(),
                'error'
            );
        } else {
            add_settings_error(
                'tejcart_settings',
                'tejcart_import_success',
                sprintf(
                    /* translators: %d: number of settings imported */
                    __( 'Settings imported successfully. %d settings updated.', 'tejcart' ),
                    $count
                ),
                'updated'
            );
        }
    }

    /**
     * Validate and import settings from a JSON file.
     *
     * @param array $file The uploaded file from $_FILES.
     * @return int|\WP_Error Number of settings imported or WP_Error on failure.
     */
    public function import_settings( $file ) {
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'invalid_file', __( 'Invalid file upload.', 'tejcart' ) );
        }

        $max_bytes = 5 * 1024 * 1024;
        $size      = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $file['tmp_name'] );
        if ( $size > $max_bytes ) {
            return new \WP_Error(
                'file_too_large',
                __( 'Settings file exceeds the 5MB limit.', 'tejcart' )
            );
        }

        $name = isset( $file['name'] ) ? (string) $file['name'] : '';
        if ( '' !== $name && 'json' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            return new \WP_Error(
                'invalid_extension',
                __( 'Settings file must have a .json extension.', 'tejcart' )
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json_content = file_get_contents( $file['tmp_name'] );

        if ( false === $json_content || empty( $json_content ) ) {
            return new \WP_Error( 'empty_file', __( 'The uploaded file is empty.', 'tejcart' ) );
        }

        $settings = json_decode( $json_content, true );

        if ( null === $settings || ! is_array( $settings ) ) {
            return new \WP_Error( 'invalid_json', __( 'The uploaded file does not contain valid JSON.', 'tejcart' ) );
        }

        $allowed   = $this->get_importable_option_names();
        $type_map  = $this->build_import_field_type_map();

        $count = 0;

        foreach ( $settings as $option_name => $option_value ) {
            if ( 0 !== strpos( (string) $option_name, 'tejcart_' ) ) {
                continue;
            }

            $option_name = sanitize_key( $option_name );

            if ( ! isset( $allowed[ $option_name ] ) ) {
                continue;
            }

            // Dispatch by declared field type (audit 04 L-8). A field
            // declared as `textarea` survives newlines; an `email` field is
            // canonicalised. import_settings() must run every imported value
            // through the same sanitiser that register_setting()'s save path
            // would — update_option() below bypasses those callbacks, so the
            // import endpoint is the only gate. Reject any key that is not a
            // known, type-declared setting rather than falling back to a
            // permissive scalar path (S-M2): a key with no entry in the
            // field-type map is not a real setting and must not be written.
            if ( ! isset( $type_map[ $option_name ] ) ) {
                continue;
            }
            $field_type = (string) $type_map[ $option_name ];

            // Validate the value *shape* against the field type before
            // sanitising. Array-shaped fields (multiselect) only accept
            // arrays; every other field type expects a scalar. A mismatched
            // shape is rejected rather than coerced so a crafted JSON cannot,
            // for example, smuggle an array into a scalar option.
            $expects_array = in_array( $field_type, array( 'multiselect' ), true );
            if ( $expects_array !== is_array( $option_value ) ) {
                continue;
            }

            $option_value = $this->sanitize_field_value( $option_value, $field_type );

            // Defence-in-depth: scrub secret-bearing fields before
            // persisting. An admin-compromise vector that uploaded a
            // crafted settings JSON could otherwise overwrite the
            // outbound-webhook signing secret with attacker-known
            // plaintext, letting them forge `X-TejCart-Webhook-Signature`
            // (and X-TejCart-Webhook-Signature-V2, see #1162) against
            // downstream consumers. Same posture for any other secret
            // field that ships in a structured option. The URL and
            // event-subscription portions of webhook rows still import
            // normally; only the `secret` slot is preserved from the
            // previously-stored row.
            if ( is_array( $option_value ) ) {
                $option_value = $this->scrub_secrets_on_import( $option_name, $option_value );
            }

            update_option( $option_name, $option_value );
            $count++;
        }

        return $count;
    }

    /**
     * Strip secret-bearing keys from imported option values, preserving
     * whatever was previously stored in the same slot. The intent is to
     * let an operator restore the structural / configuration parts of
     * a settings backup without trusting the JSON for any
     * confidentiality-bearing field.
     *
     * @param string               $option_name Option being imported.
     * @param array<string,mixed>  $incoming    Incoming JSON-decoded value.
     * @return array<string,mixed>
     */
    private function scrub_secrets_on_import( string $option_name, array $incoming ): array {
        // Per-option list of secret keys that must never be imported.
        // Extensible via filter so addon plugins can register their
        // own secret-bearing structured options without forking core.
        $defaults = array(
            'tejcart_webhooks' => array( 'secret' ),
            // Future structured-option secrets register here.
        );
        /**
         * Filter the per-option list of secret keys that the settings
         * importer must scrub. Addons that store structured options
         * with secret slots should append here.
         *
         * @param array<string,string[]> $map  option_name => secret_key[]
         */
        $map = (array) apply_filters( 'tejcart_import_secret_keys', $defaults );
        if ( empty( $map[ $option_name ] ) ) {
            return $incoming;
        }
        $secret_keys = (array) $map[ $option_name ];

        $existing = (array) get_option( $option_name, array() );

        // Two shapes are common: a flat assoc array (single subscription)
        // and a list-of-assocs (multiple subscriptions). Handle both.
        $is_list_of_assocs = array_keys( $incoming ) === range( 0, count( $incoming ) - 1 )
            && ! empty( $incoming )
            && is_array( reset( $incoming ) );

        if ( $is_list_of_assocs ) {
            foreach ( $incoming as $i => $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                foreach ( $secret_keys as $secret_key ) {
                    // If the existing row carries a value for this
                    // index + key, preserve it; otherwise drop the
                    // imported value rather than persisting whatever
                    // string the attacker supplied.
                    $existing_value = $existing[ $i ][ $secret_key ] ?? '';
                    if ( '' !== $existing_value ) {
                        $row[ $secret_key ] = $existing_value;
                    } else {
                        unset( $row[ $secret_key ] );
                    }
                }
                $incoming[ $i ] = $row;
            }
            return $incoming;
        }

        // Flat assoc array case.
        foreach ( $secret_keys as $secret_key ) {
            $existing_value = $existing[ $secret_key ] ?? '';
            if ( '' !== $existing_value ) {
                $incoming[ $secret_key ] = $existing_value;
            } else {
                unset( $incoming[ $secret_key ] );
            }
        }
        return $incoming;
    }

    /**
     * Build a map of `tejcart_*` option name → declared field type by walking
     * the Settings_Tabs field registry once.
     *
     * Used by {@see import_settings()} to dispatch a type-aware sanitiser
     * instead of the legacy `sanitize_text_field` one-size-fits-all.
     *
     * @return array<string,string>
     */
    private function build_import_field_type_map(): array {
        $map = array();
        if ( ! is_object( $this->tabs ) || ! method_exists( $this->tabs, 'get_tabs' ) ) {
            return $map;
        }
        $tabs = $this->tabs->get_tabs();
        if ( ! is_array( $tabs ) ) {
            return $map;
        }
        foreach ( array_keys( $tabs ) as $tab_id ) {
            $fields = $this->tabs->get_tab_fields( (string) $tab_id );
            if ( ! is_array( $fields ) ) {
                continue;
            }
            foreach ( $fields as $field ) {
                if ( empty( $field['name'] ) ) {
                    continue;
                }
                $option_name = 'tejcart_' . (string) $field['name'];
                $field_type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
                $map[ $option_name ] = $field_type;
            }
        }
        /**
         * Filter the per-option field-type map used by settings import.
         *
         * @param array<string,string> $map  option_name => field_type.
         */
        $map = (array) apply_filters( 'tejcart_settings_import_field_types', $map );
        return $map;
    }

    /**
     * Build the allowlist of option names that the import endpoint may write.
     *
     * Sourced from the set of `tejcart_*` options currently stored in the
     * database so we only ever overwrite keys the site already knows about.
     * Extensions can extend the set via the
     * `tejcart_settings_import_allowed_options` filter (e.g. to permit
     * first-run imports on a fresh install).
     *
     * @return array<string,bool> Map of allowed option name => true.
     */
    private function get_importable_option_names(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'tejcart_' ) . '%'
            )
        );

        $allowed = array();
        if ( is_array( $names ) ) {
            foreach ( $names as $name ) {
                $allowed[ (string) $name ] = true;
            }
        }

        /**
         * Filter the allowlist of option names accepted by settings import.
         *
         * @param array<string,bool> $allowed Map of option name => true.
         */
        $allowed = apply_filters( 'tejcart_settings_import_allowed_options', $allowed );

        return is_array( $allowed ) ? $allowed : array();
    }
}
