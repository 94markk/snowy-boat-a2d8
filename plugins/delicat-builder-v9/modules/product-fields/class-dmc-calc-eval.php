<?php
/**
 * Safe arithmetic expression evaluator.
 *
 * Parses and evaluates a math expression WITHOUT ever using eval(). Only
 * numbers, a fixed set of operators, parentheses and a whitelist of functions
 * are allowed. Variables must be pre-resolved to numbers by the caller. This is
 * what makes user-authored pricing formulas safe to run on the server.
 *
 * @package DeliMultiCurrency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Delicat_Builder_V9_Product_Fields_Eval {

	const MAX_LEN = 2000;

	/** @var array Whitelisted functions => argument count. */
	protected static $funcs = array(
		'abs'   => 1,
		'ceil'  => 1,
		'floor' => 1,
		'round' => 1,
		'sqrt'  => 1,
		'min'   => 2,
		'max'   => 2,
		'pow'   => 2,
	);

	/**
	 * Name of the unresolved variable that aborted the last tokenize(), so the
	 * caller's log line can name it instead of saying only "tokenize".
	 *
	 * @var string
	 */
	protected static $unknown_var = '';

	/** @var array Operators => precedence/associativity/arity. */
	protected static $ops = array(
		'u-' => array( 'prec' => 5, 'assoc' => 'right', 'args' => 1 ),
		'^'  => array( 'prec' => 4, 'assoc' => 'right', 'args' => 2 ),
		'*'  => array( 'prec' => 3, 'assoc' => 'left', 'args' => 2 ),
		'/'  => array( 'prec' => 3, 'assoc' => 'left', 'args' => 2 ),
		'%'  => array( 'prec' => 3, 'assoc' => 'left', 'args' => 2 ),
		'+'  => array( 'prec' => 2, 'assoc' => 'left', 'args' => 2 ),
		'-'  => array( 'prec' => 2, 'assoc' => 'left', 'args' => 2 ),
	);

	/**
	 * Evaluate an expression with a map of variables.
	 *
	 * @param string $expr Expression. May contain {var} tokens and bare identifiers.
	 * @param array  $vars Map of variable name => numeric value.
	 * @return float 0.0 on any parse/eval problem (fails safe).
	 */
	public static function evaluate( $expr, array $vars = array() ) {
		$out = self::evaluate_checked( $expr, $vars );
		return $out['ok'] ? $out['value'] : 0.0;
	}

	/**
	 * Same as evaluate(), but reports WHY it returned what it returned.
	 *
	 * evaluate() cannot distinguish "this formula legitimately equals zero" from
	 * "this formula is malformed", so a single stray character in an admin formula
	 * used to price the product at 0.00. Callers that touch money must use this.
	 *
	 * @param string $expr Expression.
	 * @param array  $vars Variables.
	 * @return array{ok:bool,value:float,error:string}
	 */
	public static function evaluate_checked( $expr, array $vars = array() ) {
		$fail = function ( $why ) {
			return array( 'ok' => false, 'value' => 0.0, 'error' => $why );
		};
		if ( ! is_string( $expr ) || '' === trim( $expr ) ) {
			return $fail( 'empty' );
		}
		if ( strlen( $expr ) > self::MAX_LEN ) {
			return $fail( 'too_long' );
		}
		self::$unknown_var = '';
		$tokens            = self::tokenize( $expr, $vars );
		if ( false === $tokens || array() === $tokens ) {
			return $fail( '' !== self::$unknown_var ? 'unknown_var:' . self::$unknown_var : 'tokenize' );
		}
		$rpn = self::to_rpn( $tokens );
		if ( false === $rpn || array() === $rpn ) {
			return $fail( 'parse' );
		}
		$result = self::eval_rpn( $rpn );
		/* Strict, and before is_finite(): is_finite( false ) coerces false to 0.0
		 * and returns true, which is how every arithmetic-stage failure used to be
		 * reported as a successful 0.00. A formula that legitimately equals zero
		 * returns float 0.0 and passes. */
		if ( false === $result ) {
			return $fail( 'eval' );
		}
		if ( ! is_finite( (float) $result ) ) {
			return $fail( 'not_finite' );
		}
		return array( 'ok' => true, 'value' => (float) $result, 'error' => '' );
	}

	/**
	 * Convert an expression string into a token list, resolving variables.
	 *
	 * @param string $expr Expression.
	 * @param array  $vars Variables.
	 * @return array|false
	 */
	protected static function tokenize( $expr, $vars ) {
		$tokens = array();
		$i      = 0;
		$n      = strlen( $expr );
		$prev   = null; // type of previous meaningful token: num|var|rp|op|func|lp|comma.

		while ( $i < $n ) {
			$ch = $expr[ $i ];

			if ( ctype_space( $ch ) ) {
				$i++;
				continue;
			}

			// Number.
			if ( ctype_digit( $ch ) || ( '.' === $ch && $i + 1 < $n && ctype_digit( $expr[ $i + 1 ] ) ) ) {
				if ( preg_match( '/\G\d*\.?\d+([eE][+-]?\d+)?/', $expr, $m, 0, $i ) ) {
					$tokens[] = array( 'num', (float) $m[0] );
					$i       += strlen( $m[0] );
					$prev     = 'num';
					continue;
				}
				return false;
			}

			// {variable}.
			if ( '{' === $ch ) {
				if ( preg_match( '/\G\{([A-Za-z0-9_]+)\}/', $expr, $m, 0, $i ) ) {
					$name = $m[1];
					/* PRO38: an unknown variable used to resolve to 0.0 and the
					 * expression still reported ok, which defeated the whole point
					 * of evaluate_checked(). A formula naming a field that was
					 * renamed, deleted or simply mistyped — `{base} * {qty_mult}`
					 * against a field actually called `qty_multiplier` — evaluated
					 * to `base * 0` and priced the product at 0.00, silently, with
					 * nothing logged. That is exactly the outcome compute_total()
					 * says must never happen.
					 *
					 * Rejecting is safe: the caller seeds $vars with base, rate and
					 * EVERY configured field id (inactive fields are seeded 0.0), so
					 * a name missing from $vars cannot be a legitimate reference.
					 * Unknown FUNCTION calls were already rejected a few lines down
					 * for the same reason; this closes the variable half. */
					if ( ! isset( $vars[ $name ] ) ) {
						self::$unknown_var = $name;
						return false;
					}
					$val      = (float) $vars[ $name ];
					$tokens[] = array( 'num', $val );
					$i       += strlen( $m[0] );
					$prev     = 'num';
					continue;
				}
				return false;
			}

			// Identifier: a function name OR a bare variable (e.g. "base").
			if ( ctype_alpha( $ch ) || '_' === $ch ) {
				if ( preg_match( '/\G[A-Za-z_][A-Za-z0-9_]*/', $expr, $m, 0, $i ) ) {
					$word = strtolower( $m[0] );
					$len  = strlen( $m[0] );
					// Function only if immediately followed by '('.
					$j = $i + $len;
					while ( $j < $n && ctype_space( $expr[ $j ] ) ) {
						$j++;
					}
					if ( $j < $n && '(' === $expr[ $j ] ) {
						if ( ! isset( self::$funcs[ $word ] ) ) {
							// An identifier followed by parentheses is always a function call.
							// Reject unknown calls instead of silently treating the identifier as 0.
							return false;
						}
						$tokens[] = array( 'func', $word );
						$i       += $len;
						$prev     = 'func';
						continue;
					}
					// Otherwise treat as a variable. Unknown names are rejected
					// rather than silently zeroed -- see the {var} branch above.
					if ( ! isset( $vars[ $m[0] ] ) ) {
						self::$unknown_var = $m[0];
						return false;
					}
					$val      = (float) $vars[ $m[0] ];
					$tokens[] = array( 'num', $val );
					$i       += $len;
					$prev     = 'num';
					continue;
				}
				return false;
			}

			// Parentheses & comma.
			if ( '(' === $ch ) {
				$tokens[] = array( 'lp' );
				$i++;
				$prev = 'lp';
				continue;
			}
			if ( ')' === $ch ) {
				$tokens[] = array( 'rp' );
				$i++;
				$prev = 'rp';
				continue;
			}
			if ( ',' === $ch ) {
				$tokens[] = array( 'comma' );
				$i++;
				$prev = 'comma';
				continue;
			}

			// Operators.
			if ( false !== strpos( '+-*/%^', $ch ) ) {
				if ( '-' === $ch && ( null === $prev || 'op' === $prev || 'lp' === $prev || 'comma' === $prev ) ) {
					$tokens[] = array( 'op', 'u-' ); // unary minus.
				} elseif ( '+' === $ch && ( null === $prev || 'op' === $prev || 'lp' === $prev || 'comma' === $prev ) ) {
					// unary plus: no-op, skip.
				} else {
					$tokens[] = array( 'op', $ch );
				}
				$i++;
				$prev = 'op';
				continue;
			}

			// Anything else is rejected.
			return false;
		}

		return $tokens;
	}

	/**
	 * Shunting-yard: token list -> Reverse Polish Notation.
	 *
	 * @param array $tokens Tokens.
	 * @return array|false
	 */
	protected static function to_rpn( $tokens ) {
		$output = array();
		$stack  = array();

		foreach ( $tokens as $t ) {
			switch ( $t[0] ) {
				case 'num':
					$output[] = $t;
					break;
				case 'func':
					$stack[] = $t;
					break;
				case 'comma':
					while ( $stack && 'lp' !== end( $stack )[0] ) {
						$output[] = array_pop( $stack );
					}
					if ( ! $stack ) {
						return false;
					}
					break;
				case 'op':
					$o1 = self::$ops[ $t[1] ];
					while ( $stack ) {
						$top = end( $stack );
						if ( 'op' === $top[0] ) {
							$o2 = self::$ops[ $top[1] ];
							if ( ( 'left' === $o1['assoc'] && $o1['prec'] <= $o2['prec'] ) ||
								( 'right' === $o1['assoc'] && $o1['prec'] < $o2['prec'] ) ) {
								$output[] = array_pop( $stack );
								continue;
							}
						}
						break;
					}
					$stack[] = $t;
					break;
				case 'lp':
					$stack[] = $t;
					break;
				case 'rp':
					while ( $stack && 'lp' !== end( $stack )[0] ) {
						$output[] = array_pop( $stack );
					}
					if ( ! $stack ) {
						return false; // mismatched parens.
					}
					array_pop( $stack ); // remove lp.
					if ( $stack && 'func' === end( $stack )[0] ) {
						$output[] = array_pop( $stack );
					}
					break;
			}
		}

		while ( $stack ) {
			$top = array_pop( $stack );
			if ( 'lp' === $top[0] || 'rp' === $top[0] ) {
				return false;
			}
			$output[] = $top;
		}

		return $output;
	}

	/**
	 * Evaluate an RPN token list.
	 *
	 * @param array $rpn RPN tokens.
	 * @return float
	 */
	/*
	 * PRO38: this returned 0.0 for every failure -- stack underflow, division by
	 * zero, modulo by zero, sqrt of a negative, a leftover stack -- which made an
	 * error indistinguishable from a formula that legitimately equals zero.
	 * evaluate_checked() then reported ok => true with value 0.0, so
	 * compute_total() took the success branch and the product sold for 0.00 with
	 * nothing logged. `{base} / {qty}` with the qty field inactive (the caller
	 * seeds inactive fields 0.0) reached that on ordinary configuration.
	 *
	 * Failure is now false, which 0.0 can never collide with.
	 *
	 * @param array $rpn Token list in RPN order.
	 * @return float|false Result, or false when the expression cannot be evaluated.
	 */
	protected static function eval_rpn( $rpn ) {
		$stack = array();

		foreach ( $rpn as $t ) {
			if ( 'num' === $t[0] ) {
				$stack[] = $t[1];
				continue;
			}
			if ( 'op' === $t[0] ) {
				$op = $t[1];
				if ( 'u-' === $op ) {
					if ( ! $stack ) {
						return false;
					}
					$a       = array_pop( $stack );
					$stack[] = -$a;
					continue;
				}
				if ( count( $stack ) < 2 ) {
					return false;
				}
				$b = array_pop( $stack );
				$a = array_pop( $stack );
				switch ( $op ) {
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
						if ( 0.0 === (float) $b ) {
							return false;
						}
						$stack[] = $a / $b;
						break;
					case '%':
						if ( 0.0 === (float) $b ) {
							return false;
						}
						$stack[] = fmod( $a, $b );
						break;
					case '^':
						$stack[] = pow( $a, $b );
						break;
				}
				continue;
			}
			if ( 'func' === $t[0] ) {
				$name  = $t[1];
				$arity = self::$funcs[ $name ];
				if ( count( $stack ) < $arity ) {
					return false;
				}
				$args = array();
				for ( $k = 0; $k < $arity; $k++ ) {
					array_unshift( $args, array_pop( $stack ) );
				}
				switch ( $name ) {
					case 'abs':
						$stack[] = abs( $args[0] );
						break;
					case 'ceil':
						$stack[] = ceil( $args[0] );
						break;
					case 'floor':
						$stack[] = floor( $args[0] );
						break;
					case 'round':
						$stack[] = round( $args[0] );
						break;
					case 'sqrt':
						if ( $args[0] < 0 ) {
							return false;
						}
						$stack[] = sqrt( $args[0] );
						break;
					case 'min':
						$stack[] = min( $args[0], $args[1] );
						break;
					case 'max':
						$stack[] = max( $args[0], $args[1] );
						break;
					case 'pow':
						$stack[] = pow( $args[0], $args[1] );
						break;
				}
				continue;
			}
		}

		return 1 === count( $stack ) ? (float) $stack[0] : false;
	}
}
