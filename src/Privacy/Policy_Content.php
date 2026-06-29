<?php
/**
 * Suggested Privacy Policy Content.
 *
 * Starter text shown to the site owner under Settings → Privacy so they can
 * copy it into their published policy.
 *
 * @package TejCart\Privacy
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace TejCart\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers TejCart's suggested privacy policy block.
 */
class Policy_Content {
    /**
     * Hook into WP's privacy manager.
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'add_content' ) );
    }

    /**
     * Register suggested content via wp_add_privacy_policy_content().
     */
    public function add_content(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = $this->get_content();
        wp_add_privacy_policy_content( 'TejCart', wp_kses_post( wpautop( $content, false ) ) );
    }

    /**
     * Build the policy content.
     *
     * @return string
     */
    private function get_content(): string {
        $content = '';

        $content .= '<h2>' . esc_html__( 'TejCart — Data we collect and why', 'tejcart' ) . '</h2>';

        $content .= '<h3>' . esc_html__( 'At checkout', 'tejcart' ) . '</h3>';
        $content .= '<p>' . esc_html__( 'When you place an order, we collect your name, email, billing and shipping addresses, phone number (when provided), and the items you purchase. We use this information to process your order, deliver products, calculate tax, and comply with legal and tax obligations.', 'tejcart' ) . '</p>';

        $content .= '<h3>' . esc_html__( 'Payment', 'tejcart' ) . '</h3>';
        $content .= '<p>' . esc_html__( 'Payments are processed by PayPal. We share your name, email, address, and order details with PayPal to complete the transaction. PayPal may collect additional data under its own privacy policy. We do not store full card numbers — only a tokenized reference, brand, and last-4 digits when you choose to save a payment method.', 'tejcart' ) . '</p>';

        $content .= '<h3>' . esc_html__( 'Cookies', 'tejcart' ) . '</h3>';
        $content .= '<ul>';
        $content .= '<li>' . esc_html__( 'Cart session cookie — up to 48 hours, so your cart persists between pages.', 'tejcart' ) . '</li>';
        $content .= '<li>' . esc_html__( 'Wishlist cookie — up to 30 days, so guests can keep a wishlist across visits.', 'tejcart' ) . '</li>';
        $content .= '<li>' . esc_html__( 'Recently viewed cookie — up to 14 days, so we can show you products you have looked at.', 'tejcart' ) . '</li>';
        $content .= '</ul>';

        $content .= '<h3>' . esc_html__( 'Retention', 'tejcart' ) . '</h3>';
        $content .= '<p>' . esc_html__( 'Order records are retained for as long as legally required for tax and accounting purposes. Addresses, wishlist, and saved payment methods are retained until you remove them or delete your account. Abandoned carts are retained for up to 30 days for recovery emails.', 'tejcart' ) . '</p>';

        $content .= '<h3>' . esc_html__( 'Your rights', 'tejcart' ) . '</h3>';
        $content .= '<p>' . esc_html__( 'You can request an export or deletion of your personal data from your account page, or via Tools → Export/Erase Personal Data in the admin. Order financial records are anonymized rather than deleted, because tax authorities require them to be retained.', 'tejcart' ) . '</p>';

        $content .= '<h3>' . esc_html__( 'Third parties', 'tejcart' ) . '</h3>';
        $content .= '<p>' . esc_html__( 'We share data with these processors only as needed to deliver the service: PayPal (payments), your chosen shipping carrier (delivery), and email delivery providers you configure (transactional emails).', 'tejcart' ) . '</p>';

        return $content;
    }
}
