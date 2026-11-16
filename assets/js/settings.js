/**
 * Settings page of Workflow Captain: warns before leaving with unsaved changes and resets the fields of the
 * current tab to their defaults after a confirmation (WF-SET-13). Nothing is saved without "Änderungen speichern".
 */
( function () {
	'use strict';
	const text = window.workflowCaptainSettings || {};
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
