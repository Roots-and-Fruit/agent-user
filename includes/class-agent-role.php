<?php
/**
 * Agent role registration.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Agent role and removes it on uninstall.
 */
class Agent_Role {

	const SLUG = 'agent_role';

	const NAME = 'Agent';

	/**
	 * Author primitive capabilities. add_role() will not update this list later.
	 *
	 * @var array<string,bool>
	 */
	const CAPS = array(
		'read'                   => true,
		'edit_posts'             => true,
		'delete_posts'           => true,
		'publish_posts'          => true,
		'upload_files'           => true,
		'edit_published_posts'   => true,
		'delete_published_posts' => true,
	);

	/**
	 * Add the role when it is missing. An existing role is left unchanged.
	 */
	public static function activate() {
		if ( get_role( self::SLUG ) ) {
			return;
		}

		add_role( self::SLUG, self::NAME, self::CAPS );
	}

	/**
	 * Remove the role and credentials this plugin created. Users are not deleted.
	 */
	public static function uninstall() {
		remove_role( self::SLUG );
		Agent_Role_Account::delete_credentials();
		delete_option( Agent_Role_Mcp::OPTION );
	}

	/**
	 * Whether this user is an Agent.
	 *
	 * @param mixed $user User to check.
	 */
	public static function is_agent( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return in_array( self::SLUG, (array) $user->roles, true );
	}
}
