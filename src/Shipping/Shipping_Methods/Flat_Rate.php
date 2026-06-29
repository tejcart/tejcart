<?php
/**
 * Flat Rate shipping method.
 *
 * @package TejCart\Shipping\Shipping_Methods
 */

declare( strict_types=1 );

namespace TejCart\Shipping\Shipping_Methods;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Charges a fixed shipping cost — or a formula — regardless of cart contents.
 *
 * Base "cost" and per-class cost fields accept either a plain number
 * (e.g. `5.00`) or a formula using placeholders:
 *
 *   [qty]                                    Number of items in cart.
 *   [cost]                                   Cart line-item subtotal.
 *   [fee percent="10" min_fee="5" max_fee=""] Percentage-based fee with
 *                                            optional floor and ceiling.
 *
 * Example formulas:
 *   10 + ( 2 * [qty] )
 *   5 + [fee percent="10" min_fee="4"]
 */
class Flat_Rate extends Abstract_Shipping_Method {
    /**
     * Method identifier.
     *
     * @var string
     */
    protected $id = 'flat_rate';

    /**
     * Method title.
     *
     * @var string
     */
    protected $title = 'Flat Rate';

    /**
     * Calculate shipping cost.
     *
     * Returns the configured flat cost, plus any per-class surcharges
     * based on product shipping classes assigned via `_shipping_class` meta.
     *
     * Settings:
     *   - cost: base flat rate cost (number or formula).
     *   - class_costs: associative array of shipping class slug => additional cost (number or formula).
     *
     * @param mixed $cart Cart instance.
     * @return float Shipping cost.
     */
    public function calculate( $cart ) {
        $context = $this->build_formula_context( $cart );

        $raw_base = isset( $this->settings['cost'] ) ? (string) $this->settings['cost'] : '0';
        $cost     = $this->evaluate_formula( $raw_base, $context );

        $class_costs = isset( $this->settings['class_costs'] ) && is_array( $this->settings['class_costs'] )
            ? $this->settings['class_costs']
            : array();

        if ( ! empty( $class_costs ) && is_object( $cart ) && method_exists( $cart, 'get_items' ) ) {
            $applied_classes = array();

            foreach ( $cart->get_items() as $item ) {
                $product = null;

                if ( method_exists( $item, 'get_product' ) ) {
                    $product = $item->get_product();
                }

                if ( ! $product || ! method_exists( $product, 'get_meta' ) ) {
                    continue;
                }

                $shipping_class = $product->get_meta( '_shipping_class' );

                if ( ! empty( $shipping_class ) && ! isset( $applied_classes[ $shipping_class ] ) ) {
                    if ( isset( $class_costs[ $shipping_class ] ) ) {
                        $cost += $this->evaluate_formula( (string) $class_costs[ $shipping_class ], $context );
                        $applied_classes[ $shipping_class ] = true;
                    }
                }
            }
        }

        $this->maybe_log_debug( $raw_base, $context, $cost );

        return $this->round_cost( $cost );
    }

    /**
     * Build the scalar context used by the formula parser.
     *
     * @param mixed $cart Cart instance.
     * @return array{qty: int, cost: float}
     */
    private function build_formula_context( $cart ): array {
        $qty  = 0;
        $cost = 0.0;

        if ( is_object( $cart ) && method_exists( $cart, 'get_items' ) ) {
            foreach ( $cart->get_items() as $item ) {
                $item_qty = 0;

                if ( is_object( $item ) && method_exists( $item, 'get_quantity' ) ) {
                    $item_qty = (int) $item->get_quantity();
                } elseif ( is_array( $item ) && isset( $item['quantity'] ) ) {
                    $item_qty = (int) $item['quantity'];
                }

                $qty += max( 0, $item_qty );

                if ( is_object( $item ) && method_exists( $item, 'get_subtotal' ) ) {
                    $cost += (float) $item->get_subtotal();
                } elseif ( is_array( $item ) && isset( $item['line_total'] ) ) {
                    $cost += (float) $item['line_total'];
                }
            }
        }

        return array(
            'qty'  => $qty,
            'cost' => $cost,
        );
    }

