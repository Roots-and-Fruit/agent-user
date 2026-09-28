<?php
/**
 * Boot the Studio site so tests call WordPress itself.
 *
 * @package Agent_Role
 */

$_SERVER['HTTPS']       = 'on';
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'wporg.wp.local';
$_SERVER['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$_SERVER['REMOTE_ADDR'] = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

$agent_role_site_root = dirname( __DIR__, 4 );

require $agent_role_site_root . '/wp-load.php';

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$agent_role_plugin_file = realpath( dirname( __DIR__ ) . '/agent-role.php' );
if ( false === $agent_role_plugin_file ) {
	fwrite( STDERR, "Agent Role main file was not found.\n" );
	exit( 1 );
}

require_once $agent_role_plugin_file;

// The plugin is not active on this site, so plugins_loaded has already fired without it.
Agent_Role_Mcp::register();

/*
 * Put the site back the way it was. Tests register the role directly, so a
 * site where the plugin is inactive would otherwise keep it.
 */
$agent_role_existing_role = get_role( Agent_Role::SLUG );
$agent_role_saved_role    = null;
if ( $agent_role_existing_role ) {
	$agent_role_names      = wp_roles()->role_names;
	$agent_role_saved_role = array(
		'name' => isset( $agent_role_names[ Agent_Role::SLUG ] ) ? $agent_role_names[ Agent_Role::SLUG ] : Agent_Role::NAME,
		'caps' => $agent_role_existing_role->capabilities,
	);
}

register_shutdown_function(
	static function () use ( $agent_role_saved_role ) {
		remove_role( Agent_Role::SLUG );

		if ( null !== $agent_role_saved_role ) {
			add_role( Agent_Role::SLUG, $agent_role_saved_role['name'], $agent_role_saved_role['caps'] );
		}
	}
);
