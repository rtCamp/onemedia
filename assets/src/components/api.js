/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { removeTrailingSlash } from '../js/utils';
const ONEMEDIA_REST_API_NAMESPACE = 'onemedia';
const ONEMEDIA_REST_API_VERSION = 'v1';
const ONEMEDIA_REST_API_BASE =
	'/wp-json/' + ONEMEDIA_REST_API_NAMESPACE + '/' + ONEMEDIA_REST_API_VERSION;
const {
	restUrl: REST_URL,
	restNonce: NONCE,
	apiKey: API_KEY,
	ajaxUrl: SHARING_AJAX_URL,
} = window.OneMediaMediaFrame || {};

/**
 * Makes a REST API request to the OneMedia backend.
 *
 * @param {Object}   options                    - Request options.
 * @param {string}   [options.baseurl=REST_URL] - Base URL for the REST API.
 * @param {string}   options.endpoint           - API endpoint.
 * @param {string}   [options.method='GET']     - HTTP method.
 * @param {string}   [options.nonce='NONCE']    - HTTP nonce.
 * @param {string}   [options.apiKey='API_KEY'] - API key.
 * @param {Object}   [options.body]             - Request body.
 * @param {Function} [options.addNotice]        - Function to display notices.
 * @param {string}   [options.errorMsg]         - Custom error message.
 * @param {Object}   [options.params={}]        - Query parameters.
 * @return {Promise<Object|null>} - API response data or null on error.
 */
export const apiFetch = async ({
	baseurl = REST_URL,
	endpoint,
	method = 'GET',
	nonce = NONCE,
	apiKey = API_KEY,
	body,
	addNotice,
	errorMsg,
	params = {},
}) => {
	try {
		let url = `${removeTrailingSlash(baseurl)}${ONEMEDIA_REST_API_BASE}/${endpoint}`;
		// Add params to URL if provided.
		if (params && Object.keys(params).length > 0) {
			const searchParams = new URLSearchParams(params).toString();
			url += `?${searchParams}`;
		}
		const headers = {
			'Content-Type': 'application/json',
			'X-OneMedia-Token': apiKey,
		};

		if ('' !== nonce) {
			headers['X-WP-Nonce'] = nonce;
		}

		const response = await fetch(url, {
			method,
			headers,
			body: body ? JSON.stringify(body) : undefined,
		});
		const responseData = await response.json();
		if (!response.ok) {
			let message =
				responseData?.message ||
				response?.message ||
				errorMsg ||
				__('An unexpected error occurred', 'onemedia');
			if (404 === response.status) {
				message = __('Resource not found', 'onemedia');
			}
			if (addNotice) {
				addNotice({
					type: 'error',
					message,
				});
			}
			// Return the error response for handling in the caller.
			return {
				...responseData,
				message,
				success: responseData?.data?.success || false,
			};
		}
		return await responseData;
	} catch (error) {
		const message =
			errorMsg || error.message || __('An error occurred', 'onemedia');
		if (addNotice) {
			addNotice({
				type: 'error',
				message,
			});
		}
		return { message, success: false };
	}
};

/**
 * Fetches the list of brand sites from the backend.
 *
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Array>} - Array of brand site objects.
 */
export const fetchBrandSites = async (addNotice) => {
	const data = await apiFetch({
		endpoint: 'shared-sites',
		addNotice,
		errorMsg: __('Error fetching brand sites.', 'onemedia'),
	});
	const sites = (data?.shared_sites || []).map((site) => ({
		...site,
		id: String(site.id),
	}));
	return sites;
};

/**
 * Performs a health check for a brand site from the governing site.
 *
 * @param {string}   url       - Site URL.
 * @param {string}   apiKey    - API key for the site.
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Object|null>} - Health check result or null on error.
 */
export const checkBrandSiteHealth = async (url, apiKey, addNotice) => {
	const response = await apiFetch({
		baseurl: removeTrailingSlash(url) + '/wp-json',
		endpoint: 'health-check',
		method: 'GET',
		nonce: '', // No nonce for cross-site requests.
		apiKey,
		addNotice,
		errorMsg: __(
			'Health check failed. Please ensure the site is accessible.',
			'onemedia'
		),
	});
	return response;
};

