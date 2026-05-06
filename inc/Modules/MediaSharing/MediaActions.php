<?php
/**
 * Handlers for various actions and filters related to Media Sharing.
 *
 * @package OneMedia\Modules\Post_Types;
 */

declare(strict_types = 1);

namespace OneMedia\Modules\MediaSharing;

use OneMedia\Contracts\Interfaces\Registrable;
use OneMedia\Modules\Rest\Abstract_REST_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Utils;

/**
 * Class Admin
 */
class MediaActions implements Registrable {
	/**
	 * Number of attachment versions to keep.
	 *
	 * @var int
	 */
	public const ATTACHMENT_VERSIONS_TO_KEEP = 5;

	/**
	 * Sync request timeout.
	 *
	 * @var int
	 */
	public const SYNC_REQUEST_TIMEOUT = 25;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Prevent updating attachment if connected sites are not available.
		add_action( 'pre_post_update', [ $this, 'pre_update_sync_attachments' ], 10, 1 );
		add_action( 'wp_ajax_save-attachment', [ $this, 'pre_update_sync_attachments_ajax' ], 0 );

		// Handle syncing the attachment metadata to brand sites for a sync media file.
		add_action( 'attachment_updated', [ $this, 'update_sync_attachments' ], 10, 1 );

		// Remove sync option if attachment is deleted.
		add_action( 'delete_attachment', [ $this, 'remove_sync_meta' ], 10, 1 );

		// Add replace media button to media library react view.
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_replace_media_button' ], 10, 2 );

		// Handle media replace.
		add_action( 'wp_ajax_onemedia_replace_media', [ $this, 'handle_media_replace' ] );

