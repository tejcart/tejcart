<?php
/**
 * GDPR exporter + eraser for the Store Insights module.
 *
 * The `tejcart_customer_ltv` table stores `customer_email` as a key
 * column. The exporter surfaces the aggregated LTV data for the
 * customer and the eraser deletes the rows outright (aggregated
 * analytics data has no accounting retention requirement).
 *
 * @package TejCart\Analytics_Advanced
 */

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {

	public const EXPORTER_ID = 'tejcart-analytics-advanced';
	public const ERASER_ID   = 'tejcart-analytics-advanced';

	public function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'TejCart Store Insights', 'tejcart' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'TejCart Store Insights', 'tejcart' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$data = array();

		if ( ! is_object( $wpdb ) ) {
			return array( 'data' => $data, 'done' => true );
		}

		$ltv_table = Schema::customer_ltv_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, order_count, total_revenue, avg_order_value,
				        first_order_date, last_order_date, acquisition_channel, cohort_month
				 FROM {$ltv_table}
				 WHERE customer_email = %s",
				$email
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array( 'data' => $data, 'done' => true );
		}

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'tejcart-analytics-advanced',
				'group_label' => (string) __( 'Store Insights (LTV)', 'tejcart' ),
				'item_id'     => 'ltv-' . sanitize_key( $email ) . '-' . sanitize_key( $row['currency'] ),
				'data'        => array(
					array( 'name' => __( 'Currency', 'tejcart' ),            'value' => $row['currency'] ),
					array( 'name' => __( 'Order Count', 'tejcart' ),         'value' => $row['order_count'] ),
					array( 'name' => __( 'Total Revenue', 'tejcart' ),       'value' => $row['total_revenue'] ),
					array( 'name' => __( 'Avg Order Value', 'tejcart' ),     'value' => $row['avg_order_value'] ),
					array( 'name' => __( 'First Order Date', 'tejcart' ),    'value' => $row['first_order_date'] ),
					array( 'name' => __( 'Last Order Date', 'tejcart' ),     'value' => $row['last_order_date'] ),
					array( 'name' => __( 'Acquisition Channel', 'tejcart' ), 'value' => $row['acquisition_channel'] ),
					array( 'name' => __( 'Cohort Month', 'tejcart' ),        'value' => $row['cohort_month'] ),
				),
			);
		}

		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
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

		$ltv_table = Schema::customer_ltv_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $ltv_table, array( 'customer_email' => $email ) );

		$out['items_removed'] = max( 0, (int) $deleted );
		if ( (int) $deleted > 0 ) {
			$out['messages'][] = (string) __( 'Advanced analytics LTV data removed.', 'tejcart' );
			Report_Cache::flush_all();
		}

		return $out;
	}
}
