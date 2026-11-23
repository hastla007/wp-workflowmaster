<?php
/**
 * Amounts in the smallest unit of their currency (R-5.1a: `*_minor` with `currency`). The number of decimals comes
 * from ISO 4217 for the currency of the amount, not from the price decimals of a shop: a shop in JPY that keeps
 * WooCommerce's default of two price decimals would otherwise report 1500 yen as 150000, and an order in another
 * currency than the shop's (multi-currency plugins) would get the shop's decimals.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Money {

	/** ISO 4217 currencies whose minor unit is not 1/100 (list of codes and minor units, as of 2026). */
	const EXPONENTS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
		'CLF' => 4,
		'UYW' => 4,
	);

	/** Decimals of the minor unit of an upper-case ISO 4217 code; 2 for every other code. */
	public static function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
	}

	/**
	 * A decimal amount (as WooCommerce returns it, e.g. "19.99") in the minor unit of the currency, rounded half up.
	 *
	 * @param string|int|float $amount
	 */
	public static function to_minor( $amount, string $currency ): int {
		return (int) round( (float) $amount * ( 10 ** self::exponent( $currency ) ) );
	}
}
