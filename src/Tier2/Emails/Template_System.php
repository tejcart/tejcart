<?php
/**
 * Email template system.
 *
 * Provides editable subject / heading / body for any registered TejCart
 * email plus a send log with resend support. Integrates by:
 *
 *  - Filtering `tejcart_email_subject` to substitute the stored subject.
 *  - Hooking `wp_mail` to log every TejCart email send.
 *  - Exposing AJAX endpoints for preview / resend used by the admin UI.
 *
 * No core email class is modified; existing emails continue to work
 * with their hard-coded defaults if no template row exists.
 *
 * @package TejCart\Tier2\Emails
 */

declare( strict_types=1 );

namespace TejCart\Tier2\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Template_System {
    public static function init() {
        add_filter( 'tejcart_email_subject',      array( __CLASS__, 'filter_subject' ),      10, 2 );
        add_filter( 'tejcart_email_heading',      array( __CLASS__, 'filter_heading' ),      10, 2 );
        add_filter( 'tejcart_email_content_html', array( __CLASS__, 'filter_content_html' ), 10, 2 );
        // `tejcart_email_styles` listener removed (audit 06 F-L3) — the
        // pass-through filter never modified the styles. Reinstate when
        // a per-template style_overrides column lands.

        add_action( 'phpmailer_init', array( __CLASS__, 'log_outgoing' ), 99 );

        add_action( 'wp_ajax_tejcart_email_preview', array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_tejcart_email_resend',  array( __CLASS__, 'ajax_resend' ) );
        add_action( 'wp_ajax_tejcart_email_save',    array( __CLASS__, 'ajax_save' ) );
    }

    /**
     * Look up a stored template row by email_id.
     */
    public static function get_template( $email_id ) {
        static $cache = array();
        if ( array_key_exists( $email_id, $cache ) ) {
            return $cache[ $email_id ];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_email_templates';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE email_id = %s", $email_id ),
            ARRAY_A
        );
        $cache[ $email_id ] = $row ?: null;
        return $cache[ $email_id ];
    }

    /**
     * Persist a template (insert or update).
     */
    public static function save_template( $email_id, array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_email_templates';
        $row   = array(
            'email_id' => sanitize_key( $email_id ),
            // Subject lines travel as plain-text SMTP headers — wp_kses_post
            // would let `<strong>` etc. leak into the inbox preview where
            // some MTAs render the markup literally (audit 06 F-L2).
            'subject'  => isset( $data['subject'] ) ? sanitize_text_field( (string) $data['subject'] ) : '',
            'heading'  => isset( $data['heading'] ) ? wp_kses_post( $data['heading'] ) : '',
            'body'     => isset( $data['body'] )    ? wp_kses_post( $data['body'] )    : '',
            'enabled'  => ! empty( $data['enabled'] ) ? 1 : 0,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id FROM {$table} WHERE email_id = %s", $row['email_id'] )
        );
        if ( $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update( $table, $row, array( 'id' => (int) $existing ), array( '%s', '%s', '%s', '%s', '%d' ), array( '%d' ) );
            return (int) $existing;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%d' ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Filter to override the subject from the stored template.
     */
    public static function filter_subject( $subject, $email ) {
        if ( ! is_object( $email ) || ! isset( $email->id ) && ! method_exists( $email, 'get_id' ) ) {
            if ( ! method_exists( $email, 'get_id' ) ) {
                return $subject;
            }
        }
        $email_id = self::resolve_email_id( $email );
        if ( ! $email_id ) {
            return $subject;
        }
        $row = self::get_template( $email_id );
        if ( $row && ! empty( $row['subject'] ) ) {
            return $row['subject'];
        }
        return $subject;
    }

    /**
     * Filter to override the heading from the stored template.
     */
    public static function filter_heading( $heading, $email ) {
        $email_id = self::resolve_email_id( $email );
        if ( ! $email_id ) {
            return $heading;
        }
        $row = self::get_template( $email_id );
        if ( $row && isset( $row['heading'] ) && '' !== (string) $row['heading'] ) {
            return (string) $row['heading'];
        }
        return $heading;
    }

    /**
     * Filter to override the rendered HTML body from the stored template.
     *
     * Returns the stored body when present; otherwise falls through to
     * the template-rendered output. The stored body is run through
     * `wp_kses_post` on save (see save_template()) so it is already
     * sanitised when read back here.
     */
    public static function filter_content_html( $html, $email ) {
        $email_id = self::resolve_email_id( $email );
        if ( ! $email_id ) {
            return $html;
        }
        $row = self::get_template( $email_id );
        if ( $row && isset( $row['body'] ) && '' !== (string) $row['body'] ) {
            return (string) $row['body'];
        }
        return $html;
    }

    /**
     * Pass-through filter retained for backwards source compatibility;
     * the `tejcart_email_styles` listener is no longer registered (see
     * `init()`).
     *
     * @param string     $styles Current style block.
     * @param mixed|null $email  Email instance (unused).
     * @return string
     */
    public static function filter_styles( $styles, $email = null ) {
        return $styles;
    }

    /**
     * Resolve an email's id via reflection (since `$id` is protected).
     *
     * Reflection is expensive on hot paths (every outbound email). We cache
     * the resolved id keyed on class name plus the id returned by get_id()
     * when available — this lets identical email classes hit the cache on
     * subsequent sends in the same request.
     */
    private static function resolve_email_id( $email ) {
        static $cache = array();

        if ( ! is_object( $email ) ) {
            return '';
        }

        $class = get_class( $email );
        if ( isset( $cache[ $class ] ) ) {
            return $cache[ $class ];
        }

        if ( method_exists( $email, 'get_id' ) ) {
            try {
                $id = (string) $email->get_id();
                if ( '' !== $id ) {
                    $cache[ $class ] = $id;
                    return $id;
                }
            } catch ( \Throwable $e ) {
                unset( $e ); // Best-effort: fall through to the reflection path.
            }
        }

        try {
            $ref = new \ReflectionClass( $email );
            if ( ! $ref->hasProperty( 'id' ) ) {
                return $cache[ $class ] = '';
            }
            $prop = $ref->getProperty( 'id' );
            // ReflectionProperty reads protected members directly since PHP 8.1;
            // setAccessible() is a no-op there and is deprecated as of PHP 8.5.
            $id = (string) $prop->getValue( $email );
            if ( '' !== $id ) {
                $cache[ $class ] = $id;
            }
            return $id;
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Marker header added to every TejCart outbound email so the log hook
     * can reliably tell our mail from a neighbouring plugin's wp_mail().
     */
    const MAIL_MARKER_HEADER = 'X-TejCart-Mail';

    /**
     * Hook into PHPMailer just before send to record an audit row.
     *
     * Only records messages that carry our X-TejCart-Mail marker header.
     * Earlier versions used a substring match on the body, which could
     * accidentally capture unrelated mail that happened to mention the
     * word "tejcart"; the marker header is unambiguous.
     */
    public static function log_outgoing( $phpmailer ) {
        $has_marker  = false;
        $email_id    = '';
        $object_type = null;
        $object_id   = null;

        if ( method_exists( $phpmailer, 'getCustomHeaders' ) ) {
            foreach ( (array) $phpmailer->getCustomHeaders() as $header ) {
                if ( ! isset( $header[0], $header[1] ) ) {
                    continue;
                }
                $name  = (string) $header[0];
                $value = (string) $header[1];

                if ( 0 === strcasecmp( $name, self::MAIL_MARKER_HEADER ) ) {
                    $has_marker = true;
                    // Marker value carries the email id when set by Abstract_Email::send().
                    if ( '' === $email_id && '1' !== $value ) {
                        $email_id = $value;
                    }
                } elseif ( 0 === strcasecmp( $name, 'X-TejCart-Email-Id' ) ) {
                    $email_id = $value;
                } elseif ( 0 === strcasecmp( $name, 'X-TejCart-Object-Type' ) ) {
                    $object_type = $value;
                } elseif ( 0 === strcasecmp( $name, 'X-TejCart-Object-Id' ) ) {
                    $object_id = (int) $value;
                }
            }
        }

        if ( ! $has_marker ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_email_log';
        $tos   = method_exists( $phpmailer, 'getToAddresses' ) ? (array) $phpmailer->getToAddresses() : array();
        foreach ( $tos as $to ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->insert(
                $table,
                array(
                    'email_id'    => '' !== $email_id ? sanitize_key( $email_id ) : 'auto',
                    'recipient'   => isset( $to[0] ) ? $to[0] : '',
                    'subject'     => (string) $phpmailer->Subject,
                    'object_type' => $object_type ? sanitize_key( $object_type ) : null,
                    'object_id'   => $object_id ? (int) $object_id : null,
                    'status'      => 'sent',
                    'error'       => null,
                ),
                array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
        }
    }

    private static function check_admin() {
        check_ajax_referer( 'tejcart_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }
    }

    public static function ajax_save() {
        self::check_admin();
        // Nonce verified by check_admin() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $email_id = isset( $_POST['email_id'] ) ? sanitize_key( wp_unslash( $_POST['email_id'] ) ) : '';
        if ( ! $email_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing email_id.', 'tejcart' ) ), 400 );
        }
        self::save_template( $email_id, array(
            'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
            'heading' => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '',
            'body'    => isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )           : '',
            'enabled' => ! empty( $_POST['enabled'] ),
        ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        wp_send_json_success();
    }

    public static function ajax_preview() {
        self::check_admin();
        // Nonce verified by check_admin() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $email_id = isset( $_GET['email_id'] ) ? sanitize_key( wp_unslash( $_GET['email_id'] ) ) : '';
        $manager  = function_exists( 'tejcart' ) ? tejcart()->emails() : null;
        if ( ! $manager ) {
            wp_send_json_error( array( 'message' => __( 'Email manager unavailable.', 'tejcart' ) ), 500 );
        }
        $emails = $manager->get_emails();
        if ( ! isset( $emails[ $email_id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown email.', 'tejcart' ) ), 404 );
        }
        $email = $emails[ $email_id ];
        $html  = method_exists( $email, 'get_content_html' ) ? $email->get_content_html() : '';
        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function ajax_resend() {
        self::check_admin();
        // Nonce verified by check_admin() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $log_id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
        if ( ! $log_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing log id.', 'tejcart' ) ), 400 );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_email_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'tejcart' ) ), 404 );
        }
        $manager = function_exists( 'tejcart' ) ? tejcart()->emails() : null;
        if ( ! $manager || ! method_exists( $manager, 'send' ) ) {
            wp_send_json_error( array( 'message' => __( 'Email manager unavailable.', 'tejcart' ) ), 500 );
        }

        if ( ! empty( $row['object_type'] ) && 'order' === $row['object_type'] && ! empty( $row['object_id'] ) ) {
            $order = new \TejCart\Order\Order( (int) $row['object_id'] );
            if ( $order->get_id() ) {
                $manager->send( $row['email_id'], array( $order->get_id(), $order ) );
                wp_send_json_success();
            }
        }
        wp_send_json_error( array( 'message' => __( 'Cannot resend this email.', 'tejcart' ) ), 400 );
    }

    /**
     * Public helper used by other modules to record a sent email
     * (preferred over relying on phpmailer_init).
     */
    public static function record( $email_id, $recipient, $subject, $object_type = null, $object_id = null, $status = 'sent', $error = null ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->insert(
            $wpdb->prefix . 'tejcart_email_log',
            array(
                'email_id'    => sanitize_key( $email_id ),
                'recipient'   => sanitize_email( $recipient ),
                'subject'     => (string) $subject,
                'object_type' => $object_type ? sanitize_key( $object_type ) : null,
                'object_id'   => $object_id ? (int) $object_id : null,
                'status'      => $status,
                'error'       => $error ? (string) $error : null,
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        return (int) $wpdb->insert_id;
    }
}