    /**
     * Evaluate a flat-rate cost formula.
     *
     * Returns 0.0 when the formula is empty, malformed, or contains
     * characters outside the allow-list.
     *
     * @param string                $formula Raw setting value.
     * @param array{qty:int,cost:float} $context Computed cart scalars.
     * @return float
     */
    public function evaluate_formula( string $formula, array $context ): float {
        $formula = trim( $formula );

        if ( '' === $formula ) {
            return 0.0;
        }

        if ( is_numeric( $formula ) ) {
            return (float) $formula;
        }

        $expanded = preg_replace_callback(
            '/\[fee([^\]]*)\]/i',
            function ( array $match ) use ( $context ): string {
                return (string) $this->calculate_fee_token( $match[1], $context );
            },
            $formula
        );

        if ( ! is_string( $expanded ) ) {
            return 0.0;
        }

        $expanded = str_ireplace(
            array( '[qty]', '[cost]' ),
            array( (string) $context['qty'], $this->format_number( $context['cost'] ) ),
            $expanded
        );

        if ( ! preg_match( '/^[0-9+\-*\/().\s]*$/', $expanded ) ) {
            return 0.0;
        }

        $expr = trim( $expanded );
        if ( '' === $expr ) {
            return 0.0;
        }

        $result = $this->evaluate_math( $expr );

        return is_finite( $result ) ? (float) $result : 0.0;
    }

    /**
     * Compute the numeric value of a single `[fee ...]` token.
     *
     * @param string                      $attrs   Raw attribute string from the shortcode.
     * @param array{qty:int,cost:float}   $context Computed cart scalars.
     * @return float
     */
    private function calculate_fee_token( string $attrs, array $context ): float {
        $parsed  = shortcode_parse_atts( $attrs );
        $parsed  = is_array( $parsed ) ? $parsed : array();

        // Shipping fees are never negative. A typo'd `percent="-10"`,
        // `min_fee="-5"`, or a `min_fee` higher than `max_fee` would
        // otherwise feed back a negative or upside-down fee and silently
        // discount the order's shipping line — the kind of misconfig
        // a merchant only notices when their margin disappears.
        $percent = isset( $parsed['percent'] ) ? max( 0.0, (float) $parsed['percent'] ) : 0.0;
        $min     = isset( $parsed['min_fee'] ) && '' !== $parsed['min_fee']
            ? max( 0.0, (float) $parsed['min_fee'] )
            : null;
        $max     = isset( $parsed['max_fee'] ) && '' !== $parsed['max_fee']
            ? max( 0.0, (float) $parsed['max_fee'] )
            : null;

        if ( null !== $min && null !== $max && $min > $max ) {
            // Operator intent is ambiguous when min > max; clamp by treating
            // the lower of the two as the floor so the resulting fee is
            // always inside a well-ordered range.
            [ $min, $max ] = array( $max, $min );
        }

        $cart_cost = max( 0.0, (float) $context['cost'] );
        $fee       = ( $percent / 100 ) * $cart_cost;

        if ( null !== $min && $fee < $min ) {
            $fee = $min;
        }

        if ( null !== $max && $fee > $max ) {
            $fee = $max;
        }

        return max( 0.0, (float) $fee );
    }

    /**
     * Safely evaluate an arithmetic expression over numbers and parentheses.
     *
     * Hand-rolled shunting-yard over a pre-validated token stream so we never
     * invoke `eval()` on user input.
     *
     * @param string $expr Expression containing only digits, `.`, `+-*\/`, and parens.
     * @return float
     */
    private function evaluate_math( string $expr ): float {
        $tokens = $this->tokenize_expression( $expr );

        if ( empty( $tokens ) ) {
            return 0.0;
        }

        $output     = array();
        $operators  = array();
        $precedence = array(
            '+' => 1,
            '-' => 1,
            '*' => 2,
            '/' => 2,
        );

        foreach ( $tokens as $token ) {
            if ( is_float( $token ) || is_int( $token ) ) {
                $output[] = (float) $token;
                continue;
            }

            if ( '(' === $token ) {
                $operators[] = $token;
                continue;
            }

            if ( ')' === $token ) {
                while ( ! empty( $operators ) && '(' !== end( $operators ) ) {
                    $output[] = array_pop( $operators );
                }

                if ( ! empty( $operators ) ) {
                    array_pop( $operators );
                }
                continue;
            }

            if ( isset( $precedence[ $token ] ) ) {
                while (
                    ! empty( $operators )
                    && '(' !== end( $operators )
                    && isset( $precedence[ end( $operators ) ] )
                    && $precedence[ end( $operators ) ] >= $precedence[ $token ]
                ) {
                    $output[] = array_pop( $operators );
                }
                $operators[] = $token;
            }
        }

        while ( ! empty( $operators ) ) {
            $operator = array_pop( $operators );
            if ( '(' === $operator || ')' === $operator ) {
                continue;
            }
            $output[] = $operator;
        }

        $stack = array();
        foreach ( $output as $item ) {
            if ( is_float( $item ) || is_int( $item ) ) {
                $stack[] = (float) $item;
                continue;
            }

            if ( count( $stack ) < 2 ) {
                return 0.0;
            }

            $b = array_pop( $stack );
            $a = array_pop( $stack );

            switch ( $item ) {
                case '+':
                    $stack[] = $a + $b;
                    break;
                case '-':
                    $stack[] = $a - $b;
                    break;
                case '*':
                    $stack[] = $a * $b;
                    break;
                case '/':
                    $stack[] = 0.0 === $b ? 0.0 : ( $a / $b );
                    break;
            }
        }

        return empty( $stack ) ? 0.0 : (float) end( $stack );
    }

