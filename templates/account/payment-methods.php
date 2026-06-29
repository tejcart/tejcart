<?php
/**
 * Payment Methods management template.
 *
 * Displays the customer's saved payment methods with options to set
 * the default, delete, or add a new method via the standalone vault
 * redirect flow.
 *
 * @package TejCart\Templates\Account
 *
 * @var int        $customer_id    Current customer user ID.
 * @var array      $methods        Saved payment methods.
 * @var array|null $vault_notice   One-shot notice from the vault flow.
 * @var bool       $can_add_method Whether the merchant has PayPal connected.
 * @var string     $add_method_url URL that initiates the standalone vault flow.
 * @var string     $add_nonce      Nonce value for the add-method form.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$nonce          = wp_create_nonce( 'tejcart_payment_methods' );
$vault_notice   = $vault_notice ?? null;
$can_add_method = $can_add_method ?? false;
$add_method_url = $add_method_url ?? '';
$add_nonce      = $add_nonce ?? '';
$methods        = $methods ?? array();

$method_type_icons = array(
    'paypal' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106Z"/></svg>',
    'card'   => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>',
    'venmo'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.802 2.165c.58.972.84 1.975.84 3.245 0 4.043-3.453 9.29-6.252 12.99H7.927L5.6 2.74l5.628-.525 1.26 10.14c1.17-1.907 2.612-4.91 2.612-6.965 0-1.21-.21-2.035-.56-2.715l5.262-.51Z"/></svg>',
);
?>

<div class="tejcart-account-payment-methods">

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title"><?php esc_html_e( 'Payment methods', 'tejcart' ); ?></h2>
            <p class="tejcart-account-subpage-header__subtitle">
                <?php esc_html_e( 'Manage your saved payment methods for a one-tap checkout experience.', 'tejcart' ); ?>
            </p>
        </div>
    </header>

    <?php if ( $vault_notice && ! empty( $vault_notice['message'] ) ) :
        $notice_kind = in_array( ( $vault_notice['type'] ?? 'success' ), array( 'success', 'error', 'info', 'warning' ), true )
            ? $vault_notice['type']
            : 'success';
        ?>
        <div class="tejcart-account-notice tejcart-account-notice--<?php echo esc_attr( $notice_kind ); ?>" role="status">
            <span class="tejcart-account-notice__icon" aria-hidden="true">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </span>
            <span><?php echo esc_html( (string) $vault_notice['message'] ); ?></span>
        </div>
    <?php endif; ?>

    <?php if ( $can_add_method && '' !== $add_method_url ) : ?>
        <section class="tejcart-account-card tejcart-vault-add" aria-labelledby="tejcart-add-method-title">
            <header class="tejcart-account-card__header">
                <div>
                    <h3 id="tejcart-add-method-title" class="tejcart-account-card__title">
                        <?php esc_html_e( 'Add a payment method', 'tejcart' ); ?>
                    </h3>
                    <p class="tejcart-account-card__subtitle">
                        <?php esc_html_e( 'Choose a wallet to link. We will redirect you to confirm — no charge is made.', 'tejcart' ); ?>
                    </p>
                </div>
                <span class="tejcart-vault-add__secure">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    <?php esc_html_e( 'Encrypted', 'tejcart' ); ?>
                </span>
            </header>

            <form method="post" action="<?php echo esc_url( $add_method_url ); ?>" class="tejcart-account-form tejcart-vault-add__form">
                <input type="hidden" name="tejcart_vault_nonce" value="<?php echo esc_attr( $add_nonce ); ?>" />

                <fieldset class="tejcart-vault-tiles">
                    <legend class="screen-reader-text"><?php esc_html_e( 'Choose a payment method to add', 'tejcart' ); ?></legend>

                    <label class="tejcart-vault-tile tejcart-vault-tile--paypal">
                        <input type="radio" name="vault_source" value="paypal" class="tejcart-vault-tile__input" checked />
                        <span class="tejcart-vault-tile__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106Z"/></svg>
                        </span>
                        <span class="tejcart-vault-tile__body">
                            <span class="tejcart-vault-tile__title"><?php esc_html_e( 'PayPal account', 'tejcart' ); ?></span>
                            <span class="tejcart-vault-tile__desc"><?php esc_html_e( 'Sign in once and check out with a single tap.', 'tejcart' ); ?></span>
                        </span>
                        <span class="tejcart-vault-tile__check" aria-hidden="true">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </span>
                    </label>

                    <label class="tejcart-vault-tile tejcart-vault-tile--venmo">
                        <input type="radio" name="vault_source" value="venmo" class="tejcart-vault-tile__input" />
                        <span class="tejcart-vault-tile__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.802 2.165c.58.972.84 1.975.84 3.245 0 4.043-3.453 9.29-6.252 12.99H7.927L5.6 2.74l5.628-.525 1.26 10.14c1.17-1.907 2.612-4.91 2.612-6.965 0-1.21-.21-2.035-.56-2.715l5.262-.51Z"/></svg>
                        </span>
                        <span class="tejcart-vault-tile__body">
                            <span class="tejcart-vault-tile__title"><?php esc_html_e( 'Venmo', 'tejcart' ); ?></span>
                            <span class="tejcart-vault-tile__desc"><?php esc_html_e( 'Pay from your Venmo balance or linked bank.', 'tejcart' ); ?></span>
                        </span>
                        <span class="tejcart-vault-tile__check" aria-hidden="true">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </span>
                    </label>
                </fieldset>

                <p class="tejcart-vault-add__hint">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25 12 11.25v6.75m0-12.75h.008v.008H12V5.25Zm9 6.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span>
                        <?php esc_html_e( 'Credit and debit cards are saved at checkout — choose "Save this card" when paying.', 'tejcart' ); ?>
                    </span>
                </p>

                <div class="tejcart-vault-add__actions">
                    <button type="submit" class="tejcart-btn tejcart-btn--primary tejcart-vault-add__submit">
                        <span class="tejcart-vault-add__submit-label">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            <?php esc_html_e( 'Add payment method', 'tejcart' ); ?>
                        </span>
                        <svg class="tejcart-vault-add__submit-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ( empty( $methods ) ) : ?>

        <section class="tejcart-account-card">
            <div class="tejcart-account-empty">
                <span class="tejcart-account-empty__icon" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>
                </span>
                <h3 class="tejcart-account-empty__title"><?php esc_html_e( 'No saved payment methods', 'tejcart' ); ?></h3>
                <p class="tejcart-account-empty__body">
                    <?php
                    if ( $can_add_method ) {
                        esc_html_e( 'Use the form above to add a method, or save one during your next checkout for a one-tap return visit.', 'tejcart' );
                    } else {
                        esc_html_e( 'Payment methods are saved automatically when you complete a checkout using PayPal vaulting.', 'tejcart' );
                    }
                    ?>
                </p>
            </div>
        </section>

    <?php else : ?>

        <div class="tejcart-account-methods" role="list">
            <?php foreach ( $methods as $method ) :
                $method_type = $method['type'] ?? 'card';
                ?>
                <div class="tejcart-account-method" role="listitem" data-method-id="<?php echo esc_attr( $method['id'] ); ?>">
                    <div class="tejcart-account-method__main">
                        <div class="tejcart-account-method__icon tejcart-account-method__icon--<?php echo esc_attr( $method_type ); ?>">
                            <?php
                            // Defensive: only emit the icon for known keys, even though the
                            // icons array is a hardcoded literal above. If a future
                            // contributor moves the array into a filter or option (where
                            // attacker-controlled values could land), the switch keeps
                            // the output side closed.
                            $icon_key = isset( $method_type_icons[ $method_type ] ) ? $method_type : 'card';
                            switch ( $icon_key ) {
                                case 'paypal':
                                    echo tejcart_kses_svg( $method_type_icons['paypal'] );
                                    break;
                                case 'venmo':
                                    echo tejcart_kses_svg( $method_type_icons['venmo'] );
                                    break;
                                case 'card':
                                default:
                                    echo tejcart_kses_svg( $method_type_icons['card'] );
                                    break;
                            }
                            ?>
                        </div>
                        <div class="tejcart-account-method__details">
                            <span class="tejcart-account-method__label">
                                <?php echo esc_html( $method['label'] ?: __( 'Saved account', 'tejcart' ) ); ?>
                            </span>
                            <span class="tejcart-account-method__meta">
                                <span><?php echo esc_html( ucfirst( $method_type ) ); ?></span>
                                <?php if ( ! empty( $method['is_default'] ) ) : ?>
                                    <span class="tejcart-status-badge tejcart-status-badge--completed">
                                        <?php esc_html_e( 'Default', 'tejcart' ); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="tejcart-account-method__actions">
                        <?php if ( empty( $method['is_default'] ) ) : ?>
                            <button
                                type="button"
                                class="tejcart-btn tejcart-btn--small tejcart-btn--ghost tejcart-set-default-method"
                                data-method-id="<?php echo esc_attr( $method['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            >
                                <?php esc_html_e( 'Set default', 'tejcart' ); ?>
                            </button>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="tejcart-btn tejcart-btn--small tejcart-btn--danger tejcart-delete-method"
                            data-method-id="<?php echo esc_attr( $method['id'] ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            aria-label="<?php
                            echo esc_attr( sprintf(
                                /* translators: %s: human-readable label of the saved payment method (e.g. "Visa ending in 4242"). */
                                __( 'Remove saved payment method: %s', 'tejcart' ),
                                $method['label'] ?: __( 'Saved account', 'tejcart' )
                            ) );
                            ?>"
                        >
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                            <?php esc_html_e( 'Remove', 'tejcart' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
