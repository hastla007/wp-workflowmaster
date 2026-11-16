<?php
/**
 * Adapter, dispatcher and clock doubles for the tests.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Clock;
use WorkflowCaptain\Consumer\Dispatcher;
use WorkflowCaptain\Consumer\DispatchResult;
use WorkflowCaptain\Consumer\TransientFailure;

final class TestAdapter implements Adapter {

	public array $types   = array( 'pass.ticket.checked_in', 'pass.order.paid', 'voucher.voucher.redeemed', 'affiliate.affiliate.erased', 'affiliate.commission.approved' );
	public array $reread  = array();
	public ?bool $reread_result = true;
	public bool $throw_on_product = false;

	public function __construct( public string $product = 'pass-captain', public ?string $contract = '1.1.0' ) {}

	public function product(): string {
		if ( $this->throw_on_product ) {
			throw new RuntimeException( 'secret-marker@example.com' );
		}
		return $this->product;
	}

	public function core_version(): string {
		return '1.5.0';
	}

	public function contract_version(): ?string {
		return $this->contract;
	}

	public function types(): array {
		return $this->types;
	}

	public function needs_reread( string $type ): bool {
		return in_array( $type, $this->reread, true );
	}

	public function reread( array $envelope ): bool {
		if ( null === $this->reread_result ) {
			throw new TransientFailure( 'provider_unreachable' );
		}
		return $this->reread_result;
	}
}

final class RecordingDispatcher implements Dispatcher {

	/** @var array<int,array{0:array,1:?int}> */
	public array $calls = array();
	public int $fail_times = 0;
	public bool $waiting = false;
	/** @var int[] Workflows whose trigger matches every event. */
	public array $workflows = array( 12, 13 );
	/** @var array<string,int> Started runs per idempotency key + workflow (C-10). */
	public array $runs = array();

	public function dispatch( array $envelope, ?int $exclude_workflow_id ): DispatchResult {
		$this->calls[] = array( $envelope, $exclude_workflow_id );
		$started       = array();
		foreach ( $this->workflows as $wf ) {
			if ( $wf === $exclude_workflow_id ) {
				continue;
			}
			$key = $envelope['idempotency_key'] . '|' . $wf;
			if ( ! isset( $this->runs[ $key ] ) ) {
				$this->runs[ $key ] = 1;
				$started[]          = $wf;
			}
		}
		if ( $this->fail_times > 0 ) {
			--$this->fail_times;
			throw new TransientFailure( 'db_timeout' );
		}
		return new DispatchResult( $started, $this->waiting );
	}
}

final class FixedClock extends Clock {

	public function __construct( public int $time = 1763287200 ) {}

	public function now(): int {
		return $this->time;
	}

	public function advance( int $seconds ): void {
		$this->time += $seconds;
	}
}
