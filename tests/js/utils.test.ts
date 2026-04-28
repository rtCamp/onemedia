/**
 * External dependencies
 */
import {
	debounce,
	getAllowedMimeTypeExtensions,
	getAllowedMimeTypes,
	getFrameProperty,
	getNoticeClass,
	isValidUrl,
	observeElement,
	removeTrailingSlash,
	restrictMediaFrameUploadTypes,
	showSnackbarNotice,
	trimTitle,
} from '@/js/utils';

describe( 'utils', () => {
	describe( 'isValidUrl', () => {
		it( 'returns true for a valid URL', () => {
			expect( isValidUrl( 'https://example.org' ) ).toBe( true );
		} );

		it( 'returns false for malformed values', () => {
			expect( isValidUrl( '://broken' ) ).toBe( false );
		} );
	} );

	describe( 'removeTrailingSlash', () => {
		it( 'removes one or more trailing slashes', () => {
			expect( removeTrailingSlash( 'https://example.com///' ) ).toBe(
				'https://example.com'
			);
		} );

		it( 'keeps URLs without trailing slash unchanged', () => {
			expect( removeTrailingSlash( 'https://example.com/path' ) ).toBe(
				'https://example.com/path'
			);
		} );
	} );

	describe( 'getNoticeClass', () => {
		it( 'maps error and warning to their dedicated classes', () => {
			expect( getNoticeClass( 'error' ) ).toBe( 'onemedia-error-notice' );
			expect( getNoticeClass( 'warning' ) ).toBe(
				'onemedia-warning-notice'
			);
		} );

		it( 'falls back to success class for other values', () => {
			expect( getNoticeClass( 'success' ) ).toBe(
				'onemedia-success-notice'
			);
		} );
	} );

	describe( 'trimTitle', () => {
		it( 'returns the same title when below max length', () => {
			expect( trimTitle( 'Short title', 20 ) ).toBe( 'Short title' );
		} );

		it( 'truncates and appends ellipsis when over max length', () => {
			expect( trimTitle( 'abcdefghijklmnopqrstuvwxyz', 5 ) ).toBe(
				'abcde…'
			);
		} );

		it( 'returns an empty string when title is omitted', () => {
			expect( trimTitle() ).toBe( '' );
		} );
	} );

	describe( 'debounce', () => {
		beforeEach( () => {
			jest.useFakeTimers();
		} );

		afterEach( () => {
			jest.useRealTimers();
		} );

		it( 'only invokes the callback once with latest arguments', () => {
			const callback = jest.fn();
			const debounced = debounce( callback, 200 );

			debounced( 'first', 1, true, {} );
			debounced( 'second', 2, false, { value: 1 } );
			debounced( 'third', 3, true, { value: 2 } );

			expect( callback ).not.toHaveBeenCalled();
			jest.advanceTimersByTime( 200 );
			expect( callback ).toHaveBeenCalledTimes( 1 );
			expect( callback ).toHaveBeenCalledWith( 'third', 3, true, {
				value: 2,
			} );
		} );
	} );

	describe( 'observeElement', () => {
		it( 'runs callback immediately when matching elements already exist', () => {
			document.body.innerHTML = '<div class="target"></div>';
			const onFound = jest.fn();

			const observer = observeElement( '.target', onFound );
			observer.disconnect();

			expect( onFound ).toHaveBeenCalledTimes( 1 );
			expect( onFound.mock.calls[ 0 ][ 0 ] ).toHaveLength( 1 );
		} );
	} );

	describe( 'getFrameProperty', () => {
		it( 'returns nested property when it exists', () => {
			( window as unknown as { demo?: { value?: string } } ).demo = {
				value: 'ok',
			};

			expect( getFrameProperty< string >( 'demo.value' ) ).toBe( 'ok' );
		} );

		it( 'returns undefined for missing or invalid paths', () => {
			expect( getFrameProperty( '' ) ).toBeUndefined();
			expect( getFrameProperty( 'demo.missing' ) ).toBeUndefined();
		} );
	} );

	describe( 'showSnackbarNotice', () => {
		it( 'dispatches onemediaNotice with detail payload', () => {
			const listener = jest.fn();
			document.addEventListener(
				'onemediaNotice',
				listener as EventListener
			);

			showSnackbarNotice( {
				type: 'success',
				message: 'Saved',
			} );

			expect( listener ).toHaveBeenCalledTimes( 1 );
			const event = listener.mock.calls[ 0 ][ 0 ] as CustomEvent;
			expect( event.detail ).toEqual( {
				type: 'success',
				message: 'Saved',
			} );

			document.removeEventListener(
				'onemediaNotice',
				listener as EventListener
			);
		} );

		it( 'does not dispatch when message is missing', () => {
			const listener = jest.fn();
			document.addEventListener(
				'onemediaNotice',
				listener as EventListener
			);

			showSnackbarNotice( {
				type: 'error',
				message: '',
			} );

			expect( listener ).not.toHaveBeenCalled();

			document.removeEventListener(
				'onemediaNotice',
				listener as EventListener
			);
		} );
	} );

	describe( 'restrictMediaFrameUploadTypes', () => {
		it( 'configures uploader filters and multipart params when uploader is ready', () => {
			const setOption = jest.fn();
			const uploader = {
				settings: {
					multipart_params: {
						existing: 'value',
					},
				},
				setOption,
			};

			const onceCallbacks: Record< string, () => void > = {};
			const onCallbacks: Record< string, () => void > = {};
			const observe = jest.fn();

			const frame = {
				once: jest.fn( ( event: string, cb: () => void ) => {
					onceCallbacks[ event ] = cb;
				} ),
				on: jest.fn( ( event: string, cb: () => void ) => {
					onCallbacks[ event ] = cb;
				} ),
				uploader: {
					uploader: {
						uploader,
					},
				},
				state: () => ( {
					get: () => ( {
						observe,
					} ),
				} ),
			};

			(
				window as unknown as { wp: { Uploader: { queue: unknown } } }
			 ).wp = {
				Uploader: {
					queue: {
						id: 1,
					},
				},
			} as { Uploader: { queue: unknown } };

			restrictMediaFrameUploadTypes(
				frame as unknown as Parameters<
					typeof restrictMediaFrameUploadTypes
				>[ 0 ],
				'jpg,png',
				true
			);

			onceCallbacks[ 'uploader:ready' ]?.();
			onCallbacks[ 'ready' ]?.();

			expect( setOption ).toHaveBeenCalledWith( 'filters', {
				mime_types: [ { extensions: 'jpg,png' } ],
			} );
			expect( setOption ).toHaveBeenCalledWith(
				'multi_selection',
				false
			);
			expect( setOption ).toHaveBeenCalledWith( 'multipart_params', {
				existing: 'value',
				is_onemedia_sync: true,
			} );
			expect( observe ).toHaveBeenCalledWith( window.wp.Uploader.queue );
		} );
	} );

	describe( 'mime helpers', () => {
		it( 'returns unique mime types from map values', () => {
			expect(
				getAllowedMimeTypes( {
					jpg: 'image/jpeg',
					jpeg: 'image/jpeg',
					png: 'image/png',
				} )
			).toEqual( [ 'image/jpeg', 'image/png' ] );
		} );

		it( 'returns flattened extension list from pipe-delimited keys', () => {
			expect(
				getAllowedMimeTypeExtensions( {
					'jpg|jpeg': 'image/jpeg',
					png: 'image/png',
				} )
			).toEqual( [ 'jpg', 'jpeg', 'png' ] );
		} );
	} );
} );
