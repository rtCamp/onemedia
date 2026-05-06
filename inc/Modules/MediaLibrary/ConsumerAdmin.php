<?php
/**
 * Handles Media Library admin restrictions for Consumer Sites.
 *
 * All hooks are skipped automatically when running on the Governing Site.
 *
 * @package OneMedia\Modules\Post_Types;
 */

declare(strict_types = 1);

namespace OneMedia\Modules\MediaLibrary;

use OneMedia\Contracts\Interfaces\Registrable;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Utils;

/**
 * Class Admin
 */
class ConsumerAdmin implements Registrable {
	/**
	 * Transient key for deletion notice.
	 *
	 * @var string
	 */
	private const DELETION_NOTICE_TRANSIENT = 'onemedia_sync_delete_notice';

	/**
	 * Transient key for edit notice.
	 *
	 * @var string
	 */
	private const EDIT_NOTICE_TRANSIENT = 'onemedia_sync_edit_notice';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {

		// Skip if not a brand site.
		if ( Settings::is_governing_site() ) {
			return;
		}

		// Prevent attachment deletion if media is synced.
		add_filter( 'delete_attachment', [ $this, 'prevent_attachment_deletion' ], 10, 2 );

		// Admin notice for attachment deletion.
		add_action( 'admin_notices', [ $this, 'show_deletion_notice' ] );

		// Prevent attachment edit if media is synced.
		add_action( 'load-post.php', [ $this, 'prevent_attachment_edit' ] );
		add_action( 'wp_ajax_save-attachment', [ $this, 'prevent_save_attachment_ajax' ], 0 );

		// Remove edit & delete links for synced attachments.
		add_filter( 'media_row_actions', [ $this, 'remove_edit_delete_links' ], 10, 2 );
	}

	/**
	 * Prevent attachment deletion.
	 *
	 * @param bool          $check Whether to allow deletion.
	 * @param \WP_Post|null $post  Post object.
	 *
	 * @return bool Whether to allow deletion.
	 */
	public function prevent_attachment_deletion( bool $check, \WP_Post|null $post ): bool {
		// Only check for attachments.
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $check;
		}

		// Check if attachment is synced.
		$is_sync = Attachment::is_sync_attachment( $post->ID );
		if ( $is_sync ) {
			set_transient( self::DELETION_NOTICE_TRANSIENT, true, 30 );
			// Redirect back to prevent deletion.
			$redirect_url = admin_url( 'upload.php' );
			wp_safe_redirect( $redirect_url );
			// @codeCoverageIgnoreStart
			exit;
			// @codeCoverageIgnoreEnd
		}

		return $check;
	}

	/**
	 * Show admin notice for attachment deletion.
	 */
	public function show_deletion_notice(): void {
		// Check for delete notice transient.
		if ( get_transient( self::DELETION_NOTICE_TRANSIENT ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'This file is synced from Governing Site, please delete it from there first.', 'onemedia' ); ?></p>
			</div>
			<?php
			// Delete the transient so the notice only shows once.
			delete_transient( self::DELETION_NOTICE_TRANSIENT );
		}

		// Check for edit notice transient.
		if ( ! get_transient( self::EDIT_NOTICE_TRANSIENT ) ) {
			return;
		}

		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'This file is synced from Governing site, please edit it over there.', 'onemedia' ); ?></p>
		</div>
		<?php
		// Delete the transient so the notice only shows once.
		delete_transient( self::EDIT_NOTICE_TRANSIENT );
	}

	/**
	 * Prevent attachment edit.
	 */
	public function prevent_attachment_edit(): void {
		$post_id = $this->get_filtered_input( INPUT_GET, 'post', FILTER_VALIDATE_INT );
		$post_id = isset( $post_id ) ? intval( $post_id ) : 0;

		// Only check for attachments.
		if ( empty( $post_id ) || 'attachment' !== get_post_type( $post_id ) ) {
			return;
		}

		// Check if we're on the attachment edit screen.
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || 'attachment' !== $screen->post_type ) {
			return;
		}

		// Check if attachment is synced.
		$is_sync = Attachment::is_sync_attachment( $post_id );
		if ( ! $is_sync ) {
			return;
		}

		// Set transient to show admin notice for edit.
		set_transient( self::EDIT_NOTICE_TRANSIENT, true, 30 );

		// Redirect back to media library.
		$redirect_url = admin_url( 'upload.php' );
		wp_safe_redirect( $redirect_url );
		// @codeCoverageIgnoreStart
		exit;
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Prevent save_attachment AJAX and block editing for synced attachments.
	 */
	public function prevent_save_attachment_ajax(): void {
		$attachment_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

		check_ajax_referer( 'update-post_' . $attachment_id, 'nonce' );

		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error();
		}

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || 'save-attachment' !== $action ) {
			return;
		}

		$is_sync = Attachment::is_sync_attachment( $attachment_id );
		if ( ! $is_sync ) {
			return;
		}

		set_transient( self::EDIT_NOTICE_TRANSIENT, true, 30 );
		wp_send_json_error(
			[
				'message' => __( 'This file is synced from the Governing Site, please edit it over there.', 'onemedia' ),
			],
			500
		);
	}

	/**
	 * Remove edit and delete links for synced attachments.
	 *
	 * @param string[] $actions Array of action links.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return string[] Modified actions.
	 */
	public function remove_edit_delete_links( $actions, $post ) {
		// Only check for attachments.
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return $actions;
		}

		// Check if attachment is synced.
		$is_sync = Attachment::is_sync_attachment( $post->ID );
		if ( ! $is_sync ) {
			return $actions;
		}

		// Remove edit links.
		if ( isset( $actions['edit'] ) ) {
			unset( $actions['edit'] );
		}

		// Remove delete links.
		if ( isset( $actions['delete'] ) ) {
			unset( $actions['delete'] );
		}

		return $actions;
	}

	/**
	 * Read a filtered input value.
	 *
	 * @param int                   $type     Input type.
	 * @param string                $var_name Input name.
	 * @param int                   $filter   Filter id.
	 * @param array<int, mixed>|int $options  Filter options.
	 *
	 * @codeCoverageIgnore
	 */
	protected function get_filtered_input( int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0 ): mixed {
		return Utils::get_filtered_input( $type, $var_name, $filter, $options );
	}
}
