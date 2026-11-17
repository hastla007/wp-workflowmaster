/**
 * Workflow Captain – visual builder, first version (WF-BUILDER-01, -03, -04, -08, -09).
 *
 * Works on the JSON textarea of the workflow editor: reads the graph, lets the user edit it on a canvas or in a linear
 * list, and writes it back on every change. The server validates on save and publish (src/Engine/Graph.php), so this
 * file only helps editing; it decides nothing.
 *
 * No build step, no dependencies. Data from PHP: window.workflowCaptainBuilder = { actions: [{key,label,fields}], events: [], i18n: {} }
 * and optionally sources (origin of events and actions), timezone and locale (schedule preview), focusNode (jump from
 * a run step to its node), conditions ({maxDepth, maxRules} of Engine\Conditions) and violations (node ID => codes the
 * server reported at the last check).
 */
( function () {
	'use strict';

	const NS = 'http://www.w3.org/2000/svg';
	const W = 180;
	const H = 64;
	const WEEKDAYS = [ 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag' ];
	const ENVELOPE_FIELDS = [ 'type', 'subject.type', 'subject.id', 'subject.id_kind', 'source.product', 'actor_user_id' ];
	const OPERATORS = [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'exists', 'not_exists' ];
	const DRAG_TYPE = 'application/x-wfc-node';
	// Server codes the builder checks itself (problems()); shown from the last check only when not covered here.
	const COVERED = /^(needs_exactly_one_outgoing_edge|needs_one_then_and_one_else|unreachable|unknown_action|end_has_outgoing_edge|invalid_delay|condition:.*|schedule:.*)$/;

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => {
			if ( 'text' === k ) {
				node.textContent = v;
			} else if ( k.startsWith( 'on' ) ) {
				node.addEventListener( k.slice( 2 ), v );
			} else if ( null !== v && undefined !== v && false !== v ) {
				node.setAttribute( k, true === v ? '' : v );
			}
		} );
		( children || [] ).forEach( ( c ) => node.appendChild( c ) );
		return node;
	}

	let uid = 0;

	/** Paragraph with a label tied to its control (for/id), so screen readers name every field. */
	function labelled( text, input ) {
		if ( ! input.id ) {
			uid++;
			input.id = 'wfc-f' + uid;
		}
		return el( 'p', {}, [ el( 'label', { for: input.id, text } ), el( 'br' ), input ] );
	}

	function svg( tag, attrs ) {
		const node = document.createElementNS( NS, tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => node.setAttribute( k, v ) );
		return node;
	}

	const offsetFormats = {};

	/** Offset of a time zone at an instant in ms (local minus UTC); fixed offsets like "+05:30" work without Intl. */
	function tzOffset( tz, t ) {
		const fixed = /^([+-])(\d{2}):?(\d{2})$/.exec( tz || '' );
		if ( fixed ) {
			return ( '-' === fixed[ 1 ] ? -1 : 1 ) * ( parseInt( fixed[ 2 ], 10 ) * 60 + parseInt( fixed[ 3 ], 10 ) ) * 60000;
		}
		if ( ! offsetFormats[ tz ] ) {
			offsetFormats[ tz ] = new Intl.DateTimeFormat( 'en-US', { timeZone: tz || 'UTC', hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' } );
		}
		const p = {};
		offsetFormats[ tz ].formatToParts( new Date( t ) ).forEach( ( x ) => {
			p[ x.type ] = x.value;
		} );
		return Date.UTC( +p.year, p.month - 1, +p.day, +p.hour % 24, +p.minute, +p.second ) - Math.floor( t / 1000 ) * 1000;
	}

	/**
	 * UTC instant of a local wall time, resolved like Schedule::local_time() in PHP (konzept 15.3, R-4.15): a time in
	 * the spring-forward gap moves forward by the gap; in the repeated hour the first occurrence counts. Checked against
	 * PHP for every quarter hour around the transitions of several zones.
	 */
	function localToUtc( tz, y, mo, d, h, mi ) {
		const guess = Date.UTC( y, mo - 1, d, h, mi );
		const before = tzOffset( tz, guess - 86400000 );
		const after = tzOffset( tz, guess + 86400000 );
		const valid = [ before, after ].map( ( o ) => guess - o ).filter( ( t ) => tzOffset( tz, t ) === guess - t );
		return valid.length ? Math.min( ...valid ) : guess - Math.min( before, after );
	}

	/** Next due times (ms) strictly after now, like Schedule::upcoming() in PHP (docs/UI-ENTWUERFE.md, section 4). */
	function upcoming( schedule, now, tz, count ) {
		const s = schedule || {};
		const m = /^([01]\d|2[0-3]):([0-5]\d)$/.exec( s.time || '' );
		if ( ! m || ! [ 'daily', 'weekly', 'monthly' ].includes( s.frequency ) || ( 'weekly' === s.frequency && ! ( s.weekday >= 1 && s.weekday <= 7 ) ) || ( 'monthly' === s.frequency && ! ( s.day >= 1 && s.day <= 28 ) ) ) {
			return [];
		}
		const local = new Date( now + tzOffset( tz, now ) );
		const out = [];
		count = count || 3;
		for ( let i = 0; i <= 31 * count + 1 && out.length < count; i++ ) {
			const day = new Date( Date.UTC( local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate() + i ) );
			if ( 'weekly' === s.frequency && ( ( day.getUTCDay() + 6 ) % 7 ) + 1 !== s.weekday ) {
				continue;
			}
			if ( 'monthly' === s.frequency && day.getUTCDate() !== s.day ) {
				continue;
			}
			const t = localToUtc( tz, day.getUTCFullYear(), day.getUTCMonth() + 1, day.getUTCDate(), parseInt( m[ 1 ], 10 ), parseInt( m[ 2 ], 10 ) );
			if ( t > now ) {
				out.push( t );
			}
		}
		return out;
	}

	/**
	 * Violation codes of a condition, the same rules as Conditions::validate() in PHP: groups need "and"/"or" and at
	 * least one item, at most maxDepth levels (the condition itself is level 1) and maxRules rules.
	 */
	function conditionViolations( rules, maxDepth, maxRules ) {
		const v = [];
		let count = 0;
		const isObject = ( x ) => x && 'object' === typeof x && ! Array.isArray( x );
		const group = ( g, depth ) => {
			if ( ! isObject( g ) || ! [ 'and', 'or' ].includes( g.op ) || ! Array.isArray( g.items ) || ! g.items.length ) {
				v.push( 'condition:invalid_group' );
				return;
			}
			if ( depth > maxDepth ) {
				v.push( 'condition:too_deep' );
				return;
			}
			g.items.forEach( ( item ) => {
				if ( isObject( item ) && null !== item.op && undefined !== item.op ) {
					group( item, depth + 1 );
					return;
				}
				count++;
				if ( ! isObject( item ) || 'string' !== typeof item.field || ! ( ENVELOPE_FIELDS.includes( item.field ) || /^data\.[a-z][a-z0-9_]*$/.test( item.field ) ) ) {
					v.push( 'condition:invalid_field' );
				}
				const op = isObject( item ) ? item.operator : undefined;
				if ( ! OPERATORS.includes( op ) ) {
					v.push( 'condition:invalid_operator' );
					return;
				}
				const value = undefined === item.value ? null : item.value;
				if ( [ 'in', 'not_in' ].includes( op ) && ( ! Array.isArray( value ) || value.length > 50 ) ) {
					v.push( 'condition:list_expected' );
				} else if ( [ 'gt', 'gte', 'lt', 'lte' ].includes( op ) && ! ( 'number' === typeof value && isFinite( value ) ) ) {
					v.push( 'condition:number_expected' );
				} else if ( [ 'eq', 'neq', 'contains' ].includes( op ) && null !== value && 'object' === typeof value ) {
					v.push( 'condition:scalar_expected' );
				}
			} );
		};
		group( rules, 1 );
		if ( count > maxRules ) {
			v.push( 'condition:too_many_rules' );
		}
		return Array.from( new Set( v ) );
	}

	/** Number of rules in a condition, groups included. */
	function countRules( g ) {
		return ( g && Array.isArray( g.items ) ? g.items : [] ).reduce( ( sum, item ) => sum + ( item && null !== item.op && undefined !== item.op ? countRules( item ) : 1 ), 0 );
	}

	class Builder {
		constructor( textarea, config ) {
			this.textarea = textarea;
			this.config = config || { actions: [], events: [], i18n: {} };
			this.t = ( key, fallback ) => ( this.config.i18n && this.config.i18n[ key ] ) || fallback;
			this.selected = null;
			this.zoom = 1;
			this.connectFrom = null;
			this.drag = null;
			this.history = [];
			this.future = [];
			// Codes from the last server check, per node; dropped with the first change, since they may no longer hold.
			this.serverViolations = this.config.violations || {};
			this.read();
			this.focusNode = this.config.focusNode || null;
			if ( this.focusNode && this.node( this.focusNode ) ) {
				this.selected = this.focusNode;
			}
			this.render();
			this.showFocus();
		}

		/** Jump from a run step (focusNode): scroll the node into view and say so (docs/UI-ENTWUERFE.md, section 5). */
		showFocus() {
			if ( ! this.focusNode ) {
				return;
			}
			if ( ! this.node( this.focusNode ) ) {
				this.announce( this.t( 'focusMissing', 'Der Knoten aus dem Lauf ist im Entwurf nicht mehr vorhanden:' ) + ' ' + this.focusNode );
				return;
			}
			const g = this.root.querySelector( '.wfc-node[data-id="' + this.focusNode + '"]' );
			if ( g && g.scrollIntoView ) {
				g.scrollIntoView( { block: 'center', inline: 'center' } );
			}
			this.announce( this.t( 'focusShown', 'Knoten aus dem Lauf markiert:' ) + ' ' + this.label( this.node( this.focusNode ) ) );
		}

		/** Product of an event type or action key and its label, plugin and contract version (from PHP). */
		origin( n ) {
			const src = this.config.sources || {};
			let product = null;
			if ( 'trigger' === n.type ) {
				product = ( src.events || {} )[ n.event ] || null;
			} else if ( 'action' === n.type ) {
				product = ( src.actions || {} )[ n.action ] || null;
			}
			if ( ! product ) {
				return null;
			}
			const info = ( src.products || {} )[ product ] || {};
			return { product, label: info.label || product, version: info.version || null, contract: info.contract || null };
		}

		/** Small heading of a node: origin and kind, e.g. "WOOCOMMERCE · AUSLÖSER". */
		kicker( n ) {
			const kinds = { trigger: this.t( 'trigger', 'Auslöser' ), action: this.t( 'action', 'Aktion' ), condition: this.t( 'condition', 'Wenn/Sonst' ), delay: this.t( 'delay', 'Warten' ), end: this.t( 'end', 'Ende' ) };
			const o = this.origin( n );
			return ( ( o ? o.label + ' · ' : '' ) + ( kinds[ n.type ] || n.type ) ).toLocaleUpperCase();
		}

		/** Accessible name of a node: label, short description and origin. */
		ariaLabel( n ) {
			const summary = this.summary( n );
			const o = this.origin( n );
			return this.label( n ) + ( summary ? ', ' + summary : '' ) + ( o ? ' (' + o.label + ')' : '' ) + ( this.focusNode === n.id ? ', ' + this.t( 'fromRun', 'aus dem Lauf' ) : '' );
		}

		/** Readable short description of a node instead of its ID (docs/UI-ENTWUERFE.md, section 2). */
		summary( n ) {
			if ( 'trigger' === n.type ) {
				if ( 'workflow.schedule.due' === n.event && n.schedule ) {
					const s = n.schedule;
					const freq = { daily: this.t( 'daily', 'täglich' ), weekly: this.t( 'weekly', 'wöchentlich' ), monthly: this.t( 'monthly', 'monatlich' ) }[ s.frequency ] || '';
					const when = 'weekly' === s.frequency ? ' ' + this.t( 'weekday' + s.weekday, WEEKDAYS[ ( s.weekday || 1 ) - 1 ] ) : ( 'monthly' === s.frequency ? ' ' + this.t( 'dayShort', 'am' ) + ' ' + s.day + '.' : '' );
					return freq + when + ' ' + ( s.time || '' );
				}
				const serial = { subject: this.t( 'serialSubject', 'je Objekt nacheinander' ), actor: this.t( 'serialActor', 'je Benutzer nacheinander' ) };
				return serial[ n.serialize ] || '';
			}
			if ( 'action' === n.type ) {
				const c = n.config || {};
				const text = [ 'subject', 'tag', 'text', 'message' ].map( ( k ) => c[ k ] ).find( ( v ) => 'string' === typeof v && '' !== v.trim() );
				return text ? text.replace( /\s+/g, ' ' ).trim() : '';
			}
			if ( 'condition' === n.type ) {
				const items = ( n.rules && Array.isArray( n.rules.items ) ) ? n.rules.items : [];
				const r = items[ 0 ];
				if ( ! r ) {
					return '';
				}
				if ( r.op ) {
					return this.t( 'ruleGroups', 'Regelgruppen' );
				}
				const op = { eq: '=', neq: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', in: '∈', not_in: '∉', contains: '∋', exists: this.t( 'opExists', 'vorhanden' ), not_exists: this.t( 'opMissing', 'fehlt' ) }[ r.operator ] || r.operator;
				const value = [ 'exists', 'not_exists' ].includes( r.operator ) ? '' : ' ' + ( Array.isArray( r.value ) ? r.value.join( ', ' ) : String( undefined === r.value ? '' : r.value ) );
				return String( r.field || '' ).replace( /^data\./, '' ) + ' ' + op + value + ( items.length > 1 ? ' (+' + ( items.length - 1 ) + ')' : '' );
			}
			return '';
		}

		read() {
			try {
				const g = JSON.parse( this.textarea.value || '{}' );
				this.graph = { nodes: Array.isArray( g.nodes ) ? g.nodes : [], edges: Array.isArray( g.edges ) ? g.edges : [] };
			} catch ( e ) {
				this.graph = { nodes: [], edges: [] };
				this.error = this.t( 'invalidJson', 'Das JSON ist ungültig; der Editor zeigt einen leeren Ablauf.' );
			}
			this.layoutMissing();
		}

		/** Gives nodes without position a simple top-down layout (WF-BUILDER-08 automatic arrangement). */
		layoutMissing( force ) {
			const depth = {};
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type );
			if ( trigger ) {
				const queue = [ [ trigger.id, 0 ] ];
				while ( queue.length ) {
					const [ id, d ] = queue.shift();
					if ( undefined !== depth[ id ] ) {
						continue;
					}
					depth[ id ] = d;
					this.graph.edges.filter( ( e ) => e.from === id ).forEach( ( e ) => queue.push( [ e.to, d + 1 ] ) );
				}
			}
			const perRow = {};
			this.graph.nodes.forEach( ( n ) => {
				if ( force || 'number' !== typeof n.x || 'number' !== typeof n.y ) {
					const d = undefined === depth[ n.id ] ? Object.keys( depth ).length + 1 : depth[ n.id ];
					perRow[ d ] = ( perRow[ d ] || 0 ) + 1;
					n.x = 40 + ( perRow[ d ] - 1 ) * ( W + 40 );
					n.y = 30 + d * ( H + 50 );
				}
			} );
		}

		commit() {
			this.serverViolations = {};
			this.history.push( this.textarea.value );
			if ( this.history.length > 50 ) {
				this.history.shift();
			}
			this.future = [];
			this.write();
		}

		write() {
			this.textarea.value = JSON.stringify( this.graph, null, 2 );
			this.textarea.dispatchEvent( new Event( 'input' ) );
			// A text field fires "change" while Tab is moving the focus on: rebuild after the move, so the focus lands
			// on the next field instead of the page start (WF-BUILDER-09).
			// Depending on the browser, "change" comes before or after the old field lost the focus (then: body).
			const doc = this.root ? this.root.ownerDocument : null;
			const active = doc ? doc.activeElement : null;
			if ( doc && ( ! active || active === doc.body || ( this.root.contains( active ) && active.matches( 'input:not([type=checkbox]):not([type=radio]), textarea' ) ) ) ) {
				clearTimeout( this.pending );
				this.pending = setTimeout( () => this.render(), 0 );
				return;
			}
			this.render();
		}

		undo() {
			if ( ! this.history.length ) {
				return;
			}
			this.future.push( this.textarea.value );
			this.textarea.value = this.history.pop();
			this.read();
			this.render();
		}

		redo() {
			if ( ! this.future.length ) {
				return;
			}
			this.history.push( this.textarea.value );
			this.textarea.value = this.future.pop();
			this.read();
			this.render();
		}

		node( id ) {
			return this.graph.nodes.find( ( n ) => n.id === id );
		}

		label( n ) {
			if ( 'trigger' === n.type ) {
				return this.t( 'trigger', 'Auslöser' ) + ': ' + ( n.event || '?' );
			}
			if ( 'action' === n.type ) {
				const a = this.config.actions.find( ( x ) => x.key === n.action );
				return a ? a.label : ( n.action || this.t( 'action', 'Aktion' ) );
			}
			if ( 'condition' === n.type ) {
				return this.t( 'condition', 'Wenn/Sonst' );
			}
			if ( 'delay' === n.type ) {
				const units = { minutes: this.t( 'minutes', 'Min.' ), hours: this.t( 'hours', 'Std.' ), days: this.t( 'days', 'Tag(e)' ), weeks: this.t( 'weeks', 'Woche(n)' ) };
				return this.t( 'delay', 'Warten' ) + ': ' + ( n.amount || '?' ) + ' ' + ( units[ n.unit ] || n.unit || '' );
			}
			return this.t( 'end', 'Ende' );
		}

		/** First position from (x, y) to the right that overlaps no node, so a new node never hides another. */
		freeSpot( x, y ) {
			const overlaps = ( px, py ) => this.graph.nodes.some( ( m ) => Math.abs( m.x - px ) < W + 20 && Math.abs( m.y - py ) < H + 20 );
			while ( overlaps( x, y ) ) {
				x += W + 40;
			}
			return [ x, y ];
		}

		newId( type ) {
			let i = 1;
			while ( this.node( type + i ) ) {
				i++;
			}
			return type + i;
		}

		/** Adds a node from the palette; at = [x, y] when it was dropped on the canvas, else below the selected node. */
		add( type, at ) {
			if ( 'trigger' === type && this.graph.nodes.some( ( n ) => 'trigger' === n.type ) ) {
				this.announce( this.t( 'oneTrigger', 'Ein Workflow hat genau einen Auslöser.' ) );
				return;
			}
			const n = { id: this.newId( type ), type };
			if ( 'trigger' === type ) {
				n.event = this.config.events[ 0 ] || '';
			} else if ( 'action' === type ) {
				n.action = ( this.config.actions[ 0 ] || {} ).key || '';
				n.config = {};
			} else if ( 'condition' === type ) {
				n.rules = { op: 'and', items: [ { field: 'data.', operator: 'eq', value: '' } ] };
				n.missing = 'false';
			} else if ( 'delay' === type ) {
				n.unit = 'hours';
				n.amount = 1;
			}
			const last = this.selected ? this.node( this.selected ) : null;
			const spot = at ? this.freeSpot( at[ 0 ], at[ 1 ] ) : this.freeSpot( last ? last.x : 40, last ? last.y + H + 50 : 30 );
			n.x = spot[ 0 ];
			n.y = spot[ 1 ];
			this.graph.nodes.push( n );
			this.selected = n.id;
			this.focusTarget = n.id;
			this.commit();
			this.announce( this.t( 'added', 'Knoten hinzugefügt:' ) + ' ' + this.label( n ) );
		}

		remove( id ) {
			const before = this.graph.edges.find( ( e ) => e.to === id );
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type && n.id !== id );
			// The focus goes to the node before the deleted one (or the trigger), never back to the page start.
			this.focusTarget = before ? before.from : ( trigger ? trigger.id : null );
			this.graph.nodes = this.graph.nodes.filter( ( n ) => n.id !== id );
			this.graph.edges = this.graph.edges.filter( ( e ) => e.from !== id && e.to !== id );
			this.selected = null;
			this.commit();
		}

		/** Connects from → to; a condition asks for the branch (Dann/Sonst, WF-BUILDER-03). */
		connect( from, to, branch ) {
			const src = this.node( from );
			if ( ! src || from === to || 'end' === src.type || 'trigger' === ( this.node( to ) || {} ).type ) {
				return;
			}
			if ( 'condition' === src.type ) {
				branch = branch || ( this.graph.edges.some( ( e ) => e.from === from && 'then' === e.branch ) ? 'else' : 'then' );
				this.graph.edges = this.graph.edges.filter( ( e ) => ! ( e.from === from && e.branch === branch ) );
				this.graph.edges.push( { from, to, branch } );
			} else {
				this.graph.edges = this.graph.edges.filter( ( e ) => e.from !== from );
				this.graph.edges.push( { from, to } );
			}
			this.commit();
		}

		/** Limits of conditions from Engine\\Conditions (default: one level of groups, 20 rules). */
		limits() {
			const c = this.config.conditions || {};
			return { depth: c.maxDepth || 2, rules: c.maxRules || 20 };
		}

		/** Nodes reachable from the trigger, for the "not connected" warning (Graph: unreachable). */
		reachable() {
			const seen = new Set();
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type );
			const stack = trigger ? [ trigger.id ] : [];
			while ( stack.length ) {
				const id = stack.pop();
				if ( ! seen.has( id ) ) {
					seen.add( id );
					this.graph.edges.filter( ( e ) => e.from === id ).forEach( ( e ) => stack.push( e.to ) );
				}
			}
			return seen;
		}

		/**
		 * What keeps a node from being published, as {code, text} (docs/UI-ENTWUERFE.md, section 3): missing provider,
		 * open outputs, invalid rules or settings (the same rules as Graph and Conditions in PHP), and codes from the last
		 * server check the builder does not test itself. The server decides; this only points to the node.
		 */
		problems( n, reach ) {
			const out = [];
			const add = ( code, text ) => out.push( { code, text } );
			const outs = this.graph.edges.filter( ( e ) => e.from === n.id );
			if ( 'trigger' === n.type ) {
				if ( this.config.events.length && ! this.config.events.includes( n.event ) ) {
					add( 'provider', this.t( 'problemProvider', 'Provider nicht verfügbar: kein angemeldeter Adapter liefert dieses Ereignis.' ) );
				}
				if ( 'workflow.schedule.due' === n.event && ! upcoming( n.schedule, Date.now(), this.config.timezone || 'UTC', 1 ).length ) {
					add( 'schedule', this.t( 'nextNone', 'Keine Termine: Der Zeitplan ist unvollständig.' ) );
				}
			} else if ( 'action' === n.type && ! this.config.actions.some( ( x ) => x.key === n.action ) ) {
				add( 'action', this.t( 'problemAction', 'Aktion nicht verfügbar: Der Provider fehlt oder ist nicht aktiv.' ) );
			} else if ( 'condition' === n.type ) {
				const texts = {
					'condition:invalid_group': this.t( 'problemGroup', 'Eine Gruppe ist leer oder hat keine Verknüpfung.' ),
					'condition:too_deep': this.t( 'problemDeep', 'Gruppen sind zu tief verschachtelt: Eine Gruppe darf keine weitere Gruppe enthalten.' ),
					'condition:too_many_rules': this.t( 'problemRules', 'Zu viele Regeln (höchstens %d).' ).replace( '%d', this.limits().rules ),
					'condition:invalid_field': this.t( 'problemField', 'Eine Regel hat kein gültiges Feld.' ),
					'condition:invalid_operator': this.t( 'problemOperator', 'Eine Regel hat keinen gültigen Operator.' ),
					'condition:list_expected': this.t( 'problemList', 'Für „in Liste“ Werte mit Komma getrennt angeben.' ),
					'condition:number_expected': this.t( 'problemNumber', 'Für >, ≥, < und ≤ eine Zahl angeben.' ),
					'condition:scalar_expected': this.t( 'problemScalar', 'Eine Regel braucht einen einzelnen Wert.' ),
				};
				const limits = this.limits();
				conditionViolations( n.rules, limits.depth, limits.rules ).forEach( ( code ) => add( code, texts[ code ] || code ) );
				if ( ! outs.some( ( e ) => 'then' === e.branch ) ) {
					add( 'then', this.t( 'thenOpen', 'Der Dann-Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.' ) );
				}
				if ( ! outs.some( ( e ) => 'else' === e.branch ) ) {
					add( 'else', this.t( 'elseOpen', 'Der Sonst-Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.' ) );
				}
			} else if ( 'delay' === n.type && ( ! [ 'minutes', 'hours', 'days', 'weeks' ].includes( n.unit ) || ! Number.isInteger( n.amount ) || n.amount < 1 || n.amount > 1000 ) ) {
				add( 'delay', this.t( 'problemDelay', 'Dauer von 1 bis 1000 und eine Einheit angeben.' ) );
			}
			if ( [ 'trigger', 'action', 'delay' ].includes( n.type ) && 1 !== outs.length ) {
				add( 'out', outs.length ? this.t( 'problemOutMany', 'Mehr als ein Ausgang.' ) : this.t( 'problemOut', 'Der Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.' ) );
			}
			if ( 'end' === n.type && outs.length ) {
				add( 'endOut', this.t( 'problemEnd', 'Ein Ende hat keinen Ausgang.' ) );
			}
			if ( 'trigger' !== n.type && reach && ! reach.has( n.id ) ) {
				add( 'unreachable', this.t( 'problemUnreachable', 'Nicht mit dem Auslöser verbunden.' ) );
			}
			( this.serverViolations[ n.id ] || [] ).filter( ( code ) => ! COVERED.test( code ) ).forEach( ( code ) => add( 'server', this.t( 'problemServer', 'Beim letzten Prüfen gemeldet:' ) + ' ' + code ) );
			return out;
		}

		/** Spoken and written form of a node's problems, e.g. "Warnung: Der Ausgang ist nicht verbunden." */
		problemText( list ) {
			return list.length ? this.t( 'warning', 'Warnung' ) + ': ' + list.map( ( p ) => p.text ).join( ' ' ) : '';
		}

		announce( text ) {
			if ( this.live ) {
				this.live.textContent = text;
			}
		}

		render() {
			// Rebuilding the DOM drops the focus; remember which control had it (WF-BUILDER-09, WCAG 2.4.3).
			const restore = this.root ? this.focusables().indexOf( this.root.ownerDocument.activeElement ) : -1;
			const linear = this.root ? this.root.querySelector( '.wfc-linear-wrap' ) : null;
			if ( linear ) {
				this.linearOpen = linear.open; // read here: the "toggle" event comes too late for this rebuild
			}
			if ( ! this.root ) {
				this.root = el( 'div', { class: 'wfc-builder' } );
				this.textarea.parentNode.insertBefore( this.root, this.textarea );
				// Outside the part that is rebuilt, so screen readers keep the live region and hear every message.
				this.live = el( 'div', { class: 'screen-reader-text', 'aria-live': 'polite' } );
				this.root.parentNode.insertBefore( this.live, this.root );
			}
			this.root.innerHTML = '';
			const reach = this.reachable();
			this.issues = {};
			this.graph.nodes.forEach( ( n ) => {
				this.issues[ n.id ] = this.problems( n, reach );
			} );
			if ( this.error ) {
				this.root.appendChild( el( 'div', { class: 'notice notice-error inline' }, [ el( 'p', { text: this.error } ) ] ) );
			}
			this.root.appendChild( this.toolbar() );
			const body = el( 'div', { class: 'wfc-builder-body' } );
			body.appendChild( this.canvas() );
			body.appendChild( this.inspector() );
			this.root.appendChild( body );
			this.root.appendChild( this.list() );
			this.fitNodeTexts();
			this.restoreFocus( restore );
		}

		/**
		 * Shortens the texts of the nodes that are wider than the node with the real fonts (with "…"; the full text is in
		 * the tooltip and the accessible name). The character limits in canvas() are only a first cut: with the wp-admin
		 * fonts "WORKFLOW CAPTAIN · AUSLÖSER" ran over the right edge (E2E third run). Needs layout, so only when shown.
		 */
		fitNodeTexts() {
			const max = W - 14; // text starts at x 12; a 2 px margin to the right edge
			this.root.querySelectorAll( '.wfc-node-kicker, .wfc-node-title, .wfc-node-sub' ).forEach( ( t ) => {
				if ( 'function' !== typeof t.getComputedTextLength || ! t.getClientRects().length ) {
					return;
				}
				let text = t.textContent;
				while ( text.length > 1 && t.getComputedTextLength() > max ) {
					text = text.replace( /…$/, '' ).slice( 0, -1 ).trimEnd();
					t.textContent = text + '…';
				}
			} );
		}

		focusables() {
			return Array.from( this.root.querySelectorAll( 'button, input, select, textarea, summary, [tabindex="0"]' ) );
		}

		/**
		 * After a rebuild: a node chosen by the last action gets the focus (on the canvas, or the inspector heading when
		 * the canvas is hidden on small screens), otherwise the control at the same position as before.
		 */
		restoreFocus( index ) {
			const target = this.focusTarget;
			const selector = this.focusSelector;
			this.focusTarget = null;
			this.focusSelector = null;
			const chosen = selector ? this.root.querySelector( selector ) : null;
			if ( chosen ) {
				chosen.focus();
				return;
			}
			if ( target ) {
				const g = this.root.querySelector( `.wfc-node[data-id="${ target }"]` );
				if ( g && g.getClientRects().length ) {
					g.focus();
					return;
				}
				const heading = this.selected === target ? this.root.querySelector( '.wfc-inspector h3' ) : null;
				const item = this.root.querySelector( `.wfc-linear button[data-id="${ target }"]` );
				const fallback = heading || ( item && item.getClientRects().length ? item : this.root.querySelector( '.wfc-linear-wrap summary' ) );
				if ( fallback ) {
					fallback.focus();
				}
				return;
			}
			const same = index >= 0 ? this.focusables()[ index ] : null;
			if ( same ) {
				same.focus();
			}
		}

		toolbar() {
			const bar = el( 'div', { class: 'wfc-toolbar', role: 'toolbar', 'aria-label': this.t( 'palette', 'Bausteine' ) } );
			// Click or Enter adds below the selected node; dragging onto the canvas places it there (workflow-builder.svg).
			[ [ 'trigger', 'Auslöser' ], [ 'condition', 'Wenn/Sonst' ], [ 'action', 'Aktion' ], [ 'delay', 'Warten' ], [ 'end', 'Ende' ] ].forEach( ( [ type, label ] ) => {
				bar.appendChild( el( 'button', {
					type: 'button',
					class: 'button wfc-add',
					'data-type': type,
					draggable: 'true',
					'aria-describedby': 'wfc-palette-hint',
					text: '+ ' + this.t( type, label ),
					onclick: () => this.add( type ),
					ondragstart: ( ev ) => {
						ev.dataTransfer.setData( DRAG_TYPE, type );
						ev.dataTransfer.setData( 'text/plain', type );
						ev.dataTransfer.effectAllowed = 'copy';
					},
				} ) );
			} );
			bar.appendChild( el( 'span', { class: 'wfc-sep' } ) );
			bar.appendChild( el( 'button', { type: 'button', class: 'button', text: this.t( 'undo', 'Rückgängig' ), onclick: () => this.undo(), disabled: ! this.history.length } ) );
			bar.appendChild( el( 'button', { type: 'button', class: 'button', text: this.t( 'redo', 'Wiederholen' ), onclick: () => this.redo(), disabled: ! this.future.length } ) );
			bar.appendChild( el( 'button', { type: 'button', class: 'button', text: this.t( 'arrange', 'Anordnen' ), onclick: () => { this.layoutMissing( true ); this.commit(); } } ) );
			bar.appendChild( el( 'span', { class: 'wfc-sep' } ) );
			const zoomTo = ( z ) => {
				this.zoom = Math.min( 2, Math.max( 0.4, Math.round( z * 10 ) / 10 ) );
				this.render();
			};
			bar.appendChild( el( 'button', { type: 'button', class: 'button wfc-zoom-out', 'aria-label': this.t( 'zoomOut', 'Verkleinern' ), text: '−', onclick: () => zoomTo( this.zoom - 0.2 ) } ) );
			bar.appendChild( el( 'button', { type: 'button', class: 'button wfc-zoom-reset', text: Math.round( this.zoom * 100 ) + ' %', onclick: () => zoomTo( 1 ) } ) );
			bar.appendChild( el( 'button', { type: 'button', class: 'button wfc-zoom-in', 'aria-label': this.t( 'zoomIn', 'Vergrößern' ), text: '+', onclick: () => zoomTo( this.zoom + 0.2 ) } ) );
			bar.appendChild( el( 'p', { id: 'wfc-palette-hint', class: 'description wfc-palette-hint', text: this.t( 'paletteHint', 'Bausteine anklicken oder auf den Ablauf ziehen. Tastatur: Enter fügt den Baustein unter dem gewählten Knoten ein, die Pfeiltasten verschieben ihn.' ) } ) );
			return bar;
		}

		canvas() {
			const maxX = Math.max( 600, ...this.graph.nodes.map( ( n ) => n.x + W + 40 ) );
			const maxY = Math.max( 300, ...this.graph.nodes.map( ( n ) => n.y + H + 40 ) );
			const s = svg( 'svg', { class: 'wfc-canvas', width: Math.round( maxX * this.zoom ), height: Math.round( maxY * this.zoom ), viewBox: `0 0 ${ maxX } ${ maxY }`, role: 'group', 'aria-label': this.t( 'canvas', 'Ablauf' ) } );
			const defs = svg( 'defs' );
			const marker = svg( 'marker', { id: 'wfc-arrow', viewBox: '0 0 10 10', refX: '10', refY: '5', markerWidth: '8', markerHeight: '8', orient: 'auto-start-reverse' } );
			marker.appendChild( svg( 'path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'wfc-arrowhead' } ) );
			defs.appendChild( marker );
			s.appendChild( defs );

			this.graph.edges.forEach( ( e ) => {
				const a = this.node( e.from );
				const b = this.node( e.to );
				if ( ! a || ! b ) {
					return;
				}
				const ax = a.x + ( 'else' === e.branch ? W * 0.75 : ( 'then' === e.branch ? W * 0.25 : W / 2 ) );
				const ay = a.y + H;
				const bx = b.x + W / 2;
				const by = b.y;
				const mid = ( ay + by ) / 2;
				const path = svg( 'path', { d: `M ${ ax } ${ ay } C ${ ax } ${ mid }, ${ bx } ${ mid }, ${ bx } ${ by }`, class: 'wfc-edge wfc-edge-' + ( e.branch || 'plain' ), 'marker-end': 'url(#wfc-arrow)' } );
				s.appendChild( path );
				if ( e.branch ) {
					const t = svg( 'text', { x: 'else' === e.branch ? ax + 8 : ax - 8, y: ay + 14, class: 'wfc-edge-label', 'text-anchor': 'else' === e.branch ? 'start' : 'end' } );
					t.textContent = 'then' === e.branch ? this.t( 'then', 'Dann' ) : this.t( 'else', 'Sonst' );
					s.appendChild( t );
				}
			} );

			this.graph.nodes.forEach( ( n ) => {
				const issues = this.issues[ n.id ] || [];
				const unavailable = issues.some( ( p ) => 'provider' === p.code || 'action' === p.code );
				const state = ( ( this.config.path || [] ).includes( n.id ) ? ' is-path' : '' ) + ( this.config.errorNode === n.id ? ' is-error' : '' ) + ( unavailable ? ' is-unavailable' : '' ) + ( issues.length ? ' has-problem' : '' ) + ( this.focusNode === n.id ? ' is-focus' : '' );
				const g = svg( 'g', { class: 'wfc-node wfc-node-' + n.type + state + ( this.selected === n.id ? ' is-selected' : '' ), transform: `translate(${ n.x },${ n.y })`, tabindex: '0', role: 'button', 'aria-label': this.ariaLabel( n ) + ( issues.length ? '. ' + this.problemText( issues ) : '' ), 'data-id': n.id } );
				g.appendChild( svg( 'rect', { width: W, height: H, rx: 'end' === n.type || 'trigger' === n.type ? 28 : 6 } ) );
				if ( issues.length ) {
					// Warning sign as a badge on the top right corner, clear of the texts: a shape and "!" (not only a colour);
					// the text is in the name and the tooltip.
					const mark = svg( 'g', { class: 'wfc-node-warn-mark', transform: `translate(${ W - 14 },-9)`, 'aria-hidden': 'true' } );
					mark.appendChild( svg( 'path', { d: 'M 11 1 L 21 19 L 1 19 Z', class: 'wfc-node-warn-sign' } ) );
					const warn = svg( 'text', { x: 11, y: 17, class: 'wfc-node-warn', 'text-anchor': 'middle' } );
					warn.textContent = '!';
					mark.appendChild( warn );
					g.appendChild( mark );
				}
				const cut = ( text, max ) => ( text.length > max ? text.slice( 0, max - 1 ) + '…' : text );
				const kicker = svg( 'text', { x: 12, y: 17, class: 'wfc-node-kicker' } );
				kicker.textContent = cut( this.kicker( n ), 28 );
				g.appendChild( kicker );
				const title = svg( 'text', { x: 12, y: 36, class: 'wfc-node-title' } );
				const full = 'trigger' === n.type ? ( n.event || '?' ) : this.label( n );
				title.textContent = cut( full, 22 );
				g.appendChild( title );
				const summary = this.summary( n );
				const o = this.origin( n );
				const tip = svg( 'title' );
				tip.textContent = [ this.kicker( n ), this.label( n ), summary, o && o.contract ? this.t( 'contract', 'Vertrag' ) + ' ' + o.contract : '', 'ID: ' + n.id ].concat( issues.map( ( p ) => '⚠ ' + p.text ) ).filter( ( x ) => x ).join( '\n' );
				g.appendChild( tip );
				if ( summary ) {
					const sub = svg( 'text', { x: 12, y: 54, class: 'wfc-node-sub' } );
					sub.textContent = cut( summary, 28 );
					g.appendChild( sub );
				}
				g.addEventListener( 'mousedown', ( ev ) => this.startDrag( ev, n ) );
				g.addEventListener( 'click', () => this.clickNode( n.id ) );
				g.addEventListener( 'keydown', ( ev ) => this.keyNode( ev, n ) );
				s.appendChild( g );
			} );
			s.addEventListener( 'mousemove', ( ev ) => this.moveDrag( ev ) );
			s.addEventListener( 'mouseup', () => this.endDrag() );
			s.addEventListener( 'mouseleave', () => this.endDrag() );
			const wrap = el( 'div', { class: 'wfc-canvas-wrap' } );
			wrap.appendChild( s );
			// Drop target for palette buttons; the point under the pointer becomes the centre of the new node.
			const fromPalette = ( ev ) => ev.dataTransfer && Array.from( ev.dataTransfer.types || [] ).includes( DRAG_TYPE );
			wrap.addEventListener( 'dragover', ( ev ) => {
				if ( fromPalette( ev ) ) {
					ev.preventDefault();
					ev.dataTransfer.dropEffect = 'copy';
					wrap.classList.add( 'is-drop-target' );
				}
			} );
			wrap.addEventListener( 'dragleave', () => wrap.classList.remove( 'is-drop-target' ) );
			wrap.addEventListener( 'drop', ( ev ) => {
				wrap.classList.remove( 'is-drop-target' );
				const type = fromPalette( ev ) ? ev.dataTransfer.getData( DRAG_TYPE ) : '';
				if ( ! [ 'trigger', 'condition', 'action', 'delay', 'end' ].includes( type ) ) {
					return;
				}
				ev.preventDefault();
				const box = s.getBoundingClientRect();
				const snap = ( v ) => Math.max( 0, Math.round( v / 10 ) * 10 );
				this.add( type, [ snap( ( ev.clientX - box.left ) / this.zoom - W / 2 ), snap( ( ev.clientY - box.top ) / this.zoom - H / 2 ) ] );
			} );
			return wrap;
		}

		clickNode( id ) {
			if ( this.connectFrom && this.connectFrom !== id ) {
				const from = this.connectFrom;
				const branch = this.connectBranch;
				this.connectFrom = null;
				this.connectBranch = null;
				this.connect( from, id, branch );
				this.announce( this.t( 'connected', 'Verbunden.' ) );
				return;
			}
			this.selected = id;
			this.focusTarget = id;
			this.render();
		}

		/** Keyboard: arrows move (Shift = 50 px), Delete removes, C starts a connection, Enter selects (WF-BUILDER-01, -09). */
		keyNode( ev, n ) {
			const step = ev.shiftKey ? 50 : 10;
			const moves = { ArrowLeft: [ -step, 0 ], ArrowRight: [ step, 0 ], ArrowUp: [ 0, -step ], ArrowDown: [ 0, step ] };
			if ( moves[ ev.key ] ) {
				ev.preventDefault();
				n.x = Math.max( 0, n.x + moves[ ev.key ][ 0 ] );
				n.y = Math.max( 0, n.y + moves[ ev.key ][ 1 ] );
				this.commit();
				const g = this.root.querySelector( `.wfc-node[data-id="${ n.id }"]` );
				if ( g ) {
					g.focus();
				}
			} else if ( 'Delete' === ev.key || 'Backspace' === ev.key ) {
				ev.preventDefault();
				this.remove( n.id );
			} else if ( 'Enter' === ev.key || ' ' === ev.key ) {
				ev.preventDefault();
				this.clickNode( n.id );
			} else if ( 'c' === ev.key.toLowerCase() ) {
				this.connectFrom = n.id;
				this.announce( this.t( 'connectHint', 'Ziel wählen: einen anderen Knoten auswählen.' ) );
			}
		}

		startDrag( ev, n ) {
			this.drag = { id: n.id, dx: ev.clientX / this.zoom - n.x, dy: ev.clientY / this.zoom - n.y, moved: false };
		}

		moveDrag( ev ) {
			if ( ! this.drag ) {
				return;
			}
			const n = this.node( this.drag.id );
			n.x = Math.max( 0, Math.round( ( ev.clientX / this.zoom - this.drag.dx ) / 10 ) * 10 );
			n.y = Math.max( 0, Math.round( ( ev.clientY / this.zoom - this.drag.dy ) / 10 ) * 10 );
			this.drag.moved = true;
			const g = this.root.querySelector( `.wfc-node[data-id="${ n.id }"]` );
			if ( g ) {
				g.setAttribute( 'transform', `translate(${ n.x },${ n.y })` );
			}
		}

		endDrag() {
			if ( this.drag && this.drag.moved ) {
				this.drag = null;
				this.commit();
				return;
			}
			this.drag = null;
		}

		/** Data fields the trigger's event type allows (catalog), for conditions and placeholders. */
		dataFields() {
			const types = this.dataTypes();
			return Object.keys( types );
		}

		/** Field => data type of the trigger's event (catalog); accepts a plain list as well. */
		dataTypes() {
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type );
			const raw = ( this.config.fields && trigger && this.config.fields[ trigger.event ] ) || {};
			if ( Array.isArray( raw ) ) {
				return Object.fromEntries( raw.map( ( f ) => [ f, '' ] ) );
			}
			return raw;
		}

		/** Placeholder picker next to a text field (WF-BUILDER-04): inserts {{…}} at the cursor. */
		tokenPicker( input, onChange ) {
			const tokens = [ 'type', 'occurred_at', 'subject.type', 'subject.id', 'source.product' ].concat( this.dataFields().map( ( f ) => 'data.' + f ) );
			return el( 'select', {
				class: 'wfc-token',
				'aria-label': this.t( 'insertToken', 'Platzhalter einfügen' ),
				onchange: ( ev ) => {
					if ( ! ev.target.value ) {
						return;
					}
					const token = '{{' + ev.target.value + '}}';
					const at = 'number' === typeof input.selectionStart ? input.selectionStart : input.value.length;
					input.value = input.value.slice( 0, at ) + token + input.value.slice( at );
					ev.target.value = '';
					onChange( input.value );
				},
			}, [ el( 'option', { value: '', text: this.t( 'insertToken', 'Platzhalter einfügen …' ) } ) ].concat( tokens.map( ( tk ) => el( 'option', { value: tk, text: '{{' + tk + '}}' } ) ) ) );
		}

		/**
		 * Field of a rule as a list (docs/UI-ENTWUERFE.md, section 3): envelope fields and the catalog fields of the
		 * trigger event with their data type. "Anderes Feld …" switches to a text field for a path outside the catalog.
		 */
		fieldPicker( key, r, fields, envelopeFields, types, save ) {
			const current = r.field || '';
			if ( this.customFields[ key ] ) {
				return el( 'input', { type: 'text', class: 'wfc-rule-field', value: current, 'aria-label': this.t( 'fieldCustom', 'Feld (eigener Pfad, z. B. data.feld)' ), onchange: ( ev ) => { r.field = ev.target.value.trim(); delete this.customFields[ key ]; save(); } } );
			}
			const option = ( f ) => el( 'option', { value: f, text: f.startsWith( 'data.' ) && types[ f.slice( 5 ) ] ? f.slice( 5 ) + ' · ' + types[ f.slice( 5 ) ] : f, selected: f === current } );
			const options = [];
			if ( ! current ) {
				options.push( el( 'option', { value: '', text: '—', selected: true } ) );
			} else if ( ! fields.includes( current ) ) {
				options.push( el( 'option', { value: current, text: current + ' ' + this.t( 'notInCatalog', '(nicht im Katalog)' ), selected: true } ) );
			}
			options.push( el( 'optgroup', { label: this.t( 'fieldsEnvelope', 'Ereignis' ) }, envelopeFields.map( option ) ) );
			const data = fields.filter( ( f ) => f.startsWith( 'data.' ) );
			if ( data.length ) {
				options.push( el( 'optgroup', { label: this.t( 'fieldsData', 'Daten des Ereignisses' ) }, data.map( option ) ) );
			}
			options.push( el( 'option', { value: '__custom', text: this.t( 'fieldOther', 'Anderes Feld …' ) } ) );
			return el( 'select', {
				class: 'wfc-rule-field',
				'aria-label': this.t( 'field', 'Feld' ),
				onchange: ( ev ) => {
					if ( '__custom' === ev.target.value ) {
						this.customFields[ key ] = true;
						this.render();
						const input = this.root.querySelector( `.wfc-rule[data-key="${ key }"] .wfc-rule-field` );
						if ( input ) {
							input.focus();
						}
						return;
					}
					r.field = ev.target.value;
					save();
				},
			}, options );
		}

		/** Value of a field path in the sample event of the test run (#wfc-sample), as an example; undefined if none. */
		sampleValue( path ) {
			const box = document.getElementById( 'wfc-sample' );
			if ( ! box || ! path ) {
				return undefined;
			}
			try {
				return String( path ).split( '.' ).reduce( ( o, k ) => ( o && 'object' === typeof o ? o[ k ] : undefined ), JSON.parse( box.value || '{}' ) );
			} catch ( e ) {
				return undefined;
			}
		}

		/**
		 * Condition as a form (WF-BUILDER-04): rules and, with "+ Gruppe", groups of rules with their own AND/OR
		 * (docs/UI-ENTWUERFE.md, section 3). Groups go as deep as Engine\\Conditions allows (MAX_DEPTH 2: one level below
		 * the condition, so "+ Gruppe" is offered at the top only); "+ Regel" stops at MAX_RULES. Anything deeper (from
		 * the JSON field or an import) falls back to JSON, with the warning "condition:too_deep" on the node.
		 */
		conditionForm( n ) {
			const limits = this.limits();
			const rules = n.rules || { op: 'and', items: [] };
			const fits = ( g, depth ) => g && 'object' === typeof g && Array.isArray( g.items ) && g.items.every( ( it ) => ! ( it && null !== it.op && undefined !== it.op ) || ( depth < limits.depth && fits( it, depth + 1 ) ) );
			if ( ! fits( rules, 1 ) ) {
				return null;
			}
			const box = el( 'div', { class: 'wfc-rules' } );
			const envelopeFields = ENVELOPE_FIELDS;
			const fields = envelopeFields.concat( this.dataFields().map( ( f ) => 'data.' + f ) );
			const types = this.dataTypes();
			const save = () => {
				n.rules = rules;
				this.commit();
			};
			const full = countRules( rules ) >= limits.rules;
			const newRule = () => ( { field: fields[ 0 ] || 'type', operator: 'eq', value: '' } );
			const keyOf = ( path ) => n.id + ':' + path;
			this.customFields = this.customFields || {};
			const combine = ( group, label, cls ) => labelled( label, el( 'select', { class: cls, onchange: ( ev ) => { group.op = ev.target.value; save(); } }, [ [ 'and', this.t( 'combineAnd', 'alle Regeln (UND)' ) ], [ 'or', this.t( 'combineOr', 'eine Regel (ODER)' ) ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: group.op === v } ) ) ) );
			box.appendChild( combine( rules, this.t( 'combine', 'Verknüpfung' ), 'wfc-op' ) );
			const ops = [ [ 'eq', '=' ], [ 'neq', '≠' ], [ 'gt', '>' ], [ 'gte', '≥' ], [ 'lt', '<' ], [ 'lte', '≤' ], [ 'in', 'in Liste' ], [ 'not_in', 'nicht in Liste' ], [ 'contains', 'enthält' ], [ 'exists', 'vorhanden' ], [ 'not_exists', 'fehlt' ] ];
			const parse = ( op, raw ) => {
				if ( [ 'in', 'not_in' ].includes( op ) ) {
					return raw.split( ',' ).map( ( x ) => x.trim() ).filter( ( x ) => x !== '' ).map( ( x ) => ( /^-?\d+(\.\d+)?$/.test( x ) ? Number( x ) : x ) );
				}
				if ( [ 'gt', 'gte', 'lt', 'lte' ].includes( op ) ) {
					return Number( raw );
				}
				if ( 'true' === raw || 'false' === raw ) {
					return 'true' === raw;
				}
				return /^-?\d+(\.\d+)?$/.test( raw ) ? Number( raw ) : raw;
			};
			const show = ( v ) => ( Array.isArray( v ) ? v.join( ', ' ) : ( undefined === v || null === v ? '' : String( v ) ) );
			// One rule; removing the last rule of a group removes the group (an empty group is invalid).
			const ruleRow = ( r, path, group, i, parent, gi ) => {
				const row = el( 'div', { class: 'wfc-rule', 'data-key': keyOf( path ) } );
				row.appendChild( this.fieldPicker( keyOf( path ), r, fields, envelopeFields, types, save ) );
				const dtype = ( r.field || '' ).startsWith( 'data.' ) ? types[ r.field.slice( 5 ) ] : '';
				if ( dtype ) {
					row.appendChild( el( 'span', { class: 'wfc-rule-type', text: dtype } ) );
				}
				const example = this.sampleValue( r.field );
				if ( undefined !== example ) {
					row.appendChild( el( 'span', { class: 'wfc-rule-example description', text: this.t( 'example', 'Beispiel:' ) + ' ' + ( 'object' === typeof example ? JSON.stringify( example ) : String( example ) ) } ) );
				}
				row.appendChild( el( 'select', { class: 'wfc-rule-op', 'aria-label': this.t( 'operator', 'Operator' ), onchange: ( ev ) => { r.operator = ev.target.value; r.value = parse( r.operator, show( r.value ) ); save(); } }, ops.map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: r.operator === v } ) ) ) );
				if ( ! [ 'exists', 'not_exists' ].includes( r.operator ) ) {
					row.appendChild( el( 'input', { type: 'text', class: 'wfc-rule-value', value: show( r.value ), 'aria-label': this.t( 'value', 'Wert' ), onchange: ( ev ) => { r.value = parse( r.operator, ev.target.value ); save(); } } ) );
				}
				row.appendChild( el( 'button', {
					type: 'button',
					class: 'button-link wfc-rule-remove',
					text: '×',
					'aria-label': this.t( 'removeRule', 'Regel entfernen' ),
					onclick: () => {
						group.items.splice( i, 1 );
						if ( parent && ! group.items.length ) {
							parent.items.splice( gi, 1 );
							this.focusSelector = '.wfc-group-add';
						}
						save();
					},
				} ) );
				return row;
			};
			let groupNo = 0;
			rules.items.forEach( ( item, i ) => {
				if ( ! ( item && null !== item.op && undefined !== item.op ) ) {
					box.appendChild( ruleRow( item, String( i ), rules, i, null, null ) );
					return;
				}
				groupNo++;
				const set = el( 'fieldset', { class: 'wfc-rule-group', 'data-key': keyOf( String( i ) ) }, [ el( 'legend', { text: this.t( 'group', 'Gruppe' ) + ' ' + groupNo } ) ] );
				set.appendChild( combine( item, this.t( 'combineGroup', 'Verknüpfung in der Gruppe' ), 'wfc-group-op' ) );
				item.items.forEach( ( r, j ) => set.appendChild( ruleRow( r, i + '.' + j, item, j, rules, i ) ) );
				set.appendChild( el( 'p', {}, [
					el( 'button', { type: 'button', class: 'button wfc-group-rule-add', text: '+ ' + this.t( 'addRuleGroup', 'Regel in Gruppe' ) + ' ' + groupNo, disabled: full, onclick: () => { item.items.push( newRule() ); this.focusSelector = `.wfc-rule[data-key="${ keyOf( i + '.' + ( item.items.length - 1 ) ) }"] .wfc-rule-field`; save(); } } ),
					el( 'button', { type: 'button', class: 'button-link wfc-group-remove', text: this.t( 'removeGroup', 'Gruppe entfernen' ) + ' ' + groupNo, onclick: () => { rules.items.splice( i, 1 ); this.focusSelector = '.wfc-group-add'; save(); } } ),
				] ) );
				box.appendChild( set );
			} );
			const buttons = el( 'p', { class: 'wfc-rule-buttons' } );
			buttons.appendChild( el( 'button', { type: 'button', class: 'button wfc-rule-add', text: '+ ' + this.t( 'addRule', 'Regel' ), disabled: full, onclick: () => { rules.items.push( newRule() ); this.focusSelector = `.wfc-rule[data-key="${ keyOf( rules.items.length - 1 ) }"] .wfc-rule-field`; save(); } } ) );
			if ( limits.depth > 1 ) {
				buttons.appendChild( document.createTextNode( ' ' ) );
				buttons.appendChild( el( 'button', { type: 'button', class: 'button wfc-group-add', text: '+ ' + this.t( 'addGroup', 'Gruppe' ), disabled: full, 'aria-describedby': 'wfc-group-hint', onclick: () => { rules.items.push( { op: 'or', items: [ newRule() ] } ); this.focusSelector = `.wfc-rule[data-key="${ keyOf( ( rules.items.length - 1 ) + '.0' ) }"] .wfc-rule-field`; save(); } } ) );
			}
			box.appendChild( buttons );
			const hint = full ? this.t( 'rulesFull', 'Höchstens %d Regeln je Bedingung, Gruppen eingeschlossen.' ).replace( '%d', limits.rules ) : this.t( 'groupHint', 'Eine Gruppe fasst Regeln mit eigener Verknüpfung zusammen, z. B. „A und (B oder C)“. Eine Gruppe kann keine weitere Gruppe enthalten.' );
			box.appendChild( el( 'p', { id: 'wfc-group-hint', class: 'description', text: hint } ) );
			return box;
		}

		/** Mails the core plugin already sends for the trigger event (R-9.4), so operators avoid duplicates. */
		coreMailHint( action ) {
			const trigger = this.graph.nodes.find( ( x ) => 'trigger' === x.type );
			const mails = ( trigger && this.config.coreMails && this.config.coreMails[ trigger.event ] ) || [];
			// Possible duplicate: the same circle of recipients already gets a core mail (operator vs. customers).
			const toOperator = ( m ) => 'Betreiber' === m.to;
			const duplicate = mails.some( ( m ) => ( 'notify.admin' === action ) === toOperator( m ) );
			const box = el( 'div', { class: 'wfc-core-mails notice inline ' + ( duplicate ? 'notice-warning wfc-core-mails-duplicate' : 'notice-info' ) } );
			if ( ! trigger || ! trigger.event ) {
				return box;
			}
			if ( ! mails.length ) {
				box.appendChild( el( 'p', { text: this.t( 'coreMailsNone', 'Das Kern-Plugin verschickt zu diesem Ereignis keine eigene Mail.' ) } ) );
				return box;
			}
			box.appendChild( el( 'p', { text: this.t( 'coreMailsIntro', 'Das Kern-Plugin verschickt zu diesem Ereignis bereits:' ) } ) );
			box.appendChild( el( 'ul', {}, mails.map( ( m ) => el( 'li', { text: m.to + ': ' + m.mail + ' (' + m.off + ( m.note ? '; ' + m.note : '' ) + ')' } ) ) ) );
			if ( duplicate ) {
				box.appendChild( el( 'p', { role: 'status', text: this.t( 'coreMailsDuplicate', 'Möglicherweise doppelt: Dieser Empfängerkreis bekommt zum selben Anlass schon eine Mail des Kern-Plugins.' ) } ) );
			}
			box.appendChild( el( 'p', { text: this.t( 'coreMailsAdvice', 'Senden Sie dieselbe Information nicht ein zweites Mal. Workflow Captain schaltet keine Mail der Kerne ab.' ) } ) );
			return box;
		}

		/** Recurring schedule of a trigger "workflow.schedule.due" (WF-CORE-04), in the time zone of the site. */
		scheduleForm( n ) {
			n.schedule = n.schedule || { frequency: 'daily', time: '08:00' };
			const s = n.schedule;
			const box = el( 'fieldset', { class: 'wfc-schedule' }, [ el( 'legend', { text: this.t( 'schedule', 'Zeitplan (Zeitzone der Website)' ) + ( this.config.timezone ? ': ' + this.config.timezone : '' ) } ) ] );
			const update = ( key, value ) => {
				s[ key ] = value;
				if ( 'weekly' !== s.frequency ) {
					delete s.weekday;
				} else if ( ! s.weekday ) {
					s.weekday = 1;
				}
				if ( 'monthly' !== s.frequency ) {
					delete s.day;
				} else if ( ! s.day ) {
					s.day = 1;
				}
				this.commit();
				const next = upcoming( s, Date.now(), this.config.timezone || 'UTC', 1 );
				if ( next.length ) {
					this.announce( this.t( 'nextDue', 'Nächster Termin:' ) + ' ' + this.formatTime( next[ 0 ] ) );
				}
			};
			const label = labelled;
			box.appendChild( label( this.t( 'frequency', 'Wiederholung' ), el( 'select', { class: 'wfc-schedule-frequency', onchange: ( ev ) => update( 'frequency', ev.target.value ) }, [ [ 'daily', 'täglich' ], [ 'weekly', 'wöchentlich' ], [ 'monthly', 'monatlich' ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: s.frequency === v } ) ) ) ) );
			box.appendChild( label( this.t( 'time', 'Uhrzeit' ), el( 'input', { type: 'time', class: 'wfc-schedule-time', value: s.time || '08:00', onchange: ( ev ) => update( 'time', ev.target.value ) } ) ) );
			if ( 'weekly' === s.frequency ) {
				box.appendChild( label( this.t( 'weekday', 'Wochentag' ), el( 'select', { class: 'wfc-schedule-weekday', onchange: ( ev ) => update( 'weekday', parseInt( ev.target.value, 10 ) ) }, WEEKDAYS.map( ( d, i ) => el( 'option', { value: i + 1, text: this.t( 'weekday' + ( i + 1 ), d ), selected: s.weekday === i + 1 } ) ) ) ) );
			}
			if ( 'monthly' === s.frequency ) {
				box.appendChild( label( this.t( 'day', 'Tag im Monat (1 bis 28)' ), el( 'input', { type: 'number', min: 1, max: 28, class: 'wfc-schedule-day', value: s.day || 1, onchange: ( ev ) => update( 'day', Math.min( 28, Math.max( 1, parseInt( ev.target.value, 10 ) || 1 ) ) ) } ) ) );
			}
			box.appendChild( this.schedulePreview( s ) );
			box.appendChild( el( 'p', { class: 'description', text: this.t( 'scheduleHint', 'Ein verpasster Termin wird höchstens einmal nachgeholt. In der Sommerzeitlücke verschiebt sich die Uhrzeit nach hinten.' ) } ) );
			return box;
		}

		/** Wall time of an instant in the site time zone, e.g. "Mo., 23.11.2026, 07:30". */
		formatTime( t ) {
			const tz = this.config.timezone || 'UTC';
			const opts = { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
			const locale = this.config.locale || document.documentElement.lang || undefined;
			try {
				return new Intl.DateTimeFormat( locale, Object.assign( { timeZone: tz }, opts ) ).format( new Date( t ) );
			} catch ( e ) {
				// Fixed offsets ("+05:30") that Intl does not know: shift and format as UTC.
				return new Intl.DateTimeFormat( locale, Object.assign( { timeZone: 'UTC' }, opts ) ).format( new Date( t + tzOffset( tz, t ) ) );
			}
		}

		/** Next three due times of the schedule (WF-CORE-04), with the same daylight saving rules as the engine. */
		schedulePreview( s ) {
			const times = upcoming( s, Date.now(), this.config.timezone || 'UTC', 3 );
			const box = el( 'div', { class: 'wfc-schedule-preview' } );
			box.appendChild( el( 'p', { id: 'wfc-next-label', text: this.t( 'nextDues', 'Nächste Termine (Zeitzone der Website):' ) } ) );
			if ( ! times.length ) {
				box.appendChild( el( 'p', { class: 'description', text: this.t( 'nextNone', 'Keine Termine: Der Zeitplan ist unvollständig.' ) } ) );
				return box;
			}
			box.appendChild( el( 'ul', { class: 'wfc-next', 'aria-labelledby': 'wfc-next-label' }, times.map( ( t ) => el( 'li', {}, [ el( 'time', { datetime: new Date( t ).toISOString().replace( '.000Z', 'Z' ), text: this.formatTime( t ) } ) ] ) ) ) );
			box.appendChild( this.lastFired() );
			return box;
		}

		/** "Zuletzt ausgelöst" of the published schedule (docs/UI-ENTWUERFE.md, section 4), from the worker's record. */
		lastFired() {
			const last = this.config.lastFired;
			if ( ! last || ! last.at ) {
				return el( 'p', { class: 'description wfc-last-fired', text: this.t( 'lastFiredNever', 'Zuletzt ausgelöst: noch nie.' ) } );
			}
			return el( 'p', { class: 'wfc-last-fired' }, [
				document.createTextNode( this.t( 'lastFired', 'Zuletzt ausgelöst:' ) + ' ' ),
				el( 'time', { datetime: new Date( last.at ).toISOString().replace( '.000Z', 'Z' ), text: this.formatTime( last.at ) } ),
				document.createTextNode( ' (' ),
				el( 'a', { href: last.runs, text: this.t( 'runsOfWorkflow', 'Läufe dieses Workflows' ) } ),
				document.createTextNode( ')' ),
			] );
		}

		/**
		 * Optional serialisation of runs (WF-CORE-05, konzept 15.3): one run per object or user at a time. Without it the
		 * editor says nothing that suggests an order; with it the hint names the only order there is.
		 */
		serializeForm( n ) {
			const options = [ [ '', 'nein (Läufe können gleichzeitig laufen)' ], [ 'subject', 'je Objekt nacheinander (z. B. je Bestellung)' ], [ 'actor', 'je Benutzer nacheinander' ] ];
			const select = el( 'select', {
				class: 'wfc-serialize',
				onchange: ( ev ) => {
					if ( ev.target.value ) {
						n.serialize = ev.target.value;
					} else {
						delete n.serialize;
					}
					this.commit();
				},
			}, options.map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: ( n.serialize || '' ) === v } ) ) );
			const box = labelled( this.t( 'serialize', 'Läufe nacheinander' ), select );
			if ( n.serialize ) {
				box.appendChild( el( 'span', { class: 'description wfc-serialize-hint', text: ' ' + this.t( 'serializeHint', 'Für denselben Schlüssel wartet ein neuer Lauf, bis der ältere fertig ist. Über verschiedene Objekte oder Benutzer hinweg gibt es keine Reihenfolge.' ) } ) );
			}
			return box;
		}

		/** Configuration of the selected node (WF-BUILDER-04). */
		inspector() {
			const box = el( 'div', { class: 'wfc-inspector' } );
			const n = this.selected ? this.node( this.selected ) : null;
			if ( ! n ) {
				box.appendChild( el( 'p', { class: 'description', text: this.t( 'selectHint', 'Einen Knoten auswählen, um ihn zu bearbeiten. Tastatur: Pfeile verschieben, C verbindet, Entf löscht.' ) } ) );
				return box;
			}
			box.appendChild( el( 'h3', { text: this.label( n ), tabindex: '-1' } ) );
			const o = this.origin( n );
			const facts = [ this.t( 'nodeId', 'ID' ) + ': ' + n.id ];
			if ( o ) {
				facts.unshift( this.t( 'origin', 'Herkunft' ) + ': ' + o.label + ( o.version ? ' ' + o.version : '' ) + ( o.contract ? ', ' + this.t( 'contract', 'Vertrag' ) + ' ' + o.contract : '' ) );
			}
			box.appendChild( el( 'p', { class: 'description wfc-origin', text: facts.join( ' · ' ) } ) );
			// Problems of the node; the open branches of a condition are shown at their buttons further down.
			const issues = ( this.issues[ n.id ] || [] ).filter( ( p ) => ! [ 'then', 'else' ].includes( p.code ) );
			if ( issues.length ) {
				box.appendChild( el( 'div', { class: 'notice notice-warning inline wfc-problems' }, [ el( 'ul', {}, issues.map( ( p ) => el( 'li', { text: p.text } ) ) ) ] ) );
			}
			if ( this.focusNode === n.id ) {
				box.appendChild( el( 'p', { class: 'notice notice-info inline wfc-focus-note', text: this.t( 'focusNote', 'Dieser Knoten ist der Schritt aus dem Lauf. Der Lauf nutzte die veröffentlichte Version; der Entwurf kann abweichen.' ) } ) );
			}
			const field = labelled;
			const set = ( key, value, target ) => {
				( target || n )[ key ] = value;
				this.commit();
			};
			if ( 'trigger' === n.type ) {
				const list = el( 'datalist', { id: 'wfc-events' }, this.config.events.map( ( e ) => el( 'option', { value: e } ) ) );
				box.appendChild( list );
				box.appendChild( field( this.t( 'event', 'Ereignis' ), el( 'input', { type: 'text', class: 'regular-text', list: 'wfc-events', value: n.event || '', onchange: ( ev ) => set( 'event', ev.target.value.trim() ) } ) ) );
				if ( 'workflow.schedule.due' === n.event ) {
					box.appendChild( this.scheduleForm( n ) );
				} else {
					box.appendChild( this.serializeForm( n ) );
				}
			} else if ( 'action' === n.type ) {
				const select = el( 'select', { onchange: ( ev ) => { n.action = ev.target.value; n.config = {}; this.commit(); } }, this.config.actions.map( ( a ) => el( 'option', { value: a.key, text: a.label, selected: a.key === n.action } ) ) );
				box.appendChild( field( this.t( 'action', 'Aktion' ), select ) );
				n.config = n.config || {};
				const def = this.config.actions.find( ( a ) => a.key === n.action );
				( def ? def.fields : [] ).forEach( ( f ) => {
					// An emptied field is removed from the configuration, so the server applies its default.
					const put = ( value ) => {
						if ( '' === value ) {
							delete n.config[ f.key ];
							this.commit();
							return;
						}
						set( f.key, 'number' === f.type ? Number( value ) : value, n.config );
					};
					const current = undefined === n.config[ f.key ] ? '' : String( n.config[ f.key ] );
					let input;
					if ( 'textarea' === f.type ) {
						input = el( 'textarea', { rows: 4, class: 'large-text', onchange: ( ev ) => set( f.key, ev.target.value, n.config ) } );
					} else if ( 'select' === f.type ) {
						input = el( 'select', { onchange: ( ev ) => put( ev.target.value ) }, [ el( 'option', { value: '', text: '—' } ) ].concat( ( f.options || [] ).map( ( o ) => el( 'option', { value: o.value, text: o.label, selected: o.value === current } ) ) ) );
					} else if ( 'number' === f.type ) {
						input = el( 'input', { type: 'number', min: '0', step: '1', class: 'small-text', value: current, onchange: ( ev ) => put( ev.target.value.trim() ) } );
					} else {
						input = el( 'input', { type: 'text', class: 'regular-text', value: n.config[ f.key ] || '', onchange: ( ev ) => set( f.key, ev.target.value, n.config ) } );
					}
					if ( 'textarea' === f.type ) {
						input.value = n.config[ f.key ] || '';
					}
					const p = field( f.label, input );
					if ( [ 'subject', 'message', 'text' ].includes( f.key ) ) {
						p.appendChild( el( 'br' ) );
						p.appendChild( this.tokenPicker( input, ( value ) => set( f.key, value, n.config ) ) );
					}
					box.appendChild( p );
				} );
				if ( n.action && 0 === n.action.indexOf( 'notify.' ) ) {
					box.appendChild( this.coreMailHint( n.action ) );
				}
			} else if ( 'condition' === n.type ) {
				const form = this.conditionForm( n );
				if ( form ) {
					box.appendChild( form );
				} else {
					const rules = el( 'textarea', { rows: 8, class: 'large-text code', onchange: ( ev ) => { try { n.rules = JSON.parse( ev.target.value ); this.commit(); } catch ( e ) { this.announce( this.t( 'invalidJson', 'Ungültiges JSON.' ) ); } } } );
					rules.value = JSON.stringify( n.rules || {}, null, 2 );
					box.appendChild( field( this.t( 'rules', 'Regeln (JSON, mit Gruppen)' ), rules ) );
				}
				box.appendChild( field( this.t( 'missing', 'Wenn ein Feld fehlt' ), el( 'select', { onchange: ( ev ) => set( 'missing', ev.target.value ) }, [ [ 'false', 'gilt als nicht erfüllt' ], [ 'stop', 'Lauf beenden' ], [ 'error', 'Fehler' ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: ( n.missing || 'false' ) === v } ) ) ) ) );
				box.appendChild( el( 'p', {}, [
					el( 'button', { type: 'button', class: 'button', text: this.t( 'connectThen', 'Dann verbinden …' ), onclick: () => { this.connectFrom = n.id; this.connectBranch = 'then'; this.announce( this.t( 'connectHint', 'Ziel wählen.' ) ); } } ),
					el( 'button', { type: 'button', class: 'button', text: this.t( 'connectElse', 'Sonst verbinden …' ), onclick: () => { this.connectFrom = n.id; this.connectBranch = 'else'; this.announce( this.t( 'connectHint', 'Ziel wählen.' ) ); } } ),
				] ) );
				( this.issues[ n.id ] || [] ).filter( ( p ) => [ 'then', 'else' ].includes( p.code ) ).forEach( ( p ) => {
					box.appendChild( el( 'p', { class: 'notice notice-warning inline wfc-branch-open', text: p.text } ) );
				} );
			} else if ( 'delay' === n.type ) {
				box.appendChild( field( this.t( 'amount', 'Dauer' ), el( 'input', { type: 'number', min: 1, max: 1000, value: n.amount || 1, onchange: ( ev ) => set( 'amount', parseInt( ev.target.value, 10 ) || 1 ) } ) ) );
				box.appendChild( field( this.t( 'unit', 'Einheit' ), el( 'select', { onchange: ( ev ) => set( 'unit', ev.target.value ) }, [ [ 'minutes', 'Minuten' ], [ 'hours', 'Stunden' ], [ 'days', 'Tage (Kalendertage)' ], [ 'weeks', 'Wochen' ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: n.unit === v } ) ) ) ) );
			}
			if ( 'end' !== n.type && 'condition' !== n.type ) {
				box.appendChild( el( 'button', { type: 'button', class: 'button', text: this.t( 'connect', 'Verbinden mit …' ), onclick: () => { this.connectFrom = n.id; this.announce( this.t( 'connectHint', 'Ziel wählen.' ) ); } } ) );
			}
			box.appendChild( el( 'button', { type: 'button', class: 'button button-link-delete', text: this.t( 'delete', 'Löschen' ), onclick: () => this.remove( n.id ) } ) );
			return box;
		}

		/** Warning after a step of the linear view (not focusable, so the list keeps its Tab order). */
		warnSpan( n, skip ) {
			const issues = ( this.issues[ n.id ] || [] ).filter( ( p ) => ! ( skip || [] ).includes( p.code ) );
			return issues.length ? [ el( 'span', { class: 'wfc-linear-warn', text: ' ⚠ ' + this.problemText( issues ) } ) ] : [];
		}

		/** Linear step view for small screens and screen readers (WF-BUILDER-09). */
		list() {
			const ol = el( 'ol', { class: 'wfc-linear', 'aria-label': this.t( 'linear', 'Schritte der Reihe nach' ) } );
			const seen = new Set();
			const walk = ( id, prefix ) => {
				if ( ! id || seen.has( id ) ) {
					return;
				}
				seen.add( id );
				const n = this.node( id );
				if ( ! n ) {
					return;
				}
				const focus = this.focusNode === id;
				ol.appendChild( el( 'li', { class: focus ? 'is-focus' : null, 'aria-current': focus ? 'step' : null }, [ el( 'button', { type: 'button', class: 'button-link', 'data-id': id, text: prefix + this.label( n ) + ( focus ? ' (' + this.t( 'fromRun', 'aus dem Lauf' ) + ')' : '' ), onclick: () => this.clickNode( id ) } ) ].concat( this.warnSpan( n ) ) ) );
				this.graph.edges.filter( ( e ) => e.from === id ).forEach( ( e ) => walk( e.to, e.branch ? ( 'then' === e.branch ? this.t( 'then', 'Dann' ) : this.t( 'else', 'Sonst' ) ) + ': ' : '' ) );
			};
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type );
			walk( trigger && trigger.id, '' );
			this.graph.nodes.filter( ( n ) => ! seen.has( n.id ) ).forEach( ( n ) => ol.appendChild( el( 'li', { class: 'wfc-unconnected' }, [ el( 'button', { type: 'button', class: 'button-link', text: this.t( 'unconnected', 'Nicht verbunden' ) + ': ' + this.label( n ), onclick: () => this.clickNode( n.id ) } ) ].concat( this.warnSpan( n, [ 'unreachable' ] ) ) ) ) );
			// Stays open across rebuilds, so choosing a step on a small screen does not close the list.
			const narrow = window.matchMedia && window.matchMedia( '(max-width: 782px)' ).matches;
			return el( 'details', { class: 'wfc-linear-wrap', open: !! this.linearOpen || !! ( this.focusNode && this.node( this.focusNode ) && narrow ) }, [ el( 'summary', { text: this.t( 'linear', 'Schritte der Reihe nach' ) } ), ol ] );
		}
	}

	Builder.upcoming = upcoming;
	Builder.conditionViolations = conditionViolations;
	window.WorkflowCaptainBuilder = Builder;
	document.addEventListener( 'DOMContentLoaded', () => {
		const textarea = document.getElementById( 'wfc-graph' );
		if ( textarea ) {
			window.workflowCaptainBuilderInstance = new Builder( textarea, window.workflowCaptainBuilder );
		}
	} );
}() );
