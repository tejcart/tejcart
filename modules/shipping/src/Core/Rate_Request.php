<?php
/**
 * Immutable rate-request value object.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rate_Request {
    /** @var array<string,string> */
    public array $origin;

    /** @var array<string,string> */
    public array $destination;

    /** @var Package[] */
    public array $packages;

    public string $currency;

    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,string> $origin       Keys: country, state, city, postcode, line1.
     * @param array<string,string> $destination  Same shape as $origin.
     * @param Package[]            $packages
     * @param array<string,mixed>  $meta         Free-form per-driver hints (residential flag, signature, insurance).
     */
    public function __construct(
        array $origin,
        array $destination,
        array $packages,
        string $currency = 'USD',
        array $meta = array()
    ) {
        if ( '' === ( $destination['country'] ?? '' ) ) {
            throw new \InvalidArgumentException( 'Rate_Request: destination.country is required.' );
        }
        if ( array() === $packages ) {
            throw new \InvalidArgumentException( 'Rate_Request: at least one package is required.' );
        }
        foreach ( $packages as $pkg ) {
            if ( ! $pkg instanceof Package ) {
                throw new \InvalidArgumentException( 'Rate_Request: packages must be Package instances.' );
            }
        }

        $this->origin      = $origin;
        $this->destination = $destination;
        $this->packages    = array_values( $packages );
        $this->currency    = strtoupper( $currency );
        $this->meta        = $meta;
    }

    /**
     * Stable cache key for this request — used by Rate_Cache. Driver id is
     * appended by the cache layer so identical requests served by different
     * drivers don't collide.
     */
    public function cache_signature(): string {
        $payload = array(
            'o' => $this->origin,
            'd' => $this->destination,
            'p' => array_map( static fn ( Package $p ): array => array(
                'w' => $p->weight_grams,
                'l' => $p->length_mm,
                'h' => $p->height_mm,
                'd' => $p->depth_mm,
                'v' => $p->declared_value_cents,
            ), $this->packages ),
            'c' => $this->currency,
            'm' => $this->meta,
        );
        return hash( 'sha256', wp_json_encode( $payload ) );
    }
}
