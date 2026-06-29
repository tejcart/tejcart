<?php
/**
 * Action Scheduler - Background task processing via WP Cron.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple background task processing using WP Cron.
 *
 * Provides methods for scheduling one-time and recurring actions,
 * and registers built-in TejCart maintenance tasks.
 */
class Action_Scheduler {
    /**
     * Option key storing the list of `tejcart_every_{N}_seconds` intervals
     * that have been dynamically coined by get_recurrence_name(). The list
     * is replayed by register_cron_schedules() on every request so wp-cron
     * can find the schedule when it reschedules a recurring event after
     * firing.
     */
    private const OPTION_DYNAMIC_INTERVALS = 'tejcart_dynamic_cron_intervals';

    /**
     * The single instance of this class.
     *
     * @var Action_Scheduler|null
     */
    private static $instance = null;

    /**
     * Returns the single instance of this class.
     *
     * @return Action_Scheduler
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the process-wide singleton. Test-substitution seam (#1242).
     *
     * Pass null to reset to lazy construction on the next `instance()`
     * call. Tests / DI overrides can hand in a fake to exercise call
     * sites that resolve through `Action_Scheduler::instance()`.
     *
     * @internal Use in tests and DI overrides only.
     * @param Action_Scheduler|null $instance Instance to install, or null to clear.
     */
    public static function set_instance( ?Action_Scheduler $instance ): void {
        if ( ! defined( 'TEJCART_TESTING' ) || ! TEJCART_TESTING ) { return; }
        self::$instance = $instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Initialize the scheduler: register custom cron intervals and built-in tasks.
     *
     * @return void
     */
    public function init(): void {
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
        add_action( 'init', array( $this, 'schedule_built_in_tasks' ) );
        add_action( 'tejcart_cleanup_sessions', array( $this, 'task_cleanup_sessions' ) );
        add_action( 'tejcart_check_pending_orders', array( $this, 'task_check_pending_orders' ) );
        add_action( 'tejcart_send_low_stock_notifications', array( $this, 'task_send_low_stock_notifications' ) );
        add_action( 'tejcart_cleanup_logs', array( $this, 'task_cleanup_logs' ) );
        add_action( 'tejcart_sweep_scheduled_sales', array( $this, 'task_sweep_scheduled_sales' ) );
        add_action( 'tejcart_cleanup_webhook_option', array( $this, 'task_cleanup_webhook_option' ), 10, 1 );
        add_action( 'tejcart_co_lock_cleanup', array( $this, 'task_co_lock_cleanup' ) );
        add_action( 'tejcart_webhook_deliveries_cleanup', array( $this, 'task_webhook_deliveries_cleanup' ) );
        // I-4 / #1194: daily webhook reconciliation poller. Each gateway
        // listens on `tejcart_webhook_reconcile` and polls its provider
        // for events the inbound webhook handler may have missed
        // (delivery failures past the gateway's retry budget). The hook
        // is a pure dispatch — implementations live in
        // Gateways/<vendor>/<vendor>_Reconciler.
        add_action( 'tejcart_webhook_reconcile', array( $this, 'task_webhook_reconcile' ) );
        // Background image sideload for the product CSV importer's
        // --defer-images mode. Each enqueued job carries a wave of URLs
        // pre-sized to the original import's image-concurrency setting,
        // so one AS tick == one curl_multi wave. Idempotent on retry
        // (the sideloader's prefetch step dedups against
        // _tejcart_source_url, so a re-tick of the same job is a no-op
        // beyond the dedup SELECT).
        add_action( 'tejcart_import_image_sideload', array( $this, 'task_import_image_sideload' ), 10, 2 );
    }

    /**
     * Process a deferred image-sideload job enqueued by the CSV importer.
     *
     * Pure dispatch — delegates to
     * {@see \TejCart\Admin\Product_Import_Export::process_deferred_image_jobs()}
     * so deferred mode shares the gallery-merge / error-formatting code
     * path with the inline flush and can't drift.
     *
     * @param array<int, array<string, mixed>> $jobs        Wave of jobs.
     * @param int                              $concurrency Per-wave parallel-fetch limit.
     */
    public function task_import_image_sideload( $jobs, $concurrency = 0 ): void {
        if ( ! is_array( $jobs ) || empty( $jobs ) ) {
            return;
        }

        $importer = new \TejCart\Admin\Product_Import_Export();
        $result   = $importer->process_deferred_image_jobs( $jobs, (int) $concurrency );

        // Surface persistent failures (e.g. 404'd image URLs) so they're
        // visible in the WP logs / observability stream rather than
        // silently swallowed in background.
        if ( ! empty( $result['error_messages'] ) && function_exists( 'tejcart_logger' ) ) {
            $logger = tejcart_logger();
            if ( $logger && method_exists( $logger, 'warning' ) ) {
                $logger->warning(
                    'Deferred image-sideload completed with errors.',
                    array(
                        'errors' => array_slice( $result['error_messages'], 0, 25 ),
                        'jobs'   => count( $jobs ),
                    )
                );
            }
        }
    }

    /**
     * Daily webhook reconciliation entrypoint. Fires the public
     * `tejcart_webhook_reconcile_run` action so gateways and addons can
     * implement provider-specific catch-up sweeps without touching this
     * scheduler. See PayPal_Reconciler for the canonical example.
     */
    public function task_webhook_reconcile(): void {
        /**
         * Fires once a day so each enabled gateway can poll its provider
         * for missed webhook deliveries. Listeners should be idempotent
         * (the inbound webhook handler is the authoritative one; this is
         * a catch-up sweep, not a replacement) and bounded (process at
         * most a few hundred candidate orders per run).
         *
         * @since 1.0.2
         */
        do_action( 'tejcart_webhook_reconcile_run' );
    }

    /**
     * Hourly cleanup of expired checkout idempotency locks (M-8).
     */
    public function task_co_lock_cleanup(): void {
        if ( class_exists( '\\TejCart\\Checkout\\Checkout' ) ) {
            \TejCart\Checkout\Checkout::sweep_expired_idempotency_locks();
        }
    }

    /**
     * Daily retention pruner for tejcart_webhook_deliveries (I-3).
     *
     * Deletes rows older than `tejcart_webhook_delivery_retention_days`
     * (default 90). Bounded LIMIT keeps the cleanup cheap on a busy
     * store so a backlog drains over multiple cycles.
     */
    public function task_webhook_deliveries_cleanup(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'tejcart_webhook_deliveries';
        /**
         * Filter the retention window (days) for the outbound webhook
         * delivery log. 0 disables the cleanup entirely.
         *
         * @since 1.0.1
         *
         * @param int $days Default 90.
         */
        $days = (int) apply_filters( 'tejcart_webhook_delivery_retention_days', 90 );
        if ( $days <= 0 ) {
            return;
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Maintenance task; table identifier passed via %i placeholder.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < %s LIMIT 5000',
                $table,
                $cutoff
            )
        );
    }

