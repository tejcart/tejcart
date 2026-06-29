<?php
/**
 * Admin "Disputes" submenu page.
 *
 * Lightweight WP_List_Table-style page that lists open / closed
 * disputes from the unified table. Linked from the TejCart top-level
 * menu so merchants have a single place to triage chargebacks across
 * gateways. Supports search, gateway / status / date-range filters,
 * pagination, manual resolve actions (mark won / lost / accepted /
 * closed), internal notes, and a CSV export of the current filter set.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Queue {
    public const PAGE_SLUG = 'tejcart-disputes';

    public const PER_PAGE = 25;

    public const NONCE_ACTION = 'tejcart_disputes_action';

    /**
     * Notice queued for the next render. Populated from request handlers
     * before the redirect-after-POST and surfaced via admin_notices.
     *
     * @var array{type: string, message: string}|null
     */
    private static ?array $notice = null;

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_export' ) );
        add_action( 'admin_notices', array( $this, 'render_notice' ) );
        add_filter( 'tejcart_admin_page_hooks', array( $this, 'register_admin_hooks' ) );
        add_filter( 'tejcart_admin_list_page_hooks', array( $this, 'register_admin_hooks' ) );
    }

    /**
     * Register this module's admin-page hook with TejCart core so the
     * queue inherits both the base admin CSS (`tejcart-admin.css`, header /
     * card tokens) and the list-page bundle (`admin-list.css` — chip-style
     * status tabs, `.nxc-empty-state`, `.tablenav` chrome) that the core
     * Orders / Products screens use.
     *
     * @param string[] $hooks
     * @return string[]
     */
    public function register_admin_hooks( array $hooks ): array {
        $hooks[] = 'tejcart_page_' . self::PAGE_SLUG;
        return $hooks;
    }

    public function add_menu(): void {
        $open_count = Dispute::count_actionable();

        $title = __( 'Disputes', 'tejcart' );
        if ( $open_count > 0 ) {
            $title .= ' <span class="awaiting-mod">' . (int) $open_count . '</span>';
        }

        // Sidebar slot is owned by TejCart core's reorder_submenu() hook
        // (admin_menu priority 9999) — see Menu::canonical_submenu_order().
        $hook = add_submenu_page(
            'tejcart',
            __( 'Disputes', 'tejcart' ),
            $title,
            Capabilities::MANAGE_DISPUTES,
            self::PAGE_SLUG,
            array( $this, 'render' )
        );

        if ( $hook ) {
            add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_admin_styles' ) );
        }
    }

    /**
     * Enqueue the dispute admin stylesheet on this page only. The
     * stylesheet defines the same `--nc-*` design tokens as core
     * TejCart so the queue page stays visually aligned even when the
     * core admin CSS isn't loaded on this submenu.
     */
    public function enqueue_admin_styles(): void {
        // Pre-fix this referenced `tejcart-disputes.php` (legacy
        // standalone-plugin bootstrap name) which no longer exists —
        // when the module graduated into the core zip the bootstrap
        // was renamed to `module.php`. plugins_url() returned a 404
        // URL and the admin CSS never loaded. Audit H-8.
        wp_enqueue_style(
            'tejcart-disputes-admin',
            plugins_url( 'assets/css/admin.css', TEJCART_DISPUTES_FILE ),
            array(),
            TEJCART_DISPUTES_VERSION
        );
    }

    /**
     * Handle a manual admin action — resolving a dispute or appending
     * an internal note. POST-only with nonce + capability gates; the
     * dispatcher exits via {@see redirect()} so there's never a re-POST
     * on browser refresh.
     */
    public function handle_actions(): void {
        if ( empty( $_POST['tejcart_disputes_action'] ) ) {
            return;
        }
        if ( ! Capabilities::check() ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }
        check_admin_referer( self::NONCE_ACTION );

        $action     = sanitize_key( wp_unslash( (string) $_POST['tejcart_disputes_action'] ) );
        $dispute_id = isset( $_POST['dispute_id'] ) ? absint( wp_unslash( (string) $_POST['dispute_id'] ) ) : 0;
        $dispute    = $dispute_id ? Dispute::find( $dispute_id ) : null;
        if ( ! $dispute ) {
            self::$notice = array( 'type' => 'error', 'message' => __( 'Dispute not found.', 'tejcart' ) );
            $this->redirect_to_queue();
            return;
        }

        $actor = $this->actor_label();
        $note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

        switch ( $action ) {
            case 'mark_won':
                $dispute->resolve( Dispute::STATUS_WON, $note ?: __( 'Marked as won via admin.', 'tejcart' ), $actor );
                self::$notice = array( 'type' => 'success', 'message' => __( 'Dispute marked as won.', 'tejcart' ) );
                do_action( 'tejcart_dispute_manual_resolve', $dispute, Dispute::STATUS_WON, $actor );
                break;
            case 'mark_lost':
                $dispute->resolve( Dispute::STATUS_LOST, $note ?: __( 'Marked as lost via admin.', 'tejcart' ), $actor );
                self::$notice = array( 'type' => 'success', 'message' => __( 'Dispute marked as lost.', 'tejcart' ) );
                do_action( 'tejcart_dispute_manual_resolve', $dispute, Dispute::STATUS_LOST, $actor );
                break;
            case 'mark_accepted':
                $dispute->resolve( Dispute::STATUS_ACCEPTED, $note ?: __( 'Loss accepted by admin.', 'tejcart' ), $actor );
                self::$notice = array( 'type' => 'success', 'message' => __( 'Dispute accepted.', 'tejcart' ) );
                do_action( 'tejcart_dispute_manual_resolve', $dispute, Dispute::STATUS_ACCEPTED, $actor );
                break;
            case 'mark_closed':
                $dispute->resolve( Dispute::STATUS_CLOSED, $note ?: __( 'Closed by admin.', 'tejcart' ), $actor );
                self::$notice = array( 'type' => 'success', 'message' => __( 'Dispute closed.', 'tejcart' ) );
                do_action( 'tejcart_dispute_manual_resolve', $dispute, Dispute::STATUS_CLOSED, $actor );
                break;
            case 'add_note':
                if ( '' === $note ) {
                    self::$notice = array( 'type' => 'error', 'message' => __( 'Note cannot be empty.', 'tejcart' ) );
                    break;
                }
                $status_snapshot = $dispute->status;
                $dispute->append_note( $note, $actor );
                Dispute_Event::record( $dispute->id, 'note_added', $status_snapshot, $status_snapshot, array( 'note' => $note ), $actor );
                self::$notice = array( 'type' => 'success', 'message' => __( 'Note added.', 'tejcart' ) );
                break;
            default:
                self::$notice = array( 'type' => 'error', 'message' => __( 'Unknown action.', 'tejcart' ) );
        }

        $this->redirect_single( $dispute->id );
    }

    /**
     * CSV export of the current filter set. Separated from
     * {@see handle_actions()} because it bypasses the POST/redirect
     * pattern and streams a file response directly.
     */
    public function handle_export(): void {
        if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
            return;
        }
        if ( self::PAGE_SLUG !== $_GET['page'] || 'export' !== $_GET['action'] ) {
            return;
        }
        if ( ! Capabilities::check() ) {
            wp_die( esc_html__( 'You do not have permission to export disputes.', 'tejcart' ) );
        }
        check_admin_referer( self::NONCE_ACTION );

        $filters  = $this->collect_filters( $_GET );
        $disputes = Dispute::query( $filters, 5000, 0 );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="tejcart-disputes-' . gmdate( 'Ymd-His' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel opens the file with correct encoding.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'id', 'gateway', 'external_id', 'order_id', 'status', 'reason', 'outcome', 'amount', 'currency', 'evidence_due', 'opened_at', 'resolved_at', 'transaction_ref', 'notes' ) );
        foreach ( $disputes as $d ) {
            fputcsv( $out, array(
                $d->id,
                self::sanitize_csv( $d->gateway ),
                self::sanitize_csv( $d->external_id ),
                $d->order_id,
                self::sanitize_csv( $d->status ),
                self::sanitize_csv( $d->reason ),
                self::sanitize_csv( $d->outcome ),
                number_format( $d->amount, 2, '.', '' ),
                self::sanitize_csv( $d->currency ),
                self::sanitize_csv( (string) $d->evidence_due ),
                self::sanitize_csv( $d->opened_at ),
                self::sanitize_csv( (string) $d->resolved_at ),
                self::sanitize_csv( $d->transaction_ref ),
                self::sanitize_csv( $d->notes ),
            ) );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writing to php://output stream for CSV export
        fclose( $out );
        exit;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin list-table display reads of $_GET filters; no state changes made from these values
    public function render_notice(): void {
        if ( null === self::$notice ) {
            // Pull stashed notice from the redirect query var.
            if ( isset( $_GET['tejcart_disputes_notice'], $_GET['tejcart_disputes_notice_type'] ) ) {
                self::$notice = array(
                    'type'    => 'error' === $_GET['tejcart_disputes_notice_type'] ? 'error' : 'success',
                    'message' => sanitize_text_field( wp_unslash( (string) $_GET['tejcart_disputes_notice'] ) ),
                );
            }
        }
        if ( null === self::$notice ) {
            return;
        }
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }
        $class = 'success' === self::$notice['type'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( self::$notice['message'] ) . '</p></div>';
        self::$notice = null;
    }

    public function render(): void {
        if ( ! Capabilities::check() ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'tejcart' ) );
        }

        $dispute_id = isset( $_GET['dispute'] ) ? absint( wp_unslash( $_GET['dispute'] ) ) : 0;
        if ( $dispute_id ) {
            $this->render_single( $dispute_id );
            return;
        }

        $filters  = $this->collect_filters( $_GET );
        $status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : 'all';
        $page_num = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
        $offset   = ( $page_num - 1 ) * self::PER_PAGE;

        $total    = Dispute::count( $filters );
        $disputes = Dispute::query( $filters, self::PER_PAGE, $offset );

        $export_url = wp_nonce_url(
            add_query_arg(
                array_merge(
                    array(
                        'page'   => self::PAGE_SLUG,
                        'action' => 'export',
                    ),
                    $this->filters_to_query( $filters )
                ),
                admin_url( 'admin.php' )
            ),
            self::NONCE_ACTION
        );

        $subtitle = $total > 0
            ? sprintf(
                /* translators: %s: total dispute count */
                _n( 'Triage chargebacks across gateways · %s dispute', 'Triage chargebacks across gateways · %s disputes', $total, 'tejcart' ),
                number_format_i18n( $total )
            )
            : __( 'Triage chargebacks across gateways.', 'tejcart' );

        $is_filtered = ! empty( $filters['search'] )
            || ! empty( $filters['gateway'] )
            || ! empty( $filters['opened_after'] )
            || ! empty( $filters['opened_before'] )
            || ( 'all' !== $status && '' !== $status );

        echo '<div class="wrap tejcart-admin-wrap nxc-list nxc-disputes">';
        echo '<header class="nxc-page-header">';
        echo '<div class="nxc-page-header__title">';
        echo '<h1>' . esc_html__( 'Disputes', 'tejcart' ) . '</h1>';
        echo '<p class="nxc-page-header__subtitle">' . esc_html( $subtitle ) . '</p>';
        echo '</div>';
        echo '<div class="nxc-page-header__actions">';
        echo '<a class="nxc-btn" href="' . esc_url( $export_url ) . '">';
        echo '<svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
        echo esc_html__( 'Export CSV', 'tejcart' );
        echo '</a>';
        echo '</div>';
        echo '</header>';
        echo '<span class="wp-header-end"></span>';

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="nxc-form" id="nxc-disputes-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

        echo '<div class="nxc-toolbar">';
        $this->render_status_filter( $status, $filters );
        $this->render_search_box( $filters );
        echo '</div>';

        echo '<div class="nxc-card">';

        $this->render_filter_tablenav( $filters, 'top' );

        if ( empty( $disputes ) ) {
            $this->render_empty_state( $is_filtered );
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Opened', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Gateway', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Order', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Reason', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Amount', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Evidence due', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'tejcart' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $disputes as $d ) {
                echo '<tr>';
                echo '<td>' . esc_html( $d->opened_at ) . '</td>';
                echo '<td>' . esc_html( ucfirst( $d->gateway ) ) . '</td>';
                echo '<td>' . ( $d->order_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-orders&action=edit&order_id=' . (int) $d->order_id ) ) . '">#' . (int) $d->order_id . '</a>' : '—' ) . '</td>';
                echo '<td>' . esc_html( $d->reason ) . '</td>';
                echo '<td>' . esc_html( wp_strip_all_tags( tejcart_price( (float) $d->amount, (string) $d->currency ) ) ) . '</td>';
                echo '<td>' . $this->status_badge( $d->status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
                echo '<td>' . esc_html( $d->evidence_due ?: '—' ) . '</td>';
                echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=tejcart-disputes&dispute=' . (int) $d->id ) ) . '">' . esc_html__( 'View', 'tejcart' ) . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            $this->render_pagination( $total, $page_num, $filters );
        }

        echo '</div>'; // .nxc-card
        echo '</form>';
        echo '</div>';
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    /**
     * Empty-state block matching the core Products list styling. Distinguishes
     * "no disputes yet" from "search / filter returned nothing" so each state
     * surfaces a useful next step.
     */
    private function render_empty_state( bool $is_filtered ): void {
        $clear_url = admin_url( 'admin.php?page=tejcart-disputes' );
        ?>
        <div class="nxc-empty-state">
            <svg class="nxc-empty-state__icon" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M24 6l18 8v10c0 11-7.5 18-18 22-10.5-4-18-11-18-22V14l18-8z"/>
                <path d="M18 24l4 4 8-8"/>
            </svg>
            <?php if ( $is_filtered ) : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No disputes match your filters', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Try a different keyword, status, or clear the active filters.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn nxc-btn--ghost" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear filters', 'tejcart' ); ?></a></p>
            <?php else : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No disputes yet', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Chargebacks from your connected gateways will appear here once they arrive.', 'tejcart' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_single( int $id ): void {
        $dispute = Dispute::find( $id );
        if ( ! $dispute ) {
            echo '<div class="wrap tejcart-admin-wrap"><div class="tejcart-page-header"><div class="tejcart-page-header-content"><h1>' . esc_html__( 'Dispute not found', 'tejcart' ) . '</h1></div></div></div>';
            return;
        }

        $back_url   = admin_url( 'admin.php?page=tejcart-disputes' );
        $opened_iso = '' !== $dispute->opened_at ? gmdate( 'c', (int) strtotime( $dispute->opened_at ) ) : '';
        $opened_fmt = '' !== $dispute->opened_at
            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $dispute->opened_at ) )
            : '';
        ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <a href="<?php echo esc_url( $back_url ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Disputes', 'tejcart' ); ?></a>
                    <h1>
                        <?php
                        printf(
                            /* translators: 1: gateway, 2: external ID */
                            esc_html__( '%1$s dispute %2$s', 'tejcart' ),
                            esc_html( ucfirst( $dispute->gateway ) ),
                            esc_html( $dispute->external_id )
                        );
                        ?>
                        <?php echo $this->status_badge( $dispute->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Review dispute details, submit evidence and track resolution.', 'tejcart' ); ?></p>
                    <ul class="tejcart-order-meta-strip" aria-label="<?php esc_attr_e( 'Dispute metadata', 'tejcart' ); ?>">
                        <?php if ( '' !== $opened_fmt ) : ?>
                            <li>
                                <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Opened', 'tejcart' ); ?></span>
                                <time datetime="<?php echo esc_attr( $opened_iso ); ?>"><?php echo esc_html( $opened_fmt ); ?></time>
                            </li>
                        <?php endif; ?>
                        <?php if ( '' !== $dispute->currency ) : ?>
                            <li>
                                <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Amount', 'tejcart' ); ?></span>
                                <span><?php echo esc_html( wp_strip_all_tags( tejcart_price( (float) $dispute->amount, (string) $dispute->currency ) ) ); ?></span>
                            </li>
                        <?php endif; ?>
                        <li>
                            <span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Gateway', 'tejcart' ); ?></span>
                            <span><?php echo esc_html( ucfirst( $dispute->gateway ) ); ?></span>
                        </li>
                        <?php if ( $dispute->order_id ) : ?>
                            <li>
                                <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Order', 'tejcart' ); ?></span>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders&action=edit&order_id=' . (int) $dispute->order_id ) ); ?>">#<?php echo (int) $dispute->order_id; ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if ( '' !== $dispute->transaction_ref ) : ?>
                            <li>
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Transaction', 'tejcart' ); ?></span>
                                <code><?php echo esc_html( $dispute->transaction_ref ); ?></code>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="tejcart-detail-grid">
                <!-- MAIN COLUMN -->
                <div class="tejcart-detail-main">
                    <?php $this->render_actions_form( $dispute ); ?>
                    <?php $this->render_notes_block( $dispute ); ?>
                    <?php $this->render_event_timeline( $dispute ); ?>

                    <?php if ( ! empty( $dispute->payload ) ) : ?>
                        <div class="tejcart-card">
                            <div class="tejcart-card-header">
                                <h3><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> <?php esc_html_e( 'Raw gateway payload', 'tejcart' ); ?></h3>
                            </div>
                            <pre class="tejcart-disputes-payload"><?php echo esc_html( wp_json_encode( $dispute->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SIDEBAR -->
                <div class="tejcart-detail-side">
                    <div class="tejcart-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-info" aria-hidden="true"></span> <?php esc_html_e( 'Dispute details', 'tejcart' ); ?></h3>
                        </div>
                        <div class="tejcart-card-body">
                            <dl class="tejcart-disputes-detail-list">
                                <div class="tejcart-disputes-detail-row">
                                    <dt><?php esc_html_e( 'Status', 'tejcart' ); ?></dt>
                                    <dd><?php echo $this->status_badge( $dispute->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
                                </div>
                                <div class="tejcart-disputes-detail-row">
                                    <dt><?php esc_html_e( 'Reason', 'tejcart' ); ?></dt>
                                    <dd><?php echo esc_html( $dispute->reason ?: '—' ); ?></dd>
                                </div>
                                <?php if ( $dispute->outcome ) : ?>
                                    <div class="tejcart-disputes-detail-row">
                                        <dt><?php esc_html_e( 'Outcome', 'tejcart' ); ?></dt>
                                        <dd><?php echo esc_html( $dispute->outcome ); ?></dd>
                                    </div>
                                <?php endif; ?>
                                <div class="tejcart-disputes-detail-row">
                                    <dt><?php esc_html_e( 'Amount', 'tejcart' ); ?></dt>
                                    <dd><?php echo esc_html( wp_strip_all_tags( tejcart_price( (float) $dispute->amount, (string) $dispute->currency ) ) ); ?></dd>
                                </div>
                                <div class="tejcart-disputes-detail-row">
                                    <dt><?php esc_html_e( 'Opened', 'tejcart' ); ?></dt>
                                    <dd><?php echo esc_html( $dispute->opened_at . ' UTC' ); ?></dd>
                                </div>
                                <?php if ( $dispute->evidence_due ) : ?>
                                    <div class="tejcart-disputes-detail-row">
                                        <dt><?php esc_html_e( 'Evidence due', 'tejcart' ); ?></dt>
                                        <dd><?php echo $this->evidence_due_label( $dispute ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $dispute->resolved_at ) : ?>
                                    <div class="tejcart-disputes-detail-row">
                                        <dt><?php esc_html_e( 'Resolved', 'tejcart' ); ?></dt>
                                        <dd><?php echo esc_html( $dispute->resolved_at . ' UTC' ); ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>

                    <?php if ( $dispute->order_id ) : ?>
                        <div class="tejcart-card">
                            <div class="tejcart-card-header">
                                <h3><span class="dashicons dashicons-cart" aria-hidden="true"></span> <?php esc_html_e( 'Linked order', 'tejcart' ); ?></h3>
                            </div>
                            <div class="tejcart-card-body">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders&action=edit&order_id=' . (int) $dispute->order_id ) ); ?>" class="button" style="width:100%;text-align:center;">
                                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                    <?php
                                    printf(
                                        /* translators: %d: order ID */
                                        esc_html__( 'View order #%d', 'tejcart' ),
                                        (int) $dispute->order_id
                                    );
                                    ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_actions_form( Dispute $dispute ): void {
        echo '<div class="tejcart-card">';
        echo '<div class="tejcart-card-header"><h3><span class="dashicons dashicons-shield" aria-hidden="true"></span> ' . esc_html__( 'Resolve dispute', 'tejcart' ) . '</h3></div>';
        echo '<div class="tejcart-card-body">';

        if ( $dispute->is_terminal() ) {
            echo '<p>' . esc_html__( 'This dispute is closed. No further actions are available.', 'tejcart' ) . '</p>';
            echo '</div></div>';
            return;
        }

        $form_url = admin_url( 'admin.php?page=tejcart-disputes&dispute=' . (int) $dispute->id );

        echo '<form method="post" action="' . esc_url( $form_url ) . '" class="tejcart-disputes-actions">';
        wp_nonce_field( self::NONCE_ACTION );
        echo '<input type="hidden" name="dispute_id" value="' . (int) $dispute->id . '" />';
        echo '<p><label for="tejcart-disputes-note">' . esc_html__( 'Optional note', 'tejcart' ) . '</label><br />';
        echo '<textarea id="tejcart-disputes-note" name="note" rows="3" class="large-text"></textarea></p>';
        echo '<div class="tejcart-disputes-actions__buttons">';
        $this->action_button( 'mark_won',      __( 'Mark won',      'tejcart' ), 'button button-primary' );
        $this->action_button( 'mark_lost',     __( 'Mark lost',     'tejcart' ), 'button' );
        $this->action_button( 'mark_accepted', __( 'Accept loss',   'tejcart' ), 'button' );
        $this->action_button( 'mark_closed',   __( 'Close dispute', 'tejcart' ), 'button' );
        echo '</div>';
        echo '</form>';

        echo '</div></div>';
    }

    private function action_button( string $action, string $label, string $class ): void {
        printf(
            '<button type="submit" name="tejcart_disputes_action" value="%1$s" class="%2$s">%3$s</button>',
            esc_attr( $action ),
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    private function render_notes_block( Dispute $dispute ): void {
        echo '<div class="tejcart-card">';
        echo '<div class="tejcart-card-header"><h3><span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . esc_html__( 'Internal notes', 'tejcart' ) . '</h3></div>';
        echo '<div class="tejcart-card-body">';

        if ( '' !== $dispute->notes ) {
            echo '<pre class="tejcart-disputes-notes">' . esc_html( $dispute->notes ) . '</pre>';
        } else {
            echo '<p>' . esc_html__( 'No internal notes yet.', 'tejcart' ) . '</p>';
        }

        $form_url = admin_url( 'admin.php?page=tejcart-disputes&dispute=' . (int) $dispute->id );
        echo '<form method="post" action="' . esc_url( $form_url ) . '" class="tejcart-disputes-notes-form">';
        wp_nonce_field( self::NONCE_ACTION );
        echo '<input type="hidden" name="dispute_id" value="' . (int) $dispute->id . '" />';
        echo '<input type="hidden" name="tejcart_disputes_action" value="add_note" />';
        echo '<p><textarea name="note" rows="3" class="large-text" placeholder="' . esc_attr__( 'Add a private note for your team…', 'tejcart' ) . '" required></textarea></p>';
        echo '<p><button type="submit" class="button">' . esc_html__( 'Save note', 'tejcart' ) . '</button></p>';
        echo '</form>';

        echo '</div></div>';
    }

    private function render_event_timeline( Dispute $dispute ): void {
        $events = Dispute_Event::for_dispute( $dispute->id );
        if ( empty( $events ) ) {
            return;
        }

        echo '<div class="tejcart-card">';
        echo '<div class="tejcart-card-header"><h3><span class="dashicons dashicons-backup" aria-hidden="true"></span> ' . esc_html__( 'Event timeline', 'tejcart' ) . '</h3></div>';
        echo '<div class="tejcart-card-body">';
        echo '<ul class="tejcart-disputes-timeline">';

        foreach ( $events as $event ) {
            $type   = (string) ( $event['event_type'] ?? '' );
            $before = (string) ( $event['status_before'] ?? '' );
            $after  = (string) ( $event['status_after'] ?? '' );
            $actor  = (string) ( $event['actor'] ?? '' );
            $at     = (string) ( $event['occurred_at'] ?? '' );

            $label = $this->event_label( $type, $before, $after, $actor );
            $tone  = $this->event_tone( $type, $after );

            echo '<li class="tejcart-disputes-timeline__item">';
            echo '<span class="tejcart-disputes-timeline__dot tejcart-disputes-timeline__dot--' . esc_attr( $tone ) . '"></span>';
            echo '<div class="tejcart-disputes-timeline__content">';
            echo '<span class="tejcart-disputes-timeline__label">' . esc_html( $label ) . '</span>';
            echo '<span class="tejcart-disputes-timeline__time">' . esc_html( $at ) . ' UTC</span>';
            echo '</div>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</div></div>';
    }

    private function event_label( string $type, string $before, string $after, string $actor ): string {
        switch ( $type ) {
            case 'webhook_created':
                return sprintf(
                    /* translators: %s: status */
                    __( 'Dispute opened via webhook — status: %s', 'tejcart' ),
                    str_replace( '_', ' ', $after )
                );
            case 'webhook_updated':
                if ( $before !== $after && '' !== $before ) {
                    return sprintf(
                        /* translators: 1: old status, 2: new status */
                        __( 'Webhook updated status: %1$s → %2$s', 'tejcart' ),
                        str_replace( '_', ' ', $before ),
                        str_replace( '_', ' ', $after )
                    );
                }
                return __( 'Webhook updated dispute data', 'tejcart' );
            case 'webhook_resolved':
                return sprintf(
                    /* translators: %s: status */
                    __( 'Gateway resolved dispute — status: %s', 'tejcart' ),
                    str_replace( '_', ' ', $after )
                );
            case 'webhook_rejected':
                return sprintf(
                    /* translators: %s: attempted status */
                    __( 'Webhook status change rejected (already resolved) — attempted: %s', 'tejcart' ),
                    str_replace( '_', ' ', $after )
                );
            case 'manual_resolve':
                return sprintf(
                    /* translators: 1: actor, 2: status */
                    __( '%1$s resolved dispute as %2$s', 'tejcart' ),
                    $actor ?: __( 'Admin', 'tejcart' ),
                    str_replace( '_', ' ', $after )
                );
            case 'note_added':
                return sprintf(
                    /* translators: %s: actor */
                    __( '%s added a note', 'tejcart' ),
                    $actor ?: __( 'Admin', 'tejcart' )
                );
            default:
                return $type;
        }
    }

    private function event_tone( string $type, string $status ): string {
        if ( 'webhook_rejected' === $type ) {
            return 'muted';
        }
        if ( 'note_added' === $type ) {
            return 'neutral';
        }
        if ( in_array( $status, array( Dispute::STATUS_WON ), true ) ) {
            return 'success';
        }
        if ( in_array( $status, array( Dispute::STATUS_LOST, Dispute::STATUS_NEEDS_RESPONSE ), true ) ) {
            return 'error';
        }
        if ( Dispute::STATUS_OPEN === $status ) {
            return 'warning';
        }
        return 'neutral';
    }

    private function evidence_due_label( Dispute $dispute ): string {
        $due = $dispute->evidence_due;
        if ( ! $due || $dispute->is_terminal() ) {
            return esc_html( $due . ' UTC' );
        }

        // time() is already UTC; current_time('timestamp', true) is deprecated.
        $now       = time();
        $due_ts    = strtotime( $due );
        if ( ! $due_ts ) {
            return esc_html( $due . ' UTC' );
        }

        $days_left = (int) ceil( ( $due_ts - $now ) / DAY_IN_SECONDS );

        if ( $days_left < 0 ) {
            $tone  = 'error';
            $label = __( 'Overdue', 'tejcart' );
        } elseif ( $days_left <= 1 ) {
            $tone  = 'error';
            $label = __( 'Due today', 'tejcart' );
        } elseif ( $days_left <= 3 ) {
            $tone  = 'warning';
            $label = sprintf(
                /* translators: %d: days remaining */
                _n( '%d day left', '%d days left', $days_left, 'tejcart' ),
                $days_left
            );
        } else {
            $tone  = 'neutral';
            $label = sprintf(
                /* translators: %d: days remaining */
                _n( '%d day left', '%d days left', $days_left, 'tejcart' ),
                $days_left
            );
        }

        return esc_html( $due . ' UTC' )
            . ' <span class="tejcart-pill tejcart-pill--' . esc_attr( $tone ) . '">'
            . esc_html( $label )
            . '</span>';
    }

    private function detail_row( string $label, string $value, bool $escape = true ): void {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
        echo $escape ? esc_html( $value ) : $value; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</td></tr>';
    }

    /**
     * Render the status tab strip. Mirrors the Products list views() override:
     * emit `<ul class="subsubsub">` directly so the chip-style CSS in
     * `assets/css/admin-list.css` handles spacing, instead of interleaving
     * literal " |" separators between items.
     *
     * @param array<string, mixed> $filters
     */
    private function render_status_filter( string $current, array $filters ): void {
        $tabs = array(
            'all'                            => __( 'All', 'tejcart' ),
            Dispute::STATUS_OPEN             => __( 'Open', 'tejcart' ),
            Dispute::STATUS_NEEDS_RESPONSE   => __( 'Needs response', 'tejcart' ),
            Dispute::STATUS_UNDER_REVIEW     => __( 'Under review', 'tejcart' ),
            Dispute::STATUS_WON              => __( 'Won', 'tejcart' ),
            Dispute::STATUS_LOST             => __( 'Lost', 'tejcart' ),
            Dispute::STATUS_ACCEPTED         => __( 'Accepted', 'tejcart' ),
            Dispute::STATUS_CLOSED           => __( 'Closed', 'tejcart' ),
        );

        // Re-flow non-status filters into the tab links so switching
        // status doesn't clobber the active gateway/search filter.
        $persistent = $this->filters_to_query( $filters );
        unset( $persistent['status'] );

        // Single grouped query for all tab counts instead of N+1.
        $count_filters = $filters;
        unset( $count_filters['status'] );
        $counts = Dispute::count_by_status( $count_filters );

        echo '<ul class="subsubsub">';
        foreach ( $tabs as $key => $label ) {
            $args = array_merge( array( 'page' => self::PAGE_SLUG ), $persistent );
            if ( 'all' !== $key ) {
                $args['status'] = $key;
            }
            $url   = add_query_arg( $args, admin_url( 'admin.php' ) );
            $class = $key === $current ? ' class="current"' : '';
            $count = 'all' === $key ? ( $counts['_all'] ?? 0 ) : ( $counts[ $key ] ?? 0 );

            printf(
                '<li class="%1$s"><a href="%2$s"%3$s>%4$s <span class="count">(%5$s)</span></a></li>',
                esc_attr( (string) $key ),
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $label ),
                esc_html( number_format_i18n( $count ) )
            );
        }
        echo '</ul>';
    }

    /**
     * Render the toolbar search box (full-text search only). Renders inside
     * the page-level `<form method="get">` that wraps the toolbar and the
     * card so it submits alongside the filter controls in the tablenav.
     *
     * @param array<string, mixed> $filters
     */
    private function render_search_box( array $filters ): void {
        $search = (string) ( $filters['search'] ?? '' );
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="tejcart-disputes-search-input"><?php esc_html_e( 'Search disputes', 'tejcart' ); ?>:</label>
            <input type="search"
                   id="tejcart-disputes-search-input"
                   name="search"
                   value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'External ID, transaction, reason, notes…', 'tejcart' ); ?>" />
            <?php submit_button( __( 'Search disputes', 'tejcart' ), 'button', '', false, array( 'id' => 'tejcart-disputes-search-submit' ) ); ?>
        </p>
        <?php
    }

    /**
     * Render the gateway + date-range filter strip as a tablenav block
     * inside the list card — matching the Products page extra_tablenav()
     * shape so the two pages share the same chrome.
     *
     * @param array<string, mixed> $filters
     */
    private function render_filter_tablenav( array $filters, string $which ): void {
        if ( 'top' !== $which ) {
            return;
        }

        $current_gateway = (string) ( $filters['gateway'] ?? '' );
        $current_after   = (string) ( $filters['opened_after'] ?? '' );
        $current_before  = (string) ( $filters['opened_before'] ?? '' );

        // The date filters round-trip as `YYYY-MM-DD HH:MM:SS` inside the
        // normalised filter map; the `<input type=date>` only accepts the
        // 10-char prefix.
        $current_after  = $current_after  !== '' ? substr( $current_after, 0, 10 )  : '';
        $current_before = $current_before !== '' ? substr( $current_before, 0, 10 ) : '';

        $gateways = array(
            'paypal'        => __( 'PayPal',        'tejcart' ),
            'stripe'        => __( 'Stripe',        'tejcart' ),
            'authorize_net' => __( 'Authorize.Net', 'tejcart' ),
        );

        $has_filters = ( '' !== $current_gateway ) || ( '' !== $current_after ) || ( '' !== $current_before );
        ?>
        <div class="tablenav top">
            <div class="alignleft actions nxc-filters">
                <label class="screen-reader-text" for="tejcart-disputes-filter-gateway"><?php esc_html_e( 'Filter by gateway', 'tejcart' ); ?></label>
                <select name="gateway" id="tejcart-disputes-filter-gateway">
                    <option value=""><?php esc_html_e( 'All gateways', 'tejcart' ); ?></option>
                    <?php foreach ( $gateways as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_gateway, $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="screen-reader-text" for="tejcart-disputes-filter-after"><?php esc_html_e( 'Opened on or after', 'tejcart' ); ?></label>
                <input type="date" name="opened_after" id="tejcart-disputes-filter-after" value="<?php echo esc_attr( $current_after ); ?>" />

                <label class="screen-reader-text" for="tejcart-disputes-filter-before"><?php esc_html_e( 'Opened on or before', 'tejcart' ); ?></label>
                <input type="date" name="opened_before" id="tejcart-disputes-filter-before" value="<?php echo esc_attr( $current_before ); ?>" />

                <?php submit_button( __( 'Filter', 'tejcart' ), 'button', 'tejcart-disputes-filter-submit', false ); ?>

                <?php if ( $has_filters ) : ?>
                    <a class="nxc-filter-clear" href="<?php echo esc_url( $this->clear_filters_url( $filters ) ); ?>"><?php esc_html_e( 'Clear', 'tejcart' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * URL that drops the gateway + date filters but preserves the active
     * status tab — used by the "Clear" link in the filter tablenav.
     *
     * @param array<string, mixed> $filters
     */
    private function clear_filters_url( array $filters ): string {
        $args = array( 'page' => self::PAGE_SLUG );
        if ( ! empty( $filters['status'] ) ) {
            $args['status'] = (string) $filters['status'];
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function render_pagination( int $total, int $current_page, array $filters ): void {
        $total_pages = (int) ceil( $total / self::PER_PAGE );

        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(
            sprintf(
                /* translators: %s: total disputes matching the current filter */
                _n( '%s item', '%s items', $total, 'tejcart' ),
                number_format_i18n( $total )
            )
        ) . '</span>';

        if ( $total_pages > 1 ) {
            $base  = add_query_arg(
                array_merge( array( 'page' => self::PAGE_SLUG ), $this->filters_to_query( $filters ) ),
                admin_url( 'admin.php' )
            );
            $links = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%', $base ),
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            if ( $links ) {
                echo '<span class="pagination-links">' . $links . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns escaped markup.
            }
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Map a dispute status to the canonical .tejcart-pill--<tone>
     * variant defined in core's tejcart-admin.css. Using the shared
     * tone palette keeps the dispute queue and the order-edit sidebar
     * card visually aligned with the rest of TejCart admin (Orders,
     * Subscriptions, etc.) and means the badge renders correctly on
     * any screen that already loads the core admin stylesheet — we no
     * longer need to ship a bespoke .tejcart-disputes-badge--<status>
     * style for each new dispute status.
     */
    public static function status_tone( string $status ): string {
        switch ( $status ) {
            case Dispute::STATUS_WON:
                return 'success';
            case Dispute::STATUS_NEEDS_RESPONSE:
            case Dispute::STATUS_LOST:
                return 'error';
            case Dispute::STATUS_OPEN:
                return 'warning';
            case Dispute::STATUS_ACCEPTED:
            case Dispute::STATUS_CLOSED:
                return 'muted';
            case Dispute::STATUS_UNDER_REVIEW:
            default:
                return 'neutral';
        }
    }

    private function status_badge( string $status ): string {
        return sprintf(
            '<span class="tejcart-pill tejcart-pill--%1$s">%2$s</span>',
            esc_attr( self::status_tone( $status ) ),
            esc_html( str_replace( '_', ' ', $status ) )
        );
    }

    /**
     * Normalise a request map into the filter array consumed by
     * {@see Dispute::query()} and {@see Dispute::count()}.
     *
     * @param array<string, mixed> $request Either $_GET or $_POST.
     * @return array<string, mixed>
     */
    private function collect_filters( array $request ): array {
        $allowed_status   = array(
            Dispute::STATUS_OPEN,
            Dispute::STATUS_NEEDS_RESPONSE,
            Dispute::STATUS_UNDER_REVIEW,
            Dispute::STATUS_WON,
            Dispute::STATUS_LOST,
            Dispute::STATUS_ACCEPTED,
            Dispute::STATUS_CLOSED,
        );
        $allowed_gateways = array( 'paypal', 'stripe', 'authorize_net' );

        $filters = array();

        $status = isset( $request['status'] ) ? sanitize_key( wp_unslash( (string) $request['status'] ) ) : '';
        if ( $status && in_array( $status, $allowed_status, true ) ) {
            $filters['status'] = $status;
        }

        $gateway = isset( $request['gateway'] ) ? sanitize_key( wp_unslash( (string) $request['gateway'] ) ) : '';
        if ( $gateway && in_array( $gateway, $allowed_gateways, true ) ) {
            $filters['gateway'] = $gateway;
        }

        if ( ! empty( $request['search'] ) ) {
            $filters['search'] = sanitize_text_field( wp_unslash( (string) $request['search'] ) );
        }
        if ( ! empty( $request['order_id'] ) ) {
            $filters['order_id'] = absint( wp_unslash( (string) $request['order_id'] ) );
        }
        if ( ! empty( $request['opened_after'] ) ) {
            $after = sanitize_text_field( wp_unslash( (string) $request['opened_after'] ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $after ) ) {
                $filters['opened_after'] = $after . ( strlen( $after ) === 10 ? ' 00:00:00' : '' );
            }
        }
        if ( ! empty( $request['opened_before'] ) ) {
            $before = sanitize_text_field( wp_unslash( (string) $request['opened_before'] ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $before ) ) {
                $filters['opened_before'] = $before . ( strlen( $before ) === 10 ? ' 23:59:59' : '' );
            }
        }

        return $filters;
    }

    /**
     * Inverse of {@see collect_filters()} — turn a normalised filter
     * map into a query string fragment for tab / pagination links.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    private function filters_to_query( array $filters ): array {
        $out = array();
        foreach ( $filters as $key => $value ) {
            if ( '' === $value || null === $value ) {
                continue;
            }
            // Strip the time-padding we added so the URL roundtrips
            // back through the date input.
            if ( in_array( $key, array( 'opened_after', 'opened_before' ), true ) ) {
                $out[ $key ] = substr( (string) $value, 0, 10 );
                continue;
            }
            $out[ $key ] = (string) $value;
        }
        return $out;
    }

    private function actor_label(): string {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return __( 'system', 'tejcart' );
        }
        $user = wp_get_current_user();
        if ( is_object( $user ) && ! empty( $user->display_name ) ) {
            // F-MODS-011: sanitise display_name before storing in the actor
            // column. The render layer (event_timeline, render_event_timeline)
            // already wraps in esc_html(), but the stored value must be clean
            // as a defence-in-depth measure. 200-char cap matches REST_API.
            $label = sanitize_text_field( (string) $user->display_name );
            return substr( $label, 0, 200 );
        }
        if ( is_object( $user ) && ! empty( $user->user_login ) ) {
            $label = sanitize_text_field( (string) $user->user_login );
            return substr( $label, 0, 200 );
        }
        return __( 'system', 'tejcart' );
    }

    private function redirect_to_queue(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-disputes' ) );
        exit;
    }

    private function redirect_single( int $dispute_id ): void {
        $args = array(
            'page'    => self::PAGE_SLUG,
            'dispute' => $dispute_id,
        );
        if ( null !== self::$notice ) {
            $args['tejcart_disputes_notice']      = self::$notice['message'];
            $args['tejcart_disputes_notice_type'] = self::$notice['type'];
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
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
