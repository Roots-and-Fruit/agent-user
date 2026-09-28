<?php
/**
 * Uninstall Agent Role.
 *
 * @package Agent_Role
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-agent-role.php';
require_once __DIR__ . '/includes/class-agent-role-account.php';
require_once __DIR__ . '/includes/class-agent-role-mcp.php';

Agent_Role::uninstall();
