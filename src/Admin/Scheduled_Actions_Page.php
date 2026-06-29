<?php
/**
 * Scheduled Actions admin screen.
 *
 * Lists pending TejCart cron events and lets admins run or cancel
 * individual actions. Embedded under Settings → Advanced → Scheduled
 * Actions, mirroring the common "Status → Scheduled Actions"
 * pattern.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Core\Action_Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scheduled_Actions_Page {

    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    public function handle_actions(): void {
        if ( empty( $_POST['tejcart_scheduled_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_scheduled_action' ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['tejcart_scheduled_action'] ) );
        $hook   = isset( $_POST['tejcart_action_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_action_hook'] ) ) : '';

        if ( '' === $hook || strpos( $hook, 'tejcart_' ) !== 0 ) {
            return;
        }

        $scheduler = Action_Scheduler::instance();

        // The POSTed `tejcart_action_args` JSON is *attacker-influenceable* (a
        // manage_options user with a valid nonce, but the field is form data),
        // so we never pass it verbatim to run_action()/cancel() (S-M3).
        // Instead we use it only to *select* which currently-pending instance
        // of $hook is targeted, then carry that instance's server-stored args.
        // This guarantees "Run Now" invokes the handler with exactly the
        // parameters the event was scheduled with — e.g.
        // tejcart_cleanup_webhook_option( string $option_name ) and
        // tejcart_net_terms_payment_reminder( int $order_id ) are scheduled
        // *with* args, and running them with forged args could touch an
        // arbitrary option/order. If no pending event matches, there is
        // nothing legitimate to run or cancel.
        $requested_args = array();
        if ( isset( $_POST['tejcart_action_args'] ) ) {
            $decoded = json_decode( wp_unslash( (string) $_POST['tejcart_action_args'] ), true );
            if ( is_array( $decoded ) ) {
                $requested_args = $decoded;
            }
        }

        $matched_event = null;
        foreach ( $scheduler->get_pending() as $event ) {
            if ( ! is_array( $event ) || ( $event['hook'] ?? '' ) !== $hook ) {
                continue;
            }
            $event_args = ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array();
            // Match the specific instance by its stored args. The
            // canonical-JSON comparison tolerates key-order differences
            // between the POSTed selector and the stored event.
            if ( wp_json_encode( $event_args ) === wp_json_encode( $requested_args ) ) {
                $matched_event = $event_args;
                break;
            }
        }

        if ( null === $matched_event ) {
            set_transient( 'tejcart_scheduled_actions_notice', array(
                'type'    => 'error',
                'message' => __( 'That scheduled action is no longer pending.', 'tejcart' ),
            ), 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=scheduled-actions' ) );
            exit;
        }

        // Server-sourced args of the actually-pending event.
        $args = $matched_event;

        $result = array( 'success' => false, 'message' => '' );

        switch ( $action ) {
            case 'run':
                $scheduler->run_action( $hook, $args );
                $result = array(
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %s: hook name */
                        __( 'Action "%s" executed successfully.', 'tejcart' ),
                        $hook
                    ),
                );
                break;

            case 'cancel':
                $scheduler->cancel( $hook, $args );
                $result = array(
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %s: hook name */
                        __( 'Action "%s" cancelled.', 'tejcart' ),
                        $hook
                    ),
                );
                break;
        }

        if ( '' !== $result['message'] ) {
            set_transient( 'tejcart_scheduled_actions_notice', array(
                'type'    => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
            ), 30 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=scheduled-actions' ) );
        exit;
    }

    /**
     * @param bool $embedded When true, skip the outer wrap for composition
     *                       inside the Settings → Advanced tab.
     */
    public function render( bool $embedded = false ): void {
        $notice = get_transient( 'tejcart_scheduled_actions_notice' );
        if ( $notice ) {
            delete_transient( 'tejcart_scheduled_actions_notice' );
        }

        $scheduler = Action_Scheduler::instance();
        $actions   = $scheduler->get_pending();

        usort( $actions, static function ( array $a, array $b ): int {
            return $a['timestamp'] <=> $b['timestamp'];
        } );
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Scheduled Actions', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'View and manage background tasks scheduled by TejCart.', 'tejcart' ); ?></p>
                </div>
            </div>
        <?php endif; ?>

            <?php if ( is_array( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! Action_Scheduler::is_action_scheduler_available() ) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php esc_html_e( 'The Action Scheduler library is not installed. TejCart is using WP-Cron as a fallback. For production stores, consider installing Action Scheduler for more reliable background processing.', 'tejcart' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $actions ) ) : ?>
                <div class="tejcart-empty-state">
                    <p><?php esc_html_e( 'No scheduled actions found.', 'tejcart' ); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped tejcart-scheduled-actions-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Hook', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Schedule', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Next Run', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Interval', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Arguments', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'tejcart' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $actions as $action ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $action['hook'] ); ?></code></td>
                                <td><?php echo esc_html( $this->format_schedule( $action['schedule'] ) ); ?></td>
                                <td><?php echo esc_html( $this->format_next_run( $action['timestamp'] ) ); ?></td>
                                <td><?php echo esc_html( $this->format_interval( (int) $action['interval'] ) ); ?></td>
                                <td>
                                    <?php if ( ! empty( $action['args'] ) ) : ?>
                                        <code class="tejcart-action-args"><?php echo esc_html( wp_json_encode( $action['args'], JSON_PRETTY_PRINT ) ); ?></code>
                                    <?php else : ?>
                                        <span class="tejcart-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'tejcart_scheduled_action' ); ?>
                                        <input type="hidden" name="tejcart_action_hook" value="<?php echo esc_attr( $action['hook'] ); ?>" />
                                        <input type="hidden" name="tejcart_action_args" value="<?php echo esc_attr( (string) wp_json_encode( $action['args'] ) ); ?>" />
                                        <button type="submit" name="tejcart_scheduled_action" value="run" class="button button-small">
                                            <?php esc_html_e( 'Run Now', 'tejcart' ); ?>
                                        </button>
                                        <button type="submit" name="tejcart_scheduled_action" value="cancel" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Cancel this scheduled action?', 'tejcart' ) ); ?>');">
                                            <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="tejcart-scheduled-actions-footer" style="margin-top: 16px;">
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of scheduled actions */
                        esc_html__( '%d scheduled action(s) found.', 'tejcart' ),
                        count( $actions )
                    );
                    ?>
                </p>
            </div>

        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    private function format_schedule( string $schedule ): string {
        $labels = array(
            'single'               => __( 'One-time', 'tejcart' ),
            'recurring'            => __( 'Recurring', 'tejcart' ),
            'hourly'               => __( 'Hourly', 'tejcart' ),
            'daily'                => __( 'Daily', 'tejcart' ),
            'twice_daily'          => __( 'Twice daily', 'tejcart' ),
            'weekly'               => __( 'Weekly', 'tejcart' ),
            'every_fifteen_minutes' => __( 'Every 15 min', 'tejcart' ),
        );

        return $labels[ $schedule ] ?? $schedule;
    }

    private function format_next_run( int $timestamp ): string {
        $now  = time();
        $diff = $timestamp - $now;

        $date_str = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
        if ( false === $date_str ) {
            $date_str = gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        if ( $diff <= 0 ) {
            /* translators: %s: formatted date */
            return sprintf( __( '%s (overdue)', 'tejcart' ), $date_str );
        }

        return $date_str;
    }

    private function format_interval( int $seconds ): string {
        if ( 0 === $seconds ) {
            return '—';
        }

        if ( $seconds < 3600 ) {
            $minutes = (int) round( $seconds / 60 );
            /* translators: %d: number of minutes */
            return sprintf( _n( '%d minute', '%d minutes', $minutes, 'tejcart' ), $minutes );
        }

        if ( $seconds < 86400 ) {
            $hours = (int) round( $seconds / 3600 );
            /* translators: %d: number of hours */
            return sprintf( _n( '%d hour', '%d hours', $hours, 'tejcart' ), $hours );
        }

        $days = (int) round( $seconds / 86400 );
        /* translators: %d: number of days */
        return sprintf( _n( '%d day', '%d days', $days, 'tejcart' ), $days );
    }
}
