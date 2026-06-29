<?php
/**
 * TejCart log viewer admin page.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the TejCart log files with level filtering.
 *
 * Log lines are written by tejcart_log() in the standard format:
 *   YYYY-MM-DDTHH:MM:SS+00:00 LEVEL message [{context-json}]
 *
 * Files live under `{uploads}/tejcart-logs/` and are named
 * `{source}-{YYYY-MM-DD}-{wp_hash}.log`. This viewer:
 *   - lists the available log files newest-first,
 *   - honours the `level` query-arg to filter entries server-side,
 *   - and offers a secure "Clear log" button that deletes the chosen file.
 */
class Log_Viewer {
    /**
     * Supported PSR-3 log levels (used for filter dropdown).
     *
     * @var string[]
     */
    private const LEVELS = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

    /**
     * Hook the clear-log handler on `admin_init` so the redirect fires
     * before any admin HTML output has started.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'maybe_handle_clear' ) );
    }

    /**
     * Render the log viewer admin page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and `<h1>` for composition inside another admin
     *                      screen (Settings → Advanced → Logs).
     * @return void
     */
    public function render( bool $embedded = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view logs.', 'tejcart' ) );
        }

        $log_dir = $this->get_log_dir();

        $files = $this->list_log_files( $log_dir );

        // F-PCA-013: Thread a shared nonce through the filter form and the
        // clear-action link so all state-reading and state-changing operations
        // on this page use the same CSRF token. The file and level params are
        // read-only, but sharing the nonce makes the intent explicit and
        // removes the need for the phpcs:ignore suppression comments.
        $nonce_action   = 'tejcart_log_viewer';
        $viewer_nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $nonce_verified = wp_verify_nonce( $viewer_nonce, $nonce_action );

        $selected_file  = '';
        $requested_file = $nonce_verified && isset( $_GET['file'] ) ? sanitize_file_name( (string) wp_unslash( $_GET['file'] ) ) : '';

        if ( $requested_file && in_array( $requested_file, $files, true ) ) {
            $selected_file = $requested_file;
        } elseif ( ! empty( $files ) ) {
            $selected_file = reset( $files );
        }

        $level = $nonce_verified && isset( $_GET['level'] ) ? strtolower( sanitize_key( (string) wp_unslash( $_GET['level'] ) ) ) : '';
        if ( $level && ! in_array( $level, self::LEVELS, true ) ) {
            $level = '';
        }

        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <h1><?php esc_html_e( 'Logs', 'tejcart' ); ?></h1>
        <?php endif; ?>

