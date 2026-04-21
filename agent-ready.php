<?php
/**
 * Plugin Name:       Agent Ready
 * Plugin URI:        https://agentready.dev
 * Description:       Makes WordPress sites optimized for AI agents — llms.txt, Markdown negotiation, structured data, bot access control, and readiness scoring.
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Agent Ready
 * Author URI:        https://agentready.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-ready
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENT_READY_VERSION',  '3.0.0' );
define( 'AGENT_READY_FILE',     __FILE__ );
define( 'AGENT_READY_PATH',     plugin_dir_path( __FILE__ ) );
define( 'AGENT_READY_URL',      plugin_dir_url( __FILE__ ) );
define( 'AGENT_READY_BASENAME', plugin_basename( __FILE__ ) );

// PSR-4 autoloader for the AgentReady namespace → src/.
spl_autoload_register( static function ( string $class ): void {
	$prefix   = 'AgentReady\\';
	$base_dir = AGENT_READY_PATH . 'src/';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Boot.
add_action( 'plugins_loaded', static function (): void {
	AgentReady\Plugin::instance()->boot();
} );

register_activation_hook( AGENT_READY_FILE,   [ AgentReady\Plugin::class, 'activate' ] );
register_deactivation_hook( AGENT_READY_FILE, [ AgentReady\Plugin::class, 'deactivate' ] );
