<?php
/**
 * Enqueue assets for OneMedia.
 *
 * @package OneMedia
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\Core;

use OneMedia\Contracts\Interfaces\Registrable;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Utils;

/**
 * Class Assets
 */
final class Assets implements Registrable {
	/**
	 * The relative path to the built assets directory.
	 * No preceding or trailing slashes.
	 */
	private const ASSETS_DIR = 'build';

	/**
	 * Prefix for all asset handles.
	 */
	private const PREFIX = 'onemedia-';

	/**
	 * Asset handles
	 */
	public const ADMIN_STYLES_HANDLE             = self::PREFIX . 'admin';
	public const SETTINGS_SCRIPT_HANDLE          = self::PREFIX . 'settings';
	public const ONBOARDING_SCRIPT_HANDLE        = self::PREFIX . 'onboarding';
	public const MEDIA_SHARING_SCRIPT_HANDLE     = self::PREFIX . 'media-sharing';
	public const MAIN_STYLE_HANDLE               = self::PREFIX . 'main';
	public const MEDIA_FRAME_SCRIPT_HANDLE       = self::PREFIX . 'media-frame';
	public const MEDIA_SYNC_FILTER_SCRIPT_HANDLE = self::PREFIX . 'media-sync-filter';
	public const MEDIA_TAXONOMY_STYLE_HANDLE     = self::PREFIX . 'media-taxonomy';

	/**
	 * Localized data for scripts.
	 *
	 * @var array<string,mixed>
	 */
	private static array $localized_data;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Prepare localized data.
	 *
	 * @return array<string, mixed> Localized data passed to JavaScript.
	 */
	public static function get_localized_data(): array {
		if ( empty( self::$localized_data ) ) {
			self::$localized_data = [
				'restUrl'             => esc_url( home_url( '/wp-json' ) ),
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'apiKey'              => Settings::get_api_key(),
				'settingsLink'        => esc_url( admin_url( 'admin.php?page=onemedia-settings' ) ),
				'siteType'            => Settings::get_site_type(),
				'siteName'            => get_bloginfo( 'name' ),
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'uploadNonce'         => wp_create_nonce( 'onemedia_upload_media' ),
				'allowedMimeTypesMap' => Utils::get_supported_mime_types(),
				'mediaSyncNonce'      => wp_create_nonce( 'onemedia_check_sync_status' ),
				'allLabel'            => __( 'All media', 'onemedia' ),
				'syncLabel'           => __( 'Synced', 'onemedia' ),
				'notSyncLabel'        => __( 'Not Synced', 'onemedia' ),
				'filterLabel'         => __( 'Sync Status', 'onemedia' ),
				'syncStatus'          => Attachment::SYNC_STATUS_POSTMETA_KEY,
			];
		}

		return self::$localized_data;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_dir = (string) ONEMEDIA_DIR;
		$this->plugin_url = (string) ONEMEDIA_URL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Add defer attribute to certain plugin bundles to improve admin load performance.
		add_filter( 'script_loader_tag', [ $this, 'defer_scripts' ], 10, 2 );
	}

	/**
	 * Register admin assets to WordPress.
	 *
	 * Assets are registered once centrally, and enqueued in the modules that need them.
	 */
	public function register_assets(): void {
		// Register scripts related to media sharing page.
		$this->register_script( self::MEDIA_SHARING_SCRIPT_HANDLE, 'media-sharing' );
		$this->register_style( self::MAIN_STYLE_HANDLE, 'main' );
		$this->register_script( self::MEDIA_FRAME_SCRIPT_HANDLE, 'media-frame' );

		// Register scripts related to media library.
		$this->register_script( self::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'media-sync-filter' );
		$this->register_style( self::MEDIA_TAXONOMY_STYLE_HANDLE, 'media-taxonomy', );

		$this->register_script( self::SETTINGS_SCRIPT_HANDLE, 'settings' );
		$this->register_style( self::SETTINGS_SCRIPT_HANDLE, 'settings', [ 'wp-components' ] );

		$this->register_script( self::ONBOARDING_SCRIPT_HANDLE, 'onboarding' );
		$this->register_style( self::ONBOARDING_SCRIPT_HANDLE, 'onboarding', [ 'wp-components' ] );

		$this->register_style( self::ADMIN_STYLES_HANDLE, 'admin', [ 'wp-components' ] );
	}

