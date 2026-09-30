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

if ( ! defined( 'AGENT_ROLE_SKIP_MIGRATE' ) ) {
	define( 'AGENT_ROLE_SKIP_MIGRATE', true );
}

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

// The plugin may already be inactive, so plugins_loaded has fired without it.
// PHPUnit is not wp-admin, so the admin screen is not loaded with the plugin.
Agent_Role_Mcp::register();
if ( ! class_exists( 'Agent_Role_Admin', false ) ) {
	require_once dirname( $agent_role_plugin_file ) . '/includes/class-agent-role-admin.php';
	require_once dirname( $agent_role_plugin_file ) . '/includes/class-agent-role-brief.php';
}
if ( ! has_action( 'admin_menu', array( 'Agent_Role_Admin', 'menu' ) ) ) {
	Agent_Role_Admin::register();
}

/*
 * Uninstall tests delete every Agent Role application password on this site,
 * and role tests deactivate the plugin. Snapshot the live site first and put
 * it back when the process ends. The stored hash is enough. The plaintext
 * password in Cursor stays valid.
 */
$agent_role_plugin_basename = plugin_basename( $agent_role_plugin_file );
$agent_role_was_active      = is_plugin_active( $agent_role_plugin_basename );
$agent_role_mcp_option      = get_option( Agent_Role_Mcp::OPTION, null );
$agent_role_persona_defaults = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION, null );
$agent_role_existing_role   = get_role( Agent_Role::SLUG );
$agent_role_saved_role      = null;
if ( $agent_role_existing_role ) {
	$agent_role_names      = wp_roles()->role_names;
	$agent_role_saved_role = array(
		'name' => isset( $agent_role_names[ Agent_Role::SLUG ] ) ? $agent_role_names[ Agent_Role::SLUG ] : Agent_Role::NAME,
		'caps' => $agent_role_existing_role->capabilities,
	);
}

$agent_role_saved_passwords = array();
$agent_role_password_users  = get_users(
	array(
		'fields'       => 'ID',
		'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_compare' => 'EXISTS',
	)
);
foreach ( $agent_role_password_users as $agent_role_password_user_id ) {
	$agent_role_password_item = Agent_Role_Account::managed_password( (int) $agent_role_password_user_id );
	if ( $agent_role_password_item ) {
		$agent_role_saved_passwords[ (int) $agent_role_password_user_id ] = $agent_role_password_item;
	}
}

$agent_role_caps_migrated  = get_option( Agent_Role::MIGRATED_OPTION, null );
$agent_role_saved_cap_users = array();
$agent_role_cap_ids         = get_users(
	array(
		'role'   => Agent_Role::SLUG,
		'fields' => 'ID',
	)
);
foreach ( $agent_role_cap_ids as $agent_role_cap_id ) {
	$agent_role_cap_user = get_userdata( (int) $agent_role_cap_id );
	if ( ! $agent_role_cap_user instanceof WP_User ) {
		continue;
	}
	$agent_role_saved_cap_users[ (int) $agent_role_cap_id ] = array(
		'cap_key'    => $agent_role_cap_user->cap_key,
		'caps'       => get_user_meta( (int) $agent_role_cap_id, $agent_role_cap_user->cap_key, true ),
		'legacy'     => get_user_meta( (int) $agent_role_cap_id, Agent_Role::CAPS_META, true ),
		'had_legacy' => metadata_exists( 'user', (int) $agent_role_cap_id, Agent_Role::CAPS_META ),
	);
}

register_shutdown_function(
	static function () use ( $agent_role_saved_role, $agent_role_was_active, $agent_role_plugin_basename, $agent_role_mcp_option, $agent_role_persona_defaults, $agent_role_saved_passwords, $agent_role_caps_migrated, $agent_role_saved_cap_users ) {
		remove_role( Agent_Role::SLUG );

		if ( null !== $agent_role_saved_role ) {
			add_role( Agent_Role::SLUG, $agent_role_saved_role['name'], $agent_role_saved_role['caps'] );
		}

		foreach ( $agent_role_saved_passwords as $user_id => $item ) {
			$current = WP_Application_Passwords::get_user_application_passwords( $user_id );
			$found   = false;
			foreach ( $current as $row ) {
				if ( isset( $row['uuid'] ) && $row['uuid'] === $item['uuid'] ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				continue;
			}
			$current[] = $item;
			update_user_meta( $user_id, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $current );
		}

		if ( null === $agent_role_mcp_option ) {
			delete_option( Agent_Role_Mcp::OPTION );
		} else {
			update_option( Agent_Role_Mcp::OPTION, $agent_role_mcp_option );
		}

		if ( null === $agent_role_persona_defaults ) {
			delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
		} else {
			update_option( Agent_Role::PERSONA_DEFAULTS_OPTION, $agent_role_persona_defaults, false );
		}

		foreach ( $agent_role_saved_cap_users as $user_id => $saved ) {
			if ( ! get_userdata( $user_id ) ) {
				continue;
			}
			update_user_meta( $user_id, $saved['cap_key'], $saved['caps'] );
			if ( $saved['had_legacy'] ) {
				update_user_meta( $user_id, Agent_Role::CAPS_META, $saved['legacy'] );
			} else {
				delete_user_meta( $user_id, Agent_Role::CAPS_META );
			}
			clean_user_cache( $user_id );
		}

		if ( null === $agent_role_caps_migrated ) {
			delete_option( Agent_Role::MIGRATED_OPTION );
		} else {
			update_option( Agent_Role::MIGRATED_OPTION, $agent_role_caps_migrated, false );
		}

		if ( $agent_role_was_active && ! is_plugin_active( $agent_role_plugin_basename ) ) {
			activate_plugin( $agent_role_plugin_basename, '', false, true );
		}
	}
);
