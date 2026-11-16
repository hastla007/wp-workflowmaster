<?php
/**
 * Plugin Name: E2E add-on action (test site only, never ship)
 * With the option "e2e_addon_action" = 1: registers an action of an add-on with its own input fields
 * (builder_fields()), so the builder form of add-on actions and the pause of runs whose action has gone
 * ("action_unavailable") can be checked without a licensed Pro add-on (third run).
 */
if ( ! get_option( 'e2e_addon_action' ) ) {
	return;
}

add_action(
	'workflow_captain_actions_v1',
	static function ( $registry ) {
		if ( ! class_exists( 'E2E_Addon_Action' ) ) {
			final class E2E_Addon_Action implements \WorkflowCaptain\Engine\Action {
				public function key(): string { return 'woocommerce.e2e.mark'; }
				public function label(): string { return 'E2E-Add-on: Bestellung kennzeichnen'; }
				public function capability(): ?string { return null; }
				public function idempotent(): bool { return true; }
				public function validate_config( array $config ): array {
					$out = array();
					if ( ! in_array( $config['color'] ?? '', array( 'red', 'green' ), true ) ) {
						$out[] = 'color:invalid';
					}
					if ( ! is_numeric( $config['priority'] ?? null ) ) {
						$out[] = 'priority:invalid';
					}
					return $out;
				}
				public function builder_fields(): array {
					return array(
						array( 'key' => 'color', 'label' => 'Farbe', 'type' => 'select', 'options' => array( array( 'value' => 'red', 'label' => 'Rot' ), array( 'value' => 'green', 'label' => 'Grün' ) ) ),
						array( 'key' => 'priority', 'label' => 'Priorität', 'type' => 'number' ),
						array( 'key' => 'comment', 'label' => 'Kommentar', 'type' => 'textarea' ),
					);
				}
				public function execute( \WorkflowCaptain\Engine\ActionContext $context ): array {
					return array( 'marked' => (string) $context->config['color'] );
				}
			}
		}
		$registry->add( new E2E_Addon_Action() );
	}
);
