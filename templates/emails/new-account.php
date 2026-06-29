<?php
/**
 * New-account welcome email template (gold-grade, table-based,
 * inline styles).
 *
 * Sent to a customer when their account is created. Header and
 * footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var \WP_User $user
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$display_name = $user ? ( $user->display_name ?: $user->user_login ) : '';
$user_login   = $user ? $user->user_login : '';
$account_url  = function_exists( 'tejcart_get_page_url' ) ? (string) tejcart_get_page_url( 'account' ) : (string) home_url( '/' );
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: customer name, 2: site name, 3: username */
            __( 'Hi %1$s, thanks for creating an account at %2$s. Your username is <strong>%3$s</strong>. You can log in to your account area to view orders, manage addresses, and save payment methods for faster checkout.', 'tejcart' ),
            esc_html( $display_name ),
            esc_html( get_bloginfo( 'name' ) ),
            esc_html( $user_login )
        )
    );
    ?>
</p>

<?php
if ( '' !== $account_url && function_exists( 'tejcart_email_button' ) ) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_email_button() escapes the URL and label internally and returns ready-to-print HTML.
    echo tejcart_email_button( $account_url, __( 'Visit your account', 'tejcart' ) );
}
?>
