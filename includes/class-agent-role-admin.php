<?php
/**
 * Users → Add Agent screen.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin admin form over Agent_Role_Account.
 */
class Agent_Role_Admin {

	/**
	 * Register the screen and its form handlers.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_agent_role_add_agent', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_agent_role_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_agent_role_reissue', array( __CLASS__, 'handle_reissue' ) );
		add_action( 'admin_post_agent_role_mcp_setting', array( __CLASS__, 'handle_mcp_setting' ) );
	}

	/**
	 * Add the Users submenu.
	 */
	public static function menu() {
		add_users_page(
			__( 'Add Agent', 'agent-role' ),
			__( 'Add Agent', 'agent-role' ),
			'create_users',
			'agent-role',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the form, the one-time password, and existing agents.
	 */
	public static function render() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to create users.', 'agent-role' ), '', array( 'response' => 403 ) );
		}

		$admin_id = get_current_user_id();
		$created  = self::created_user_id();
		$password = $created ? Agent_Role_Account::take_password( $admin_id, $created ) : '';
		$notice   = get_transient( 'agent_role_notice_' . $admin_id );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( 'agent_role_notice_' . $admin_id );
		} else {
			$notice = '';
		}

		$agent = $created ? get_userdata( $created ) : false;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Add Agent', 'agent-role' ) . '</h1>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( '' !== $password && $agent instanceof WP_User ) {
			echo '<div class="notice notice-success"><p>';
			echo esc_html__( 'Copy this application password now. It will not be shown again.', 'agent-role' );
			echo '</p><p><code>' . esc_html( $password ) . '</code></p><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: WordPress username. */
					__( 'Username: %s. Use this pair as HTTP Basic auth on the REST API.', 'agent-role' ),
					$agent->user_login
				)
			);
			echo '</p>';
			self::render_mcp_config( $agent->user_login, $password );
			echo '</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agent_role_add_agent', 'agent_role_nonce' );
		echo '<input type="hidden" name="action" value="agent_role_add_agent" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="agent_role_username">' . esc_html__( 'Username', 'agent-role' ) . '</label></th>';
		echo '<td><input name="agent_role_username" id="agent_role_username" type="text" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="agent_role_display_name">' . esc_html__( 'Display name', 'agent-role' ) . '</label></th>';
		echo '<td><input name="agent_role_display_name" id="agent_role_display_name" type="text" class="regular-text" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Create Agent', 'agent-role' ) );
		echo '</form>';

		$agents = get_users(
			array(
				'role'   => Agent_Role::SLUG,
				'fields' => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		if ( $agents ) {
			echo '<h2>' . esc_html__( 'Agents', 'agent-role' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'User', 'agent-role' ) . '</th>';
			echo '<th>' . esc_html__( 'Application password', 'agent-role' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $agents as $agent_user ) {
				$has_password = (bool) Agent_Role_Account::managed_password( $agent_user->ID );
				echo '<tr><td>' . esc_html( $agent_user->display_name . ' (' . $agent_user->user_login . ')' ) . '</td><td>';
				if ( $has_password ) {
					$revoke_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'  => 'agent_role_revoke',
								'user_id' => (int) $agent_user->ID,
							),
							admin_url( 'admin-post.php' )
						),
						'agent_role_revoke_' . (int) $agent_user->ID
					);
					echo '<a href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke', 'agent-role' ) . '</a>';
				} else {
					$reissue_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'  => 'agent_role_reissue',
								'user_id' => (int) $agent_user->ID,
							),
							admin_url( 'admin-post.php' )
						),
						'agent_role_reissue_' . (int) $agent_user->ID
					);
					echo '<a href="' . esc_url( $reissue_url ) . '">' . esc_html__( 'Create password', 'agent-role' ) . '</a>';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		self::render_mcp_setting();

		echo '</div>';
	}

	/**
	 * MCP endpoint and client config, shown only with the one-time password.
	 *
	 * @param string $username Agent username.
	 * @param string $password Application password.
	 */
	private static function render_mcp_config( $username, $password ) {
		if ( ! Agent_Role_Mcp::is_available() ) {
			return;
		}

		$json = wp_json_encode( Agent_Role_Mcp::client_config( $username, $password ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		echo '<p>' . esc_html__( 'MCP endpoint:', 'agent-role' ) . ' <code>' . esc_html( Agent_Role_Mcp::endpoint() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Client config for Cursor, Claude Desktop, and other MCP clients:', 'agent-role' ) . '</p>';
		echo '<pre><code>' . esc_html( (string) $json ) . '</code></pre>';
	}

	/**
	 * Checkbox that limits the MCP server to Agent accounts.
	 */
	private static function render_mcp_setting() {
		if ( ! Agent_Role_Mcp::is_available() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'MCP Adapter', 'agent-role' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agent_role_mcp_setting', 'agent_role_mcp_nonce' );
		echo '<input type="hidden" name="action" value="agent_role_mcp_setting" />';
		echo '<p><label><input type="checkbox" name="agent_role_mcp_agents_only" value="1" ' . checked( Agent_Role_Mcp::agents_only(), true, false ) . ' /> ';
		echo esc_html__( 'Only Agent accounts may use the MCP server.', 'agent-role' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'When off, the MCP Adapter accepts any logged-in user, as it ships.', 'agent-role' ) . '</p>';
		submit_button( __( 'Save', 'agent-role' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Save the MCP gate setting.
	 */
	public static function handle_mcp_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'agent-role' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'agent_role_mcp_setting', 'agent_role_mcp_nonce' );

		Agent_Role_Mcp::set_agents_only( ! empty( $_POST['agent_role_mcp_agents_only'] ) );

		wp_safe_redirect( admin_url( 'users.php?page=agent-role' ) );
		exit;
	}

	/**
	 * Create an agent from the form.
	 */
	public static function handle_add() {
		self::require_create_users();
		check_admin_referer( 'agent_role_add_agent', 'agent_role_nonce' );

		$username = isset( $_POST['agent_role_username'] ) ? sanitize_user( wp_unslash( $_POST['agent_role_username'] ), true ) : '';
		$display  = isset( $_POST['agent_role_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_role_display_name'] ) ) : '';
		$result   = Agent_Role_Account::create( $username, $display, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			set_transient( 'agent_role_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'users.php?page=agent-role' ) );
			exit;
		}

		wp_safe_redirect( self::shown_url( (int) $result['user_id'] ) );
		exit;
	}

	/**
	 * Revoke the named application password.
	 */
	public static function handle_revoke() {
		self::require_create_users();
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0;
		check_admin_referer( 'agent_role_revoke_' . $user_id );

		$result = Agent_Role_Account::revoke( $user_id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'agent_role_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}

		wp_safe_redirect( admin_url( 'users.php?page=agent-role' ) );
		exit;
	}

	/**
	 * Issue a replacement password after revoke.
	 */
	public static function handle_reissue() {
		self::require_create_users();
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0;
		check_admin_referer( 'agent_role_reissue_' . $user_id );

		$result = Agent_Role_Account::issue_password( $user_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			set_transient( 'agent_role_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'users.php?page=agent-role' ) );
			exit;
		}

		wp_safe_redirect( self::shown_url( $user_id ) );
		exit;
	}

	/**
	 * The one-time password screen for this agent, with a nonce.
	 *
	 * @param int $user_id Agent user ID.
	 */
	private static function shown_url( $user_id ) {
		$user_id = (int) $user_id;

		return add_query_arg(
			array(
				'page'     => 'agent-role',
				'created'  => $user_id,
				'_wpnonce' => wp_create_nonce( 'agent_role_show_' . $user_id ),
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * Agent user ID from the one-time screen, after the nonce checks out.
	 */
	private static function created_user_id() {
		if ( ! isset( $_GET['created'], $_GET['_wpnonce'] ) ) {
			return 0;
		}

		$created = absint( wp_unslash( $_GET['created'] ) );
		$nonce   = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'agent_role_show_' . $created ) ) {
			return 0;
		}

		return $created;
	}

	/**
	 * Stop users who cannot create accounts.
	 */
	private static function require_create_users() {
		if ( current_user_can( 'create_users' ) ) {
			return;
		}

		wp_die( esc_html__( 'You do not have permission to create users.', 'agent-role' ), '', array( 'response' => 403 ) );
	}
}
