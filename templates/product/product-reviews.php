<?php
/**
 * Product Reviews template — v2.
 *
 * Renders: reviews summary, distribution histogram, individual review
 * cards (with photos, videos, helpful votes, admin replies), sort
 * control, and the review submission form.
 *
 * @package TejCart\Templates\Product
 *
 * @var int $product_id The product ID.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Reviews;
use TejCart\Product\Review_Media;
use TejCart\Product\Review_Votes;

$enable_reviews = get_option( 'tejcart_enable_reviews', 'yes' );

if ( 'yes' !== $enable_reviews ) {
    return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tejcart_sort   = isset( $_GET['review_sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['review_sort'] ) ) : 'newest';
$sort_options    = array(
    'newest'      => __( 'Newest first', 'tejcart' ),
    'oldest'      => __( 'Oldest first', 'tejcart' ),
    'most_helpful' => __( 'Most helpful', 'tejcart' ),
);

$review_args = array( 'number' => 10 );
if ( 'oldest' === $tejcart_sort ) {
    $review_args['order'] = 'ASC';
}

if ( 'most_helpful' === $tejcart_sort ) {
    $reviews = Product_Reviews::get_reviews_by_helpfulness( $product_id, $review_args );
} else {
    $reviews = Product_Reviews::get_reviews( $product_id, $review_args );
}

$average_rating  = (float) Product_Reviews::get_average_rating( $product_id );
$review_count    = (int) Product_Reviews::get_review_count( $product_id );
$distribution    = Product_Reviews::get_rating_distribution( $product_id );
$rating_required = 'yes' === get_option( 'tejcart_review_rating_required', 'no' );
$media_enabled   = Review_Media::is_enabled();
$videos_enabled  = Review_Media::videos_enabled();
$votes_enabled   = 'yes' === get_option( 'tejcart_review_helpful_votes_enabled', 'yes' );

$comment_ids = array_map( static fn( $r ) => (int) $r->comment_ID, $reviews );
$vote_counts = $votes_enabled && ! empty( $comment_ids )
    ? Review_Votes::get_vote_counts_batch( $comment_ids )
    : array();

$tejcart_rating_word = static function ( $rating ) {
    switch ( (int) $rating ) {
        case 5:
            return __( 'Excellent', 'tejcart' );
        case 4:
            return __( 'Very good', 'tejcart' );
        case 3:
            return __( 'Average', 'tejcart' );
        case 2:
            return __( 'Poor', 'tejcart' );
        case 1:
            return __( 'Terrible', 'tejcart' );
        default:
            return '';
    }
};

$tejcart_render_stars = static function ( $rating ) {
    $rating = max( 0, min( 5, (float) $rating ) );
    $full   = (int) round( $rating );
    ob_start();
    for ( $i = 1; $i <= 5; $i++ ) {
        if ( $i <= $full ) {
            echo '<span class="tejcart-star tejcart-star-filled" aria-hidden="true">&#9733;</span>';
        } else {
            echo '<span class="tejcart-star tejcart-star-empty" aria-hidden="true">&#9734;</span>';
        }
    }
    return ob_get_clean();
};

$tejcart_initials = static function ( $name ) {
    $name = trim( wp_strip_all_tags( (string) $name ) );
    if ( '' === $name ) {
        return '★';
    }
    $parts = preg_split( '/\s+/u', $name ) ?: array( $name );
    $first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
    $last  = '';
    if ( count( $parts ) > 1 ) {
        $last = function_exists( 'mb_substr' ) ? mb_substr( end( $parts ), 0, 1 ) : substr( end( $parts ), 0, 1 );
    }
    $initials = strtoupper( $first . $last );
    return '' !== $initials ? $initials : '★';
};
?>

<div id="tejcart-reviews" class="tejcart-reviews" data-tejcart-reviews>

    <header class="tejcart-reviews-head">
        <h3 class="tejcart-reviews-title">
            <?php esc_html_e( 'Customer reviews', 'tejcart' ); ?>
        </h3>
        <?php if ( $review_count > 0 ) : ?>
            <p class="tejcart-reviews-subtitle">
                <?php
                /* translators: 1: number of reviews, 2: average rating out of 5 */
                printf(
                    esc_html( _n( 'Based on %1$s review · %2$s out of 5', 'Based on %1$s reviews · %2$s out of 5', $review_count, 'tejcart' ) ),
                    esc_html( number_format_i18n( $review_count ) ),
                    esc_html( number_format_i18n( $average_rating, 1 ) )
                );
                ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if ( $review_count > 0 ) : ?>

        <section class="tejcart-reviews-summary-card" aria-label="<?php esc_attr_e( 'Rating summary', 'tejcart' ); ?>">
            <div class="tejcart-reviews-summary-score">
                <span class="tejcart-reviews-summary-number"><?php echo esc_html( number_format_i18n( $average_rating, 1 ) ); ?></span>
                <div class="tejcart-star-rating tejcart-star-rating--lg" aria-label="<?php echo esc_attr( sprintf(
                    /* translators: %s: average rating value (out of 5) */
                    __( 'Rated %s out of 5', 'tejcart' ),
                    number_format_i18n( $average_rating, 1 )
                ) ); ?>">
                    <?php echo $tejcart_render_stars( $average_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <span class="tejcart-reviews-summary-count">
                    <?php
                    /* translators: %s: number of reviews */
                    echo esc_html( sprintf(
                        _n( '%s review', '%s reviews', $review_count, 'tejcart' ),
                        number_format_i18n( $review_count )
                    ) );
                    ?>
                </span>
            </div>

            <ul class="tejcart-reviews-summary-distribution" aria-label="<?php esc_attr_e( 'Rating breakdown', 'tejcart' ); ?>">
                <?php foreach ( $distribution as $bucket => $count ) :
                    $percent = $review_count > 0 ? ( $count / $review_count ) * 100 : 0;
                    $percent = max( 0.0, min( 100.0, (float) $percent ) );
                ?>
                    <li class="tejcart-rating-bar">
                        <span class="tejcart-rating-bar-label">
                            <?php
                            /* translators: %d: star rating value (1-5) */
                            printf(
                                esc_html__( '%d stars', 'tejcart' ),
                                (int) $bucket
                            );
                            ?>
                        </span>
                        <span class="tejcart-rating-bar-track" role="progressbar"
                            aria-valuemin="0" aria-valuemax="100"
                            aria-valuenow="<?php echo esc_attr( (string) round( $percent ) ); ?>"
                            aria-label="<?php echo esc_attr( sprintf(
                                __( '%1$d stars: %2$s%% of reviews', 'tejcart' ),
                                (int) $bucket,
                                number_format_i18n( $percent, 0 )
                            ) ); ?>">
                            <span class="tejcart-rating-bar-fill" style="width: <?php echo esc_attr( number_format( $percent, 2, '.', '' ) ); ?>%"></span>
                        </span>
                        <span class="tejcart-rating-bar-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tejcart-reviews-summary-action">
                <a href="#tejcart-review-form" class="tejcart-btn tejcart-btn--ghost tejcart-reviews-write-cta" data-tejcart-write-review>
                    <?php esc_html_e( 'Write a review', 'tejcart' ); ?>
                </a>
            </div>
        </section>

        <?php if ( $review_count > 1 ) : ?>
            <div class="tejcart-reviews-sort">
                <label for="tejcart-review-sort-select" class="tejcart-reviews-sort-label">
                    <?php esc_html_e( 'Sort by', 'tejcart' ); ?>
                </label>
                <select id="tejcart-review-sort-select" class="tejcart-reviews-sort-select" data-tejcart-review-sort>
                    <?php foreach ( $sort_options as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tejcart_sort, $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <ol class="tejcart-reviews-list">
            <?php foreach ( $reviews as $review ) :
                $rating   = (int) Product_Reviews::get_review_rating( $review->comment_ID );
                $verified = Product_Reviews::is_review_verified( $review->comment_ID );
                $word     = $tejcart_rating_word( $rating );

                $review_media  = $media_enabled ? Review_Media::get_media( (int) $review->comment_ID, 'approved' ) : array();
                $review_photos = array_filter( $review_media, static fn( $m ) => $m['media_type'] === 'photo' );
                $review_videos = array_filter( $review_media, static fn( $m ) => $m['media_type'] === 'video' );

                $legacy_photos = empty( $review_photos ) ? Product_Reviews::get_review_photos( (int) $review->comment_ID ) : array();

                $votes = isset( $vote_counts[ (int) $review->comment_ID ] )
                    ? $vote_counts[ (int) $review->comment_ID ]
                    : array( 'helpful' => 0, 'not_helpful' => 0, 'total' => 0 );

                $admin_reply = Product_Reviews::get_admin_reply( (int) $review->comment_ID );
            ?>
                <li class="tejcart-review" id="review-<?php echo esc_attr( (string) $review->comment_ID ); ?>">
                    <div class="tejcart-review-avatar" aria-hidden="true">
                        <?php echo esc_html( $tejcart_initials( $review->comment_author ) ); ?>
                    </div>
                    <div class="tejcart-review-body">
                        <div class="tejcart-review-header">
                            <span class="tejcart-review-author"><?php echo esc_html( $review->comment_author ); ?></span>
                            <?php if ( $verified && Product_Reviews::show_verified_label() ) : ?>
                                <span class="tejcart-review-verified" title="<?php esc_attr_e( 'This reviewer purchased the product', 'tejcart' ); ?>">
                                    <span class="tejcart-review-verified-icon" aria-hidden="true">&#10003;</span>
                                    <?php esc_html_e( 'Verified purchase', 'tejcart' ); ?>
                                </span>
                            <?php endif; ?>
                            <time class="tejcart-review-date" datetime="<?php echo esc_attr( $review->comment_date ); ?>">
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->comment_date ) ) ); ?>
                            </time>
                        </div>
                        <div class="tejcart-star-rating" aria-label="<?php echo esc_attr( sprintf(
                            /* translators: 1: rating word (e.g. "Excellent"), 2: numeric rating out of 5 */
                            __( '%1$s — rated %2$d out of 5', 'tejcart' ),
                            $word,
                            $rating
                        ) ); ?>">
                            <?php echo $tejcart_render_stars( $rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <div class="tejcart-review-content">
                            <?php echo wp_kses_post( wpautop( $review->comment_content ) ); ?>
                        </div>

                        <?php if ( ! empty( $review_photos ) ) : ?>
                            <div class="tejcart-review-photos">
                                <?php foreach ( $review_photos as $photo ) :
                                    $att_id    = (int) $photo['attachment_id'];
                                    $thumb_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
                                    $full_url  = wp_get_attachment_image_url( $att_id, 'large' );
                                    if ( ! $thumb_url ) { continue; }
                                    ?>
                                    <button type="button" class="tejcart-review-photo"
                                            data-full="<?php echo esc_url( $full_url ); ?>"
                                            aria-label="<?php esc_attr_e( 'View photo', 'tejcart' ); ?>">
                                        <img src="<?php echo esc_url( $thumb_url ); ?>"
                                             alt="<?php esc_attr_e( 'Review photo', 'tejcart' ); ?>"
                                             loading="lazy" decoding="async" />
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ( ! empty( $legacy_photos ) ) : ?>
                            <div class="tejcart-review-photos">
                                <?php foreach ( $legacy_photos as $photo_id ) :
                                    $thumb_url = wp_get_attachment_image_url( $photo_id, 'thumbnail' );
                                    $full_url  = wp_get_attachment_image_url( $photo_id, 'large' );
                                    if ( ! $thumb_url ) { continue; }
                                    ?>
                                    <button type="button" class="tejcart-review-photo"
                                            data-full="<?php echo esc_url( $full_url ); ?>"
                                            aria-label="<?php esc_attr_e( 'View photo', 'tejcart' ); ?>">
                                        <img src="<?php echo esc_url( $thumb_url ); ?>"
                                             alt="<?php esc_attr_e( 'Review photo', 'tejcart' ); ?>"
                                             loading="lazy" decoding="async" />
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $review_videos ) ) : ?>
                            <div class="tejcart-review-videos">
                                <?php foreach ( $review_videos as $video ) :
                                    $embed_url = Review_Media::get_embed_iframe_url( $video['embed_url'] ?? '' );
                                    if ( '' === $embed_url ) { continue; }
                                    ?>
                                    <div class="tejcart-review-video">
                                        <iframe
                                            src="<?php echo esc_url( $embed_url ); ?>"
                                            loading="lazy"
                                            allowfullscreen
                                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                            title="<?php esc_attr_e( 'Review video', 'tejcart' ); ?>"
                                        ></iframe>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $votes_enabled ) : ?>
                            <div class="tejcart-review-vote" data-tejcart-review-vote data-comment-id="<?php echo esc_attr( (string) $review->comment_ID ); ?>">
                                <span class="tejcart-review-vote-label"><?php esc_html_e( 'Was this helpful?', 'tejcart' ); ?></span>
                                <button type="button" class="tejcart-review-vote-btn tejcart-review-vote-yes"
                                        data-vote="1"
                                        aria-label="<?php esc_attr_e( 'Yes, this review was helpful', 'tejcart' ); ?>">
                                    <span class="tejcart-review-vote-icon" aria-hidden="true">&#9757;</span>
                                    <?php esc_html_e( 'Yes', 'tejcart' ); ?>
                                    <?php if ( $votes['helpful'] > 0 ) : ?>
                                        <span class="tejcart-review-vote-count" data-vote-count="helpful">(<?php echo esc_html( number_format_i18n( $votes['helpful'] ) ); ?>)</span>
                                    <?php endif; ?>
                                </button>
                                <button type="button" class="tejcart-review-vote-btn tejcart-review-vote-no"
                                        data-vote="-1"
                                        aria-label="<?php esc_attr_e( 'No, this review was not helpful', 'tejcart' ); ?>">
                                    <span class="tejcart-review-vote-icon" aria-hidden="true">&#9759;</span>
                                    <?php esc_html_e( 'No', 'tejcart' ); ?>
                                    <?php if ( $votes['not_helpful'] > 0 ) : ?>
                                        <span class="tejcart-review-vote-count" data-vote-count="not_helpful">(<?php echo esc_html( number_format_i18n( $votes['not_helpful'] ) ); ?>)</span>
                                    <?php endif; ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if ( $admin_reply ) : ?>
                            <div class="tejcart-review-reply">
                                <div class="tejcart-review-reply-header">
                                    <span class="tejcart-review-reply-badge"><?php esc_html_e( 'Store reply', 'tejcart' ); ?></span>
                                    <span class="tejcart-review-reply-author"><?php echo esc_html( $admin_reply->comment_author ); ?></span>
                                    <time class="tejcart-review-reply-date" datetime="<?php echo esc_attr( $admin_reply->comment_date ); ?>">
                                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $admin_reply->comment_date ) ) ); ?>
                                    </time>
                                </div>
                                <div class="tejcart-review-reply-content">
                                    <?php echo wp_kses_post( wpautop( $admin_reply->comment_content ) ); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>

    <?php else : ?>
        <div class="tejcart-reviews-empty">
            <div class="tejcart-reviews-empty-icon" aria-hidden="true">&#9734;&#9734;&#9734;&#9734;&#9734;</div>
            <p class="tejcart-reviews-empty-title"><?php esc_html_e( 'No reviews yet', 'tejcart' ); ?></p>
            <p class="tejcart-reviews-empty-copy"><?php esc_html_e( 'Be the first to share what you think — your review will help other shoppers decide.', 'tejcart' ); ?></p>
            <a href="#tejcart-review-form" class="tejcart-btn tejcart-btn--ghost tejcart-reviews-write-cta" data-tejcart-write-review>
                <?php esc_html_e( 'Write the first review', 'tejcart' ); ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="tejcart-review-form-wrapper" id="tejcart-review-form-wrapper">
        <h4 class="tejcart-review-form-title"><?php esc_html_e( 'Write a review', 'tejcart' ); ?></h4>
        <p class="tejcart-review-form-help">
            <?php esc_html_e( 'Reviews are moderated before they appear. Required fields are marked with *.', 'tejcart' ); ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tejcart_review_status = isset( $_GET['tejcart_review_status'] )
            ? sanitize_key( wp_unslash( (string) $_GET['tejcart_review_status'] ) )
            : '';
        if ( 'success' === $tejcart_review_status || 'pending' === $tejcart_review_status ) :
            ?>
            <div class="tejcart-review-form-notice tejcart-review-form-notice--success" role="status">
                <?php esc_html_e( 'Thanks! Your review was submitted and is awaiting moderation.', 'tejcart' ); ?>
            </div>
        <?php elseif ( 'error' === $tejcart_review_status ) : ?>
            <div class="tejcart-review-form-notice tejcart-review-form-notice--error" role="alert">
                <?php esc_html_e( 'Sorry, we couldn\'t save your review. Please complete the required fields and try again.', 'tejcart' ); ?>
            </div>
        <?php endif; ?>

        <form id="tejcart-review-form" class="tejcart-review-form" method="post" action="" novalidate data-tejcart-review-form enctype="multipart/form-data">
            <?php wp_nonce_field( 'tejcart_submit_review', 'tejcart_review_nonce' ); ?>
            <input type="hidden" name="tejcart_product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />

            <div class="tejcart-review-hp-wrap" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
                <label for="tejcart-review-website"><?php esc_html_e( 'Website (leave blank)', 'tejcart' ); ?></label>
                <input type="text" id="tejcart-review-website" name="tejcart_review_hp" value="" tabindex="-1" autocomplete="off" />
            </div>
            <input type="hidden" name="tejcart_review_ts" value="<?php echo esc_attr( (string) time() ); ?>" />

            <fieldset class="tejcart-field-row tejcart-review-rating-fieldset">
                <legend class="tejcart-review-rating-legend">
                    <?php esc_html_e( 'Your rating', 'tejcart' ); ?>
                    <?php if ( $rating_required ) : ?><span class="required" aria-hidden="true">*</span><?php endif; ?>
                </legend>
                <div class="tejcart-rating-input">
                    <div class="tejcart-star-selector" id="tejcart-star-selector" role="radiogroup"
                        <?php echo $rating_required ? 'aria-required="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        aria-label="<?php esc_attr_e( 'Your rating, from 1 (terrible) to 5 (excellent) stars', 'tejcart' ); ?>">
                        <?php for ( $i = 5; $i >= 1; $i-- ) : ?>
                            <label class="tejcart-star-label">
                                <input type="radio" name="tejcart_rating" value="<?php echo esc_attr( (string) $i ); ?>"
                                    data-rating="<?php echo esc_attr( (string) $i ); ?>"
                                    data-rating-word="<?php echo esc_attr( $tejcart_rating_word( $i ) ); ?>"
                                    <?php echo $rating_required ? 'required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    aria-label="<?php echo esc_attr( sprintf(
                                        /* translators: 1: number of stars, 2: rating word (e.g. "Excellent") */
                                        _n( '%1$d star (%2$s)', '%1$d stars (%2$s)', $i, 'tejcart' ),
                                        $i,
                                        $tejcart_rating_word( $i )
                                    ) ); ?> />
                                <span class="tejcart-star" aria-hidden="true">&#9733;</span>
                            </label>
                        <?php endfor; ?>
                    </div>
                    <output class="tejcart-rating-feedback" for="tejcart-star-selector" aria-live="polite" data-tejcart-rating-feedback>
                        <?php esc_html_e( 'Tap a star to rate', 'tejcart' ); ?>
                    </output>
                </div>
            </fieldset>

            <div class="tejcart-field-row">
                <label for="tejcart-review-content">
                    <?php esc_html_e( 'Your review', 'tejcart' ); ?>
                    <span class="required" aria-hidden="true">*</span>
                </label>
                <textarea id="tejcart-review-content" name="tejcart_review_content" rows="5"
                    placeholder="<?php esc_attr_e( 'What did you like or dislike? How did you use the product?', 'tejcart' ); ?>"
                    required></textarea>
            </div>

            <?php if ( ! is_user_logged_in() ) : ?>
                <div class="tejcart-review-form-grid">
                    <div class="tejcart-field-row">
                        <label for="tejcart-review-author">
                            <?php esc_html_e( 'Name', 'tejcart' ); ?>
                            <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="tejcart-review-author" name="tejcart_review_author"
                            autocomplete="name" required />
                    </div>

                    <div class="tejcart-field-row">
                        <label for="tejcart-review-email">
                            <?php esc_html_e( 'Email', 'tejcart' ); ?>
                            <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input type="email" id="tejcart-review-email" name="tejcart_review_email"
                            autocomplete="email" required />
                        <small class="tejcart-field-hint">
                            <?php esc_html_e( 'Your email is never published.', 'tejcart' ); ?>
                        </small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $media_enabled ) : ?>
                <div class="tejcart-field-row tejcart-review-photos-field">
                    <label for="tejcart-review-photos">
                        <?php esc_html_e( 'Add photos', 'tejcart' ); ?>
                    </label>
                    <input
                        type="file"
                        id="tejcart-review-photos"
                        name="tejcart_review_photos[]"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        data-max-files="<?php echo esc_attr( (string) Review_Media::MAX_PHOTOS_PER_REVIEW ); ?>"
                        data-max-size="<?php echo esc_attr( (string) Review_Media::MAX_PHOTO_SIZE_BYTES ); ?>"
                    />
                    <small class="tejcart-field-hint">
                        <?php
                        /* translators: 1: maximum number of photos, 2: maximum size per photo in MB */
                        echo esc_html( sprintf(
                            __( 'Up to %1$d photos, max %2$d MB each. JPEG, PNG, or WebP.', 'tejcart' ),
                            Review_Media::MAX_PHOTOS_PER_REVIEW,
                            Review_Media::MAX_PHOTO_SIZE_BYTES / ( 1024 * 1024 )
                        ) );
                        ?>
                    </small>
                    <div class="tejcart-review-photos-preview" data-tejcart-photos-preview hidden></div>
                </div>
            <?php endif; ?>

            <?php if ( $videos_enabled ) : ?>
                <div class="tejcart-field-row tejcart-review-video-field">
                    <label for="tejcart-review-video-url">
                        <?php esc_html_e( 'Add a video', 'tejcart' ); ?>
                    </label>
                    <input
                        type="url"
                        id="tejcart-review-video-url"
                        name="tejcart_review_video_url"
                        placeholder="<?php esc_attr_e( 'YouTube or Vimeo URL', 'tejcart' ); ?>"
                    />
                    <small class="tejcart-field-hint">
                        <?php esc_html_e( 'Paste a YouTube or Vimeo link to include a video with your review.', 'tejcart' ); ?>
                    </small>
                    <div class="tejcart-review-video-preview" data-tejcart-video-preview hidden></div>
                </div>
            <?php endif; ?>

            <div class="tejcart-review-form-actions">
                <button type="submit" class="tejcart-btn tejcart-btn-submit-review">
                    <?php esc_html_e( 'Submit review', 'tejcart' ); ?>
                </button>
            </div>
        </form>
    </div>

</div>
