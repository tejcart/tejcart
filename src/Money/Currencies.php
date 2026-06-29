<?php
/**
 * ISO 4217 currency catalogue.
 *
 * @package TejCart\Money
 */

declare( strict_types = 1 );

namespace TejCart\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper exposing the full ISO 4217 currency dataset (code => name + symbol).
 *
 * Loaded from src/Money/Data/currencies.php so the same table feeds the store-
 * settings dropdown, the {@see tejcart_get_currency_symbol()} formatter, and the
 * /tejcart/v1/data/currencies REST endpoint. Mirrors the standard WordPress
 * currency surface so merchants migrating across platforms see the same choice set.
 *
 * Companion {@see Currency} class still owns the decimal/multiplier maths — this
 * one only knows display strings.
 */
final class Currencies {
	/**
	 * In-process memoisation of the dataset.
	 *
	 * @var array<string, array{name: string, symbol: string}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Full code => { name, symbol } catalogue.
	 *
	 * Filterable via `tejcart_currencies` so extensions (crypto gateways,
	 * regional sandboxes) can append or override entries without forking
	 * the data file.
	 *
	 * @return array<string, array{name: string, symbol: string}>
	 */
	public static function get_currencies(): array {
		if ( null === self::$cache ) {
			$data        = require __DIR__ . '/Data/currencies.php';
			self::$cache = is_array( $data ) ? $data : array();
		}

		$currencies = self::$cache;

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tejcart_currencies', $currencies );
			if ( is_array( $filtered ) ) {
				$currencies = $filtered;
			}
		}

		return $currencies;
	}

	/**
	 * Symbol for the given currency, or the code itself when unknown.
	 *
	 * @param string $code Three-letter ISO 4217 code.
	 * @return string Display symbol (e.g. "$", "€", "₹").
	 */
	public static function get_symbol( string $code ): string {
		$code        = strtoupper( $code );
		$currencies  = self::get_currencies();
		return isset( $currencies[ $code ] ) ? (string) $currencies[ $code ]['symbol'] : $code;
	}

	/**
	 * Human-readable name for the given currency, or the code itself when unknown.
	 *
	 * @param string $code Three-letter ISO 4217 code.
	 * @return string English currency name (e.g. "United States (US) dollar").
	 */
	public static function get_name( string $code ): string {
		$code        = strtoupper( $code );
		$currencies  = self::get_currencies();
		return isset( $currencies[ $code ] ) ? (string) $currencies[ $code ]['name'] : $code;
	}

	/**
	 * Reset the in-process cache. Tests use this between cases that mutate
	 * the catalogue via the `tejcart_currencies` filter.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}
}
