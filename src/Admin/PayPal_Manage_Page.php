<?php
/**
 * Unified PayPal Manage Page
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Gateways\Gateway_Registry;
use TejCart\Gateways\PayPal\PayPal_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified PayPal Manage page.
 *
 * Replaces the old per-gateway settings pages for the PayPal family
 * (PayPal, Card, Google Pay, Apple Pay, Pay Later) with a single tabbed
 * screen. Each tab saves its own fields independently and writes them to
 * the underlying gateway's `tejcart_gateway_{id}` option.
 *
 * Tab locking:
 *   - API Connection is always accessible.
 *   - All other tabs are visible but display a soft-lock notice and
 *     disabled save bar until PayPal seller onboarding is complete.
 */
class PayPal_Manage_Page {
    /**
     * Admin page slug.
     */
    public const PAGE_SLUG = 'tejcart-paypal-manage';

    /**
     * Official PayPal "PP" monogram (color mark) SVG fragments — 2024
     * Pentagram refresh: sharper geometric corners and the new brand
     * palette (#002991 deep navy / #008CFF mid blue / #60CDFF light
     * blue). Concatenated by `get_paypal_logo_svg()` with this page's
     * dimensions. Split across multiple string literals so no single
     * source line exceeds the phpcs absolute limit (#1234).
     */
    private const PAYPAL_LOGO_SVG_OPEN =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="7.056 3 37.351 45" preserveAspectRatio="xMidYMid meet"';

    private const PAYPAL_LOGO_SVG_PATHS =
        '<path fill="#002991" d="M38.914 13.35c0 5.574-5.144 12.15-12.927 12.15H18.49l-.368 2.322L16.373 39'
        . 'H7.056l5.605-36h15.095c5.083 0 9.082 2.833 10.555 6.77a9.687 9.687 0 0 1 .603 3.58z"/>'
        . '<path fill="#60CDFF" d="M44.284 23.7A12.894 12.894 0 0 1 31.53 34.5h-5.206L24.157 48H14.89l1.483-9'
        . ' 1.75-11.178.367-2.322h7.497c7.773 0 12.927-6.576 12.927-12.15 3.825 1.974 6.055 5.963 5.37'
        . ' 10.35z"/>'
        . '<path fill="#008CFF" d="M38.914 13.35C37.31 12.511 35.365 12 33.248 12h-12.64L18.49 25.5h7.497'
        . 'c7.773 0 12.927-6.576 12.927-12.15z"/>';

    /**
     * Gateway registry.
     *
     * @var Gateway_Registry
     */
    private Gateway_Registry $registry;

    /**
     * Payment method settings renderer, reused for field input + sanitization.
     *
     * @var Payment_Method_Settings
     */
    private Payment_Method_Settings $field_renderer;

    /**
     * Constructor.
     *
     * @param Gateway_Registry|null $registry Gateway registry instance.
     */
    public function __construct( ?Gateway_Registry $registry = null ) {
        $this->registry       = $registry ?: tejcart()->gateways();
        $this->field_renderer = new Payment_Method_Settings( $this->registry );
    }

