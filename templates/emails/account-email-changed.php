<?php
/**
 * Account email changed security notice template (gold-grade, inline styles).
 *
 * Sent to the PREVIOUS account address when a customer's email is changed.
 * Header and footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var string $previous_email
 * @var string $new_email
 * @var string $reset_url
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
$reset_url = isset( $reset_url ) && '' !== (string) $reset_url ? (string) $reset_url : wp_lostpassword_url();
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php echo esc_html__( 'Hi,', 'tejcart' ); ?>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: previous email, 2: site name */
            __( 'The email address on your account <strong>%1$s</strong> at %2$s was just changed to a new address.', 'tejcart' ),
            esc_html( (string) $previous_email ),
            esc_html( $site_name )
        )
    );
    ?>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php echo esc_html__( 'If you made this change, no further action is needed.', 'tejcart' ); ?>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <strong><?php echo esc_html__( 'If you did NOT make this change', 'tejcart' ); ?></strong>
    <?php echo esc_html__( ', please contact the site owner immediately and reset your password using the button below.', 'tejcart' ); ?>
</p>

<?php
if ( function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( $reset_url, __( 'Reset your password', 'tejcart' ) );
}
?>

<p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
    <?php echo esc_html__( 'For your security, this notice was sent to the previous email address on the account.', 'tejcart' ); ?>
</p>
