/**
 * Settings page of Workflow Captain: warns before leaving with unsaved changes and resets the fields of the
 * current tab to their defaults after a confirmation (WF-SET-13). Nothing is saved without "Änderungen speichern".
 */
( function () {
	'use strict';
	const text = window.workflowCaptainSettings || {};
	/*
	 * After a save, import, reset or undo the page reloads and focus would start at the top of the document. Move it
	 * to what the action produced: the first field with an error, else the import preview, else the message of the
	 * action (the page marks a reload after an action with data-wfc-result; WordPress removes settings-updated from
	 * the address bar). Runs after WordPress has moved its notices below the heading, so focus stays put.
	 */
	const focusResult = function () {
		const target = document.querySelector( '#wpbody-content [aria-invalid="true"]' ) ||
			document.querySelector( '#wpbody-content [data-wfc-focus]' ) ||
			( document.querySelector( '#wpbody-content [data-wfc-result]' ) ? document.querySelector( '#wpbody-content .settings-error' ) : null );
		if ( ! target ) {
			return;
		}
		if ( ! target.matches( 'input, select, textarea, button, a[href]' ) && ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}
		target.focus();
	};
	if ( 'complete' === document.readyState ) {
		window.setTimeout( focusResult, 0 );
	} else {
		window.addEventListener( 'load', function () {
			window.setTimeout( focusResult, 0 );
		} );
	}
	const forms = Array.prototype.slice.call( document.querySelectorAll( 'form.wfc-settings-form' ) );
	if ( ! forms.length ) {
		return;
	}
	let dirty = false;
	let submitting = false;
	const markDirty = function () {
		dirty = true;
	};
	forms.forEach( function ( form ) {
		// Switching on a destructive option needs a confirmation (SET-WC-05): cancelling leaves it off.
		form.querySelectorAll( 'input[type="checkbox"][data-confirm]' ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				// eslint-disable-next-line no-alert -- a native confirmation is the accessible choice here.
				if ( box.checked && ! window.confirm( box.getAttribute( 'data-confirm' ) ) ) {
					box.checked = false;
				}
			} );
		} );
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			submitting = true;
		} );
		const reset = form.querySelector( '.wfc-reset-tab' );
		if ( ! reset ) {
			return;
		}
		reset.addEventListener( 'click', function () {
			// eslint-disable-next-line no-alert -- a native confirmation is the accessible choice here.
			if ( ! window.confirm( text.reset || 'Reset?' ) ) {
				return;
			}
			form.querySelectorAll( '[data-default]' ).forEach( function ( input ) {
				if ( 'checkbox' === input.type ) {
					input.checked = '1' === input.getAttribute( 'data-default' );
				} else {
					input.value = input.getAttribute( 'data-default' );
				}
				input.removeAttribute( 'aria-invalid' );
			} );
			markDirty();
			const first = form.querySelector( '[data-default]' );
			if ( first ) {
				first.focus();
			}
		} );
	} );
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty && ! submitting ) {
			event.preventDefault();
			event.returnValue = text.unsaved || '';
			return text.unsaved || '';
		}
		return undefined;
	} );
}() );
