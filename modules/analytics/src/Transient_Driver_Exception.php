<?php
/**
 * Exception thrown by analytics drivers when an upstream returned a
 * transient (5xx or 429) HTTP status and the event should be retried.
 *
 * Action Scheduler treats any throwable from the dispatcher's
 * `handle_fanout()` callback as a job failure and re-queues with
 * exponential backoff, so the dispatcher only needs to bubble these
 * up — see Audit #20 / 07 F-5.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Transient_Driver_Exception extends \RuntimeException {
}