    /**
     * Register custom cron intervals.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public function register_cron_schedules( array $schedules ): array {
        // The `cron_schedules` filter can fire while WordPress is still on
        // `plugins_loaded` (e.g. when a sibling plugin calls
        // wp_get_schedules() during its own bootstrap). Calling __() before
        // the `init` action triggers WP 6.7+'s "translation loading too
        // early" notice, so the labels are translated lazily — the
        // `display` value is only ever surfaced in admin tooling that
        // runs well after init.
        $init_ready = did_action( 'init' );

        if ( ! isset( $schedules['every_fifteen_minutes'] ) ) {
            $schedules['every_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => $init_ready ? __( 'Every Fifteen Minutes', 'tejcart' ) : 'Every Fifteen Minutes',
            );
        }

        if ( ! isset( $schedules['twice_daily'] ) ) {
            $schedules['twice_daily'] = array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => $init_ready ? __( 'Twice Daily', 'tejcart' ) : 'Twice Daily',
            );
        }

        // Replay every dynamic `tejcart_every_{N}_seconds` schedule that has
        // been registered through get_recurrence_name() in the past. Without
        // this, the per-request `add_filter()` inside get_recurrence_name()
        // is only registered on requests that themselves call
        // schedule_recurring() — which a wp-cron request doesn't, so the
        // event fires once and then the reschedule lookup fails with
        // `invalid_schedule` because the named entry no longer exists.
        $dynamic = (array) get_option( self::OPTION_DYNAMIC_INTERVALS, array() );
        foreach ( $dynamic as $interval ) {
            $interval = (int) $interval;
            if ( $interval <= 0 ) {
                continue;
            }
            $name = 'tejcart_every_' . $interval . '_seconds';
            if ( ! isset( $schedules[ $name ] ) ) {
                $schedules[ $name ] = array(
                    'interval' => $interval,
                    'display'  => $init_ready
                        ? sprintf( /* translators: %d: interval in seconds */ __( 'Every %d seconds', 'tejcart' ), $interval )
                        : sprintf( 'Every %d seconds', $interval ),
                );
            }
        }

        return $schedules;
    }

    /**
     * Schedule built-in maintenance tasks if not already scheduled.
     *
     * @return void
     */
    public function schedule_built_in_tasks(): void {
        if ( ! $this->is_scheduled( 'tejcart_cleanup_sessions' ) ) {
            $this->schedule_recurring( time(), HOUR_IN_SECONDS, 'tejcart_cleanup_sessions' );
        }

        if ( ! $this->is_scheduled( 'tejcart_check_pending_orders' ) ) {
            $this->schedule_recurring( time(), 15 * MINUTE_IN_SECONDS, 'tejcart_check_pending_orders' );
        }

        if ( ! $this->is_scheduled( 'tejcart_send_low_stock_notifications' ) ) {
            $this->schedule_recurring( time(), DAY_IN_SECONDS, 'tejcart_send_low_stock_notifications' );
        }

        if ( ! $this->is_scheduled( 'tejcart_cleanup_logs' ) ) {
            // Daily rather than weekly so retention runs frequently
            // enough to keep the inode count down on busy stores. The
            // sweep itself is cheap — `tejcart_log_dir_prune()` walks
            // a flat directory, so this is bounded by the total log
            // file count (capped by `tejcart_log_max_files`).
            $this->schedule_recurring( time(), DAY_IN_SECONDS, 'tejcart_cleanup_logs' );
        }

        if ( ! $this->is_scheduled( 'tejcart_sweep_scheduled_sales' ) ) {
            $this->schedule_recurring( time(), HOUR_IN_SECONDS, 'tejcart_sweep_scheduled_sales' );
        }

        // M-8: hourly cleanup of expired checkout idempotency locks.
        // Belt-and-braces complement to the 0.5% in-request sweep —
        // the sampler is unreliable on low-traffic stores so the
        // hourly job catches the long tail without WP-Cron.
        if ( ! $this->is_scheduled( 'tejcart_co_lock_cleanup' ) ) {
            $this->schedule_recurring( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'tejcart_co_lock_cleanup' );
        }

        // I-3: daily retention pruner for the outbound webhook delivery
        // log so high-throughput stores don't accumulate the table
        // forever. Default 90 days; filterable.
        if ( ! $this->is_scheduled( 'tejcart_webhook_deliveries_cleanup' ) ) {
            $this->schedule_recurring( time() + 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, 'tejcart_webhook_deliveries_cleanup' );
        }

        // I-4 / #1194: daily catch-up sweep so deliveries that the
        // provider couldn't push past its retry budget don't strand
        // orders in `pending` forever. First fires +1h after install /
        // upgrade to avoid colliding with the initial migration burst.
        if ( ! $this->is_scheduled( 'tejcart_webhook_reconcile' ) ) {
            $this->schedule_recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'tejcart_webhook_reconcile' );
        }
    }

    /**
     * Whether the Action Scheduler library is available.
     *
     * When present, scheduling is delegated to AS for higher reliability and
     * throughput; otherwise we fall back to WP Cron.
     *
     * @return bool
     */
    public static function is_action_scheduler_available(): bool {
        return function_exists( 'as_schedule_single_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_unschedule_all_actions' )
            && function_exists( 'as_next_scheduled_action' );
    }

    /**
     * C-1: surface the AS-missing degraded mode to operators via an
     * admin notice, so the silent wp-cron fallback for payment-critical
     * async work is visible at install time rather than discovered when
     * webhook retries miss SLA. Filterable so siblings can suppress the
     * notice when they bundle their own AS copy.
     */
    public function maybe_emit_degraded_notice(): void {
        if ( ! is_admin() || self::is_action_scheduler_available() ) {
            return;
        }
        if ( ! function_exists( 'apply_filters' ) || ! apply_filters( 'tejcart_show_action_scheduler_notice', true ) ) {
            return;
        }
        add_action(
            'admin_notices',
            static function () {
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }
                echo '<div class="notice notice-warning"><p><strong>TejCart:</strong> ';
                echo esc_html__(
                    'The Action Scheduler library is not available. Payment-critical async work (webhook retries, abandoned cart, email queue) is currently using WP-Cron, which is unreliable under load. Install the standalone Action Scheduler plugin to enable durable async processing.',
                    'tejcart'
                );
                echo ' ';
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_doc_link returns pre-escaped HTML.
                echo tejcart_doc_link( 'troubleshooting/notices/action-scheduler', __( 'How to install Action Scheduler', 'tejcart' ) );
                echo '</p></div>';
            }
        );
    }

    /**
     * Schedule a one-time action.
     *
     * @param int    $timestamp Unix timestamp for when to run.
     * @param string $hook      Action hook name.
     * @param array  $args      Optional arguments to pass to the hook.
     * @return bool True on success, false if already scheduled.
     */
    public function schedule_single( int $timestamp, string $hook, array $args = [] ): bool {
        // H-7: when AS is available it dedups internally on (hook, args,
        // group) at insert time, so the previous is_scheduled() pre-check
        // was a TOCTOU that two concurrent enqueuers could pass before
        // either had inserted. Trust AS to dedup. Only apply the
        // pre-check on the wp-cron fallback path, where duplicates are
        // tolerable but cheaper to avoid.
        if ( self::is_action_scheduler_available() ) {
            return false !== as_schedule_single_action( $timestamp, $hook, $args, 'tejcart' );
        }

        if ( $this->is_scheduled( $hook, $args ) ) {
            return false;
        }
        return false !== wp_schedule_single_event( $timestamp, $hook, $args );
    }

    /**
     * Schedule a recurring action.
     *
     * @param int    $timestamp Unix timestamp for first run.
     * @param int    $interval  Recurrence interval in seconds.
     * @param string $hook      Action hook name.
     * @param array  $args      Optional arguments to pass to the hook.
     * @return bool True on success, false if already scheduled.
     */
    public function schedule_recurring( int $timestamp, int $interval, string $hook, array $args = [] ): bool {
        if ( $this->is_scheduled( $hook, $args ) ) {
            return false;
        }

        if ( self::is_action_scheduler_available() ) {
            return false !== as_schedule_recurring_action( $timestamp, $interval, $hook, $args, 'tejcart' );
        }

        $recurrence = $this->get_recurrence_name( $interval );

        return false !== wp_schedule_event( $timestamp, $recurrence, $hook, $args );
    }

    /**
     * Cancel a scheduled action.
     *
     * @param string $hook Action hook name.
     * @param array  $args Optional arguments used when scheduling.
     * @return bool True if unscheduled, false otherwise.
     */
    public function cancel( string $hook, array $args = [] ): bool {
        if ( self::is_action_scheduler_available() ) {
            as_unschedule_all_actions( $hook, $args, 'tejcart' );
            return true;
        }

        $timestamp = wp_next_scheduled( $hook, $args );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook, $args );
            return true;
        }

        wp_clear_scheduled_hook( $hook, $args );
        return true;
    }

    /**
     * Check if an action is currently scheduled.
     *
     * @param string $hook Action hook name.
     * @param array  $args Optional arguments used when scheduling.
     * @return bool True if scheduled, false otherwise.
     */
    public function is_scheduled( string $hook, array $args = [] ): bool {
        if ( self::is_action_scheduler_available() ) {
            return false !== as_next_scheduled_action( $hook, $args, 'tejcart' );
        }

        return false !== wp_next_scheduled( $hook, $args );
    }

    /**
     * Get a list of all pending TejCart background actions.
     *
     * Returns the union of two sources so the Scheduled Actions admin screen
     * is correct regardless of how an event was queued:
     *
     *  - WP-Cron array — events queued via wp_schedule_*_event() directly
     *    (e.g. Net_Terms reminders, Email_Manager sends, PayPal webhook
     *    retries) and everything scheduled on the wp-cron fallback path.
     *  - Action Scheduler — events in the `tejcart` group when the AS library
     *    is present. schedule_single()/schedule_recurring() route there when
     *    available, so without this branch the screen would render empty on
     *    exactly the production stores the degraded-mode notice asks merchants
     *    to set up.
     *
     * @return array<int, array{hook:string,timestamp:int,args:array<array-key, mixed>,schedule:string,interval:int}>
     */
    public function get_pending(): array {
        $pending = $this->get_pending_from_cron();

        if ( self::is_action_scheduler_available() && function_exists( 'as_get_scheduled_actions' ) ) {
            $pending = array_merge( $pending, $this->get_pending_from_action_scheduler() );
        }

        return $pending;
    }

    /**
     * Pending TejCart events stored in the WP-Cron array.
     *
     * @return array<int, array{hook:string,timestamp:int,args:array<array-key, mixed>,schedule:string,interval:int}>
     */
    private function get_pending_from_cron(): array {
        $cron_array = _get_cron_array();
        $pending    = array();

        if ( ! is_array( $cron_array ) ) {
            return $pending;
        }

        foreach ( $cron_array as $timestamp => $hooks ) {
            if ( ! is_array( $hooks ) ) {
                continue;
            }

            foreach ( $hooks as $hook => $events ) {
                if ( strpos( (string) $hook, 'tejcart_' ) !== 0 || ! is_array( $events ) ) {
                    continue;
                }

                foreach ( $events as $event ) {
                    $pending[] = array(
                        'hook'      => (string) $hook,
                        'timestamp' => (int) $timestamp,
                        'args'      => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
                        'schedule'  => isset( $event['schedule'] ) && is_string( $event['schedule'] ) ? $event['schedule'] : 'single',
                        'interval'  => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
                    );
                }
            }
        }

        return $pending;
    }

    /**
     * Pending TejCart-group actions stored by the Action Scheduler library.
     *
     * Normalised into the same shape as get_pending_from_cron() so both
     * sources render through one admin-screen code path.
     *
     * @return array<int, array{hook:string,timestamp:int,args:array<array-key, mixed>,schedule:string,interval:int}>
     */
    private function get_pending_from_action_scheduler(): array {
        $actions = as_get_scheduled_actions(
            array(
                'group'    => 'tejcart',
                'status'   => 'pending',
                'per_page' => 100,
                'orderby'  => 'date',
                'order'    => 'ASC',
            )
        );

        if ( ! is_array( $actions ) ) {
            return array();
        }

        $pending = array();
        foreach ( $actions as $action ) {
            $normalised = $this->normalise_action_scheduler_action( $action );
            if ( null !== $normalised ) {
                $pending[] = $normalised;
            }
        }

        return $pending;
    }

    /**
     * Map a single ActionScheduler_Action onto the pending-action shape.
     *
     * Coded defensively against the AS object API (method_exists / instanceof
     * guards) so a future AS release that renames or drops an accessor
     * degrades to a partial row instead of fatalling the admin screen.
     *
     * @param mixed $action Expected ActionScheduler_Action instance.
     * @return array{hook:string,timestamp:int,args:array<array-key, mixed>,schedule:string,interval:int}|null
     */
    private function normalise_action_scheduler_action( $action ): ?array {
        if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) ) {
            return null;
        }

        $hook = (string) $action->get_hook();
        if ( strpos( $hook, 'tejcart_' ) !== 0 ) {
            return null;
        }

        $args = method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();

        $timestamp    = 0;
        $interval     = 0;
        $is_recurring = false;

        if ( method_exists( $action, 'get_schedule' ) ) {
            $schedule = $action->get_schedule();

            if ( is_object( $schedule ) ) {
                if ( method_exists( $schedule, 'get_date' ) ) {
                    $date = $schedule->get_date();
                    if ( $date instanceof \DateTimeInterface ) {
                        $timestamp = $date->getTimestamp();
                    }
                }

                if ( method_exists( $schedule, 'is_recurring' ) ) {
                    $is_recurring = (bool) $schedule->is_recurring();
                }

                if ( method_exists( $schedule, 'get_recurrence' ) ) {
                    $recurrence = $schedule->get_recurrence();
                    if ( is_numeric( $recurrence ) ) {
                        $interval = (int) $recurrence;
                    }
                }
            }
        }

        if ( $interval > 0 ) {
            $is_recurring = true;
        }

        return array(
            'hook'      => $hook,
            'timestamp' => $timestamp,
            'args'      => $args,
            'schedule'  => $is_recurring ? 'recurring' : 'single',
            'interval'  => $interval,
        );
    }

    /**
     * Execute an action immediately.
     *
     * @param string $hook Action hook name.
     * @param array  $args Arguments to pass to the action.
     * @return void
     */
    public function run_action( string $hook, array $args = [] ): void {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        do_action_ref_array( $hook, $args );
    }

    /**
     * Delete expired sessions from the database.
     *
     * @return void
     */
    public function task_cleanup_sessions(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE session_expiry < %d",
                time()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        update_option(
            'tejcart_last_session_cleanup',
            array(
                'time'    => time(),
                'deleted' => max( 0, (int) $deleted ),
            ),
            false
        );

        if ( $deleted > 0 ) {
            tejcart_log( sprintf( 'Cleaned up %d expired sessions.', $deleted ), 'info' );
        }
    }

    /**
     * Cancel orders that have been stuck in the `pending` status longer
     * than the merchant-configured window.
     *
     * The window is read from the `tejcart_pending_order_timeout` option
     * (in hours; default 24, set at install in `Installer::create_default_options()`).
     * Setting the option to 0 — or returning 0 from the
     * `tejcart_pending_order_timeout_hours` filter — disables the sweep
     * entirely so stores that want to keep stale pendings around for
     * manual review can opt out without unscheduling the cron event.
     *
     * Only orders still in `pending` are cancelled. `on-hold` is left
     * alone because it is normally set by deliberate admin / fraud-check
     * action and should not auto-expire on the same clock.
     *
     * @return void
     */
    public function task_check_pending_orders(): void {
        $hours = (int) get_option( 'tejcart_pending_order_timeout', 24 );

        /**
         * Filter the auto-cancel window (hours) for stale `pending` orders.
         * Return 0 to disable the sweep entirely.
         *
         * @since 1.1.0
         *
         * @param int $hours Hours after which a `pending` order is auto-cancelled.
         */
        $hours = (int) apply_filters( 'tejcart_pending_order_timeout_hours', $hours );

        if ( $hours <= 0 ) {
            return;
        }

        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_orders';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        // F-CORE-005: cap at BATCH_LIMIT rows per cron tick to prevent an
        // unbounded O(N) DB + status-update loop on stores with thousands of
        // stale pending orders. When the batch is full, reschedule a follow-up
        // run immediately so the remaining rows are processed without waiting
        // for the next scheduled interval.
        $batch_limit = (int) apply_filters( 'tejcart_pending_order_batch_limit', 100 );
        $batch_limit = max( 1, $batch_limit );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $pending_orders = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status = %s AND created_at < %s ORDER BY id ASC LIMIT %d",
                'pending',
                $cutoff,
                $batch_limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $pending_orders ) ) {
            return;
        }

        // If we fetched a full batch there may be more rows; schedule an
        // immediate follow-up run so the remainder is processed without
        // waiting for the next scheduled interval.
        if ( count( $pending_orders ) >= $batch_limit ) {
            $this->schedule_single( time() + 1, 'tejcart_check_pending_orders' );
        }

        foreach ( $pending_orders as $row ) {
            $order = new \TejCart\Order\Order( $row->id );
            if ( $order->get_id() && 'pending' === $order->get_status() ) {
                $note = sprintf(
                    /* translators: %d: timeout window in hours */
                    __( 'Automatically cancelled: pending for more than %d hours.', 'tejcart' ),
                    $hours
                );
                $order->update_status( 'cancelled', $note );
                tejcart_log( sprintf( 'Auto-cancelled order #%d (pending > %dh).', $row->id, $hours ), 'info' );
            }
        }
    }

    /**
     * Check product stock levels and send low-stock notifications.
     *
     * @return void
     */
    public function task_send_low_stock_notifications(): void {
        global $wpdb;

        $table     = $wpdb->prefix . 'tejcart_products';
        $threshold = absint( get_option( 'tejcart_low_stock_threshold', 5 ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $low_stock_products = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, name, stock_quantity FROM {$table}
                 WHERE manage_stock = 1 AND stock_quantity IS NOT NULL AND stock_quantity <= %d AND stock_status = %s",
                $threshold,
                'instock'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $low_stock_products ) ) {
            return;
        }

        $low_ids = array();
        foreach ( $low_stock_products as $product ) {
            $low_ids[] = (int) $product->id;
        }

        // Route through Low_Stock_Digest_Email so the scheduled sweep is
        // delivered as a designed HTML email (with the email-log marker
        // headers and admin template-override pipeline) instead of the
        // legacy plain-text `wp_mail()` body that bypassed Abstract_Email.
        $email = new \TejCart\Email\Emails\Low_Stock_Digest_Email();
        $email->trigger( $low_ids );

        tejcart_log( sprintf( 'Sent low-stock notification for %d products.', count( $low_stock_products ) ), 'info' );
    }

    /**
     * Expire products whose scheduled sale window has elapsed.
     *
     * For any product with `_sale_price_dates_to > 0 && < now`, clears the
     * sale price column and resets both schedule fields, so bulk queries
     * like `WHERE sale_price != ''` stay consistent with the on-request
     * schedule check in Abstract_Product::is_sale_active().
     *
     * @return void
     */
    public function task_sweep_scheduled_sales(): void {
        global $wpdb;

        $meta_table     = $wpdb->prefix . 'tejcart_product_meta';
        $products_table = $wpdb->prefix . 'tejcart_products';
        $now            = time();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $expired_ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT product_id FROM {$meta_table}
                 WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > 0 AND CAST( meta_value AS UNSIGNED ) < %d",
                '_sale_price_dates_to',
                $now
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( empty( $expired_ids ) ) {
            return;
        }

        foreach ( $expired_ids as $product_id ) {
            $product_id = (int) $product_id;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update(
                $products_table,
                array( 'sale_price' => '' ),
                array( 'id' => $product_id ),
                array( '%s' ),
                array( '%d' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $wpdb->delete(
                $meta_table,
                array(
                    'product_id' => $product_id,
                    'meta_key'   => '_sale_price_dates_from',
                ),
                array( '%d', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $wpdb->delete(
                $meta_table,
                array(
                    'product_id' => $product_id,
                    'meta_key'   => '_sale_price_dates_to',
                ),
                array( '%d', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        }

        tejcart_log( sprintf( 'Expired scheduled sale on %d product(s).', count( $expired_ids ) ), 'info' );
    }

    /**
     * Delete log files older than 30 days.
     *
     * @return void
     */
    public function task_cleanup_logs(): void {
        // Resolve the log directory through the centralised helper so it
        // honours wp_upload_dir() (and the WP filter chain) instead of
        // assuming WP_CONTENT_DIR — see wp.org plugin guideline on
        // hardcoded paths.
        $log_dir = function_exists( 'tejcart_log_dir' )
            ? tejcart_log_dir()
            : '';

        if ( '' === $log_dir || ! is_dir( $log_dir ) ) {
            return;
        }

        // Delegate to `tejcart_log_dir_prune()` so this scheduled
        // sweep covers EVERY per-channel file (`payment-*.log`,
        // `tax-*.log`, `shipping-*.log`, `discount-*.log`, …) and
        // also rotated `*.log.N` siblings — not just the original
        // `tejcart-*.log` set. It also picks up the configurable
        // retention window (`tejcart_log_retention_days`) and the
        // file-count safety net (`tejcart_log_max_files`).
        $deleted = function_exists( 'tejcart_log_dir_prune' )
            ? tejcart_log_dir_prune( $log_dir )
            : 0;

        if ( $deleted > 0 ) {
            tejcart_log( sprintf( 'Cleaned up %d old log files.', $deleted ), 'info' );
        }
    }

    /**
     * Delete a one-shot guard option created for webhook / capture-lock /
     * checkout idempotency.
     *
     * Three call sites schedule this hook with the option name they own
     * (PayPal_Webhook::claim_event, PayPal_AJAX capture-lock, Checkout
     * idempotency key). Without a registered handler the scheduled
     * events fire and do nothing, leaving stale rows in `wp_options`
     * (autoloaded; growing slowly forever).
     *
     * Defensive: only deletes options whose name starts with one of the
     * known TejCart guard prefixes, so a tampered scheduled-event
     * payload cannot trick this into deleting an unrelated option.
     *
     * @param string $option_name Option to delete.
     * @return void
     */
    public function task_cleanup_webhook_option( string $option_name ): void {
        if ( '' === $option_name ) {
            return;
        }

        $allowed_prefixes = array(
            'tejcart_wh_',           // PayPal_Webhook::claim_event
            'tejcart_pp_cap_lock_',  // PayPal_AJAX capture lock
            'tejcart_co_lock_',      // Checkout::process idempotency
        );

        $allowed = false;
        foreach ( $allowed_prefixes as $prefix ) {
            if ( 0 === strpos( $option_name, $prefix ) ) {
                $allowed = true;
                break;
            }
        }

        if ( ! $allowed ) {
            tejcart_log(
                sprintf( 'Refused to delete unknown guard option: %s', $option_name ),
                'warning'
            );
            return;
        }

        delete_option( $option_name );
    }

    /**
     * Map an interval in seconds to a registered WP Cron schedule name.
     *
     * @param int $interval Interval in seconds.
     * @return string Schedule name.
     */
    private function get_recurrence_name( int $interval ): string {
        $map = array(
            15 * MINUTE_IN_SECONDS => 'every_fifteen_minutes',
            HOUR_IN_SECONDS        => 'hourly',
            12 * HOUR_IN_SECONDS   => 'twice_daily',
            DAY_IN_SECONDS         => 'daily',
            WEEK_IN_SECONDS        => 'weekly',
        );

        if ( isset( $map[ $interval ] ) ) {
            return $map[ $interval ];
        }

        $schedules = wp_get_schedules();
        foreach ( $schedules as $name => $schedule ) {
            if ( (int) $schedule['interval'] === $interval ) {
                return $name;
            }
        }

        $custom_name = 'tejcart_every_' . $interval . '_seconds';

        // Persist the interval so register_cron_schedules() can replay it on
        // subsequent requests (notably wp-cron, which fires the event but
        // doesn't itself call schedule_recurring() to re-coin the schedule).
        $known = (array) get_option( self::OPTION_DYNAMIC_INTERVALS, array() );
        $known = array_map( 'intval', $known );
        if ( ! in_array( $interval, $known, true ) ) {
            $known[] = $interval;
            // Audit M-33 (Core F-014): cap at 50 entries to prevent
            // unbounded wp_options growth from novel interval values.
            if ( count( $known ) > 50 ) {
                $known = array_slice( $known, -50 );
            }
            update_option( self::OPTION_DYNAMIC_INTERVALS, array_values( array_unique( $known ) ), false );
        }

        // F-CORE-017: guard against accumulating one anonymous closure per
        // novel interval value in the global $wp_filter table. Without this
        // guard, a batch loop calling get_recurrence_name() with many
        // different intervals (e.g. WP-CLI) would add a new closure per
        // call and they would never be removed for the lifetime of the request.
        static $registered_filter_intervals = array();
        if ( ! in_array( $interval, $registered_filter_intervals, true ) ) {
            $registered_filter_intervals[] = $interval;
            // Belt-and-braces: also add the schedule to THIS request's filter
            // chain in case wp_schedule_event runs before cron_schedules is
            // next rebuilt. Translation happens lazily for the same
            // `_doing_it_wrong` reason as register_cron_schedules() above.
            add_filter( 'cron_schedules', function ( $schedules ) use ( $custom_name, $interval ) {
                if ( ! isset( $schedules[ $custom_name ] ) ) {
                    $init_ready = did_action( 'init' );
                    $schedules[ $custom_name ] = array(
                        'interval' => $interval,
                        'display'  => $init_ready
                            ? sprintf( /* translators: %d: interval in seconds */ __( 'Every %d seconds', 'tejcart' ), $interval )
                            : sprintf( 'Every %d seconds', $interval ),
                    );
                }
                return $schedules;
            } );
        }

        return $custom_name;
    }
}
