<?php
/**
 * Background job handler — invoked by Action Scheduler / WP-Cron for
 * the `tejcart_ai_content_generate_single` hook.
 *
 * @package TejCart\AI_Content_Smartsuite\Background
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Background;

use TejCart\AI_Content_Smartsuite\Generator\Bulk_Generator;
use TejCart\AI_Content_Smartsuite\Generator\Generator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Job_Runner {
    public static function register(): void {
        add_action( Bulk_Generator::JOB_HOOK, array( __CLASS__, 'run' ), 10, 3 );
    }

    public static function run( string $batch_id, int $product_id, string $field ): void {
        $batch_id   = (string) $batch_id;
        $product_id = (int) $product_id;
        $field      = (string) $field;

        try {
            $generator = new Generator();
            $result    = $generator->generate( $product_id, $field );
            $ok        = ! empty( $result['ok'] );
            $error     = $ok ? '' : (string) ( $result['error'] ?? 'unknown' );
        } catch ( \Throwable $e ) {
            $ok    = false;
            $error = $e->getMessage();
            tejcart_log(
                sprintf( 'Bulk job exception product=%d field=%s: %s', $product_id, $field, $error ),
                'error',
                array( 'source' => 'ai_content_smartsuite', 'batch_id' => $batch_id )
            );
        }

        Bulk_Generator::record_completion( $batch_id, $product_id, $ok, $error );
    }
}
