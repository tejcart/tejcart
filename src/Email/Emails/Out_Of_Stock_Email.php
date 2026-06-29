<?php
/**
 * Out of Stock Email.
 *
 * @package TejCart\Email\Emails
 */

declare( strict_types=1 );

namespace TejCart\Email\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Abstract_Email;

/**
 * Sends an out-of-stock alert email to the site administrator.
 *
 * Previously fired by `Low_Stock_Alert::send_out_of_stock_email()` via a
 * direct `wp_mail()` call (06 F-M1) which bypassed the Abstract_Email
 * scaffolding — no template, no preheader, no marker header (so the
 * Tier-2 email log couldn't see it), no per-message Content-Type lock.
 * Routing through this class gives admins the same enable/disable,
 * subject/heading override, template-system, and email-log surface
 * available for every other transactional email.
 */
class Out_Of_Stock_Email extends Abstract_Email {
    /**
     * Product ID this alert is about.
     *
     * @var int
     */
    protected $product_id = 0;

    /**
     * Constructor. Set defaults for the out-of-stock alert email.
     */
    public function __construct() {
        $this->id            = 'out_of_stock';
        $this->title         = 'Out of Stock Alert';
        $this->description   = 'Sent to the site admin when a product reaches zero stock.';
        $this->subject       = '[{site_title}] Out of stock: {product_name}';
        $this->heading       = 'Product Out of Stock';
        $this->template_html = 'emails/out-of-stock.php';
        $this->recipient     = get_option( 'admin_email' );

        parent::__construct();
    }

    /**
     * Trigger the email for a given product.
     *
     * @param int $product_id The product post ID.
     * @return void
     */
    public function trigger( $product_id ) {
        $this->product_id = absint( $product_id );

        if ( ! $this->product_id ) {
            return;
        }

        $this->send();
    }

    /**
     * Get the email subject with product-specific placeholder replacement.
     *
     * @return string
     */
    public function get_subject() {
        $product = function_exists( 'tejcart_get_product' ) ? tejcart_get_product( $this->product_id ) : null;
        $name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';

        $subject = str_replace( '{product_name}', sanitize_text_field( $name ), (string) $this->subject );
        $subject = str_replace( '{site_title}', get_bloginfo( 'name' ), $subject );

        return apply_filters( 'tejcart_email_subject', $subject, $this );
    }

    /**
     * Return template arguments for the out-of-stock email template.
     *
     * @return array<string, mixed>
     */
    public function get_template_args() {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $product_row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT name, sku FROM {$products_table} WHERE id = %d", $this->product_id ),
            ARRAY_A
        );

        return array(
            'product_id'    => $this->product_id,
            'product_name'  => $product_row ? (string) $product_row['name'] : '',
            'sku'           => $product_row ? (string) $product_row['sku'] : '',
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
