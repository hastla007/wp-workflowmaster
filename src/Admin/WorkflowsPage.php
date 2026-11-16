<?php
/**
 * Admin pages "Workflows" and "Läufe".
 *
 * Until the visual builder exists (WF-BUILDER-01), a workflow is edited as JSON (format: src/Engine/Graph.php). The
 * server validates on every save and before publishing; publishing creates an immutable version.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\Graph;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class WorkflowsPage {

	const SLUG_WORKFLOWS = 'workflow-captain-workflows';
	const SLUG_RUNS      = 'workflow-captain-runs';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_workflow_captain_workflow', array( $this, 'handle_workflow' ) );
		add_action( 'admin_post_workflow_captain_run', array( $this, 'handle_run' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Workflows', 'workflow-captain' ), __( 'Workflows', 'workflow-captain' ), 'edit_workflows', self::SLUG_WORKFLOWS, array( $this, 'render_workflows' ) );
		add_submenu_page( OverviewPage::SLUG, __( 'Läufe', 'workflow-captain' ), __( 'Läufe', 'workflow-captain' ), 'view_workflow_logs', self::SLUG_RUNS, array( $this, 'render_runs' ) );
	}

	/** Loads the visual builder on the workflow editor (WF-BUILDER-01). */
	public function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG_WORKFLOWS ) || ! isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides which assets load.
			return;
		}
		$base = plugins_url( 'assets/', WORKFLOW_CAPTAIN_FILE );
		wp_enqueue_style( 'workflow-captain-builder', $base . 'css/builder.css', array(), WORKFLOW_CAPTAIN_VERSION );
		wp_enqueue_script( 'workflow-captain-builder', $base . 'js/builder.js', array(), WORKFLOW_CAPTAIN_VERSION, true );
		$fields  = array(
			'notify.admin'        => array( array( 'key' => 'subject', 'label' => __( 'Betreff', 'workflow-captain' ) ), array( 'key' => 'message', 'label' => __( 'Nachricht', 'workflow-captain' ), 'type' => 'textarea' ), array( 'key' => 'to', 'label' => __( 'Empfänger (site_admin oder Berechtigung)', 'workflow-captain' ) ) ),
			'notify.user'         => array( array( 'key' => 'subject', 'label' => __( 'Betreff', 'workflow-captain' ) ), array( 'key' => 'message', 'label' => __( 'Nachricht', 'workflow-captain' ), 'type' => 'textarea' ), array( 'key' => 'user_field', 'label' => __( 'Benutzer aus (actor_user_id oder data.…_user_id)', 'workflow-captain' ) ) ),
			'workflow.tag.set'    => array( array( 'key' => 'tag', 'label' => __( 'Markierung', 'workflow-captain' ) ) ),
			'workflow.tag.remove' => array( array( 'key' => 'tag', 'label' => __( 'Markierung', 'workflow-captain' ) ) ),
			'workflow.note.add'   => array( array( 'key' => 'text', 'label' => __( 'Notiz', 'workflow-captain' ), 'type' => 'textarea' ) ),
		);
		$actions = array();
		foreach ( $this->plugin->engine()->actions()->all() as $key => $action ) {
			$actions[] = array( 'key' => $key, 'label' => $action->label(), 'fields' => $fields[ $key ] ?? array() );
		}
		$events = array();
		foreach ( $this->plugin->adapters()->products() as $product ) {
			$events = array_merge( $events, (array) $this->plugin->adapters()->get( $product )?->types() );
		}
		$dry  = isset( $_GET['dryrun'] ) ? get_transient( 'workflow_captain_dryrun_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$path = is_array( $dry ) ? array_column( $dry['result']['path'], 'node' ) : array();
		$fields = json_decode( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/data/catalog-fields.json' ), true );
		wp_add_inline_script( 'workflow-captain-builder', 'window.workflowCaptainBuilder = ' . wp_json_encode( array( 'actions' => $actions, 'events' => array_values( array_unique( $events ) ), 'fields' => is_array( $fields ) ? $fields : new \stdClass(), 'path' => $path, 'errorNode' => is_array( $dry ) ? $dry['result']['error_node'] : null, 'i18n' => new \stdClass() ) ) . ';', 'before' );
	}

	private static function url( string $page, array $args = array() ): string {
		return add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) );
	}

	// ----- Handlers -------------------------------------------------------------------------------------------

	public function handle_workflow(): void {
		$id = isset( $_POST['workflow'] ) ? absint( wp_unslash( $_POST['workflow'] ) ) : 0;
		check_admin_referer( 'workflow_captain_workflow_' . $id );
		$do        = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$workflows = $this->plugin->workflows();
		$errors    = array();
		if ( 'dryrun' === $do ) {
			if ( ! current_user_can( 'edit_workflows' ) ) {
				wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
			}
			$graph  = json_decode( isset( $_POST['graph'] ) ? (string) wp_unslash( $_POST['graph'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated by the engine.
			$sample = json_decode( isset( $_POST['sample'] ) ? (string) wp_unslash( $_POST['sample'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, only read.
			$result = \WorkflowCaptain\Engine\DryRun::run( is_array( $graph ) ? $graph : array(), is_array( $sample ) ? $sample : array(), $this->plugin->engine()->actions() );
			set_transient( 'workflow_captain_dryrun_' . get_current_user_id(), array( 'result' => $result, 'graph' => $graph, 'sample' => $sample ), 300 );
			wp_safe_redirect( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $id > 0 ? $id : 'new', 'dryrun' => 1 ) ) );
			exit;
		}
		if ( 'save' === $do || 'publish' === $do ) {
			if ( ! current_user_can( 'edit_workflows' ) ) {
				wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
			}
			$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$raw   = isset( $_POST['graph'] ) ? (string) wp_unslash( $_POST['graph'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, decoded and validated below.
			$graph = json_decode( $raw, true );
			if ( ! is_array( $graph ) ) {
				$errors = array( 'graph:not_json' );
				$graph  = array( 'nodes' => array(), 'edges' => array() );
			}
			list( $id, $violations ) = $workflows->save( $id > 0 ? $id : null, $name, $graph );
			$errors                  = array_merge( $errors, $violations );
			if ( 'publish' === $do && array() === $errors ) {
				if ( ! current_user_can( 'activate_workflows' ) ) {
					wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
				}
				$errors = $workflows->publish( $id, get_current_user_id() );
			}
		} elseif ( in_array( $do, array( 'pause', 'resume' ), true ) ) {
			if ( ! current_user_can( 'activate_workflows' ) ) {
				wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
			}
			'pause' === $do ? $workflows->pause( $id ) : $workflows->resume( $id );
		}
		set_transient( 'workflow_captain_notice_' . get_current_user_id(), array( 'do' => $do, 'errors' => array_slice( $errors, 0, 30 ) ), 60 );
		wp_safe_redirect( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $id ) ) );
		exit;
	}

	public function handle_run(): void {
		if ( ! current_user_can( 'retry_workflow_runs' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		$run = isset( $_POST['run'] ) ? absint( wp_unslash( $_POST['run'] ) ) : 0;
		check_admin_referer( 'workflow_captain_run_' . $run );
		$do        = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$workflows = $this->plugin->workflows();
		$ok        = false;
		if ( 'cancel' === $do ) {
			$ok = $workflows->cancel_run( $run );
		} elseif ( 'continue' === $do ) {
			$step = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 0;
			$ok   = $workflows->continue_step( $step, ! empty( $_POST['confirm'] ) );
		}
		wp_safe_redirect( self::url( self::SLUG_RUNS, array( 'run' => $run, 'done' => $ok ? '1' : '0' ) ) );
		exit;
	}

	// ----- Workflows ------------------------------------------------------------------------------------------

	public function render_workflows(): void {
		if ( ! current_user_can( 'edit_workflows' ) ) {
			return;
		}
		$store = $this->plugin->engine()->store();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Workflows', 'workflow-captain' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::url( self::SLUG_WORKFLOWS, array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Neu', 'workflow-captain' ) . '</a><hr class="wp-header-end">';

		$notice = get_transient( 'workflow_captain_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'workflow_captain_notice_' . get_current_user_id() );
			if ( array() === $notice['errors'] ) {
				echo '<div class="notice notice-success"><p>' . esc_html( 'publish' === $notice['do'] ? __( 'Veröffentlicht. Neue Läufe nutzen diese Version; laufende behalten ihre.', 'workflow-captain' ) : __( 'Gespeichert.', 'workflow-captain' ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Der Workflow kann so nicht veröffentlicht werden:', 'workflow-captain' ) . '</p><ul style="list-style:disc;margin-left:2em">';
				foreach ( $notice['errors'] as $code ) {
					echo '<li><code>' . esc_html( (string) $code ) . '</code></li>';
				}
				echo '</ul></div>';
			}
		}

		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		if ( '' !== $edit ) {
			$this->render_editor( 'new' === $edit ? null : $store->get_workflow( absint( $edit ) ) );
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Auslöser', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Version', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		$rows = $store->list_workflows( 100, 0 );
		if ( array() === $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Noch keine Workflows.', 'workflow-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $wf ) {
			$version = null === $wf['published_version_id'] ? null : $store->get_version( $wf['published_version_id'] );
			$trigger = Graph::trigger( $wf['draft'] );
			echo '<tr><td><a href="' . esc_url( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $wf['id'] ) ) ) . '">' . esc_html( $wf['name'] ) . '</a></td><td>' . esc_html( self::status_label( $wf['status'] ) ) . '</td><td><code>' . esc_html( (string) ( $trigger['event'] ?? '–' ) ) . '</code></td><td>' . esc_html( null === $version ? '–' : (string) $version['version'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_editor( ?array $wf ): void {
		$id    = $wf['id'] ?? 0;
		$graph = $wf['draft'] ?? array(
			'nodes' => array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'pass.ticket.checked_in' ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Einlass {{subject.id}}', 'message' => 'Ticket {{subject.id}} wurde um {{occurred_at}} eingecheckt.' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			'edges' => array( array( 'from' => 'start', 'to' => 'mail' ), array( 'from' => 'mail', 'to' => 'end' ) ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="workflow_captain_workflow"><input type="hidden" name="workflow" value="' . (int) $id . '">';
		wp_nonce_field( 'workflow_captain_workflow_' . (int) $id );
		echo '<p><label for="wfc-name"><strong>' . esc_html__( 'Name', 'workflow-captain' ) . '</strong></label><br><input id="wfc-name" class="regular-text" name="name" value="' . esc_attr( (string) ( $wf['name'] ?? '' ) ) . '"></p>';
		echo '<p><label for="wfc-graph"><strong>' . esc_html__( 'Ablauf (JSON)', 'workflow-captain' ) . '</strong></label><br><textarea id="wfc-graph" name="graph" rows="22" class="large-text code">' . esc_textarea( (string) wp_json_encode( $graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea></p>';
		$actions = array_map( static fn( $a ) => $a->key(), $this->plugin->engine()->actions()->all() );
		/* translators: %s: comma separated action keys */
		echo '<p class="description">' . esc_html( sprintf( __( 'Knoten: trigger, condition, action, delay, end. Verfügbare Aktionen: %s. Platzhalter in Texten: {{type}}, {{occurred_at}}, {{subject.id}}, {{data.feld}}.', 'workflow-captain' ), implode( ', ', $actions ) ) ) . '</p>';
		if ( null !== $wf ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: status */ __( 'Zustand: %s', 'workflow-captain' ), self::status_label( $wf['status'] ) ) ) . '</p>';
		}
		$dry = isset( $_GET['dryrun'] ) ? get_transient( 'workflow_captain_dryrun_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$sample = is_array( $dry ) && is_array( $dry['sample'] ) ? $dry['sample'] : array( 'data' => new \stdClass() );
		echo '<details' . ( is_array( $dry ) ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Testlauf mit Beispieldaten (ohne Seiteneffekte)', 'workflow-captain' ) . '</strong></summary>';
		echo '<p><label for="wfc-sample">' . esc_html__( 'Beispielereignis (JSON, mindestens "data")', 'workflow-captain' ) . '</label><br><textarea id="wfc-sample" name="sample" rows="5" class="large-text code">' . esc_textarea( (string) wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea></p>';
		echo '<p><button class="button" name="do" value="dryrun">' . esc_html__( 'Testlauf starten', 'workflow-captain' ) . '</button></p>';
		if ( is_array( $dry ) ) {
			echo '<p><strong>' . esc_html( sprintf( /* translators: %s: outcome code */ __( 'Ergebnis: %s', 'workflow-captain' ), $dry['result']['outcome'] ) ) . '</strong></p><ol>';
			foreach ( $dry['result']['path'] as $entry ) {
				$detail = array();
				foreach ( array( 'outcome', 'action', 'wait' ) as $key ) {
					if ( isset( $entry[ $key ] ) ) {
						$detail[] = $key . ': ' . $entry[ $key ];
					}
				}
				foreach ( (array) ( $entry['would'] ?? array() ) as $field => $text ) {
					$detail[] = $field . ' → ' . $text;
				}
				echo '<li><code>' . esc_html( $entry['node'] ) . '</code> (' . esc_html( $entry['type'] ) . ')' . ( $detail ? ' – ' . esc_html( implode( '; ', $detail ) ) : '' ) . '</li>';
			}
			echo '</ol>';
		}
		echo '</details>';
		echo '<p><button class="button" name="do" value="save">' . esc_html__( 'Als Entwurf speichern', 'workflow-captain' ) . '</button> ';
		if ( current_user_can( 'activate_workflows' ) ) {
			echo '<button class="button button-primary" name="do" value="publish">' . esc_html__( 'Prüfen und veröffentlichen', 'workflow-captain' ) . '</button> ';
			if ( null !== $wf && EngineStore::WF_ACTIVE === $wf['status'] ) {
				echo '<button class="button" name="do" value="pause">' . esc_html__( 'Pausieren', 'workflow-captain' ) . '</button>';
			} elseif ( null !== $wf && EngineStore::WF_PAUSED === $wf['status'] ) {
				echo '<button class="button" name="do" value="resume">' . esc_html__( 'Fortsetzen', 'workflow-captain' ) . '</button>';
			}
		}
		echo '</p></form>';
	}

	// ----- Runs -----------------------------------------------------------------------------------------------

	public function render_runs(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$store = $this->plugin->engine()->store();
		echo '<div class="wrap"><h1>' . esc_html__( 'Läufe', 'workflow-captain' ) . '</h1>';
		if ( isset( $_GET['done'] ) ) {
			$ok = '1' === sanitize_key( wp_unslash( $_GET['done'] ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $ok ? __( 'Erledigt.', 'workflow-captain' ) : __( 'Das ging nicht. Ein Schritt mit begonnener Nachricht braucht die Bestätigung, dass sie erneut gesendet werden darf.', 'workflow-captain' ) ) . '</p></div>';
		}
		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
		$run    = $run_id > 0 ? $store->get_run( $run_id ) : null;
		if ( null !== $run ) {
			$this->render_run( $run );
			echo '</div>';
			return;
		}
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Nr.', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Workflow', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Ereignis', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Grund', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Begonnen (UTC)', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		$runs = $store->find_runs( '' === $state ? array() : array( 'state' => $state ), 100, 0 );
		if ( array() === $runs ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Keine Läufe.', 'workflow-captain' ) . '</td></tr>';
		}
		foreach ( $runs as $r ) {
			$wf = $store->get_workflow( $r['workflow_id'] );
			echo '<tr><td><a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'run' => $r['id'] ) ) ) . '">' . (int) $r['id'] . '</a></td><td>' . esc_html( (string) ( $wf['name'] ?? $r['workflow_id'] ) ) . '</td><td><code>' . esc_html( (string) ( $r['context']['type'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $r['context']['subject']['id'] ?? '' ) ) . '</td><td>' . esc_html( self::run_label( $r['state'] ) ) . '</td><td>' . esc_html( (string) $r['last_error'] ) . '</td><td>' . esc_html( $r['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_run( array $run ): void {
		$store = $this->plugin->engine()->store();
		echo '<p><a href="' . esc_url( self::url( self::SLUG_RUNS ) ) . '">&larr; ' . esc_html__( 'Alle Läufe', 'workflow-captain' ) . '</a></p>';
		/* translators: 1: run number, 2: state */
		echo '<h2>' . esc_html( sprintf( __( 'Lauf %1$d: %2$s', 'workflow-captain' ), $run['id'], self::run_label( $run['state'] ) ) ) . '</h2>';
		echo '<p><code>' . esc_html( (string) ( $run['context']['type'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $run['context']['subject']['type'] ?? '' ) . ' ' . (string) ( $run['context']['subject']['id'] ?? '' ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Schritt', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Versuche', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Fällig (UTC)', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Ergebnis', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Grund', 'workflow-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $store->steps_for_run( $run['id'] ) as $step ) {
			echo '<tr><td><code>' . esc_html( $step['node_id'] ) . '</code></td><td>' . esc_html( $step['state'] ) . '</td><td>' . (int) $step['attempts'] . '</td><td>' . esc_html( $step['due_at'] ) . '</td><td><code>' . esc_html( null === $step['result'] ? '' : (string) wp_json_encode( $step['result'] ) ) . '</code></td><td>' . esc_html( (string) $step['last_error'] ) . '</td><td>';
			if ( in_array( $step['state'], array( EngineStore::STEP_ACTION_REQUIRED, EngineStore::STEP_PAUSED ), true ) && current_user_can( 'retry_workflow_runs' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_run"><input type="hidden" name="run" value="' . (int) $run['id'] . '"><input type="hidden" name="step" value="' . (int) $step['id'] . '">';
				wp_nonce_field( 'workflow_captain_run_' . (int) $run['id'] );
				if ( EngineStore::EFFECT_STARTED === $step['effect'] ) {
					echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Die Nachricht wurde vielleicht schon gesendet. Erneut senden?', 'workflow-captain' ) . '</label><br>';
				}
				echo '<button class="button button-small" name="do" value="continue">' . esc_html__( 'Fortsetzen', 'workflow-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( in_array( $run['state'], EngineStore::RUN_OPEN, true ) && current_user_can( 'retry_workflow_runs' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="workflow_captain_run"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
			wp_nonce_field( 'workflow_captain_run_' . (int) $run['id'] );
			echo '<button class="button" name="do" value="cancel">' . esc_html__( 'Lauf abbrechen', 'workflow-captain' ) . '</button> <span class="description">' . esc_html__( 'Wartende Schritte laufen nicht mehr; Erledigtes wird nicht rückgängig gemacht.', 'workflow-captain' ) . '</span></form>';
		}
	}

	private static function status_label( string $status ): string {
		$labels = array(
			EngineStore::WF_DRAFT  => __( 'Entwurf', 'workflow-captain' ),
			EngineStore::WF_ACTIVE => __( 'Aktiv', 'workflow-captain' ),
			EngineStore::WF_PAUSED => __( 'Pausiert', 'workflow-captain' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function run_label( string $state ): string {
		$labels = array(
			EngineStore::RUN_RUNNING         => __( 'Läuft', 'workflow-captain' ),
			EngineStore::RUN_SUCCEEDED       => __( 'Erfolgreich', 'workflow-captain' ),
			EngineStore::RUN_STOPPED         => __( 'Beendet (Bedingung)', 'workflow-captain' ),
			EngineStore::RUN_FAILED          => __( 'Fehlgeschlagen', 'workflow-captain' ),
			EngineStore::RUN_ACTION_REQUIRED => __( 'Aktion erforderlich', 'workflow-captain' ),
			EngineStore::RUN_PAUSED          => __( 'Pausiert', 'workflow-captain' ),
			EngineStore::RUN_CANCELLED       => __( 'Abgebrochen', 'workflow-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}
}
