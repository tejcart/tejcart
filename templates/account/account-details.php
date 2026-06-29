<?php
/**
 * Account details template.
 *
 * Allows the customer to update their personal info, change their
 * password, and (in the danger zone) delete their account.
 *
 * @package TejCart\Templates\Account
 *
 * @var int $customer_id Current customer user ID.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_user = wp_get_current_user();
?>

<div class="tejcart-account-details">

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title"><?php esc_html_e( 'Account details', 'tejcart' ); ?></h2>
            <p class="tejcart-account-subpage-header__subtitle">
                <?php esc_html_e( 'Update your personal information, change your password, or delete your account.', 'tejcart' ); ?>
            </p>
        </div>
    </header>

    <?php
    $tejcart_account_notice = class_exists( '\\TejCart\\Customer\\Account_Details' )
        ? \TejCart\Customer\Account_Details::consume_notice()
        : null;

    // Audit #93 / 09 F-014 — the data-export request handler reuses
    // the same notice UI as Account_Details. If both transients are
    // present (rare) the export notice wins because it's the most
    // recent user action.
    if ( ! $tejcart_account_notice && class_exists( '\\TejCart\\Customer\\Data_Export_Request' ) ) {
        $tejcart_account_notice = \TejCart\Customer\Data_Export_Request::consume_notice();
    }

    if ( $tejcart_account_notice ) :
        $notice_kind = 'success' === $tejcart_account_notice['type']
            ? 'success'
            : ( 'error' === $tejcart_account_notice['type'] ? 'error' : 'info' );
        ?>
        <div class="tejcart-account-notice tejcart-account-notice--<?php echo esc_attr( $notice_kind ); ?>" role="status">
            <span class="tejcart-account-notice__icon" aria-hidden="true">
                <?php if ( 'success' === $notice_kind ) : ?>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <?php else : ?>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                <?php endif; ?>
            </span>
            <span><?php echo esc_html( (string) $tejcart_account_notice['message'] ); ?></span>
        </div>
    <?php endif; ?>

    <form class="tejcart-account-form" method="post" novalidate>

        <?php wp_nonce_field( 'tejcart_save_account_details', 'tejcart_account_details_nonce' ); ?>

        <section class="tejcart-account-card" aria-labelledby="tejcart-personal-info-title">
            <header class="tejcart-account-card__header">
                <div>
                    <h3 id="tejcart-personal-info-title" class="tejcart-account-card__title">
                        <?php esc_html_e( 'Personal information', 'tejcart' ); ?>
                    </h3>
                    <p class="tejcart-account-card__subtitle">
                        <?php esc_html_e( 'This is how sellers contact you about orders.', 'tejcart' ); ?>
                    </p>
                </div>
            </header>

            <div class="tejcart-account-form__grid">
                <div class="tejcart-account-field tejcart-account-field--half">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-account-first-name">
                        <?php esc_html_e( 'First name', 'tejcart' ); ?>
                    </label>
                    <input
                        type="text"
                        class="tejcart-account-input"
                        name="first_name"
                        id="tejcart-account-first-name"
                        autocomplete="given-name"
                        value="<?php echo esc_attr( $current_user->first_name ); ?>"
                        required
                        aria-required="true"
                    />
                </div>

                <div class="tejcart-account-field tejcart-account-field--half">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-account-last-name">
                        <?php esc_html_e( 'Last name', 'tejcart' ); ?>
                    </label>
                    <input
                        type="text"
                        class="tejcart-account-input"
                        name="last_name"
                        id="tejcart-account-last-name"
                        autocomplete="family-name"
                        value="<?php echo esc_attr( $current_user->last_name ); ?>"
                        required
                        aria-required="true"
                    />
                </div>

                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-account-display-name">
                        <?php esc_html_e( 'Display name', 'tejcart' ); ?>
                    </label>
                    <input
                        type="text"
                        class="tejcart-account-input"
                        name="display_name"
                        id="tejcart-account-display-name"
                        value="<?php echo esc_attr( $current_user->display_name ); ?>"
                        required
                        aria-required="true"
                        aria-describedby="tejcart-account-display-name-hint"
                    />
                    <span id="tejcart-account-display-name-hint" class="tejcart-account-field__hint">
                        <?php esc_html_e( 'Shown next to reviews and in your account sidebar.', 'tejcart' ); ?>
                    </span>
                </div>

                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-account-email">
                        <?php esc_html_e( 'Email address', 'tejcart' ); ?>
                    </label>
                    <input
                        type="email"
                        class="tejcart-account-input"
                        name="email"
                        id="tejcart-account-email"
                        autocomplete="email"
                        value="<?php echo esc_attr( $current_user->user_email ); ?>"
                        required
                        aria-required="true"
                    />
                </div>
            </div>
        </section>

        <section class="tejcart-account-card" aria-labelledby="tejcart-password-title">
            <header class="tejcart-account-card__header">
                <div>
                    <h3 id="tejcart-password-title" class="tejcart-account-card__title">
                        <?php esc_html_e( 'Change password', 'tejcart' ); ?>
                    </h3>
                    <p class="tejcart-account-card__subtitle">
                        <?php esc_html_e( 'Leave all three fields blank to keep your current password.', 'tejcart' ); ?>
                    </p>
                </div>
            </header>

            <div class="tejcart-account-form__grid">
                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label" for="tejcart-account-current-password">
                        <?php esc_html_e( 'Current password', 'tejcart' ); ?>
                    </label>
                    <input
                        type="password"
                        class="tejcart-account-input"
                        name="current_password"
                        id="tejcart-account-current-password"
                        autocomplete="current-password"
                    />
                </div>

                <div class="tejcart-account-field tejcart-account-field--half">
                    <label class="tejcart-account-field__label" for="tejcart-account-new-password">
                        <?php esc_html_e( 'New password', 'tejcart' ); ?>
                    </label>
                    <input
                        type="password"
                        class="tejcart-account-input"
                        name="new_password"
                        id="tejcart-account-new-password"
                        autocomplete="new-password"
                    />
                </div>

                <div class="tejcart-account-field tejcart-account-field--half">
                    <label class="tejcart-account-field__label" for="tejcart-account-confirm-password">
                        <?php esc_html_e( 'Confirm new password', 'tejcart' ); ?>
                    </label>
                    <input
                        type="password"
                        class="tejcart-account-input"
                        name="confirm_password"
                        id="tejcart-account-confirm-password"
                        autocomplete="new-password"
                    />
                </div>
            </div>
        </section>

        <div class="tejcart-account-form__actions">
            <button type="submit" class="tejcart-btn tejcart-btn--primary" name="tejcart_save_account_details" value="1">
                <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
            </button>
        </div>

    </form>

    <?php
    /*
     * Audit #93 / 09 F-014 — self-service GDPR Article 20 export.
     * Wraps `wp_create_user_request()` so the customer can ask for
     * their personal-data zip without involving an admin. The
     * confirmation link is emailed by WP core; the download link
     * arrives by email once the export is ready.
     */
    ?>
    <form class="tejcart-account-form" method="post" novalidate>
        <?php wp_nonce_field( 'tejcart_request_data_export', 'tejcart_data_export_nonce' ); ?>

        <section class="tejcart-account-card" aria-labelledby="tejcart-data-export-title">
            <header class="tejcart-account-card__header">
                <div>
                    <h3 id="tejcart-data-export-title" class="tejcart-account-card__title">
                        <?php esc_html_e( 'Download my data', 'tejcart' ); ?>
                    </h3>
                    <p class="tejcart-account-card__subtitle">
                        <?php esc_html_e( 'Request a copy of the personal data we hold about you — including orders, addresses, wishlist, downloads, and saved payment-method metadata. You will receive a confirmation email; once you confirm, a download link is emailed when the export is ready.', 'tejcart' ); ?>
                    </p>
                </div>
            </header>

            <div class="tejcart-account-form__actions">
                <button type="submit" class="tejcart-btn tejcart-btn--secondary" name="tejcart_request_data_export" value="1">
                    <?php esc_html_e( 'Request data export', 'tejcart' ); ?>
                </button>
            </div>
        </section>
    </form>

    <form class="tejcart-account-form" method="post" novalidate>
        <?php wp_nonce_field( 'tejcart_delete_account', 'tejcart_delete_account_nonce' ); ?>

        <div class="tejcart-account-danger-zone" role="group" aria-labelledby="tejcart-danger-title">
            <h3 id="tejcart-danger-title" class="tejcart-account-danger-zone__title">
                <?php esc_html_e( 'Delete account', 'tejcart' ); ?>
            </h3>
            <p class="tejcart-account-danger-zone__description">
                <?php esc_html_e( 'Your orders will be anonymised (financial records are retained for tax compliance), and your addresses, wishlist, and saved payment methods will be permanently removed. This cannot be undone.', 'tejcart' ); ?>
            </p>

            <div class="tejcart-account-form__grid">
                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-delete-password">
                        <?php esc_html_e( 'Confirm with your password', 'tejcart' ); ?>
                    </label>
                    <input
                        type="password"
                        class="tejcart-account-input"
                        name="delete_password"
                        id="tejcart-delete-password"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                    />
                </div>

                <div class="tejcart-account-field">
                    <label class="tejcart-account-checkbox" for="tejcart-delete-account-ack">
                        <input type="checkbox" id="tejcart-delete-account-ack" name="delete_account_ack" value="1" required />
                        <span><?php esc_html_e( 'I understand this will anonymise my orders and delete my addresses and payment methods.', 'tejcart' ); ?></span>
                    </label>
                </div>
            </div>

            <div class="tejcart-account-form__actions">
                <button type="submit" class="tejcart-btn tejcart-btn--danger-solid" name="tejcart_delete_account" value="1">
                    <?php esc_html_e( 'Delete my account', 'tejcart' ); ?>
                </button>
            </div>
        </div>
    </form>

</div>
