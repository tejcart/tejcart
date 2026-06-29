<?php
/**
 * Back-in-stock notification email template (gold-grade, inline styles).
 *
 * Sent to subscribers who opted in via the "Notify me when available"
 * form on an out-of-stock product page. Header and footer are supplied
 * by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var int    $product_id
 * @var string $product_name
 * @var string $product_url
 * @var string $unsubscribe_url
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: %s: product name */
            __( 'Good news — <strong>%s</strong> is back in stock and ready to ship.', 'tejcart' ),
            esc_html( (string) $product_name )
        )
    );
    ?>
</p>

<?php
if ( ! empty( $product_url ) && function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( (string) $product_url, __( 'Shop now', 'tejcart' ) );
}
?>

<?php if ( ! empty( $unsubscribe_url ) ) : ?>
    <p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: unsubscribe link */
                __( 'If you no longer wish to receive these alerts, %s.', 'tejcart' ),
                '<a class="nx-link" style="' . esc_attr( $nx_link_style ) . '" href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'unsubscribe here', 'tejcart' ) . '</a>'
            )
        );
        ?>
    </p>
<?php endif; ?>
