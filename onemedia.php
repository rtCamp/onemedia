<?php
/**
 * OneMedia
 *
 * @package           OneMedia
 * @author            rtCamp
 * @copyright         2025 rtCamp
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       OneMedia
 * Plugin URI:        https://github.com/rtCamp/onemedia
 * Update URI:        https://github.com/rtCamp/onemedia
 * Description:       A unified, scalable and centralized Media Library that stores brand assets once and automatically propagates them to every connected site.
 * Author:            rtCamp
 * Author URI:        https://rtcamp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       onemedia
 * Domain Path:       /languages
 * Version:           1.1.3
 * Requires PHP:      8.2
 * Requires at least: 6.8
 * Tested up to:      6.9
 */

declare( strict_types = 1 );

namespace OneMedia;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'ONEMEDIA_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'ONEMEDIA_VERSION', '1.1.3' );

	/**
	 * Root path to the plugin directory.
	 */
	define( 'ONEMEDIA_DIR', plugin_dir_path( __FILE__ ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'ONEMEDIA_URL', plugin_dir_url( __FILE__ ) );

	/**
	 * Plugin basename.
	 */
	define( 'ONEMEDIA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

constants();

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! \OneMedia\Autoloader::autoload() ) {
	return;
}

// Load the plugin.
if ( class_exists( '\OneMedia\Main' ) ) {
	\OneMedia\Main::instance();
}
