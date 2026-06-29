<?php
/**
 * Low Stock Digest Email.
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
 * Sends a single HTML digest email to the site administrator summarising
 * every product that crossed the low-stock or out-of-stock threshold.
 *
 * Replaces two legacy plain-text `wp_mail()` senders that bypassed the
 * Abstract_Email scaffolding (the per-order digest in Low_Stock_Alert and
 * the scheduled sweep in Action_Scheduler) — routing both through this
 * class gives the digest the same designed HTML template, preheader,
 * marker header (so the Tier-2 email log can see it), per-message
 * Content-Type lock, and admin enable/disable surface as every other
 * transactional email.
 */
class Low_Stock_Digest_Email extends Abstract_Email {
    /**
     * Product IDs that are low on stock (but still in stock).
     *
     * @var int[]
     */
    protected $low_ids = array();

    /**
     * Product IDs that are out of stock.
     *
     * @var int[]
     */
    protected $out_ids = array();

    /**
     * Constructor. Set defaults for the low-stock digest email.
     */
    public function __construct() {
        $this->id            = 'low_stock_digest';
        $this->title         = 'Low Stock Digest';
        $this->description   = 'Sent to the site admin summarising every product that has reached its low-stock or out-of-stock threshold.';
        $this->subject       = '[{site_title}] Stock alert: products need attention';
        $this->heading       = 'Stock Alert';
        $this->template_html = 'emails/low-stock-digest.php';
        $this->recipient     = get_option( 'admin_email' );

        parent::__construct();
    }

    /**
     * Trigger the digest for a set of low-stock and out-of-stock products.
     *
     * @param int[]  $low_ids   Product IDs that are low on stock.
     * @param int[]  $out_ids   Product IDs that are out of stock.
     * @param string $recipient Optional recipient override (e.g. a filtered
     *                          admin address). Falls back to the configured
     *                          per-email recipient / admin_email when blank.
     * @return void
     */
    public function trigger( $low_ids, $out_ids = array(), $recipient = '' ) {
        $this->low_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $low_ids ) ) ) );
        $this->out_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $out_ids ) ) ) );

        // A product that is both out and (stale) low should only appear in
        // the out-of-stock list.
        $this->low_ids = array_values( array_diff( $this->low_ids, $this->out_ids ) );

        if ( empty( $this->low_ids ) && empty( $this->out_ids ) ) {
            return;
        }

        $recipient = is_string( $recipient ) ? sanitize_email( $recipient ) : '';
        if ( '' !== $recipient && is_email( $recipient ) ) {
            $this->recipient = $recipient;
        }

        $this->send();
    }

    /**
     * Get the email subject with the product count substituted in.
     *
     * @return string
     */
    public function get_subject() {
        $count   = count( $this->low_ids ) + count( $this->out_ids );
        $default = sprintf(
            /* translators: 1: site title, 2: number of products */
            _n(
                '[%1$s] Stock alert: %2$d product needs attention',
                '[%1$s] Stock alert: %2$d products need attention',
                $count,
                'tejcart'
            ),
            sanitize_text_field( (string) get_bloginfo( 'name' ) ),
            $count
        );

        // Honour an admin-customised subject (with {site_title} support)
        // when one has been saved; otherwise use the count-aware default.
        $saved = (string) $this->subject;
        if ( '[{site_title}] Stock alert: products need attention' !== $saved ) {
            $subject = str_replace( '{site_title}', sanitize_text_field( (string) get_bloginfo( 'name' ) ), $saved );
        } else {
            $subject = $default;
        }

        $subject = str_replace( array( "\r", "\n" ), '', $subject );

        return apply_filters( 'tejcart_email_subject', $subject, $this );
    }

    /**
     * Resolve product rows for the queued IDs and split them into
     * out-of-stock and low-stock lists for the template.
     *
     * @return array<string, mixed>
     */
    public function get_template_args() {
        $ids = array_values( array_unique( array_merge( $this->out_ids, $this->low_ids ) ) );
        $by_id = array();

        if ( ! empty( $ids ) ) {
            global $wpdb;
            $products_table = $wpdb->prefix . 'tejcart_products';
            $placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id, name, sku, stock_quantity FROM {$products_table} WHERE id IN ({$placeholders})",
                    ...$ids
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

            foreach ( (array) $rows as $row ) {
                $by_id[ (int) $row['id'] ] = $row;
            }
        }

        $build = function ( $id ) use ( $by_id ) {
            $row = $by_id[ $id ] ?? array();
            return array(
                'id'    => $id,
                'name'  => isset( $row['name'] ) && '' !== (string) $row['name'] ? (string) $row['name'] : ( '#' . $id ),
                'sku'   => isset( $row['sku'] ) ? (string) $row['sku'] : '',
                'stock' => isset( $row['stock_quantity'] ) ? (int) $row['stock_quantity'] : 0,
            );
        };

        return array(
            'out_products'  => array_map( $build, $this->out_ids ),
            'low_products'  => array_map( $build, $this->low_ids ),
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        );
    }
}
