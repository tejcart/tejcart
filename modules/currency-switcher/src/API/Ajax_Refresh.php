<?php
/**
 * Admin AJAX handler for manual rate refresh + per-currency CRUD.
 *
 * @package TejCart\Currency_Switcher\API
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\API;

use TejCart\Currency_Switcher\Currency_Catalog;
use TejCart\Currency_Switcher\Currency_Config;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Three admin-only AJAX endpoints used by the settings UI:
 *
 *  - tejcart_csw_get_currency_settings   — fetch one row for the modal
 *  - tejcart_csw_save_currency_settings  — save one row from the modal
 *  - tejcart_csw_update_rates            — manual refresh from the API
 *
 * All three require a valid nonce + the `manage_options` capability
 * (filterable via `tejcart_csw_setting_cap` for sites that want shop
 * managers to edit currencies without full admin rights).
 */
final class Ajax_Refresh {
    public function register(): void {
        add_action( 'wp_ajax_tejcart_csw_get_currency_settings',  array( $this, 'get_settings' ) );
        add_action( 'wp_ajax_tejcart_csw_save_currency_settings', array( $this, 'save_settings' ) );
        add_action( 'wp_ajax_tejcart_csw_update_rates',           array( $this, 'update_rates' ) );
        add_action( 'wp_ajax_tejcart_csw_fetch_rate',             array( $this, 'fetch_rate' ) );
    }

    /**
     * Preview a single base→target rate without persisting it. Powers the
     * admin "auto-populate on currency select" interaction: when the
     * merchant picks a code from the Currencies dropdown, the JS calls
     * this and pre-fills the Rate input with whatever the upstream API
     * returns (so they don't have to click Save → refresh → re-edit).
     *
     * Returns `{rate: float, base: string, target: string}` on success.
     * The response intentionally does NOT touch `Currency_Repository` —
     * the merchant still has to hit "Save changes" for the row to land.
     */
    public function fetch_rate(): void {
        $this->guard();

        $code = $this->code_from_request();
        if ( null === $code ) {
            wp_send_json_error( array( 'message' => 'invalid_code' ), 400 );
        }
        if ( ! Currency_Catalog::is_supported( $code ) ) {
            wp_send_json_error( array( 'message' => 'unsupported_currency' ), 400 );
        }

        $repo = new Currency_Repository();
        $base = $repo->base_currency();
        if ( $code === $base ) {
            wp_send_json_success(
                array(
                    'rate'   => 1.0,
                    'base'   => $base,
                    'target' => $code,
                    'reason' => 'base_currency',
                )
            );
        }

        $client = new Exchange_Rate_Client();
        $result = $client->fetch( $base, $code );

        if ( ! ( $result['success'] ?? false ) || ! isset( $result['rate'] ) ) {
            wp_send_json_error(
                array(
                    'message'     => 'fetch_failed',
                    'error'       => $result['error'] ?? 'unknown',
                    'http_status' => $result['http_status'] ?? null,
                ),
                502
            );
        }

        wp_send_json_success(
            array(
                'rate'   => (float) $result['rate'],
                'base'   => $base,
                'target' => $code,
            )
        );
    }

    public function get_settings(): void {
        $this->guard();
        $code = $this->code_from_request();
        if ( null === $code ) {
            wp_send_json_error( array( 'message' => 'invalid_code' ), 400 );
        }

        $cfg = ( new Currency_Repository() )->get( $code );
        if ( null === $cfg ) {
            wp_send_json_error( array( 'message' => 'not_configured' ), 404 );
        }

        wp_send_json_success( $cfg->to_array() );
    }

    public function save_settings(): void {
        $this->guard();
        $code = $this->code_from_request();
        if ( null === $code ) {
            wp_send_json_error( array( 'message' => 'invalid_code' ), 400 );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in $this->guard() above
        $row = array(
            'code'         => $code,
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to numeric type
            'rate'         => isset( $_POST['rate'] ) ? (float) wp_unslash( $_POST['rate'] ) : 1.0,
            'rate_type'    => isset( $_POST['rate_type'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['rate_type'] ) )
                : Options::RATE_TYPE_AUTO,
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to numeric type
            'fee'          => isset( $_POST['fee'] ) ? (float) wp_unslash( $_POST['fee'] ) : 0.0,
            'fee_type'     => isset( $_POST['fee_type'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['fee_type'] ) )
                : Options::FEE_FIXED,
            'currency_pos' => isset( $_POST['currency_pos'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['currency_pos'] ) )
                : Options::POS_LEFT,
            'thousand_sep' => isset( $_POST['thousand_sep'] )
                ? substr( sanitize_text_field( wp_unslash( (string) $_POST['thousand_sep'] ) ), 0, 1 )
                : ',',
            'decimal_sep'  => isset( $_POST['decimal_sep'] )
                ? substr( sanitize_text_field( wp_unslash( (string) $_POST['decimal_sep'] ) ), 0, 1 )
                : '.',
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to numeric type
            'num_decimals' => isset( $_POST['num_decimals'] ) ? (int) wp_unslash( $_POST['num_decimals'] ) : 2,
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $cfg  = Currency_Config::from_array( $row );
        $repo = new Currency_Repository();
        $repo->save( $cfg );

        wp_send_json_success( $cfg->to_array() );
    }

    public function update_rates(): void {
        $this->guard();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in $this->guard() above; each value sanitized in the loop below
        $raw    = isset( $_POST['currency'] ) ? wp_unslash( $_POST['currency'] ) : array();
        $codes  = array();
        if ( is_array( $raw ) ) {
            foreach ( $raw as $value ) {
                $codes[] = sanitize_text_field( (string) $value );
            }
        } else {
            $codes[] = sanitize_text_field( (string) $raw );
        }
        $codes = array_filter( array_map( 'strtoupper', $codes ) );

        if ( empty( $codes ) ) {
            wp_send_json_error( array( 'message' => 'no_currencies' ), 400 );
        }

        $results = ( new Cron() )->refresh( $codes );
        wp_send_json_success(
            array(
                'results'      => $results,
                'last_updated' => (int) get_option( Options::LAST_RATE_UPDATE, 0 ),
            )
        );
    }

    /**
     * Capability + nonce gate. Aborts the request when violated.
     */
    private function guard(): void {
        $cap = apply_filters( 'tejcart_csw_setting_cap', 'manage_options' );
        if ( ! current_user_can( (string) $cap ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        }
        $nonce = isset( $_REQUEST['tejcart_csw_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_REQUEST['tejcart_csw_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, Options::NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
        }
    }

    /**
     * Extract an uppercase 3-letter ISO code from the request or
     * return null if absent/malformed.
     */
    private function code_from_request(): ?string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by guard() at the start of the handler
        if ( empty( $_REQUEST['code'] ) ) {
            return null;
        }
        $code = strtoupper(
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by guard() at the start of the handler
            sanitize_text_field( wp_unslash( (string) $_REQUEST['code'] ) )
        );
        return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : null;
    }
}
