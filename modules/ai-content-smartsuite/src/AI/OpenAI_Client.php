<?php
/**
 * OpenAI client — wraps wp_remote_post on /v1/chat/completions and the
 * key-validation /v1/models lookup.
 *
 * @package TejCart\AI_Content_Smartsuite\AI
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\AI;

use TejCart\AI_Content_Smartsuite\Languages;
use TejCart\AI_Content_Smartsuite\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenAI_Client {
    public const ENDPOINT_CHAT   = 'https://api.openai.com/v1/chat/completions';
    public const ENDPOINT_MODELS = 'https://api.openai.com/v1/models';

    public const LOG_SOURCE = 'ai_content_smartsuite';

    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Default per-request token budget. Sized to comfortably cover a
     * product description + FAQ block without wasting context window
     * on a free/low-tier OpenAI account.
     */
    public const DEFAULT_MAX_TOKENS = 2000;

    /**
     * Absolute upper bound on max_tokens, enforced after the request-
     * body filter runs. The filter can ratchet the value down but
     * cannot raise it past this number, so a misconfigured extension
     * can't run up an unbounded OpenAI bill.
     */
    public const MAX_TOKENS_CEILING = 4000;

    /** Max number of attempts for a single completion (initial + retries). */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** Base sleep in milliseconds for exponential backoff. */
    public const DEFAULT_BACKOFF_BASE_MS = 500;

    /** Cap any individual backoff at this many seconds. */
    public const DEFAULT_BACKOFF_CAP_S = 30;

    /**
     * @param string $prompt
     * @param array{model?:string, language?:string, api_key?:string, product_id?:int, field?:string} $context
     *
     * @return array{ok:bool, content?:string, error?:string, http_status?:int, duration_ms?:int}
     */
    public function complete( string $prompt, array $context = array() ): array {
        $settings = Settings::get();
        $api_key  = (string) ( $context['api_key'] ?? $settings['api_key'] );
        $model    = (string) ( $context['model']   ?? $settings['model']   );
        $language = (string) ( $context['language'] ?? $settings['language'] );

        if ( '' === $api_key ) {
            return array(
                'ok'    => false,
                'error' => __( 'OpenAI API key is not configured.', 'tejcart' ),
            );
        }

        $rate_error = Rate_Guard::check();
        if ( '' !== $rate_error ) {
            return array(
                'ok'    => false,
                'error' => $rate_error,
            );
        }

        $allowed = Settings::allowed_models();
        if ( ! in_array( $model, $allowed, true ) ) {
            $model = $allowed[0] ?? Settings::ALLOWED_MODELS[0];
        }

        $language_label = Languages::label_for( $language );
        $system_msg     = Prompt_Renderer::system_prompt_preamble() . "\n\n"
            . sprintf(
                'You are a senior eCommerce copywriter. Write clear, scannable product copy that converts; avoid superlatives, do not fabricate features, and respond in %s. Output HTML markup (use <strong>, <em>, <ul>, <li>, <p> tags) instead of Markdown syntax.',
                $language_label
            );
        if ( ! Languages::is_english( $language ) ) {
            $system_msg .= ' Do Not Use English';
        }

        $temperature = (float) ( $settings['temperature'] ?? self::DEFAULT_TEMPERATURE );
        $temperature = max( 0.0, min( 2.0, $temperature ) );

        $body = array(
            'model'       => $model,
            'temperature' => $temperature,
            'max_tokens'  => self::DEFAULT_MAX_TOKENS,
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system_msg ),
                array( 'role' => 'user',   'content' => $prompt ),
            ),
        );

        /**
         * Filter the OpenAI request body just before sending.
         *
         * Note: any `max_tokens` returned by the filter is clamped to
         * `self::MAX_TOKENS_CEILING` afterwards. The filter can lower
         * the per-request token budget but cannot raise it past the
         * built-in ceiling, so a misconfigured extension cannot drive
         * the merchant's OpenAI bill arbitrarily high.
         */
        $body = (array) apply_filters( 'tejcart_ai_content_openai_request', $body, $context );

        // Clamp max_tokens after the filter so it can only narrow the
        // ceiling, never widen it. Also catches a filter that strips
        // the key entirely (would fall back to OpenAI's account-wide
        // default which can be very large on paid plans).
        $requested_tokens = isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : self::DEFAULT_MAX_TOKENS;
        if ( $requested_tokens < 1 ) {
            $requested_tokens = self::DEFAULT_MAX_TOKENS;
        }
        $body['max_tokens'] = min( $requested_tokens, self::MAX_TOKENS_CEILING );

        $endpoint = (string) apply_filters( 'tejcart_ai_content_openai_endpoint', self::ENDPOINT_CHAT, $context );

        $payload = wp_json_encode( $body );
        if ( ! is_string( $payload ) ) {
            return array(
                'ok'    => false,
                'error' => __( 'Failed to encode request body.', 'tejcart' ),
            );
        }

        tejcart_log(
            'OpenAI request',
            'info',
            array(
                'source'      => self::LOG_SOURCE,
                'endpoint'    => $endpoint,
                'model'       => $model,
                'language'    => $language,
                'product_id'  => (int) ( $context['product_id'] ?? 0 ),
                'field'       => (string) ( $context['field'] ?? '' ),
                'prompt_len'  => strlen( $prompt ),
            )
        );

        /**
         * Filter the per-request retry budget. Lower it (or set to 1)
         * to disable retries on integration tests; raise it on
         * high-volume installs that proxy through a tolerant gateway.
         */
        $max_attempts = (int) apply_filters( 'tejcart_ai_content_openai_max_attempts', self::DEFAULT_MAX_ATTEMPTS, $context );
        if ( $max_attempts < 1 ) {
            $max_attempts = 1;
        }

        $attempt     = 0;
        $started_all = microtime( true );
        $response    = null;
        $code        = 0;
        $raw         = '';
        $duration_ms = 0;

        while ( $attempt < $max_attempts ) {
            $attempt++;
            $started = microtime( true );

            $response = wp_remote_post(
                $endpoint,
                array(
                    'timeout' => self::DEFAULT_TIMEOUT,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ),
                    'body'    => $payload,
                )
            );

            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

            // Transport failure (DNS / TCP / TLS / timeout). Retry on a
            // bounded backoff — these are almost always transient.
            if ( is_wp_error( $response ) ) {
                $err = $response->get_error_message();
                tejcart_log(
                    'OpenAI transport error',
                    'warning',
                    array(
                        'source'       => self::LOG_SOURCE,
                        'attempt'      => $attempt,
                        'max_attempts' => $max_attempts,
                        'duration_ms'  => $duration_ms,
                        'message'      => $err,
                    )
                );
                if ( $attempt < $max_attempts ) {
                    self::backoff_sleep( $attempt, null );
                    continue;
                }
                return array(
                    'ok'          => false,
                    'error'       => $err,
                    'duration_ms' => (int) round( ( microtime( true ) - $started_all ) * 1000 ),
                );
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = (string) wp_remote_retrieve_body( $response );

            if ( $code >= 200 && $code < 300 ) {
                break;
            }

            // 429 / 5xx are transient — retry with backoff that honours
            // an upstream Retry-After header when present. 4xx other
            // than 429 are caller errors (auth, payload, model id) and
            // will not improve on retry, so fail fast.
            $is_retryable = ( 429 === $code ) || ( $code >= 500 && $code < 600 );
            if ( $is_retryable && $attempt < $max_attempts ) {
                $retry_after = self::parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
                tejcart_log(
                    'OpenAI retryable response',
                    'warning',
                    array(
                        'source'       => self::LOG_SOURCE,
                        'attempt'      => $attempt,
                        'max_attempts' => $max_attempts,
                        'http_status'  => $code,
                        'retry_after'  => $retry_after,
                        'duration_ms'  => $duration_ms,
                    )
                );
                self::backoff_sleep( $attempt, $retry_after );
                continue;
            }

            $msg = self::extract_api_error( $raw, $code );
            tejcart_log(
                'OpenAI non-2xx response',
                'error',
                array(
                    'source'       => self::LOG_SOURCE,
                    'attempt'      => $attempt,
                    'max_attempts' => $max_attempts,
                    'http_status'  => $code,
                    'duration_ms'  => $duration_ms,
                    'body'         => mb_substr( $raw, 0, 2000 ),
                )
            );
            return array(
                'ok'          => false,
                'error'       => $msg,
                'http_status' => $code,
                'duration_ms' => (int) round( ( microtime( true ) - $started_all ) * 1000 ),
            );
        }

        $duration_ms = (int) round( ( microtime( true ) - $started_all ) * 1000 );

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            tejcart_log( 'OpenAI response was not JSON', 'error', array( 'source' => self::LOG_SOURCE, 'http_status' => $code, 'duration_ms' => $duration_ms ) );
            return array(
                'ok'          => false,
                'error'       => __( 'Unexpected response from OpenAI (not JSON).', 'tejcart' ),
                'http_status' => $code,
                'duration_ms' => $duration_ms,
            );
        }

        $content = isset( $decoded['choices'][0]['message']['content'] )
            ? (string) $decoded['choices'][0]['message']['content']
            : '';

        if ( '' === trim( $content ) ) {
            tejcart_log( 'OpenAI response empty content', 'warning', array( 'source' => self::LOG_SOURCE, 'http_status' => $code, 'duration_ms' => $duration_ms ) );
            return array(
                'ok'          => false,
                'error'       => __( 'OpenAI returned an empty response.', 'tejcart' ),
                'http_status' => $code,
                'duration_ms' => $duration_ms,
            );
        }

        $total_tokens = (int) ( $decoded['usage']['total_tokens'] ?? 0 );

        tejcart_log(
            'OpenAI response',
            'info',
            array(
                'source'        => self::LOG_SOURCE,
                'http_status'   => $code,
                'duration_ms'   => $duration_ms,
                'content_chars' => strlen( $content ),
                'usage'         => $decoded['usage'] ?? null,
            )
        );

        Rate_Guard::record( $total_tokens );

        return array(
            'ok'          => true,
            'content'     => $content,
            'http_status' => $code,
            'duration_ms' => $duration_ms,
        );
    }

    /**
     * @return array{ok:bool, message:string, http_status?:int}
     */
    public function validate_key( string $api_key ): array {
        $api_key = trim( $api_key );
        if ( '' === $api_key ) {
            return array(
                'ok'      => false,
                'message' => __( 'Empty', 'tejcart' ),
            );
        }

        $response = wp_remote_get(
            self::ENDPOINT_MODELS,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'ok'      => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            return array(
                'ok'          => true,
                'message'     => __( 'Valid', 'tejcart' ),
                'http_status' => $code,
            );
        }

        return array(
            'ok'          => false,
            'message'     => self::extract_api_error( (string) wp_remote_retrieve_body( $response ), $code ),
            'http_status' => $code,
        );
    }

    /**
     * Parse a `Retry-After` header value. Returns the wait time in
     * seconds (capped to {@see self::DEFAULT_BACKOFF_CAP_S}), or null
     * when the header is absent or unparseable.
     *
     * @param mixed $header
     */
    private static function parse_retry_after( $header ): ?int {
        if ( is_array( $header ) ) {
            $header = reset( $header );
        }
        $raw = trim( (string) $header );
        if ( '' === $raw ) {
            return null;
        }
        // RFC 7231 allows either delta-seconds or an HTTP-date. Most
        // gateways emit delta-seconds; only handle the date form when it
        // parses cleanly so we never throw on weird inputs.
        if ( ctype_digit( $raw ) ) {
            $secs = (int) $raw;
        } else {
            $ts = strtotime( $raw );
            if ( false === $ts ) {
                return null;
            }
            $secs = max( 0, $ts - time() );
        }
        if ( $secs <= 0 ) {
            return 0;
        }
        return min( $secs, self::DEFAULT_BACKOFF_CAP_S );
    }

    /**
     * Sleep for an exponential-backoff window with full jitter, or for
     * the upstream-supplied retry-after when one is present. Capped at
     * {@see self::DEFAULT_BACKOFF_CAP_S} so a hostile gateway can't pin
     * a worker for an unbounded interval.
     */
    private static function backoff_sleep( int $attempt, ?int $retry_after_secs ): void {
        $delay_ms = self::DEFAULT_BACKOFF_BASE_MS * ( 2 ** max( 0, $attempt - 1 ) );
        // Full jitter: pick uniformly in [0, delay_ms).
        $delay_ms = random_int( 0, max( 1, $delay_ms ) );

        if ( null !== $retry_after_secs ) {
            $delay_ms = max( $delay_ms, $retry_after_secs * 1000 );
        }
        $cap_ms = self::DEFAULT_BACKOFF_CAP_S * 1000;
        if ( $delay_ms > $cap_ms ) {
            $delay_ms = $cap_ms;
        }
        if ( $delay_ms <= 0 ) {
            return;
        }
        usleep( $delay_ms * 1000 );
    }

    private static function extract_api_error( string $raw, int $code ): string {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
            return (string) $decoded['error']['message'];
        }
        return sprintf(
            /* translators: %d HTTP status code */
            __( 'OpenAI returned HTTP %d.', 'tejcart' ),
            $code
        );
    }
}
