<?php
/**
 * Dispute entity.
 *
 * One row in `wp_tejcart_disputes` represents a single chargeback or
 * inquiry raised by a customer through their card-issuer or wallet
 * provider. The table is gateway-agnostic: PayPal CUSTOMER.DISPUTE.*
 * and Stripe charge.dispute.* events are normalised into the same
 * shape so the admin queue, email pipeline and reports can treat
 * disputes uniformly regardless of source.
 *
 * Idempotency: (gateway, external_id) is UNIQUE so webhook replays
 * upsert into the same row rather than creating duplicates.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dispute {
    public const STATUS_OPEN          = 'open';
    public const STATUS_UNDER_REVIEW  = 'under_review';
    public const STATUS_NEEDS_RESPONSE = 'needs_response';
    public const STATUS_WON           = 'won';
    public const STATUS_LOST          = 'lost';
    public const STATUS_ACCEPTED      = 'accepted';
    public const STATUS_CLOSED        = 'closed';

    /**
     * Object-cache group for the per-status COUNT(*) cache used by the
     * admin menu badge. The badge fires on every admin page render so
     * the unfiltered query would otherwise hit the disputes table on
     * every wp-admin pageview.
     */
    public const CACHE_GROUP = 'tejcart_disputes';

    public int $id = 0;
    public ?int $order_id = null;
    public string $gateway = '';
    public string $external_id = '';
    public string $transaction_ref = '';
    public string $status = self::STATUS_OPEN;
    public string $reason = '';
    public string $outcome = '';
    public float $amount = 0.0;
    public string $currency = '';
    public ?string $evidence_due = null;
    public string $opened_at = '';
    public string $updated_at = '';
    public ?string $resolved_at = null;
    public string $notes = '';
    public ?string $notes_updated_at = null;

    /**
     * Raw gateway payload — kept verbatim so investigators can audit
     * exactly what the gateway said without parsing it twice.
     *
     * @var array<string, mixed>
     */
    public array $payload = array();

    /**
     * @param array<string, mixed> $data
     */
    public function __construct( array $data = array() ) {
        if ( $data ) {
            $this->hydrate( $data );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function hydrate( array $data ): void {
        $this->id              = isset( $data['id'] ) ? (int) $data['id'] : $this->id;
        $this->order_id        = isset( $data['order_id'] ) && '' !== $data['order_id'] ? (int) $data['order_id'] : $this->order_id;
        $this->gateway         = isset( $data['gateway'] ) ? (string) $data['gateway'] : $this->gateway;
        $this->external_id     = isset( $data['external_id'] ) ? (string) $data['external_id'] : $this->external_id;
        $this->transaction_ref = isset( $data['transaction_ref'] ) ? (string) $data['transaction_ref'] : $this->transaction_ref;
        $this->status          = isset( $data['status'] ) ? (string) $data['status'] : $this->status;
        $this->reason          = isset( $data['reason'] ) ? (string) $data['reason'] : $this->reason;
        $this->outcome         = isset( $data['outcome'] ) ? (string) $data['outcome'] : $this->outcome;
        $this->amount          = isset( $data['amount'] ) ? (float) $data['amount'] : $this->amount;
        $this->currency        = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : $this->currency;
        $this->evidence_due    = isset( $data['evidence_due'] ) && '' !== $data['evidence_due'] ? (string) $data['evidence_due'] : $this->evidence_due;
        $this->opened_at       = isset( $data['opened_at'] ) ? (string) $data['opened_at'] : $this->opened_at;
        $this->updated_at      = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : $this->updated_at;
        $this->resolved_at     = isset( $data['resolved_at'] ) && '' !== $data['resolved_at'] ? (string) $data['resolved_at'] : $this->resolved_at;
        $this->notes           = isset( $data['notes'] ) ? (string) $data['notes'] : $this->notes;
        $this->notes_updated_at = isset( $data['notes_updated_at'] ) && '' !== $data['notes_updated_at']
            ? (string) $data['notes_updated_at']
            : $this->notes_updated_at;
        if ( isset( $data['payload'] ) ) {
            if ( is_array( $data['payload'] ) ) {
                $this->payload = $data['payload'];
            } elseif ( is_string( $data['payload'] ) && '' !== $data['payload'] ) {
                $decoded       = json_decode( $data['payload'], true );
                $this->payload = is_array( $decoded ) ? $decoded : array();
            }
        }
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_disputes';
    }

    /**
     * Insert or update by (gateway, external_id).
     *
     * Returns true on success — the post-condition is that the local
     * row reflects the latest gateway-supplied payload. We do not log
     * a "duplicate suppressed" path; webhooks are expected to replay
     * and the UNIQUE KEY is the contract that protects against
     * row-doubling.
     */
    public function save(): bool {
        global $wpdb;

        if ( '' === $this->gateway || '' === $this->external_id ) {
            return false;
        }

        if ( '' === $this->opened_at ) {
            $this->opened_at = current_time( 'mysql', true );
        }

        $table = self::table();

        $existing = self::find_by_external( $this->gateway, $this->external_id );

        $data = array(
            'order_id'         => $this->order_id,
            'gateway'          => $this->gateway,
            'external_id'      => $this->external_id,
            'transaction_ref'  => $this->transaction_ref,
            'status'           => $this->status,
            'reason'           => $this->reason,
            'outcome'          => $this->outcome,
            'amount'           => round( $this->amount, 4 ),
            'currency'         => $this->currency,
            'evidence_due'     => $this->evidence_due,
            'opened_at'        => $this->opened_at,
            'resolved_at'      => $this->resolved_at,
            'notes'            => $this->notes,
            'notes_updated_at' => $this->notes_updated_at,
            'payload'          => wp_json_encode( $this->payload ),
        );
        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $existing ) {
            $this->id = $existing->id;
            // Preserve the original opened_at so admins keep the real
            // dispute-opened timestamp even if the gateway replays the
            // event months later (e.g. on resolution).
            $data['opened_at'] = $existing->opened_at;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $res = $wpdb->update( $table, $data, array( 'id' => $existing->id ), $formats, array( '%d' ) );
            if ( false !== $res ) {
                self::flush_count_cache();
                return true;
            }
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $res = $wpdb->insert( $table, $data, $formats );
        if ( false === $res ) {
            return false;
        }
        $this->id = (int) $wpdb->insert_id;
        self::flush_count_cache();
        return true;
    }

    public static function find_by_external( string $gateway, string $external_id ): ?self {
        if ( '' === $gateway || '' === $external_id ) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND external_id = %s LIMIT 1", $gateway, $external_id ), ARRAY_A );
        return is_array( $row ) ? new self( $row ) : null;
    }

    public static function find( int $id ): ?self {
        if ( $id <= 0 ) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        return is_array( $row ) ? new self( $row ) : null;
    }

    /**
     * Page through disputes for the admin queue. Filters are an
     * associative array of column => value pairs; only `status` and
     * `gateway` are honoured today, but the shape is open to
     * extension.
     *
     * @param array<string, mixed> $filters
     * @return self[]
     */
    public static function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = self::table();

        [ $where, $params ] = self::build_where_clause( $filters );

        $sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY opened_at DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = max( 1, min( 200, $limit ) );
        $params[] = max( 0, $offset );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = new self( $row );
        }
        return $out;
    }

    /**
     * Build a parameterised WHERE clause from the filter map. Shared
     * between {@see query()}, {@see count()} and CSV export so the
     * filter surface stays consistent.
     *
     * Supported filters:
     *   - status     (string)        Exact match.
     *   - gateway    (string)        Exact match.
     *   - order_id   (int)           Exact match.
     *   - search     (string)        LIKE %search% across external_id,
     *                                transaction_ref, reason, notes.
     *   - opened_after  (string)     Y-m-d or Y-m-d H:i:s lower bound.
     *   - opened_before (string)     Y-m-d or Y-m-d H:i:s upper bound.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private static function build_where_clause( array $filters ): array {
        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['gateway'] ) ) {
            $where[]  = 'gateway = %s';
            $params[] = (string) $filters['gateway'];
        }
        if ( ! empty( $filters['order_id'] ) ) {
            $where[]  = 'order_id = %d';
            $params[] = (int) $filters['order_id'];
        }
        if ( ! empty( $filters['search'] ) ) {
            global $wpdb;
            $needle   = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where[]  = '(external_id LIKE %s OR transaction_ref LIKE %s OR reason LIKE %s OR notes LIKE %s)';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }
        if ( ! empty( $filters['opened_after'] ) ) {
            $where[]  = 'opened_at >= %s';
            $params[] = (string) $filters['opened_after'];
        }
        if ( ! empty( $filters['opened_before'] ) ) {
            $where[]  = 'opened_at <= %s';
            $params[] = (string) $filters['opened_before'];
        }

        return array( $where, $params );
    }

    /**
     * Aggregate count, optionally filtered. Used by the admin badge,
     * which fires on every wp-admin pageview — the result is cached in
     * the object cache for a short TTL and invalidated by {@see save()}.
     *
     * @param array<string, mixed> $filters
     */
    public static function count( array $filters = array() ): int {
        // Cache only the simple status/gateway-only count — those are the
        // hot paths that the admin queue tabs and badge re-fetch on every
        // wp-admin pageview. Search / date-range queries are merchant-
        // initiated and don't justify a per-permutation cache surface.
        $cacheable = self::is_cacheable_filter( $filters );
        $cache_key = $cacheable ? self::count_cache_key( $filters ) : '';
        if ( $cacheable && function_exists( 'wp_cache_get' ) ) {
            $hit = wp_cache_get( $cache_key, self::CACHE_GROUP );
            if ( false !== $hit ) {
                return (int) $hit;
            }
        }

        global $wpdb;
        $table = self::table();

        [ $where, $params ] = self::build_where_clause( $filters );

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

        if ( $params ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $sql = $wpdb->prepare( $sql, $params );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $count = (int) $wpdb->get_var( $sql );

        if ( $cacheable && function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $cache_key, $count, self::CACHE_GROUP, MINUTE_IN_SECONDS );
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function is_cacheable_filter( array $filters ): bool {
        foreach ( array_keys( $filters ) as $key ) {
            if ( ! in_array( $key, array( 'status', 'gateway' ), true ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Stable cache key for {@see count()}. Filter values are sanitised
     * via sanitize_key so the key stays portable across cache backends
     * that disallow non-ASCII characters.
     *
     * @param array<string, mixed> $filters
     */
    private static function count_cache_key( array $filters ): string {
        $status  = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
        $gateway = isset( $filters['gateway'] ) ? sanitize_key( (string) $filters['gateway'] ) : '';
        return 'count:' . $status . ':' . $gateway;
    }

    /**
     * Drop every cached count entry. Cheap because the cache surface is
     * small (one entry per status/gateway combination the admin queue
     * page actually surfaces).
     */
    public static function flush_count_cache(): void {
        if ( ! function_exists( 'wp_cache_delete' ) ) {
            return;
        }
        wp_cache_delete( 'count_actionable', self::CACHE_GROUP );
        wp_cache_delete( 'count_grouped:', self::CACHE_GROUP );
        $statuses = array(
            '',
            self::STATUS_OPEN,
            self::STATUS_UNDER_REVIEW,
            self::STATUS_NEEDS_RESPONSE,
            self::STATUS_WON,
            self::STATUS_LOST,
            self::STATUS_ACCEPTED,
            self::STATUS_CLOSED,
        );
        foreach ( $statuses as $status ) {
            wp_cache_delete( 'count:' . $status . ':', self::CACHE_GROUP );
        }
    }

    /**
     * Grouped COUNT by status in a single query. Returns an associative
     * map of `status => count` plus an `_all` key for the grand total.
     * Used by the admin status-tab strip to avoid N+1 COUNT queries.
     *
     * @param array<string, mixed> $filters Non-status filters (gateway, search, date range).
     * @return array<string, int>
     */
    public static function count_by_status( array $filters = array() ): array {
        $cacheable = self::is_cacheable_filter( $filters );
        $cache_key = $cacheable ? 'count_grouped:' . ( isset( $filters['gateway'] ) ? sanitize_key( (string) $filters['gateway'] ) : '' ) : '';
        if ( $cacheable && function_exists( 'wp_cache_get' ) ) {
            $hit = wp_cache_get( $cache_key, self::CACHE_GROUP );
            if ( is_array( $hit ) ) {
                return $hit;
            }
        }

        global $wpdb;
        $table = self::table();

        $no_status = $filters;
        unset( $no_status['status'] );

        [ $where, $params ] = self::build_where_clause( $no_status );

        $sql = "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE " . implode( ' AND ', $where ) . ' GROUP BY status';

        if ( $params ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sql = $wpdb->prepare( $sql, $params );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $out   = array( '_all' => 0 );
        foreach ( (array) $rows as $row ) {
            $s          = (string) $row['status'];
            $c          = (int) $row['cnt'];
            $out[ $s ]  = $c;
            $out['_all'] += $c;
        }

        if ( $cacheable && function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $cache_key, $out, self::CACHE_GROUP, MINUTE_IN_SECONDS );
        }
        return $out;
    }

    /**
     * Statuses that still need merchant attention. Used by the queue
     * default filter and the admin notification badge.
     *
     * @return string[]
     */
    public static function actionable_statuses(): array {
        return array( self::STATUS_OPEN, self::STATUS_UNDER_REVIEW, self::STATUS_NEEDS_RESPONSE );
    }

    /**
     * Single COUNT(*) for the admin badge. Replaces two separate
     * {@see count()} calls — high-volume merchants render wp-admin
     * many times an hour and the per-page DB hit was visible in
     * query logs.
     */
    public static function count_actionable(): int {
        $cache_key = 'count_actionable';
        if ( function_exists( 'wp_cache_get' ) ) {
            $hit = wp_cache_get( $cache_key, self::CACHE_GROUP );
            if ( false !== $hit ) {
                return (int) $hit;
            }
        }

        global $wpdb;
        $table    = self::table();
        $statuses = self::actionable_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $sql = "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $statuses ) );

        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $cache_key, $count, self::CACHE_GROUP, MINUTE_IN_SECONDS );
        }
        return $count;
    }

    public function is_actionable(): bool {
        return in_array( $this->status, self::actionable_statuses(), true );
    }

    /**
     * Statuses we treat as terminal — the dispute is resolved one way
     * or another and the evidence-due reminder cron must skip it.
     *
     * @return string[]
     */
    public static function terminal_statuses(): array {
        return array( self::STATUS_WON, self::STATUS_LOST, self::STATUS_ACCEPTED, self::STATUS_CLOSED );
    }

    public function is_terminal(): bool {
        return in_array( $this->status, self::terminal_statuses(), true );
    }

    /**
     * Append a timestamped internal note. Each entry is prefixed with
     * the UTC timestamp + actor display name so the audit trail is
     * legible without a separate notes table.
     *
     * Returns true when the note was persisted.
     */
    public function append_note( string $note, string $actor = '' ): bool {
        $note = trim( $note );
        if ( '' === $note ) {
            return false;
        }
        $now    = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        $actor  = '' !== $actor ? $actor : __( 'system', 'tejcart' );
        $entry  = sprintf( '[%s] %s: %s', $now, $actor, $note );
        $existing = trim( $this->notes );
        $this->notes            = '' !== $existing ? $existing . "\n" . $entry : $entry;
        $this->notes_updated_at = $now;
        return $this->save();
    }

    /**
     * Mark the dispute as resolved with a terminal status. Used by the
     * manual admin actions and the CLI command. Records the resolution
     * timestamp and writes an internal note describing the action.
     *
     * @param string $status One of {@see terminal_statuses()}.
     * @param string $note   Optional human-readable note appended to the
     *                       internal notes field.
     * @param string $actor  Display name (defaults to "system").
     */
    public function resolve( string $status, string $note = '', string $actor = '' ): bool {
        if ( ! in_array( $status, self::terminal_statuses(), true ) ) {
            return false;
        }
        $status_before     = $this->status;
        $now               = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        $this->status      = $status;
        $this->resolved_at = $now;
        if ( '' === $this->outcome ) {
            $this->outcome = strtoupper( $status );
        }
        if ( '' !== $note ) {
            $this->append_note( $note, $actor );
            Dispute_Event::record( $this->id, 'manual_resolve', $status_before, $status, array( 'note' => $note ), $actor ?: 'system' );
            return true;
        }
        $saved = $this->save();
        if ( $saved ) {
            Dispute_Event::record( $this->id, 'manual_resolve', $status_before, $status, array(), $actor ?: 'system' );
        }
        return $saved;
    }
}
