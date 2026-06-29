<?php
/**
 * Main Customer Account template.
 *
 * Orchestrates the account shell: page header, grouped sidebar nav,
 * and dispatch to the active sub-tab's template.
 *
 * All hooks, filters, tab slugs, and URL query parameters from the
 * pre-redesign template are preserved so downstream plugins (custom
 * tabs via tejcart_account_tabs, Payment Methods, etc.) keep working
 * without changes.
 *
 * @package TejCart\Templates\Account
 *
 * @var int    $customer_id  Current customer user ID.
 * @var array  $orders       Customer orders.
 * @var array  $addresses    Customer addresses.
 * @var string $current_tab  Active tab slug.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_user = wp_get_current_user();
$account_url  = get_permalink();

$tabs = apply_filters( 'tejcart_account_tabs', array(
    'dashboard'       => __( 'Dashboard', 'tejcart' ),
    'orders'          => __( 'Orders', 'tejcart' ),
    'downloads'       => __( 'Downloads', 'tejcart' ),
    'addresses'       => __( 'Addresses', 'tejcart' ),
    'account-details' => __( 'Account Details', 'tejcart' ),
) );

$tab_icons = array(
    'dashboard'       => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>',
    'orders'          => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>',
    'downloads'       => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>',
    'addresses'       => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>',
    'account-details' => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>',
    'payment-methods' => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>',
);

$tab_groups = array(
    'shop'     => array(
        'label' => __( 'Shop', 'tejcart' ),
        'tabs'  => array( 'dashboard', 'orders', 'downloads' ),
    ),
    'settings' => array(
        'label' => __( 'Settings', 'tejcart' ),
        'tabs'  => array( 'addresses', 'payment-methods', 'account-details' ),
    ),
);

$known_tabs = array_merge( $tab_groups['shop']['tabs'], $tab_groups['settings']['tabs'] );
$other_tabs = array_values( array_diff( array_keys( $tabs ), $known_tabs ) );

/**
 * Fires before the account content wrapper.
 *
 * @param int $customer_id The current customer ID.
 */
do_action( 'tejcart_before_account_content', $customer_id );

// The `tejcart_account_brand_color` filter (returning any truthy CSS
// color) is read in Frontend\Frontend::enqueue_assets() and attached
// via wp_add_inline_style() against the `tejcart-account` handle —
// wordpress.org review forbids raw <style> tags inside templates.
?>

