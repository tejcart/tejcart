<?php
/**
 * Normalised tracking status returned by any provider.
 *
 * Carrier APIs all model events differently — UPS uses
 * `package.activity[].status.code`, FedEx uses
 * `latestStatusDetail.code`, EasyPost uses
 * `tracking_details[].status` — but we only care about a small set of
 * canonical states. Each provider is responsible for translating its
 * vendor format into one of these.
 *
 * Immutable. `events` is an ordered (newest first) list of normalised
 * checkpoints. `raw` is kept opaque for audit / replay / future drivers.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Provider_Status {
    /** @var array<int, array<string, mixed>> */
    public readonly array $events;

    /**
     * @param string                                $status       One of Shipment_Status::*
     * @param string|null                           $delivered_at MySQL datetime if delivered.
     * @param string|null                           $shipped_at   MySQL datetime when first scanned.
     * @param string|null                           $eta          MySQL datetime estimated delivery.
     * @param array<int, array<string, mixed>>      $events       Normalised events (newest first).
     * @param array<string, mixed>                  $raw          Original provider payload.
     * @param string|null                           $event_id     Provider-assigned event identifier
     *                                                            (e.g. EasyPost tracker id or webhook
     *                                                            envelope id). Preferred over body
     *                                                            hashing for webhook idempotency
     *                                                            because payloads can re-serialise
     *                                                            with different whitespace.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $delivered_at = null,
        public readonly ?string $shipped_at = null,
        public readonly ?string $eta = null,
        array $events = array(),
        public readonly array $raw = array(),
        public readonly ?string $event_id = null,
    ) {
        $this->events = $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return array(
            'status'       => $this->status,
            'delivered_at' => $this->delivered_at,
            'shipped_at'   => $this->shipped_at,
            'eta'          => $this->eta,
            'events'       => $this->events,
            'raw'          => $this->raw,
            'event_id'     => $this->event_id,
        );
    }
}
