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
	const PER_PAGE       = 50;
	const ERROR_DAYS     = 30;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_workflow_captain_workflow', array( $this, 'handle_workflow' ) );
		add_action( 'admin_post_workflow_captain_run', array( $this, 'handle_run' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_workflow_captain_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_workflow_captain_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_workflow_captain_run_export', array( $this, 'handle_run_export' ) );
	}

	/** Origin of event types and actions for the builder and the editor header (docs/UI-ENTWUERFE.md, section 2). */
	private function sources(): array {
		return Provenance::sources( $this->plugin->adapters(), array_keys( $this->plugin->engine()->actions()->all() ), array_keys( $this->plugin->connectors()->all() ) );
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
			$own       = method_exists( $action, 'builder_fields' ) ? self::clean_fields( (array) $action->builder_fields() ) : null;
			$actions[] = array( 'key' => $key, 'label' => $action->label(), 'fields' => $own ?? ( $fields[ $key ] ?? array() ) );
		}
		$events = array();
		foreach ( $this->plugin->adapters()->products() as $product ) {
			$events = array_merge( $events, (array) $this->plugin->adapters()->get( $product )?->types() );
		}
		$dry  = isset( $_GET['dryrun'] ) ? get_transient( 'workflow_captain_dryrun_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$path = is_array( $dry ) ? array_column( $dry['result']['path'], 'node' ) : array();
		$fields = json_decode( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/data/catalog-fields.json' ), true );
		// Fields of enabled own connectors, from their manifests, in the same format (WF-CONNECTOR-10).
		$fields = array_merge( is_array( $fields ) ? $fields : array(), $this->plugin->connectors()->builder_fields(), \WorkflowCaptain\Engine\InboundTriggers::builder_fields() );
		$mails  = json_decode( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/data/core-mails.json' ), true );
		// Jump from a run step to its node (docs/UI-ENTWUERFE.md, section 5); node IDs as Graph allows them.
		$node   = isset( $_GET['node'] ) ? (string) wp_unslash( $_GET['node'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- display only, checked against the pattern below.
		$config = array(
			'actions'   => $actions,
			'events'    => array_values( array_unique( $events ) ),
			'fields'    => array() !== $fields ? $fields : new \stdClass(),
			'coreMails' => is_array( $mails ) && is_array( $mails['events'] ?? null ) ? $mails['events'] : new \stdClass(),
			'path'      => $path,
			'errorNode' => is_array( $dry ) ? $dry['result']['error_node'] : null,
			'sources'   => $this->sources(),
			'timezone'  => wp_timezone_string(),
			'locale'    => str_replace( '_', '-', get_user_locale() ),
			'focusNode' => 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $node ) ? $node : null,
			'i18n'      => self::builder_i18n(),
		);
		wp_add_inline_script( 'workflow-captain-builder', 'window.workflowCaptainBuilder = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Translated texts of the builder that are new with origin, schedule preview, field list and the jump from a run.
	 * The older texts keep their German fallbacks in builder.js.
	 *
	 * @return array<string,string>
	 */
	private static function builder_i18n(): array {
		return array(
			'trigger'        => __( 'Auslöser', 'workflow-captain' ),
			'action'         => __( 'Aktion', 'workflow-captain' ),
			'condition'      => __( 'Wenn/Sonst', 'workflow-captain' ),
			'delay'          => __( 'Warten', 'workflow-captain' ),
			'end'            => __( 'Ende', 'workflow-captain' ),
			'origin'         => __( 'Herkunft', 'workflow-captain' ),
			'contract'       => __( 'Vertrag', 'workflow-captain' ),
			'nodeId'         => __( 'ID', 'workflow-captain' ),
			'fromRun'        => __( 'aus dem Lauf', 'workflow-captain' ),
			'focusShown'     => __( 'Knoten aus dem Lauf markiert:', 'workflow-captain' ),
			'focusMissing'   => __( 'Der Knoten aus dem Lauf ist im Entwurf nicht mehr vorhanden:', 'workflow-captain' ),
			'focusNote'      => __( 'Dieser Knoten ist der Schritt aus dem Lauf. Der Lauf nutzte die veröffentlichte Version; der Entwurf kann abweichen.', 'workflow-captain' ),
			'daily'          => __( 'täglich', 'workflow-captain' ),
			'weekly'         => __( 'wöchentlich', 'workflow-captain' ),
			'monthly'        => __( 'monatlich', 'workflow-captain' ),
			'weekday1'       => __( 'Montag', 'workflow-captain' ),
			'weekday2'       => __( 'Dienstag', 'workflow-captain' ),
			'weekday3'       => __( 'Mittwoch', 'workflow-captain' ),
			'weekday4'       => __( 'Donnerstag', 'workflow-captain' ),
			'weekday5'       => __( 'Freitag', 'workflow-captain' ),
			'weekday6'       => __( 'Samstag', 'workflow-captain' ),
			'weekday7'       => __( 'Sonntag', 'workflow-captain' ),
			'dayShort'       => _x( 'am', 'monthly schedule: on day N', 'workflow-captain' ),
			'serialSubject'  => __( 'je Objekt nacheinander', 'workflow-captain' ),
			'serialActor'    => __( 'je Benutzer nacheinander', 'workflow-captain' ),
			'ruleGroups'     => __( 'Regelgruppen', 'workflow-captain' ),
			'opExists'       => __( 'vorhanden', 'workflow-captain' ),
			'opMissing'      => __( 'fehlt', 'workflow-captain' ),
			'nextDue'        => __( 'Nächster Termin:', 'workflow-captain' ),
			'nextDues'       => __( 'Nächste Termine (Zeitzone der Website):', 'workflow-captain' ),
			'nextNone'       => __( 'Keine Termine: Der Zeitplan ist unvollständig.', 'workflow-captain' ),
			'schedule'       => __( 'Zeitplan (Zeitzone der Website)', 'workflow-captain' ),
			'field'          => __( 'Feld', 'workflow-captain' ),
			'fieldCustom'    => __( 'Feld (eigener Pfad, z. B. data.feld)', 'workflow-captain' ),
			'fieldOther'     => __( 'Anderes Feld …', 'workflow-captain' ),
			'fieldsEnvelope' => __( 'Ereignis', 'workflow-captain' ),
			'fieldsData'     => __( 'Daten des Ereignisses', 'workflow-captain' ),
			'notInCatalog'   => __( '(nicht im Katalog)', 'workflow-captain' ),
			'example'        => __( 'Beispiel:', 'workflow-captain' ),
			'thenOpen'       => __( 'Der Dann-Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.', 'workflow-captain' ),
			'elseOpen'       => __( 'Der Sonst-Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.', 'workflow-captain' ),
		);
	}

	public static function url( string $page, array $args = array() ): string {
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
		} elseif ( in_array( $do, array( 'duplicate', 'archive', 'restore' ), true ) ) {
			if ( ! current_user_can( 'edit_workflows' ) ) {
				wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
			}
			if ( 'duplicate' === $do ) {
				$id = $workflows->duplicate( $id );
			} elseif ( 'archive' === $do ) {
				$workflows->archive( $id );
			} else {
				$workflows->restore( $id );
			}
		} elseif ( in_array( $do, array( 'pause', 'resume' ), true ) ) {
			if ( ! current_user_can( 'activate_workflows' ) ) {
				wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
			}
			'pause' === $do ? $workflows->pause( $id ) : $workflows->resume( $id );
		}
		if ( array() === $errors && in_array( $do, array( 'publish', 'archive', 'restore', 'pause', 'resume' ), true ) ) {
			\WorkflowCaptain\Audit::record( 'workflow_' . $do, array( 'workflow' => $id ) );
		}
		set_transient( 'workflow_captain_notice_' . get_current_user_id(), array( 'do' => $do, 'errors' => array_slice( $errors, 0, 30 ) ), 60 );
		wp_safe_redirect( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $id ) ) );
		exit;
	}

	public function handle_export(): void {
		$id = isset( $_GET['workflow'] ) ? absint( wp_unslash( $_GET['workflow'] ) ) : 0;
		check_admin_referer( 'workflow_captain_export_' . $id );
		if ( ! current_user_can( 'edit_workflows' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		$wf = $this->plugin->engine()->store()->get_workflow( $id );
		if ( null === $wf ) {
			wp_die( esc_html__( 'Workflow nicht gefunden.', 'workflow-captain' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workflow-' . $id . '.json"' );
		echo wp_json_encode( \WorkflowCaptain\Engine\Transfer::export( $wf, WORKFLOW_CAPTAIN_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/** "Lauf exportieren": JSON for support, redacted by EnvelopeView::run_export() (docs/UI-ENTWUERFE.md, section 5). */
	public function handle_run_export(): void {
		$id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
		check_admin_referer( 'workflow_captain_run_export_' . $id );
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		$store = $this->plugin->engine()->store();
		$run   = $store->get_run( $id );
		if ( null === $run ) {
			wp_die( esc_html__( 'Lauf nicht gefunden.', 'workflow-captain' ), 404 );
		}
		$wf      = $store->get_workflow( $run['workflow_id'] );
		$version = $run['version_id'] > 0 ? $store->get_version( $run['version_id'] ) : null;
		$export  = \WorkflowCaptain\Privacy\EnvelopeView::run_export(
			$run,
			self::timeline( $store->steps_for_run( $id ) ),
			null === $wf ? null : (string) $wf['name'],
			null === $version ? null : (int) $version['version'],
			\WorkflowCaptain\Privacy\EnvelopeView::allowed_fields( $this->plugin->connectors()->builder_fields( false ) ),
			WORKFLOW_CAPTAIN_VERSION,
			gmdate( 'Y-m-d\TH:i:s\Z' )
		);
		\WorkflowCaptain\Audit::record( 'run_export', array( 'run' => $id ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workflow-captain-run-' . $id . '.json"' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	public function handle_import(): void {
		check_admin_referer( 'workflow_captain_import' );
		if ( ! current_user_can( 'edit_workflows' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		$json = '';
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only, size-limited, decoded and validated.
			$json = (string) file_get_contents( $_FILES['file']['tmp_name'], false, null, 0, \WorkflowCaptain\Engine\Transfer::MAX_BYTES + 1 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$result = \WorkflowCaptain\Engine\Transfer::import( $json, $this->plugin->workflows() );
		set_transient( 'workflow_captain_notice_' . get_current_user_id(), array( 'do' => 'import', 'errors' => array_slice( $result['violations'], 0, 30 ) ), 60 );
		wp_safe_redirect( self::url( self::SLUG_WORKFLOWS, $result['ok'] ? array( 'edit' => $result['id'] ) : array() ) );
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
		if ( $ok ) {
			\WorkflowCaptain\Audit::record( 'run_' . $do, array( 'run' => $run ) );
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
				$texts = array(
					'publish' => __( 'Veröffentlicht. Neue Läufe nutzen diese Version; laufende behalten ihre.', 'workflow-captain' ),
					'import'  => __( 'Als Entwurf importiert.', 'workflow-captain' ),
				);
				echo '<div class="notice notice-success"><p>' . esc_html( $texts[ $notice['do'] ] ?? __( 'Gespeichert.', 'workflow-captain' ) ) . '</p></div>';
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

		$pages = Pagination::state( $store->count_workflows(), self::PER_PAGE, Pagination::requested() );
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Workflows, neueste zuerst', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Name', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Auslöser', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Version', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Letzter Lauf (UTC)', 'workflow-captain' ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %d: number of days */ __( 'Fehler (%d Tage)', 'workflow-captain' ), self::ERROR_DAYS ) ) . '</th></tr></thead><tbody>';
		$rows = $store->list_workflows( self::PER_PAGE, $pages['offset'] );
		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Workflows.', 'workflow-captain' ) . '</td></tr>';
		}
		$summary = $store->workflow_run_summary( array_column( $rows, 'id' ), self::error_since( time() ) );
		foreach ( $rows as $wf ) {
			$version = null === $wf['published_version_id'] ? null : $store->get_version( $wf['published_version_id'] );
			$trigger = Graph::trigger( $wf['draft'] );
			$export = wp_nonce_url( add_query_arg( array( 'action' => 'workflow_captain_export', 'workflow' => $wf['id'] ), admin_url( 'admin-post.php' ) ), 'workflow_captain_export_' . $wf['id'] );
			echo '<tr><td><a href="' . esc_url( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $wf['id'] ) ) ) . '">' . esc_html( $wf['name'] ) . '</a> <span class="row-actions"><a href="' . esc_url( $export ) . '">' . esc_html__( 'Exportieren', 'workflow-captain' ) . '</a></span></td><td>' . esc_html( self::status_label( $wf['status'] ) ) . '</td><td><code>' . esc_html( (string) ( $trigger['event'] ?? '–' ) ) . '</code></td><td>' . esc_html( null === $version ? '–' : (string) $version['version'] ) . '</td>' . self::summary_cells( (int) $wf['id'], $summary[ (int) $wf['id'] ] ?? null ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- summary_cells() escapes.
		}
		echo '</tbody></table>';
		echo Pagination::render( $pages, array( 'page' => self::SLUG_WORKFLOWS ), __( 'Seiten der Workflow-Liste', 'workflow-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Pagination::render().
		echo '<h2>' . esc_html__( 'Workflow importieren', 'workflow-captain' ) . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_import">';
		wp_nonce_field( 'workflow_captain_import' );
		echo '<p><label for="wfc-import-file">' . esc_html__( 'Workflow-Datei (JSON)', 'workflow-captain' ) . '</label> <input id="wfc-import-file" type="file" name="file" accept="application/json,.json"> <button class="button">' . esc_html__( 'Als Entwurf importieren', 'workflow-captain' ) . '</button></p><p class="description">' . esc_html__( 'Ein Import wird immer ein Entwurf. Veröffentlicht wird erst nach Prüfung und Testlauf.', 'workflow-captain' ) . '</p></form></div>';
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
		$this->render_editor_head( $wf, $graph );
		echo '<p><label for="wfc-name"><strong>' . esc_html__( 'Name', 'workflow-captain' ) . '</strong></label><br><input id="wfc-name" class="regular-text" name="name" value="' . esc_attr( (string) ( $wf['name'] ?? '' ) ) . '"></p>';
		echo '<p><label for="wfc-graph"><strong>' . esc_html__( 'Ablauf (JSON)', 'workflow-captain' ) . '</strong></label><br><textarea id="wfc-graph" name="graph" rows="22" class="large-text code">' . esc_textarea( (string) wp_json_encode( $graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea></p>';
		$actions = array_map( static fn( $a ) => $a->key(), $this->plugin->engine()->actions()->all() );
		/* translators: %s: comma separated action keys */
		echo '<p class="description">' . esc_html( sprintf( __( 'Knoten: trigger, condition, action, delay, end. Verfügbare Aktionen: %s. Platzhalter in Texten: {{type}}, {{occurred_at}}, {{subject.id}}, {{data.feld}}.', 'workflow-captain' ), implode( ', ', $actions ) ) ) . '</p>';
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
		if ( null !== $wf ) {
			echo ' <button class="button" name="do" value="duplicate">' . esc_html__( 'Duplizieren', 'workflow-captain' ) . '</button>';
			if ( EngineStore::WF_ARCHIVED === $wf['status'] ) {
				echo ' <button class="button" name="do" value="restore">' . esc_html__( 'Wiederherstellen', 'workflow-captain' ) . '</button>';
			} else {
				echo ' <button class="button button-link-delete" name="do" value="archive" onclick="return confirm(\'' . esc_js( __( 'Workflow archivieren? Er startet keine neuen Läufe mehr; Läufe und Protokolle bleiben erhalten.', 'workflow-captain' ) ) . '\')">' . esc_html__( 'Archivieren', 'workflow-captain' ) . '</button>';
			}
		}
		echo '</p></form>';
	}

	/**
	 * Header of the editor (docs/UI-ENTWUERFE.md, section 2): name, state and published version, the trigger's
	 * provider with plugin and contract version, the last run, and test run and publish at the top.
	 */
	private function render_editor_head( ?array $wf, array $graph ): void {
		$store   = $this->plugin->engine()->store();
		$version = null !== $wf && null !== $wf['published_version_id'] ? $store->get_version( $wf['published_version_id'] ) : null;
		$parts   = array( null === $wf ? __( 'Neu, noch nicht gespeichert', 'workflow-captain' ) : self::status_label( $wf['status'] ) );
		/* translators: %d: version number */
		$parts[] = null === $version ? __( 'noch nicht veröffentlicht', 'workflow-captain' ) : sprintf( __( 'Version %d veröffentlicht', 'workflow-captain' ), (int) $version['version'] );
		$trigger = Graph::trigger( $graph );
		$event   = (string) ( $trigger['event'] ?? '' );
		echo '<div class="wfc-editor-head"><div><h2>' . esc_html( null === $wf ? __( 'Neuer Workflow', 'workflow-captain' ) : (string) $wf['name'] ) . '</h2>';
		echo '<p class="wfc-editor-meta">' . esc_html( implode( ' · ', $parts ) );
		if ( '' !== $event ) {
			$origin = Provenance::describe_event( $this->sources(), $event );
			/* translators: 1: event type, 2: provider with plugin and contract version */
			echo '<br>' . esc_html( null === $origin ? sprintf( __( 'Auslöser %s: Provider nicht verfügbar', 'workflow-captain' ), $event ) : sprintf( __( 'Auslöser %1$s von %2$s', 'workflow-captain' ), $event, $origin ) );
		}
		if ( null !== $wf ) {
			$last = $store->workflow_run_summary( array( (int) $wf['id'] ), self::error_since( time() ) )[ (int) $wf['id'] ] ?? null;
			if ( null !== $last ) {
				/* translators: 1: run number, 2: state */
				echo '<br>' . esc_html__( 'Letzter Lauf:', 'workflow-captain' ) . ' <a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'run' => $last['last_run_id'] ) ) ) . '">' . esc_html( sprintf( __( 'Lauf %1$d, %2$s', 'workflow-captain' ), $last['last_run_id'], self::run_label( $last['last_state'] ) ) ) . '</a>, ' . self::time_html( $last['last_at'] ) . ' UTC'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- time_html() escapes.
			}
		}
		echo '</p></div><p class="wfc-editor-actions"><button class="button" name="do" value="dryrun">' . esc_html__( 'Testlauf', 'workflow-captain' ) . '</button>';
		if ( current_user_can( 'activate_workflows' ) ) {
			echo ' <button class="button button-primary" name="do" value="publish">' . esc_html__( 'Prüfen und veröffentlichen', 'workflow-captain' ) . '</button>';
		}
		echo '</p></div>';
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
		$states   = array( EngineStore::RUN_RUNNING, EngineStore::RUN_ACTION_REQUIRED, EngineStore::RUN_PAUSED, EngineStore::RUN_FAILED, EngineStore::RUN_SUCCEEDED, EngineStore::RUN_STOPPED, EngineStore::RUN_CANCELLED );
		$state    = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$state    = in_array( $state, $states, true ) ? $state : '';
		$corr     = EventsPage::correlation_param();
		$workflow = isset( $_GET['workflow'] ) ? absint( wp_unslash( $_GET['workflow'] ) ) : 0;
		$base     = array_filter( array( 'correlation_id' => $corr, 'workflow_id' => $workflow ) );
		$filter   = $base + array_filter( array( 'state' => $state ) );
		$pages    = Pagination::state( $store->count_runs( $filter ), self::PER_PAGE, Pagination::requested() );
		$args     = array( 'page' => self::SLUG_RUNS, 'correlation' => $corr, 'workflow' => $workflow );

		if ( '' !== $corr || $workflow > 0 ) {
			$wf_row = $workflow > 0 ? $store->get_workflow( $workflow ) : null;
			$parts  = array();
			if ( '' !== $corr ) {
				/* translators: %s: correlation ID */
				$parts[] = sprintf( __( 'Korrelation %s', 'workflow-captain' ), $corr );
			}
			if ( $workflow > 0 ) {
				/* translators: %s: workflow name or number */
				$parts[] = sprintf( __( 'Workflow „%s“', 'workflow-captain' ), (string) ( $wf_row['name'] ?? $workflow ) );
			}
			/* translators: %s: active filters */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Nur Läufe mit: %s.', 'workflow-captain' ), implode( ', ', $parts ) ) ) . ' ';
			if ( '' !== $corr ) {
				echo '<a href="' . esc_url( add_query_arg( array( 'page' => EventsPage::SLUG, 'correlation' => $corr ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Ereignisse mit dieser Korrelation', 'workflow-captain' ) . '</a> | ';
			}
			echo '<a href="' . esc_url( self::url( self::SLUG_RUNS, array_filter( array( 'state' => $state ) ) ) ) . '">' . esc_html__( 'Filter entfernen', 'workflow-captain' ) . '</a></p></div>';
		}
		$links = array( '<a href="' . esc_url( add_query_arg( array_filter( $args ), admin_url( 'admin.php' ) ) ) . '"' . ( '' === $state ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Alle', 'workflow-captain' ) . ' (' . (int) $store->count_runs( $base ) . ')</a>' );
		foreach ( $states as $key ) {
			$links[] = '<a href="' . esc_url( add_query_arg( array_filter( $args + array( 'state' => $key ) ), admin_url( 'admin.php' ) ) ) . '"' . ( $state === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( self::run_label( $key ) ) . ' (' . (int) $store->count_runs( $base + array( 'state' => $key ) ) . ')</a>';
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.

		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Läufe, neueste zuerst', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Nr.', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Workflow', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Ereignis', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Grund', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Begonnen (UTC)', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		$runs = $store->find_runs( $filter, self::PER_PAGE, $pages['offset'] );
		if ( array() === $runs ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Keine Läufe.', 'workflow-captain' ) . '</td></tr>';
		}
		$names = array();
		foreach ( $runs as $r ) {
			if ( ! array_key_exists( $r['workflow_id'], $names ) ) {
				$names[ $r['workflow_id'] ] = $store->get_workflow( $r['workflow_id'] )['name'] ?? (string) $r['workflow_id'];
			}
			/* translators: %d: run number */
			echo '<tr><td><a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'run' => $r['id'] ) ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Lauf %d ansehen', 'workflow-captain' ), (int) $r['id'] ) ) . '">' . (int) $r['id'] . '</a></td><td>' . esc_html( (string) $names[ $r['workflow_id'] ] ) . '</td><td><code>' . esc_html( (string) ( $r['context']['type'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $r['context']['subject']['id'] ?? '' ) ) . '</td><td>' . esc_html( self::run_label( $r['state'] ) ) . '</td><td>' . esc_html( (string) $r['last_error'] ) . '</td><td>' . esc_html( $r['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo Pagination::render( $pages, $args + array( 'state' => $state ), __( 'Seiten der Laufliste', 'workflow-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Pagination::render().
		echo '</div>';
	}

	private function render_run( array $run ): void {
		$store   = $this->plugin->engine()->store();
		$context = $run['context'];
		echo '<p><a href="' . esc_url( self::url( self::SLUG_RUNS ) ) . '">&larr; ' . esc_html__( 'Alle Läufe', 'workflow-captain' ) . '</a></p>';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'workflow_captain_run_export', 'run' => $run['id'] ), admin_url( 'admin-post.php' ) ), 'workflow_captain_run_export_' . $run['id'] );
		/* translators: 1: run number, 2: state */
		echo '<div class="wfc-editor-head"><h2>' . esc_html( sprintf( __( 'Lauf %1$d: %2$s', 'workflow-captain' ), $run['id'], self::run_label( $run['state'] ) ) ) . '</h2>';
		echo '<p class="wfc-editor-actions"><a class="button" href="' . esc_url( $export ) . '" download>' . esc_html__( 'Lauf exportieren (JSON, redigiert)', 'workflow-captain' ) . '</a></p></div>';

		$wf      = $store->get_workflow( $run['workflow_id'] );
		$version = $run['version_id'] > 0 ? $store->get_version( $run['version_id'] ) : null;
		$corr    = (string) ( $context['correlation_id'] ?? '' );
		$meta    = array();
		$name    = esc_html( (string) ( $wf['name'] ?? $run['workflow_id'] ) );
		$editor  = null !== $wf && EngineStore::WF_NETWORK !== $wf['status'] && current_user_can( 'edit_workflows' );
		if ( $editor ) {
			$name = '<a href="' . esc_url( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $wf['id'] ) ) ) . '">' . $name . '</a>';
		}
		$name .= ' (<a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'workflow' => $run['workflow_id'] ) ) ) . '">' . esc_html__( 'alle Läufe dieses Workflows', 'workflow-captain' ) . '</a>)';
		$meta[ __( 'Workflow', 'workflow-captain' ) ] = $name;
		if ( null !== $version ) {
			$meta[ __( 'Version', 'workflow-captain' ) ] = esc_html( (string) $version['version'] );
		}
		$meta[ __( 'Ereignis', 'workflow-captain' ) ]          = '<code>' . esc_html( (string) ( $context['type'] ?? '' ) ) . '</code>';
		$meta[ __( 'Objekt', 'workflow-captain' ) ]            = esc_html( trim( (string) ( $context['subject']['type'] ?? '' ) . ' ' . (string) ( $context['subject']['id'] ?? '' ) ) );
		$meta[ __( 'Eingetreten', 'workflow-captain' ) ]       = esc_html( (string) ( $context['occurred_at'] ?? '–' ) );
		$meta[ __( 'Begonnen (UTC)', 'workflow-captain' ) ]    = esc_html( $run['created_at'] );
		$meta[ in_array( $run['state'], EngineStore::RUN_OPEN, true ) ? __( 'Zuletzt geändert (UTC)', 'workflow-captain' ) : __( 'Beendet (UTC)', 'workflow-captain' ) ] = esc_html( $run['updated_at'] );
		if ( '' !== (string) $run['last_error'] ) {
			$meta[ __( 'Grund', 'workflow-captain' ) ] = '<code>' . esc_html( (string) $run['last_error'] ) . '</code>';
		}
		if ( '' !== $corr ) {
			$meta[ __( 'Korrelation', 'workflow-captain' ) ] = '<code>' . esc_html( $corr ) . '</code> (<a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'correlation' => $corr ) ) ) . '">' . esc_html__( 'Läufe mit dieser Korrelation', 'workflow-captain' ) . '</a> | <a href="' . esc_url( add_query_arg( array( 'page' => EventsPage::SLUG, 'correlation' => $corr ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Ereignisse mit dieser Korrelation', 'workflow-captain' ) . '</a>)';
		}
		if ( ! empty( $context['idempotency_key'] ) ) {
			$meta[ __( 'Idempotenzschlüssel', 'workflow-captain' ) ] = '<code>' . esc_html( (string) $context['idempotency_key'] ) . '</code>';
		}
		echo '<table class="widefat striped wfc-run-meta" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Angaben zum Lauf', 'workflow-captain' ) . '</caption><tbody>';
		foreach ( $meta as $label => $html ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Zeitleiste der Schritte', 'workflow-captain' ) . '</h3>';
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Schritte des Laufs in ihrer Reihenfolge, Zeiten in UTC', 'workflow-captain' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Schritt', 'workflow-captain' ), __( 'Zustand', 'workflow-captain' ), __( 'Angelegt (UTC)', 'workflow-captain' ), __( 'Wartet bis (UTC)', 'workflow-captain' ), __( 'Beendet (UTC)', 'workflow-captain' ), __( 'Versuche', 'workflow-captain' ), __( 'Ergebnis', 'workflow-captain' ), __( 'Fehler', 'workflow-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'workflow-captain' ) . '</span></th></tr></thead><tbody>';
		$steps = $store->steps_for_run( $run['id'] );
		if ( array() === $steps ) {
			echo '<tr><td colspan="9">' . esc_html__( 'Noch keine Schritte.', 'workflow-captain' ) . '</td></tr>';
		}
		foreach ( self::timeline( $steps ) as $i => $row ) {
			$step = $steps[ $i ];
			$jump = $editor ? ' <a class="wfc-jump" href="' . esc_url( self::url( self::SLUG_WORKFLOWS, array( 'edit' => $wf['id'], 'node' => $row['node'] ) ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: node ID */ __( 'Knoten %s im Editor zeigen', 'workflow-captain' ), $row['node'] ) ) . '">' . esc_html__( 'Im Editor zeigen', 'workflow-captain' ) . '</a>' : '';
			echo '<tr><th scope="row"><code>' . esc_html( $row['node'] ) . '</code>' . $jump . '</th><td>' . esc_html( self::step_label( $row['state'] ) ) . '</td><td>' . self::time_html( $row['started'] ) . '</td><td>' . self::time_html( $row['wait_until'] ) . '</td><td>' . self::time_html( $row['ended'] ) . '</td><td>' . (int) $row['attempts'] . '</td><td>' . ( array() === $row['result'] ? '' : '<code>' . esc_html( (string) wp_json_encode( $row['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</code>' ) . '</td><td>' . esc_html( (string) $row['error'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- time_html() escapes.
			if ( in_array( $step['state'], array( EngineStore::STEP_ACTION_REQUIRED, EngineStore::STEP_PAUSED ), true ) && current_user_can( 'retry_workflow_runs' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_run"><input type="hidden" name="run" value="' . (int) $run['id'] . '"><input type="hidden" name="step" value="' . (int) $step['id'] . '">';
				wp_nonce_field( 'workflow_captain_run_' . (int) $run['id'] );
				if ( EngineStore::EFFECT_STARTED === $step['effect'] ) {
					echo '<label class="wfc-confirm"><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Die Nachricht wurde vielleicht schon gesendet. Erneut senden?', 'workflow-captain' ) . '</label><br>';
				}
				/* translators: %s: node ID of the step */
				echo '<button class="button button-small" name="do" value="continue" aria-label="' . esc_attr( sprintf( __( 'Schritt %s fortsetzen', 'workflow-captain' ), $step['node_id'] ) ) . '">' . esc_html__( 'Fortsetzen', 'workflow-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$view = \WorkflowCaptain\Privacy\EnvelopeView::redacted( $context, \WorkflowCaptain\Privacy\EnvelopeView::allowed_fields( $this->plugin->connectors()->builder_fields( false ) ) );
		if ( array() === $view['envelope']['data'] ) {
			$view['envelope']['data'] = new \stdClass();
		}
		echo '<details class="wfc-run-data"><summary>' . esc_html__( 'Ereignisdaten (redigiert)', 'workflow-captain' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Nur Felder aus dem Ereigniskatalog, ohne Benutzer, Adressen und Geheimnisse.', 'workflow-captain' );
		if ( $view['hidden'] > 0 ) {
			/* translators: %d: number of hidden data fields */
			echo ' ' . esc_html( sprintf( _n( '%d Datenfeld ist ausgeblendet.', '%d Datenfelder sind ausgeblendet.', $view['hidden'], 'workflow-captain' ), $view['hidden'] ) );
		}
		echo '</p><pre tabindex="0" aria-label="' . esc_attr__( 'Ereignisdaten (redigiert) als JSON', 'workflow-captain' ) . '" style="white-space:pre-wrap;word-break:break-word;max-width:100%">' . esc_html( (string) wp_json_encode( $view['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';

		if ( in_array( $run['state'], EngineStore::RUN_OPEN, true ) && current_user_can( 'retry_workflow_runs' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="workflow_captain_run"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
			wp_nonce_field( 'workflow_captain_run_' . (int) $run['id'] );
			echo '<button class="button" name="do" value="cancel">' . esc_html__( 'Lauf abbrechen', 'workflow-captain' ) . '</button> <span class="description">' . esc_html__( 'Wartende Schritte laufen nicht mehr; Erledigtes wird nicht rückgängig gemacht.', 'workflow-captain' ) . '</span></form>';
		}
	}

	/** Start of the error window of the workflow list (UTC, MySQL format). */
	public static function error_since( int $now ): string {
		return gmdate( 'Y-m-d H:i:s', $now - self::ERROR_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Cells "Letzter Lauf" and "Fehler" of a workflow row (docs/UI-ENTWUERFE.md, section 1): time, state and link of
	 * the last run; failed or action_required runs of the last ERROR_DAYS days, linked to the runs of the workflow.
	 *
	 * @param array{last_run_id:int,last_state:string,last_at:string,errors:int}|null $summary
	 */
	public static function summary_cells( int $workflow_id, ?array $summary ): string {
		if ( null === $summary ) {
			return '<td>' . self::time_html( null ) . '</td><td>0</td>';
		}
		$bad   = in_array( $summary['last_state'], array( EngineStore::RUN_FAILED, EngineStore::RUN_ACTION_REQUIRED ), true );
		/* translators: %d: run number */
		$last  = '<a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'run' => $summary['last_run_id'] ) ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Lauf %d ansehen', 'workflow-captain' ), $summary['last_run_id'] ) ) . '">' . self::time_html( $summary['last_at'] ) . '</a> ';
		$last .= '<span class="wfc-run-state' . ( $bad ? ' wfc-run-state-bad' : '' ) . '">' . esc_html( self::run_label( $summary['last_state'] ) ) . '</span>';
		$errors = (int) $summary['errors'];
		/* translators: %d: number of runs */
		$count  = $errors > 0 ? '<a href="' . esc_url( self::url( self::SLUG_RUNS, array( 'workflow' => $workflow_id ) ) ) . '" aria-label="' . esc_attr( sprintf( _n( '%d Lauf mit Fehler oder offener Aktion, Läufe dieses Workflows ansehen', '%d Läufe mit Fehler oder offener Aktion, Läufe dieses Workflows ansehen', $errors, 'workflow-captain' ), $errors ) ) . '"><strong>' . $errors . '</strong></a>' : '0';
		return '<td>' . $last . '</td><td>' . $count . '</td>';
	}

	/**
	 * Timeline of the steps of a run (docs/UI-ENTWUERFE.md, section 5): when a step was created, until when it waited
	 * (delay or retry), when it ended or stopped for a human, attempts, redacted result and error code.
	 *
	 * @param array<int,array<string,mixed>> $steps From EngineStore::steps_for_run().
	 * @return array<int,array{node:string,state:string,started:string,wait_until:?string,ended:?string,attempts:int,result:array,error:?string}>
	 */
	public static function timeline( array $steps ): array {
		$ended_states = array( EngineStore::STEP_SUCCEEDED, EngineStore::STEP_CANCELLED, EngineStore::STEP_ACTION_REQUIRED, EngineStore::STEP_PAUSED );
		$out          = array();
		foreach ( $steps as $step ) {
			$out[] = array(
				'node'       => (string) $step['node_id'],
				'state'      => (string) $step['state'],
				'started'    => (string) $step['created_at'],
				'wait_until' => (string) $step['due_at'] > (string) $step['created_at'] ? (string) $step['due_at'] : null,
				'ended'      => in_array( $step['state'], $ended_states, true ) ? (string) $step['updated_at'] : null,
				'attempts'   => (int) $step['attempts'],
				'result'     => \WorkflowCaptain\Engine\Engine::redact( (array) ( $step['result'] ?? array() ) ),
				'error'      => null === $step['last_error'] || '' === (string) $step['last_error'] ? null : (string) $step['last_error'],
			);
		}
		return $out;
	}

	private static function time_html( ?string $mysql ): string {
		if ( null === $mysql || '' === $mysql ) {
			return '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'keine Angabe', 'workflow-captain' ) . '</span>';
		}
		return '<time datetime="' . esc_attr( str_replace( ' ', 'T', $mysql ) . 'Z' ) . '">' . esc_html( $mysql ) . '</time>';
	}

	private static function step_label( string $state ): string {
		$labels = array(
			EngineStore::STEP_QUEUED          => __( 'Wartet', 'workflow-captain' ),
			EngineStore::STEP_RUNNING         => __( 'Läuft', 'workflow-captain' ),
			EngineStore::STEP_RETRY_WAIT      => __( 'Wartet auf Wiederholung', 'workflow-captain' ),
			EngineStore::STEP_SUCCEEDED       => __( 'Erledigt', 'workflow-captain' ),
			EngineStore::STEP_ACTION_REQUIRED => __( 'Aktion erforderlich', 'workflow-captain' ),
			EngineStore::STEP_PAUSED          => __( 'Pausiert', 'workflow-captain' ),
			EngineStore::STEP_CANCELLED       => __( 'Abgebrochen', 'workflow-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}

	private static function status_label( string $status ): string {
		$labels = array(
			EngineStore::WF_DRAFT  => __( 'Entwurf', 'workflow-captain' ),
			EngineStore::WF_ACTIVE => __( 'Aktiv', 'workflow-captain' ),
			EngineStore::WF_PAUSED => __( 'Pausiert', 'workflow-captain' ),
			EngineStore::WF_ARCHIVED => __( 'Archiviert', 'workflow-captain' ),
			EngineStore::WF_NETWORK  => __( 'Netzwerk', 'workflow-captain' ),
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

	/**
	 * Input fields an add-on action declares for the builder through the optional method builder_fields():
	 * a list of array{key:string,label:string,type?:'text'|'textarea'|'number'|'select',options?:array<array{value:string,label:string}>}.
	 * Anything else is dropped; the server still validates the configuration with validate_config().
	 *
	 * @param array<mixed> $declared Declared fields.
	 * @return array<int,array<string,mixed>>
	 */
	public static function clean_fields( array $declared ): array {
		$out = array();
		foreach ( $declared as $f ) {
			if ( ! is_array( $f ) || ! is_string( $f['key'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $f['key'] ) ) {
				continue;
			}
			$type  = in_array( $f['type'] ?? 'text', array( 'text', 'textarea', 'number', 'select' ), true ) ? (string) ( $f['type'] ?? 'text' ) : 'text';
			$field = array( 'key' => $f['key'], 'label' => is_string( $f['label'] ?? null ) ? $f['label'] : $f['key'], 'type' => $type );
			if ( 'select' === $type ) {
				$field['options'] = array();
				foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
					if ( is_array( $o ) && is_scalar( $o['value'] ?? null ) ) {
						$field['options'][] = array( 'value' => (string) $o['value'], 'label' => is_string( $o['label'] ?? null ) ? $o['label'] : (string) $o['value'] );
					}
				}
			}
			$out[] = $field;
		}
		return $out;
	}
}