    /**
     * Render the manage page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage payment methods.', 'tejcart' ) );
        }

        $is_onboarded = PayPal_Gateway::is_onboarded();
        $current_tab  = $this->get_current_tab( $is_onboarded );

        $is_locked    = ( 'api_connection' !== $current_tab ) && ! $is_onboarded;

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        // Existence check only; save_tab() performs nonce verification.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 'POST' === $request_method && isset( $_POST['tejcart_paypal_manage_nonce'] ) && ! $is_locked ) {
            $this->save_tab( $current_tab );
        }

        $this->render_page( $current_tab, $is_onboarded, $is_locked );
    }

    /**
     * Render the full page shell: header, hero, sidebar nav, and tab content.
     *
     * Uses the plugin-wide Polaris-style two-column shell so this screen
     * looks and behaves like the rest of TejCart's admin (Settings, list
     * tables, etc.). Sidebar groups tabs by purpose; main panel renders
     * the active tab's form inside a card with a sticky save footer.
     *
     * @param string $current_tab  Tab slug being viewed.
     * @param bool   $is_onboarded Whether PayPal onboarding is complete.
     * @param bool   $is_locked    Whether the current tab is soft-locked.
     * @return void
     */
    private function render_page( string $current_tab, bool $is_onboarded, bool $is_locked ): void {
        $tabs         = $this->get_tabs();
        $tab          = $tabs[ $current_tab ] ?? array( 'label' => '', 'icon' => '' );
        $back_url     = Payment_Methods_List::get_list_url();
        $env_snapshot = \TejCart\Gateways\PayPal\PayPal_Onboarding::instance()->get_status_snapshot();
        $active_env   = $env_snapshot['active_environment'] ?? 'sandbox';
        ?>
        <?php

        ?>
        <div class="wrap tejcart-admin-wrap nxc-list tejcart-paypal-manage-wrap"
             data-current-tab="<?php echo esc_attr( $current_tab ); ?>"
             data-onboarded="<?php echo $is_onboarded ? 'yes' : 'no'; ?>"
             data-environment="<?php echo esc_attr( $active_env ); ?>">

            <?php $this->render_top_bar( $back_url ); ?>

            <span class="wp-header-end"></span>

            <?php settings_errors( 'tejcart_paypal_manage' ); ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['tejcart_paypal_connected'] ) ) :
                ?>
                <div class="notice notice-success is-dismissible tejcart-paypal-manage__notice">
                    <p>
                        <strong><?php esc_html_e( 'PayPal is now connected.', 'tejcart' ); ?></strong>
                        <?php esc_html_e( 'All payment method tabs are unlocked.', 'tejcart' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $this->render_hero( $is_onboarded, $active_env, $env_snapshot ); ?>

            <?php if ( ! $is_onboarded && 'api_connection' !== $current_tab ) : ?>
                <?php $this->render_connect_cta(); ?>
            <?php endif; ?>

            <div class="tejcart-paypal-manage__layout">
                <?php $this->render_sidebar_nav( $tabs, $current_tab, $is_onboarded ); ?>

                <section class="tejcart-paypal-manage__main"
                         aria-labelledby="tejcart-paypal-manage-main-title">
                    <?php $this->render_main_header( $tab, $current_tab, $is_locked ); ?>

                    <div class="tejcart-paypal-manage-tab-content tejcart-paypal-manage__panel"
                         data-tab="<?php echo esc_attr( $current_tab ); ?>">

                        <?php if ( $is_locked ) : ?>
                            <?php $this->render_locked_notice( $tab['label'] ?? '' ); ?>
                        <?php endif; ?>

                        <form method="post"
                              action=""
                              class="tejcart-paypal-manage-form tejcart-settings-form tejcart-paypal-manage__form<?php echo $is_locked ? ' is-locked' : ''; ?>">
                            <?php wp_nonce_field( 'tejcart_paypal_manage_save_' . $current_tab, 'tejcart_paypal_manage_nonce' ); ?>
                            <input type="hidden" name="tejcart_paypal_manage_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

                            <div class="tejcart-paypal-manage__form-body">
                                <table class="form-table tejcart-settings-table" role="presentation">
                                    <?php $this->render_tab_fields( $current_tab ); ?>
                                </table>
                            </div>

                            <?php $this->render_save_bar( $is_locked ); ?>
                        </form>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * Render the breadcrumb / top bar above the hero.
     *
     * @param string $back_url URL of the Payment Methods list to return to.
     * @return void
     */
    private function render_top_bar( string $back_url ): void {
        ?>
        <nav class="tejcart-paypal-manage__topbar" aria-label="<?php esc_attr_e( 'Breadcrumb', 'tejcart' ); ?>">
            <a href="<?php echo esc_url( $back_url ); ?>" class="tejcart-paypal-manage__breadcrumb">
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                <?php esc_html_e( 'Payment Methods', 'tejcart' ); ?>
            </a>
            <span class="tejcart-paypal-manage__breadcrumb-sep" aria-hidden="true">/</span>
            <span class="tejcart-paypal-manage__breadcrumb-current">
                <?php esc_html_e( 'PayPal Payments', 'tejcart' ); ?>
            </span>
        </nav>
        <?php
    }

    /**
     * Render the hero header — PayPal brand, headline, environment chip,
     * connection status pill, and supporting docs link.
     *
     * @param bool   $is_onboarded Whether onboarding is complete.
     * @param string $active_env   Active environment ("sandbox" | "live").
     * @param array  $snapshot     Onboarding status snapshot.
     * @return void
     */
    private function render_hero( bool $is_onboarded, string $active_env, array $snapshot ): void {
        $is_sandbox     = ( 'live' !== $active_env );
        $env_data       = $snapshot[ $is_sandbox ? 'sandbox' : 'live' ] ?? array();
        $merchant_id    = (string) ( $env_data['merchant_id'] ?? '' );
        $merchant_email = (string) ( $env_data['email'] ?? '' );
        $has_meta       = $is_onboarded && ( '' !== $merchant_id || '' !== $merchant_email );

        $status_class = $is_onboarded ? 'tejcart-pill--success' : 'tejcart-pill--neutral';
        $status_label = $is_onboarded
            ? __( 'Connected', 'tejcart' )
            : __( 'Not connected', 'tejcart' );
        $env_class = $is_sandbox ? 'tejcart-pill--warning' : 'tejcart-pill--success';
        $env_label = $is_sandbox
            ? __( 'Sandbox mode', 'tejcart' )
            : __( 'Live mode', 'tejcart' );

        ?>
        <header class="tejcart-paypal-manage__hero" role="banner">
            <div class="tejcart-paypal-manage__hero-brand">
                <span class="tejcart-paypal-manage__hero-logo" aria-hidden="true">
                    <?php echo tejcart_kses_svg( self::get_paypal_logo_svg() ); ?>
                </span>

                <div class="tejcart-paypal-manage__hero-copy">
                    <h1 class="tejcart-paypal-manage__hero-title">
                        <?php esc_html_e( 'Payments', 'tejcart' ); ?>
                    </h1>
                    <p class="tejcart-paypal-manage__hero-subtitle">
                        <?php esc_html_e( 'Accept PayPal, Cards, Google Pay, Apple Pay, and Pay Later from a single integration.', 'tejcart' ); ?>
                    </p>

                    <div class="tejcart-paypal-manage__hero-meta">
                        <span class="tejcart-pill <?php echo esc_attr( $status_class ); ?>">
                            <span class="tejcart-pill__dot" aria-hidden="true"></span>
                            <?php echo esc_html( $status_label ); ?>
                        </span>
                        <span class="tejcart-pill <?php echo esc_attr( $env_class ); ?>"
                              title="<?php echo esc_attr(
                                  $is_sandbox
                                      ? __( 'Test transactions only — no real money is moved.', 'tejcart' )
                                      : __( 'Real transactions are being processed.', 'tejcart' )
                              ); ?>">
                            <span class="tejcart-pill__dot" aria-hidden="true"></span>
                            <?php echo esc_html( $env_label ); ?>
                        </span>

                        <?php if ( $has_meta ) : ?>
                            <span class="tejcart-paypal-manage__hero-meta-sep" aria-hidden="true">·</span>
                            <span class="tejcart-paypal-manage__hero-meta-info">
                                <?php if ( '' !== $merchant_id ) : ?>
                                    <span class="tejcart-paypal-manage__hero-meta-label">
                                        <?php esc_html_e( 'Merchant ID', 'tejcart' ); ?>
                                    </span>
                                    <code><?php echo esc_html( $merchant_id ); ?></code>
                                <?php endif; ?>
                                <?php if ( '' !== $merchant_id && '' !== $merchant_email ) : ?>
                                    <span class="tejcart-paypal-manage__hero-meta-sep" aria-hidden="true">·</span>
                                <?php endif; ?>
                                <?php if ( '' !== $merchant_email ) : ?>
                                    <span class="tejcart-paypal-manage__hero-meta-email">
                                        <?php echo esc_html( $merchant_email ); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tejcart-paypal-manage__hero-actions">
                <?php if ( ! $is_onboarded ) : ?>
                    <a href="<?php echo esc_url( self::get_url( 'api_connection' ) ); ?>"
                       class="nxc-btn nxc-btn--primary">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <?php esc_html_e( 'Connect PayPal', 'tejcart' ); ?>
                    </a>
                <?php endif; ?>
                <a href="https://www.paypal.com/businessmanage/account/aboutBusiness"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="nxc-btn nxc-btn--ghost tejcart-paypal-manage__hero-link">
                    <?php esc_html_e( 'Open PayPal dashboard', 'tejcart' ); ?>
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
            </div>
        </header>
        <?php
    }

    /**
     * Render the top "Connect PayPal Account" CTA banner shown on
     * non-connection tabs while onboarding is incomplete.
     *
     * @return void
     */
    private function render_connect_cta(): void {
        $connect_url = self::get_url( 'api_connection' );
        ?>
        <div class="tejcart-paypal-manage-cta" role="alert">
            <span class="tejcart-paypal-manage-cta__icon" aria-hidden="true">
                <span class="dashicons dashicons-info-outline"></span>
            </span>
            <div class="tejcart-paypal-manage-cta__copy">
                <h2><?php esc_html_e( 'Connect your PayPal account to start accepting payments', 'tejcart' ); ?></h2>
                <p><?php esc_html_e( 'One-click onboarding powered by the PayPal Commerce Platform. No REST app or manual credentials required.', 'tejcart' ); ?></p>
            </div>
            <a href="<?php echo esc_url( $connect_url ); ?>" class="nxc-btn nxc-btn--primary tejcart-paypal-manage-cta__button">
                <?php esc_html_e( 'Connect PayPal Account', 'tejcart' ); ?>
                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
            </a>
        </div>
        <?php
    }

    /**
     * Render the vertical sidebar navigation.
     *
     * Tabs are visually grouped (Setup / Payment methods / Customer
     * experience) so high-volume merchants can scan to the section they
     * need at a glance instead of reading a long flat list.
     *
     * @param array  $tabs         Tab definitions.
     * @param string $current_tab  Active tab slug.
     * @param bool   $is_onboarded Whether onboarding is complete.
     * @return void
     */
    private function render_sidebar_nav( array $tabs, string $current_tab, bool $is_onboarded ): void {
        $groups = $this->get_tab_groups();
        ?>
        <aside class="tejcart-paypal-manage__sidebar" aria-label="<?php esc_attr_e( 'PayPal settings sections', 'tejcart' ); ?>">
            <button type="button"
                    class="nxc-btn nxc-btn--ghost tejcart-paypal-manage__sidebar-toggle"
                    aria-controls="tejcart-paypal-manage-nav"
                    aria-expanded="false">
                <span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
                <span><?php echo esc_html( $tabs[ $current_tab ]['label'] ?? __( 'Sections', 'tejcart' ) ); ?></span>
            </button>

            <nav id="tejcart-paypal-manage-nav"
                 class="tejcart-paypal-manage__nav"
                 aria-label="<?php esc_attr_e( 'PayPal settings sections', 'tejcart' ); ?>">
                <?php foreach ( $groups as $group ) : ?>
                    <div class="tejcart-paypal-manage__nav-group">
                        <h3 class="tejcart-paypal-manage__nav-group-label">
                            <?php echo esc_html( $group['label'] ); ?>
                        </h3>
                        <ul class="tejcart-paypal-manage__nav-list">
                            <?php foreach ( $group['tabs'] as $slug ) :
                                if ( ! isset( $tabs[ $slug ] ) ) {
                                    continue;
                                }
                                $tab         = $tabs[ $slug ];
                                $is_active   = ( $slug === $current_tab );
                                $is_tab_lock = ( 'api_connection' !== $slug ) && ! $is_onboarded;
                                $tab_url     = self::get_url( $slug );
                                $classes     = array( 'tejcart-paypal-manage__nav-item' );
                                if ( $is_active ) {
                                    $classes[] = 'is-active';
                                }
                                if ( $is_tab_lock ) {
                                    $classes[] = 'is-locked';
                                }
                                ?>
                                <li>
                                    <a href="<?php echo esc_url( $tab_url ); ?>"
                                       class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                                        <?php if ( ! empty( $tab['icon'] ) ) : ?>
                                            <span class="tejcart-paypal-manage__nav-icon" aria-hidden="true"><?php echo tejcart_kses_svg( (string) $tab['icon'] ); ?></span>
                                        <?php endif; ?>
                                        <span class="tejcart-paypal-manage__nav-label">
                                            <?php echo esc_html( $tab['label'] ); ?>
                                        </span>
                                        <?php if ( $is_tab_lock ) : ?>
                                            <span class="dashicons dashicons-lock tejcart-paypal-manage__nav-lock" aria-hidden="true"></span>
                                            <span class="screen-reader-text"><?php esc_html_e( '(locked)', 'tejcart' ); ?></span>
                                        <?php elseif ( $is_active ) : ?>
                                            <span class="dashicons dashicons-arrow-right-alt2 tejcart-paypal-manage__nav-arrow" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <?php $this->render_sidebar_help(); ?>
        </aside>
        <?php
    }

    /**
     * Render the help / docs callout at the bottom of the sidebar.
     *
     * @return void
     */
    private function render_sidebar_help(): void {
        ?>
        <div class="tejcart-paypal-manage__sidebar-help">
            <div class="tejcart-paypal-manage__sidebar-help-icon" aria-hidden="true">
                <span class="dashicons dashicons-book-alt"></span>
            </div>
            <div class="tejcart-paypal-manage__sidebar-help-copy">
                <strong><?php esc_html_e( 'Need a hand?', 'tejcart' ); ?></strong>
                <p><?php esc_html_e( 'Browse our PayPal setup guide or contact support.', 'tejcart' ); ?></p>
                <a href="https://docs.tejcart.com/" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'View documentation', 'tejcart' ); ?>
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render the main panel header — section title, description, and
     * optional locked badge.
     *
     * @param array  $tab         Active tab definition (label + icon).
     * @param string $current_tab Active tab slug.
     * @param bool   $is_locked   Whether the active tab is soft-locked.
     * @return void
     */
    private function render_main_header( array $tab, string $current_tab, bool $is_locked ): void {
        $descriptions = $this->get_tab_descriptions();
        $description  = $descriptions[ $current_tab ] ?? '';
        ?>
        <header class="tejcart-paypal-manage__main-header">
            <div class="tejcart-paypal-manage__main-heading">
                <div>
                    <h2 id="tejcart-paypal-manage-main-title">
                        <?php echo esc_html( $tab['label'] ?? '' ); ?>
                    </h2>
                    <?php if ( '' !== $description ) : ?>
                        <p class="tejcart-paypal-manage__main-desc">
                            <?php echo esc_html( $description ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <?php
    }

    /**
     * Render the soft-lock notice shown above a locked tab's body.
     *
     * @param string $tab_label Human-readable tab label.
     * @return void
     */
    private function render_locked_notice( string $tab_label ): void {
        $connect_url = self::get_url( 'api_connection' );
        ?>
        <div class="tejcart-paypal-manage-lock-notice" role="status">
            <span class="tejcart-paypal-manage-lock-notice__icon" aria-hidden="true">
                <span class="dashicons dashicons-lock"></span>
            </span>
            <div class="tejcart-paypal-manage-lock-notice__copy">
                <h3>
                    <?php
                    printf(
                        /* translators: %s: tab name. */
                        esc_html__( '%s is locked', 'tejcart' ),
                        esc_html( $tab_label )
                    );
                    ?>
                </h3>
                <p>
                    <?php esc_html_e( 'Connect your PayPal account first to configure this payment method.', 'tejcart' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $connect_url ); ?>" class="nxc-btn nxc-btn--primary">
                <?php esc_html_e( 'Go to PayPal Connection', 'tejcart' ); ?>
                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
            </a>
        </div>
        <?php
    }

    /**
     * Render the sticky save bar that anchors the bottom of the panel.
     *
     * Includes an unsaved-changes indicator the JS toggles when any
     * input in the form receives input/change events.
     *
     * @param bool $is_locked Whether the active tab is soft-locked.
     * @return void
     */
    private function render_save_bar( bool $is_locked ): void {
        ?>
        <footer class="tejcart-paypal-manage-save-bar" role="contentinfo">
            <div class="tejcart-paypal-manage-save-bar__inner">
                <span class="tejcart-paypal-manage-save-bar__status">
                    <?php if ( $is_locked ) : ?>
                        <span class="tejcart-paypal-manage-save-bar__hint">
                            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                            <?php esc_html_e( 'Connect your PayPal account to unlock these settings.', 'tejcart' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="tejcart-paypal-manage-save-bar__saved">
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                            <?php esc_html_e( 'All changes saved', 'tejcart' ); ?>
                        </span>
                        <span class="tejcart-paypal-manage-save-bar__dirty" aria-live="polite">
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <?php esc_html_e( 'You have unsaved changes', 'tejcart' ); ?>
                        </span>
                    <?php endif; ?>
                </span>

                <div class="tejcart-paypal-manage-save-bar__actions">
                    <button type="button"
                            class="nxc-btn nxc-btn--ghost tejcart-paypal-manage-save-bar__discard"
                            data-action="discard"
                            <?php disabled( $is_locked ); ?>>
                        <?php esc_html_e( 'Discard', 'tejcart' ); ?>
                    </button>
                    <button type="submit" class="nxc-btn nxc-btn--primary button button-primary" <?php disabled( $is_locked ); ?>>
                        <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                        <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                    </button>
                </div>
            </div>
        </footer>
        <?php
    }

    /**
     * Tab grouping for the sidebar.
     *
     * Order is intentional — Setup first (so onboarding is the path of
     * least resistance), then payment methods themselves, then customer
     * experience tweaks the merchant typically configures last.
     *
     * @return array<int, array{label:string, tabs:string[]}>
     */
    private function get_tab_groups(): array {
        return array(
            array(
                'label' => __( 'Setup', 'tejcart' ),
                'tabs'  => array( 'api_connection' ),
            ),
            array(
                'label' => __( 'Payment methods', 'tejcart' ),
                'tabs'  => array( 'paypal', 'card', 'googlepay', 'applepay', 'paylater' ),
            ),
        );
    }

    /**
     * One-line description per tab, shown beneath the tab title in the
     * main panel header.
     *
     * @return array<string, string>
     */
    private function get_tab_descriptions(): array {
        return array(
            'api_connection' => __( 'Connect your PayPal account using one-click onboarding or paste your REST credentials.', 'tejcart' ),
            'paypal'         => __( 'Configure the PayPal Smart Button — branding, checkout flow, and where the button appears.', 'tejcart' ),
            'card'           => __( 'Accept credit and debit cards directly on your checkout, with optional 3D Secure protection.', 'tejcart' ),
            'googlepay'      => __( 'Let shoppers pay with Google Pay on supported browsers and devices.', 'tejcart' ),
            'applepay'       => __( 'Let shoppers pay with Apple Pay in Safari on supported Apple devices.', 'tejcart' ),
            'paylater'       => __( 'Show Pay Later messaging on product, cart, and checkout pages — control placements and styling.', 'tejcart' ),
        );
    }

    /**
     * Render the field rows for the current tab.
     *
     * Walks each (gateway_id, field_ids) entry in the tab definition and
     * renders the requested fields. Passing an empty array for field_ids
     * renders all of the gateway's fields.
     *
     * @param string $current_tab Tab slug.
     * @return void
     */
    private function render_tab_fields( string $current_tab ): void {
        $sections = $this->get_tab_sections( $current_tab );

        $hide_manual                  = false;
        $other_env_has_saved_creds    = false;
        $other_env_label              = '';
        if ( 'api_connection' === $current_tab ) {
            $snapshot    = \TejCart\Gateways\PayPal\PayPal_Onboarding::instance()->get_status_snapshot();
            $hide_manual = ( 'sandbox' === $snapshot['active_environment'] )
                ? ! empty( $snapshot['sandbox']['connected'] )
                : ! empty( $snapshot['live']['connected'] );

            $paypal_gateway = $this->registry->get_gateway( 'tejcart_paypal' );
            if ( $paypal_gateway ) {
                $active_is_sandbox = 'yes' === $paypal_gateway->get_option( 'sandbox_mode', 'yes' );
                if ( $active_is_sandbox ) {
                    $other_env_has_saved_creds = '' !== (string) $paypal_gateway->get_option( 'client_id', '' )
                        || '' !== (string) $paypal_gateway->get_option( 'client_secret', '' );
                    $other_env_label = __( 'Live', 'tejcart' );
                } else {
                    $other_env_has_saved_creds = '' !== (string) $paypal_gateway->get_option( 'sandbox_client_id', '' )
                        || '' !== (string) $paypal_gateway->get_option( 'sandbox_client_secret', '' );
                    $other_env_label = __( 'Sandbox', 'tejcart' );
                }
            }
        }

        foreach ( $sections as $section ) {
            $gateway_id = $section['gateway'];
            $field_ids  = $section['fields'];
            $gateway    = $this->registry->get_gateway( $gateway_id );

            if ( ! $gateway ) {
                continue;
            }

            $all_fields = $gateway->get_form_fields();
            $fields     = array();

            if ( empty( $field_ids ) ) {
                $fields = $all_fields;
            } else {
                foreach ( $field_ids as $field_id ) {
                    if ( isset( $all_fields[ $field_id ] ) ) {
                        $fields[ $field_id ] = $all_fields[ $field_id ];
                    }
                }
            }

            $collapse_group = '';
            $collapse_open  = false;

            foreach ( $fields as $field_id => $field ) {
                $type = $field['type'] ?? 'text';

                if (
                    'api_connection' === $current_tab
                    && 'credentials_heading' === $field_id
                    && $other_env_has_saved_creds
                ) {
                    $existing_desc = (string) ( $field['description'] ?? '' );
                    $field['description'] = trim(
                        $existing_desc . ' ' . sprintf(
                            /* translators: %s: environment name, e.g. "Live" or "Sandbox". */
                            __( '%s credentials are saved — switch Environment above to edit them.', 'tejcart' ),
                            $other_env_label
                        )
                    );
                }

                if ( 'heading' === $type ) {
                    if ( ! empty( $field['collapsible'] ) ) {
                        $collapse_group = sanitize_html_class( 'group-' . $field_id );
                        $collapse_open  = empty( $field['collapsed'] );
                    } else {
                        $collapse_group = '';
                        $collapse_open  = false;
                    }
                    $this->render_field_row( $gateway, $field_id, $field, '', false, $hide_manual );
                    continue;
                }

                $this->render_field_row( $gateway, $field_id, $field, $collapse_group, $collapse_open, $hide_manual );
            }
        }

        if ( 'paypal' === $current_tab ) {
            $this->render_paypal_button_preview();
        }
    }

    /**
     * Render a live preview of the PayPal Smart Button using PayPal's
     * official JS SDK. The container is hydrated on the client:
     * tejcart-paypal-onboarding.js reads the data attributes, lazy-loads
     * the SDK from www.paypal.com/sdk/js, and renders a real
     * paypal.Buttons() instance — then re-renders on every style change.
     *
     * If the merchant hasn't onboarded yet (no client_id for the active
     * environment) we render an empty-state card instead of a broken
     * button, since the SDK requires a valid client_id to load.
     *
     * @return void
     */
    private function render_paypal_button_preview(): void {
        $gateway = $this->registry->get_gateway( 'tejcart_paypal' );
        if ( ! $gateway ) {
            return;
        }

        $is_sandbox = 'yes' === $gateway->get_option( 'sandbox_mode', 'yes' );
        $client_id  = $is_sandbox
            ? (string) $gateway->get_option( 'sandbox_client_id', '' )
            : (string) $gateway->get_option( 'client_id', '' );
        $environment = $is_sandbox ? 'sandbox' : 'live';
        $currency    = (string) tejcart_get_setting( 'currency', 'USD' );

        $color   = (string) $gateway->get_option( 'button_color', 'gold' );
        $shape   = (string) $gateway->get_option( 'button_shape', 'rect' );
        $label   = (string) $gateway->get_option( 'button_label', 'paypal' );
        $layout  = (string) $gateway->get_option( 'button_layout', 'vertical' );
        $tagline = 'yes' === $gateway->get_option( 'button_tagline', 'no' );

        $height_int = (int) $gateway->get_option( 'button_height', '45' );
        if ( $height_int < 25 || $height_int > 55 ) {
            $height_int = 45;
        }
        ?>
        <tr class="tejcart-field-heading">
            <td colspan="2">
                <h3><?php esc_html_e( 'Button Preview', 'tejcart' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Live PayPal Smart Button rendered by the PayPal JS SDK. Updates in real time as you change the style controls above.', 'tejcart' ); ?>
                </p>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <?php if ( '' === $client_id ) : ?>
                    <div class="tejcart-paypal-button-preview__empty">
                        <?php esc_html_e( 'Connect a PayPal account to see a live Smart Button preview here.', 'tejcart' ); ?>
                    </div>
                <?php else : ?>
                    <div class="tejcart-paypal-button-preview"
                         data-client-id="<?php echo esc_attr( $client_id ); ?>"
                         data-environment="<?php echo esc_attr( $environment ); ?>"
                         data-currency="<?php echo esc_attr( $currency ); ?>"
                         data-partner-attribution-id="<?php echo esc_attr( \TejCart\Gateways\PayPal\PayPal_Gateway::BN_CODE ); ?>"
                         data-color="<?php echo esc_attr( $color ); ?>"
                         data-shape="<?php echo esc_attr( $shape ); ?>"
                         data-label="<?php echo esc_attr( $label ); ?>"
                         data-layout="<?php echo esc_attr( $layout ); ?>"
                         data-tagline="<?php echo $tagline ? 'true' : 'false'; ?>"
                         data-height="<?php echo esc_attr( (string) $height_int ); ?>">
                        <div class="tejcart-paypal-button-preview__toolbar" role="group" aria-label="<?php esc_attr_e( 'Preview viewport', 'tejcart' ); ?>">
                            <div class="tejcart-paypal-button-preview__chips">
                                <button type="button"
                                        class="tejcart-paypal-button-preview__chip"
                                        data-viewport="mobile"
                                        data-width="375"
                                        aria-pressed="false">
                                    <span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
                                    <span><?php esc_html_e( 'Mobile', 'tejcart' ); ?></span>
                                </button>
                                <button type="button"
                                        class="tejcart-paypal-button-preview__chip"
                                        data-viewport="tablet"
                                        data-width="768"
                                        aria-pressed="false">
                                    <span class="dashicons dashicons-tablet" aria-hidden="true"></span>
                                    <span><?php esc_html_e( 'Tablet', 'tejcart' ); ?></span>
                                </button>
                                <button type="button"
                                        class="tejcart-paypal-button-preview__chip is-active"
                                        data-viewport="desktop"
                                        data-width=""
                                        aria-pressed="true">
                                    <span class="dashicons dashicons-desktop" aria-hidden="true"></span>
                                    <span><?php esc_html_e( 'Desktop', 'tejcart' ); ?></span>
                                </button>
                            </div>
                            <span class="tejcart-paypal-button-preview__dimensions" aria-live="polite">
                                <?php esc_html_e( 'Full width', 'tejcart' ); ?>
                            </span>
                        </div>
                        <div class="tejcart-paypal-button-preview__canvas">
                            <div class="tejcart-paypal-button-preview__device" data-viewport="desktop">
                                <div class="tejcart-paypal-button-preview__frame"
                                     id="tejcart-paypal-button-preview-container">
                                    <div class="tejcart-paypal-button-preview__loading">
                                        <?php esc_html_e( 'Loading PayPal Smart Button…', 'tejcart' ); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render a single field row using the shared Payment_Method_Settings helpers.
     *
     * @param Abstract_Gateway $gateway        Gateway instance the field belongs to.
     * @param string           $field_id       Field ID.
     * @param array            $field          Field definition.
     * @param string           $collapse_group Optional collapse group slug this row belongs to.
     * @param bool             $collapse_open  Whether the collapse group starts open.
     * @param bool             $hide_manual    Whether Manual credentials rows should be hidden on render.
     * @return void
     */
    private function render_field_row(
        Abstract_Gateway $gateway,
        string $field_id,
        array $field,
        string $collapse_group = '',
        bool $collapse_open = false,
        bool $hide_manual = false
    ): void {
        $type = $field['type'] ?? 'text';

        $parent_attr        = '';
        $parent_hidden_class = '';
        if ( ! empty( $field['parent'] ) && is_string( $field['parent'] ) ) {
            $parent_field_name = $this->field_renderer->get_field_name( $gateway->get_id(), $field['parent'] );
            $parent_attr       = ' data-parent="' . esc_attr( $parent_field_name ) . '"';
            $parent_value      = (string) $gateway->get_option( $field['parent'], '' );
            if ( 'yes' !== $parent_value ) {
                $parent_hidden_class = ' is-parent-hidden';
            }
        }

        if ( 'heading' === $type ) {
            $is_collapsible = ! empty( $field['collapsible'] );
            $is_collapsed   = $is_collapsible && ! empty( $field['collapsed'] );
            $classes        = 'tejcart-field-heading';
            $row_id_attr    = '';
            if ( $is_collapsible ) {
                $classes .= ' is-collapsible';
                if ( $is_collapsed ) {
                    $classes .= ' is-collapsed';
                }

                $classes    .= ' tejcart-paypal-manual-section';
                $row_id_attr = ' id="tejcart-paypal-manual-credentials"';
                if ( $hide_manual ) {
                    $classes .= ' is-connected-hidden';
                }
            }
            $classes .= $parent_hidden_class;

            echo '<tr class="' . esc_attr( $classes ) . '"' . $row_id_attr . $parent_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs pre-escaped
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

        $row_classes = array();
        $row_attrs   = '';
        if ( '' !== $collapse_group ) {
            $row_classes[] = 'tejcart-collapsible-row';
            $row_classes[] = 'tejcart-collapse-member';

            $row_classes[] = 'tejcart-paypal-manual-section';
            $row_attrs    .= ' data-collapse-group="' . esc_attr( $collapse_group ) . '"';
            if ( ! $collapse_open ) {
                $row_classes[] = 'is-hidden';
            }
            if ( $hide_manual ) {
                $row_classes[] = 'is-connected-hidden';
            }
        }

        if ( '' !== $parent_attr ) {
            $row_attrs .= $parent_attr;
            if ( '' !== $parent_hidden_class ) {
                $row_classes[] = ltrim( $parent_hidden_class );
            }
        }

        $field_env = isset( $field['env'] ) ? (string) $field['env'] : '';
        if ( '' !== $field_env ) {
            $current_env = 'yes' === $gateway->get_option( 'sandbox_mode', 'yes' ) ? 'sandbox' : 'live';
            $row_attrs  .= ' data-env="' . esc_attr( $field_env ) . '"';
            if ( $field_env !== $current_env ) {
                $row_classes[] = 'is-env-hidden';
            }
        }

        if ( 'connection' === $type ) {
            $is_sandbox   = 'yes' === $gateway->get_option( 'sandbox_mode', 'yes' );
            $snapshot     = \TejCart\Gateways\PayPal\PayPal_Onboarding::instance()->get_status_snapshot();
            $is_connected = ( 'sandbox' === $snapshot['active_environment'] )
                ? ! empty( $snapshot['sandbox']['connected'] )
                : ! empty( $snapshot['live']['connected'] );

            if ( $is_connected ) {
                $connect_lbl = __( 'Connection Status', 'tejcart' );
            } else {
                $connect_lbl = $is_sandbox
                    ? __( 'Connect to PayPal Sandbox', 'tejcart' )
                    : __( 'Connect to PayPal Live', 'tejcart' );
            }

            $row_classes[] = 'tejcart-field-connection';
            echo '<tr class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' . $row_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs pre-escaped
            echo '<th scope="row">' . esc_html( $connect_lbl ) . '</th>';
            echo '<td>';
            $this->field_renderer->render_paypal_connection_card( $gateway );
            echo '</td>';
            echo '</tr>';
            return;
        }

        if ( 'note' === $type ) {
            $row_classes[] = 'tejcart-field-note';
            echo '<tr class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' . $row_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs pre-escaped
            echo '<th scope="row">' . esc_html( $field['title'] ?? '' ) . '</th>';
            echo '<td><p class="description">' . wp_kses_post( $field['description'] ?? '' ) . '</p></td>';
            echo '</tr>';
            return;
        }

        $field_name = $this->field_renderer->get_field_name( $gateway->get_id(), $field_id );
        $value      = $gateway->get_option( $field_id, $field['default'] ?? '' );
        $label      = $field['title'] ?? '';
        $desc       = $field['description'] ?? '';

        echo '<tr' . ( $row_classes ? ' class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' : '' ) . $row_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs pre-escaped
        echo '<th scope="row"><label for="' . esc_attr( $field_name ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td>';
        $this->field_renderer->render_field_input( $type, $field_name, $value, $field );
        if ( ! empty( $desc ) ) {
            echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
        }
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Handle a POST save for the given tab.
     *
     * Walks each (gateway_id, field_ids) entry in the tab definition, reads
     * posted values for those fields, sanitizes them, and persists to the
     * owning gateway's options.
     *
     * @param string $current_tab Tab slug.
     * @return void
     */
    private function save_tab( string $current_tab ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_paypal_manage_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_paypal_manage_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_paypal_manage_save_' . $current_tab ) ) {
            add_settings_error(
                'tejcart_paypal_manage',
                'tejcart_nonce_error',
                __( 'Security check failed. Please try again.', 'tejcart' ),
                'error'
            );
            return;
        }

        $sections = $this->get_tab_sections( $current_tab );
        $touched  = array();

        foreach ( $sections as $section ) {
            $gateway_id = $section['gateway'];
            $field_ids  = $section['fields'];
            $gateway    = $this->registry->get_gateway( $gateway_id );

            if ( ! $gateway ) {
                continue;
            }

            $all_fields = $gateway->get_form_fields();
            $fields     = empty( $field_ids )
                ? $all_fields
                : array_intersect_key( $all_fields, array_flip( $field_ids ) );

            foreach ( $fields as $field_id => $field ) {
                $field_type = $field['type'] ?? 'text';

                if ( in_array( $field_type, array( 'heading', 'note', 'readonly', 'connection' ), true ) ) {
                    continue;
                }

                $field_name = $this->field_renderer->get_field_name( $gateway_id, $field_id );

                // Nonce verified above. Sanitization is delegated to sanitize_field_value()
                // because each field type has its own coercion (bool, int, textarea, key…).
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $raw_value = isset( $_POST[ $field_name ] ) ? wp_unslash( $_POST[ $field_name ] ) : null;
                $sanitized = $this->field_renderer->sanitize_field_value( $field_type, $raw_value );

                $gateway->update_option( $field_id, $sanitized );
                $touched[ $gateway_id ] = true;
            }
        }

        foreach ( array_keys( $touched ) as $gateway_id ) {
            $gateway = $this->registry->get_gateway( $gateway_id );
            if ( $gateway ) {
                $gateway->save_settings();
            }
        }

        add_settings_error(
            'tejcart_paypal_manage',
            'tejcart_settings_saved',
            __( 'Settings saved.', 'tejcart' ),
            'updated'
        );
    }

    /**
     * Get the list of tabs for the manage page.
     *
     * @return array
     */
    private function get_tabs(): array {
        return array(
            'api_connection' => array(
                'label' => __( 'PayPal Connection', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'connection' ),
            ),
            'paypal'         => array(
                'label' => __( 'PayPal Settings', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'paypal' ),
            ),
            'card'           => array(
                'label' => __( 'Card Payments', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'card' ),
            ),
            'googlepay'      => array(
                'label' => __( 'Google Pay', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'mobile' ),
            ),
            'applepay'       => array(
                'label' => __( 'Apple Pay', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'mobile' ),
            ),
            'paylater'       => array(
                'label' => __( 'Pay Later', 'tejcart' ),
                'icon'  => self::get_nav_icon_svg( 'paylater' ),
            ),
        );
    }

    /**
     * Inline SVG markup for the sidebar nav icons.
     *
     * Returned as raw SVG so all five tabs render at identical 16×16 metrics
     * regardless of which dashicons happen to ship with the host WP version
     * (notably, no `dashicons-credit-card` exists in core).
     *
     * @param string $name Icon key.
     * @return string
     */
    private static function get_nav_icon_svg( string $name ): string {
        $attrs = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"';

        switch ( $name ) {
            case 'connection':
                return '<svg ' . $attrs . '><path d="M6.5 9.5l3-3"/><path d="M9.25 4.25L10.5 3a3 3 0 014.243 4.243L13.5 8.5"/><path d="M2.5 7.5L3.75 6.25a3 3 0 014.243 4.243L6.75 11.75"/><path d="M6.75 11.75L5.5 13a3 3 0 01-4.243-4.243L2.5 7.5"/></svg>';
            case 'paypal':
                return '<svg ' . $attrs . '><circle cx="8" cy="8" r="6.5"/><path d="M9.75 6.25c-.25-.75-1-1.25-1.75-1.25-1 0-1.75.75-1.75 1.5s.5 1.25 1.75 1.5 1.75.75 1.75 1.5-.75 1.5-1.75 1.5c-.75 0-1.5-.5-1.75-1.25"/><path d="M8 4.25v1M8 10.75v1"/></svg>';
            case 'card':
                return '<svg ' . $attrs . '><rect x="1.5" y="3" width="13" height="10" rx="1.5"/><path d="M1.5 6.5h13"/><path d="M4 10h2.5"/><path d="M9 10h3"/></svg>';
            case 'mobile':
                return '<svg ' . $attrs . '><rect x="4.25" y="1.5" width="7.5" height="13" rx="1.5"/><path d="M7 12.25h2"/></svg>';
            case 'paylater':
                return '<svg ' . $attrs . '><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.25 1.5"/></svg>';
            default:
                return '';
        }
    }

    /**
     * Return the (gateway_id, field_ids[]) sections for a given tab.
     *
     * An empty `fields` array means "all fields of that gateway".
     *
     * @param string $tab Tab slug.
     * @return array
     */
    private function get_tab_sections( string $tab ): array {
        switch ( $tab ) {
            case 'api_connection':
                return array(
                    array(
                        'gateway' => 'tejcart_paypal',
                        'fields'  => array(
                            'sandbox_mode',
                            'paypal_connection',
                            'credentials_heading',
                            'client_id',
                            'client_secret',
                            'sandbox_client_id',
                            'sandbox_client_secret',
                        ),
                    ),
                );

            case 'paypal':
                return array(
                    array(
                        'gateway' => 'tejcart_paypal',
                        'fields'  => array(
                            'general_heading',
                            'enabled',
                            'title',
                            'description',
                            'checkout_heading',
                            'brand_name',
                            'soft_descriptor',
                            'invoice_prefix',
                            'locale',
                            'landing_page',
                            'shipping_preference',
                            'user_action',
                            'payment_action',
                            'save_payment_methods',
                            'send_line_items',
                            'capture_virtual_only',
                            'subgateways_heading',
                            'enable_venmo',
                            'smart_button_heading',
                            'button_product_page',
                            'button_cart_page',
                            'button_express_checkout',
                            'button_side_cart',
                            'button_checkout',
                            'button_style_heading',
                            'button_layout',
                            'button_color',
                            'button_shape',
                            'button_label',
                            'button_tagline',
                            'button_height',
                            'advanced_heading',
                            'disable_funding',
                            'disable_cards',
                        ),
                    ),
                );

            case 'card':
                return array(
                    array(
                        'gateway' => 'tejcart_card',
                        'fields'  => array(),
                    ),
                    array(
                        'gateway' => 'tejcart_paypal',
                        'fields'  => array(
                            'disable_cards',
                        ),
                    ),
                );

            case 'googlepay':
                return array(
                    array(
                        'gateway' => 'tejcart_googlepay',
                        'fields'  => array(),
                    ),
                );

            case 'applepay':
                return array(
                    array(
                        'gateway' => 'tejcart_applepay',
                        'fields'  => array(),
                    ),
                );

            case 'paylater':
                return array(
                    array(
                        'gateway' => 'tejcart_paypal',
                        'fields'  => array(
                            'enable_paylater',
                            'paylater_placements_heading',
                            'paylater_product_page',
                            'paylater_cart_page',
                            'paylater_side_cart',
                            'paylater_checkout',
                            'paylater_express_checkout',
                            'paylater_style_heading',
                            'paylater_style_layout',
                            'paylater_style_logo_type',
                            'paylater_style_text_color',
                        ),
                    ),
                );
        }

        return array();
    }

    /**
     * Get the currently selected tab, falling back to a sensible default.
     *
     * If the merchant has not onboarded yet, the default is the API
     * Connection tab. Otherwise, it defaults to the main PayPal tab.
     *
     * @param bool $is_onboarded Whether PayPal onboarding is complete.
     * @return string
     */
    private function get_current_tab( bool $is_onboarded ): string {
        $tabs = $this->get_tabs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
        $requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

        if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
            return $requested;
        }

        return $is_onboarded ? 'paypal' : 'api_connection';
    }

    /**
     * Get the URL for the PayPal Manage page, optionally with a specific tab.
     *
     * @param string $tab Tab slug (optional).
     * @return string
     */
    public static function get_url( string $tab = '' ): string {
        $args = array( 'page' => self::PAGE_SLUG );
        if ( '' !== $tab ) {
            $args['tab'] = $tab;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Inline PayPal brand SVG used in the page header.
     *
     * @return string
     */
    private static function get_paypal_logo_svg(): string {
        return self::PAYPAL_LOGO_SVG_OPEN
            . ' width="40" height="48" focusable="false">'
            . self::PAYPAL_LOGO_SVG_PATHS
            . '</svg>';
    }
}
