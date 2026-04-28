/**
 * Utility functions
 */

/**
 * Internal dependencies
 */
import type { NoticeType } from '../admin/settings/page';

declare global {
	interface Window {
		wp: {
			Uploader: {
				queue: unknown;
			};
			media: {
				attachment: (
					id: number
				) => { get: ( key: string ) => unknown } | undefined;
			};
		};
	}
}

type WPMediaUploader = {
	settings: {
		multipart_params: Record< string, unknown >;
	};
	setOption: (
		key: 'filters' | 'multi_selection' | string,
		value: string | object | boolean
	) => void;
};

type WPMediaFrame = {
	once: ( event: 'uploader:ready' | string, cb: () => void ) => void;
	uploader: {
		uploader: {
			uploader: WPMediaUploader;
		};
	};
	on: ( event: 'ready' | string, cb: () => void ) => void;
	state: () => {
		get: (
			key: 'library' | string
		) => { observe: ( queue: unknown ) => void } | undefined;
	};
};

/**
 * Validates if a given string is a valid URL.
 *
 * @param {string} url - The URL string to validate.
 *
 * @return {boolean} True if the URL is valid, false otherwise.
 */
const isValidUrl = ( url: string ): boolean => {
	try {
		new URL( url );
		return true;
	} catch {
		return false;
	}
};

/**
 * Removes trailing slashes from a URL.
 *
 * @param {string} url - The URL to process.
 * @return {string} The URL without trailing slashes.
 */
const removeTrailingSlash = ( url: string ): string =>
	url.replace( /\/+$/, '' );

/**
 * Returns the appropriate CSS class for a notice based on its type.
 *
 * @param {string} type - The type of notice ('error', 'warning', 'success').
 * @return {string} The corresponding CSS class.
 */
const getNoticeClass = ( type: NoticeType[ 'type' ] ): string => {
	if ( type === 'error' ) {
		return 'onemedia-error-notice';
	}
	if ( type === 'warning' ) {
		return 'onemedia-warning-notice';
	}
	return 'onemedia-success-notice';
};

/**
 * Trims a title to a specified maximum length, adding an ellipsis if trimmed.
 *
 * @param {string} title     - The title to trim.
 * @param {number} maxLength - The maximum length of the title (default is 25).
 * @return {string} The trimmed title.
 */
const trimTitle = ( title: string = '', maxLength: number = 25 ): string => {
	return title.length > maxLength
		? title.substring( 0, maxLength ) + '…'
		: title;
};

/**
 * Debounced function that delays invoking the provided function until after
 * the specified wait time has elapsed since the last time it was invoked.
 *
 * @param { Function } func - The function to debounce.
 * @param { number }   wait - The number of milliseconds to delay.
 *
 * @return {Function} A debounced version of the provided function.
 */
const debounce = < TArgs extends Array< string | number | boolean | object > >(
	func: ( ...args: TArgs ) => void,
	wait: number
): ( ( ...args: TArgs ) => void ) => {
	let timeout: ReturnType< typeof setTimeout > | undefined;
	return function executedFunction( ...args: TArgs ) {
		const later = () => {
			clearTimeout( timeout );
			func( ...args );
		};
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
	};
};

/**
 * Observe for elements matching selector and run callback when found.
 *
 * @param {string}   selector      - CSS selector to observe for.
 * @param {Function} onFound       - Callback when element is found.
 * @param {number}   debounceDelay - Time to wait after last mutation before firing (default 200ms).
 * @return {MutationObserver} The MutationObserver instance.
 */
const observeElement = (
	selector: string,
	onFound: ( elements: NodeListOf< Element > ) => void,
	debounceDelay: number = 200
): MutationObserver => {
	const debouncedOnFound = debounce( () => {
		const elements = document.querySelectorAll( selector );
		if ( elements.length > 0 ) {
			onFound( elements );
		}
	}, debounceDelay );

	const observer = new MutationObserver( () => {
		debouncedOnFound();
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
	} );

	// Run once in case elements already exist.
	const existing = document.querySelectorAll( selector );
	if ( existing.length > 0 ) {
		onFound( existing );
	}

	return observer;
};

