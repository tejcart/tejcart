<?php
/**
 * Abandoned Cart Recovery Email.
 *
 * @package TejCart\Email\Emails
 */

declare( strict_types=1 );

namespace TejCart\Email\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Email\Abstract_Email;
use TejCart\Money\Currency;

/**
 * Sent to customers who left items in their cart without completing checkout.
 *
 * Triggered by the Tier-2 Abandoned_Cart cron; each row in the
 * abandoned-carts table is eligible for up to three sequenced emails
 * (first / second / final). The preheader adapts to the sequence step
 * while subject and heading remain admin-overrideable via the standard
 * `tejcart_email_abandoned_cart` option.
 */
class Abandoned_Cart_Email extends Abstract_Email {

    /**
     * @var string
     */
    protected $recovery_url = '';

    /**
     * @var string Sequence step: 'first', 'second', or 'final'.
     */
    protected $template_key = 'first';

    /**
     * @var array<int, array{product_id: int, quantity: int, data: array<string, mixed>}>
     */
    protected $cart_items = array();

    /**
     * @var float
     */
    protected $cart_total = 0.0;

    /**
     * @var string ISO-4217 currency code.
     */
    protected $currency = 'USD';

    public function __construct() {
        $this->id            = 'abandoned_cart';
        $this->title         = 'Abandoned Cart Recovery';
        $this->description   = 'Sent to customers who left items in their cart without completing checkout.';
        $this->subject       = 'You left items in your cart at {site_title}';
        $this->heading       = 'Your cart is waiting';
        $this->preheader     = 'Complete your purchase — your items are still reserved.';
        $this->template_html = 'emails/abandoned-cart.php';

        parent::__construct();
    }

    /**
     * @param array<string, mixed> $row          Abandoned cart row from the database.
     * @param string               $template_key Sequence step: 'first', 'second', or 'final'.
     * @return bool
     */
    public function trigger( $row, $template_key = 'first' ) {
        if ( ! is_array( $row ) || empty( $row['email'] ) || empty( $row['token'] ) ) {
            return false;
        }

        $this->object       = $row;
        $this->template_key = (string) $template_key;
        $this->recipient    = sanitize_email( $row['email'] );
        $this->recovery_url = add_query_arg( 'tejcart_recover', $row['token'], home_url( '/' ) );
        $this->cart_items   = json_decode( $row['cart_contents'] ?? '[]', true ) ?: array();
        $this->currency     = (string) ( $row['currency'] ?? 'USD' );
        // cart_total is stored as integer minor units (BIGINT); convert to
        // major-unit float so tejcart_price() and the template render correctly.
        $this->cart_total   = Currency::from_minor_units( (int) ( $row['cart_total'] ?? 0 ), $this->currency );

        if ( 'second' === $template_key ) {
            $this->preheader = __( 'Your cart is still waiting for you.', 'tejcart' );
        } elseif ( 'final' === $template_key ) {
            $this->preheader = __( 'Last chance to complete your purchase.', 'tejcart' );
        }

        return $this->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function get_template_args() {
        return array(
            'email_heading' => $this->get_heading(),
            'email'         => $this,
            'recovery_url'  => $this->recovery_url,
            'template_key'  => $this->template_key,
            'cart_items'    => $this->cart_items,
            'cart_total'    => $this->cart_total,
            'currency'      => $this->currency,
            'row'           => $this->object,
        );
    }
}
