<?php
/**
 * Customs declaration / commercial invoice for a cross-border shipment.
 *
 * Attach to a Rate_Request via `meta['customs']` and drivers that support
 * international labels will read it back. Carriers without customs
 * support simply ignore it — domestic shipments don't need one.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Customs_Declaration {
    public const CONTENTS_MERCHANDISE = 'merchandise';
    public const CONTENTS_GIFT        = 'gift';
    public const CONTENTS_DOCUMENTS   = 'documents';
    public const CONTENTS_SAMPLE      = 'sample';
    public const CONTENTS_RETURN      = 'returned_goods';

    public const NDR_RETURN  = 'return';
    public const NDR_ABANDON = 'abandon';
    public const NDR_REDIRECT = 'redirect_to_address';

    /**
     * @param Customs_Item[] $items
     */
    public function __construct(
        public array $items,
        public string $contents_type = self::CONTENTS_MERCHANDISE,
        public string $contents_explanation = '',
        public string $non_delivery_option = self::NDR_RETURN,
        public string $exporter_reference = '',
        public string $importer_reference = '',
        public string $eori_number = '',
        public string $ioss_number = '',
        public bool $certify = true,
        public string $certify_signer = ''
    ) {
        if ( array() === $items ) {
            throw new \InvalidArgumentException( 'Customs_Declaration: at least one Customs_Item is required.' );
        }
        foreach ( $items as $item ) {
            if ( ! $item instanceof Customs_Item ) {
                throw new \InvalidArgumentException( 'Customs_Declaration: items must be Customs_Item instances.' );
            }
        }
    }

    public function total_value_cents(): int {
        $total = 0;
        foreach ( $this->items as $item ) {
            $total += $item->value_cents * $item->quantity;
        }
        return $total;
    }

    public function currency(): string {
        return $this->items[0]->currency;
    }
}
