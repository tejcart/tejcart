<?php
/**
 * Read-only REST API for the disputes queue.
 *
 * Exposes the unified disputes table over `/wp-json/tejcart/v1/disputes`
 * for back-office dashboards and reporting integrations. The endpoints
 * are gated by the `manage_tejcart_disputes` capability — there is
 * intentionally no public surface.
 *
 * Mutating routes (resolve, add note) are also exposed so admin tooling
 * built on top of the API can drive the same lifecycle the WP-Admin
 * queue does. They reuse the same {@see Dispute::resolve()} helpers and
 * fire the same `tejcart_dispute_manual_resolve` action.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class REST_API {
    public const NAMESPACE = 'tejcart/v1';

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/disputes',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'list_disputes' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                    'args'                => array(
                        'status'        => array( 'type' => 'string', 'required' => false ),
                        'gateway'       => array( 'type' => 'string', 'required' => false ),
                        'order_id'      => array( 'type' => 'integer', 'required' => false ),
                        'search'        => array( 'type' => 'string', 'required' => false ),
                        'opened_after'  => array( 'type' => 'string', 'required' => false ),
                        'opened_before' => array( 'type' => 'string', 'required' => false ),
                        'per_page'      => array( 'type' => 'integer', 'required' => false, 'default' => 25 ),
                        'page'          => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/disputes/(?P<id>\d+)',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_dispute' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/disputes/(?P<id>\d+)/resolve',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'resolve_dispute' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                    'args'                => array(
                        'status' => array( 'type' => 'string', 'required' => true ),
                        'note'   => array( 'type' => 'string', 'required' => false, 'default' => '' ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/disputes/(?P<id>\d+)/notes',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'add_note' ),
                    'permission_callback' => array( $this, 'permission_check' ),
                    'args'                => array(
                        'note' => array( 'type' => 'string', 'required' => true ),
                    ),
                ),
            )
        );
    }

    public function permission_check(): bool {
        return Capabilities::check();
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function list_disputes( $request ) {
        $filters  = array();
        foreach ( array( 'status', 'gateway', 'search', 'opened_after', 'opened_before' ) as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value ) {
                $filters[ $key ] = (string) $value;
            }
        }
        $order_id = (int) $request->get_param( 'order_id' );
        if ( $order_id > 0 ) {
            $filters['order_id'] = $order_id;
        }

        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $disputes = Dispute::query( $filters, $per_page, $offset );
        $total    = Dispute::count( $filters );

        $data = array_map( array( $this, 'serialize' ), $disputes );

        $response = rest_ensure_response( array(
            'items' => $data,
            'total' => $total,
            'page'  => $page,
            'per_page' => $per_page,
        ) );

        if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
            $response->header( 'X-WP-Total', (string) $total );
            $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
        }
        return $response;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_dispute( $request ) {
        $id      = (int) $request->get_param( 'id' );
        $dispute = Dispute::find( $id );
        if ( ! $dispute ) {
            return new \WP_Error( 'tejcart_disputes_not_found', __( 'Dispute not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $this->serialize( $dispute ) );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function resolve_dispute( $request ) {
        $id      = (int) $request->get_param( 'id' );
        $dispute = Dispute::find( $id );
        if ( ! $dispute ) {
            return new \WP_Error( 'tejcart_disputes_not_found', __( 'Dispute not found.', 'tejcart' ), array( 'status' => 404 ) );
        }

        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        if ( ! in_array( $status, Dispute::terminal_statuses(), true ) ) {
            return new \WP_Error(
                'tejcart_disputes_invalid_status',
                sprintf(
                    /* translators: %s comma-separated list of allowed statuses */
                    __( 'Status must be one of: %s.', 'tejcart' ),
                    implode( ', ', Dispute::terminal_statuses() )
                ),
                array( 'status' => 400 )
            );
        }

        $actor = $this->actor_label();
        $note  = (string) $request->get_param( 'note' );
        $dispute->resolve( $status, $note, $actor );

        do_action( 'tejcart_dispute_manual_resolve', $dispute, $status, $actor );

        return rest_ensure_response( $this->serialize( $dispute ) );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function add_note( $request ) {
        $id      = (int) $request->get_param( 'id' );
        $dispute = Dispute::find( $id );
        if ( ! $dispute ) {
            return new \WP_Error( 'tejcart_disputes_not_found', __( 'Dispute not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        $note = trim( (string) $request->get_param( 'note' ) );
        if ( '' === $note ) {
            return new \WP_Error( 'tejcart_disputes_empty_note', __( 'Note cannot be empty.', 'tejcart' ), array( 'status' => 400 ) );
        }
        $actor = $this->actor_label();
        $status_snapshot = $dispute->status;
        $dispute->append_note( $note, $actor );
        Dispute_Event::record( $dispute->id, 'note_added', $status_snapshot, $status_snapshot, array( 'note' => $note ), $actor );
        return rest_ensure_response( $this->serialize( $dispute ) );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize( Dispute $dispute ): array {
        $data = array(
            'id'               => $dispute->id,
            'order_id'         => $dispute->order_id,
            'gateway'          => $dispute->gateway,
            'external_id'      => $dispute->external_id,
            'transaction_ref'  => $dispute->transaction_ref,
            'status'           => $dispute->status,
            'reason'           => $dispute->reason,
            'outcome'          => $dispute->outcome,
            'amount'           => $dispute->amount,
            'currency'         => $dispute->currency,
            'evidence_due'     => $dispute->evidence_due,
            'opened_at'        => $dispute->opened_at,
            'updated_at'       => $dispute->updated_at,
            'resolved_at'      => $dispute->resolved_at,
            'is_actionable'    => $dispute->is_actionable(),
            'is_terminal'      => $dispute->is_terminal(),
        );

        if ( Capabilities::check( 'manage_options' ) ) {
            $data['notes']            = $dispute->notes;
            $data['notes_updated_at'] = $dispute->notes_updated_at;
        }

        return $data;
    }

    private function actor_label(): string {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return 'system';
        }
        $user = wp_get_current_user();
        if ( is_object( $user ) && ! empty( $user->display_name ) ) {
            // F-MODS-011: sanitise before storage so the `actor` column
            // never holds unclean data. The render layer (event_timeline)
            // already wraps output in esc_html(), but defence-in-depth
            // requires the stored value to be clean too. A 200-char cap
            // prevents oversized payloads from straining the DB column.
            $label = sanitize_text_field( (string) $user->display_name );
            return substr( $label, 0, 200 );
        }
        if ( is_object( $user ) && ! empty( $user->user_login ) ) {
            $label = sanitize_text_field( (string) $user->user_login );
            return substr( $label, 0, 200 );
        }
        return 'system';
    }
}