<div class="tejcart-account" data-tejcart-account>

    <a class="tejcart-account__skip-link" href="#tejcart-account-main">
        <?php esc_html_e( 'Skip to main content', 'tejcart' ); ?>
    </a>

    <header class="tejcart-account__page-header">
        <p class="tejcart-account__page-subtitle">
            <?php
            printf(
                wp_kses(
                    /* translators: %s: customer email wrapped in <strong> */
                    __( 'Signed in as %s', 'tejcart' ),
                    array( 'strong' => array() )
                ),
                '<strong>' . esc_html( $current_user->user_email ) . '</strong>'
            );
            ?>
        </p>
    </header>

    <aside class="tejcart-account__sidebar">
        <nav
            class="tejcart-account-nav"
            aria-label="<?php esc_attr_e( 'Account navigation', 'tejcart' ); ?>"
            data-tejcart-nav
        >
            <?php
            /*
             * F-FE-012: aria-expanded="true" was hardcoded — AT announced "expanded" on
             * initial page load even when the nav is visually hidden on mobile.
             * Default to "false" (collapsed). tejcart-account-nav.js sets the correct
             * value after reading the viewport on DOMContentLoaded.
             */
            ?>
            <button
                type="button"
                class="tejcart-account-nav__toggle"
                aria-expanded="false"
                aria-controls="tejcart-account-nav-groups"
                data-tejcart-nav-toggle
            >
                <span><?php esc_html_e( 'Menu', 'tejcart' ); ?></span>
                <span class="tejcart-account-nav__toggle-chevron" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </span>
            </button>

            <div id="tejcart-account-nav-groups" class="tejcart-account-nav__groups">

                <div class="tejcart-account-nav__identity">
                    <div class="tejcart-account-nav__avatar">
                        <?php echo get_avatar( $current_user->ID, 40 ); ?>
                    </div>
                    <div>
                        <span class="tejcart-account-nav__name"><?php echo esc_html( $current_user->display_name ); ?></span>
                        <span class="tejcart-account-nav__email"><?php echo esc_html( $current_user->user_email ); ?></span>
                    </div>
                </div>

                <?php foreach ( $tab_groups as $group_key => $group ) :
                    $group_tabs = array_values( array_filter(
                        $group['tabs'],
                        static function ( $tab ) use ( $tabs ) {
                            return isset( $tabs[ $tab ] );
                        }
                    ) );
                    if ( empty( $group_tabs ) ) {
                        continue;
                    }
                    ?>
                    <ul
                        class="tejcart-account-nav__group"
                        aria-label="<?php echo esc_attr( $group['label'] ); ?>"
                    >
                        <li class="tejcart-account-nav__group-label" aria-hidden="true">
                            <?php echo esc_html( $group['label'] ); ?>
                        </li>
                        <?php foreach ( $group_tabs as $tab ) :
                            $is_current = ( $current_tab === $tab );
                            ?>
                            <li class="tejcart-account-nav__item">
                                <a
                                    class="tejcart-account-nav__link"
                                    href="<?php echo esc_url( add_query_arg( 'tab', $tab, $account_url ) ); ?>"
                                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                                >
                                    <span class="tejcart-account-nav__icon" aria-hidden="true">
                                        <?php
                                        // SVG markup defined statically in this file (see $tab_icons array above).
                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo isset( $tab_icons[ $tab ] ) ? $tab_icons[ $tab ] : '';
                                        ?>
                                    </span>
                                    <span class="tejcart-account-nav__label"><?php echo esc_html( $tabs[ $tab ] ); ?></span>
                                    <?php if ( 'orders' === $tab && ! empty( $orders ) ) : ?>
                                        <span class="tejcart-account-nav__badge"><?php echo esc_html( (string) count( $orders ) ); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>

                <?php if ( ! empty( $other_tabs ) ) : ?>
                    <ul class="tejcart-account-nav__group" aria-label="<?php esc_attr_e( 'More', 'tejcart' ); ?>">
                        <li class="tejcart-account-nav__group-label" aria-hidden="true">
                            <?php esc_html_e( 'More', 'tejcart' ); ?>
                        </li>
                        <?php foreach ( $other_tabs as $tab ) :
                            $is_current = ( $current_tab === $tab );
                            ?>
                            <li class="tejcart-account-nav__item">
                                <a
                                    class="tejcart-account-nav__link"
                                    href="<?php echo esc_url( add_query_arg( 'tab', $tab, $account_url ) ); ?>"
                                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                                >
                                    <span class="tejcart-account-nav__icon" aria-hidden="true">
                                        <?php
                                        // SVG markup defined statically in this file (see $tab_icons array above).
                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo isset( $tab_icons[ $tab ] ) ? $tab_icons[ $tab ] : '';
                                        ?>
                                    </span>
                                    <span class="tejcart-account-nav__label"><?php echo esc_html( $tabs[ $tab ] ); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <ul class="tejcart-account-nav__group" aria-label="<?php esc_attr_e( 'Session', 'tejcart' ); ?>">
                    <li class="tejcart-account-nav__item">
                        <a
                            class="tejcart-account-nav__link tejcart-account-nav__link--logout"
                            href="<?php echo esc_url( wp_logout_url( $account_url ) ); ?>"
                        >
                            <span class="tejcart-account-nav__icon" aria-hidden="true">
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                            </span>
                            <span class="tejcart-account-nav__label"><?php esc_html_e( 'Sign out', 'tejcart' ); ?></span>
                        </a>
                    </li>
                </ul>

            </div>
        </nav>
    </aside>

    <main id="tejcart-account-main" class="tejcart-account__main" tabindex="-1">

        <?php if ( 'dashboard' === $current_tab ) : ?>

            <?php include __DIR__ . '/dashboard.php'; ?>

        <?php elseif ( 'orders' === $current_tab ) : ?>

            <?php include __DIR__ . '/orders.php'; ?>

        <?php elseif ( 'view-order' === $current_tab ) : ?>

            <?php include __DIR__ . '/view-order.php'; ?>

        <?php elseif ( 'downloads' === $current_tab ) : ?>

            <?php include __DIR__ . '/downloads.php'; ?>

        <?php elseif ( 'addresses' === $current_tab ) : ?>

            <?php include __DIR__ . '/addresses.php'; ?>

        <?php elseif ( 'account-details' === $current_tab ) : ?>

            <?php include __DIR__ . '/account-details.php'; ?>

        <?php else : ?>

            <?php
            /**
             * Fires for custom / unknown account tabs so extensions can render content.
             *
             * @param string $current_tab The current tab slug.
             */
            do_action( 'tejcart_account_tab_content', $current_tab );
            ?>

        <?php endif; ?>

    </main>

</div>

<?php
/**
 * Fires after the account content wrapper.
 *
 * @param int $customer_id The current customer ID.
 */
do_action( 'tejcart_after_account_content', $customer_id );
