<?php
/**
 * Product Reviews & Ratings System.
 *
 * Stores reviews in the custom tejcart_product_reviews table.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

use TejCart\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles product reviews and ratings using the custom tejcart_product_reviews table.
 */
class Product_Reviews {
    /**
     * Custom table name (without prefix).
     *
     * @var string
     */
    const TABLE = 'tejcart_product_reviews';

    /**
     * Hook the single-product review form's POST handler, meta sync
     * listeners, and cascade cleanup.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_handle_submission' ) );

        add_action( 'tejcart_review_created', array( __CLASS__, 'sync_product_rating_meta' ), 10, 2 );
        add_action( 'tejcart_review_approved', array( __CLASS__, 'sync_rating_meta_from_comment' ) );
        add_action( 'tejcart_review_deleted', array( __CLASS__, 'sync_rating_meta_from_comment' ) );

        add_action( 'tejcart_review_deleted', array( __CLASS__, 'cascade_delete_review_data' ) );

        add_action( 'tejcart_review_approved', array( __CLASS__, 'auto_approve_media_on_review_approve' ) );
    }

    /**
     * Detect and process a posted review submission.
     */
    public function maybe_handle_submission(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
        if ( empty( $_POST['tejcart_review_nonce'] ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
        $nonce = sanitize_text_field( wp_unslash( (string) $_POST['tejcart_review_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'tejcart_submit_review' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $product_id = isset( $_POST['tejcart_product_id'] ) ? absint( $_POST['tejcart_product_id'] ) : 0;
        if ( $product_id <= 0 ) {
            return;
        }

        // TejCart products are not WP posts, so get_permalink( $product_id )
        // would resolve the ID against wp_posts and return an unrelated
        // post/attachment URL. Resolve the product object and use its own
        // get_permalink() (Shop page + ?product=<id> / pretty slug) instead.
        $return_url = '';
        $product    = function_exists( 'tejcart_get_product' ) ? tejcart_get_product( $product_id ) : null;
        if ( $product && method_exists( $product, 'get_permalink' ) ) {
            $return_url = (string) $product->get_permalink();
        }
        if ( '' === $return_url ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $return_url = isset( $_SERVER['REQUEST_URI'] )
                ? home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : home_url( '/' );
        }

        // Honeypot — silently treat as success so bots can't tell.
        if ( self::is_likely_spam_submission() ) {
            wp_safe_redirect( add_query_arg( 'tejcart_review_status', 'pending', $return_url ) . '#tejcart-review-form' );
            exit;
        }

        $user_id      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $rating       = isset( $_POST['tejcart_rating'] ) ? absint( $_POST['tejcart_rating'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $content      = isset( $_POST['tejcart_review_content'] ) ? (string) wp_unslash( $_POST['tejcart_review_content'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $author       = isset( $_POST['tejcart_review_author'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tejcart_review_author'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $author_email = isset( $_POST['tejcart_review_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['tejcart_review_email'] ) ) : '';

        // Logged-in users supply identity from the user object.
        if ( $user_id > 0 ) {
            $current_user = wp_get_current_user();
            if ( is_object( $current_user ) ) {
                if ( '' === $author ) {
                    $author = (string) $current_user->display_name;
                }
                if ( '' === $author_email ) {
                    $author_email = (string) $current_user->user_email;
                }
            }
        }

        $result = self::add_review(
            $product_id,
            array(
                'rating'       => $rating,
                'content'      => $content,
                'author'       => $author,
                'author_email' => $author_email,
                'user_id'      => $user_id,
            )
        );

        $status = is_wp_error( $result ) ? 'error' : 'success';

        if ( 'success' === $status ) {
            $review_id = (int) $result;

            if ( Review_Media::is_enabled()
                && ! empty( $_FILES['tejcart_review_photos'] )
                && ! empty( $_FILES['tejcart_review_photos']['tmp_name'] )
            ) {
                $has_files = is_array( $_FILES['tejcart_review_photos']['tmp_name'] )
                    ? array_filter( $_FILES['tejcart_review_photos']['tmp_name'] )
                    : array( $_FILES['tejcart_review_photos']['tmp_name'] );

                if ( ! empty( $has_files ) ) {
                    Review_Media::process_photos( $review_id, $_FILES['tejcart_review_photos'] );
                }
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
            $video_url = isset( $_POST['tejcart_review_video_url'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['tejcart_review_video_url'] ) )
                : '';
            if ( '' !== $video_url && Review_Media::videos_enabled() ) {
                Review_Media::add_video_embed( $review_id, $video_url );
            }
        }

        $args = array( 'tejcart_review_status' => $status );
        if ( 'error' === $status && method_exists( $result, 'get_error_code' ) ) {
            $args['tejcart_review_code'] = (string) $result->get_error_code();
        }

        wp_safe_redirect( add_query_arg( $args, $return_url ) . '#tejcart-review-form' );
        exit;
    }

    const MAX_PHOTOS_PER_REVIEW = 5;
    const MAX_PHOTO_SIZE_BYTES  = 5 * 1024 * 1024; // 5 MB

    /**
     * Inspect the current request for honeypot / time-to-submit signals
     * that strongly indicate a bot is filling the review form.
     *
     * Submission handlers should call this *before* add_review() and, if
     * it returns true, respond with a normal-looking success so bots
     * can't tell their submission was dropped.
     *
     * @param int $min_seconds Minimum seconds the form must be on screen
     *                         before the submission is considered human.
     *                         Defaults to 3.
     * @return bool True when the submission looks like spam.
     */
    public static function is_likely_spam_submission( $min_seconds = 3 ) {
        // Honeypot inspection — a non-empty value indicates a bot, so we do not
        // need (and don't want) the request to be a fully authorised submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['tejcart_review_hp'] ) && '' !== trim( (string) wp_unslash( $_POST['tejcart_review_hp'] ) ) ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- inspecting raw input.
        $ts = isset( $_POST['tejcart_review_ts'] ) ? absint( $_POST['tejcart_review_ts'] ) : 0;
        if ( $ts > 0 && ( time() - $ts ) < absint( $min_seconds ) ) {
            return true;
        }

        return false;
    }

    /**
     * Add a review for a product.
     *
     * @param int   $product_id Product ID.
     * @param array $data {
     *     Review data.
     *
     *     @type int    $rating       Rating from 1-5. Required.
     *     @type string $content      Review text. Required.
     *     @type string $author       Author name. Required.
     *     @type string $author_email Author email. Required.
     *     @type int    $user_id      WordPress user ID. Optional, default 0.
     * }
     * @return int|\WP_Error Review ID on success, WP_Error on failure.
     */
    public static function add_review( $product_id, $data ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return new \WP_Error( 'invalid_product', __( 'Invalid product ID.', 'tejcart' ) );
        }

        $rating          = isset( $data['rating'] ) ? absint( $data['rating'] ) : 0;
        $rating_required = 'yes' === get_option( 'tejcart_review_rating_required', 'no' );

        if ( $rating_required && ( $rating < 1 || $rating > 5 ) ) {
            return new \WP_Error( 'invalid_rating', __( 'Please leave a star rating before submitting your review.', 'tejcart' ) );
        }

        if ( $rating > 0 && ( $rating < 1 || $rating > 5 ) ) {
            return new \WP_Error( 'invalid_rating', __( 'Rating must be between 1 and 5.', 'tejcart' ) );
        }

        $content = isset( $data['content'] ) ? sanitize_textarea_field( $data['content'] ) : '';
        if ( empty( $content ) ) {
            return new \WP_Error( 'empty_review', __( 'Review text is required.', 'tejcart' ) );
        }

        $author       = isset( $data['author'] ) ? sanitize_text_field( $data['author'] ) : '';
        $author_email = isset( $data['author_email'] ) ? sanitize_email( $data['author_email'] ) : '';
        $user_id      = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

        if ( empty( $author ) || empty( $author_email ) ) {
            return new \WP_Error( 'missing_author', __( 'Author name and email are required.', 'tejcart' ) );
        }

        if ( 'yes' === get_option( 'tejcart_review_verified_only', 'no' ) ) {
            $can_verify = $user_id && self::is_verified_purchase( $user_id, $product_id );

            if ( ! $can_verify ) {
                $can_verify = self::email_has_purchased( $author_email, $product_id );
            }

            if ( ! $can_verify ) {
                return new \WP_Error(
                    'not_verified_owner',
                    __( 'Only customers who have purchased this product can leave a review.', 'tejcart' )
                );
            }
        }

        $client_ip = class_exists( __NAMESPACE__ . '\\Rate_Limiter' ) || class_exists( '\\TejCart\\Security\\Rate_Limiter' )
            ? Rate_Limiter::get_client_ip()
            : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        if ( '' !== $client_ip
            && Rate_Limiter::check_and_record( 'submit_review', $client_ip, 3, HOUR_IN_SECONDS ) ) {
            return new \WP_Error(
                'review_rate_limited',
                __( 'You have submitted too many reviews recently. Please try again later.', 'tejcart' )
            );
        }

        $verified = 0;
        if ( $user_id && self::is_verified_purchase( $user_id, $product_id ) ) {
            $verified = 1;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            $table,
            array(
                'product_id'        => $product_id,
                'author_name'       => $author,
                'author_email'      => $author_email,
                'author_ip'         => $client_ip,
                'author_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                'user_id'           => $user_id > 0 ? $user_id : null,
                'content'           => $content,
                'rating'            => $rating,
                'verified_purchase' => $verified,
                'status'            => 'pending',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( ! $inserted ) {
            return new \WP_Error( 'review_failed', __( 'Could not create the review.', 'tejcart' ) );
        }

        $review_id = (int) $wpdb->insert_id;

        /**
         * Fires after a product review is created.
         *
         * @param int   $review_id  The review ID.
         * @param int   $product_id The product ID.
         * @param array $data       The submitted review data.
         */
        do_action( 'tejcart_review_created', $review_id, $product_id, $data );

        return $review_id;
    }

    /**
     * Get reviews for a product.
     *
     * @param int   $product_id Product ID.
     * @param array $args       Optional. Supports 'number', 'offset', 'status',
     *                           'orderby', 'order', and 'count' (returns int).
     * @return object[]|int Array of review objects, or count when 'count' is true.
     */
    public static function get_reviews( $product_id, $args = array() ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return isset( $args['count'] ) && $args['count'] ? 0 : array();
        }

        $defaults = array(
            'number'  => 10,
            'offset'  => 0,
            'status'  => 'approved',
            'orderby' => 'created_at',
            'order'   => 'DESC',
            'count'   => false,
        );
        $args = wp_parse_args( $args, $defaults );

        $table = $wpdb->prefix . self::TABLE;

        $allowed_orderby = array( 'created_at', 'rating', 'id' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

        // Low: build the full statement with placeholders and run ONE
        // final $wpdb->prepare() rather than prepare-then-interpolate
        // fragments. $table, $orderby and $order are whitelisted internal
        // identifiers (validated above) so they're safe to interpolate as
        // SQL identifiers; every runtime VALUE is bound via placeholders.
        $has_status = '' !== $args['status'];

        if ( $args['count'] ) {
            $sql  = "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND parent_id IS NULL";
            $sql .= $has_status ? ' AND status = %s' : '';
            $params = $has_status ? array( $product_id, $args['status'] ) : array( $product_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }

        $number = (int) $args['number'];

        $sql  = "SELECT * FROM {$table} WHERE product_id = %d AND parent_id IS NULL";
        $sql .= $has_status ? ' AND status = %s' : '';
        $sql .= " ORDER BY {$orderby} {$order}";

        $params = array( $product_id );
        if ( $has_status ) {
            $params[] = $args['status'];
        }
        if ( $number > 0 ) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = $number;
            $params[] = max( 0, (int) $args['offset'] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Get the average rating for a product.
     *
     * @param int $product_id Product ID.
     * @return float Average rating (0.0 if no reviews).
     */
    /**
     * Get the average star rating for a product.
     *
     * Ratings are stored as integer strings (1–5) in comment meta.
     * The database average is rounded to two decimal places for display.
     *
     * F-PCA-012: Float return is intentional here — star ratings are not money
     * and do not require integer-minor-unit representation. However, a return
     * of `0.0` is ambiguous: it can mean either "no approved reviews exist" or
     * (in theory) a genuine sub-0.5 average that rounds to zero. In practice
     * the DB constraint `BETWEEN 1 AND 5` makes a genuine zero impossible, so
     * callers may treat `0.0` as the no-data sentinel.
     *
     * Consumers that need to distinguish "no reviews" from a low rating should
     * check `get_review_count() === 0` first.
     *
     * @param int $product_id Product ID.
     * @return float Average rating in the range [1.00, 5.00], or 0.0 when no approved reviews exist.
     */
    public static function get_average_rating( $product_id ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return 0.0;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $avg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG( rating )
                 FROM {$table}
                 WHERE product_id = %d
                   AND status = 'approved'
                   AND rating BETWEEN 1 AND 5",
                $product_id
            )
        );

        return $avg !== null ? round( (float) $avg, 2 ) : 0.0;
    }

    /**
     * Get the count of approved reviews for a product.
     *
     * @param int $product_id Product ID.
     * @return int Number of approved reviews.
     */
    public static function get_review_count( $product_id ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return 0;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = 'approved'",
                $product_id
            )
        );
    }

    /**
     * Get the count of approved reviews per rating bucket (1-5) for a product.
     *
     * Returns an associative array keyed 5 -> 1 (high to low) so the caller can
     * iterate in display order without re-sorting. Buckets with no reviews
     * are still present with a value of 0 so renderers can draw an empty bar.
     *
     * @param int $product_id Product ID.
     * @return array<int,int> Map of rating (1..5) to number of approved reviews.
     */
    public static function get_rating_distribution( $product_id ) {
        global $wpdb;

        $distribution = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return $distribution;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rating, COUNT(*) AS total
                 FROM {$table}
                 WHERE product_id = %d
                   AND status = 'approved'
                   AND rating BETWEEN 1 AND 5
                 GROUP BY rating",
                $product_id
            ),
            ARRAY_A
        );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $rating = isset( $row['rating'] ) ? (int) $row['rating'] : 0;
                if ( $rating >= 1 && $rating <= 5 ) {
                    $distribution[ $rating ] = (int) $row['total'];
                }
            }
        }

        return $distribution;
    }

    /**
     * Check whether a guest email address has purchased the given product.
     *
     * Complements is_verified_purchase() for reviews written without a
     * logged-in user context.
     *
     * @param string $email      Customer email.
     * @param int    $product_id Product ID.
     * @return bool
     */
    public static function email_has_purchased( $email, $product_id ) {
        global $wpdb;

        $email      = sanitize_email( (string) $email );
        $product_id = absint( $product_id );

        if ( '' === $email || ! $product_id ) {
            return false;
        }

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $items_table  = $wpdb->prefix . 'tejcart_order_items';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$orders_table} AS o
                 INNER JOIN {$items_table} AS i ON o.id = i.order_id
                 WHERE o.customer_email = %s
                   AND i.product_id = %d
                   AND o.status = 'completed'
                 LIMIT 1",
                $email,
                $product_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $result > 0;
    }

    /**
     * Whether the "verified owner" label should be shown next to a review.
     *
     * Controlled by the tejcart_review_show_verified_label option.
     *
     * @return bool
     */
    public static function show_verified_label(): bool {
        return 'no' !== get_option( 'tejcart_review_show_verified_label', 'yes' );
    }

    /**
     * Check whether a logged-in user has a verified purchase for a product
     * by scanning their completed orders.
     *
     * @param int $user_id    WordPress user ID.
     * @param int $product_id Product ID.
     * @return bool True if the user has purchased the product.
     */
    public static function is_verified_purchase( $user_id, $product_id ) {
        global $wpdb;

        $user_id    = absint( $user_id );
        $product_id = absint( $product_id );

        if ( ! $user_id || ! $product_id ) {
            return false;
        }

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $items_table  = $wpdb->prefix . 'tejcart_order_items';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$orders_table} AS o
                 INNER JOIN {$items_table} AS i ON o.id = i.order_id
                 WHERE o.customer_id = %d
                   AND i.product_id = %d
                   AND o.status = 'completed'
                 LIMIT 1",
                $user_id,
                $product_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $result > 0;
    }

    /**
     * Approve a review.
     *
     * @param int $review_id Review ID.
     * @return bool True on success, false on failure.
     */
    public static function approve_review( $review_id ) {
        global $wpdb;

        $review_id = absint( $review_id );
        if ( ! $review_id ) {
            return false;
        }
        // Audit L-6: gate on moderate_comments (or manage_options) so
        // a caller that reaches this method without proper context
        // can't approve reviews.
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'moderate_comments' ) ) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table,
            array( 'status' => 'approved' ),
            array( 'id' => $review_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false !== $result && $result > 0 ) {
            /**
             * Fires after a review is approved.
             *
             * @param int $review_id The review ID.
             */
            do_action( 'tejcart_review_approved', $review_id );
            return true;
        }

        return false;
    }

    /**
     * Delete a review.
     *
     * @param int $review_id Review ID.
     * @return bool True on success, false on failure.
     */
    public static function delete_review( $review_id ) {
        global $wpdb;

        $review_id = absint( $review_id );
        if ( ! $review_id ) {
            return false;
        }
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'moderate_comments' ) ) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete( $table, array( 'id' => $review_id ), array( '%d' ) );

        if ( false !== $result && $result > 0 ) {
            /**
             * Fires after a review is deleted.
             *
             * @param int $review_id The review ID.
             */
            do_action( 'tejcart_review_deleted', $review_id );
            return true;
        }

        return false;
    }

