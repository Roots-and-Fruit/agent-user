<?php
/**
 * Picks the setup prompt an agent receives.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP when the adapter is active, the REST API otherwise.
 */
class Agent_Role_Connection {

	/**
	 * Text left in place of the password in every setup prompt.
	 */
	const PASSWORD_PLACEHOLDER = 'PASTE_APPLICATION_PASSWORD_HERE';

	/**
	 * Whether agents connect through the MCP Adapter.
	 *
	 * The agent_role_uses_mcp filter lets a site keep REST onboarding while the
	 * adapter is loaded. Returning true without the adapter does nothing useful.
	 */
	public static function uses_mcp() {
		return (bool) apply_filters( 'agent_role_uses_mcp', Agent_Role_Mcp::is_available() );
	}

	/**
	 * Setup prompt for one agent.
	 *
	 * @param string $username Agent username.
	 * @param int    $user_id  Agent user ID. Zero leaves out the capability list.
	 */
	public static function setup_prompt( $username, $user_id = 0 ) {
		if ( self::uses_mcp() ) {
			return Agent_Role_Mcp::setup_prompt( $username );
		}

		return Agent_Role_Harness::setup_prompt( $username, $user_id );
	}
}
