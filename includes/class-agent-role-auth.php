<?php
/**
 * Blocks interactive login for Agent accounts.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Password login, password reset, and XML-RPC are refused for Agents.
 * REST application passwords are unchanged.
 */
class Agent_Role_Auth {

	/**
	 * Register the Core hooks.
	 */
	public static function register() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_password_login' ) );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_password_reset' ), 10, 2 );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'block_xmlrpc' ), 10, 2 );
	}

	/**
	 * Refuse password and email login for an Agent.
	 *
	 * Application passwords never reach this filter.
	 *
	 * @param WP_User|WP_Error $user User being authenticated.
	 * @return WP_User|WP_Error
	 */
	public static function block_password_login( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( ! Agent_Role::is_agent( $user ) ) {
			return $user;
		}

		return new WP_Error(
			'agent_role_password_login_blocked',
			__( 'This account cannot sign in with a password.', 'agent-role' )
		);
	}

	/**
	 * Refuse password reset for an Agent.
	 *
	 * @param bool $allow   Whether reset is allowed.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public static function block_password_reset( $allow, $user_id ) {
		$user = get_userdata( $user_id );

		if ( $user && Agent_Role::is_agent( $user ) ) {
			return false;
		}

		return $allow;
	}

	/**
	 * Refuse application-password login over XML-RPC.
	 *
	 * @param WP_Error $error Error object Core checks after this action.
	 * @param WP_User  $user  User being authenticated.
	 */
	public static function block_xmlrpc( $error, $user ) {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}

		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return;
		}

		$error->add(
			'agent_role_xmlrpc_blocked',
			__( 'Agent accounts cannot authenticate over XML-RPC.', 'agent-role' )
		);
	}
}
