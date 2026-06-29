<?php
/**
 * Tax-rates CSV import / export.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Tax\Tax_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Streaming CSV exporter + uploader for the Tax Rates admin page.
 *
 * The column layout follows the conventional Tax Rates importer schema,
 * plus a `tax_class` column so TejCart's class-aware rates round-trip
 * cleanly.
 */
class Tax_Rates_IO {
    /**
     * Column order used for both export and import.
     */
    private const COLUMNS = array(
        'country',
        'state',
        'postcodes',
        'cities',
        'rate',
        'name',
        'priority',
        'compound',
        'shipping',
        'tax_class',
    );

    /**
     * Register admin_init handlers for import / export.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'maybe_handle' ) );
    }

    /**
     * Dispatch export / import based on posted action.
     *
     * @return void
     */
    public function maybe_handle(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $is_tax_page = ( 'tejcart-tax-rates' === $page ) || ( 'tejcart-settings' === $page && 'tax' === $tab );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $is_tax_page && isset( $_GET['action'] ) && 'export_csv' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'tejcart_export_tax_rates' ) ) {
                return;
            }
            $this->stream_export();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! empty( $_POST['tejcart_tax_rate_action'] ) && 'import_csv' === sanitize_key( wp_unslash( $_POST['tejcart_tax_rate_action'] ) ) ) {
            $nonce = isset( $_POST['tejcart_tax_rate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_tax_rate_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'tejcart_import_tax_rates' ) ) {
                return;
            }
            $this->handle_import();
        }
    }

    /**
     * Stream the configured tax rates as a CSV download.
     *
     * @return void
     */
    private function stream_export(): void {
        $manager = new Tax_Manager();
        $rates   = $manager->get_rates();

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=tejcart-tax-rates-' . gmdate( 'Ymd-His' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, self::COLUMNS );

        foreach ( $rates as $rate ) {
            $row = array();
            foreach ( self::COLUMNS as $col ) {
                $row[] = (string) ( $rate[ $col ] ?? '' );
            }
            fputcsv( $out, tejcart_csv_sanitize_row( $row ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Handle an uploaded CSV.
     *
     * Two modes:
     *  - `append`      — add rows on top of existing rates.
     *  - `replace_all` — wipe existing rates and replace with the file contents.
     *
     * @return void
     */
    private function handle_import(): void {
        // Nonce verified upstream by maybe_dispatch() before this private helper runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $mode = isset( $_POST['import_mode'] ) ? sanitize_key( wp_unslash( $_POST['import_mode'] ) ) : 'append';

        // Nonce + capability already checked by maybe_dispatch() before this runs.
        // tmp_name is server-controlled; is_uploaded_file() guarantees it points
        // at a legitimate upload tempfile.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $tmp_name = isset( $_FILES['tax_rates_csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['tax_rates_csv']['tmp_name'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            $this->flash_notice( __( 'No CSV file was uploaded.', 'tejcart' ), 'error' );
            $this->redirect_back();
        }

        // Defense in depth: cap size and confirm the upload is a CSV/text file
        // before parsing. The importer is admin-gated + nonced, but a stray
        // binary or oversized upload should fail fast with a clear message.
        // (Column values are sanitized again inside Tax_Manager::add_rate().)
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $file_size = isset( $_FILES['tax_rates_csv']['size'] ) ? (int) $_FILES['tax_rates_csv']['size'] : 0;
        $file_name = isset( $_FILES['tax_rates_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['tax_rates_csv']['name'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( $file_size > 2 * MB_IN_BYTES ) {
            $this->flash_notice( __( 'The CSV file is too large (maximum 2 MB).', 'tejcart' ), 'error' );
            $this->redirect_back();
        }
        $filetype = wp_check_filetype( $file_name, array( 'csv' => 'text/csv', 'txt' => 'text/plain' ) );
        if ( 'csv' !== $filetype['ext'] && 'txt' !== $filetype['ext'] ) {
            $this->flash_notice( __( 'Please upload a .csv file.', 'tejcart' ), 'error' );
            $this->redirect_back();
        }

        // CSV import requires a streaming file handle (fgetcsv); WP_Filesystem reads whole files only.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $tmp_name, 'r' );
        if ( ! $handle ) {
            $this->flash_notice( __( 'Could not open the uploaded file.', 'tejcart' ), 'error' );
            $this->redirect_back();
        }

        $header = fgetcsv( $handle );
        if ( ! is_array( $header ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $handle );
            $this->flash_notice( __( 'Empty or invalid CSV.', 'tejcart' ), 'error' );
            $this->redirect_back();
        }

        $header = array_map( static fn( $h ) => sanitize_key( (string) $h ), $header );
        $map    = array_flip( $header );

        $manager = new Tax_Manager();
        if ( 'replace_all' === $mode ) {
            foreach ( $manager->get_rates() as $existing ) {
                $manager->delete_rate( (int) ( $existing['id'] ?? 0 ) );
            }

            $manager = new Tax_Manager();
        }

        $imported = 0;
        $errors   = array();
        $row_no   = 1;

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_no++;
            if ( empty( array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) ) {
                continue;
            }

            $data    = array();
            foreach ( self::COLUMNS as $col ) {
                $idx          = $map[ $col ] ?? null;
                $data[ $col ] = ( null !== $idx && isset( $row[ $idx ] ) ) ? (string) $row[ $idx ] : '';
            }

            if ( '' === $data['country'] ) {
                $errors[] = sprintf(
                    /* translators: %d: CSV row number */
                    __( 'Row %d: missing required "country" column.', 'tejcart' ),
                    $row_no
                );
                continue;
            }

            $manager->add_rate( array(
                'country'   => $data['country'],
                'state'     => $data['state'],
                'rate'      => (float) $data['rate'],
                'name'      => '' !== $data['name'] ? $data['name'] : __( 'Tax', 'tejcart' ),
                'priority'  => (int) ( '' !== $data['priority'] ? $data['priority'] : 1 ),
                'compound'  => ( 'yes' === strtolower( $data['compound'] ) || '1' === $data['compound'] ) ? 'yes' : 'no',
                'shipping'  => ( 'no' === strtolower( $data['shipping'] ) || '0' === $data['shipping'] ) ? 'no' : 'yes',
                'tax_class' => $data['tax_class'],
            ) );

            $imported++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        $msg = sprintf(
            /* translators: %d: number of imported rates */
            __( 'Imported %d tax rate(s).', 'tejcart' ),
            $imported
        );
        if ( ! empty( $errors ) ) {
            $msg .= ' ' . implode( ' ', array_slice( $errors, 0, 10 ) );
        }

        $this->flash_notice( $msg, empty( $errors ) ? 'success' : 'warning' );
        $this->redirect_back();
    }

    /**
     * Persist a transient notice and redirect back to the rates page.
     *
     * @param string $message Notice text.
     * @param string $type    notice-<type> CSS token.
     * @return void
     */
    private function flash_notice( string $message, string $type = 'success' ): void {
        set_transient( 'tejcart_tax_io_notice', array( 'type' => $type, 'message' => $message ), 30 );
    }

    /**
     * Redirect to the tax rates admin page and stop execution.
     *
     * @return void
     */
    private function redirect_back(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates' ) );
        exit;
    }
}