	/**
	 * Add scripts and styles to the page.
	 */
	public function enqueue_scripts(): void {
		// @todo Only enqueue on OneMedia admin pages.
		wp_enqueue_style( self::ADMIN_STYLES_HANDLE );
	}

	/**
	 * Add defer attribute to certain plugin bundle scripts to improve loading performance.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return string Modified script tag.
	 */
	public function defer_scripts( string $tag, string $handle ): string {
		$defer_handles = [
			self::SETTINGS_SCRIPT_HANDLE,
		];

		// Bail if we don't need to defer.
		if ( ! in_array( $handle, $defer_handles, true ) || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src', ' defer src', $tag );
	}

	/**
	 * Register a script.
	 *
	 * @param string   $handle        Name of the script. Should be unique.
	 * @param string   $filename      Path of the script relative to js directory.
	 *                                excluding the .js extension.
	 * @param string[] $deps          Optional. An array of registered script handles this script depends on. If not set, the dependencies will be inherited from the asset file.
	 * @param ?string  $ver           Optional. String specifying script version number, if not set, the version will be inherited from the asset file.
	 * @param bool     $in_footer     Optional. Whether to enqueue the script before </body> instead of in the <head>.
	 */
	private function register_script( string $handle, string $filename, array $deps = [], $ver = null, bool $in_footer = true ): bool {
		$asset_file = sprintf( '%s/%s.asset.php', $this->plugin_dir . untrailingslashit( self::ASSETS_DIR ), $filename );

		// Bail if the asset file does not exist. Log error and optionally show admin notice.
		if ( ! file_exists( $asset_file ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- The file is checked for existence above.
		$asset = require_once $asset_file;

		$version   = $ver ?? ( $asset['version'] ?? filemtime( $asset_file ) );
		$asset_src = sprintf( '%s/%s.js', $this->plugin_url . untrailingslashit( self::ASSETS_DIR ), $filename );

		return wp_register_script(
			$handle,
			$asset_src,
			$deps ?: $asset['dependencies'],
			$version ?: false,
			$in_footer
		);
	}

	/**
	 * Register a CSS stylesheet
	 *
	 * @param string   $handle        Name of the stylesheet. Should be unique.
	 * @param string   $filename      Path of the stylesheet relative to the css directory,
	 *                                excluding the .css extension.
	 * @param string[] $deps          Optional. An array of registered stylesheet handles this stylesheet depends on. Default empty array.
	 * @param ?string  $ver           Optional. String specifying style version number, if not set, the version will be inherited from the asset file.
	 *
	 * @param string   $media         Optional. The media for which this stylesheet has been defined.
	 *                                Default 'all'. Accepts media types like 'all', 'print' and 'screen', or media queries like
	 *                                '(orientation: portrait)' and '(max-width: 640px)'.
	 */
	private function register_style( string $handle, string $filename, array $deps = [], $ver = null, string $media = 'all' ): bool {
		// CSS doesnt have a PHP assets file so we infer from the file itself.
		$asset_file = sprintf( '%s/%s.css', $this->plugin_dir . untrailingslashit( self::ASSETS_DIR ), $filename );

		// Bail if the asset file does not exist.
		if ( ! file_exists( $asset_file ) ) {
			return false;
		}

		$version   = $ver ?? (string) filemtime( $asset_file );
		$asset_src = sprintf( '%s/%s.css', $this->plugin_url . untrailingslashit( self::ASSETS_DIR ), $filename );

		// Register as a style.
		return wp_register_style(
			$handle,
			$asset_src,
			$deps,
			$version ?: false,
			$media
		);
	}
}
