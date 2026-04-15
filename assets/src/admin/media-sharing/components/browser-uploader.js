/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	uploadMedia,
	updateExistingAttachment,
	checkIfAllSitesConnected,
	isSyncAttachment as isSyncAttachmentApi,
} from '../../../components/api';
import {
	getAllowedMimeTypeExtensions,
	getFrameProperty,
	getAllowedMimeTypes,
	restrictMediaFrameUploadTypes,
	showSnackbarNotice,
} from '../../../js/utils';

//
const UPLOAD_NONCE = window.OneMediaMediaFrame?.uploadNonce || '';
const ALLOWED_MIME_TYPES_MAP =
	typeof window.OneMediaMediaFrame?.allowedMimeTypesMap !== 'undefined'
		? window.OneMediaMediaFrame?.allowedMimeTypesMap
		: [];

const BrowserUploaderButton = ({
	onAddMediaSuccess,
	isSyncMediaUpload,
	attachmentId,
	addedMedia,
	setNotice,
}) => {
	const [isUploading, setIsUploading] = useState(false);
	const fileInputRef = useRef(null);

	const isReplaceMedia = !!attachmentId;

	let buttonText;
	if (isSyncMediaUpload) {
		buttonText = __('Add Sync Media', 'onemedia');
	} else if (isReplaceMedia) {
		buttonText = __('Replace Media', 'onemedia');
	} else {
		buttonText = __('Add Non Sync Media', 'onemedia');
	}

	const failedSitesMessage = (initialMessage, failedSites) => (
		<div>
			<span>
				{sprintf(
					/* translators: %s: initial message. */
					__(
						'%s Please check your connection for unreachable sites:',
						'onemedia'
					),
					initialMessage
				)}
			</span>
			{(failedSites || []).map((site, idx) => (
				<div key={idx}>
					<span>{site?.site_name}</span>
				</div>
			))}
		</div>
	);

	const handleButtonClick = async () => {
		// Media library not available
		if (!getFrameProperty('wp.media')) {
			setNotice({
				type: 'error',
				message: __('Media library is not available.', 'onemedia'),
			});
			fileInputRef.current?.click();
			return;
		}

		// Handle replace media
		if (isReplaceMedia && attachmentId) {
			const response = await checkIfAllSitesConnected(attachmentId);

			if (
				!response ||
				!response?.success ||
				response?.failed_sites?.length > 0
			) {
				showSnackbarNotice({
					type: 'error',
					message: failedSitesMessage(
						__('Failed to replace media.', 'onemedia'),
						response?.failed_sites
					),
				});
				return;
			}

			fileInputRef.current?.click();
			return;
		}

		// Invalid replace media state
		if (isReplaceMedia) {
			return;
		}

		// Handle sync media upload
		if (isSyncMediaUpload) {
			openSyncMediaFrame();
			return;
		}

		// Handle non-sync media upload
		openNonSyncMediaFrame();
	};

	const openSyncMediaFrame = () => {
		const frame = window.wp.media({
			title: __('Select Sync Media', 'onemedia'),
			button: {
				text: __('Select', 'onemedia'),
			},
			multiple: false,
			library: {
				type: getAllowedMimeTypes(ALLOWED_MIME_TYPES_MAP),
				is_onemedia_sync: false,
			},
		});

		restrictMediaFrameUploadTypes(
			frame,
			getAllowedMimeTypeExtensions(ALLOWED_MIME_TYPES_MAP).join(',')
		);

		frame.on('open', () => {
			const frameEl = frame.el;
			if (frameEl) {
				frameEl.classList.add('onemedia-select-sync-media-frame');
			}
		});

		frame.on('select', async () => {
			const selection = frame.state().get('selection');
			const attachment = selection.first().toJSON();

			if (!attachment || !attachment.url) {
				setNotice({
					type: 'error',
					message: __('No image selected.', 'onemedia'),
				});
				return;
			}

			setIsUploading(true);

			try {
				const healthCheckResponse = await checkIfAllSitesConnected(
					attachment.id
				);

				if (
					!healthCheckResponse ||
					!healthCheckResponse?.success ||
					healthCheckResponse?.failed_sites?.length > 0
				) {
					throw new Error(
						sprintf(
							/* translators: %s: list of failed sites. */
							__(
								'Media conversion failed for some sites: %s',
								'onemedia'
							),
							healthCheckResponse?.failed_sites
								?.map((site) => site?.site_name)
								.join(', ')
						)
					);
				}

				const response = await updateExistingAttachment(
					attachment.id,
					isSyncMediaUpload,
					setNotice
				);

				if (!response || !response.success) {
					setNotice({
						type: 'warning',
						message:
							response?.message ||
							__('Failed to update sync attachment.', 'onemedia'),
					});
					return;
				}

				if (onAddMediaSuccess) {
					onAddMediaSuccess();
				}

				setNotice({
					type: 'success',
					message:
						response?.message ||
						__('Sync media added successfully!', 'onemedia'),
				});
			} catch (error) {
				setNotice({
					type: 'error',
					message:
						error.message ||
						__('Failed to update sync attachment.', 'onemedia'),
				});
			} finally {
				setIsUploading(false);
			}
		});

		frame.on('close', () => {
			if (onAddMediaSuccess) {
				onAddMediaSuccess();
			}
		});

		frame.open();
	};

	const openNonSyncMediaFrame = () => {
		const frame = window.wp.media({
			title: __('Upload Non-Sync Media', 'onemedia'),
			button: {
				text: __('Add', 'onemedia'),
			},
			multiple: false,
			library: {
				type: getAllowedMimeTypes(ALLOWED_MIME_TYPES_MAP),
				is_onemedia_sync: false,
			},
		});

		restrictMediaFrameUploadTypes(
			frame,
			getAllowedMimeTypeExtensions(ALLOWED_MIME_TYPES_MAP).join(',')
		);

		frame.on('open', () => {
			const frameEl = frame.el;
			if (frameEl) {
				frameEl.classList.add('onemedia-select-non-sync-media-frame');
			}
		});

		frame.on('select', async () => {
			const selection = frame.state().get('selection');
			const attachment = selection.first().toJSON();

			if (!attachment || !attachment.url) {
				setNotice({
					type: 'error',
					message: __('No image selected.', 'onemedia'),
				});
				return;
			}

			const alreadyAdded = addedMedia?.some(
				(media) => media.id === attachment.id
			);
			if (alreadyAdded) {
				setNotice({
					type: 'warning',
					message: __('Media has been added already.', 'onemedia'),
				});
				return;
			}

			const isSyncAttachment = await isSyncAttachmentApi(
				attachment.id,
				setNotice
			);
			if (isSyncAttachment) {
				setNotice({
					type: 'warning',
					message: __(
						'Media is already added to "Sync Media" tab.',
						'onemedia'
					),
				});
				return;
			}

			setNotice({
				type: 'success',
				message: __('Media added successfully.', 'onemedia'),
			});
		});

		frame.on('close', () => {
			if (onAddMediaSuccess) {
				onAddMediaSuccess();
			}
		});

		frame.open();
	};
	const handleFileSelect = (event) => {
		const file = event.target.files[0];
		if (!file) {
			return;
		}

		const mimeTypes = getAllowedMimeTypes(ALLOWED_MIME_TYPES_MAP);

		// Validate file type.
		if (mimeTypes.length === 0 || !mimeTypes.includes(file.type)) {
			setNotice({
				type: 'error',
				message: __('Please select a valid image file.', 'onemedia'),
			});
			return;
		}

		// Start upload
		setIsUploading(true);
		uploadFile(file);
	};

	const uploadFile = async (file) => {
		// Create FormData for upload.
		const formData = new FormData();
		formData.append('file', file);
		formData.append('action', 'onemedia_replace_media');

		// Add current media ID for replacement.
		if (isReplaceMedia && attachmentId) {
			formData.append('current_media_id', attachmentId);
		}

		// Add WordPress nonce for security.
		if (UPLOAD_NONCE) {
			formData.append('_ajax_nonce', UPLOAD_NONCE);
		}

		try {
			// Upload to WordPress AJAX URL.
			const data = await uploadMedia(formData, setNotice);
			if (data && data?.success) {
				if (onAddMediaSuccess) {
					onAddMediaSuccess();
				}
				if (isReplaceMedia) {
					setNotice({
						type: 'success',
						message: __('Media replaced successfully!', 'onemedia'),
					});
				} else {
					setNotice({
						type: 'success',
						message: __('Media uploaded successfully!', 'onemedia'),
					});
				}
			} else {
				throw new Error(
					data.data.message || __('Upload failed', 'onemedia')
				);
			}
		} catch (error) {
			if (typeof setNotice === 'function') {
				setNotice({
					type: 'error',
					message:
						error.message ||
						__('An error occurred during upload.', 'onemedia'),
				});
			}
		} finally {
			setIsUploading(false);

			// Reset file input.
			if (fileInputRef.current) {
				fileInputRef.current.value = '';
			}
		}
	};

	return (
		<>
			{/* Hidden file input */}
			{!isSyncMediaUpload && (
				<input
					className="onemedia-hidden-file-input"
					type="file"
					accept={getAllowedMimeTypes(ALLOWED_MIME_TYPES_MAP).join(
						','
					)}
					ref={fileInputRef}
					onChange={handleFileSelect}
				/>
			)}

			{/* Upload button */}
			<Button
				variant="primary"
				onClick={handleButtonClick}
				isBusy={isUploading}
				disabled={isUploading}
			>
				{isUploading ? __('Adding…', 'onemedia') : buttonText}
			</Button>
		</>
	);
};

export default BrowserUploaderButton;
