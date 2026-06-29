<?php
/**
 * Branded password-reset email template (gold-grade, table-based,
 * inline styles).
 *
 * Header and footer are supplied by Abstract_Email::send() in the
 * normal flow; the Email_Manager filter that injects this template
 * into core's `retrieve_password_message` calls `get_full_html()`,
 * so the same header/footer wrapping is applied there too.
 *
 * @package TejCart\Templates\Emails
 *
 * @var \WP_User|null $user
 * @var string        $reset_url
 * @var string        $user_login
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$user_login = isset( $user_login ) ? (string) $user_login : '';
$reset_url  = isset( $reset_url ) ? (string) $reset_url : '';
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php esc_html_e( 'Someone requested a password reset for the following account:', 'tejcart' ); ?>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <strong><?php echo esc_html( $user_login ); ?></strong>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php esc_html_e( 'If this was a mistake, you can safely ignore this email — your current password will continue to work.', 'tejcart' ); ?>
</p>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php esc_html_e( 'To reset your password, click the button below:', 'tejcart' ); ?>
</p>

<?php
if ( '' !== $reset_url && function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( $reset_url, __( 'Reset password', 'tejcart' ) );
}
?>

<?php if ( '' !== $reset_url ) : ?>
    <p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
        <?php esc_html_e( 'Or paste this link into your browser:', 'tejcart' ); ?><br />
        <span style="word-break:break-all;color:#6b7280;"><?php echo esc_html( $reset_url ); ?></span>
    </p>
<?php endif; ?>