/**
 * Retrieves a nested property from the window object based on a dot-separated path.
 *
 * @template T -- The expected type of the property.
 * @param {string} propertyPath - Dot-separated path to the property, e.g. 'wp.media.view.AttachmentsBrowser'
 * @return {T | undefined} The value of the nested property, or undefined if not found.
 */
function getFrameProperty< T = object >( propertyPath: string ): T | undefined {
	if ( typeof propertyPath !== 'string' || ! propertyPath ) {
		return undefined;
	}

	try {
		const keys = propertyPath.split( '.' );
		let current: Record< string, unknown > = window as unknown as Record<
			string,
			unknown
		>;

		for ( const key of keys ) {
			if (
				current &&
				( typeof current === 'object' ||
					typeof current === 'function' ) &&
				key in current
			) {
				current = current[ key ] as Record< string, unknown >;
			} else {
				return undefined;
			}
		}

		return current as T;
	} catch {
		return undefined;
	}
}

/**
 * Show a snackbar notice with the specified type and message.
 *
 * @param {NoticeType} detail - The detail object containing type and message.
 */
const showSnackbarNotice = ( detail: NoticeType ): void => {
	const type = detail.type || 'error';
	const message = detail.message || '';

	if ( ! message ) {
		return;
	}

	const event = new CustomEvent( 'onemediaNotice', {
		detail: {
			type,
			message,
		},
	} );
	document.dispatchEvent( event );
};

/**
 * Restrict upload types in a WordPress media frame.
 *
 * @param {WPMediaFrame} frame        - The WordPress media frame to restrict.
 * @param {string}       allowedTypes - Comma-separated list of allowed file extensions.
 * @param {boolean}      isSync       - Whether the upload is for sync (default false).
 *
 * @return {void}
 */
const restrictMediaFrameUploadTypes = (
	frame: WPMediaFrame,
	allowedTypes: string,
	isSync: boolean = false
) => {
	/**
	 * Using mime_type will restrict the upload types in media modal,
	 * Which we don't want as we only need to restrict for OneMedia uploader frame.
	 *
	 * @see https://wordpress.stackexchange.com/questions/343320/restrict-file-types-in-the-uploader-of-a-wp-media-frame
	 */
	frame.once( 'uploader:ready', () => {
		const uploader = frame.uploader.uploader.uploader;

		// Get existing multipart_params first
		const existingParams = uploader.settings.multipart_params || {};

		uploader.setOption( 'filters', {
			mime_types: [ { extensions: allowedTypes } ],
		} );

		// Trick to re-init field
		uploader.setOption( 'multi_selection', false );

		// Set is_onemedia_sync param
		uploader.setOption( 'multipart_params', {
			...existingParams,
			is_onemedia_sync: isSync,
		} );
	} );

	/**
	 * Observe the library to link with uploader queue.
	 *
	 * @see https://core.trac.wordpress.org/ticket/34465
	 */
	frame.on( 'ready', function () {
		const library = frame.state().get( 'library' );
		if ( library && window.wp.Uploader && window.wp.Uploader.queue ) {
			library.observe( window.wp.Uploader.queue );
		}
	} );
};

/**
 * Get MIME types from a MIME map.
 * @param {Object} mimeMap
 */
function getAllowedMimeTypes( mimeMap: Object ): string[] | undefined {
	return [ ...new Set( Object.values( mimeMap ) ) ];
}

/**
 * Get extensions from a MIME map.
 * @param {Object} mimeMap
 */
function getAllowedMimeTypeExtensions( mimeMap: Object ): string[] {
	return Object.keys( mimeMap ).flatMap( ( key ) => key.split( '|' ) );
}

export {
	isValidUrl,
	removeTrailingSlash,
	getNoticeClass,
	trimTitle,
	debounce,
	observeElement,
	getFrameProperty,
	showSnackbarNotice,
	restrictMediaFrameUploadTypes,
	getAllowedMimeTypes,
	getAllowedMimeTypeExtensions,
};
