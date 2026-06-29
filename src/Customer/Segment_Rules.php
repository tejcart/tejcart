<?php
/**
 * Custom segment rule evaluation engine.
 *
 * Translates admin-defined rules into SQL WHERE clauses that run against
 * the `tejcart_customers` table. Rules are AND-combined (all must match).
 *
 * Supported rule types:
 *   - total_spent     — LTV comparison (operator + value in major units)
 *   - order_count     — Number of completed orders
 *   - last_order_days — Days since last order
 *   - rfm_score       — Composite RFM score (1–125)
 *   - segment         — Auto-segment slug match
 *   - registered_days — Days since registration
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Segment_Rules {

    private const ALLOWED_OPERATORS = array( '>', '>=', '<', '<=', '=', '!=' );

    private const RULE_HANDLERS = array(
        'total_spent'     => 'rule_total_spent',
        'order_count'     => 'rule_order_count',
        'last_order_days' => 'rule_last_order_days',
        'rfm_score'       => 'rule_rfm_score',
        'segment'         => 'rule_segment',
        'registered_days' => 'rule_registered_days',
    );

    /**
     * Build a parameterised WHERE clause from an array of rules.
     *
     * Each rule is: ['type' => string, 'operator' => string, 'value' => mixed]
     *
     * @param array $rules Rule definitions.
     * @return array{where:string, params:array} SQL fragment + bound params.
     */
    public static function build_where_clause( array $rules ): array {
        $conditions = array();
        $params     = array();

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
                continue;
            }

            $type = (string) $rule['type'];
            if ( ! isset( self::RULE_HANDLERS[ $type ] ) ) {
                continue;
            }

            $handler = self::RULE_HANDLERS[ $type ];
            $result  = self::$handler( $rule );

            if ( null === $result ) {
                continue;
            }

            $conditions[] = $result['sql'];
            foreach ( $result['params'] as $p ) {
                $params[] = $p;
            }
        }

        if ( empty( $conditions ) ) {
            return array( 'where' => '', 'params' => array() );
        }

        return array(
            'where'  => implode( ' AND ', $conditions ),
            'params' => $params,
        );
    }

    /**
     * Validate a single rule definition.
     *
     * @return true|\WP_Error
     */
    public static function validate_rule( array $rule ) {
        if ( empty( $rule['type'] ) || ! isset( self::RULE_HANDLERS[ $rule['type'] ] ) ) {
            return new \WP_Error( 'invalid_rule_type', 'Unknown rule type.' );
        }

        if ( 'segment' === $rule['type'] ) {
            if ( empty( $rule['value'] ) || ! is_string( $rule['value'] ) ) {
                return new \WP_Error( 'invalid_rule_value', 'Segment rule requires a string value.' );
            }
            return true;
        }

        if ( ! isset( $rule['operator'] ) || ! in_array( $rule['operator'], self::ALLOWED_OPERATORS, true ) ) {
            return new \WP_Error( 'invalid_operator', 'Invalid comparison operator.' );
        }

        if ( ! isset( $rule['value'] ) || ! is_numeric( $rule['value'] ) ) {
            return new \WP_Error( 'invalid_value', 'Rule value must be numeric.' );
        }

        return true;
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_total_spent( array $rule ): ?array {
        $op = self::safe_operator( $rule['operator'] ?? '' );
        if ( null === $op || ! is_numeric( $rule['value'] ?? null ) ) {
            return null;
        }

        $shop_currency = (string) get_option( 'tejcart_currency', 'USD' );
        $multiplier    = Currency::multiplier( $shop_currency );
        $minor_value   = (int) round( (float) $rule['value'] * $multiplier );

        return array(
            'sql'    => "ltv_minor_units {$op} %d",
            'params' => array( $minor_value ),
        );
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_order_count( array $rule ): ?array {
        $op = self::safe_operator( $rule['operator'] ?? '' );
        if ( null === $op || ! is_numeric( $rule['value'] ?? null ) ) {
            return null;
        }

        return array(
            'sql'    => "order_count {$op} %d",
            'params' => array( (int) $rule['value'] ),
        );
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_last_order_days( array $rule ): ?array {
        $op = self::safe_operator( $rule['operator'] ?? '' );
        if ( null === $op || ! is_numeric( $rule['value'] ?? null ) ) {
            return null;
        }

        $days = (int) $rule['value'];

        return array(
            'sql'    => "last_order_at IS NOT NULL AND DATEDIFF(NOW(), last_order_at) {$op} %d",
            'params' => array( $days ),
        );
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_rfm_score( array $rule ): ?array {
        $op = self::safe_operator( $rule['operator'] ?? '' );
        if ( null === $op || ! is_numeric( $rule['value'] ?? null ) ) {
            return null;
        }

        return array(
            'sql'    => "rfm_score {$op} %d",
            'params' => array( (int) $rule['value'] ),
        );
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_segment( array $rule ): ?array {
        $value = $rule['value'] ?? '';
        if ( ! is_string( $value ) || '' === $value ) {
            return null;
        }

        $op = ( $rule['operator'] ?? '=' ) === '!=' ? '!=' : '=';

        return array(
            'sql'    => "segment {$op} %s",
            'params' => array( $value ),
        );
    }

    /**
     * @return array{sql:string, params:array}|null
     */
    private static function rule_registered_days( array $rule ): ?array {
        $op = self::safe_operator( $rule['operator'] ?? '' );
        if ( null === $op || ! is_numeric( $rule['value'] ?? null ) ) {
            return null;
        }

        return array(
            'sql'    => "DATEDIFF(NOW(), created_at) {$op} %d",
            'params' => array( (int) $rule['value'] ),
        );
    }

    /**
     * Whitelist-validate an operator string.
     */
    private static function safe_operator( string $op ): ?string {
        return in_array( $op, self::ALLOWED_OPERATORS, true ) ? $op : null;
    }
}
