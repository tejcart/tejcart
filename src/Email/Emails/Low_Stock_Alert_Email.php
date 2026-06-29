<?php
/**
 * Low Stock Alert Email.
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
 * Sends a low-stock alert email to the site administrator.
 */
class Low_Stock_Alert_Email extends Abstract_Email {
    /**
     * Product ID this alert is about.
     *
     * @var int
     */
    protected $product_id = 0;

    /**
     * Constructor. Set defaults for the low stock alert email.
     */
    public function __construct() {
        // Must match the registry key in Email_Manager::init()
        // ('low_stock_alert') so per-email settings, Tier-2 template
        // overrides, the email log, and the admin "Send test" / preview
        // UI all key on the same id.
        $this->id            = 'low_stock_alert';
        $this->title         = 'Low Stock Alert';
        $this->description   = 'Sent to the site admin when a product stock reaches the low-stock threshold.';
        $this->subject       = '[{site_title}] Low stock: {product_name}';
        $this->heading       = 'Low Stock Alert';
        $this->template_html = 'emails/low-stock-alert.php';
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
        $product = tejcart_get_product( $this->product_id );
        $name    = $product ? $product->get_name() : '';

        // Defence in depth (audit 06 F-L4): product names are
        // sanitize_text_field-stripped at save, but a subject line
        // round-trips through SMTP headers where CR/LF would split
        // the message. Sanitise on substitution as well.
        $name = sanitize_text_field( (string) $name );

        $subject = str_replace(
            '{product_name}',
            $name,
            $this->subject
        );

        $subject = str_replace( '{site_title}', sanitize_text_field( (string) get_bloginfo( 'name' ) ), $subject );

        return apply_filters( 'tejcart_email_subject', $subject, $this );
    }

    /**
     * Return template arguments for the low stock alert template.
     *
     * @return array
     */
    public function get_template_args() {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $product_row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT name, sku, stock_quantity FROM {$products_table} WHERE id = %d", $this->product_id ),
            ARRAY_A
        );

        return array(
            'product_id'    => $this->product_id,
            'product_name'  => $product_row ? $product_row['name'] : '',
            'sku'           => $product_row ? $product_row['sku'] : '',
            'stock'         => $product_row ? (int) $product_row['stock_quantity'] : 0,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
