/**
 * Media Grid Sync Filter Implementation
 */

/**
 * Internal dependencies
 */
/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { getFrameProperty } from './utils';

function SyncMediaFilter() {
	const media = getFrameProperty( 'wp.media' );
	const originalAttachmentsBrowser = getFrameProperty(
		'wp.media.view.AttachmentsBrowser'
	);

	if ( ! media || ! originalAttachmentsBrowser ) {
		return;
	}

	const ONEMEDIA_MEDIA_UPLOAD = window.OneMediaMediaUpload || '';

	if ( ! ONEMEDIA_MEDIA_UPLOAD ) {
		return;
	}

	const ALL_LABEL = ONEMEDIA_MEDIA_UPLOAD?.allLabel ?? '';
	const SYNC_LABEL = ONEMEDIA_MEDIA_UPLOAD?.syncLabel ?? '';
	const NOT_SYNC_LABEL = ONEMEDIA_MEDIA_UPLOAD?.notSyncLabel ?? '';
	const NONCE = ONEMEDIA_MEDIA_UPLOAD?.nonce ?? '';
	const SYNC_STATUS = ONEMEDIA_MEDIA_UPLOAD?.syncStatus ?? '';

	// Create a custom filter view.
	const SyncFilterView = wp.media.View.extend( {
		tagName: 'label',
		className: 'attachment-filters onemedia-sync-filter-wrapper',

		initialize() {
			this.createSelect();
			this.listenTo( this.model, 'change', this.updateSelect );
		},

		createSelect() {
			this.$el.html( '' );

			// Create select element.
			this.select = document.createElement( 'select' );
			this.select.className = 'attachment-filters onemedia-sync-filter';
			this.select.innerHTML = `
				<option value="">${ ALL_LABEL }</option>
				<option value="sync">${ SYNC_LABEL }</option>
				<option value="no_sync">${ NOT_SYNC_LABEL }</option>
			`;

			// Get saved value from URL or previous state.
			const urlParams = new URLSearchParams( window?.location?.search );
			const savedFilter = urlParams?.get( SYNC_STATUS ) || '';
			this.select.value = savedFilter;

			// Set initial model value.
			if ( savedFilter ) {
				this.model.set( SYNC_STATUS, savedFilter );
			}

			this.$el.append( this.select );

			// Add event listener.
			this.select.addEventListener( 'change', () => {
				const value = this.select.value;
				this.model.set( SYNC_STATUS, value );

				// Update URL.
				if ( window?.history?.replaceState ) {
					const url = new URL( window?.location );
					if ( value ) {
						url.searchParams.set( SYNC_STATUS, value );
					} else {
						url.searchParams.delete( SYNC_STATUS );
					}
					window?.history.replaceState( {}, '', url );
				}
			} );
		},

		updateSelect() {
			const value = this.model.get( SYNC_STATUS ) || '';
			this.select.value = value;
		},
	} );

	// Extend the AttachmentsBrowser to include our filter.
	media.view.AttachmentsBrowser = originalAttachmentsBrowser.extend( {
		createToolbar() {
			// Call original method.
			originalAttachmentsBrowser.prototype.createToolbar.call( this );

			// Add our filter to the toolbar.
			this.toolbar.set(
				'onemediaSyncFilter',
				new SyncFilterView( {
					controller: this.controller,
					model: this.collection.props,
					priority: -75,
				} )
			);
		},
	} );

	// Modify the query to handle our filter.
	const originalAjax = media.ajax;
	media.ajax = function ( action, options ) {
		if ( 'query-attachments' === action ) {
			const syncStatus = options.data.query.onemedia_sync_status;

			// Add nonce to the request.
			options.data._ajax_nonce = NONCE;

			// Convert our filter parameter to WordPress meta_query format.
			if ( 'sync' === syncStatus ) {
				options.data.query.meta_query = [
					{
						key: SYNC_STATUS,
						value: 'sync',
						compare: '=',
					},
				];
			} else if ( 'no_sync' === syncStatus ) {
				// We need to send a properly formatted meta_query.
				options.data.query.meta_query = {
					relation: 'OR',
					0: {
						key: SYNC_STATUS,
						value: 'no_sync',
						compare: '=',
					},
					1: {
						key: SYNC_STATUS,
						compare: 'NOT EXISTS',
					},
				};
			}

			// Clean up our custom parameter to avoid conflicts.
			delete options.data.query.onemedia_sync_status;
		}

		// Call the original Ajax method.
		return originalAjax.call( this, action, options );
	};

	// Make sure filters are applied on initial load.
	const urlParams = new URLSearchParams( window?.location?.search );
	const initialFilter = urlParams?.get( SYNC_STATUS );
	const frameContentProperty = getFrameProperty( 'wp.media.frame.content' );
	if ( initialFilter && frameContentProperty?.get() ) {
		const library = frameContentProperty.get().collection;
		if ( library ) {
			library.props.set( SYNC_STATUS, initialFilter );
		}
	}
}

// Initialize the filter.
domReady( () => {
	SyncMediaFilter();
} );