		// Add sync status to attachment data for JavaScript (Media Modal).
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'add_sync_meta' ], 10, 2 );
	}

	/**
	 * Add replace media button to media library react view.
	 *
	 * @param array<string, mixed> $form_fields Form fields.
	 * @param \WP_Post             $post        The WP_Post attachment object.
	 *
	 * @return array<string, mixed> Modified form fields.
	 */
	public function add_replace_media_button( array $form_fields, \WP_Post $post ): array {
		if ( Settings::is_consumer_site() ) {
			// Don't show replace media button on brand sites.
			return $form_fields;
		}

		// Don't show replace media button for non sync media.
		$show_replace_media = Attachment::is_sync_attachment( $post->ID );
		if ( ! $show_replace_media ) {
			return $form_fields;
		}

		$form_fields['replace_media'] = [
			'label' => __( 'Replace Media', 'onemedia' ),
			'input' => 'html',
			'html'  => sprintf(
			/* translators: %d is the post ID. */
				'<div class="replace-media-react-container" data-attachment-id="%d"></div>',
				$post->ID
			),
		];

		return $form_fields;
	}

	/**
	 * Remove sync meta when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function remove_sync_meta( int $attachment_id ): void {
		// On Governing Site.

		// Check post is_onemedia_sync is set to be true.
		$synced_brand_site_media = Settings::get_brand_sites_synced_media();
		if ( ! $synced_brand_site_media || ! isset( $synced_brand_site_media[ $attachment_id ] ) ) {
			return;
		}

		// Delete onemedia_sync_sites meta.
		delete_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY );

		// Delete is_onemedia_sync meta.
		delete_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY );

		// Delete onemedia_sync_status from remote sites.
		$synced_sites = $synced_brand_site_media[ $attachment_id ] ?? [];

		foreach ( $synced_sites as $site => $site_media_id ) {
			$site_url      = rtrim( $site, '/' );
			$site_media_id = (int) $site_media_id;

			if ( empty( $site_url ) || empty( $site_media_id ) ) {
				continue;
			}

			// Get site api key from options.
			$site_api_key = Settings::get_brand_site_api_key( $site_url );

			// Check if site api key is empty.
			if ( empty( $site_api_key ) ) {
				continue;
			}

			// Make POST request to delete attachment on brand sites.
			$response = wp_safe_remote_post(
				$site_url . '/wp-json/' . Abstract_REST_Controller::NAMESPACE . '/delete-media-metadata',
				[
					'body'      => wp_json_encode(
						[
							'attachment_id' => (int) $site_media_id,
						]
					) ?: '',
					'timeout'   => self::SYNC_REQUEST_TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
					'headers'   => [
						'Origin'           => get_site_url(),
						'X-OneMedia-Token' => $site_api_key,
						'Cache-Control'    => 'no-cache, no-store, must-revalidate',
					],
					'sslverify' => false,
				]
			);

			// Check if response is successful.
			if ( ! is_wp_error( $response ) ) {
				continue;
			}

			// Show notice in admin that media metadata deletion failed.
			add_action(
				'admin_notices',
				static function () use ( $site_url, $response ) {
					$error_message = $response->get_error_message();
					/* translators: %1$s is the site URL, %2$s is the error message. */
					echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Failed to delete media metadata on site %1$s: %2$s', 'onemedia' ), esc_html( $site_url ), esc_html( $error_message ) ) ) . '</p></div>';
				}
			);

			wp_die(
				sprintf(
				/* translators: %1$s is the site URL, %2$s is the error message. */
					esc_html__( 'Failed to delete media metadata on site %1$s: %2$s', 'onemedia' ),
					esc_html( $site_url ),
					esc_html( $response->get_error_message() )
				)
			);
		}

		// Delete synced media from options.
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- PHPUnit coverage marker.
		// @codeCoverageIgnoreStart
		if ( ! isset( $synced_brand_site_media[ $attachment_id ] ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		unset( $synced_brand_site_media[ $attachment_id ] );
		update_option( Settings::BRAND_SITES_SYNCED_MEDIA, $synced_brand_site_media );
	}

	/**
	 * Prevent updating attachment if connected sites are not available.
	 *
	 * @param int $post_id Post ID.
	 */
	public function pre_update_sync_attachments( int $post_id ): void {
		// Check if post type is attachment.
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		// Check post is_onemedia_sync is set to be true.
		$is_onemedia_sync = Attachment::is_sync_attachment( $post_id );

		if ( ! $is_onemedia_sync ) {
			return;
		}

		$health_check_connected_sites = Attachment::health_check_attachment_brand_sites( $post_id );
		$success                      = $health_check_connected_sites['success'] ?? false;

		// If any of the connected brand sites are not reachable, prevent updating the attachment.
		if ( $success ) {
			return;
		}

		$error_message = sprintf(
		/* translators: %s is the error message. */
			__( 'Failed to update media. %s', 'onemedia' ),
			( $health_check_connected_sites['message'] ?? __( 'Some connected brand sites are unreachable.', 'onemedia' ) )
		);
		wp_send_json_error(
			[
				'message' => $error_message,
			],
			500
		);
	}

	/**
	 * Prevent saving attachment if connected sites are not available.
	 */
	public function pre_update_sync_attachments_ajax(): void {
		$attachment_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

		check_ajax_referer( 'update-post_' . $attachment_id, 'nonce' );

		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You do not have permission to edit this attachment.', 'onemedia' ),
				],
				403
			);
		}

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || 'save-attachment' !== $action ) {
			return;
		}

		// Check post is_onemedia_sync is set to be true.
		$is_onemedia_sync = Attachment::is_sync_attachment( $attachment_id );

		if ( ! $is_onemedia_sync ) {
			return;
		}

		$health_check_connected_sites = Attachment::health_check_attachment_brand_sites( $attachment_id );
		$success                      = $health_check_connected_sites['success'] ?? false;

		// If any of the connected brand sites are not reachable, prevent updating the attachment.
		if ( $success ) {
			return;
		}

		$error_message = sprintf(
		/* translators: %s is the error message. */
			__( 'Failed to update media. %s', 'onemedia' ),
			( $health_check_connected_sites['message'] ?? __( 'Some connected brand sites are unreachable.', 'onemedia' ) )
		);
		wp_send_json_error(
			[
				'message' => $error_message,
			],
			500
		);
	}

	/**
	 * Update sync attachments on brand sites.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function update_sync_attachments( int $attachment_id ): void {
		// Check post is_onemedia_sync is set to be true.
		$is_onemedia_sync = Attachment::is_sync_attachment( $attachment_id );

		if ( ! $is_onemedia_sync ) {
			return;
		}

		// Get the brand sites this media is synced to.
		$onemedia_sync_sites = Attachment::get_sync_sites( $attachment_id );

		// POST request suffix.
		$post_request_suffix = '/wp-json/' . Abstract_REST_Controller::NAMESPACE . '/update-attachment';

		// Send updates to all sites.
		foreach ( $onemedia_sync_sites as $site ) {
			$site_url = $site['site'];
			// Trim trailing slash.
			$site_url      = rtrim( $site_url, '/' );
			$site_media_id = $site['id'];

			if ( empty( $site_url ) || empty( $site_media_id ) ) {
				continue;
			}

			// Get site api key from options.
			$site_api_key = Settings::get_brand_site_api_key( $site_url );

			// Check if site api key is empty.
			if ( empty( $site_api_key ) ) {
				return;
			}

			// Get update attachment data and its url.
			$attachment_url = wp_get_attachment_url( $attachment_id );

			$attachment_data = wp_get_attachment_metadata( $attachment_id );

			if ( ! $attachment_data || ! is_array( $attachment_data ) ) {
				$attachment_data = [];
			}

			// Get attachment title, alt text, caption and description.
			$attachment_title       = get_the_title( $attachment_id );
			$attachment_alt_text    = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$attachment_caption     = get_post_field( 'post_excerpt', $attachment_id );
			$attachment_description = get_post_field( 'post_content', $attachment_id );

			// Set attachment data.
			$attachment_data['title']       = $attachment_title;
			$attachment_data['alt_text']    = $attachment_alt_text;
			$attachment_data['caption']     = $attachment_caption;
			$attachment_data['description'] = $attachment_description;
			$attachment_data['is_sync']     = Attachment::is_sync_attachment( $attachment_id );

			// Make POST request to update existing attachment on brand sites.
			wp_safe_remote_post(
				$site_url . $post_request_suffix,
				[
					'body'    => [
						'attachment_id'   => (int) $site_media_id,
						'attachment_url'  => $attachment_url,
						'attachment_data' => $attachment_data,
					],
					'timeout' => self::SYNC_REQUEST_TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
					'headers' => [
						'Origin'           => get_site_url(),
						'X-OneMedia-Token' => $site_api_key,
						'Cache-Control'    => 'no-cache, no-store, must-revalidate',
					],
				]
			);
		}
	}

	/**
	 * Handle media replacement via AJAX on governing site.
	 */
	public function handle_media_replace(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'onemedia_upload_media' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to replace media.', 'onemedia' ) ], 403 );
		}

		// Check if this is a version restore operation.
		$is_version_restore = ! empty( $this->get_filtered_input( INPUT_POST, 'is_version_restore', FILTER_VALIDATE_BOOLEAN ) );

		// Get the file input.
		$input_file = isset( $_FILES['file'] ) && ! empty( $_FILES['file']['name'] ) ? wp_unslash( $_FILES['file'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized later in sanitize_file_input().

		if ( ! $input_file && $is_version_restore ) {
			$file_json = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized later in sanitize_file_input().
			if ( ! empty( $file_json ) ) {
				$decoded = json_decode( $file_json, true );
				if ( is_array( $decoded ) ) {
					$input_file = $decoded;
				}
			}

			if ( is_array( $input_file ) ) {
				$input_file['tmp_name'] = $input_file['path'] ?? '';
			}
		}

		// Sanitize file input.
		$file = $this->sanitize_file_input( $input_file );

		if ( is_wp_error( $file ) ) {
			wp_send_json_error( [ 'message' => $file->get_error_message() ], 400 );
		}

		// Get and validate media ID.
		$current_media_id = $this->get_filtered_input( INPUT_POST, 'current_media_id', FILTER_VALIDATE_INT );
		if ( empty( $current_media_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid media ID.', 'onemedia' ) ], 400 );
		}
		$current_media_id = absint( $current_media_id );

		if ( $is_version_restore ) {
			$result = $this->restore_attachment_version( $current_media_id, $file );
		} else {
			// Capture original file information before updating.
			$original_data = [
				'attachment' => get_post( $current_media_id ),
				'metadata'   => wp_get_attachment_metadata( $current_media_id ),
				'file_path'  => get_attached_file( $current_media_id ),
				'url'        => wp_get_attachment_url( $current_media_id ),
				'alt_text'   => get_post_meta( $current_media_id, '_wp_attachment_image_alt', true ),
				'caption'    => wp_get_attachment_caption( $current_media_id ),
			];

			// Update the attachment with the new file.
			$result = $this->update_attachment( $current_media_id, $file );

			// Update version history, add the new version to versions array.
			if ( ! is_wp_error( $result ) ) {
				$this->update_attachment_versions( $current_media_id, $file, $result, $original_data );
			}
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		// Return success response.
		wp_send_json_success(
			[
				'attachment_id' => $current_media_id,
				'message'       => __( 'Media replaced successfully.', 'onemedia' ),
			]
		);
	}

	/**
	 * Read a filtered input value.
	 *
	 * @param int                   $type     Input type.
	 * @param string                $var_name Input name.
	 * @param int                   $filter   Filter id.
	 * @param array<int, mixed>|int $options  Filter options.
	 */
	protected function get_filtered_input( int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0 ): mixed {
		return Utils::get_filtered_input( $type, $var_name, $filter, $options );
	}

	/**
	 * Process and sanitize file data from either $_FILES or $_POST.
	 *
	 * @param array<string, mixed>|null $input_file The raw input file data.
	 *
	 * @return array<string, mixed>|\WP_Error Sanitized file array or WP_Error on failure.
	 */
	public function sanitize_file_input( $input_file ): array|\WP_Error {
		// Verify file input exists.
		if ( ! isset( $input_file ) || empty( $input_file['name'] ) ) {
			return new \WP_Error( 'invalid_input', __( 'No file uploaded.', 'onemedia' ) );
		}

		// Sanitize all file fields.
		$file = [
			'name'     => isset( $input_file['name'] ) ? sanitize_file_name( $input_file['name'] ) : '',
			'type'     => isset( $input_file['type'] ) ? sanitize_mime_type( $input_file['type'] ) : '',
			'tmp_name' => isset( $input_file['tmp_name'] ) ? sanitize_text_field( $input_file['tmp_name'] ) : '',
			'error'    => isset( $input_file['error'] ) ? intval( $input_file['error'] ) : 0,
			'size'     => isset( $input_file['size'] ) ? intval( $input_file['size'] ) : 0,
		];

		if ( isset( $input_file['attachment_id'] ) ) {
			$file['attachment_id'] = intval( $input_file['attachment_id'] );
		}
		if ( isset( $input_file['path'] ) ) {
			$file['path'] = sanitize_text_field( $input_file['path'] );
		}
		if ( isset( $input_file['url'] ) ) {
			$file['url'] = esc_url_raw( $input_file['url'] );
		}
		if ( isset( $input_file['guid'] ) ) {
			$file['guid'] = esc_url_raw( $input_file['guid'] );
		}
		if ( isset( $input_file['filename'] ) ) {
			$file['filename'] = sanitize_file_name( $input_file['filename'] );
		}
		if ( isset( $input_file['mime_type'] ) ) {
			$file['mime_type'] = sanitize_mime_type( $input_file['mime_type'] );
		}
		if ( isset( $input_file['alt'] ) ) {
			$file['alt'] = sanitize_text_field( $input_file['alt'] );
		}
		if ( isset( $input_file['caption'] ) ) {
			$file['caption'] = sanitize_text_field( $input_file['caption'] );
		}
		if ( isset( $input_file['metadata'] ) && is_array( $input_file['metadata'] ) ) {
			$file['metadata'] = $input_file['metadata'];
		}
		if ( isset( $input_file['dimensions'] ) && is_array( $input_file['dimensions'] ) ) {
			$file['dimensions'] = $input_file['dimensions'];
		}
		if ( isset( $input_file['checksum'] ) ) {
			$file['checksum'] = sanitize_text_field( $input_file['checksum'] );
		}

		// Decode filename.
		$file['name'] = Utils::decode_filename( $file['name'] );

		// Validate mime type.
		if ( ! in_array( $file['type'], Utils::get_supported_mime_types(), true ) ) {
			return new \WP_Error(
				'invalid_file_type',
				__( 'Invalid file type. Only JPG, PNG, WEBP, BMP, SVG and GIF files are allowed.', 'onemedia' )
			);
		}

		return $file;
	}

	/**
	 * Update attachment with new file.
	 *
	 * @param int                  $attachment_id     The attachment ID.
	 * @param array<string, mixed> $file              The file data.
	 * @param bool                 $is_version_restore Whether this is a version restore operation.
	 * @param array<string, mixed> $version_data      Version data for restore operations.
	 *
	 * @return array<string, mixed>|\WP_Error Result data or WP_Error on failure.
	 */
	public function update_attachment( int $attachment_id, array $file, bool $is_version_restore = false, array $version_data = [] ): array|\WP_Error {
		// Get existing attachment data.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'invalid_attachment', __( 'Invalid attachment.', 'onemedia' ) );
		}

		// Get current file info.
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$caption  = wp_get_attachment_caption( $attachment_id );

		if ( $is_version_restore ) {
			// For version restore, use existing file path and data.
			if ( ! $version_data ) {
				return new \WP_Error( 'missing_version_data', __( 'Missing version data for restore operation.', 'onemedia' ) );
			}

			$file_data = $version_data['file'];

			// Check if file still exists at the saved location.
			if ( ! file_exists( $file_data['path'] ) ) {
				return new \WP_Error( 'file_not_found', __( 'This version file could not be found. It may have been deleted.', 'onemedia' ) );
			}

			$target_path = $file_data['path'];
			$new_url     = $file_data['url'];
			$mime_type   = $file_data['mime_type'] ?? $file_data['type'];
			$title       = sanitize_file_name( pathinfo( $file_data['name'], PATHINFO_FILENAME ) );

			// Use existing metadata from version history.
			$metadata = $file_data['metadata'] ?? [];
		} else {
			// For new uploads, process the uploaded file.
			$upload_dir  = wp_upload_dir();
			$filename    = wp_unique_filename( $upload_dir['path'], $file['name'] );
			$target_path = $upload_dir['path'] . '/' . $filename;
			$new_url     = $upload_dir['url'] . '/' . $filename;
			$mime_type   = $file['type'];
			$title       = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );

			// Move the uploaded file to the uploads directory.
			if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) { //phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
				return new \WP_Error( 'file_move_failed', __( 'Failed to move uploaded file.', 'onemedia' ) );
			}

			// Generate and update attachment metadata.
			include_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $target_path );
			if ( ! $metadata ) {
				return new \WP_Error( 'metadata_generation_failed', __( 'Failed to generate attachment metadata.', 'onemedia' ) );
			}
		}

		// Update attachment URL across posts.
		MediaReplacement::replace_image_across_all_post_types(
			$attachment_id,
			$new_url,
			$alt_text,
			$caption ?: '',
		);

		// Update attachment data.
		$attachment_data = [
			'ID'             => $attachment_id,
			'guid'           => $new_url,
			'post_mime_type' => $mime_type,
			'post_title'     => $title,
		];

		$result = wp_update_post( $attachment_data );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update attachment.', 'onemedia' ) );
		}

		// Update attachment metadata.
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Update the attachment file path.
		update_attached_file( $attachment_id, $target_path );

		// Update synced media on brand sites.
		$this->update_sync_attachments( $attachment_id );

		return [
			'attachment_id' => $attachment_id,
			'new_url'       => $new_url,
			'target_path'   => $target_path,
			'metadata'      => $metadata,
		];
	}

	/**
	 * Restore a specific version of an attachment.
	 *
	 * @param int                  $attachment_id The attachment ID.
	 * @param array<string, mixed> $version_file  The version file data to restore.
	 *
	 * @return array<string, mixed>|\WP_Error Result data or WP_Error on failure.
	 */
	public function restore_attachment_version( int $attachment_id, array $version_file ): array|\WP_Error {
		// Get existing versions.
		$existing_versions = Attachment::get_sync_attachment_versions( $attachment_id );
		$is_new_meta       = empty( $existing_versions );
		$existing_versions = array_values( $existing_versions );

		// Find the index of the version being restored.
		$restore_index = null;
		foreach ( $existing_versions as $index => $version ) {
			if ( isset( $version['file']['path'] ) && $version['file']['path'] === $version_file['path'] ) {
				$restore_index = $index;
				break;
			}
		}

		// If no versions exist or the specified version is not found, return error.
		if ( $is_new_meta || is_null( $restore_index ) ) {
			return new \WP_Error( 'no_version_history', __( 'No version history available for this attachment.', 'onemedia' ) );
		}

		// Get the version being restored.
		$restored_version = $existing_versions[ $restore_index ];

		// Update the attachment using the unified function (with version restore flag).
		$result = $this->update_attachment( $attachment_id, $version_file, true, $restored_version );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update timestamp for last used.
		$timestamp = time();

		// Update the versions list.
		if ( ! empty( $existing_versions ) ) {
			// Remove the restored version from its current position.
			if ( isset( $existing_versions[ $restore_index ] ) ) {
				unset( $existing_versions[ $restore_index ] );
			}

			// Update its timestamp.
			$restored_version['last_used'] = $timestamp;

			// Reindex and add restored version to the front.
			$existing_versions = array_values( $existing_versions );
			array_unshift( $existing_versions, $restored_version );

			// Keep only the 5 most recent versions.
			$existing_versions = array_slice( $existing_versions, 0, self::ATTACHMENT_VERSIONS_TO_KEEP );

			// Update version history.
			Attachment::update_sync_attachment_versions( $attachment_id, $existing_versions );
		}

		return $result;
	}

	/**
	 * Update attachment version history.
	 *
	 * @param int                  $attachment_id The attachment ID.
	 * @param array<string, mixed> $file          The file data.
	 * @param array<string, mixed> $update_result The result from update_attachment function.
	 * @param array<string, mixed> $original_data The original file information.
	 */
	public function update_attachment_versions( int $attachment_id, array $file, array $update_result, array $original_data ): void {
		// Get existing versions.
		$existing_versions = Attachment::get_sync_attachment_versions( $attachment_id );
		$is_new_meta       = empty( $existing_versions );
		$existing_versions = array_values( $existing_versions );

		// Original file information.
		$attachment   = $original_data['attachment'];
		$old_metadata = $original_data['metadata'];
		$current_file = $original_data['file_path'];
		$old_url      = $original_data['url'];
		$alt_text     = $original_data['alt_text'];
		$caption      = $original_data['caption'];

		// Add current timestamp.
		$timestamp = time();

		// Snapshot of the current (pre-replacement) file.
		$current_snapshot = [
			'last_used' => $timestamp,
			'file'      => [
				'attachment_id' => $attachment_id,
				'path'          => $current_file,
				'url'           => $old_url,
				'guid'          => is_object( $attachment ) && property_exists( $attachment, 'guid' ) ? $attachment->guid : $old_url,
				'name'          => wp_basename( $current_file ),
				'type'          => get_post_mime_type( $attachment_id ),
				'alt'           => $alt_text,
				'caption'       => $caption,
				'size'          => ( file_exists( $current_file ) ? (int) filesize( $current_file ) : 0 ),
				'metadata'      => is_array( $old_metadata ) ? $old_metadata : [],
				'dimensions'    => is_array( $old_metadata ) && isset( $old_metadata['width'], $old_metadata['height'] )
					? [
						'width'  => (int) $old_metadata['width'],
						'height' => (int) $old_metadata['height'],
					]
					: [],
				'checksum'      => ( file_exists( $current_file ) ? md5_file( $current_file ) : '' ),
			],
		];

		// Snapshot of the new file.
		$new_snapshot = [
			'last_used' => $timestamp,
			'file'      => [
				'attachment_id' => $attachment_id,
				'path'          => $update_result['target_path'],
				'url'           => $update_result['new_url'],
				'guid'          => $update_result['new_url'],
				'name'          => wp_basename( $update_result['target_path'] ),
				'type'          => $file['type'],
				'alt'           => $alt_text,
				'caption'       => $caption,
				'size'          => ( file_exists( $update_result['target_path'] ) ? (int) filesize( $update_result['target_path'] ) : (int) $file['size'] ),
				'metadata'      => $update_result['metadata'] ?? [],
				'dimensions'    => isset( $update_result['metadata']['width'], $update_result['metadata']['height'] )
					? [
						'width'  => (int) $update_result['metadata']['width'],
						'height' => (int) $update_result['metadata']['height'],
					]
					: [],
				'checksum'      => ( file_exists( $update_result['target_path'] ) ? md5_file( $update_result['target_path'] ) : '' ),
			],
		];

		if ( $is_new_meta ) {
			// First replacement: new (current) at 0, previous (old) at 1.
			$versions = [ $new_snapshot, $current_snapshot ];
		} else {
			// Subsequent replacement: new goes to index 0, others shift down.
			$versions = $existing_versions;
			array_unshift( $versions, $new_snapshot );
		}

		// Keep only the 5 most recent versions.
		$versions = array_slice( $versions, 0, self::ATTACHMENT_VERSIONS_TO_KEEP );

		Attachment::update_sync_attachment_versions( $attachment_id, $versions );
	}

	/**
	 * Add sync status meta to attachment data for JavaScript.
	 *
	 * @param array<string, mixed> $response   The prepared attachment data.
	 * @param \WP_Post             $attachment The attachment post object.
	 *
	 * @return array<string, mixed> Modified attachment data with sync status.
	 */
	public function add_sync_meta( $response, $attachment ) {
		// If attachment ID is not set, return original response.
		if ( empty( $attachment->ID ) ) {
			return $response;
		}

		// Add sync status to the response.
		$response['is_onemedia_sync'] = Attachment::is_sync_attachment( $attachment->ID );

		return $response;
	}
}
