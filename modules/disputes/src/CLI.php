<?php
/**
 * WP-CLI command surface for the disputes queue.
 *
 * Registers `wp tejcart-disputes list|get|resolve|note` so ops can
 * triage disputes from the shell — useful for SRE runbooks where the
 * admin UI is unavailable, and for batch operations like resolving all
 * pending PayPal disputes when a gateway-side outage is reconciled.
 *
 * Only loaded when WP-CLI is the active context (see Disputes::init()).
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CLI {
    public static function register(): void {
        if ( ! class_exists( '\\WP_CLI' ) ) {
            return;
        }
        \WP_CLI::add_command( 'tejcart disputes', __CLASS__ );
    }

    /**
     * List disputes, optionally filtered.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by dispute status (open, needs_response, under_review,
     * won, lost, accepted, closed).
     *
     * [--gateway=<gateway>]
     * : Filter by gateway slug (paypal, stripe, authorize_net).
     *
     * [--order_id=<id>]
     * : Filter by TejCart order ID.
     *
     * [--search=<term>]
     * : LIKE-search across external_id, transaction_ref, reason, notes.
     *
     * [--per_page=<count>]
     * : Number of rows to return (default: 50, max: 500).
     *
     * [--page=<page>]
     * : Page offset (default: 1).
     *
     * [--format=<format>]
     * : table | json | csv | yaml | count. Default: table.
     *
     * @when after_wp_load
     * @param string[]              $args
     * @param array<string, string> $assoc_args
     */
    public function list( array $args, array $assoc_args ): void {
        $filters  = $this->filters_from_args( $assoc_args );
        $per_page = max( 1, min( 500, (int) ( $assoc_args['per_page'] ?? 50 ) ) );
        $page     = max( 1, (int) ( $assoc_args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $disputes = Dispute::query( $filters, $per_page, $offset );

        $format = (string) ( $assoc_args['format'] ?? 'table' );
        if ( 'count' === $format ) {
            \WP_CLI::log( (string) Dispute::count( $filters ) );
            return;
        }

        // gateway/external_id originate from gateway webhook payloads;
        // neutralise spreadsheet formula triggers when emitting CSV so an
        // operator opening the export cannot be hit by formula injection.
        $is_csv = ( 'csv' === $format );

        $items = array();
        foreach ( $disputes as $d ) {
            $items[] = array(
                'ID'         => $d->id,
                'Gateway'    => $is_csv ? self::sanitize_csv( (string) $d->gateway ) : $d->gateway,
                'External'   => $is_csv ? self::sanitize_csv( (string) $d->external_id ) : $d->external_id,
                'Order'      => (int) $d->order_id,
                'Status'     => $d->status,
                'Amount'     => sprintf( '%.2f %s', $d->amount, $d->currency ),
                'Opened'     => $d->opened_at,
                'EvidenceBy' => $d->evidence_due ?: '',
            );
        }

        \WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'Gateway', 'External', 'Order', 'Status', 'Amount', 'Opened', 'EvidenceBy' ) );
    }

    /**
     * Show a single dispute by internal ID or by gateway+external_id.
     *
     * ## OPTIONS
     *
     * [<id>]
     * : Internal dispute ID.
     *
     * [--gateway=<gateway>]
     * : When passing --external, the gateway to look up under.
     *
     * [--external=<external_id>]
     * : Gateway-supplied dispute ID. Requires --gateway.
     *
     * [--format=<format>]
     * : json | yaml. Default: json.
     *
     * @when after_wp_load
     * @param string[]              $args
     * @param array<string, string> $assoc_args
     */
    public function get( array $args, array $assoc_args ): void {
        $dispute = $this->resolve_dispute( $args, $assoc_args );

        $format = (string) ( $assoc_args['format'] ?? 'json' );
        $data   = array(
            'id'              => $dispute->id,
            'order_id'        => $dispute->order_id,
            'gateway'         => $dispute->gateway,
            'external_id'     => $dispute->external_id,
            'transaction_ref' => $dispute->transaction_ref,
            'status'          => $dispute->status,
            'reason'          => $dispute->reason,
            'outcome'         => $dispute->outcome,
            'amount'          => $dispute->amount,
            'currency'        => $dispute->currency,
            'evidence_due'    => $dispute->evidence_due,
            'opened_at'       => $dispute->opened_at,
            'resolved_at'     => $dispute->resolved_at,
            'notes'           => $dispute->notes,
        );

        if ( 'yaml' === $format && function_exists( 'yaml_emit' ) ) {
            \WP_CLI::log( yaml_emit( $data ) );
            return;
        }
        \WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Resolve a dispute with a terminal status.
     *
     * ## OPTIONS
     *
     * <id>
     * : Internal dispute ID.
     *
     * --status=<status>
     * : One of won, lost, accepted, closed.
     *
     * [--note=<note>]
     * : Optional note appended to the internal notes timeline.
     *
     * @when after_wp_load
     * @param string[]              $args
     * @param array<string, string> $assoc_args
     */
    public function resolve( array $args, array $assoc_args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Dispute ID is required.' );
        }
        $dispute = Dispute::find( (int) $args[0] );
        if ( ! $dispute ) {
            \WP_CLI::error( sprintf( 'Dispute %d not found.', (int) $args[0] ) );
        }
        $status = (string) ( $assoc_args['status'] ?? '' );
        if ( ! in_array( $status, Dispute::terminal_statuses(), true ) ) {
            \WP_CLI::error( 'Status must be one of: ' . implode( ', ', Dispute::terminal_statuses() ) );
        }
        $note  = (string) ( $assoc_args['note'] ?? '' );
        $actor = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' )
            ? (string) ( posix_getpwuid( posix_geteuid() )['name'] ?? 'cli' )
            : 'cli';
        $dispute->resolve( $status, $note, $actor );
        do_action( 'tejcart_dispute_manual_resolve', $dispute, $status, $actor );

        \WP_CLI::success( sprintf( 'Dispute %d resolved as %s.', $dispute->id, $status ) );
    }

    /**
     * Append an internal note to a dispute.
     *
     * ## OPTIONS
     *
     * <id>
     * : Internal dispute ID.
     *
     * <note>
     * : Note text.
     *
     * @when after_wp_load
     * @param string[] $args
     */
    public function note( array $args ): void {
        if ( count( $args ) < 2 ) {
            \WP_CLI::error( 'Usage: wp tejcart disputes note <id> "<note>"' );
        }
        $dispute = Dispute::find( (int) $args[0] );
        if ( ! $dispute ) {
            \WP_CLI::error( sprintf( 'Dispute %d not found.', (int) $args[0] ) );
        }
        $dispute->append_note( (string) $args[1], 'cli' );
        \WP_CLI::success( sprintf( 'Note added to dispute %d.', $dispute->id ) );
    }

    /**
     * @param array<string, string> $assoc_args
     * @return array<string, mixed>
     */
    private function filters_from_args( array $assoc_args ): array {
        $filters = array();
        foreach ( array( 'status', 'gateway', 'search' ) as $key ) {
            if ( ! empty( $assoc_args[ $key ] ) ) {
                $filters[ $key ] = (string) $assoc_args[ $key ];
            }
        }
        if ( ! empty( $assoc_args['order_id'] ) ) {
            $filters['order_id'] = (int) $assoc_args['order_id'];
        }
        return $filters;
    }

    /**
     * @param string[]              $args
     * @param array<string, string> $assoc_args
     */
    private function resolve_dispute( array $args, array $assoc_args ): Dispute {
        if ( ! empty( $args[0] ) ) {
            $d = Dispute::find( (int) $args[0] );
            if ( ! $d ) {
                \WP_CLI::error( sprintf( 'Dispute %d not found.', (int) $args[0] ) );
                exit( 1 );
            }
            return $d;
        }
        if ( ! empty( $assoc_args['gateway'] ) && ! empty( $assoc_args['external'] ) ) {
            $d = Dispute::find_by_external( (string) $assoc_args['gateway'], (string) $assoc_args['external'] );
            if ( ! $d ) {
                \WP_CLI::error( 'Dispute not found for the supplied gateway+external_id.' );
                exit( 1 );
            }
            return $d;
        }
        \WP_CLI::error( 'Provide an <id> or both --gateway and --external.' );
        exit( 1 );
    }

    /**
     * Prevent CSV formula injection. Spreadsheet applications interpret
     * cells starting with =, +, -, @, tab, or CR as formulas. Prefixing
     * with a single-quote neutralises the trigger without altering the
     * visual display in most spreadsheet software.
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
}
