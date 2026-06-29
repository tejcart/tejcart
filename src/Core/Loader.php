<?php
/**
 * TejCart Hook Registration Helper
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages registration of WordPress actions and filters.
 *
 * Collects all hooks during initialization and registers them
 * with WordPress in a single run() call.
 */
class Loader {
    /**
     * Registered actions.
     *
     * F-CORE-014: typed so PHPStan can validate array member shapes.
     *
     * @var array<int, array{hook: string, component: object, callback: string, priority: int, args: int}>
     */
    protected array $actions = array();

    /**
     * Registered filters.
     *
     * F-CORE-014: typed so PHPStan can validate array member shapes.
     *
     * @var array<int, array{hook: string, component: object, callback: string, priority: int, args: int}>
     */
    protected array $filters = array();

    /**
     * Whether run() has already been called. A second run() call
     * would re-add every action/filter to WordPress's hook table, so
     * each hook fires twice (or N+1 times). Idempotency-guard added
     * for audit H-7.
     *
     * @var bool
     */
    protected $ran = false;

    /**
     * Register an action hook.
     *
     * @param string $hook        The WordPress action hook name.
     * @param object $component   The object instance containing the callback.
     * @param string $callback    The method name on the component.
     * @param int    $priority    Hook priority. Default 10.
     * @param int    $args        Number of accepted arguments. Default 1.
     */
    public function add_action( $hook, $component, $callback, $priority = 10, $args = 1 ) {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $args );
    }

    /**
     * Register a filter hook.
     *
     * @param string $hook        The WordPress filter hook name.
     * @param object $component   The object instance containing the callback.
     * @param string $callback    The method name on the component.
     * @param int    $priority    Hook priority. Default 10.
     * @param int    $args        Number of accepted arguments. Default 1.
     */
    public function add_filter( $hook, $component, $callback, $priority = 10, $args = 1 ) {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $args );
    }

    /**
     * Add a hook entry to the given collection.
     *
     * @param array  $hooks      The existing hooks collection.
     * @param string $hook       The hook name.
     * @param object $component  The object instance.
     * @param string $callback   The method name.
     * @param int    $priority   Hook priority.
     * @param int    $args       Number of accepted arguments.
     * @return array The updated hooks collection.
     */
    private function add( $hooks, $hook, $component, $callback, $priority, $args ) {
        $hooks[] = array(
            'hook'      => $hook,
            'component' => $component,
            'callback'  => $callback,
            'priority'  => $priority,
            'args'      => $args,
        );

        return $hooks;
    }

    /**
     * Register all collected actions and filters with WordPress.
     *
     * Idempotent: a second call is a no-op. Re-registering the same
     * (hook, callable, priority) pair makes the callback fire twice
     * for each event — which double-charges customers, double-sends
     * emails, double-decrements stock. Audit H-7.
     */
    public function run() {
        if ( $this->ran ) {
            return;
        }
        $this->ran = true;

        // Audit L-16 (Core F-018): register actions BEFORE filters so
        // listeners that set up state in an action and consume it in a
        // filter see the expected causality order.
        foreach ( $this->actions as $hook ) {
            add_action(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['args']
            );
        }

        foreach ( $this->filters as $hook ) {
            add_filter(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['args']
            );
        }
    }

    /**
     * Get all registered actions.
     *
     * @return array
     */
    public function get_actions() {
        return $this->actions;
    }

    /**
     * Get all registered filters.
     *
     * @return array
     */
    public function get_filters() {
        return $this->filters;
    }
}
