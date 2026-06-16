/**
 * Internal dependencies
 */
import type { MimeTypeMap } from './media-sharing';
import type { SiteType } from './settings';
import type { WPMediaAttachmentModel, WPMediaFrame } from './wordpress';

interface OneMediaSettingsConfig {
	restUrl: string;
	restNonce: string;
	api_key: string;
	settingsLink: string;
	siteType: SiteType;
	restNamespace?: string;
	nonce?: string;
	currentSiteUrl?: string;
}

interface OneMediaOnboardingConfig {
	nonce: string;
	site_type: SiteType;
	setup_url: string;
}

interface OneMediaMediaFrameConfig {
	restUrl: string;
	restNonce: string;
	apiKey: string;
	ajaxUrl: string;
	uploadNonce?: string;
	allowedMimeTypesMap?: MimeTypeMap;
	siteType: SiteType;
}

interface OneMediaMediaSharingConfig {
	uploadNonce?: string;
}

interface OneMediaMediaUploadConfig {
	isMediaPage?: boolean;
	allLabel?: string;
	syncLabel?: string;
	notSyncLabel?: string;
	nonce?: string;
	syncStatus?: string;
}

interface OneMediaWordPressGlobal {
	Uploader: {
		queue: unknown;
	};
	media: {
		( options: Record< string, unknown > ): WPMediaFrame;
		attachment: ( id: number | string ) => WPMediaAttachmentModel;
	};
}

declare global {
	interface Window {
		OneMediaSettings: OneMediaSettingsConfig;
		OneMediaOnboarding: OneMediaOnboardingConfig;
		OneMediaMediaFrame: OneMediaMediaFrameConfig;
		OneMediaMediaSharing: OneMediaMediaSharingConfig;
		OneMediaMediaUpload?: OneMediaMediaUploadConfig;
		wp: OneMediaWordPressGlobal;
	}
}

export {};
