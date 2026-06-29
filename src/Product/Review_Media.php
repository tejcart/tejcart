<?php
/**
 * Review Media — photo and video attachments for product reviews.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Review_Media {

    const TABLE = 'tejcart_review_media';

    const TYPE_PHOTO = 'photo';
    const TYPE_VIDEO = 'video';

    const SOURCE_UPLOAD = 'upload';
    const SOURCE_EMBED  = 'embed';

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    const MAX_PHOTOS_PER_REVIEW = 5;
    const MAX_VIDEOS_PER_REVIEW = 2;
    const MAX_PHOTO_SIZE_BYTES  = 5 * 1024 * 1024; // 5 MB
    const MAX_VIDEO_SIZE_BYTES  = 50 * 1024 * 1024; // 50 MB

    const ALLOWED_PHOTO_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );
    const ALLOWED_VIDEO_TYPES = array( 'video/mp4', 'video/webm' );

    /**
     * Regex patterns for supported embed providers.
     */
    private const YOUTUBE_PATTERN = '#^https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{11})(?:&|$)#i';
    private const VIMEO_PATTERN   = '#^https?://(?:www\.)?vimeo\.com/(\d+)(?:\?|$|/)#i';

    /**
     * Store uploaded photo media for a review.
     *
     * @param int   $comment_id Review comment ID.
     * @param array $files      $_FILES array for the photos input.
     * @return int[] Created media row IDs.
     */
    public static function process_photos( int $comment_id, array $files ): array {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $names  = isset( $files['name'] ) ? (array) $files['name'] : array();
        $tmp    = isset( $files['tmp_name'] ) ? (array) $files['tmp_name'] : array();
        $types  = isset( $files['type'] ) ? (array) $files['type'] : array();
        $sizes  = isset( $files['size'] ) ? (array) $files['size'] : array();
        $errors = isset( $files['error'] ) ? (array) $files['error'] : array();

        $count     = min( count( $names ), self::MAX_PHOTOS_PER_REVIEW );
        $media_ids = array();

        for ( $i = 0; $i < $count; $i++ ) {
            if ( empty( $tmp[ $i ] ) || ! empty( $errors[ $i ] ) ) {
                continue;
            }
            if ( (int) $sizes[ $i ] > self::MAX_PHOTO_SIZE_BYTES ) {
                continue;
            }

            $finfo     = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : null;
            $real_type = $finfo ? finfo_file( $finfo, $tmp[ $i ] ) : ( $types[ $i ] ?? '' );
            if ( $finfo ) {
                finfo_close( $finfo );
            }

            if ( ! in_array( $real_type, self::ALLOWED_PHOTO_TYPES, true ) ) {
                continue;
            }

            $file_array = array(
                'name'     => sanitize_file_name( $names[ $i ] ),
                'tmp_name' => $tmp[ $i ],
                'type'     => $real_type,
                'size'     => (int) $sizes[ $i ],
                'error'    => 0,
            );

            $_FILES['tejcart_review_media_single'] = $file_array;

            $attachment_id = media_handle_upload( 'tejcart_review_media_single', 0, array(), array(
                'test_form' => false,
                'mimes'     => array(
                    'jpg|jpeg' => 'image/jpeg',
                    'png'      => 'image/png',
                    'webp'     => 'image/webp',
                ),
            ) );

            unset( $_FILES['tejcart_review_media_single'] );

            if ( ! is_wp_error( $attachment_id ) ) {
                $media_id = self::insert_media( $comment_id, array(
                    'media_type'    => self::TYPE_PHOTO,
                    'source_type'   => self::SOURCE_UPLOAD,
                    'attachment_id' => $attachment_id,
                    'sort_order'    => $i,
                ) );
                if ( $media_id ) {
                    $media_ids[] = $media_id;
                }
            }
        }

        return $media_ids;
    }

    /**
     * Store a video embed URL for a review.
     *
     * @param int    $comment_id Review comment ID.
     * @param string $url        YouTube or Vimeo URL.
     * @param int    $sort_order Sort position.
     * @return int|false Media row ID or false on failure.
     */
    public static function add_video_embed( int $comment_id, string $url, int $sort_order = 0 ) {
        $url = esc_url_raw( trim( $url ) );
        if ( ! self::is_valid_embed_url( $url ) ) {
            return false;
        }

        return self::insert_media( $comment_id, array(
            'media_type'  => self::TYPE_VIDEO,
            'source_type' => self::SOURCE_EMBED,
            'embed_url'   => $url,
            'sort_order'  => $sort_order,
        ) );
    }

    /**
     * Insert a media row into the review media table.
     *
     * @param int   $comment_id Review comment ID.
     * @param array $data       Media data.
     * @return int|false Insert ID or false.
     */
    private static function insert_media( int $comment_id, array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $encrypted_meta = null;
        if ( ! empty( $data['attachment_id'] ) ) {
            $file_path = get_attached_file( $data['attachment_id'] );
            if ( $file_path && class_exists( Crypto::class ) ) {
                $encrypted_meta = Crypto::encrypt( $file_path );
            }
        }

        $has_media = ! empty( $data['attachment_id'] ) || ! empty( $data['embed_url'] );
        $status    = $has_media ? self::STATUS_PENDING : self::STATUS_PENDING;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'comment_id'          => $comment_id,
                'media_type'          => $data['media_type'] ?? self::TYPE_PHOTO,
                'source_type'         => $data['source_type'] ?? self::SOURCE_UPLOAD,
                'attachment_id'       => $data['attachment_id'] ?? null,
                'embed_url'           => $data['embed_url'] ?? null,
                'file_path_encrypted' => $encrypted_meta,
                'sort_order'          => $data['sort_order'] ?? 0,
                'status'              => $status,
                'created_at'          => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get all media for a review.
     *
     * @param int    $comment_id Review comment ID.
     * @param string $status     Filter by status (empty = all).
     * @return array[] Media rows.
     */
    public static function get_media( int $comment_id, string $status = 'approved' ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if ( '' !== $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE comment_id = %d AND status = %s ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id,
                    $status
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE comment_id = %d ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id
                ),
                ARRAY_A
            );
        }

        return is_array( $results ) ? $results : array();
    }

    /**
     * Get photos only for a review.
     *
     * @param int    $comment_id Review comment ID.
     * @param string $status     Filter by status.
     * @return array[] Photo media rows.
     */
    public static function get_photos( int $comment_id, string $status = 'approved' ): array {
        return array_filter(
            self::get_media( $comment_id, $status ),
            static fn( $m ) => $m['media_type'] === self::TYPE_PHOTO
        );
    }

    /**
     * Get videos only for a review.
     *
     * @param int    $comment_id Review comment ID.
     * @param string $status     Filter by status.
     * @return array[] Video media rows.
     */
    public static function get_videos( int $comment_id, string $status = 'approved' ): array {
        return array_filter(
            self::get_media( $comment_id, $status ),
            static fn( $m ) => $m['media_type'] === self::TYPE_VIDEO
        );
    }

    /**
     * Check if a review has any media (photos or videos).
     *
     * @param int    $comment_id Review comment ID.
     * @param string $status     Filter by status.
     * @return bool
     */
    public static function has_media( int $comment_id, string $status = '' ): bool {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if ( '' !== $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE comment_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id,
                    $status
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE comment_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id
                )
            );
        }

        return (int) $count > 0;
    }

    /**
     * Approve all media for a review.
     *
     * @param int $comment_id Review comment ID.
     * @return int Number of rows updated.
     */
    public static function approve_media( int $comment_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            array( 'status' => self::STATUS_APPROVED ),
            array( 'comment_id' => $comment_id, 'status' => self::STATUS_PENDING ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        return (int) $updated;
    }

    /**
     * Reject all media for a review.
     *
     * @param int $comment_id Review comment ID.
     * @return int Number of rows updated.
     */
    public static function reject_media( int $comment_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            array( 'status' => self::STATUS_REJECTED ),
            array( 'comment_id' => $comment_id, 'status' => self::STATUS_PENDING ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        return (int) $updated;
    }

    /**
     * Delete all media for a review.
     *
     * @param int $comment_id Review comment ID.
     * @return int Number of rows deleted.
     */
    public static function delete_media( int $comment_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $media_rows = self::get_media( $comment_id, '' );
        foreach ( $media_rows as $row ) {
            if ( ! empty( $row['attachment_id'] ) ) {
                wp_delete_attachment( (int) $row['attachment_id'], true );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->delete( $table, array( 'comment_id' => $comment_id ), array( '%d' ) );
    }

    /**
     * Get count of reviews with pending media for the moderation queue.
     *
     * @return int
     */
    public static function get_pending_media_count(): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            "SELECT COUNT( DISTINCT comment_id ) FROM {$table} WHERE status = 'pending'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return (int) $count;
    }

    /**
     * Get reviews that have pending media, for the moderation queue.
     *
     * @param int $limit  Number of results.
     * @param int $offset Pagination offset.
     * @return array[] Array of [ comment_id, media_count, media_types ].
     */
    public static function get_reviews_with_pending_media( int $limit = 20, int $offset = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT comment_id, COUNT(*) AS media_count,
                        GROUP_CONCAT( DISTINCT media_type ) AS media_types
                 FROM {$table}
                 WHERE status = 'pending'
                 GROUP BY comment_id
                 ORDER BY MIN(created_at) ASC
                 LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Validate a YouTube or Vimeo embed URL.
     *
     * @param string $url URL to validate.
     * @return bool
     */
    public static function is_valid_embed_url( string $url ): bool {
        if ( '' === $url ) {
            return false;
        }
        return (bool) preg_match( self::YOUTUBE_PATTERN, $url )
            || (bool) preg_match( self::VIMEO_PATTERN, $url );
    }

    /**
     * Extract an embeddable iframe URL from a YouTube/Vimeo link.
     *
     * @param string $url Original video URL.
     * @return string Embed-ready URL, or empty string.
     */
    public static function get_embed_iframe_url( string $url ): string {
        if ( preg_match( self::YOUTUBE_PATTERN, $url, $m ) ) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if ( preg_match( self::VIMEO_PATTERN, $url, $m ) ) {
            return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
        }
        return '';
    }

    /**
     * Whether review media (photos/videos) is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return 'yes' === get_option( 'tejcart_review_media_enabled', 'no' );
    }

    /**
     * Whether video uploads/embeds are enabled.
     *
     * @return bool
     */
    public static function videos_enabled(): bool {
        return self::is_enabled() && 'yes' === get_option( 'tejcart_review_videos_enabled', 'no' );
    }
}