    /**
     * Get the rating value for a specific review.
     *
     * @param int $review_id Review ID.
     * @return int Rating (1-5) or 0 if not set.
     */
    public static function get_review_rating( $review_id ) {
        global $wpdb;

        $review_id = absint( $review_id );
        if ( ! $review_id ) {
            return 0;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rating = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT rating FROM {$table} WHERE id = %d", $review_id )
        );

        return ( $rating >= 1 && $rating <= 5 ) ? $rating : 0;
    }

    /**
     * Check if a review is from a verified purchaser.
     *
     * @param int $review_id Review ID.
     * @return bool True if verified purchase.
     */
    public static function is_review_verified( $review_id ) {
        global $wpdb;

        $review_id = absint( $review_id );
        if ( ! $review_id ) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $verified = $wpdb->get_var(
            $wpdb->prepare( "SELECT verified_purchase FROM {$table} WHERE id = %d", $review_id )
        );

        return (bool) $verified;
    }

    /**
     * Whether review photo uploads are enabled.
     *
     * @return bool
     */
    public static function photos_enabled(): bool {
        return 'yes' === get_option( 'tejcart_review_photos_enabled', 'no' );
    }

    /**
     * Process uploaded review photos and attach them to a review.
     *
     * Delegates to Review_Media::process_photos() which handles file
     * validation, upload via media_handle_upload, and storage in the
     * tejcart_review_media table.
     *
     * @param int   $review_id The review ID in tejcart_product_reviews.
     * @param array $files     The $_FILES['tejcart_review_photos'] array.
     * @return int[] Review-media row IDs that were stored.
     */
    public static function process_review_photos( int $review_id, array $files ): array {
        return Review_Media::process_photos( $review_id, $files );
    }

