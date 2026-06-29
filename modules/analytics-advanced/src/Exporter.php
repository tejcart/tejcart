<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Exporter {

    public static function export( string $report, string $format = 'csv' ): void {
        switch ( $report ) {
            case 'cohorts':
                $data    = self::cohorts_data();
                $headers = array( 'Cohort Month', 'Customers', 'First Order Revenue', 'Total Revenue', 'Avg LTV', 'M0 %', 'M1 %', 'M2 %', 'M3 %', 'M4 %', 'M5 %', 'M6 %', 'M7 %', 'M8 %', 'M9 %', 'M10 %', 'M11 %', 'M12 %' );
                break;

            case 'ltv':
                $data    = self::ltv_data();
                $headers = array( 'Channel', 'Customers', 'Total Revenue', 'Avg LTV', 'Avg Orders' );
                break;

            case 'segments':
                $data    = self::segments_data();
                $headers = array( 'Segment', 'Customers', 'Revenue', 'Orders', 'Avg LTV' );
                break;

            case 'trends':
                $data    = self::trends_data();
                $headers = array( 'Month', 'Revenue', 'Orders', 'AOV', 'Customers' );
                break;

            default:
                return;
        }

        if ( 'html' === $format || 'pdf' === $format ) {
            self::output_html( $report, $headers, $data );
        } else {
            self::output_csv( $report, $headers, $data );
        }
    }

    /**
     * @param array<int, string>              $headers
     * @param array<int, array<int, string>>  $rows
     */
    private static function output_csv( string $filename, array $headers, array $rows ): void {
        $filename = sanitize_file_name( $filename );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="tejcart-' . $filename . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        if ( false === $output ) {
            return;
        }

        fputcsv( $output, $headers );
        foreach ( $rows as $row ) {
            $row = array_map( static fn( $cell ): string => self::sanitize_csv( (string) $cell ), $row );
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Prevent CSV formula injection. Spreadsheet applications interpret
     * cells starting with =, +, -, @, tab, or CR as formulas. Exported
     * cells include customer-controlled values (email, acquisition
     * channel), so prefix any such value with a single-quote to neutralise
     * the trigger without altering its visual display.
     */
    private static function sanitize_csv( string $value ): string {
        if ( '' === $value ) {
            return $value;
        }
        if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param array<int, string>              $headers
     * @param array<int, array<int, string>>  $rows
     */
    private static function output_html( string $filename, array $headers, array $rows ): void {
        $filename = sanitize_file_name( $filename );
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="tejcart-' . $filename . '-' . gmdate( 'Y-m-d' ) . '.html"' );

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>TejCart ' . esc_html( ucfirst( $filename ) ) . ' Report</title>';
        echo '<style>body{font-family:sans-serif;margin:2em}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:right}th{background:#f5f5f5;text-align:left}td:first-child{text-align:left}h1{font-size:1.5em}</style>';
        echo '</head><body>';
        echo '<h1>TejCart ' . esc_html( ucfirst( $filename ) ) . ' Report — ' . esc_html( gmdate( 'F j, Y' ) ) . '</h1>';
        echo '<table><thead><tr>';

        foreach ( $headers as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $row as $cell ) {
                echo '<td>' . esc_html( (string) $cell ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function cohorts_data(): array {
        $cohort = new Cohort_Report();
        $matrix = $cohort->get_matrix( self::currency() );
        $rows   = array();

        foreach ( $matrix as $entry ) {
            $row = array(
                $entry['cohort_month'],
                (string) $entry['customer_count'],
                self::fmt( $entry['first_order_revenue'] ),
                self::fmt( $entry['total_revenue'] ),
                self::fmt( $entry['avg_ltv'] ),
            );

            for ( $m = 0; $m <= 12; $m++ ) {
                $row[] = isset( $entry['retention'][ $m ] )
                    ? $entry['retention'][ $m ]['rate'] . '%'
                    : '—';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function ltv_data(): array {
        $calc    = new LTV_Calculator();
        $channels = $calc->get_by_channel( self::currency() );
        $rows    = array();

        foreach ( $channels as $channel => $data ) {
            $rows[] = array(
                ucfirst( $channel ),
                (string) $data['customer_count'],
                self::fmt( $data['total_revenue'] ),
                self::fmt( $data['avg_ltv'] ),
                (string) $data['avg_orders'],
            );
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function segments_data(): array {
        $segment = new Segment_Report();
        $data    = $segment->get_breakdown( self::currency() );
        $labels  = Segment_Report::labels();
        $rows    = array();

        foreach ( $data as $key => $seg ) {
            $rows[] = array(
                $labels[ $key ] ?? $key,
                (string) $seg['customer_count'],
                self::fmt( $seg['revenue'] ),
                (string) $seg['order_count'],
                self::fmt( $seg['avg_ltv'] ),
            );
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function trends_data(): array {
        $trend   = new Trend_Report();
        $from    = gmdate( 'Y-m-01', strtotime( '-12 months' ) );
        $to      = gmdate( 'Y-m-d' );
        $monthly = $trend->get_monthly_trends( $from, $to, self::currency() );
        $rows    = array();

        foreach ( $monthly as $row ) {
            $rows[] = array(
                $row['month'],
                self::fmt( $row['revenue'] ),
                (string) $row['order_count'],
                self::fmt( $row['aov'] ),
                (string) $row['customer_count'],
            );
        }

        return $rows;
    }

    private static function currency(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['currency'] ) ) {
            $code = strtoupper( sanitize_text_field( wp_unslash( $_GET['currency'] ) ) );
            if ( preg_match( '/^[A-Z]{3}$/', $code ) ) {
                return $code;
            }
        }

        return (string) ( function_exists( 'get_option' )
            ? get_option( 'tejcart_currency', 'USD' )
            : 'USD' );
    }

    private static function fmt( int $minor ): string {
        $cur   = self::currency();
        $major = Currency::from_minor_units( $minor, $cur );
        return number_format( $major, Currency::decimals( $cur ), '.', '' );
    }
}
