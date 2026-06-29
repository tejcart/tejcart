<?php
/**
 * Persisted shipment row.
 *
 * Mirrors the `wp_tejcart_shipments` table 1:1 — one row per label
 * purchased from a carrier. Status enum follows the carrier-agnostic
 * lifecycle: `purchased` → `in_transit` → `delivered` (or `voided`).
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Shipment {
    public const STATUS_PURCHASED   = 'purchased';
    public const STATUS_IN_TRANSIT  = 'in_transit';
    public const STATUS_DELIVERED   = 'delivered';
    public const STATUS_VOIDED      = 'voided';
    public const STATUS_EXCEPTION   = 'exception';

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public int $id,
        public int $order_id,
        public string $carrier_id,
        public string $service_code,
        public string $rate_id,
        public string $tracking_number,
        public string $label_url,
        public string $label_format,
        public int $cost_cents,
        public string $currency,
        public string $status,
        public string $idempotency_key,
        public array $meta,
        public string $created_at,
        public string $updated_at,
        public ?string $voided_at = null
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function from_row( array $row ): self {
        $meta = array();
        if ( isset( $row['meta'] ) && is_string( $row['meta'] ) && '' !== $row['meta'] ) {
            $decoded = json_decode( $row['meta'], true );
            if ( is_array( $decoded ) ) {
                $meta = $decoded;
            }
        }
        return new self(
            id: (int) ( $row['id'] ?? 0 ),
            order_id: (int) ( $row['order_id'] ?? 0 ),
            carrier_id: (string) ( $row['carrier_id'] ?? '' ),
            service_code: (string) ( $row['service_code'] ?? '' ),
            rate_id: (string) ( $row['rate_id'] ?? '' ),
            tracking_number: (string) ( $row['tracking_number'] ?? '' ),
            label_url: (string) ( $row['label_url'] ?? '' ),
            label_format: (string) ( $row['label_format'] ?? 'PDF' ),
            cost_cents: (int) ( $row['cost_cents'] ?? 0 ),
            currency: (string) ( $row['currency'] ?? 'USD' ),
            status: (string) ( $row['status'] ?? self::STATUS_PURCHASED ),
            idempotency_key: (string) ( $row['idempotency_key'] ?? '' ),
            meta: $meta,
            created_at: (string) ( $row['created_at'] ?? '' ),
            updated_at: (string) ( $row['updated_at'] ?? '' ),
            voided_at: isset( $row['voided_at'] ) && '' !== $row['voided_at'] ? (string) $row['voided_at'] : null
        );
    }
}