            <?php if ( 'off' === strtolower( (string) get_option( 'tejcart_log_level', 'error' ) ) ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Logging is set to Off — no new log entries will be written. Raise the log level under Settings → Advanced.', 'tejcart' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $files ) ) : ?>
                <p><?php esc_html_e( 'No log files found yet.', 'tejcart' ); ?></p>
                <?php return; ?>
            <?php endif; ?>

            <form method="get" class="tejcart-log-filters" style="display:flex;gap:12px;align-items:flex-end;margin-bottom:20px;">
                <input type="hidden" name="page" value="tejcart-settings" />
                <input type="hidden" name="tab" value="advanced" />
                <input type="hidden" name="section" value="logs" />
                <?php wp_nonce_field( $nonce_action ); ?>

                <div>
                    <label for="tejcart-log-file"><strong><?php esc_html_e( 'Log file', 'tejcart' ); ?></strong></label><br />
                    <select id="tejcart-log-file" name="file">
                        <?php foreach ( $files as $file ) : ?>
                            <option value="<?php echo esc_attr( $file ); ?>" <?php selected( $file, $selected_file ); ?>>
                                <?php echo esc_html( $file ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="tejcart-log-level"><strong><?php esc_html_e( 'Level', 'tejcart' ); ?></strong></label><br />
                    <select id="tejcart-log-level" name="level">
                        <option value=""><?php esc_html_e( 'All levels', 'tejcart' ); ?></option>
                        <?php foreach ( self::LEVELS as $lvl ) : ?>
                            <option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $lvl, $level ); ?>>
                                <?php echo esc_html( ucfirst( $lvl ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'tejcart' ); ?></button>
                </div>

                <?php
                $clear_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'page'                  => 'tejcart-settings',
                            'tab'                   => 'advanced',
                            'section'               => 'logs',
                            'tejcart_log_action'    => 'clear',
                            'file'                  => $selected_file,
                        ),
                        admin_url( 'admin.php' )
                    ),
                    'tejcart_clear_log'
                );
                ?>
                <div style="margin-left:auto;">
                    <a href="<?php echo esc_url( $clear_url ); ?>" class="button button-secondary"
                       onclick="return confirm('<?php echo esc_js( __( 'Delete this log file? This cannot be undone.', 'tejcart' ) ); ?>');">
                        <?php esc_html_e( 'Delete log file', 'tejcart' ); ?>
                    </a>
                </div>
            </form>

            <?php $this->render_log_body( $log_dir, $selected_file, $level ); ?>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Resolve the log directory (same path used by tejcart_log()).
     *
     * @return string
     */
    private function get_log_dir(): string {
        if ( function_exists( 'tejcart_log_dir' ) ) {
            return tejcart_log_dir();
        }

        // Defensive fallback if the global helper is somehow unavailable.
        // Resolve through the uploads API only — never a hardcoded
        // wp-content path (breaks on custom UPLOADS / multisite roots).
        if ( function_exists( 'wp_get_upload_dir' ) ) {
            $uploads = wp_get_upload_dir();
        } elseif ( function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir( null, false );
        } else {
            $uploads = array();
        }

        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
            return '';
        }

        return trailingslashit( $uploads['basedir'] ) . 'tejcart-logs/';
    }

    /**
     * Enumerate log files in the log directory, newest first.
     *
     * @param string $log_dir Absolute log directory path.
     * @return string[] Base filenames like "tejcart-2025-12-31-abc123.log".
     */
    private function list_log_files( string $log_dir ): array {
        if ( ! is_dir( $log_dir ) ) {
            return array();
        }

        $entries = glob( $log_dir . '*.log' ) ?: array();
        usort(
            $entries,
            static function ( $a, $b ) {
                return filemtime( $b ) <=> filemtime( $a );
            }
        );

        return array_map( 'basename', $entries );
    }

    /**
     * Handle the delete-log action.
     *
     * Hooked on `admin_init` so the redirect runs before any output.
     * Must remain public — WordPress invokes it via the action callback.
     *
     * @return void
     */
    public function maybe_handle_clear(): void {
        if ( ! isset( $_GET['tejcart_log_action'] ) || 'clear' !== $_GET['tejcart_log_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'tejcart_clear_log' );

        $file = isset( $_GET['file'] ) ? sanitize_file_name( (string) wp_unslash( $_GET['file'] ) ) : '';
        if ( '' === $file ) {
            return;
        }

        $log_dir = $this->get_log_dir();
        $path    = $log_dir . $file;

        if ( 0 !== strpos( realpath( $path ) ?: '', realpath( $log_dir ) ?: '' ) ) {
            return;
        }

        if ( file_exists( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $path );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=logs&cleared=1' ) );
        exit;
    }

    /**
     * Render the contents of a single log file with the chosen level filter.
     *
     * @param string $log_dir Log directory.
     * @param string $file    Base filename of the selected log.
     * @param string $level   Level filter (empty for all).
     * @return void
     */
    private function render_log_body( string $log_dir, string $file, string $level ): void {
        $path = $log_dir . $file;

        if ( '' === $file || ! is_readable( $path ) ) {
            echo '<p>' . esc_html__( 'The selected log file could not be read.', 'tejcart' ) . '</p>';
            return;
        }

        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $lines = array_reverse( $lines );

        // Three accepted formats:
        //   Text (default):  "YYYY-MM-DDTHH:MM:SS+00:00 LEVEL message [{json}]"
        //   JSON Lines:      "{"ts":"…","level":"…","msg":"…","context":{…}}"
        //   Legacy:          "[YYYY-MM-DD HH:MM:SS] [level] message"
        $text_pattern = '/(?:^\S+\s+|\[)(' . implode( '|', self::LEVELS ) . ')(?:\]|\s)/i';

        echo '<div class="tejcart-log-viewer">';

        $rendered = 0;
        foreach ( $lines as $line ) {
            if ( '' !== $level ) {
                $line_level = $this->extract_level( $line, $text_pattern );
                if ( '' === $line_level || $line_level !== $level ) {
                    continue;
                }
            }

            echo '<div class="tejcart-log-line">' . esc_html( $line ) . '</div>';
            $rendered++;
        }

        if ( 0 === $rendered ) {
            echo '<em>' . esc_html__( 'No matching log entries.', 'tejcart' ) . '</em>';
        }

        echo '</div>';
    }

    /**
     * Extract the PSR-3 level from a single log line. Handles both the
     * text format ("YYYY-MM-DDTHH:MM:SS+00:00 LEVEL message …") and the
     * JSON Lines format ({"ts":"…","level":"…","msg":"…"}).
     *
     * @param string $line          One log line, unescaped.
     * @param string $text_pattern  Pre-built regex for the text format.
     * @return string Lower-case level name, or '' when none is detected.
     */
    private function extract_level( string $line, string $text_pattern ): string {
        $trim = ltrim( $line );
        if ( '' !== $trim && '{' === $trim[0] ) {
            // JSON Lines — fast path: decode the line and read `level`.
            $decoded = json_decode( $trim, true );
            if ( is_array( $decoded ) && isset( $decoded['level'] ) ) {
                $level = strtolower( (string) $decoded['level'] );
                return in_array( $level, self::LEVELS, true ) ? $level : '';
            }
        }
        if ( preg_match( $text_pattern, $line, $match ) ) {
            return strtolower( $match[1] );
        }
        return '';
    }
}
