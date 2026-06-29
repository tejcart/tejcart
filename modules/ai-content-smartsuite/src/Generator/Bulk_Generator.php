<?php
/**
 * Bulk generation orchestrator.
 *
 * @package TejCart\AI_Content_Smartsuite\Generator
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Generator;

use TejCart\Core\Action_Scheduler;
use TejCart\AI_Content_Smartsuite\Content\Content_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Bulk_Generator {
    public const JOB_HOOK         = 'tejcart_ai_content_generate_single';
    public const TRANSIENT_PREFIX = 'tejcart_ai_bulk_';
    public const TRANSIENT_TTL    = 12 * HOUR_IN_SECONDS;

    public const MAX_PER_REQUEST = 200;

    /**
     * Lower per-batch ceiling when Action Scheduler is unavailable and
     * we fall back to WP-Cron. WP-Cron processes a single hook per
     * request and each completion can retry up to
     * `OpenAI_Client::DEFAULT_MAX_ATTEMPTS` times at
     * `OpenAI_Client::DEFAULT_TIMEOUT` (30 s) plus backoff — so a 200-job
     * batch can monopolise the cron runner for a long stretch. 25 keeps
     * the worst case bounded.
     */
    public const MAX_PER_REQUEST_DEGRADED = 25;

    /**
     * @param int[]  $product_ids
     * @return array{ok:bool, batch_id?:string, total?:int, error?:string}
     */
    public function enqueue( array $product_ids, string $field ): array {
        if ( ! Content_Repository::is_field( $field ) ) {
            return array( 'ok' => false, 'error' => __( 'Unknown field.', 'tejcart' ) );
        }

        $product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
        if ( empty( $product_ids ) ) {
            return array( 'ok' => false, 'error' => __( 'No products selected.', 'tejcart' ) );
        }

        $as_available  = Action_Scheduler::is_action_scheduler_available();
        $effective_cap = $as_available ? self::MAX_PER_REQUEST : self::MAX_PER_REQUEST_DEGRADED;

        if ( count( $product_ids ) > $effective_cap ) {
            $message = $as_available
                ? sprintf(
                    /* translators: %d max */
                    __( 'Too many products selected. Maximum %d per batch.', 'tejcart' ),
                    $effective_cap
                )
                : sprintf(
                    /* translators: %d max */
                    __( 'Too many products selected. Action Scheduler is not active, so the WP-Cron fallback is in use; please limit each batch to %d products or install Action Scheduler.', 'tejcart' ),
                    $effective_cap
                );
            return array(
                'ok'    => false,
                'error' => $message,
            );
        }

        $batch_id = self::generate_batch_id();
        $progress = array(
            'batch_id'  => $batch_id,
            'field'     => $field,
            'total'     => count( $product_ids ),
            'queued'    => count( $product_ids ),
            'completed' => 0,
            'failed'    => 0,
            'started'   => time(),
            'updated'   => time(),
            'errors'    => array(),
        );
        set_transient( self::TRANSIENT_PREFIX . $batch_id, $progress, self::TRANSIENT_TTL );

        $scheduler = Action_Scheduler::instance();
        $now       = time();
        foreach ( $product_ids as $i => $pid ) {
            $scheduler->schedule_single(
                $now + max( 1, (int) floor( $i / 5 ) ),
                self::JOB_HOOK,
                array( $batch_id, (int) $pid, $field )
            );
        }

        return array(
            'ok'       => true,
            'batch_id' => $batch_id,
            'total'    => count( $product_ids ),
        );
    }

    /**
     * @return array{batch_id:string,field:string,total:int,queued:int,completed:int,failed:int,started:int,updated:int,errors:array}|null
     */
    public static function get_progress( string $batch_id ): ?array {
        if ( '' === $batch_id ) {
            return null;
        }
        $val = get_transient( self::TRANSIENT_PREFIX . $batch_id );
        return is_array( $val ) ? $val : null;
    }

    public static function record_completion( string $batch_id, int $product_id, bool $ok, string $error = '' ): void {
        $progress = self::get_progress( $batch_id );
        if ( null === $progress ) {
            return;
        }
        if ( $ok ) {
            $progress['completed'] = (int) $progress['completed'] + 1;
        } else {
            $progress['failed'] = (int) $progress['failed'] + 1;
            if ( '' !== $error ) {
                $progress['errors'][] = array(
                    'product_id' => $product_id,
                    'error'      => $error,
                );
                if ( count( $progress['errors'] ) > 50 ) {
                    $progress['errors'] = array_slice( $progress['errors'], -50 );
                }
            }
        }
        $progress['queued']  = max( 0, (int) $progress['queued'] - 1 );
        $progress['updated'] = time();

        set_transient( self::TRANSIENT_PREFIX . $batch_id, $progress, self::TRANSIENT_TTL );
    }

    private static function generate_batch_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return bin2hex( random_bytes( 8 ) );
    }
}
