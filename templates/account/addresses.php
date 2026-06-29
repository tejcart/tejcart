<?php
/**
 * Address management template.
 *
 * Displays the customer's billing and shipping addresses as cards
 * that flip into an inline edit form when ?edit_address=<type> is
 * present in the URL.
 *
 * @package TejCart\Templates\Account
 *
 * @var int   $customer_id Current customer user ID.
 * @var array $addresses   Customer addresses (billing/shipping).
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$address_types = array(
    'billing'  => __( 'Billing address', 'tejcart' ),
    'shipping' => __( 'Shipping address', 'tejcart' ),
);

$address_icons = array(
    'billing'  => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>',
    'shipping' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
);

$address_fields = array(
    'first_name' => __( 'First name', 'tejcart' ),
    'last_name'  => __( 'Last name', 'tejcart' ),
    'company'    => __( 'Company', 'tejcart' ),
    'address_1'  => __( 'Street address', 'tejcart' ),
    'address_2'  => __( 'Apartment, suite, etc.', 'tejcart' ),
    'city'       => __( 'City', 'tejcart' ),
    'country'    => __( 'Country / Region', 'tejcart' ),
    'state'      => __( 'State / County', 'tejcart' ),
    'postcode'   => __( 'Postcode / ZIP', 'tejcart' ),
    'phone'      => __( 'Phone', 'tejcart' ),
);

$required_fields = array( 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'country' );
$half_fields     = array( 'first_name', 'last_name', 'city', 'state', 'postcode', 'country' );

$countries = class_exists( '\TejCart\Tax\Tax_Manager' ) ? \TejCart\Tax\Tax_Manager::get_countries() : array();
asort( $countries );
$store_country = (string) get_option( 'tejcart_store_country', 'US' );
if ( isset( $countries[ $store_country ] ) ) {
    $countries = array( $store_country => $countries[ $store_country ] ) + $countries;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing = isset( $_GET['edit_address'] ) ? sanitize_text_field( wp_unslash( $_GET['edit_address'] ) ) : '';

$account_url = get_permalink();
?>

<div class="tejcart-account-addresses">

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title"><?php esc_html_e( 'Addresses', 'tejcart' ); ?></h2>
            <p class="tejcart-account-subpage-header__subtitle">
                <?php esc_html_e( 'Manage your billing and shipping addresses for a faster checkout experience.', 'tejcart' ); ?>
            </p>
        </div>
    </header>

    <div class="tejcart-account-addresses-grid">

        <?php foreach ( $address_types as $type => $title ) : ?>

            <section class="tejcart-account-card tejcart-account-address-card" aria-labelledby="tejcart-address-<?php echo esc_attr( $type ); ?>-title">

                <?php if ( $editing === $type ) : ?>

                    <header class="tejcart-account-address-card__head">
                        <div class="tejcart-account-address-card__heading">
                            <span class="tejcart-account-address-card__icon"><?php
                                // SVG markup defined statically in this file (see $address_icons array above);
                                // funnelled through the SVG allowlist for belt-and-braces output escaping.
                                echo tejcart_kses_svg( $address_icons[ $type ] );
                            ?></span>
                            <h3 id="tejcart-address-<?php echo esc_attr( $type ); ?>-title" class="tejcart-account-address-card__title">
                                <?php
                                printf(
                                    /* translators: %s: address type label */
                                    esc_html__( 'Edit %s', 'tejcart' ),
                                    esc_html( strtolower( $title ) )
                                );
                                ?>
                            </h3>
                        </div>
                    </header>

                    <?php
                    $current_country = isset( $addresses[ $type ]['country'] ) && '' !== $addresses[ $type ]['country']
                        ? (string) $addresses[ $type ]['country']
                        : $store_country;
                    $country_states  = class_exists( '\TejCart\Tax\Tax_Manager' )
                        ? \TejCart\Tax\Tax_Manager::get_states( $current_country )
                        : array();
                    ?>

                    <form class="tejcart-account-form" method="post" novalidate data-tejcart-address-scope="<?php echo esc_attr( $type ); ?>">

                        <?php wp_nonce_field( 'tejcart_save_address_' . $type, 'tejcart_address_nonce' ); ?>
                        <input type="hidden" name="tejcart_address_type" value="<?php echo esc_attr( $type ); ?>" />

                        <div class="tejcart-account-form__grid">
                            <?php foreach ( $address_fields as $field_key => $field_label ) :
                                $full_key      = $type . '_' . $field_key;
                                $current_value = isset( $addresses[ $type ][ $field_key ] ) ? $addresses[ $type ][ $field_key ] : '';
                                $is_required   = in_array( $field_key, $required_fields, true );
                                $is_half       = in_array( $field_key, $half_fields, true );
                                $autocomplete  = $type . ' ' . ( 'address_1' === $field_key ? 'address-line1' : ( 'address_2' === $field_key ? 'address-line2' : ( 'postcode' === $field_key ? 'postal-code' : ( 'state' === $field_key ? 'address-level1' : ( 'city' === $field_key ? 'address-level2' : str_replace( '_', '-', $field_key ) ) ) ) ) );

                                $is_country = ( 'country' === $field_key );
                                $is_state   = ( 'state' === $field_key );

                                $wrapper_classes = array( 'tejcart-account-field' );
                                if ( $is_half ) {
                                    $wrapper_classes[] = 'tejcart-account-field--half';
                                }

                                $wrapper_data = '';
                                if ( $is_country ) {
                                    $wrapper_data = ' data-tejcart-country-field="' . esc_attr( $full_key ) . '"';
                                } elseif ( $is_state ) {
                                    $wrapper_data = ' data-tejcart-state-field="' . esc_attr( $full_key ) . '"';
                                }

                                if ( '' === $current_value && $is_country ) {
                                    $current_value = $store_country;
                                }
                                ?>
                                <div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"<?php echo $wrapper_data; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                                    <label
                                        class="tejcart-account-field__label<?php echo $is_required ? ' tejcart-account-field__label--required' : ''; ?>"
                                        for="tejcart-address-<?php echo esc_attr( $full_key ); ?>"
                                    >
                                        <?php echo esc_html( $field_label ); ?>
                                    </label>

                                    <?php if ( $is_country ) : ?>
                                        <select
                                            class="tejcart-account-select"
                                            name="<?php echo esc_attr( $full_key ); ?>"
                                            id="tejcart-address-<?php echo esc_attr( $full_key ); ?>"
                                            autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
                                            <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                        >
                                            <option value=""><?php esc_html_e( 'Select a country / region', 'tejcart' ); ?></option>
                                            <?php foreach ( $countries as $country_code => $country_name ) : ?>
                                                <option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( $current_value, $country_code ); ?>>
                                                    <?php echo esc_html( $country_name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ( $is_state ) : ?>
                                        <?php if ( ! empty( $country_states ) ) : ?>
                                            <select
                                                class="tejcart-account-select"
                                                name="<?php echo esc_attr( $full_key ); ?>"
                                                id="tejcart-address-<?php echo esc_attr( $full_key ); ?>"
                                                autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
                                                <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                            >
                                                <option value=""><?php esc_html_e( 'Select a state / province', 'tejcart' ); ?></option>
                                                <?php foreach ( $country_states as $state_code => $state_name ) : ?>
                                                    <option value="<?php echo esc_attr( $state_code ); ?>" <?php selected( $current_value, $state_code ); ?>>
                                                        <?php echo esc_html( $state_name ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else : ?>
                                            <input
                                                type="text"
                                                class="tejcart-account-input"
                                                name="<?php echo esc_attr( $full_key ); ?>"
                                                id="tejcart-address-<?php echo esc_attr( $full_key ); ?>"
                                                value="<?php echo esc_attr( $current_value ); ?>"
                                                autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
                                                <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                            />
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <input
                                            type="text"
                                            class="tejcart-account-input"
                                            name="<?php echo esc_attr( $full_key ); ?>"
                                            id="tejcart-address-<?php echo esc_attr( $full_key ); ?>"
                                            value="<?php echo esc_attr( $current_value ); ?>"
                                            autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
                                            <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                        />
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        /**
                         * Fires inside the address edit form, before the save button.
                         *
                         * @param string $type        Address type (billing or shipping).
                         * @param array  $addresses   Current address data.
                         * @param int    $customer_id The customer ID.
                         */
                        do_action( 'tejcart_save_address', $type, $addresses, $customer_id );
                        ?>

                        <div class="tejcart-account-form__actions">
                            <button type="submit" class="tejcart-btn tejcart-btn--primary" name="tejcart_save_address" value="1">
                                <?php esc_html_e( 'Save address', 'tejcart' ); ?>
                            </button>
                            <a class="tejcart-btn tejcart-btn--ghost" href="<?php echo esc_url( add_query_arg( 'tab', 'addresses', $account_url ) ); ?>">
                                <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                            </a>
                        </div>

                    </form>

                <?php else : ?>

                    <header class="tejcart-account-address-card__head">
                        <div class="tejcart-account-address-card__heading">
                            <span class="tejcart-account-address-card__icon"><?php
                                // SVG markup defined statically in this file (see $address_icons array above);
                                // funnelled through the SVG allowlist for belt-and-braces output escaping.
                                echo tejcart_kses_svg( $address_icons[ $type ] );
                            ?></span>
                            <h3 id="tejcart-address-<?php echo esc_attr( $type ); ?>-title" class="tejcart-account-address-card__title">
                                <?php echo esc_html( $title ); ?>
                            </h3>
                        </div>
                        <a class="tejcart-btn tejcart-btn--small tejcart-btn--ghost" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'addresses', 'edit_address' => $type ), $account_url ) ); ?>">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"/></svg>
                            <?php esc_html_e( 'Edit', 'tejcart' ); ?>
                        </a>
                    </header>

                    <?php
                    $formatted = isset( $addresses[ $type ] ) ? $addresses[ $type ] : array();
                    if ( ! empty( $formatted ) && array_filter( $formatted ) ) :
                        $country_code  = isset( $formatted['country'] ) ? (string) $formatted['country'] : '';
                        $state_code    = isset( $formatted['state'] ) ? (string) $formatted['state'] : '';
                        $country_label = ( '' !== $country_code && isset( $countries[ $country_code ] ) )
                            ? $countries[ $country_code ]
                            : $country_code;
                        $state_lookup  = ( '' !== $country_code && class_exists( '\TejCart\Tax\Tax_Manager' ) )
                            ? \TejCart\Tax\Tax_Manager::get_states( $country_code )
                            : array();
                        $state_label   = ( '' !== $state_code && isset( $state_lookup[ $state_code ] ) )
                            ? $state_lookup[ $state_code ]
                            : $state_code;
                        ?>
                        <address class="tejcart-account-address-card__display">
                            <?php
                            $parts = array_filter( array(
                                isset( $formatted['first_name'] ) ? esc_html( $formatted['first_name'] . ' ' . ( $formatted['last_name'] ?? '' ) ) : '',
                                isset( $formatted['company'] ) ? esc_html( $formatted['company'] ) : '',
                                isset( $formatted['address_1'] ) ? esc_html( $formatted['address_1'] ) : '',
                                isset( $formatted['address_2'] ) ? esc_html( $formatted['address_2'] ) : '',
                                isset( $formatted['city'] ) ? esc_html( $formatted['city'] . ( '' !== $state_label ? ', ' . $state_label : '' ) . ' ' . ( $formatted['postcode'] ?? '' ) ) : '',
                                '' !== $country_label ? esc_html( $country_label ) : '',
                            ) );
                            echo wp_kses_post( implode( '<br>', $parts ) );

                            if ( ! empty( $formatted['phone'] ) ) :
                                ?>
                                <br><span class="tejcart-account-address-phone"><?php echo esc_html( $formatted['phone'] ); ?></span>
                            <?php endif; ?>
                        </address>
                    <?php else : ?>
                        <div class="tejcart-account-address-card__empty">
                            <p><?php esc_html_e( 'No address on file yet. Add one to speed up checkout and receive shipping updates.', 'tejcart' ); ?></p>
                            <a class="tejcart-btn tejcart-btn--primary tejcart-btn--small" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'addresses', 'edit_address' => $type ), $account_url ) ); ?>">
                                <?php esc_html_e( 'Add address', 'tejcart' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </section>

        <?php endforeach; ?>

    </div>

</div>