/**
 * Checks if all sites are connected for a given attachment.
 *
 * @param {number} attachmentId - Attachment ID to check.
 * @return {Promise<boolean>} - True if all sites are connected, false otherwise.
 */
export const checkIfAllSitesConnected = async (attachmentId) => {
	const response = await apiFetch({
		endpoint: 'check-sites-connected',
		method: 'POST',
		body: {
			attachment_id: attachmentId,
		},
		errorMsg: __('Failed to check connected sites.', 'onemedia'),
	});
	return response;
};

/**
 * Fetches the multisite type.
 *
 * @return {Promise<string>} - Multisite type string (single, subdomain or subdirectory).
 */
export const fetchMultisiteType = async () => {
	const response = await apiFetch({
		endpoint: 'multisite-type',
		method: 'GET',
		errorMsg: __('Failed to fetch multisite type.', 'onemedia'),
	});
	return response?.multisite_type || '';
};

/**
 * Fetches the current site type (governing or brand).
 *
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<string>} - Site type string.
 */
export const fetchSiteType = async (addNotice) => {
	const data = await apiFetch({
		endpoint: 'site-type',
		addNotice,
		errorMsg: __('Error fetching site types.', 'onemedia'),
	});
	return data?.site_type || '';
};

/**
 * Saves the selected site type to the backend.
 *
 * @param {string}   siteType  - Site type to save.
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<boolean>} - True if saved successfully.
 */
export const saveSiteType = async (siteType, addNotice) => {
	const result = await apiFetch({
		endpoint: 'site-type',
		method: 'POST',
		body: { site_type: siteType },
		addNotice,
		errorMsg: __('Error saving site type.', 'onemedia'),
	});
	return !!result;
};

/**
 * Saves the list of brand sites to the backend.
 *
 * @param {Array}    sites     - Array of brand site objects.
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Object>} - API response.
 */
export const saveBrandSites = async (sites, addNotice) => {
	const result = await apiFetch({
		endpoint: 'brand-sites',
		method: 'POST',
		body: {
			sites,
		},
		addNotice,
		errorMsg: __('Error saving Brand sites.', 'onemedia'),
	});
	return result;
};

/**
 * Fetches the API key for the brand site.
 *
 * @return {Promise<string|null>} - API key string or null on error.
 */
export const fetchBrandSiteApiKey = async () => {
	const data = await apiFetch({
		endpoint: 'secret-key',
		method: 'GET',
		errorMsg: __('Failed to fetch api key.', 'onemedia'),
	});
	return data?.secret_key || null;
};

/**
 * Regenerates the API key for the brand site.
 *
 * @return {Promise<string|null>} - New API key string or null on error.
 */
export const regenerateBrandSiteApiKey = async () => {
	const data = await apiFetch({
		endpoint: 'secret-key',
		method: 'POST',
		errorMsg: __('Failed to regenerate API key.', 'onemedia'),
	});
	return data?.secret_key || null;
};

/**
 * Fetches the list of sites where media is synced.
 *
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Array>} - Array of synced site objects.
 */
export const fetchSyncedSites = async (addNotice) => {
	const data = await apiFetch({
		endpoint: 'brand-sites-synced-media',
		addNotice,
		errorMsg: __('Failed to fetch synced sites.', 'onemedia'),
	});
	const sites = data?.data || [];
	return sites;
};

/**
 * Fetches media items with pagination and optional filtering.
 *
 * @param {Object}   options             - Options for fetching media.
 * @param {string}   [options.search]    - Search term.
 * @param {number}   [options.page]      - Page number.
 * @param {number}   [options.perPage]   - Items per page.
 * @param {string}   [options.imageType] - Filter by image type.
 * @param {Function} [options.addNotice] - Function to display notices.
 * @return {Promise<Object>} - Media items response.
 */
