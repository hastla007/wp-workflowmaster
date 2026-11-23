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

final class FakeDirectory implements \WorkflowCaptain\Engine\Directory, \WorkflowCaptain\Engine\SiteMembers, \WorkflowCaptain\Engine\Marketing, \WorkflowCaptain\Engine\MessageFooter {

	/** Footer of messages to customers (SET-WC-10). */
	public string $footer = '';

	public function footer(): string {
		return $this->footer;
	}


	/** @var int[] Users with consent to promotional messages; users who unsubscribed. */
	public array $consent = array();
	public array $unsubscribed = array();
	public array $consent_context = array();

	public function has_consent( int $user_id, array $context ): bool {
		$this->consent_context = $context;
		return in_array( $user_id, $this->consent, true );
	}

	public function unsubscribed( int $user_id ): bool {
		return in_array( $user_id, $this->unsubscribed, true );
	}

	public function unsubscribe_url( int $user_id ): string {
		return 'https://shop.example/wp-admin/admin-post.php?action=workflow_captain_unsubscribe&u=' . $user_id . '&t=sig';
	}

	public function mail_promotional( string $to, string $subject, string $message, string $unsubscribe_url ): bool {
		$ok = $this->mail( $to, $subject, $message );
		if ( $ok ) {
			$this->sent[ count( $this->sent ) - 1 ][] = 'List-Unsubscribe: <' . $unsubscribe_url . '>';
		}
		return $ok;
	}

	/** @var int[] Users who are members of this site (multisite). */
	public array $members = array( 1, 17 );

	public function is_member( int $user_id ): bool {
		return in_array( $user_id, $this->members, true );
	}

	/** @var array<int,string[]> Capabilities per user. */
	public array $caps = array( 1 => array( 'activate_workflows', 'manage_options' ) );
	public array $emails = array( 17 => 'person@example.com', 1 => 'admin@example.com' );
	public array $sent = array();
	public int $fail_mails = 0;
	public bool $crash_after_send = false;

	public function user_can( int $user_id, string $capability ): bool {
		return in_array( $capability, $this->caps[ $user_id ] ?? array(), true );
	}

	public function email_of( int $user_id ): ?string {
		return $this->emails[ $user_id ] ?? null;
	}

	public function emails_with_capability( string $capability ): array {
		$out = array();
		foreach ( $this->caps as $id => $caps ) {
			if ( in_array( $capability, $caps, true ) && isset( $this->emails[ $id ] ) ) {
				$out[] = $this->emails[ $id ];
			}
		}
		return $out;
	}

	/** @var array<string,int[]> role => user IDs */
	public array $roles = array();

	public function emails_with_role( string $role ): array {
		return array_values( array_filter( array_map( fn( $id ) => $this->emails[ $id ] ?? null, $this->roles[ $role ] ?? array() ) ) );
	}

	public function site_admin_email(): string {
		return 'site@example.com';
	}

	public function mail( string $to, string $subject, string $message ): bool {
		if ( $this->fail_mails > 0 ) {
			--$this->fail_mails;
			return false;
		}
		$this->sent[] = array( $to, $subject, $message );
		if ( $this->crash_after_send ) {
			throw new \LogicException( 'worker died after sending' );
		}
		return true;
	}
}

/** An action that writes in a provider and needs a capability (stands in for voucher.void in tests). */
final class CapAction implements \WorkflowCaptain\Engine\Action {
	public int $runs = 0;
	public function key(): string { return 'test.write'; }
	public function label(): string { return 'test'; }
	public function capability(): ?string { return 'vcap_manage_vouchers'; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function execute( \WorkflowCaptain\Engine\ActionContext $context ): array { ++$this->runs; return array( 'ok' => 1, 'mail' => 'leak@example.com' ); }
}
