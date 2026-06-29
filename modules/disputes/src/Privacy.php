<?php
/**
 * GDPR exporter + eraser for the Disputes module.
 *
 * Hooks into the documented core privacy filters:
 *  - `wp_privacy_personal_data_exporters`
 *  - `wp_privacy_personal_data_erasers`
 *
 * Exporter: returns disputes whose linked order belongs to the
 * customer (matched by orders.customer_email). Each row exposes
 * gateway, external_id, reason, outcome, amount, currency, opened_at,
 * resolved_at, and the merchant-authored notes.
 *
 * Eraser: redacts the `notes` and `payload` columns to a fixed
 * sentinel and clears `transaction_ref`. The financial dispute row
 * itself is retained (required for accounting / chargeback dispute
 * audit), only the PII-bearing free-text fields are anonymised.
 *
 * Closes #1214 (disputes slice).
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Privacy {
    public const EXPORTER_ID = 'tejcart-disputes';
    public const ERASER_ID   = 'tejcart-disputes';

    public function register(): void {
        if ( ! function_exists( 'add_filter' ) ) {
            return;
        }
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
    }

    public function register_exporter( array $exporters ): array {
        $exporters[ self::EXPORTER_ID ] = array(
            'exporter_friendly_name' => __( 'TejCart Disputes', 'tejcart' ),
            'callback'               => array( $this, 'export' ),
        );
        return $exporters;
    }

    public function register_eraser( array $erasers ): array {
        $erasers[ self::ERASER_ID ] = array(
            'eraser_friendly_name' => __( 'TejCart Disputes', 'tejcart' ),
            'callback'             => array( $this, 'erase' ),
        );
        return $erasers;
    }

    /**
     * @param string $email
     * @param int    $page  Currently unused — disputes per customer are
     *                      always a small set; we return them all in
     *                      page 1.
     * @return array{data: array<int,array{group_id:string,group_label:string,item_id:string,data:array<int,array{name:string,value:mixed}>}>, done: bool}
     */
    public function export( string $email, int $page = 1 ): array {
        global $wpdb;
        $data = array();

        if ( ! is_object( $wpdb ) ) {
            return array( 'data' => $data, 'done' => true );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.id, d.gateway, d.external_id, d.reason, d.outcome, d.status,
                        d.amount, d.currency, d.opened_at, d.resolved_at, d.notes
                 FROM {$wpdb->prefix}tejcart_disputes d
                 INNER JOIN {$wpdb->prefix}tejcart_orders o ON o.id = d.order_id
                 WHERE o.customer_email = %s
                 ORDER BY d.opened_at DESC",
                $email
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return array( 'data' => $data, 'done' => true );
        }

        foreach ( $rows as $row ) {
            $data[] = array(
                'group_id'    => 'tejcart-disputes',
                'group_label' => (string) __( 'Disputes', 'tejcart' ),
                'item_id'     => 'dispute-' . (int) $row['id'],
                'data'        => array(
                    array( 'name' => __( 'Gateway', 'tejcart' ),        'value' => $row['gateway'] ),
                    array( 'name' => __( 'External ID', 'tejcart' ),    'value' => $row['external_id'] ),
                    array( 'name' => __( 'Reason', 'tejcart' ),         'value' => $row['reason'] ),
                    array( 'name' => __( 'Status', 'tejcart' ),         'value' => $row['status'] ),
                    array( 'name' => __( 'Outcome', 'tejcart' ),        'value' => $row['outcome'] ),
                    array( 'name' => __( 'Amount', 'tejcart' ),         'value' => $row['amount'] . ' ' . $row['currency'] ),
                    array( 'name' => __( 'Opened at', 'tejcart' ),      'value' => $row['opened_at'] ),
                    array( 'name' => __( 'Resolved at', 'tejcart' ),    'value' => $row['resolved_at'] ),
                    array( 'name' => __( 'Notes', 'tejcart' ),          'value' => (string) $row['notes'] ),
                ),
            );
        }

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * @param string $email
     * @param int    $page
     * @return array{items_removed:int, items_retained:int, messages:array<int,string>, done:bool}
     */
    public function erase( string $email, int $page = 1 ): array {
        global $wpdb;
        $out = array(
            'items_removed'  => 0,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => true,
        );

        if ( ! is_object( $wpdb ) ) {
            return $out;
        }

        // Anonymise PII-bearing free-text columns on every dispute
        // linked to the customer's orders. The financial row is kept
        // for accounting / chargeback audit retention.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tejcart_disputes d
                 INNER JOIN {$wpdb->prefix}tejcart_orders o ON o.id = d.order_id
                 SET d.notes = '', d.payload = '', d.transaction_ref = ''
                 WHERE o.customer_email = %s",
                $email
            )
        );

        $out['items_retained'] = max( 0, (int) $rows );
        if ( (int) $rows > 0 ) {
            $out['messages'][] = (string) __( 'Dispute notes anonymised; financial record retained for accounting compliance.', 'tejcart' );
        }

        return $out;
    }
}
