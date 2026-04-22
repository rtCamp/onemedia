<?php
/**
 * Base REST controller class.
 *
 * Includes the shared namespace, version and hook registration.
 *
 * @package OneMedia\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\Rest;

use OneMedia\Contracts\Interfaces\Registrable;
use OneMedia\Modules\Settings\Settings;

/**
 * Class - Abstract_REST_Controller
 */
abstract class Abstract_REST_Controller extends \WP_REST_Controller implements Registrable {
	/**
	 * The namespace for the REST API.
	 */
	public const NAMESPACE = 'onemedia/v1';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * We throw an exception here to force the child class to implement this method.
	 *
	 * @throws \Exception If method not implemented.
	 *
	 * @codeCoverageIgnore
	 */
	public function register_routes(): void {
		throw new \Exception( __FUNCTION__ . ' Method not implemented.' );
	}

	/**
	 * Checks for the use of the OneMedia API key in the request headers.
	 *
	 * @todo this should be on a hook.
	 *
	 * @param \WP_REST_Request<array{}> $request Request.
	 */
	public function check_api_permissions( $request ): bool {
		// If it's the same domain, check if the current user can manage options.
		$request_origin = $request->get_header( 'Origin' );
		$request_origin = ! empty( $request_origin ) ? esc_url_raw( wp_unslash( $request_origin ) ) : '';
		$parsed_origin  = wp_parse_url( $request_origin );
		$request_url    = ! empty( $parsed_origin['scheme'] ) && ! empty( $parsed_origin['host'] ) ? sprintf(
			'%s://%s%s',
			$parsed_origin['scheme'],
			$parsed_origin['host'],
			isset( $parsed_origin['port'] ) ? ':' . $parsed_origin['port'] : ''
		) : '';

		$origin_port = $parsed_origin['port'] ?? 80;

		if ( empty( $request_url ) || $this->is_url_from_host( get_site_url(), $parsed_origin['host'], $origin_port ) ) {
			return current_user_can( 'manage_options' );
		}

		// See if the `X-OneMedia-Token` header is present.
		$token = $request->get_header( 'X-OneMedia-Token' );
		$token = ! empty( $token ) ? sanitize_text_field( wp_unslash( $token ) ) : '';
		if ( empty( $token ) ) {
			return false;
		}

		$stored_key = $this->get_stored_api_key( trailingslashit( $request_url ) );
		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $token ) ) {
			return false;
		}

		// Governing sites were checked by ::get_stored_api_key already.
		if ( Settings::is_governing_site() ) {
			return true;
		}

		// If it's not a healthcheck, compare the origins.
		$governing_site_url = Settings::get_parent_site_url();
		if ( '/' . self::NAMESPACE . '/health-check' !== $request->get_route() ) {
			return ! empty( $governing_site_url ) ? $this->is_url_from_host( $governing_site_url, $parsed_origin['host'], $origin_port ) : false;
		}

		// For health-checks, if no governing site is set, we set it now.
		Settings::set_parent_site_url( $request_origin );
		return true;
	}

	/**
	 * Check if two URLs belong to the same host.
	 *
	 * @param string   $url  The URL to check.
	 * @param string   $host The host to compare against.
	 * @param int|null $port Optional. The port to compare against.
	 *
	 * @return bool True if both URLs belong to the same domain, false otherwise.
	 */
	private function is_url_from_host( string $url, string $host, ?int $port = null ): bool {
		$parsed_url = wp_parse_url( $url );

		// Compare both host and port to properly handle localhost with different ports.
		if ( ! isset( $parsed_url['host'] ) || $parsed_url['host'] !== $host ) {
			return false;
		}

		// If a port was provided, also compare ports.
		if ( null !== $port ) {
			$url_port = $parsed_url['port'] ?? 80;
			return $url_port === $port;
		}

		return true;
	}

	/**
	 * Gets the locally-stored API key for comparison.
	 *
	 * @param ?string $site_url Site URL. Only used for child->governing site requests.
	 *
	 * @return string The stored API key. Empty string if not found.
	 */
	private function get_stored_api_key( ?string $site_url = null ): string {
		if ( Settings::is_consumer_site() ) {
			return Settings::get_api_key();
		}

		// If there's no child site URL we cannot match the API key.
		if ( ! isset( $site_url ) ) {
			return '';
		}

		$shared_sites = Settings::get_shared_sites();

		return ! empty( $shared_sites[ $site_url ]['api_key'] ) ? $shared_sites[ $site_url ]['api_key'] : '';
	}
}
