<?php
/**
 * Per-request registry that records the Rate_Quote that was just used to
 * price each carrier-driven method id.
 *
 * Core's Abstract_Shipping_Method::calculate() returns a float price and
 * has no slot for the carrier-specific `rate_id` token that's needed to
 * later redeem the quote against `buy_label()`. Rather than change the
 * core return-type, the Carrier_Driven_Method records the selected quote
 * here; the order layer reads it back when it persists the customer's
 * shipping method choice.
 *
 * Scope is the current PHP process (request). The registry is a process
 * singleton — tests reset it via `Plugin::reset_for_testing()`.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Selected_Quote_Registry {
    /** @var array<string,Rate_Quote> */
    private array $by_method_id = array();

    public function record( string $method_id, Rate_Quote $quote ): void {
        if ( '' === $method_id ) {
            return;
        }
        $this->by_method_id[ $method_id ] = $quote;
    }

    public function get( string $method_id ): ?Rate_Quote {
        return $this->by_method_id[ $method_id ] ?? null;
    }

    /**
     * @return array<string,Rate_Quote>
     */
    public function all(): array {
        return $this->by_method_id;
    }

    public function reset(): void {
        $this->by_method_id = array();
    }
}
