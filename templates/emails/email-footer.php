<?php
/**
 * Email footer template (gold-grade scaffolding closer).
 *
 * Closes the inline content `<div>`, the content-card `<td>`, the
 * 600px container `<table>`, and the outer 100% wrapper opened by
 * `email-header.php`. Renders the brand-neutral footer band and
 * closes the document.
 *
 * @package TejCart\Templates\Emails
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$site_name = (string) get_bloginfo( 'name' );
$body_bg   = '#f4f5f7';

$font_stack = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

/**
 * Filter the footer tagline rendered under the copyright line.
 * Return an empty string to suppress the line entirely.
 *
 * Defaults to the merchant-set `tejcart_footer_text` option (collected by
 * the setup wizard) so the wizard input has immediate effect on outgoing
 * emails. Falls back to a generic activity notice when no footer text is
 * configured.
 *
 * @param string $tagline
 */
$tejcart_default_tagline = (string) get_option( 'tejcart_footer_text', '' );
if ( '' === trim( wp_strip_all_tags( $tejcart_default_tagline ) ) ) {
    $tejcart_default_tagline = __( 'You are receiving this email because of activity on your account at our store.', 'tejcart' );
}
$tagline = (string) apply_filters( 'tejcart_email_footer_tagline', $tejcart_default_tagline );

/**
 * Filter additional footer links (e.g. unsubscribe, privacy policy).
 * Each entry: array{ label: string, url: string }.
 *
 * @param array<int, array{label: string, url: string}> $links
 */
$extra_links = (array) apply_filters( 'tejcart_email_footer_links', array() );
?>

                        </div><!-- .nx-text -->
                    </td>
                </tr>

                <?php // Footer band. ?>
                <tr>
                    <td align="center" class="nx-px" style="padding:24px 30px;font-family:<?php echo esc_attr( $font_stack ); ?>;">
                        <p class="nx-footer-text" style="margin:0 0 6px;padding:0;color:#6b7280;font-size:12px;line-height:18px;">
                            &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
                            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="nx-link" style="color:#6b7280;text-decoration:underline;">
                                <?php echo esc_html( $site_name ); ?>
                            </a>.
                            <?php esc_html_e( 'All rights reserved.', 'tejcart' ); ?>
                        </p>

                        <?php if ( '' !== $tagline ) : ?>
                            <p class="nx-footer-text" style="margin:0 0 6px;padding:0;color:#6b7280;font-size:12px;line-height:18px;">
                                <?php echo esc_html( $tagline ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( ! empty( $extra_links ) ) : ?>
                            <p class="nx-footer-text" style="margin:6px 0 0;padding:0;color:#6b7280;font-size:12px;line-height:18px;">
                                <?php
                                $rendered = array();
                                foreach ( $extra_links as $link ) {
                                    if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) ) {
                                        continue;
                                    }
                                    $rendered[] = sprintf(
                                        '<a href="%s" class="nx-link" style="color:#6b7280;text-decoration:underline;">%s</a>',
                                        esc_url( (string) $link['url'] ),
                                        esc_html( (string) $link['label'] )
                                    );
                                }
                                echo wp_kses(
                                    implode( ' &middot; ', $rendered ),
                                    array(
                                        'a' => array(
                                            'href'  => array(),
                                            'class' => array(),
                                            'style' => array(),
                                        ),
                                    )
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <!--[if mso | IE]>
            </td></tr></table>
            <![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
