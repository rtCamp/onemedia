<?php
/**
 * Admin class to handle all the admin functionalities related to MediaLibrary.
 *
 * @package OneMedia\Modules\Post_Types;
 */

declare(strict_types = 1);

namespace OneMedia\Modules\MediaLibrary;

use OneMedia\Contracts\Interfaces\Registrable;
use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Utils;

/**
 * Class Admin
 */
class Admin implements Registrable {
	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Run after Core/Admin hooks so screen context and dependencies are fully available.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ], 20 );

		add_filter( 'ajax_query_attachments_args', [ $this,'filter_ajax_query_attachments_args' ] );

		// Create media filter for synced attachments.
		add_action( 'restrict_manage_posts', [ $this, 'add_sync_filter' ] );
		add_action( 'parse_query', [ $this, 'filter_sync_attachments' ] );
	}

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_scripts(): void {
		$current_screen = get_current_screen();

		if ( ! $current_screen instanceof \WP_Screen ) {
			return;
		}

		if ( in_array( $current_screen->id, [ 'upload', 'edit-onemedia_media_type' ], true ) ) {
			wp_enqueue_style( Assets::MEDIA_TAXONOMY_STYLE_HANDLE );
		}

		if ( 'upload' !== $current_screen->id && ! Settings::is_consumer_site() ) {
			return;
		}

		wp_localize_script(
			Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE,
			'OneMediaMediaUpload',
			array_merge(
				Assets::get_localized_data(),
				[
					'isMediaPage' => (bool) ( is_admin() && 'upload' === $current_screen->id ),
				]
			)
		);

		// Required scripts for showing sync filter in media library.
		wp_enqueue_script( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE );

		wp_localize_script(
			Assets::MEDIA_FRAME_SCRIPT_HANDLE,
			'OneMediaMediaFrame',
			Assets::get_localized_data(),
		);

		// Shows sync status in media library.
		wp_enqueue_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE );
	}

	/**
	 * Handle sync status filter in Ajax requests for media library.
	 *
	 * @param array<string, mixed> $query WordPress query arguments.
	 *
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_ajax_query_attachments_args( array $query ): array {

		// Handle the meta_query passed from our JavaScript.
		if ( isset( $query['meta_query'] ) ) {
			return $query;
		}

		// Nonce verification for AJAX requests.
		if ( wp_doing_ajax() && isset( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'onemedia_check_sync_status' ) ) {
				return $query;
			}
		}

		// Handle direct URL parameter for grid mode.
		$request_query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? array_map( 'sanitize_text_field', $_REQUEST['query'] ) : [];
		if ( ! empty( $request_query['onemedia_sync_status'] ) ) {
			$sync_status = sanitize_text_field( wp_unslash( $request_query['onemedia_sync_status'] ) );

			if ( Attachment::SYNC_STATUS_SYNC === $sync_status ) {
				$query['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '1',
						'compare' => '=',
					],
				];
			} elseif ( Attachment::SYNC_STATUS_NO_SYNC === $sync_status ) {
				$query['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '0',
						'compare' => '=',
					],
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'compare' => 'NOT EXISTS',
					],
				];
			}
		}

		// check for is_onemedia_sync meta filter.
		if ( ! empty( $request_query['is_onemedia_sync'] ) ) {
			$is_onemedia_sync = filter_var( $request_query['is_onemedia_sync'], FILTER_VALIDATE_BOOLEAN );

			if ( true === $is_onemedia_sync ) {
				$query['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '1',
						'compare' => '=',
					],
				];
			} else {
				$query['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '0',
						'compare' => '=',
					],
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'compare' => 'NOT EXISTS',
					],
				];
			}
		}

		return $query;
	}

	/**
	 * Add filter for synced attachments.
	 */
	public function add_sync_filter(): void {
		global $pagenow;

		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		// Nonce verification for filter form.
		$nonce = isset( $_GET['onemedia_sync_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['onemedia_sync_nonce'] ) )
			: '';

		if ( ! $nonce ) {
			// This means this is the first load of the page, so we don't have onemedia_sync_filter nonce yet.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping handled in the template file.
			echo Utils::get_template_content( 'brand-site/sync-status' );
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'onemedia_sync_filter' ) ) {
			return;
		}

		// This means the form has been submitted, so we have a nonce to verify.
		$sync_status = isset( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] )
			? sanitize_text_field( wp_unslash( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] ) )
			: '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping handled in the template file.
		echo Utils::get_template_content( 'brand-site/sync-status', [ 'sync_status' => $sync_status ] );
	}

	/**
	 * Filter attachments based on sync status.
	 *
	 * @param \WP_Query $query A reference of the current query object.
	 */
	public function filter_sync_attachments( \WP_Query $query ): void {
		global $pagenow;
		$onemedia_sync_status = isset( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] )
			? sanitize_text_field( wp_unslash( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] ) )
			: '';

		if ( 'upload.php' !== $pagenow || empty( $onemedia_sync_status ) ) {
			return;
		}

		// Nonce verification for filter query.
		$nonce = isset( $_GET['onemedia_sync_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['onemedia_sync_nonce'] ) )
			: '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'onemedia_sync_filter' ) ) {
			return;
		}

		$sync_status = isset( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] )
			? sanitize_text_field( wp_unslash( $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] ) )
			: '';

		if ( Attachment::SYNC_STATUS_SYNC === $sync_status ) {
			$query->set(
				'meta_query',
				[
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '1',
						'compare' => '=',
					],
				]
			);
		} elseif ( Attachment::SYNC_STATUS_NO_SYNC === $sync_status ) {
			$query->set(
				'meta_query',
				[
					'relation' => 'OR',
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'value'   => '0',
						'compare' => '=',
					],
					[
						'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
						'compare' => 'NOT EXISTS',
					],
				]
			);
		}
	}
}
