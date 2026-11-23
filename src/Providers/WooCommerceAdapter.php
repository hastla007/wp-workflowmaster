<?php
/**
 * Adapter for WooCommerce orders (source.product "woocommerce", F-20, SPEC-suite-events 4.7). Lives in the free core
 * and needs no license; registers only while WooCommerce 11.2.2 or newer is active
 * (MINVER-1; with an older WooCommerce it stays unregistered and the adapters page names the reason).
 *
 * Orders are read only through wc_get_order() and the getters of WC_Order, never from wp_posts or wc_orders, so HPOS
 * and the classic storage behave the same (R-4.8). Addresses, e-mail, phone, names, IP, the customer note and coupon
 * codes are never passed on (R-4.9); money is an integer in minor units, the currency an upper-case ISO code.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Providers;

use WorkflowCaptain\Consumer\TransientFailure;

defined( 'ABSPATH' ) || exit;

final class WooCommerceAdapter extends CoreAdapter {

	const ORDER_CREATED        = 'woocommerce.order.created';
	const ORDER_PAID           = 'woocommerce.order.paid';
	const ORDER_STATUS_CHANGED = 'woocommerce.order.status_changed';
	const ORDER_REFUNDED       = 'woocommerce.order.refunded';
	const ORDER_CANCELLED      = 'woocommerce.order.cancelled';

	public function product(): string {
		return 'woocommerce';
	}

	public function types(): array {
		return array( self::ORDER_CREATED, self::ORDER_PAID, self::ORDER_STATUS_CHANGED, self::ORDER_REFUNDED, self::ORDER_CANCELLED );
	}

	protected function detect_version(): string {
		if ( ! class_exists( 'WooCommerce', false ) || ! defined( 'WC_VERSION' ) ) {
			return '';
		}
		return (string) constant( 'WC_VERSION' );
	}

	protected function min_version(): string {
		return \WorkflowCaptain\Requirements::WOOCOMMERCE;
	}

	public function available(): bool {
		return parent::available() && function_exists( 'wc_get_order' );
	}

	public function hook(): void {
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), self::PRIORITY, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), self::PRIORITY, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), self::PRIORITY, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), self::PRIORITY, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_cancelled' ), self::PRIORITY, 3 );
	}

	/**
	 * Hook "woocommerce_new_order". The order object passed along is preferred: when the classic storage fires the
	 * hook, its line items may not be saved yet, so a fresh wc_get_order() could count none.
	 *
	 * @param int|string     $order_id
	 * @param \WC_Order|null $order
	 */
	public function on_new_order( $order_id, $order = null ): void {
		try {
			$order = self::order( $order_id, $order );
			if ( null === $order ) {
				return;
			}
			$this->emit(
				array(
					'type'          => self::ORDER_CREATED,
					'subject'       => self::subject( $order ),
					'data'          => array(
						'status'           => self::status( $order->get_status() ),
						'currency'         => self::currency( $order ),
						'total_minor'      => self::minor( $order->get_total(), $order ),
						'item_count'       => (int) $order->get_item_count(),
						'created_via'      => self::key( $order->get_created_via() ),
						'customer_user_id' => self::customer( $order ),
					),
					'actor_user_id' => self::current_actor(),
					'discriminator' => 'once',
				)
			);
		} catch ( \Throwable $e ) {
			return; // Nothing may reach WooCommerce (R-7.6).
		}
	}

	/**
	 * Hook "woocommerce_payment_complete". No actor: the hook often runs in a gateway webhook (4.7).
	 *
	 * @param int|string $order_id
	 */
	public function on_payment_complete( $order_id ): void {
		try {
			$order = self::order( $order_id );
			if ( null === $order ) {
				return;
			}
			$this->emit(
				array(
					'type'          => self::ORDER_PAID,
					'subject'       => self::subject( $order ),
					'data'          => array(
						'currency'         => self::currency( $order ),
						'total_minor'      => self::minor( $order->get_total(), $order ),
						'item_count'       => (int) $order->get_item_count(),
						'payment_method'   => self::key( $order->get_payment_method() ),
						'customer_user_id' => self::customer( $order ),
					),
					'discriminator' => 'once',
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Hook "woocommerce_order_status_changed". Discriminator: the new status (4.7).
	 *
	 * @param int|string     $order_id
	 * @param string         $from
	 * @param string         $to
	 * @param \WC_Order|null $order
	 */
	public function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		try {
			$order = self::order( $order_id, $order );
			$to    = self::status( $to );
			if ( null === $order || '' === $to ) {
				return;
			}
			$this->emit(
				array(
					'type'          => self::ORDER_STATUS_CHANGED,
					'subject'       => self::subject( $order ),
					'data'          => array(
						'from_status' => self::status( $from ),
						'to_status'   => $to,
						'currency'    => self::currency( $order ),
						'total_minor' => self::minor( $order->get_total(), $order ),
					),
					'actor_user_id' => self::current_actor(),
					'discriminator' => $to,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Hook "woocommerce_order_refunded". Discriminator: the refund id (4.7).
	 *
	 * @param int|string $order_id
	 * @param int|string $refund_id
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		try {
			$order     = self::order( $order_id );
			$refund_id = (int) $refund_id;
			$refund    = $refund_id > 0 ? wc_get_order( $refund_id ) : null;
			if ( null === $order || ! is_object( $refund ) || (int) $refund->get_parent_id() !== (int) $order->get_id() ) {
				return;
			}
			$total    = self::minor( $order->get_total(), $order );
			$refunded = self::minor( $order->get_total_refunded(), $order );
			$this->emit(
				array(
					'type'          => self::ORDER_REFUNDED,
					'subject'       => self::subject( $order ),
					'data'          => array(
						'refund_id'            => $refund_id,
						'amount_minor'         => abs( self::minor( $refund->get_amount(), $order ) ),
						'currency'             => self::currency( $order ),
						'refunded_total_minor' => $refunded,
						'full_refund'          => $total > 0 && $refunded >= $total,
					),
					'actor_user_id' => self::current_actor(),
					'discriminator' => (string) $refund_id,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Hook "woocommerce_order_status_cancelled". WooCommerce passes the transition as third argument; its "from" is
	 * the previous status.
	 *
	 * @param int|string     $order_id
	 * @param \WC_Order|null $order
	 * @param array|null     $transition
	 */
	public function on_cancelled( $order_id, $order = null, $transition = null ): void {
		try {
			$order = self::order( $order_id, $order );
			if ( null === $order ) {
				return;
			}
			$this->emit(
				array(
					'type'          => self::ORDER_CANCELLED,
					'subject'       => self::subject( $order ),
					'data'          => array(
						'from_status' => self::status( is_array( $transition ) ? ( $transition['from'] ?? '' ) : '' ),
						'currency'    => self::currency( $order ),
						'total_minor' => self::minor( $order->get_total(), $order ),
					),
					'actor_user_id' => self::current_actor(),
					'discriminator' => 'once',
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Re-reads through wc_get_order() (R-7.4): the order exists and still matches the event.
	 *
	 * @throws TransientFailure When WooCommerce cannot be read right now.
	 */
	public function reread( array $envelope ): bool {
		if ( ! function_exists( 'wc_get_order' ) ) {
			throw new TransientFailure( 'provider_unreachable' );
		}
		$order = self::order( $envelope['subject']['id'] ?? 0 );
		if ( null === $order ) {
			return false;
		}
		$data = (array) ( $envelope['data'] ?? array() );
		switch ( $envelope['type'] ?? '' ) {
			case self::ORDER_CREATED:
				return true;
			case self::ORDER_PAID:
				return null !== $order->get_date_paid() || (bool) $order->is_paid();
			case self::ORDER_STATUS_CHANGED:
				return self::status( $order->get_status() ) === (string) ( $data['to_status'] ?? '' );
			case self::ORDER_CANCELLED:
				return 'cancelled' === self::status( $order->get_status() );
			case self::ORDER_REFUNDED:
				$refund_id = (int) ( $data['refund_id'] ?? 0 );
				$refund    = $refund_id > 0 ? wc_get_order( $refund_id ) : null;
				return is_object( $refund ) && (int) $refund->get_parent_id() === (int) $order->get_id();
		}
		return false;
	}

	/**
	 * The order through the CRUD layer (R-4.8). A passed WC_Order with the same id is used as it is.
	 *
	 * @param mixed $order_id
	 * @param mixed $order
	 * @return \WC_Order|null
	 */
	private static function order( $order_id, $order = null ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 ) {
			return null;
		}
		if ( ! ( is_object( $order ) && class_exists( 'WC_Order', false ) && $order instanceof \WC_Order && (int) $order->get_id() === $order_id ) ) {
			$order = wc_get_order( $order_id );
		}
		// A refund is an order object as well, but never the subject of these events.
		if ( ! is_object( $order ) || ! class_exists( 'WC_Order', false ) || ! $order instanceof \WC_Order ) {
			return null;
		}
		return $order;
	}

	/** @param \WC_Order $order */
	private static function subject( $order ): array {
		return array( 'type' => 'order', 'id' => (string) (int) $order->get_id(), 'id_kind' => 'record' );
	}

	/** Status without the "wc-" prefix. */
	private static function status( $status ): string {
		$status = self::key( $status );
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Upper-case ISO 4217 code; anything else is refused so no free text can slip into the event.
	 *
	 * @param \WC_Order $order
	 * @throws \UnexpectedValueException On a currency that is not three letters.
	 */
	private static function currency( $order ): string {
		$currency = strtoupper( trim( (string) $order->get_currency() ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new \UnexpectedValueException( 'currency' );
		}
		return $currency;
	}

	/**
	 * An amount in the minor unit of the order's currency (ISO 4217, \WorkflowCaptain\Money), not with the shop's price
	 * decimals: those are a display setting (WooCommerce keeps 2 for JPY unless changed) and belong to the shop's
	 * currency, not to an order in another one.
	 *
	 * @param string|int|float $amount
	 * @param \WC_Order        $order
	 */
	private static function minor( $amount, $order ): int {
		return \WorkflowCaptain\Money::to_minor( $amount, self::currency( $order ) );
	}

	/**
	 * The customer's user id, or null for a guest order.
	 *
	 * @param \WC_Order $order
	 */
	private static function customer( $order ): ?int {
		$id = (int) $order->get_customer_id();
		return $id > 0 ? $id : null;
	}
}