export const fetchMediaItems = async ({
	search,
	page,
	perPage,
	imageType,
	addNotice,
}) => {
	const params = {};
	if (page) {
		params.page = page;
	}
	if (perPage) {
		params.per_page = perPage;
	}
	if (imageType) {
		params.image_type = imageType;
	}
	if (search) {
		params.search_term = search;
	}

	return await apiFetch({
		endpoint: 'media',
		params,
		addNotice,
		errorMsg: __('Failed to fetch media items.', 'onemedia'),
	});
};

/**
 * Shares media with brand sites (sync operation).
 *
 * @param {Object}   payload   - Data to send for sharing media.
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Object>} - API response.
 */
export const shareMedia = async (payload, addNotice) => {
	return await apiFetch({
		endpoint: 'sync-media',
		method: 'POST',
		body: payload,
		addNotice,
		errorMsg: __('Failed to sync media.', 'onemedia'),
	});
};

/**
 * Uploads a media file to the backend.
 *
 * @param {FormData} formData  - Form data containing the file.
 * @param {Function} addNotice - Function to display notices.
 * @return {Promise<Object>} - Upload response.
 */
export const uploadMedia = async (formData, addNotice) => {
	try {
		const url = `${SHARING_AJAX_URL}`;

		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'X-OneMedia-Token': API_KEY,
			},
			credentials: 'same-origin',
			body: formData,
		});

		const data = await response.json();

		if (!response.ok || 404 === response?.status) {
			if (data?.data?.message) {
				throw new Error(data.data.message);
			}
		}
		return data;
	} catch (error) {
		if (addNotice) {
			addNotice({
				type: 'error',
				message:
					error.message ||
					__('An error occurred during media upload', 'onemedia'),
			});
		}
		return null;
	}
};

/**
 * Updates an existing media attachment as sync or non-sync.
 *
 * @param {number}   attachmentId      - ID of the attachment to update.
 * @param {boolean}  isSyncMediaUpload - Whether to mark as sync media.
 * @param {Function} addNotice         - Function to display notices.
 * @return {Promise<Object>} - API response.
 */
export const updateExistingAttachment = async (
	attachmentId,
	isSyncMediaUpload,
	addNotice
) => {
	return await apiFetch({
		endpoint: 'update-existing-attachment',
		method: 'POST',
		body: {
			attachment_id: attachmentId,
			sync_option: isSyncMediaUpload ? 'sync' : 'no_sync',
		},
		addNotice,
		errorMsg: __('Failed to update existing attachment.', 'onemedia'),
	});
};

/**
 * Fetches the current governing site URL for the brand site.
 *
 * @return {Promise<string|null>} - Governing site URL or null on error.
 */
export const fetchGoverningSite = async () => {
	const data = await apiFetch({
		endpoint: 'governing-site',
		method: 'GET',
	});
	return data?.governing_site_url || null;
};

/**
 * Removes the governing site connection for the brand site.
 *
 * @return {Promise<boolean>} - True if disconnected successfully, false otherwise.
 */
export const removeGoverningSite = async () => {
	const data = await apiFetch({
		endpoint: 'governing-site',
		method: 'DELETE',
	});
	return !!data;
};

/**
 * Checks if an attachment is a sync attachment.
 *
 * @param {number}   attachmentId - Attachment ID to check.
 * @param {Function} addNotice    - Function to display notices.
 * @return {Promise<boolean>} - True if sync attachment, false otherwise.
 */
export const isSyncAttachment = async (attachmentId, addNotice) => {
	const data = await apiFetch({
		endpoint: `is-sync-attachment`,
		method: 'POST',
		body: {
			attachment_id: Number(attachmentId),
		},
		addNotice,
		errorMsg: __('Failed to check sync status.', 'onemedia'),
	});
	return data?.is_sync || false;
};

/**
 * Fetches the versions of a sync attachment.
 *
 * @param {number} attachmentId - Attachment ID to fetch versions for.
 * @return {Promise<Array>} - Array of version objects.
 */
export const fetchSyncAttachmentVersions = async (attachmentId) => {
	const data = await apiFetch({
		endpoint: `sync-attachment-versions`,
		method: 'POST',
		body: {
			attachment_id: Number(attachmentId),
		},
		errorMsg: __('Failed to fetch attachment versions.', 'onemedia'),
	});
	return data || [];
};
