<?php
/**
 * Result of a carrier-side address verification call.
 *
 * Carriers normalise the input (e.g. add ZIP+4, capitalize state codes,
 * spell out abbreviations). Use `is_deliverable` to gate checkout
 * (false = bad address) and `corrected` for any prompt-the-customer flow.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Address_Validation_Result {
    /**
     * @param array<string,string>   $corrected   Carrier-normalised address (same keys as input).
     * @param array<int,string>      $messages    Human-readable warnings/notes.
     */
    public function __construct(
        public bool $is_deliverable,
        public bool $is_residential,
        public array $corrected,
        public array $messages = array()
    ) {}
}
