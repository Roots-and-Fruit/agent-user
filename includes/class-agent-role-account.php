<?php
/**
 * Creates Agent accounts and their single application password.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account creation, one application password, and the non-human session rules.
 */
class Agent_Role_Account {

	const PASSWORD_NAME = 'Agent Role';

	const TRANSIENT_TTL = 120;

	/**
	 * Register session and role-list hooks.
	 */
	public static function register() {
		add_filter( 'editable_roles', array( __CLASS__, 'hide_role' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ), 1 );
	}

	/**
	 * Keep the Agent role off the normal Add User screen.
	 *
	 * @param array<string,array<string,mixed>> $roles Editable roles.
	 * @return array<string,array<string,mixed>>
	 */
	public static function hide_role( $roles ) {
		unset( $roles[ Agent_Role::SLUG ] );
		return $roles;
	}

	/**
	 * Hide the admin bar for an Agent.
	 *
	 * @param bool $show Whether to show the bar.
	 * @return bool
	 */
	public static function hide_admin_bar( $show ) {
		if ( Agent_Role::is_agent( wp_get_current_user() ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Send an Agent away from wp-admin. REST requests do not load wp-admin.
	 */
	public static function block_admin() {
		if ( ! Agent_Role::is_agent( wp_get_current_user() ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Create an Agent user and one application password.
	 *
	 * The login password is discarded. The application password is stored in a
	 * short-lived transient for the administrator who created the account.
	 *
	 * @param string $username     Requested username.
	 * @param string $display_name Display name. Falls back to the username.
	 * @param int    $admin_id     Administrator who will see the password once.
	 * @return array{user_id:int}|WP_Error
	 */
	public static function create( $username, $display_name, $admin_id ) {
		$username = sanitize_user( (string) $username, true );

		if ( '' === $username ) {
			return new WP_Error(
				'agent_role_invalid_username',
				__( 'A username is required.', 'agent-role' )
			);
		}

		if ( username_exists( $username ) ) {
			return new WP_Error(
				'agent_role_username_exists',
				__( 'That username is already taken.', 'agent-role' )
			);
		}

		$display_name = sanitize_text_field( (string) $display_name );
		if ( '' === $display_name ) {
			$display_name = $username;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => self::unique_email( $username ),
				'display_name' => $display_name,
				'role'         => Agent_Role::SLUG,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$issued = self::issue_password( $user_id, $admin_id );
		if ( is_wp_error( $issued ) ) {
			wp_delete_user( $user_id );
			return $issued;
		}

		return array(
			'user_id' => $user_id,
		);
	}

	/**
	 * Create the single named application password and stash it for one view.
	 *
	 * @param int $user_id  Agent user ID.
	 * @param int $admin_id Administrator who will see the password.
	 * @return true|WP_Error
	 */
	public static function issue_password( $user_id, $admin_id ) {
		if ( self::managed_password( $user_id ) ) {
			return new WP_Error(
				'agent_role_password_exists',
				__( 'This agent already has an application password.', 'agent-role' )
			);
		}

		$created = WP_Application_Passwords::create_new_application_password(
			(int) $user_id,
			array(
				'name'   => self::PASSWORD_NAME,
				'app_id' => 'agent-role',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		set_transient(
			self::transient_key( $admin_id, $user_id ),
			WP_Application_Passwords::chunk_password( $created[0] ),
			self::TRANSIENT_TTL
		);

		return true;
	}

	/**
	 * Read the one-time password and delete it.
	 *
	 * @param int $admin_id Administrator who created it.
	 * @param int $user_id  Agent user ID.
	 * @return string Empty when there is nothing to show.
	 */
	public static function take_password( $admin_id, $user_id ) {
		$key      = self::transient_key( $admin_id, $user_id );
		$password = get_transient( $key );
		delete_transient( $key );

		if ( ! is_string( $password ) || '' === $password ) {
			return '';
		}

		return $password;
	}

	/**
	 * Revoke the named application password.
	 *
	 * @param int $user_id Agent user ID.
	 * @return true|WP_Error
	 */
	public static function revoke( $user_id ) {
		$item = self::managed_password( $user_id );

		if ( ! $item ) {
			return new WP_Error(
				'agent_role_password_missing',
				__( 'This agent has no application password.', 'agent-role' )
			);
		}

		$deleted = WP_Application_Passwords::delete_application_password( (int) $user_id, $item['uuid'] );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( ! $deleted ) {
			return new WP_Error(
				'agent_role_password_missing',
				__( 'This agent has no application password.', 'agent-role' )
			);
		}

		return true;
	}

	/**
	 * The application password this plugin created, if any.
	 *
	 * @param int $user_id Agent user ID.
	 * @return array<string,mixed>|null
	 */
	public static function managed_password( $user_id ) {
		$passwords = WP_Application_Passwords::get_user_application_passwords( (int) $user_id );

		foreach ( $passwords as $item ) {
			if ( self::PASSWORD_NAME === $item['name'] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Delete this plugin's transients and the application passwords it created.
	 */
	public static function delete_credentials() {
		global $wpdb;

		$transient = $wpdb->esc_like( '_transient_agent_role_' ) . '%';
		$timeout   = $wpdb->esc_like( '_transient_timeout_agent_role_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient,
				$timeout
			)
		);

		// Runs once at uninstall. Application passwords are the only user meta that matters here.
		$user_ids = get_users(
			array(
				'fields'       => 'ID',
				'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $user_ids as $user_id ) {
			$item = self::managed_password( (int) $user_id );
			if ( $item ) {
				WP_Application_Passwords::delete_application_password( (int) $user_id, $item['uuid'] );
			}
		}
	}

	/**
	 * Transient key for one administrator and one agent.
	 *
	 * @param int $admin_id Administrator user ID.
	 * @param int $user_id  Agent user ID.
	 */
	public static function transient_key( $admin_id, $user_id ) {
		return 'agent_role_pw_' . (int) $admin_id . '_' . (int) $user_id;
	}

	/**
	 * Build a unique example.invalid address for the account.
	 *
	 * @param string $username Sanitized username.
	 */
	private static function unique_email( $username ) {
		$local = 'agent-' . strtolower( $username );
		$email = $local . '@example.invalid';
		$count = 1;

		while ( email_exists( $email ) ) {
			$email = $local . '-' . $count . '@example.invalid';
			++$count;
		}

		return $email;
	}
}
