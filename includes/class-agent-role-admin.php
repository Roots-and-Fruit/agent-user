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
		add_action( 'admin_post_agent_role_save_agent', array( __CLASS__, 'handle_save_agent' ) );
		add_action( 'wp_ajax_agent_role_create_agent', array( __CLASS__, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_agent_role_draft_instructions', array( __CLASS__, 'handle_draft_instructions' ) );
		add_action( 'wp_ajax_agent_role_reset_instructions', array( __CLASS__, 'handle_reset_instructions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'role_note' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'role_note' ) );
	}

	/**
	 * Tell an administrator, on the profile screen, where Agent accounts are created.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function role_note( $user ) {
		unset( $user );
		if ( ! current_user_can( 'create_users' ) && ! current_user_can( 'promote_users' ) ) {
			return;
		}

		echo '<p>' . esc_html__( 'To give an agent access to this site, create an Agent under Users → Add Agent.', 'agent-role' ) . '</p>';
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
	 * Load the settings styles and copy buttons on Add Agent only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'users_page_agent-role' !== $hook ) {
			return;
		}

		$style_path  = AGENT_ROLE_DIR . 'admin/css/agent-role-admin.css';
		$script_path = AGENT_ROLE_DIR . 'admin/js/agent-role-admin.js';
		$version     = AGENT_ROLE_VERSION . '.' . (string) max(
			file_exists( $style_path ) ? filemtime( $style_path ) : 0,
			file_exists( $script_path ) ? filemtime( $script_path ) : 0
		);

		wp_enqueue_style(
			'agent-role-admin',
			plugins_url( 'admin/css/agent-role-admin.css', AGENT_ROLE_FILE ),
			array(),
			$version
		);
		wp_enqueue_script(
			'agent-role-admin',
			plugins_url( 'admin/js/agent-role-admin.js', AGENT_ROLE_FILE ),
			array(),
			$version,
			true
		);
		wp_localize_script(
			'agent-role-admin',
			'agentRoleAdmin',
			array(
				'copied'     => __( 'Copied', 'agent-role' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'agent_role_add_agent' ),
				'draftNonce' => wp_create_nonce( 'agent_role_draft_instructions' ),
				'resetNonce' => wp_create_nonce( 'agent_role_reset_instructions' ),
				'drafting'   => __( 'Writing instructions…', 'agent-role' ),
				'draftDone'  => __( 'Draft is in the box. Update Agent saves it.', 'agent-role' ),
				'resetting'  => __( 'Writing the built-in hint…', 'agent-role' ),
				'resetDone'  => __( 'Built-in hint is in the box. Update Agent saves it.', 'agent-role' ),
			)
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

		echo '<div class="wrap ar-rf-settings">';
		echo '<header class="ar-rf-settings__header">';
		echo '<h1 class="ar-rf-settings__title">';
		echo '<img class="ar-rf-settings__mark" src="' . esc_url( plugins_url( 'admin/images/rf-logo.svg', AGENT_ROLE_FILE ) ) . '" alt="" width="88" height="36" />';
		echo esc_html__( 'Agent Role', 'agent-role' );
		echo '</h1>';
		echo '<p class="ar-rf-settings__lede">' . esc_html__( 'An Agent cannot sign in as a person.', 'agent-role' ) . '</p>';
		echo '</header>';
		// WordPress moves .notice elements to after this marker, under the heading.
		echo '<hr class="wp-header-end" />';
		echo '<div class="ar-rf-settings__body">';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
		}

		$detail = self::requested_agent();
		if ( $detail instanceof WP_User ) {
			self::render_agent_screen( $detail );
		} else {
			if ( false === $detail ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'That account is not an Agent.', 'agent-role' ) . '</p></div>';
			}
			self::render_tabs();
			if ( 'settings' === self::current_tab() ) {
				self::render_settings_tab();
			} else {
				self::render_agents_tab( $password, $agent );
			}
		}

		echo '</div></div>';
	}

	/**
	 * Agents or Settings.
	 */
	private static function current_tab() {
		// Tab only chooses which view to render. It does not change data.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agents'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'settings' === $tab ? 'settings' : 'agents';
	}

	/**
	 * Tab links.
	 */
	private static function render_tabs() {
		$current = self::current_tab();
		echo '<nav class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ( 'agents' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'users.php?page=agent-role&tab=agents' ) ) . '">' . esc_html__( 'Agents', 'agent-role' ) . '</a>';
		echo '<a class="nav-tab' . ( 'settings' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'users.php?page=agent-role&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'agent-role' ) . '</a>';
		echo '</nav>';
	}

	/**
	 * The agent list and the create modal.
	 *
	 * @param string       $password One-time password, or empty.
	 * @param WP_User|false $agent   Account the password belongs to.
	 */
	private static function render_agents_tab( $password, $agent ) {
		$show_result = '' !== $password && $agent instanceof WP_User;

		echo '<div class="ar-rf-panel">';
		echo '<div class="ar-rf-toolbar">';
		echo '<h2>' . esc_html__( 'Agents', 'agent-role' ) . '</h2>';
		echo '<button type="button" class="button button-primary" id="ar-new-agent">';
		echo self::plus_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in plus_icon().
		echo esc_html__( 'New Agent', 'agent-role' );
		echo '</button></div>';

		$agents = get_users(
			array(
				'role'   => Agent_Role::SLUG,
				'fields' => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		if ( $agents ) {
			echo '<table class="widefat ar-rf-table"><thead><tr>';
			echo '<th>' . esc_html__( 'User', 'agent-role' ) . '</th>';
			echo '<th>' . esc_html__( 'Application password', 'agent-role' ) . '</th>';
			echo '<th>' . esc_html__( 'MCP info', 'agent-role' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $agents as $agent_user ) {
				$has_password = (bool) Agent_Role_Account::managed_password( $agent_user->ID );
				$edit_url     = add_query_arg(
					array(
						'page'    => 'agent-role',
						'user_id' => (int) $agent_user->ID,
					),
					admin_url( 'users.php' )
				);
				echo '<tr><td><a class="ar-rf-agent-link" href="' . esc_url( $edit_url ) . '">' . esc_html( $agent_user->display_name ) . '</a> ';
				echo '<span class="ar-rf-agent-login">(' . esc_html( $agent_user->user_login ) . ')</span></td><td>';
				if ( $has_password ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ar-rf-inline-form">';
					wp_nonce_field( 'agent_role_revoke_' . (int) $agent_user->ID );
					echo '<input type="hidden" name="action" value="agent_role_revoke" />';
					echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $agent_user->ID ) . '" />';
					echo '<button type="submit" class="button-link">' . esc_html__( 'Revoke', 'agent-role' ) . '</button>';
					echo '</form>';
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
				echo '</td><td>';
				if ( ! Agent_Role_Mcp::is_available() ) {
					echo '<p class="ar-rf-mcp-notice" role="note">';
					echo self::info_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in info_icon().
					echo '<span>' . esc_html__( 'No MCP is currently present.', 'agent-role' ) . '</span>';
					echo '</p>';
				} elseif ( ! $has_password ) {
					echo '<p class="ar-rf-mcp-notice" role="note">';
					echo self::info_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in info_icon().
					echo '<span>' . esc_html__( 'Generate App Password for MCP access', 'agent-role' ) . '</span>';
					echo '</p>';
				} else {
					$template_id = 'ar-mcp-' . (int) $agent_user->ID;
					$mcp_json    = (string) wp_json_encode(
						Agent_Role_Mcp::client_config( $agent_user->user_login, Agent_Role_Mcp::PASSWORD_PLACEHOLDER ),
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					);
					echo '<span class="ar-rf-mcp-actions">';
					echo '<button type="button" class="ar-rf-mcp-view" data-template="' . esc_attr( $template_id ) . '">' . esc_html__( 'View', 'agent-role' ) . '</button>';
					echo '<button type="button" class="ar-rf-mcp-copy" data-copy="' . esc_attr( $mcp_json ) . '" aria-label="' . esc_attr__( 'Copy MCP info', 'agent-role' ) . '">';
					echo self::clipboard_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in clipboard_icon().
					echo '<span class="ar-rf-mcp-copy__status" aria-live="polite"></span>';
					echo '</button></span>';
					echo '<template id="' . esc_attr( $template_id ) . '">';
					self::render_mcp_preview( $agent_user, $mcp_json );
					echo '</template>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'No agents yet. Create one when you are ready to connect a tool.', 'agent-role' ) . '</p>';
		}
		echo '</div>';

		echo '<dialog class="ar-rf-modal" id="ar-agent-modal">';
		echo '<form method="dialog" class="ar-rf-modal__panel" id="ar-agent-form">';
		echo '<div class="ar-rf-modal__step" id="ar-agent-step-create"' . ( $show_result ? ' hidden' : '' ) . '>';
		echo '<h2>' . esc_html__( 'New Agent', 'agent-role' ) . '</h2>';
		echo '<p>' . esc_html__( 'This account cannot sign in as a person.', 'agent-role' ) . '</p>';
		echo '<p><label for="agent_role_username">' . esc_html__( 'Username', 'agent-role' ) . '</label><br />';
		echo '<input name="agent_role_username" id="agent_role_username" type="text" class="regular-text" required /></p>';
		echo '<p><label for="agent_role_display_name">' . esc_html__( 'Display name', 'agent-role' ) . '</label><br />';
		echo '<input name="agent_role_display_name" id="agent_role_display_name" type="text" class="regular-text" /></p>';
		echo '<p class="ar-rf-modal__error" id="ar-agent-error" hidden></p>';
		echo '<p class="ar-rf-modal__actions">';
		echo '<button type="button" class="button" id="ar-agent-cancel">' . esc_html__( 'Cancel', 'agent-role' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="ar-agent-create">' . esc_html__( 'Create Agent', 'agent-role' ) . '</button>';
		echo '</p></div>';
		echo '<div class="ar-rf-modal__step" id="ar-agent-step-result"' . ( $show_result ? '' : ' hidden' ) . '>';
		if ( $show_result ) {
			self::render_credentials_body( $agent, $password );
		}
		echo '</div></form></dialog>';

		echo '<dialog class="ar-rf-modal" id="ar-mcp-modal">';
		echo '<form method="dialog" class="ar-rf-modal__panel">';
		echo '<div id="ar-mcp-modal-body"></div>';
		echo '</form></dialog>';
	}

	/**
	 * MCP connection details without the stored application password.
	 *
	 * @param WP_User $agent Agent account.
	 * @param string  $json  Client config that uses a password placeholder.
	 */
	private static function render_mcp_preview( $agent, $json ) {
		echo '<h2>' . esc_html__( 'MCP info', 'agent-role' ) . '</h2>';
		echo '<p>' . esc_html__( 'The application password is not stored. Replace the placeholder with the password you saved when this agent was created.', 'agent-role' ) . '</p>';
		self::render_copy_row( __( 'Site URL', 'agent-role' ), home_url( '/' ) );
		self::render_copy_row( __( 'Username', 'agent-role' ), $agent->user_login );
		self::render_copy_row( __( 'Application password', 'agent-role' ), Agent_Role_Mcp::PASSWORD_PLACEHOLDER );
		self::render_copy_row( __( 'MCP endpoint', 'agent-role' ), Agent_Role_Mcp::endpoint() );
		self::render_code_block( __( 'Client config', 'agent-role' ), $json );
		echo '<p class="ar-rf-modal__actions"><button type="submit" class="button button-primary" value="close">' . esc_html__( 'Close', 'agent-role' ) . '</button></p>';
	}

	/**
	 * Info mark for the note that MCP needs an application password.
	 */
	private static function info_icon() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ar-rf-mcp-notice__icon" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 11.5V16"/><circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/></svg>'
		);
	}

	/**
	 * Clipboard icon for copying MCP info from the list.
	 */
	private static function clipboard_icon() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ar-rf-mcp-copy__icon" aria-hidden="true" focusable="false"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>'
		);
	}

	/**
	 * Plus icon matching Site Functions. Core icon on WordPress 7.1+, Dashicons before that.
	 */
	private static function plus_icon() {
		if ( function_exists( 'wp_get_icon' ) ) {
			$icon = wp_get_icon(
				'core/plus',
				array(
					'size'  => 20,
					'class' => 'ar-new-agent__icon',
				)
			);
			if ( is_string( $icon ) && '' !== $icon ) {
				return self::kses_icon( $icon );
			}
		}

		return '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>';
	}

	/**
	 * Left arrow for the link back to the agent list.
	 */
	private static function back_arrow() {
		if ( function_exists( 'wp_get_icon' ) ) {
			$icon = wp_get_icon(
				'core/arrow-left',
				array(
					'size'  => 22,
					'class' => 'ar-rf-back__icon',
				)
			);
			if ( is_string( $icon ) && '' !== $icon ) {
				return self::kses_icon( $icon );
			}
		}

		return '<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>';
	}

	/**
	 * Profile mark for an Agent: a rounded frame, the letters AI, and a few sparkles.
	 */
	private static function agent_mark() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64" fill="none" class="ar-rf-profile__mark" aria-hidden="true" focusable="false"><path d="M20 14h10M50 26v18a8 8 0 0 1-8 8H20a8 8 0 0 1-8-8V22a8 8 0 0 1 8-8h2" stroke="#4b5e2e" stroke-width="4" stroke-linecap="round"/><text x="30" y="40" text-anchor="middle" fill="#4b5e2e" font-size="18" font-weight="700" font-family="Nunito Sans, system-ui, sans-serif">AI</text><path fill="#4b5e2e" d="M48 8l1.4 4.4 4.4 1.4-4.4 1.4L48 19.6l-1.4-4.4-4.4-1.4 4.4-1.4zM57 20l.8 2.2 2.2.8-2.2.8L57 26l-.8-2.2-2.2-.8 2.2-.8zM40 6l.6 1.5 1.5.6-1.5.6L40 10.2l-.6-1.5-1.5-.6 1.5-.6z"/></svg>'
		);
	}

	/**
	 * SVG icon markup from Core or from this plugin.
	 *
	 * @param string $icon SVG markup.
	 * @return string
	 */
	private static function kses_icon( $icon ) {
		return wp_kses(
			$icon,
			array(
				'svg'  => array(
					'xmlns'           => true,
					'width'           => true,
					'height'          => true,
					'viewbox'         => true,
					'fill'            => true,
					'stroke'          => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
					'class'           => true,
					'aria-hidden'     => true,
					'focusable'       => true,
					'role'            => true,
				),
				'path' => array(
					'd'               => true,
					'fill'            => true,
					'stroke'          => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
				),
				'rect'   => array(
					'width'  => true,
					'height' => true,
					'x'      => true,
					'y'      => true,
					'rx'     => true,
					'ry'     => true,
					'fill'   => true,
					'stroke' => true,
				),
				'circle' => array(
					'cx'     => true,
					'cy'     => true,
					'r'      => true,
					'fill'   => true,
					'stroke' => true,
				),
				'text'   => array(
					'x'           => true,
					'y'           => true,
					'fill'        => true,
					'font-size'   => true,
					'font-weight' => true,
					'font-family' => true,
					'text-anchor' => true,
				),
			)
		);
	}

	/**
	 * The agent named in the URL, false when the ID is not an Agent, null when absent.
	 *
	 * @return WP_User|false|null
	 */
	private static function requested_agent() {
		if ( ! isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
			return null;
		}

		$user = get_userdata( absint( wp_unslash( $_GET['user_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return false;
		}

		return $user;
	}

	/**
	 * Capabilities and abilities for one Agent.
	 *
	 * @param WP_User $agent Agent account.
	 */
	private static function render_agent_screen( $agent ) {
		$list_url = admin_url( 'users.php?page=agent-role&tab=agents' );
		echo '<p class="ar-rf-back"><a href="' . esc_url( $list_url ) . '">';
		echo self::back_arrow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in back_arrow().
		echo esc_html__( 'Agents', 'agent-role' );
		echo '</a></p>';

		// The flag only chooses the notice. Saving is checked in handle_save_agent().
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agent updated.', 'agent-role' ) . '</p></div>';
		}

		$saved_caps = get_user_meta( $agent->ID, Agent_Role::CAPS_META, true );
		$caps       = is_array( $saved_caps ) ? $saved_caps : array();
		if ( ! is_array( $saved_caps ) ) {
			foreach ( Agent_Role::cap_choices() as $cap => $choice ) {
				unset( $choice );
				$caps[ $cap ] = $agent->has_cap( $cap );
			}
		}

		$saved_abilities = get_user_meta( $agent->ID, Agent_Role::ABILITIES_META, true );
		$abilities       = is_array( $saved_abilities ) ? $saved_abilities : Agent_Role::abilities_allowed_now( $agent->ID );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agent_role_save_agent_' . $agent->ID, 'agent_role_agent_nonce' );
		echo '<input type="hidden" name="action" value="agent_role_save_agent" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $agent->ID ) . '" />';

		echo '<div class="ar-rf-panel">';
		echo '<div class="ar-rf-profile">';
		echo self::agent_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in agent_mark().
		echo '<div class="ar-rf-profile__text">';
		echo '<p class="ar-rf-profile__name">' . esc_html( $agent->display_name ) . '</p>';
		echo '<p class="ar-rf-profile__login">' . esc_html( $agent->user_login ) . '</p>';
		echo '</div></div>';

		echo '<h3>' . esc_html__( 'What an Agent can do', 'agent-role' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Read stays on. These switches apply to this agent only. Delete is the destructive pair.', 'agent-role' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Agent_Role::cap_choices() as $cap => $choice ) {
			$label_id = 'agent-role-cap-' . $agent->ID . '-' . $cap;
			$hint_id  = $label_id . '-note';
			echo '<tr><th scope="row">';
			echo '<span class="ar-rf-setting__label" id="' . esc_attr( $label_id ) . '">' . esc_html( $choice['label'] ) . '</span>';
			if ( $choice['destructive'] ) {
				echo '<span class="ar-rf-setting__hint" id="' . esc_attr( $hint_id ) . '">' . esc_html__( 'This can delete content.', 'agent-role' ) . '</span>';
			}
			echo '</th><td>';
			self::render_toggle(
				array(
					'name'        => 'agent_role_caps[]',
					'value'       => $cap,
					'checked'     => ! empty( $caps[ $cap ] ),
					'labelledby'  => $label_id,
					'describedby' => $choice['destructive'] ? $hint_id : '',
				)
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Abilities', 'agent-role' ) . '</h3>';
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			echo '<p class="description">' . esc_html__( 'This site does not have the Abilities API.', 'agent-role' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Turn an ability on to let this agent run it. These switches apply to this agent only.', 'agent-role' ) . '</p>';
			echo '<ul class="ar-rf-abilities">';
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! Agent_Role::is_listed_ability( $ability ) ) {
					continue;
				}
				$name     = $ability->get_name();
				$label_id = 'agent-role-ability-' . $agent->ID . '-' . sanitize_html_class( str_replace( '/', '-', $name ) );
				echo '<li class="ar-rf-ability">';
				echo '<span class="ar-rf-ability__name" id="' . esc_attr( $label_id ) . '">' . esc_html( $ability->get_label() ) . '</span>';
				self::render_toggle(
					array(
						'name'       => 'agent_role_abilities[]',
						'value'      => $name,
						'checked'    => ! empty( $abilities[ $name ] ),
						'labelledby' => $label_id,
					)
				);
				echo '</li>';
			}
			echo '</ul>';
		}

		$instructions = Agent_Role::instructions_for( $agent );
		echo '<h3 id="agent-role-instructions">' . esc_html__( 'Instructions', 'agent-role' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Sent when this agent connects over MCP. Reset fills this box from the switches. Generate drafts a version with AI. Update Agent saves the box. If you change the switches and leave this box untouched, the hint is rewritten from the switches.', 'agent-role' ) . '</p>';
		echo '<textarea class="ar-rf-instructions" id="agent_role_instructions" name="agent_role_instructions" rows="14" aria-labelledby="agent-role-instructions">';
		echo esc_textarea( $instructions );
		echo '</textarea>';
		echo '<p class="ar-rf-draft">';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<button type="button" class="button" id="ar-draft-instructions" data-user="' . esc_attr( (string) $agent->ID ) . '">';
			echo esc_html__( 'Generate Instructions based on your website', 'agent-role' );
			echo '</button>';
		}
		echo '<button type="button" class="button" id="ar-reset-instructions" data-user="' . esc_attr( (string) $agent->ID ) . '">';
		echo esc_html__( 'Reset Instructions', 'agent-role' );
		echo '</button></p>';
		echo '<p class="ar-rf-draft__status" id="ar-draft-instructions-status" aria-live="polite"></p>';

		submit_button( __( 'Update Agent', 'agent-role' ) );
		echo '</div></form>';
	}

	/**
	 * MCP Adapter setting. Capability and ability switches live on each agent.
	 */
	private static function render_settings_tab() {
		if ( ! Agent_Role_Mcp::is_available() ) {
			echo '<p class="description">' . esc_html__( 'The MCP Adapter is not active.', 'agent-role' ) . '</p>';
			return;
		}

		self::render_mcp_setting();
	}

	/**
	 * A checkbox drawn as a switch, with Enabled or Disabled beside it.
	 *
	 * The status is one text node, uppercased in CSS. It is part of the accessible
	 * name, so the word a person sees is the word a screen reader says. Script
	 * updates that node when the switch moves. Color is not the only signal.
	 *
	 * @param array $args name, value, checked, labelledby, note, note_is_label.
	 */
	private static function render_toggle( $args ) {
		$name          = $args['name'];
		$value         = $args['value'];
		$checked       = ! empty( $args['checked'] );
		$labelledby    = $args['labelledby'];
		$note          = isset( $args['note'] ) ? $args['note'] : '';
		$note_is_label = ! empty( $args['note_is_label'] );
		$note_id       = $note_is_label ? $labelledby : $labelledby . '-note';
		$describedby   = isset( $args['describedby'] ) ? $args['describedby'] : '';
		$state_id      = $labelledby . '-state';

		echo '<label class="ar-rf-toggle">';
		echo '<input class="ar-rf-toggle__input" type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';
		echo ' aria-labelledby="' . esc_attr( $labelledby . ' ' . $state_id ) . '"';
		if ( $describedby ) {
			echo ' aria-describedby="' . esc_attr( $describedby ) . '"';
		} elseif ( $note && ! $note_is_label ) {
			echo ' aria-describedby="' . esc_attr( $note_id ) . '"';
		}
		echo ' ' . checked( $checked, true, false ) . ' />';
		echo '<span class="ar-rf-toggle__track" aria-hidden="true"></span>';
		echo '<span class="ar-rf-toggle__state" id="' . esc_attr( $state_id ) . '" data-enabled="' . esc_attr__( 'Enabled', 'agent-role' ) . '" data-disabled="' . esc_attr__( 'Disabled', 'agent-role' ) . '">';
		echo esc_html( $checked ? __( 'Enabled', 'agent-role' ) : __( 'Disabled', 'agent-role' ) );
		echo '</span>';
		if ( $note ) {
			echo '<span class="ar-rf-toggle__note" id="' . esc_attr( $note_id ) . '">' . esc_html( $note ) . '</span>';
		}
		echo '</label>';
	}

	/**
	 * Copyable credentials. Used inside the modal after an account is created.
	 *
	 * @param WP_User $agent    Agent account.
	 * @param string  $password Application password.
	 */
	private static function render_credentials_body( $agent, $password ) {
		echo '<h2>' . esc_html__( 'Agent connected', 'agent-role' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy these now. They will not be shown again.', 'agent-role' ) . '</p>';
		self::render_copy_row( __( 'Site URL', 'agent-role' ), home_url( '/' ) );
		self::render_copy_row( __( 'Username', 'agent-role' ), $agent->user_login );
		self::render_copy_row( __( 'Application password', 'agent-role' ), $password );
		self::render_mcp_config( $agent->user_login, $password );
		echo '<p class="ar-rf-modal__actions"><button type="submit" class="button button-primary" value="close">' . esc_html__( 'Close', 'agent-role' ) . '</button></p>';
	}

	/**
	 * Create an agent from the modal without leaving the page.
	 */
	public static function handle_create_ajax() {
		check_ajax_referer( 'agent_role_add_agent', 'nonce' );
		if ( ! current_user_can( 'create_users' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to create users.', 'agent-role' ) ),
				403
			);
		}

		$username = isset( $_POST['agent_role_username'] ) ? sanitize_user( wp_unslash( $_POST['agent_role_username'] ), true ) : '';
		$display  = isset( $_POST['agent_role_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_role_display_name'] ) ) : '';
		$result   = Agent_Role_Account::create( $username, $display, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$agent    = get_userdata( $result['user_id'] );
		$password = Agent_Role_Account::take_password( get_current_user_id(), $result['user_id'] );
		if ( ! $agent instanceof WP_User || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'The account was created, but the password could not be shown.', 'agent-role' ) ) );
		}

		ob_start();
		self::render_credentials_body( $agent, $password );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Put an AI draft in the textarea. Update Agent is what stores it.
	 */
	public static function handle_draft_instructions() {
		check_ajax_referer( 'agent_role_draft_instructions', 'nonce' );
		$user = self::agent_from_post();
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ), (int) $user->get_error_data() );
		}

		list( $caps, $abilities ) = self::posted_maps();
		$text                     = Agent_Role_Brief::draft( $user->ID, $caps, $abilities );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}

		wp_send_json_success( array( 'text' => $text ) );
	}

	/**
	 * Put the PHP hint for the current switches in the textarea. Update Agent stores it.
	 */
	public static function handle_reset_instructions() {
		check_ajax_referer( 'agent_role_reset_instructions', 'nonce' );
		$user = self::agent_from_post();
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ), (int) $user->get_error_data() );
		}

		list( $caps, $abilities ) = self::posted_maps();
		wp_send_json_success(
			array(
				'text' => Agent_Role::compose_instructions( $user, $caps, $abilities ),
			)
		);
	}

	/**
	 * Save one agent's capabilities and abilities.
	 *
	 * An untouched instructions box is rewritten from the new switches.
	 * A box changed by Generate, Reset, or typing is stored as written.
	 */
	public static function handle_save_agent() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'agent-role' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		check_admin_referer( 'agent_role_save_agent_' . $user_id, 'agent_role_agent_nonce' );

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			wp_die( esc_html__( 'That account is not an Agent.', 'agent-role' ), '', array( 'response' => 400 ) );
		}

		$previous = Agent_Role::instructions_for( $user );
		$posted   = isset( $_POST['agent_role_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['agent_role_instructions'] ) ) : '';

		list( $caps, $abilities ) = self::posted_maps();
		update_user_meta( $user_id, Agent_Role::CAPS_META, $caps );
		update_user_meta( $user_id, Agent_Role::ABILITIES_META, $abilities );

		if ( self::normalize_instructions( $posted ) === self::normalize_instructions( $previous ) ) {
			Agent_Role::store_instructions( $user_id );
		} else {
			update_user_meta( $user_id, Agent_Role::INSTRUCTIONS_META, self::normalize_instructions( $posted ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'agent-role',
					'user_id' => $user_id,
					'updated' => '1',
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * The Agent named in the AJAX request.
	 *
	 * @return WP_User|WP_Error
	 */
	private static function agent_from_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'agent_role_forbidden',
				__( 'You do not have permission to change this setting.', 'agent-role' ),
				403
			);
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce.
		$user    = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return new WP_Error(
				'agent_role_not_agent',
				__( 'That account is not an Agent.', 'agent-role' ),
				400
			);
		}

		return $user;
	}

	/**
	 * Capability and ability maps from the current form post.
	 *
	 * @return array{0:array<string,bool>,1:array<string,bool>}
	 */
	private static function posted_maps() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce.
		$chosen_caps = isset( $_POST['agent_role_caps'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['agent_role_caps'] ) ) : array();
		$caps        = array();
		foreach ( Agent_Role::cap_choices() as $cap => $choice ) {
			unset( $choice );
			$caps[ $cap ] = in_array( $cap, $chosen_caps, true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce.
		$chosen_abilities = isset( $_POST['agent_role_abilities'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['agent_role_abilities'] ) ) : array();
		$abilities        = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! Agent_Role::is_listed_ability( $ability ) ) {
					continue;
				}
				$name               = $ability->get_name();
				$abilities[ $name ] = in_array( $name, $chosen_abilities, true );
			}
		}

		return array( $caps, $abilities );
	}

	/**
	 * Compare instruction text without newline differences.
	 *
	 * @param string $text Instruction text.
	 */
	private static function normalize_instructions( $text ) {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
	}

	/**
	 * Client config in the CDS code block.
	 *
	 * @param string $label Visible name.
	 * @param string $code  Text to show and copy.
	 */
	private static function render_code_block( $label, $code ) {
		echo '<p class="ar-rf-code-label">' . esc_html( $label ) . '</p>';
		echo '<div class="rf-code">';
		echo '<div class="rf-code__chrome">';
		echo '<div class="rf-code__dots" aria-hidden="true"><span></span><span></span><span></span></div>';
		echo '<button type="button" class="rf-code__copy" aria-label="' . esc_attr__( 'Copy client config', 'agent-role' ) . '">';
		echo self::kses_icon( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in kses_icon().
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6A2.25 2.25 0 0014.25 3.75H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18A2.25 2.25 0 0120.25 10.5V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6A2.25 2.25 0 008.25 10.5v6"/></svg>'
		);
		echo '</button></div>';
		echo '<pre class="rf-code__body"><code>' . esc_html( $code ) . '</code></pre>';
		echo '<span class="rf-code__lang">' . esc_html__( 'JSON', 'agent-role' ) . '</span>';
		echo '</div>';
	}

	/**
	 * A labeled value with a copy button.
	 *
	 * @param string $label Visible label.
	 * @param string $value Text to copy.
	 */
	private static function render_copy_row( $label, $value ) {
		echo '<div class="ar-rf-copy">';
		echo '<span class="ar-rf-copy__label">' . esc_html( $label ) . '</span>';
		echo '<code class="ar-rf-copy__value">' . esc_html( $value ) . '</code>';
		echo '<button type="button" class="button ar-rf-copy__button">' . esc_html__( 'Copy', 'agent-role' ) . '</button>';
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

		self::render_copy_row( __( 'MCP endpoint', 'agent-role' ), Agent_Role_Mcp::endpoint() );
		self::render_code_block( __( 'Client config', 'agent-role' ), (string) $json );
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
		echo '<p>';
		self::render_toggle(
			array(
				'name'          => 'agent_role_mcp_agents_only',
				'value'         => '1',
				'checked'       => Agent_Role_Mcp::agents_only(),
				'labelledby'    => 'agent-role-mcp-label',
				'note'          => __( 'Only Agent accounts may use the MCP server.', 'agent-role' ),
				'note_is_label' => true,
			)
		);
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'When off, the MCP Adapter accepts any logged-in user, as it ships.', 'agent-role' ) . '</p>';
		submit_button( __( 'Save MCP Setting', 'agent-role' ) );
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

		wp_safe_redirect( admin_url( 'users.php?page=agent-role&tab=settings' ) );
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
