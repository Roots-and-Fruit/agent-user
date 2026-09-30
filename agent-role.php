<?php
/**
 * Plugin Name: Agent Role
 * Description: Registers an Agent role and creates accounts that cannot log in with a password. Each account gets one application password for REST API access.
 * Version: 1.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Roots & Fruit
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rf-agent-role
 * Domain Path: /languages
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_ROLE_VERSION', '1.6.0' );
define( 'AGENT_ROLE_FILE', __FILE__ );
define( 'AGENT_ROLE_DIR', plugin_dir_path( __FILE__ ) );

require_once AGENT_ROLE_DIR . 'includes/class-agent-role.php';
require_once AGENT_ROLE_DIR . 'includes/class-agent-role-auth.php';
require_once AGENT_ROLE_DIR . 'includes/class-agent-role-account.php';
require_once AGENT_ROLE_DIR . 'includes/class-agent-role-mcp.php';
require_once AGENT_ROLE_DIR . 'includes/class-agent-role-log.php';

if ( is_admin() ) {
	require_once AGENT_ROLE_DIR . 'includes/class-agent-role-admin.php';
	require_once AGENT_ROLE_DIR . 'includes/class-agent-role-brief.php';
}

register_activation_hook( __FILE__, array( 'Agent_Role', 'activate' ) );

Agent_Role::register();
Agent_Role_Auth::register();
Agent_Role_Account::register();
Agent_Role_Log::register();
if ( is_admin() ) {
	Agent_Role_Admin::register();
}

// The MCP Adapter loads after this plugin, so its class is not visible until here.
add_action( 'plugins_loaded', array( 'Agent_Role_Mcp', 'register' ) );
