<?php
/**
 * Review Votes — "Was this helpful?" vote tracking for product reviews.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

use TejCart\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Review_Votes {

    const TABLE = 'tejcart_review_votes';

    const VOTE_HELPFUL     = 1;
    const VOTE_NOT_HELPFUL = -1;

    /**
     * Hook AJAX handlers.
     */
    public function init(): void {
        add_action( 'wp_ajax_tejcart_review_vote', array( $this, 'handle_vote' ) );
        add_action( 'wp_ajax_nopriv_tejcart_review_vote', array( $this, 'handle_vote' ) );
    }

    /**
     * AJAX handler for casting a helpful vote.
     */
    public function handle_vote(): void {
        check_ajax_referer( 'tejcart_review_vote', 'nonce' );

        global $wpdb;

        $review_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $vote      = isset( $_POST['vote'] ) ? (int) $_POST['vote'] : 0;

        if ( ! $review_id || ! in_array( $vote, array( self::VOTE_HELPFUL, self::VOTE_NOT_HELPFUL ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid vote.', 'tejcart' ) ), 400 );
        }

        $reviews_table = $wpdb->prefix . Product_Reviews::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $review = $wpdb->get_row(
            $wpdb->prepare( "SELECT id FROM {$reviews_table} WHERE id = %d AND parent_id IS NULL", $review_id )
        );

        if ( ! $review ) {
            wp_send_json_error( array( 'message' => __( 'Review not found.', 'tejcart' ) ), 404 );
        }

        $client_ip = Rate_Limiter::get_client_ip();
        $user_id   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

        if ( self::has_voted( $review_id, $client_ip, $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You have already voted on this review.', 'tejcart' ) ), 409 );
        }

        $result = self::record_vote( $review_id, $vote, $client_ip, $user_id );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Could not record your vote.', 'tejcart' ) ), 500 );
        }

        $counts = self::get_vote_counts( $review_id );

        wp_send_json_success( array(
            'helpful'     => $counts['helpful'],
            'not_helpful' => $counts['not_helpful'],
            'message'     => __( 'Thanks for your feedback!', 'tejcart' ),
        ) );
    }

    /**
     * Record a vote.
     *
     * @param int    $comment_id Review comment ID.
     * @param int    $vote       1 for helpful, -1 for not helpful.
     * @param string $voter_ip   Client IP address.
     * @param int    $user_id    WordPress user ID (0 for guests).
     * @return bool Success.
     */
    public static function record_vote( int $comment_id, int $vote, string $voter_ip, int $user_id = 0 ): bool {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'comment_id'    => $comment_id,
                'vote'          => $vote,
                'voter_ip'      => $voter_ip,
                'voter_user_id' => $user_id > 0 ? $user_id : null,
                'created_at'    => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%d', '%s' )
        );

        if ( $result ) {
            /**
             * Fires after a helpful vote is recorded.
             *
             * @param int    $comment_id Review comment ID.
             * @param int    $vote       1 or -1.
             * @param int    $user_id    Voter user ID.
             */
            do_action( 'tejcart_review_voted', $comment_id, $vote, $user_id );
        }

        return (bool) $result;
    }

    /**
     * Check if a visitor has already voted on a review.
     *
     * Deduplicates by user ID (for logged-in users) OR IP (for guests).
     *
     * @param int    $comment_id Review comment ID.
     * @param string $voter_ip   Client IP.
     * @param int    $user_id    WordPress user ID.
     * @return bool
     */
    public static function has_voted( int $comment_id, string $voter_ip, int $user_id = 0 ): bool {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if ( $user_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1 FROM {$table} WHERE comment_id = %d AND voter_user_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id,
                    $user_id
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1 FROM {$table} WHERE comment_id = %d AND voter_ip = %s AND voter_user_id IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $comment_id,
                    $voter_ip
                )
            );
        }

        return ! empty( $exists );
    }

    /**
     * Get vote counts for a review.
     *
     * @param int $comment_id Review comment ID.
     * @return array{helpful: int, not_helpful: int, total: int}
     */
    public static function get_vote_counts( int $comment_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT vote, COUNT(*) AS cnt FROM {$table} WHERE comment_id = %d GROUP BY vote", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $comment_id
            ),
            ARRAY_A
        );

        $counts = array( 'helpful' => 0, 'not_helpful' => 0, 'total' => 0 );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( (int) $row['vote'] === self::VOTE_HELPFUL ) {
                    $counts['helpful'] = (int) $row['cnt'];
                } elseif ( (int) $row['vote'] === self::VOTE_NOT_HELPFUL ) {
                    $counts['not_helpful'] = (int) $row['cnt'];
                }
            }
            $counts['total'] = $counts['helpful'] + $counts['not_helpful'];
        }

        return $counts;
    }

    /**
     * Batch-load vote counts for multiple reviews.
     *
     * @param int[] $comment_ids Review comment IDs.
     * @return array<int, array{helpful: int, not_helpful: int, total: int}>
     */
    public static function get_vote_counts_batch( array $comment_ids ): array {
        global $wpdb;

        $counts = array();
        foreach ( $comment_ids as $id ) {
            $counts[ (int) $id ] = array( 'helpful' => 0, 'not_helpful' => 0, 'total' => 0 );
        }

        if ( empty( $comment_ids ) ) {
            return $counts;
        }

        $table       = $wpdb->prefix . self::TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT comment_id, vote, COUNT(*) AS cnt FROM {$table} WHERE comment_id IN ({$placeholders}) GROUP BY comment_id, vote", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ...array_map( 'absint', $comment_ids )
            ),
            ARRAY_A
        );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $cid = (int) $row['comment_id'];
                if ( ! isset( $counts[ $cid ] ) ) {
                    continue;
                }
                if ( (int) $row['vote'] === self::VOTE_HELPFUL ) {
                    $counts[ $cid ]['helpful'] = (int) $row['cnt'];
                } elseif ( (int) $row['vote'] === self::VOTE_NOT_HELPFUL ) {
                    $counts[ $cid ]['not_helpful'] = (int) $row['cnt'];
                }
                $counts[ $cid ]['total'] = $counts[ $cid ]['helpful'] + $counts[ $cid ]['not_helpful'];
            }
        }

        return $counts;
    }

    /**
     * Delete all votes for a review (cascade on review delete).
     *
     * @param int $comment_id Review comment ID.
     * @return int Rows deleted.
     */
    public static function delete_votes( int $comment_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->delete( $table, array( 'comment_id' => $comment_id ), array( '%d' ) );
    }

    /**
     * Get the most helpful review IDs for a product (for sorting).
     *
     * @param int $product_id Product ID.
     * @param int $limit      Number of results.
     * @return int[] Comment IDs ordered by helpfulness.
     */
    public static function get_most_helpful_review_ids( int $product_id, int $limit = 50 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT v.comment_id
                 FROM {$table} AS v
                 INNER JOIN {$wpdb->commentmeta} AS pm
                     ON pm.comment_id = v.comment_id
                     AND pm.meta_key = '_tejcart_product_id'
                     AND pm.meta_value = %s
                 WHERE v.vote = %d
                 GROUP BY v.comment_id
                 ORDER BY COUNT(*) DESC
                 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $product_id,
                self::VOTE_HELPFUL,
                $limit
            )
        );

        return array_map( 'absint', is_array( $rows ) ? $rows : array() );
    }
}
