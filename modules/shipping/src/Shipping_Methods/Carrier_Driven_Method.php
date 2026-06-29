<?php
/**
 * Bridge between a Carrier_Driver and TejCart core's shipping system.
 *
 * Core's Shipping_Manager instantiates one of these per carrier method
 * stored in a zone (id `carrier_<driver-id>`); this class delegates
 * `calculate()` and availability checks to the underlying driver.
 *
 * @package TejCart\Shipping_Plugin\Shipping_Methods
 */

namespace TejCart\Shipping_Plugin\Shipping_Methods;

use TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method;
use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Box_Packer;
use TejCart\Shipping_Plugin\Core\Credentials_Vault;
use TejCart\Shipping_Plugin\Core\Package;
use TejCart\Shipping_Plugin\Core\Rate_Cache;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Admin\Carrier_State;
use TejCart\Shipping_Plugin\Core\Selected_Quote_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carrier_Driven_Method extends Abstract_Shipping_Method {
    private ?Abstract_Carrier_Driver $driver = null;
    private ?Credentials_Vault $vault = null;
    private ?Rate_Cache $cache = null;
    private ?Selected_Quote_Registry $selected_quotes = null;
    private ?Carrier_State $state = null;

    /**
     * Optional explicit service code this method instance is bound to.
     *
     * When set, only quotes whose service_code matches are considered;
     * the cheapest match is returned. When empty (back-compat), the
     * cheapest quote across all services is returned.
     */
    private string $service_code = '';

    /**
     * Estimated transit time in days for this method's service, as quoted
     * by the carrier. Null when the carrier doesn't return an ETA.
     */
    private ?int $eta_days = null;

    /**
     * Carrier-supplied literal delivery date (e.g. "Jun 26, 2026").
     * Empty when the carrier only returns a day count (or nothing).
     */
    private string $eta_date = '';

    /**
     * Wire dependencies after instantiation. Called from
     * `Plugin::configure_carrier_instance()` via the
     * `tejcart_shipping_method_instance` filter.
     */
    public function bind(
        Abstract_Carrier_Driver $driver,
        Credentials_Vault $vault,
        Rate_Cache $cache,
        ?Selected_Quote_Registry $selected_quotes = null,
        ?Carrier_State $state = null
    ): void {
        $this->driver          = $driver;
        $this->vault           = $vault;
        $this->cache           = $cache;
        $this->selected_quotes = $selected_quotes;
        $this->state           = $state;
        $this->id              = 'carrier_' . $driver->id();
        $this->title           = $driver->label();
    }

    /**
     * Bind this method instance to a specific carrier service code.
     * Used by the auto-fan-out path so each surfaced service has its
     * own settable price and availability check.
     */
    public function set_service( string $service_code, string $service_label, string $instance_id = '' ): void {
        $this->service_code = $service_code;
        if ( '' !== $service_label ) {
            $this->title = $service_label;
        }
        if ( '' !== $instance_id ) {
            $this->id = $instance_id;
        }
    }

    public function get_service_code(): string {
        return $this->service_code;
    }

    /**
     * Bind the carrier-quoted estimated delivery to this method instance.
     * Set by the fan-out path from the originating Rate_Quote so the
     * checkout method list can display it.
     *
     * @param int|null $days Estimated transit days (null when unknown).
     * @param string   $date Literal delivery date string (optional).
     */
    public function set_eta( ?int $days, string $date = '' ): void {
        $this->eta_days = ( null !== $days && $days > 0 ) ? $days : null;
        $this->eta_date = $date;
    }

    /**
     * Human-readable estimated-delivery line for the checkout method list.
     *
     * Presents a concrete, localized delivery *date* ("Estimated delivery
     * Mon, Jun 30") rather than a raw transit-day count — matching
     * Shopify's checkout presentation. Baymard's checkout UX research
     * likewise finds that shoppers shown only a shipping speed/day count
     * stall while they mentally extrapolate an arrival date (factoring
     * cutoff times, weekends and holidays), which adds friction and
     * abandonment — a specific date removes that work.
     *
     * Returns '' when the carrier supplied no ETA at all, so the
     * template's `method_exists( …, 'get_eta' )` guard renders nothing
     * rather than an empty badge.
     */
    public function get_eta(): string {
        $timestamp = $this->resolve_eta_timestamp();
        if ( null === $timestamp ) {
            return '';
        }

        /**
         * Filter the date format used for the checkout delivery estimate.
         *
         * Defaults to Shopify's weekday + month + day ("Mon, Jun 30") — an
         * unambiguous, locale-name-aware concrete date. Return any
         * `wp_date()`/PHP date format string to customise (e.g. to add the
         * year, or reorder for a locale convention).
         *
         * @param string $format Default 'D, M j'.
         */
        $format = function_exists( 'apply_filters' )
            ? (string) apply_filters( 'tejcart_shipping_eta_date_format', 'D, M j' )
            : 'D, M j';
        if ( '' === $format ) {
            $format = 'D, M j';
        }

        // wp_date() localizes month/weekday names and renders in the site
        // timezone; fall back to gmdate() outside a WP runtime.
        $formatted = function_exists( 'wp_date' )
            ? wp_date( $format, $timestamp )
            : gmdate( $format, $timestamp );
        if ( ! is_string( $formatted ) || '' === $formatted ) {
            return '';
        }

        /* translators: %s: estimated delivery date, already localized (e.g. "Mon, Jun 30"). */
        return sprintf( __( 'Estimated delivery %s', 'tejcart' ), $formatted );
    }

    /**
     * Resolve the estimated delivery moment as a Unix timestamp.
     *
     * Prefers the carrier's own computed delivery date (it already
     * accounts for cutoff times, transit, weekends and holidays); falls
     * back to deriving a date from the transit-day count so the shopper
     * still sees a concrete date rather than a count they must convert.
     * The day-count fallback assumes calendar days from now — carriers
     * that report business days can refine via the date-format filter or
     * by supplying an explicit date. Returns null when no ETA is known.
     */
    private function resolve_eta_timestamp(): ?int {
        $date = trim( $this->eta_date );
        if ( '' !== $date ) {
            $parsed = strtotime( $date );
            if ( false !== $parsed && $parsed > 0 ) {
                return $parsed;
            }
        }

        if ( null !== $this->eta_days ) {
            $day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
            $now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
            return $now + ( $this->eta_days * $day );
        }

        return null;
    }

    public function get_driver_id(): string {
        return null === $this->driver ? '' : $this->driver->id();
    }

    /**
     * Allow zone settings to set a service code at instantiation time.
     */
    public function set_settings( $settings ) {
        parent::set_settings( $settings );
        if ( is_array( $settings ) && isset( $settings['service_code'] ) && is_string( $settings['service_code'] ) ) {
            $this->service_code = $settings['service_code'];
        }
    }

    public function calculate( $cart ) {
        $quote = $this->selected_quote_for_cart( $cart );
        if ( null === $quote ) {
            return 0.0;
        }
        if ( null !== $this->selected_quotes ) {
            $this->selected_quotes->record( $this->id, $quote );
        }
        // Audit #9 / 04 H-1 — `cost_cents` is the smallest-unit field
        // off the carrier driver. Derive the major→minor divisor from
        // the store currency so JPY (0-decimal) and KWD/BHD/OMR
        // (3-decimal) flow correctly. 2-decimal currencies keep the
        // original behaviour (divisor 100).
        $currency = function_exists( 'get_option' )
            ? strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) )
            : 'USD';
        $multiplier = \TejCart\Money\Currency::multiplier( $currency );
        return $multiplier > 0 ? ( (int) $quote->cost_cents ) / $multiplier : 0.0;
    }

    public function is_available( $cart ) {
        if ( ! parent::is_available( $cart ) || null === $this->driver ) {
            return false;
        }
        return null !== $this->selected_quote_for_cart( $cart );
    }

    /**
     * Return the full quote list available for this cart — used by the
     * Plugin's fan-out hook to surface one shipping method per service.
     *
     * @return Rate_Quote[]
     */
    public function quotes_for_cart( $cart ): array {
        if ( null === $this->driver || null === $this->vault || null === $this->cache ) {
            return array();
        }
        // Honour the per-carrier on/off toggle from the admin list view.
        // Without this gate a paused carrier would still be polled for
        // rates on every cart calculation, which is wasted network work
        // and (for usage-priced aggregators) wasted money. Gating is
        // skipped when no state object was injected — keeps legacy tests
        // that hand-construct a method without a state working.
        if ( null !== $this->state && ! $this->state->is_enabled( $this->driver->id() ) ) {
            $this->log_bypass( 'carrier_disabled' );
            return array();
        }
        try {
            $request = $this->build_request_from_cart( $cart );
        } catch ( \Throwable $e ) {
            $this->log_bypass( 'cart_not_quotable:' . $e->getMessage() );
            return array();
        }
        $driver = $this->driver;
        $vault  = $this->vault;
        $quotes = $this->cache->get_or_compute(
            $driver->id(),
            $request,
            static function () use ( $driver, $request, $vault ): array {
                try {
                    return $driver->rates( $request, $vault->get( $driver->id() ) );
                } catch ( \Throwable $e ) {
                    // Defend the cart/checkout total against a misbehaving
                    // driver. A carrier returning a malformed payload could
                    // surface as a TypeError/JsonException (not just our
                    // Carrier_Exception); letting it escape would fatal the
                    // whole shipping calculation. Degrade to "no quotes".
                    return array();
                }
            }
        );
        if ( array() === $quotes ) {
            // Carrier was reachable enough to be called but returned no
            // quotes — typically missing/invalid credentials, an origin
            // the carrier can't ship from (e.g. Shiprocket configured
            // with a US store origin), or a destination outside service
            // area. Surface as a bypass so the merchant can diagnose
            // from WP_DEBUG_LOG without guessing.
            $this->log_bypass( 'empty_quotes' );
        }
        return $this->convert_to_store_currency( $this->filter_allowed( $quotes ) );
    }

    /**
     * Emit a single structured log line + action when this row is
     * bypassed at rate-quoting time. Lets ops dashboards count
     * "shipped via fallback because carrier paused" without parsing
     * cart-page HTML, and surfaces the event for distributed tracing.
     *
     * The action fires unconditionally; the file-log write is also
     * unconditional (no `WP_DEBUG_LOG` gate) — a configured carrier
     * that silently doesn't quote is the merchant's primary "why is
     * my carrier missing?" diagnostic, and forcing them to flip
     * `WP_DEBUG_LOG` on just to see it was the most common follow-up
     * report. The line lands in `{uploads}/tejcart-logs/shipping_<driver>-<date>-<hash>.log`.
     */
    private function log_bypass( string $reason ): void {
        if ( null === $this->driver ) {
            return;
        }
        $driver_id = $this->driver->id();

        /**
         * Fires whenever a carrier-driven method declines to quote.
         *
         * @param string $driver_id Carrier id (e.g. `shiprocket`).
         * @param string $reason    Machine-readable reason. Known values:
         *                          `carrier_disabled`, `cart_not_quotable:<detail>`,
         *                          `empty_quotes` (driver returned no rates —
         *                          usually missing credentials or unsupported
         *                          origin/destination pair),
         *                          `currency_mismatch:<from>-><to>` (carrier
         *                          quoted in a currency the store doesn't
         *                          use and no `tejcart_carrier_fx_rate`
         *                          filter is wired — quote dropped to
         *                          avoid mis-pricing the customer).
         * @param string $method_id The zone's `carrier_<id>[:service]`
         *                          instance id, useful for correlating
         *                          back to a specific zone row.
         */
        do_action( 'tejcart_shipping_carrier_bypassed', $driver_id, $reason, $this->id );

        if ( function_exists( 'tejcart_shipping_log' ) ) {
            tejcart_shipping_log(
                'shipping_' . $driver_id,
                'carrier_bypassed',
                array(
                    'driver_id' => $driver_id,
                    'reason'    => $reason,
                    'method_id' => $this->id,
                )
            );
        }
    }

    private function selected_quote_for_cart( $cart ): ?Rate_Quote {
        $quotes = $this->quotes_for_cart( $cart );
        if ( '' !== $this->service_code ) {
            $quotes = array_values( array_filter(
                $quotes,
                fn ( Rate_Quote $q ): bool => $q->service_code === $this->service_code
            ) );
        }
        return $this->cheapest( $quotes );
    }

    /**
     * Project carrier-quoted prices into the store's display currency.
     *
     * Each carrier API returns rates in its own native currency
     * (Shiprocket → INR, USPS → USD, Royal Mail → GBP, …) but the cart
     * calculator downstream just pipes the number through and labels
     * it with the store currency. Without a conversion step, an INR
     * 53.36 quote from Shiprocket renders as `$53.36` in a USD store —
     * ~84× the real price. That mis-pricing is a checkout-blocking bug
     * the customer can't see through.
     *
     * Rules:
     *  - When quote currency matches the store currency, pass through.
     *  - When they differ, ask the `tejcart_carrier_fx_rate` filter for
     *    a positive numeric rate (`store_units = quote_units * rate`).
     *  - When no rate is returned, DROP the quote and emit a
     *    `currency_mismatch:<from>-><to>` bypass so the customer never
     *    sees a wrong number and the merchant gets a single log line
     *    per (carrier, currency pair) telling them to either match
     *    store currency to the carrier or wire up an FX provider.
     *
     * @param Rate_Quote[] $quotes
     * @return Rate_Quote[]
     */
    private function convert_to_store_currency( array $quotes ): array {
        if ( array() === $quotes ) {
            return $quotes;
        }

        $store_currency = function_exists( 'get_option' )
            ? strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) )
            : 'USD';
        if ( '' === $store_currency ) {
            $store_currency = 'USD';
        }

        $out    = array();
        $logged = array();

        foreach ( $quotes as $quote ) {
            $quote_currency = strtoupper( $quote->currency );
            if ( $quote_currency === $store_currency ) {
                $out[] = $quote;
                continue;
            }

            /**
             * Filter the FX rate used to convert one carrier quote
             * into the store's display currency.
             *
             * Return a positive number to enable conversion: the cart
             * will charge `quote.cost_cents * rate` (rounded to the
             * nearest cent). Return null / false / 0 (or a negative
             * number) to leave the quote dropped — the customer never
             * sees a mis-priced shipping line and the merchant gets a
             * single bypass log entry per (carrier, from, to).
             *
             * @param mixed      $rate    Default: null (no conversion).
             * @param string     $from    Carrier-quoted currency (ISO 4217).
             * @param string     $to      Store currency (ISO 4217).
             * @param Rate_Quote $quote   Full quote, in case the callback
             *                            wants service-specific overrides.
             */
            $rate = function_exists( 'apply_filters' )
                ? apply_filters( 'tejcart_carrier_fx_rate', null, $quote_currency, $store_currency, $quote )
                : null;

            if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
                $key = $quote_currency . '->' . $store_currency;
                if ( ! isset( $logged[ $key ] ) ) {
                    $this->log_bypass( 'currency_mismatch:' . $key );
                    $logged[ $key ] = true;
                }
                continue;
            }

            $out[] = new Rate_Quote(
                carrier_id:    $quote->carrier_id,
                service_code:  $quote->service_code,
                service_label: $quote->service_label,
                cost_cents:    (int) round( $quote->cost_cents * (float) $rate ),
                currency:      $store_currency,
                eta_days:      $quote->eta_days,
                rate_id:       $quote->rate_id,
                meta:          array_merge( $quote->meta, array(
                    'original_currency'   => $quote_currency,
                    'original_cost_cents' => $quote->cost_cents,
                    'fx_rate'             => (float) $rate,
                ) )
            );
        }

        return $out;
    }

    /**
     * Apply the per-zone service-code allowlist filter.
     *
     * @param Rate_Quote[] $quotes
     * @return Rate_Quote[]
     */
    private function filter_allowed( array $quotes ): array {
        if ( null === $this->driver ) {
            return $quotes;
        }

        /**
         * Filter the allowed service codes for a given carrier driver.
         *
         * Return a non-empty array of service codes to narrow the visible
         * quotes; return an empty array (default) to expose every service
         * the carrier returns. The filter receives the original quote
         * list so a callback can inspect the choices before deciding.
         *
         * @param string[]     $allowed   Allowed service codes (default: empty = all).
         * @param string       $driver_id Carrier driver id.
         * @param Rate_Quote[] $quotes    All quotes returned by the carrier.
         */
        $allowed = apply_filters( 'tejcart_shipping_allowed_services', array(), $this->driver->id(), $quotes );
        if ( ! is_array( $allowed ) || array() === $allowed ) {
            return $quotes;
        }
        $set = array_flip( array_map( 'strval', $allowed ) );
        return array_values( array_filter(
            $quotes,
            static fn ( Rate_Quote $q ): bool => isset( $set[ $q->service_code ] )
        ) );
    }

    /**
     * @param Rate_Quote[] $quotes
     */
    private function cheapest( array $quotes ): ?Rate_Quote {
        $cheapest = null;
        foreach ( $quotes as $quote ) {
            if ( null === $cheapest || $quote->cost_cents < $cheapest->cost_cents ) {
                $cheapest = $quote;
            }
        }
        return $cheapest;
    }

    /**
     * Project a TejCart cart and customer destination into a Rate_Request.
     *
     * Cart shape varies across versions, so we read defensively. If the
     * cart hasn't yet captured a destination address we throw — the
     * Carrier_Driven_Method is then reported unavailable, which surfaces
     * to the customer as "select a different shipping method" rather
     * than a hard error.
     *
     * @param mixed $cart
     */
    private function build_request_from_cart( $cart ): Rate_Request {
        $destination = $this->extract_destination( $cart );
        if ( '' === ( $destination['country'] ?? '' ) ) {
            throw new \RuntimeException( 'destination not yet available' );
        }

        $origin   = $this->extract_origin();
        $packages = $this->extract_packages( $cart );

        if ( array() === $packages ) {
            throw new \RuntimeException( 'no shippable packages in cart' );
        }

        return new Rate_Request(
            $origin,
            $destination,
            $packages,
            (string) ( get_option( 'tejcart_currency' ) ?: 'USD' )
        );
    }

    /**
     * @return array<string,string>
     */
    private function extract_destination( $cart ): array {
        $address = array();
        if ( is_object( $cart ) ) {
            if ( method_exists( $cart, 'get_shipping_address' ) ) {
                $maybe = $cart->get_shipping_address();
                if ( is_array( $maybe ) ) {
                    $address = $maybe;
                }
            } elseif ( method_exists( $cart, 'get_shipping_destination' ) ) {
                // TejCart core Cart shape — keys: country, state, postcode,
                // city, line1 (note: `line1`, not `address_1`).
                $maybe = $cart->get_shipping_destination();
                if ( is_array( $maybe ) ) {
                    $address = $maybe;
                }
            }
        }

        $line1 = '';
        if ( isset( $address['address_1'] ) ) {
            $line1 = (string) $address['address_1'];
        } elseif ( isset( $address['line1'] ) ) {
            $line1 = (string) $address['line1'];
        }

        return array(
            'country'  => isset( $address['country'] ) ? (string) $address['country'] : '',
            'state'    => isset( $address['state'] ) ? (string) $address['state'] : '',
            'city'     => isset( $address['city'] ) ? (string) $address['city'] : '',
            'postcode' => isset( $address['postcode'] ) ? (string) $address['postcode'] : '',
            'line1'    => $line1,
        );
    }

    /**
     * @return array<string,string>
     */
    private function extract_origin(): array {
        return array(
            'country'  => (string) get_option( 'tejcart_store_country', 'US' ),
            'state'    => (string) get_option( 'tejcart_store_state', '' ),
            'city'     => (string) get_option( 'tejcart_store_city', '' ),
            'postcode' => (string) get_option( 'tejcart_store_postcode', '' ),
            'line1'    => (string) get_option( 'tejcart_store_address', '' ),
        );
    }

    /**
     * Project cart items into Package value objects.
     *
     * One Package per cart item per quantity unit by default — this preserves
     * per-item dimensions so dimensional-weight-sensitive carriers (UPS,
     * FedEx, DHL) can charge correctly on bulky low-density items. Filter
     * `tejcart_shipping_packages` to inject a real bin-packer.
     *
     * @return Package[]
     */
    private function extract_packages( $cart ): array {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_items' ) ) {
            return array();
        }

        $items = $cart->get_items();
        if ( ! is_array( $items ) || array() === $items ) {
            return array();
        }

        $packages = array();

        foreach ( $items as $item ) {
            $qty       = max( 1, $this->item_int( $item, array( 'get_quantity', 'quantity' ), 1 ) );
            $grams     = $this->item_int( $item, array( 'get_weight_grams', 'weight_grams' ), 0 );
            $length_mm = $this->item_int( $item, array( 'get_length_mm', 'length_mm' ), 0 );
            $height_mm = $this->item_int( $item, array( 'get_height_mm', 'height_mm' ), 0 );
            $depth_mm  = $this->item_int( $item, array( 'get_depth_mm', 'depth_mm' ), 0 );
            $cents     = $this->item_int( $item, array( 'get_subtotal_cents', 'subtotal_cents' ), 0 );

            // TejCart core Cart_Item shape: weight + dimensions live on the
            // linked product in option-configured units (e.g. kg, cm), and
            // the line subtotal is a decimal float. Translate to the
            // gram/mm/cent canonical form the carrier APIs expect.
            if ( $grams <= 0 || ( $length_mm + $height_mm + $depth_mm ) === 0 ) {
                $product = is_object( $item ) && method_exists( $item, 'get_product' ) ? $item->get_product() : null;
                if ( is_object( $product ) ) {
                    if ( $grams <= 0 && method_exists( $product, 'get_weight' ) ) {
                        $grams = $this->weight_to_grams( $product->get_weight() );
                    }
                    if ( ( $length_mm + $height_mm + $depth_mm ) === 0 && method_exists( $product, 'get_dimensions' ) ) {
                        $dims = $product->get_dimensions();
                        if ( is_array( $dims ) ) {
                            $length_mm = $this->dimension_to_mm( $dims['length'] ?? 0 );
                            $height_mm = $this->dimension_to_mm( $dims['height'] ?? 0 );
                            $depth_mm  = $this->dimension_to_mm( $dims['width'] ?? 0 );
                        }
                    }
                }
            }
            if ( 0 === $cents && is_object( $item ) && method_exists( $item, 'get_subtotal' ) ) {
                // Audit #9 / 04 H-1 — use the store-currency
                // multiplier so JPY / KWD-class amounts pack
                // correctly into the carrier's smallest-unit field.
                $pkg_currency = function_exists( 'get_option' )
                    ? strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) )
                    : 'USD';
                $pkg_mult     = \TejCart\Money\Currency::multiplier( $pkg_currency );
                $cents        = (int) round( ( (float) $item->get_subtotal() ) * $pkg_mult );
            }

            if ( $grams <= 0 ) {
                continue;
            }

            $unit_value_cents = $qty > 0 ? (int) round( $cents / $qty ) : $cents;

            for ( $i = 0; $i < $qty; $i++ ) {
                $packages[] = new Package(
                    weight_grams: $grams,
                    length_mm: max( 0, $length_mm ),
                    height_mm: max( 0, $height_mm ),
                    depth_mm: max( 0, $depth_mm ),
                    declared_value_cents: max( 0, $unit_value_cents )
                );
            }
        }

        if ( array() === $packages ) {
            return array();
        }

        $packages = ( new Box_Packer() )->pack( $packages, null === $this->driver ? '' : $this->driver->id() );

        /**
         * Filter the projected packages just before they are sent to a carrier.
         *
         * Replace this with a bin-packer (3D-bin, weight-tier, custom box
         * library) without touching the rest of the rate flow.
         *
         * @param Package[] $packages
         * @param mixed     $cart
         * @param string    $driver_id
         */
        $filtered = apply_filters(
            'tejcart_shipping_packages',
            $packages,
            $cart,
            null === $this->driver ? '' : $this->driver->id()
        );

        if ( ! is_array( $filtered ) || array() === $filtered ) {
            return $packages;
        }

        $clean = array();
        foreach ( $filtered as $package ) {
            if ( $package instanceof Package ) {
                $clean[] = $package;
            }
        }
        return array() === $clean ? $packages : $clean;
    }

    /**
     * Convert a stored product weight (in `tejcart_weight_unit`, default kg)
     * to grams. Unknown units fall through to kg so a misconfigured option
     * doesn't silently zero out every shipment weight.
     *
     * @param mixed $weight
     */
    private function weight_to_grams( $weight ): int {
        if ( '' === $weight || null === $weight ) {
            return 0;
        }
        $w = (float) $weight;
        if ( $w <= 0 ) {
            return 0;
        }
        $unit = function_exists( 'get_option' ) ? strtolower( (string) get_option( 'tejcart_weight_unit', 'kg' ) ) : 'kg';
        $grams = match ( $unit ) {
            'g'   => $w,
            'lbs', 'lb' => $w * 453.59237,
            'oz'  => $w * 28.349523125,
            default => $w * 1000.0, // kg
        };
        return (int) round( $grams );
    }

    /**
     * Convert a stored product dimension (in `tejcart_dimension_unit`,
     * default cm) to millimetres.
     *
     * @param mixed $dim
     */
    private function dimension_to_mm( $dim ): int {
        if ( '' === $dim || null === $dim ) {
            return 0;
        }
        $d = (float) $dim;
        if ( $d <= 0 ) {
            return 0;
        }
        $unit = function_exists( 'get_option' ) ? strtolower( (string) get_option( 'tejcart_dimension_unit', 'cm' ) ) : 'cm';
        $mm = match ( $unit ) {
            'mm' => $d,
            'm'  => $d * 1000.0,
            'in' => $d * 25.4,
            'yd' => $d * 914.4,
            default => $d * 10.0, // cm
        };
        return (int) round( $mm );
    }

    /**
     * Read an int from an item via candidate getters then property fallback.
     *
     * @param mixed         $item
     * @param array<string> $candidates Method names to try in order.
     */
    private function item_int( $item, array $candidates, int $default ): int {
        if ( is_object( $item ) ) {
            foreach ( $candidates as $name ) {
                if ( method_exists( $item, $name ) ) {
                    return (int) $item->{$name}();
                }
            }
            foreach ( $candidates as $name ) {
                if ( property_exists( $item, $name ) ) {
                    return (int) $item->{$name};
                }
            }
        }
        if ( is_array( $item ) ) {
            foreach ( $candidates as $name ) {
                if ( isset( $item[ $name ] ) ) {
                    return (int) $item[ $name ];
                }
            }
        }
        return $default;
    }
}