    /**
     * Get photo attachment IDs for a review.
     *
     * Reads from the tejcart_review_media table via Review_Media.
     *
     * @param int $review_id Review ID in tejcart_product_reviews.
     * @return int[] Attachment IDs.
     */
    public static function get_review_photos( int $review_id ): array {
        $photos = Review_Media::get_photos( $review_id );
        return array_values( array_map(
            static fn( $row ) => (int) ( $row['attachment_id'] ?? 0 ),
            $photos
        ) );
    }

    /**
     * Add an admin reply to a review.
     *
     * @param int    $review_id Review ID in tejcart_product_reviews to reply to.
     * @param string $content   Reply text.
     * @param int    $admin_id  WordPress admin user ID.
     * @return int|\WP_Error Reply row ID on success.
     */
    public static function add_admin_reply( int $review_id, string $content, int $admin_id ) {
        global $wpdb;

        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'moderate_comments' ) ) {
            return new \WP_Error( 'insufficient_permissions', __( 'You do not have permission to reply to reviews.', 'tejcart' ) );
        }

        $content = sanitize_textarea_field( $content );
        if ( '' === $content ) {
            return new \WP_Error( 'empty_reply', __( 'Reply text is required.', 'tejcart' ) );
        }

        $table = $wpdb->prefix . self::TABLE;

        // Validate the parent review exists.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $parent = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND parent_id IS NULL", $review_id )
        );

        if ( ! $parent ) {
            return new \WP_Error( 'invalid_review', __( 'Review not found.', 'tejcart' ) );
        }

        $admin_user = get_userdata( $admin_id );
        if ( ! $admin_user ) {
            return new \WP_Error( 'invalid_user', __( 'Invalid admin user.', 'tejcart' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            $table,
            array(
                'product_id'   => (int) $parent->product_id,
                'author_name'  => $admin_user->display_name,
                'author_email' => $admin_user->user_email,
                'user_id'      => $admin_id,
                'content'      => $content,
                'rating'       => 0,
                'status'       => 'approved',
                'parent_id'    => $review_id,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'reply_failed', __( 'Could not create the reply.', 'tejcart' ) );
        }

        $reply_id = (int) $wpdb->insert_id;

        /**
         * Fires after an admin reply is added to a review.
         *
         * @param int $reply_id  The reply row ID.
         * @param int $review_id The parent review ID.
         * @param int $admin_id  The admin user ID.
         */
        do_action( 'tejcart_review_reply_created', $reply_id, $review_id, $admin_id );

        return $reply_id;
    }

    /**
     * Get the admin reply for a review (only the first/latest).
     *
     * @param int $review_id Review ID in tejcart_product_reviews.
     * @return object|null stdClass row or null.
     */
    public static function get_admin_reply( int $review_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE parent_id = %d AND status = 'approved' ORDER BY created_at ASC LIMIT 1",
                $review_id
            )
        );
    }

    /**
     * Get reviews sorted by helpfulness (most helpful first).
     *
     * @param int   $product_id Product ID.
     * @param array $args       Optional query args.
     * @return object[] Array of stdClass review rows.
     */
    public static function get_reviews_by_helpfulness( int $product_id, array $args = array() ): array {
        $helpful_ids = Review_Votes::get_most_helpful_review_ids( $product_id, 100 );
        $all_reviews = self::get_reviews( $product_id, array_merge( $args, array( 'number' => 100 ) ) );

        if ( empty( $helpful_ids ) ) {
            return $all_reviews;
        }

        $helpful_map = array_flip( $helpful_ids );
        $sorted      = array();
        $rest        = array();

        foreach ( $all_reviews as $review ) {
            if ( isset( $helpful_map[ $review->id ] ) ) {
                $sorted[ $helpful_map[ $review->id ] ] = $review;
            } else {
                $rest[] = $review;
            }
        }

        ksort( $sorted );

        $limit = isset( $args['number'] ) ? (int) $args['number'] : 10;
        return array_slice( array_merge( array_values( $sorted ), $rest ), 0, $limit > 0 ? $limit : 10 );
    }

    /**
     * Recalculate and store average rating + review count in product meta.
     *
     * @param int $comment_id Review comment ID.
     * @param int $product_id Product ID.
     */
    public static function sync_product_rating_meta( int $comment_id, int $product_id ): void {
        self::update_product_rating_meta( $product_id );
    }

    /**
     * Recalculate rating meta from a review ID (for approve/delete hooks).
     *
     * @param int $review_id Review ID in tejcart_product_reviews.
     */
    public static function sync_rating_meta_from_comment( int $review_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $product_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT product_id FROM {$table} WHERE id = %d", $review_id )
        );

        if ( $product_id > 0 ) {
            self::update_product_rating_meta( $product_id );
        }
    }

    /**
     * Update the cached _average_rating and _review_count product meta.
     *
     * @param int $product_id Product ID.
     */
    private static function update_product_rating_meta( int $product_id ): void {
        $avg   = self::get_average_rating( $product_id );
        $count = self::get_review_count( $product_id );

        Product_Meta::update( $product_id, '_average_rating', (string) $avg );
        Product_Meta::update( $product_id, '_review_count', (string) $count );
    }

    /**
     * Cascade-delete review votes, media, and replies when a review is deleted.
     *
     * @param int $review_id Review ID in tejcart_product_reviews.
     */
    public static function cascade_delete_review_data( int $review_id ): void {
        global $wpdb;

        Review_Votes::delete_votes( $review_id );
        Review_Media::delete_media( $review_id );

        $table = $wpdb->prefix . self::TABLE;

        // Delete all child replies for this review.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, array( 'parent_id' => $review_id ), array( '%d' ) );
    }

    /**
     * Auto-approve media when the parent review is approved.
     *
     * @param int $comment_id Review comment ID.
     */
    public static function auto_approve_media_on_review_approve( int $comment_id ): void {
        Review_Media::approve_media( $comment_id );
    }
}
