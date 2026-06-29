<?php
/**
 * Read/write access to the `tejcart_csw_options` currency map.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repository for stored {@see Currency_Config} entries.
 *
 * The store is a simple ISO-code-keyed map under the WP option
 * `tejcart_csw_options`. We never include the store base currency in
 * the option — it always has an implicit rate of 1.0 and uses the
 * formatting saved on the store-level `tejcart_*` options.
 *
 * The parsed currency map is memoised in a static cache shared across
 * every Repository instance for the request. Price filters fire dozens
 * of times per page render on a busy catalog; keeping the parse cost
 * to one `get_option` + one walk per request is the difference between
 * a hot-path that's microseconds and one that's milliseconds. The
 * cache is keyed on the raw option contents so any write through
 * `replace()` / `save()` invalidates it transparently for the caller.
 */
final class Currency_Repository {
    /**
     * Per-request memoised parse of `tejcart_csw_options`.
     *
     * @var array<string, Currency_Config>|null
     */
    private static ?array $shared_cache = null;

    /**
     * Per-request memoised base currency.
     */
    private static ?string $shared_base = null;

    /**
     * All configured non-base currencies, keyed by uppercase ISO code.
     *
     * @return array<string, Currency_Config>
     */
    public function all(): array {
        if ( null !== self::$shared_cache ) {
            return self::$shared_cache;
        }

        $raw = get_option( Options::CURRENCIES, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $base = $this->base_currency();
        $out  = array();

        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $cfg = Currency_Config::from_array( $row );
            if ( 'XXX' === $cfg->code || $cfg->code === $base ) {
                continue;
            }
            $out[ $cfg->code ] = $cfg;
        }

        self::$shared_cache = $out;
        return $out;
    }

    /**
     * Return a single currency config or null if not configured.
     */
    public function get( string $code ): ?Currency_Config {
        $code = strtoupper( $code );
        $all  = $this->all();
        return $all[ $code ] ?? null;
    }

    /**
     * Whether the supplied code is either the base currency or a
     * configured non-base currency (i.e. legal to display).
     */
    public function is_known( string $code ): bool {
        $code = strtoupper( $code );
        if ( $code === $this->base_currency() ) {
            return true;
        }
        return null !== $this->get( $code );
    }

    /**
     * Replace the entire stored set. Pass an array of {@see Currency_Config}
     * (or array-shapes) — invalid rows are dropped silently.
     *
     * @param array<int|string, Currency_Config|array<string, mixed>> $entries
     */
    public function replace( array $entries ): void {
        $base = $this->base_currency();
        $out  = array();
        foreach ( $entries as $entry ) {
            $cfg = $entry instanceof Currency_Config
                ? $entry
                : Currency_Config::from_array( (array) $entry );
            if ( 'XXX' === $cfg->code || $cfg->code === $base ) {
                continue;
            }
            $out[] = $cfg->to_array();
        }
        update_option( Options::CURRENCIES, $out, false );
        self::$shared_cache = null;
    }

    /**
     * Persist a single currency's row, replacing any existing one for
     * the same code.
     */
    public function save( Currency_Config $cfg ): void {
        $existing = $this->all();
        $existing[ $cfg->code ] = $cfg;
        $this->replace( $existing );
    }

    /**
     * Update only the live rate for a single currency. Used by the
     * exchange-rate cron / manual refresh — we keep all the formatting
     * fields the admin had configured.
     *
     * Returns true if the row was found and updated.
     */
    public function update_rate( string $code, float $rate ): bool {
        $code = strtoupper( $code );
        $cfg  = $this->get( $code );
        if ( null === $cfg ) {
            return false;
        }
        $next = new Currency_Config(
            $cfg->code,
            $rate,
            $cfg->rate_type,
            $cfg->fee,
            $cfg->fee_type,
            $cfg->currency_pos,
            $cfg->thousand_sep,
            $cfg->decimal_sep,
            $cfg->num_decimals,
        );
        $this->save( $next );
        return true;
    }

    /**
     * Bulk variant of {@see self::update_rate()} for the cron path —
     * applies many `[code => rate]` pairs in a single `update_option`
     * call instead of one alloptions-invalidation per currency. Returns
     * the set of codes that were actually written (codes that aren't
     * configured are silently dropped).
     *
     * @param array<string, float> $rates  Map of ISO code → new rate.
     * @return string[] Codes that were updated.
     */
    public function bulk_update_rates( array $rates ): array {
        if ( empty( $rates ) ) {
            return array();
        }
        $existing = $this->all();
        $updated  = array();
        foreach ( $rates as $code => $rate ) {
            $code = strtoupper( (string) $code );
            $rate = (float) $rate;
            if ( $rate <= 0.0 ) {
                continue;
            }
            $cfg = $existing[ $code ] ?? null;
            if ( null === $cfg ) {
                continue;
            }
            $existing[ $code ] = new Currency_Config(
                $cfg->code,
                $rate,
                $cfg->rate_type,
                $cfg->fee,
                $cfg->fee_type,
                $cfg->currency_pos,
                $cfg->thousand_sep,
                $cfg->decimal_sep,
                $cfg->num_decimals,
            );
            $updated[] = $code;
        }
        if ( empty( $updated ) ) {
            return array();
        }
        $this->replace( $existing );
        return $updated;
    }

    /**
     * Drop a configured currency by code. No-op if not configured.
     */
    public function delete( string $code ): void {
        $code     = strtoupper( $code );
        $existing = $this->all();
        if ( ! isset( $existing[ $code ] ) ) {
            return;
        }
        unset( $existing[ $code ] );
        $this->replace( $existing );
    }

    /**
     * The store's base currency code (uppercase). Memoised per request
     * because every price filter and gateway-filter callback asks for
     * it; reading `tejcart_currency` via the alloptions cache is still
     * a function call we can elide entirely.
     */
    public function base_currency(): string {
        if ( null !== self::$shared_base ) {
            return self::$shared_base;
        }
        $code = (string) get_option( 'tejcart_currency', 'USD' );
        return self::$shared_base = strtoupper( $code );
    }

    /**
     * Reset the in-memory cache.
     *
     * Called automatically on writes and exposed for tests that mutate
     * `tejcart_csw_options` / `tejcart_currency` directly between
     * scenarios and need the next read to hit the option store.
     */
    public function flush(): void {
        self::$shared_cache = null;
        self::$shared_base  = null;
    }

    /**
     * Test/runtime helper — drops the shared cache without needing an
     * instance. Used by hook handlers that fire after option updates.
     */
    public static function flush_shared(): void {
        self::$shared_cache = null;
        self::$shared_base  = null;
    }
}
