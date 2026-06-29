<?php
/**
 * CSV helpers — extracted from src/functions.php (#1203 slice 1).
 *
 * Closes #1203 (slice 1). Full migration of the remaining 45 functions
 * is tracked in #1246.
 *
 * @package TejCart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tejcart_csv_sanitize_cell' ) ) {
	/**
	 * Sanitize a single CSV cell to defang formula injection.
	 *
	 * When a cell begins with `=`, `+`, `-`, `@`, tab, or carriage
	 * return, spreadsheet software (Excel, Google Sheets, LibreOffice)
	 * may interpret the value as a formula. Prefixing the cell with a
	 * single quote forces them to be treated as literal text.
	 *
	 * Non-string values are returned unchanged (casters like (int) /
	 * (float) at the call site are still the preferred way to handle
	 * numeric cells).
	 *
	 * @param mixed $value Raw cell value.
	 * @return mixed
	 */
	function tejcart_csv_sanitize_cell( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( '' === $value ) {
			return $value;
		}
		$first = $value[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}
		return $value;
	}
}

if ( ! function_exists( 'tejcart_csv_sanitize_row' ) ) {
	/**
	 * Apply tejcart_csv_sanitize_cell() to every element of a CSV row.
	 *
	 * @param array<int|string, mixed> $row Row of cell values.
	 * @return array<int|string, mixed>
	 */
	function tejcart_csv_sanitize_row( array $row ): array {
		return array_map( 'tejcart_csv_sanitize_cell', $row );
	}
}
