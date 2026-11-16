<?php
/**
 * Plugin Name: E2E fake providers (test site only, never ship)
 * With the option "e2e_fake_providers" = 1: registers a Captain provider adapter with an invalid contract version and
 * a connector that needs a newer core contract, so the refusals can be checked on the Providers and Connectors pages.
 */
if ( ! get_option( 'e2e_fake_providers' ) ) {
	return;
}

// Declared once the core is loaded (mu-plugins load before plugins).
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'E2E_Fake_Adapter' ) || ! interface_exists( '\\WorkflowCaptain\\Adapter\\Adapter' ) ) {
			return;
		}
		final class E2E_Fake_Adapter implements \WorkflowCaptain\Adapter\Adapter {
			public function __construct( private string $product, private ?string $contract ) {}
			public function product(): string { return $this->product; }
			public function core_version(): string { return '1.0.0'; }
			public function contract_version(): ?string { return $this->contract; }
			public function types(): array { return array( 'xe2enewer.thing.done' ); }
			public function needs_reread( string $type ): bool { return false; }
			public function reread( array $envelope ): bool { return true; }
		}
	},
	1
);

add_action(
	'workflow_captain_register_adapters_v1',
	static function ( $registry ) {
		$registry->add( new E2E_Fake_Adapter( 'pass-captain', 'v2' ) );
	}
);
add_action(
	'workflow_captain_register_connectors_v1',
	static function ( $registry ) {
		$registry->add(
			array(
				'key'              => 'e2e-newer',
				'name'             => 'E2E Newer',
				'version'          => '1.0.0',
				'contract_version' => '1.0.0',
				'requires'         => array( 'workflow_captain' => '9.0.0' ),
				'events'           => array( 'xe2enewer.thing.done' => array( 'fields' => array(), 'after_commit' => true, 'actor' => 'none' ) ),
				'actions'          => array(),
			),
			new E2E_Fake_Adapter( 'e2e-newer', '1.0.0' )
		);
	}
);
