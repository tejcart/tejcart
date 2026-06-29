<?php
/**
 * Login / registration / order-tracking template.
 *
 * Rendered when a non-logged-in visitor views the account page.
 * Two auth cards (login + optional register) above a separate
 * order-tracking card for guest order lookup.
 *
 * @package TejCart\Templates\Account
 *
 * @var string|null $track_order_error Optional error from the tracking flow.
 * @var string|null $login_error       Optional error from the login submission.
 * @var string|null $register_error    Optional error from the registration submission.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$enable_registration = apply_filters( 'tejcart_enable_registration', get_option( 'tejcart_enable_registration', 'yes' ) === 'yes' );
$track_order_error   = $track_order_error ?? '';
?>

<div class="tejcart-account-guest">

    <header class="tejcart-account-guest__header">
        <p class="tejcart-account-guest__subtitle">
            <?php esc_html_e( 'Sign in to view orders, manage addresses, and check out faster next time.', 'tejcart' ); ?>
        </p>
    </header>

    <div class="tejcart-account-guest__grid <?php echo $enable_registration ? 'tejcart-account-guest__grid--with-register' : ''; ?>">

        <!-- Login form -->
        <section class="tejcart-account-card tejcart-account-auth-card" aria-labelledby="tejcart-login-title">

            <header class="tejcart-account-auth-card__head">
                <span class="tejcart-account-auth-card__icon" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
                </span>
                <h2 id="tejcart-login-title" class="tejcart-account-auth-card__title"><?php esc_html_e( 'Welcome back', 'tejcart' ); ?></h2>
                <p class="tejcart-account-auth-card__subtitle"><?php esc_html_e( 'Sign in to your account to continue.', 'tejcart' ); ?></p>
            </header>

            <?php if ( ! empty( $login_error ) ) : ?>
                <div class="tejcart-account-notice tejcart-account-notice--error" role="alert">
                    <?php echo esc_html( $login_error ); ?>
                </div>
            <?php endif; ?>

            <form class="tejcart-account-form" method="post" novalidate>

                <?php wp_nonce_field( 'tejcart_login', 'tejcart_login_nonce' ); ?>

                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-login-username">
                        <?php esc_html_e( 'Username or email', 'tejcart' ); ?>
                    </label>
                    <input
                        type="text"
                        class="tejcart-account-input"
                        name="username"
                        id="tejcart-login-username"
                        autocomplete="username"
                        required
                        aria-required="true"
                    />
                </div>

                <div class="tejcart-account-field">
                    <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-login-password">
                        <?php esc_html_e( 'Password', 'tejcart' ); ?>
                    </label>
                    <input
                        type="password"
                        class="tejcart-account-input"
                        name="password"
                        id="tejcart-login-password"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                    />
                </div>

                <div class="tejcart-account-field tejcart-account-field--inline">
                    <label class="tejcart-account-checkbox" for="tejcart-login-remember">
                        <input type="checkbox" name="rememberme" id="tejcart-login-remember" value="forever" />
                        <span><?php esc_html_e( 'Remember me', 'tejcart' ); ?></span>
                    </label>
                    <a class="tejcart-account-forgot-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
                        <?php esc_html_e( 'Forgot password?', 'tejcart' ); ?>
                    </a>
                </div>

                <?php
                /**
                 * Fires inside the login form, before the submit button.
                 */
                do_action( 'tejcart_login_form' );
                ?>

                <button type="submit" class="tejcart-btn tejcart-btn--primary tejcart-btn--full" name="tejcart_login" value="1">
                    <?php esc_html_e( 'Sign in', 'tejcart' ); ?>
                </button>

            </form>

        </section>

        <?php if ( $enable_registration ) : ?>

            <section class="tejcart-account-card tejcart-account-auth-card" aria-labelledby="tejcart-register-title">

                <header class="tejcart-account-auth-card__head">
                    <span class="tejcart-account-auth-card__icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
                    </span>
                    <h2 id="tejcart-register-title" class="tejcart-account-auth-card__title"><?php esc_html_e( 'Create an account', 'tejcart' ); ?></h2>
                    <p class="tejcart-account-auth-card__subtitle"><?php esc_html_e( 'Register for faster checkout and order tracking.', 'tejcart' ); ?></p>
                </header>

                <?php if ( ! empty( $register_error ) ) : ?>
                    <div class="tejcart-account-notice tejcart-account-notice--error" role="alert">
                        <?php echo esc_html( $register_error ); ?>
                    </div>
                <?php endif; ?>

                <form class="tejcart-account-form" method="post" novalidate>

                    <?php wp_nonce_field( 'tejcart_register', 'tejcart_register_nonce' ); ?>

                    <div class="tejcart-account-field">
                        <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-register-email">
                            <?php esc_html_e( 'Email address', 'tejcart' ); ?>
                        </label>
                        <input
                            type="email"
                            class="tejcart-account-input"
                            name="email"
                            id="tejcart-register-email"
                            autocomplete="email"
                            required
                            aria-required="true"
                        />
                    </div>

                    <div class="tejcart-account-field">
                        <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-register-password">
                            <?php esc_html_e( 'Password', 'tejcart' ); ?>
                        </label>
                        <input
                            type="password"
                            class="tejcart-account-input"
                            name="password"
                            id="tejcart-register-password"
                            autocomplete="new-password"
                            required
                            aria-required="true"
                        />
                    </div>

                    <?php
                    /**
                     * Fires inside the registration form, before the submit button.
                     */
                    do_action( 'tejcart_register_form' );
                    ?>

                    <button type="submit" class="tejcart-btn tejcart-btn--primary tejcart-btn--full" name="tejcart_register" value="1">
                        <?php esc_html_e( 'Create account', 'tejcart' ); ?>
                    </button>

                </form>

            </section>

        <?php endif; ?>

    </div>

    <section class="tejcart-account-card tejcart-account-track" aria-labelledby="tejcart-track-title">

        <header class="tejcart-account-track__head">
            <span class="tejcart-account-track__icon" aria-hidden="true">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            </span>
            <h2 id="tejcart-track-title" class="tejcart-account-track__title"><?php esc_html_e( 'Track an order', 'tejcart' ); ?></h2>
        </header>

        <p class="tejcart-account-track__description">
            <?php esc_html_e( 'Already placed an order? Look it up by order number and email — no account required.', 'tejcart' ); ?>
        </p>

        <?php if ( $track_order_error ) : ?>
            <div class="tejcart-account-notice tejcart-account-notice--error" role="alert">
                <span class="tejcart-account-notice__icon" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                </span>
                <span><?php echo esc_html( $track_order_error ); ?></span>
            </div>
        <?php endif; ?>

        <form class="tejcart-account-track__form" method="post" novalidate>

            <?php wp_nonce_field( 'tejcart_track_order', 'tejcart_track_order_nonce' ); ?>

            <div class="tejcart-account-field">
                <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-track-order-number">
                    <?php esc_html_e( 'Order number', 'tejcart' ); ?>
                </label>
                <input
                    type="text"
                    class="tejcart-account-input"
                    name="order_number"
                    id="tejcart-track-order-number"
                    placeholder="<?php esc_attr_e( 'e.g. NXC-2M5KR7-A3BX9P', 'tejcart' ); ?>"
                    autocomplete="off"
                    required
                    aria-required="true"
                />
            </div>

            <div class="tejcart-account-field">
                <label class="tejcart-account-field__label tejcart-account-field__label--required" for="tejcart-track-order-email">
                    <?php esc_html_e( 'Email address', 'tejcart' ); ?>
                </label>
                <input
                    type="email"
                    class="tejcart-account-input"
                    name="order_email"
                    id="tejcart-track-order-email"
                    autocomplete="email"
                    required
                    aria-required="true"
                />
            </div>

            <?php
            /**
             * Fires inside the order tracking form, before the submit button.
             */
            do_action( 'tejcart_track_order_form' );
            ?>

            <button type="submit" class="tejcart-btn tejcart-btn--secondary" name="tejcart_track_order" value="1">
                <?php esc_html_e( 'Track order', 'tejcart' ); ?>
            </button>

        </form>

    </section>

</div>
