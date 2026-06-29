<?php
/**
 * Downloads history template.
 *
 * Lists the customer's purchased digital products with remaining
 * downloads, expiry, and secure download links.
 *
 * @package TejCart\Templates\Account
 *
 * @var int $customer_id Current customer user ID.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$downloads = function_exists( 'tejcart_get_customer_downloads' )
    ? tejcart_get_customer_downloads( $customer_id )
    : array();

$shop_url = apply_filters( 'tejcart_shop_url', home_url( '/shop/' ) );
?>

<div class="tejcart-account-downloads">

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title"><?php esc_html_e( 'Downloads', 'tejcart' ); ?></h2>
            <p class="tejcart-account-subpage-header__subtitle">
                <?php esc_html_e( 'Secure download links for every digital product you own. Links are tied to your account and expire per the seller policy.', 'tejcart' ); ?>
            </p>
        </div>
    </header>

    <?php if ( empty( $downloads ) ) : ?>

        <section class="tejcart-account-card">
            <div class="tejcart-account-empty">
                <span class="tejcart-account-empty__icon" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                </span>
                <h3 class="tejcart-account-empty__title"><?php esc_html_e( 'No downloads available', 'tejcart' ); ?></h3>
                <p class="tejcart-account-empty__body"><?php esc_html_e( 'When you purchase downloadable products they will appear here with secure, signed links.', 'tejcart' ); ?></p>
                <div class="tejcart-account-empty__actions">
                    <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
                        <?php esc_html_e( 'Browse products', 'tejcart' ); ?>
                    </a>
                </div>
            </div>
        </section>

    <?php else : ?>

        <section class="tejcart-account-card tejcart-account-card--flush">
            <div class="tejcart-account-card__body tejcart-account-card__body--table">
                <table class="tejcart-account-table" aria-label="<?php esc_attr_e( 'Downloadable products', 'tejcart' ); ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Purchased', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Expires', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Remaining', 'tejcart' ); ?></th>
                            <th scope="col" class="tejcart-account-table__align-end">
                                <span class="screen-reader-text"><?php esc_html_e( 'Download', 'tejcart' ); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $downloads as $download ) :
                            $remaining = $download->get_downloads_remaining();
                            $download_url = method_exists( $download, 'get_url' ) ? $download->get_url() : '#';
                            $exhausted    = ( null !== $remaining && $remaining <= 0 );
                            ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Product', 'tejcart' ); ?>">
                                    <span class="tejcart-account-table__link"><?php echo esc_html( $download->get_product_name() ); ?></span>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Purchased', 'tejcart' ); ?>">
                                    <?php echo esc_html( $download->get_date() ); ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Expires', 'tejcart' ); ?>">
                                    <?php echo esc_html( method_exists( $download, 'get_expires' ) ? $download->get_expires() : __( 'Never', 'tejcart' ) ); ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Remaining', 'tejcart' ); ?>">
                                    <?php if ( null === $remaining ) : ?>
                                        <span class="tejcart-status-badge tejcart-status-badge--completed">
                                            <?php esc_html_e( 'Unlimited', 'tejcart' ); ?>
                                        </span>
                                    <?php elseif ( $remaining > 0 ) : ?>
                                        <span class="tejcart-status-badge tejcart-status-badge--processing">
                                            <?php
                                            echo esc_html( sprintf(
                                                /* translators: %d: remaining downloads */
                                                _n( '%d remaining', '%d remaining', $remaining, 'tejcart' ),
                                                (int) $remaining
                                            ) );
                                            ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="tejcart-status-badge tejcart-status-badge--failed">
                                            <?php esc_html_e( 'Exhausted', 'tejcart' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Download', 'tejcart' ); ?>" class="tejcart-account-table__align-end">
                                    <div class="tejcart-account-table__actions">
                                        <a
                                            class="tejcart-btn tejcart-btn--small tejcart-btn--primary <?php echo $exhausted ? 'is-disabled' : ''; ?>"
                                            href="<?php echo esc_url( $download_url ); ?>"
                                            <?php echo $exhausted ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
                                        >
                                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                            <?php esc_html_e( 'Download', 'tejcart' ); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>

</div>
