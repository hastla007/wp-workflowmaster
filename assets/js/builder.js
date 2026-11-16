/**
 * Workflow Captain – visual builder, first version (WF-BUILDER-01, -03, -04, -08, -09).
 *
 * Works on the JSON textarea of the workflow editor: reads the graph, lets the user edit it on a canvas or in a linear
 * list, and writes it back on every change. The server validates on save and publish (src/Engine/Graph.php), so this
 * file only helps editing; it decides nothing.
 *
 * No build step, no dependencies. Data from PHP: window.workflowCaptainBuilder = { actions: [{key,label,fields}], events: [], i18n: {} }.
 */
( function () {
	'use strict';

	const NS = 'http://www.w3.org/2000/svg';
	const W = 180;
	const H = 56;

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

	function svg( tag, attrs ) {
		const node = document.createElementNS( NS, tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => node.setAttribute( k, v ) );
		return node;
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
			this.read();
			this.render();
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

		add( type ) {
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
			const spot = this.freeSpot( last ? last.x : 40, last ? last.y + H + 50 : 30 );
			n.x = spot[ 0 ];
			n.y = spot[ 1 ];
			this.graph.nodes.push( n );
			this.selected = n.id;
			this.commit();
			this.announce( this.t( 'added', 'Knoten hinzugefügt:' ) + ' ' + this.label( n ) );
		}

		remove( id ) {
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

		announce( text ) {
			if ( this.live ) {
				this.live.textContent = text;
			}
		}

		render() {
			if ( ! this.root ) {
				this.root = el( 'div', { class: 'wfc-builder' } );
				this.textarea.parentNode.insertBefore( this.root, this.textarea );
			}
			this.root.innerHTML = '';
			this.live = el( 'div', { class: 'screen-reader-text', 'aria-live': 'polite' } );
			this.root.appendChild( this.live );
			if ( this.error ) {
				this.root.appendChild( el( 'div', { class: 'notice notice-error inline' }, [ el( 'p', { text: this.error } ) ] ) );
			}
			this.root.appendChild( this.toolbar() );
			const body = el( 'div', { class: 'wfc-builder-body' } );
			body.appendChild( this.canvas() );
			body.appendChild( this.inspector() );
			this.root.appendChild( body );
			this.root.appendChild( this.list() );
		}

		toolbar() {
			const bar = el( 'div', { class: 'wfc-toolbar', role: 'toolbar', 'aria-label': this.t( 'palette', 'Bausteine' ) } );
			[ [ 'trigger', 'Auslöser' ], [ 'condition', 'Wenn/Sonst' ], [ 'action', 'Aktion' ], [ 'delay', 'Warten' ], [ 'end', 'Ende' ] ].forEach( ( [ type, label ] ) => {
				bar.appendChild( el( 'button', { type: 'button', class: 'button wfc-add', 'data-type': type, text: '+ ' + this.t( type, label ), onclick: () => this.add( type ) } ) );
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
				const unavailable = ( 'trigger' === n.type && this.config.events.length && ! this.config.events.includes( n.event ) ) || ( 'action' === n.type && ! this.config.actions.some( ( x ) => x.key === n.action ) );
				const state = ( ( this.config.path || [] ).includes( n.id ) ? ' is-path' : '' ) + ( this.config.errorNode === n.id ? ' is-error' : '' ) + ( unavailable ? ' is-unavailable' : '' );
				const g = svg( 'g', { class: 'wfc-node wfc-node-' + n.type + state + ( this.selected === n.id ? ' is-selected' : '' ), transform: `translate(${ n.x },${ n.y })`, tabindex: '0', role: 'button', 'aria-label': this.label( n ), 'data-id': n.id } );
				g.appendChild( svg( 'rect', { width: W, height: H, rx: 'end' === n.type || 'trigger' === n.type ? 28 : 6 } ) );
				if ( unavailable ) {
					const warn = svg( 'text', { x: W - 22, y: 22, class: 'wfc-node-warn' } );
					warn.textContent = '!';
					g.appendChild( warn );
				}
				const title = svg( 'text', { x: 12, y: 24, class: 'wfc-node-title' } );
				const full = this.label( n );
				title.textContent = full.length > 22 ? full.slice( 0, 21 ) + '…' : full;
				g.appendChild( title );
				const tip = svg( 'title' );
				tip.textContent = full;
				g.appendChild( tip );
				const sub = svg( 'text', { x: 12, y: 42, class: 'wfc-node-id' } );
				sub.textContent = n.id;
				g.appendChild( sub );
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
			this.render();
			const g = this.root.querySelector( `.wfc-node[data-id="${ id }"]` );
			if ( g ) {
				g.focus();
			}
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

		/** Condition as a form (one level of rules); groups fall back to JSON. */
		conditionForm( n ) {
			const box = el( 'div', { class: 'wfc-rules' } );
			const rules = n.rules || { op: 'and', items: [] };
			const simple = Array.isArray( rules.items ) && rules.items.every( ( r ) => ! r.op );
			if ( ! simple ) {
				return null;
			}
			const fields = [ 'type', 'subject.type', 'subject.id', 'subject.id_kind', 'source.product', 'actor_user_id' ].concat( this.dataFields().map( ( f ) => 'data.' + f ) );
			const types = this.dataTypes();
			box.appendChild( el( 'datalist', { id: 'wfc-fields' }, fields.map( ( f ) => el( 'option', { value: f, label: f.startsWith( 'data.' ) && types[ f.slice( 5 ) ] ? types[ f.slice( 5 ) ] : null } ) ) ) );
			box.appendChild( el( 'p', {}, [ el( 'label', { text: this.t( 'combine', 'Verknüpfung' ) + ' ' } ), el( 'select', { class: 'wfc-op', onchange: ( ev ) => { rules.op = ev.target.value; n.rules = rules; this.commit(); } }, [ [ 'and', 'alle Regeln (UND)' ], [ 'or', 'eine Regel (ODER)' ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: rules.op === v } ) ) ) ] ) );
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
			rules.items.forEach( ( r, i ) => {
				const row = el( 'div', { class: 'wfc-rule' } );
				row.appendChild( el( 'input', { type: 'text', class: 'wfc-rule-field', list: 'wfc-fields', value: r.field || '', 'aria-label': this.t( 'field', 'Feld' ), onchange: ( ev ) => { r.field = ev.target.value.trim(); n.rules = rules; this.commit(); } } ) );
				const dtype = ( r.field || '' ).startsWith( 'data.' ) ? types[ r.field.slice( 5 ) ] : '';
				if ( dtype ) {
					row.appendChild( el( 'span', { class: 'wfc-rule-type', text: dtype } ) );
				}
				row.appendChild( el( 'select', { class: 'wfc-rule-op', 'aria-label': this.t( 'operator', 'Operator' ), onchange: ( ev ) => { r.operator = ev.target.value; r.value = parse( r.operator, show( r.value ) ); n.rules = rules; this.commit(); } }, ops.map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: r.operator === v } ) ) ) );
				if ( ! [ 'exists', 'not_exists' ].includes( r.operator ) ) {
					row.appendChild( el( 'input', { type: 'text', class: 'wfc-rule-value', value: show( r.value ), 'aria-label': this.t( 'value', 'Wert' ), onchange: ( ev ) => { r.value = parse( r.operator, ev.target.value ); n.rules = rules; this.commit(); } } ) );
				}
				row.appendChild( el( 'button', { type: 'button', class: 'button-link wfc-rule-remove', text: '×', 'aria-label': this.t( 'removeRule', 'Regel entfernen' ), onclick: () => { rules.items.splice( i, 1 ); n.rules = rules; this.commit(); } } ) );
				box.appendChild( row );
			} );
			box.appendChild( el( 'button', { type: 'button', class: 'button wfc-rule-add', text: '+ ' + this.t( 'addRule', 'Regel' ), onclick: () => { rules.items.push( { field: fields[ 0 ] || 'type', operator: 'eq', value: '' } ); n.rules = rules; this.commit(); } } ) );
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
			const box = el( 'fieldset', { class: 'wfc-schedule' }, [ el( 'legend', { text: this.t( 'schedule', 'Zeitplan (Zeitzone der Website)' ) } ) ] );
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
			};
			const label = ( text, input ) => el( 'p', {}, [ el( 'label', { text } ), el( 'br' ), input ] );
			box.appendChild( label( this.t( 'frequency', 'Wiederholung' ), el( 'select', { class: 'wfc-schedule-frequency', onchange: ( ev ) => update( 'frequency', ev.target.value ) }, [ [ 'daily', 'täglich' ], [ 'weekly', 'wöchentlich' ], [ 'monthly', 'monatlich' ] ].map( ( [ v, l ] ) => el( 'option', { value: v, text: l, selected: s.frequency === v } ) ) ) ) );
			box.appendChild( label( this.t( 'time', 'Uhrzeit' ), el( 'input', { type: 'time', class: 'wfc-schedule-time', value: s.time || '08:00', onchange: ( ev ) => update( 'time', ev.target.value ) } ) ) );
			if ( 'weekly' === s.frequency ) {
				box.appendChild( label( this.t( 'weekday', 'Wochentag' ), el( 'select', { class: 'wfc-schedule-weekday', onchange: ( ev ) => update( 'weekday', parseInt( ev.target.value, 10 ) ) }, [ 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag' ].map( ( d, i ) => el( 'option', { value: i + 1, text: d, selected: s.weekday === i + 1 } ) ) ) ) );
			}
			if ( 'monthly' === s.frequency ) {
				box.appendChild( label( this.t( 'day', 'Tag im Monat (1 bis 28)' ), el( 'input', { type: 'number', min: 1, max: 28, class: 'wfc-schedule-day', value: s.day || 1, onchange: ( ev ) => update( 'day', Math.min( 28, Math.max( 1, parseInt( ev.target.value, 10 ) || 1 ) ) ) } ) ) );
			}
			box.appendChild( el( 'p', { class: 'description', text: this.t( 'scheduleHint', 'Ein verpasster Termin wird höchstens einmal nachgeholt. In der Sommerzeitlücke verschiebt sich die Uhrzeit nach hinten.' ) } ) );
			return box;
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
			const box = el( 'p', {}, [ el( 'label', { text: this.t( 'serialize', 'Läufe nacheinander' ) } ), el( 'br' ), select ] );
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
			box.appendChild( el( 'h3', { text: this.label( n ) } ) );
			const field = ( label, input ) => el( 'p', {}, [ el( 'label', { text: label } ), el( 'br' ), input ] );
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
					const input = 'textarea' === f.type
						? el( 'textarea', { rows: 4, class: 'large-text', onchange: ( ev ) => set( f.key, ev.target.value, n.config ) } )
						: el( 'input', { type: 'text', class: 'regular-text', value: n.config[ f.key ] || '', onchange: ( ev ) => set( f.key, ev.target.value, n.config ) } );
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
				ol.appendChild( el( 'li', {}, [ el( 'button', { type: 'button', class: 'button-link', text: prefix + this.label( n ), onclick: () => this.clickNode( id ) } ) ] ) );
				this.graph.edges.filter( ( e ) => e.from === id ).forEach( ( e ) => walk( e.to, e.branch ? ( 'then' === e.branch ? this.t( 'then', 'Dann' ) : this.t( 'else', 'Sonst' ) ) + ': ' : '' ) );
			};
			const trigger = this.graph.nodes.find( ( n ) => 'trigger' === n.type );
			walk( trigger && trigger.id, '' );
			this.graph.nodes.filter( ( n ) => ! seen.has( n.id ) ).forEach( ( n ) => ol.appendChild( el( 'li', { class: 'wfc-unconnected' }, [ el( 'button', { type: 'button', class: 'button-link', text: this.t( 'unconnected', 'Nicht verbunden' ) + ': ' + this.label( n ), onclick: () => this.clickNode( n.id ) } ) ] ) ) );
			return el( 'details', { class: 'wfc-linear-wrap' }, [ el( 'summary', { text: this.t( 'linear', 'Schritte der Reihe nach' ) } ), ol ] );
		}
	}

	window.WorkflowCaptainBuilder = Builder;
	document.addEventListener( 'DOMContentLoaded', () => {
		const textarea = document.getElementById( 'wfc-graph' );
		if ( textarea ) {
			window.workflowCaptainBuilderInstance = new Builder( textarea, window.workflowCaptainBuilder );
		}
	} );
}() );
