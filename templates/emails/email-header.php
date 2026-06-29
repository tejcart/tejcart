<?php
/**
 * Email header template (gold-grade hybrid table scaffolding).
 *
 * Opens the HTML document with an XHTML 1.0 Transitional DOCTYPE
 * (the safest baseline for Outlook desktop's Word rendering engine),
 * declares the `v:` and `o:` namespaces required for VML buttons,
 * pins Outlook's pixel density to 96dpi, emits a hidden preheader
 * span with whitespace padding, and opens the bulletproof outer /
 * 600px-wide inner table that wraps every TejCart email.
 *
 * Inline styles cover every "must look right" client. The single
 * `<style>` block carries only @media queries: a mobile breakpoint
 * (`max-width: 600px`) and a `prefers-color-scheme: dark` override.
 *
 * @package TejCart\Templates\Emails
 *
 * @var string $email_heading The email heading text.
 * @var string $preheader     Optional preview-pane text (hidden in body).
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$site_name = (string) get_bloginfo( 'name' );

$logo_url = (string) get_option( 'tejcart_header_image', '' );
if ( '' === $logo_url ) {
    $logo_url = (string) get_option( 'tejcart_email_logo_url', '' );
}

$brand_color = function_exists( 'tejcart_email_brand_color' )
    ? tejcart_email_brand_color()
    : '#0073aa';

$preheader     = isset( $preheader ) ? (string) $preheader : '';
$email_heading = isset( $email_heading ) ? (string) $email_heading : '';

$body_bg      = '#f4f5f7';
$container_bg = '#ffffff';
$text_color   = '#2b2f33';
$muted_color  = '#6b7280';
$border_color = '#e5e7eb';

$font_stack = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji'";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
    <meta name="color-scheme" content="light dark" />
    <meta name="supported-color-schemes" content="light dark" />
    <title><?php echo esc_html( $site_name ); ?></title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
                <o:AllowPNG/>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        /* Client resets */
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; border-collapse: collapse !important; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { text-decoration: none; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
        /* Mobile */
        @media screen and (max-width: 600px) {
            .nx-container { width: 100% !important; max-width: 100% !important; }
            .nx-px { padding-left: 20px !important; padding-right: 20px !important; }
            .nx-py { padding-top: 20px !important; padding-bottom: 20px !important; }
            .nx-h1 { font-size: 22px !important; line-height: 28px !important; }
            .nx-h2 { font-size: 18px !important; line-height: 24px !important; }
            .nx-stack { display: block !important; width: 100% !important; }
            .nx-hide-mobile { display: none !important; }
        }
        /* Dark mode (Apple Mail, iOS Mail, some Outlook.com surfaces) */
        @media (prefers-color-scheme: dark) {
            body, .nx-body-bg { background-color: #15171a !important; }
            .nx-card { background-color: #1f2226 !important; border-color: #2c3036 !important; }
            .nx-text { color: #e7eaee !important; }
            .nx-muted { color: #9aa1aa !important; }
            .nx-border { border-color: #2c3036 !important; }
            .nx-table-row { border-color: #2c3036 !important; }
            .nx-th { background-color: #24272c !important; color: #e7eaee !important; }
            .nx-h2 { color: #ffffff !important; }
            .nx-link { color: #7ab8ff !important; }
            .nx-footer-text { color: #9aa1aa !important; }
        }
        /* Outlook.com dark-mode hint */
        [data-ogsc] body, [data-ogsc] .nx-body-bg { background-color: #15171a !important; }
        [data-ogsc] .nx-card { background-color: #1f2226 !important; }
        [data-ogsc] .nx-text { color: #e7eaee !important; }
        [data-ogsc] .nx-muted, [data-ogsc] .nx-footer-text { color: #9aa1aa !important; }
    </style>
</head>
<body class="nx-body-bg" style="margin:0;padding:0;width:100%;background-color:<?php echo esc_attr( $body_bg ); ?>;font-family:<?php echo esc_attr( $font_stack ); ?>;color:<?php echo esc_attr( $text_color ); ?>;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;">

<?php // Hidden preheader (preview-pane text). Trailing whitespace pushes default Gmail "..." preview off-screen. ?>
<?php if ( '' !== $preheader ) : ?>
<div style="display:none;font-size:1px;color:<?php echo esc_attr( $body_bg ); ?>;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;visibility:hidden;">
    <?php echo esc_html( $preheader ); ?>
    <?php echo str_repeat( '&#847;&zwnj;&nbsp;', 60 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded HTML entities used as preheader spacer; escaping would corrupt the entities. ?>
</div>
<?php endif; ?>

<?php // Outer 100% wrapper table — mandatory for cross-client background colour. ?>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="nx-body-bg" style="background-color:<?php echo esc_attr( $body_bg ); ?>;width:100%;">
    <tr>
        <td align="center" valign="top" style="padding:24px 12px;">
            <!--[if mso | IE]>
            <table role="presentation" align="center" border="0" cellspacing="0" cellpadding="0" width="600" style="width:600px;"><tr><td>
            <![endif]-->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="nx-container" style="width:100%;max-width:600px;margin:0 auto;">
                <?php // Branded header bar. ?>
                <tr>
                    <td align="center" bgcolor="<?php echo esc_attr( $brand_color ); ?>" style="background-color:<?php echo esc_attr( $brand_color ); ?>;padding:24px 30px;border-radius:6px 6px 0 0;">
                        <?php if ( '' !== $logo_url ) : ?>
                            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;border:0;outline:none;">
                                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" width="160" style="display:block;border:0;outline:none;text-decoration:none;max-height:60px;width:auto;height:auto;" />
                            </a>
                        <?php else : ?>
                            <h1 class="nx-h1" style="margin:0;padding:0;color:#ffffff;font-family:<?php echo esc_attr( $font_stack ); ?>;font-size:24px;line-height:30px;font-weight:700;letter-spacing:-0.01em;">
                                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#ffffff;text-decoration:none;">
                                    <?php echo esc_html( $site_name ); ?>
                                </a>
                            </h1>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php // Content card. ?>
                <tr>
                    <td class="nx-card nx-px nx-py" bgcolor="<?php echo esc_attr( $container_bg ); ?>" style="background-color:<?php echo esc_attr( $container_bg ); ?>;padding:32px 36px;border-left:1px solid <?php echo esc_attr( $border_color ); ?>;border-right:1px solid <?php echo esc_attr( $border_color ); ?>;border-bottom:1px solid <?php echo esc_attr( $border_color ); ?>;border-radius:0 0 6px 6px;">
                        <div class="nx-text" style="font-family:<?php echo esc_attr( $font_stack ); ?>;font-size:15px;line-height:24px;color:<?php echo esc_attr( $text_color ); ?>;">

                            <?php if ( '' !== $email_heading ) : ?>
                                <h2 class="nx-h2" style="margin:0 0 16px;padding:0;color:<?php echo esc_attr( $brand_color ); ?>;font-family:<?php echo esc_attr( $font_stack ); ?>;font-size:22px;line-height:28px;font-weight:700;letter-spacing:-0.01em;">
                                    <?php echo esc_html( $email_heading ); ?>
                                </h2>
                            <?php endif; ?>
