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

	const APP_ID = 'agent-role';

	const META = '_agent_role_managed';

	const TRANSIENT_TTL = 120;

	/**
	 * True while a revert is calling set_role() or remove_role().
	 *
	 * @var bool
	 */
	private static $reverting = false;

	/**
	 * Register session and role-list hooks.
	 */
	public static function register() {
		add_filter( 'editable_roles', array( __CLASS__, 'hide_role' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ), 1 );
		add_action( 'set_user_role', array( __CLASS__, 'guard_set_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'guard_add_role' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_switch_notice' ) );
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

		// User Switching sends switch-back through a login action. Let that finish.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only. The switching plugin checks its own nonce.
		if ( in_array( $action, array( 'switch_to_olduser', 'switch_to_user' ), true ) ) {
			return;
		}

		if ( headers_sent() ) {
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

		$user_id = self::insert_user(
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

		Agent_Role::seed_agent( $user_id );
		Agent_Role_Log::record( $user_id, 'admin', 'Agent account created', 'account-created', 'changed' );

		return array(
			'user_id' => $user_id,
		);
	}

	/**
	 * Insert a user. Assigning the Agent role also marks the account as managed.
	 *
	 * The mark is written before set_role() runs, via insert_custom_user_meta.
	 *
	 * @param array<string,mixed> $userdata Arguments for wp_insert_user().
	 * @return int|WP_Error
	 */
	public static function insert_user( array $userdata ) {
		$managed = isset( $userdata['role'] ) && Agent_Role::SLUG === $userdata['role'];
		if ( $managed ) {
			add_filter( 'insert_custom_user_meta', array( __CLASS__, 'add_managed_meta' ), 10, 4 );
		}

		$user_id = wp_insert_user( $userdata );

		if ( $managed ) {
			remove_filter( 'insert_custom_user_meta', array( __CLASS__, 'add_managed_meta' ), 10 );
		}

		return $user_id;
	}

	/**
	 * Mark a new Agent so later role changes can be told apart from a person.
	 *
	 * @param array<string,mixed> $meta     Meta being inserted.
	 * @param WP_User             $user     User being saved.
	 * @param bool                $update   Whether this is an update.
	 * @param array<string,mixed> $userdata Raw arguments passed to wp_insert_user().
	 * @return array<string,mixed>
	 */
	public static function add_managed_meta( $meta, $user, $update, $userdata ) {
		unset( $user );
		if ( ! $update && isset( $userdata['role'] ) && Agent_Role::SLUG === $userdata['role'] ) {
			$meta[ self::META ] = '1';
		}

		return $meta;
	}

	/**
	 * Put a person back if they were given the Agent role, and an Agent back if they were moved off it.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      Role just assigned.
	 * @param string[] $old_roles Roles before this change.
	 */
	public static function guard_set_role( $user_id, $role, $old_roles ) {
		if ( self::$reverting ) {
			return;
		}

		$user_id = (int) $user_id;
		$managed = (bool) get_user_meta( $user_id, self::META, true );

		if ( Agent_Role::SLUG === $role && ! $managed ) {
			self::restore_role( $user_id, self::previous_role( $old_roles ) );
			self::remember_notice(
				/* translators: "Users" and "Agents" are WordPress admin menu labels. */
				__( 'This account was not created as an Agent. Create an Agent under Users → Agents.', 'agent-role' )
			);
			return;
		}

		if ( $managed && Agent_Role::SLUG !== $role ) {
			self::restore_role( $user_id, Agent_Role::SLUG );
			self::remember_notice(
				__( 'Agent accounts stay Agents. Create a separate user for a person.', 'agent-role' )
			);
		}
	}

	/**
	 * Remove the Agent role if it was added beside a person's existing role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role just added.
	 */
	public static function guard_add_role( $user_id, $role ) {
		if ( self::$reverting || Agent_Role::SLUG !== $role ) {
			return;
		}

		if ( get_user_meta( (int) $user_id, self::META, true ) ) {
			return;
		}

		self::$reverting = true;
		$user            = get_userdata( $user_id );
		if ( $user instanceof WP_User ) {
			$user->remove_role( Agent_Role::SLUG );
		}
		self::$reverting = false;

		self::remember_notice(
			/* translators: "Users" and "Agents" are WordPress admin menu labels. */
			__( 'This account was not created as an Agent. Create an Agent under Users → Agents.', 'agent-role' )
		);
	}

	/**
	 * Show the role-switch notice once for the administrator who made the change.
	 */
	public static function show_switch_notice() {
		$admin_id = get_current_user_id();
		if ( ! $admin_id ) {
			return;
		}

		$message = get_transient( self::notice_key( $admin_id ) );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( self::notice_key( $admin_id ) );
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Assign a role without the guard treating it as a new switch.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role to assign. Empty clears every role.
	 */
	private static function restore_role( $user_id, $role ) {
		self::$reverting = true;
		$user            = get_userdata( $user_id );
		if ( $user instanceof WP_User ) {
			$user->set_role( $role );
		}
		self::$reverting = false;
	}

	/**
	 * First previous role that is not the Agent role.
	 *
	 * @param mixed $old_roles Roles before the change.
	 */
	private static function previous_role( $old_roles ) {
		if ( ! is_array( $old_roles ) ) {
			return '';
		}

		foreach ( $old_roles as $old ) {
			if ( is_string( $old ) && '' !== $old && Agent_Role::SLUG !== $old ) {
				return $old;
			}
		}

		return '';
	}

	/**
	 * Store a one-time admin notice for the current user.
	 *
	 * @param string $message Notice text.
	 */
	private static function remember_notice( $message ) {
		$admin_id = get_current_user_id();
		if ( ! $admin_id ) {
			return;
		}

		set_transient( self::notice_key( $admin_id ), $message, 60 );
	}

	/**
	 * Transient key for a role-switch notice.
	 *
	 * @param int $admin_id Administrator user ID.
	 */
	private static function notice_key( $admin_id ) {
		return 'agent_role_switch_notice_' . (int) $admin_id;
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
				'app_id' => self::APP_ID,
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

		if ( class_exists( 'Agent_Role_Log' ) ) {
			Agent_Role_Log::record( (int) $user_id, 'admin', 'Application password issued', 'password', 'changed' );
		}

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

		if ( class_exists( 'Agent_Role_Log' ) ) {
			Agent_Role_Log::record( (int) $user_id, 'admin', 'Application password revoked', 'password', 'changed' );
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
			if ( self::APP_ID === ( $item['app_id'] ?? '' ) ) {
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