    /**
     * Tokenize an arithmetic expression into numbers, operators, and parens.
     *
     * Supports unary +/- by folding the sign into the next numeric literal.
     *
     * @param string $expr Expression.
     * @return array Mixed list of floats and single-character operator strings.
     */
    private function tokenize_expression( string $expr ): array {
        $tokens  = array();
        $length  = strlen( $expr );
        $i       = 0;
        $prev    = null;

        while ( $i < $length ) {
            $char = $expr[ $i ];

            if ( ' ' === $char || "\t" === $char ) {
                $i++;
                continue;
            }

            if ( ctype_digit( $char ) || '.' === $char ) {
                $number = '';
                while ( $i < $length && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
                    $number .= $expr[ $i ];
                    $i++;
                }
                $tokens[] = (float) $number;
                $prev     = 'number';
                continue;
            }

            if ( '(' === $char ) {
                $tokens[] = '(';
                $prev     = '(';
                $i++;
                continue;
            }

            if ( ')' === $char ) {
                $tokens[] = ')';
                $prev     = ')';
                $i++;
                continue;
            }

            if ( in_array( $char, array( '+', '-', '*', '/' ), true ) ) {
                if ( ( '+' === $char || '-' === $char ) && ( null === $prev || 'operator' === $prev || '(' === $prev ) ) {
                    $sign = '-' === $char ? -1.0 : 1.0;
                    $i++;

                    while ( $i < $length && ( ' ' === $expr[ $i ] || "\t" === $expr[ $i ] ) ) {
                        $i++;
                    }
                    $number = '';
                    while ( $i < $length && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
                        $number .= $expr[ $i ];
                        $i++;
                    }
                    if ( '' === $number ) {
                        $tokens[] = 0.0;
                    } else {
                        $tokens[] = $sign * (float) $number;
                    }
                    $prev = 'number';
                    continue;
                }

                $tokens[] = $char;
                $prev     = 'operator';
                $i++;
                continue;
            }

            return array();
        }

        return $tokens;
    }

    /**
     * Format a float for substitution into an arithmetic expression.
     *
     * @param float $number Value.
     * @return string
     */
    private function format_number( float $number ): string {
        return rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' ) ?: '0';
    }

    /**
     * Emit a flat-rate evaluation trace.
     *
     * Routed through the central `tejcart_log()` pipeline at `debug`
     * level — operators who want to see these entries set the global
     * `tejcart_log_level` to `debug` under Settings → Advanced. There is
     * no separate per-shipping debug toggle; one log gate controls the
     * whole plugin.
     *
     * @param string $formula Raw formula from settings.
     * @param array  $context Parser context.
     * @param float  $cost    Final numeric cost.
     */
    private function maybe_log_debug( string $formula, array $context, float $cost ): void {
        if ( ! function_exists( 'tejcart_log_level_passes' ) || ! tejcart_log_level_passes( 'debug' ) ) {
            return;
        }

        tejcart_log(
            sprintf(
                'Flat rate evaluated: formula="%s" qty=%d cost=%s => %s',
                $formula,
                (int) $context['qty'],
                number_format( (float) $context['cost'], 2, '.', '' ),
                number_format( $cost, 2, '.', '' )
            ),
            'debug',
            array( 'source' => 'shipping' )
        );
    }
}
