<?php
/**
 * Users → Agents screen.
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
		add_action( 'admin_post_agent_role_log_setting', array( __CLASS__, 'handle_log_setting' ) );
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<p>' . esc_html__(
			/* translators: "Users" and "Agents" are WordPress admin menu labels. */
			'To give an agent access to this site, create an Agent under Users → Agents.',
			'agent-role'
		) . '</p>';
	}

	/**
	 * Add the Users submenu.
	 */
	public static function menu() {
		add_users_page(
			__( 'Agent Role', 'agent-role' ),
			__( 'Agents', 'agent-role' ),
			'manage_options',
			'agent-role',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the settings styles and copy buttons on the Agents screen only.
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
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_script(
			'agent-role-admin',
			plugins_url( 'admin/js/agent-role-admin.js', AGENT_ROLE_FILE ),
			array( 'jquery-ui-datepicker' ),
			$version,
			true
		);
		wp_localize_script(
			'agent-role-admin',
			'agentRoleAdmin',
			array(
				/* translators: Shown on a Copy button after the value is copied. */
				'copied'     => __( 'Copied', 'agent-role' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'agent_role_add_agent' ),
				'draftNonce' => wp_create_nonce( 'agent_role_draft_instructions' ),
				'resetNonce' => wp_create_nonce( 'agent_role_reset_instructions' ),
				'drafting'   => __( 'Writing instructions…', 'agent-role' ),
				/* translators: "Update Agent" is the primary submit button on this screen. */
				'draftDone'  => __( 'Draft is in the box. Click "Update Agent" to save it.', 'agent-role' ),
				'resetting'  => __( 'Loading this persona’s default instructions…', 'agent-role' ),
				/* translators: "Update Agent" is the primary submit button on this screen. */
				'resetDone'  => __( 'This persona’s default instructions are in the box. Click "Update Agent" to save them.', 'agent-role' ),
				'logDates'   => Agent_Role_Log::logged_dates(),
			)
		);
	}

	/**
	 * Render the form, the one-time password, and existing agents.
	 */
	public static function render() {
		self::require_manage_options();

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
		echo '<img class="ar-rf-settings__mark" src="' . esc_url( plugins_url( 'admin/images/agent-mark.png', AGENT_ROLE_FILE ) ) . '" alt="" width="56" height="56" />';
		echo esc_html__( 'Agent Role', 'agent-role' );
		echo '</h1>';
		echo '<p class="ar-rf-settings__lede">' . esc_html__( 'A dedicated Agent role for your AI tools to interact with your website based on the rules you set.', 'agent-role' ) . '</p>';
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
				echo '<div class="notice notice-error"><p>' . esc_html__( 'That user is not an Agent.', 'agent-role' ) . '</p></div>';
			}
			$tab = self::current_tab();
			echo '<div class="rf-tabs">';
			self::render_tabs();
			echo '<div class="rf-tabs__panel">';
			if ( 'settings' === $tab ) {
				self::render_settings_tab();
			} elseif ( 'activity' === $tab ) {
				self::render_activity_tab();
			} else {
				self::render_agents_tab();
			}
			echo '</div></div>';
			if ( 'agents' === $tab ) {
				self::render_agent_dialogs( $password, $agent );
			}
		}

		echo '</div>';
		self::render_footer();
		echo '</div>';
	}

	/**
	 * Sticky bar on every Agent Role screen. Link targets are placeholders.
	 */
	private static function render_footer() {
		echo '<footer class="ar-rf-footer">';
		echo '<a class="ar-rf-footer__rate" href="#">' . esc_html__( 'Like Agent Role? Give us a 5-★ Rating Here', 'agent-role' ) . '</a>';
		echo '<nav class="ar-rf-footer__links" aria-label="' . esc_attr__( 'Plugin links', 'agent-role' ) . '">';
		echo '<a href="#">' . esc_html__( 'Docs', 'agent-role' ) . '</a>';
		echo '<a href="#">' . esc_html__( 'Feedback', 'agent-role' ) . '</a>';
		echo '<a href="#">' . esc_html__( 'Support', 'agent-role' ) . '</a>';
		echo '</nav>';
		echo '<button type="button" class="ar-rf-footer__brand" aria-expanded="false" aria-controls="ar-rf-about">';
		echo '<img src="' . esc_url( plugins_url( 'admin/images/rf-logo.svg', AGENT_ROLE_FILE ) ) . '" alt="' . esc_attr__( 'Roots and Fruit', 'agent-role' ) . '" width="120" height="53" />';
		echo '</button>';
		echo '</footer>';

		echo '<aside id="ar-rf-about" class="ar-rf-about" hidden>';
		echo '<p class="ar-rf-about__hello"><strong>' . esc_html__( 'Hi, I’m Matt!', 'agent-role' ) . '</strong>';
		echo '<span>' . esc_html__( 'But most folks call me Cromwell.', 'agent-role' ) . '</span></p>';
		echo '<p>' . esc_html__( 'Roots & Fruit is my digital product practice for solopreneurs and product teams: sustainable growth focused on CX and Marketing driven ROI.', 'agent-role' ) . '</p>';
		echo '<h2 class="ar-rf-about__heading">' . esc_html__( 'About Agent Role', 'agent-role' ) . '</h2>';
		echo '<p>' . esc_html__( 'Agent Role is a simple plugin designed to help you connect your agents to your WordPress website with just the right amount of abilities it needs and no more. It has three Core User Commitments:', 'agent-role' ) . '</p>';
		echo '<ol class="ar-rf-about__list">';
		echo '<li>' . esc_html__( 'An Agent cannot sign in as a person.', 'agent-role' ) . '</li>';
		echo '<li>' . esc_html__( 'Permissions stay on that Agent’s account.', 'agent-role' ) . '</li>';
		echo '<li>' . esc_html__( 'The application password is shown once, and it is not stored.', 'agent-role' ) . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__( 'I’m always available to chat.', 'agent-role' ) . ' <a href="#">' . esc_html__( 'Here’s my comment form.', 'agent-role' ) . '</a></p>';
		echo '<div class="ar-rf-about__person">';
		echo '<img class="ar-rf-about__photo" src="' . esc_url( plugins_url( 'admin/images/matt-cromwell.jpg', AGENT_ROLE_FILE ) ) . '" alt="" width="64" height="64" />';
		echo '<p class="ar-rf-about__id"><strong>' . esc_html__( 'Matt Cromwell', 'agent-role' ) . '</strong>';
		echo '<span>' . esc_html__( 'Founder and CGO at Roots and Fruit', 'agent-role' ) . '</span></p>';
		echo '</div></aside>';
	}

	/**
	 * Agents or Settings.
	 */
	private static function current_tab() {
		// Tab only chooses which view to render. It does not change data.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agents'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'settings' === $tab || 'activity' === $tab ) {
			return $tab;
		}
		return 'agents';
	}

	/**
	 * Folder tabs. Each link reloads the screen so filters and the agent editor keep working.
	 */
	private static function render_tabs() {
		$current = self::current_tab();
		echo '<nav class="rf-tabs__list" aria-label="' . esc_attr__( 'Agent Role', 'agent-role' ) . '">';
		self::render_tab_link( 'agents', __( 'Agents', 'agent-role' ), $current );
		self::render_tab_link( 'settings', __( 'Settings', 'agent-role' ), $current );
		self::render_tab_link( 'activity', __( 'Activity', 'agent-role' ), $current );
		echo '</nav>';
	}

	/**
	 * One folder tab.
	 *
	 * @param string $slug    Tab query value.
	 * @param string $label   Visible label.
	 * @param string $current Active tab slug.
	 */
	private static function render_tab_link( $slug, $label, $current ) {
		$active = $slug === $current;
		$url    = add_query_arg(
			array(
				'page' => 'agent-role',
				'tab'  => $slug,
			),
			admin_url( 'users.php' )
		);

		echo '<a class="rf-tabs__tab' . ( $active ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"';
		if ( $active ) {
			echo ' aria-current="page"';
		}
		echo '>';
		echo '<span class="rf-tabs__dot" aria-hidden="true"></span>';
		echo esc_html( $label );
		echo self::tab_shoulder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in tab_shoulder().
		echo '</a>';
	}

	/**
	 * Slanted right edge of a folder tab.
	 */
	private static function tab_shoulder() {
		return self::kses_icon(
			'<svg class="rf-tabs__shoulder" viewBox="0 0 48 40" width="48" height="40" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path class="rf-tabs__shoulder-face" d="M0 0H20Q26 0 30 8L46 40H0Z" /><path class="rf-tabs__shoulder-edge" d="M0 .5H20Q26 .5 30 8.5L46 40" /></svg>'
		);
	}

	/**
	 * Turn a date from the filter into a site-local datetime bound.
	 *
	 * @param string $raw        Submitted date text.
	 * @param bool   $end_of_day True for the inclusive end of the range.
	 */
	private static function activity_bound( $raw, $end_of_day ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$dt = date_create_immutable( $raw, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}

		$dt = $end_of_day ? $dt->setTime( 23, 59, 59 ) : $dt->setTime( 0, 0, 0 );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Show a stored bound with the General Settings date format.
	 *
	 * @param string $mysql Site-local datetime, or empty.
	 */
	private static function activity_input_value( $mysql ) {
		if ( '' === $mysql ) {
			return '';
		}

		$dt = date_create_immutable( $mysql, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Activity across every Agent, filtered by account and event type.
	 */
	private static function render_activity_tab() {
		$agent_filter = isset( $_GET['agent'] ) ? absint( wp_unslash( $_GET['agent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_filter = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $event_filter, Agent_Role_Log::TYPES, true ) ) {
			$event_filter = '';
		}

		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to_raw   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_sql = self::activity_bound( $from_raw, false );
		$to_sql   = self::activity_bound( $to_raw, true );

		$agents   = get_users(
			array(
				'role'   => Agent_Role::SLUG,
				'fields' => array( 'ID', 'display_name' ),
			)
		);
		$rows     = Agent_Role_Log::query( $agent_filter, $event_filter, $from_sql, $to_sql );
		$labels   = array(
			'ability' => __( 'Ability Calls', 'agent-role' ),
			'rest'    => __( 'REST API', 'agent-role' ),
			'admin'   => __( 'Admin Changes', 'agent-role' ),
		);
		$outcomes = array(
			'success' => __( 'Success', 'agent-role' ),
			'denied'  => __( 'Denied', 'agent-role' ),
			'changed' => __( 'Changed', 'agent-role' ),
			'error'   => __( 'Error', 'agent-role' ),
		);

		echo '<div class="ar-rf-panel">';
		echo '<div class="ar-rf-toolbar ar-rf-activity__head">';
		echo '<div>';
		echo '<h2>' . esc_html__( 'Activity', 'agent-role' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A record of agent actions and access changes.', 'agent-role' ) . '</p>';
		echo '</div>';
		echo '</div>';
		$count = sprintf(
			/* translators: %d: number of visible activity rows. */
			_n( '%d event', '%d events', count( $rows ), 'agent-role' ),
			count( $rows )
		);
		echo '<div class="ar-rf-activity__meta">';
		echo '<p class="ar-rf-activity__note">' . esc_html(
			sprintf(
				/* translators: 1: days to keep events, 2: maximum events per agent. */
				__( 'Retained for %1$d days (capped at %2$d events per agent)', 'agent-role' ),
				Agent_Role_Log::days(),
				Agent_Role_Log::cap()
			)
		) . '</p>';
		echo '<p class="ar-rf-activity__count">' . esc_html( $count ) . '</p>';
		echo '</div>';

		echo '<form method="get" class="ar-rf-activity__filters">';
		echo '<input type="hidden" name="page" value="agent-role" />';
		echo '<input type="hidden" name="tab" value="activity" />';
		echo '<label class="ar-rf-field"><span class="ar-rf-field__label">' . esc_html__( 'Agent', 'agent-role' ) . '</span>';
		echo '<select class="ar-rf-select" name="agent">';
		echo '<option value="">' . esc_html__( 'All Agents', 'agent-role' ) . '</option>';
		foreach ( $agents as $agent_user ) {
			echo '<option value="' . esc_attr( (string) $agent_user->ID ) . '" ' . selected( $agent_filter, (int) $agent_user->ID, false ) . '>' . esc_html( $agent_user->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="ar-rf-field"><span class="ar-rf-field__label">' . esc_html__( 'Event type', 'agent-role' ) . '</span>';
		echo '<select class="ar-rf-select" name="event">';
		echo '<option value="">' . esc_html__( 'All Events', 'agent-role' ) . '</option>';
		foreach ( $labels as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( $event_filter, $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="ar-rf-field"><span class="ar-rf-field__label">' . esc_html_x( 'From', 'activity log start date', 'agent-role' ) . '</span>';
		echo '<input class="ar-rf-select ar-rf-date ar-rf-date--from" type="text" name="from" value="' . esc_attr( self::activity_input_value( $from_sql ) ) . '" autocomplete="off" />';
		echo '</label>';
		echo '<label class="ar-rf-field"><span class="ar-rf-field__label">' . esc_html_x( 'To', 'activity log end date', 'agent-role' ) . '</span>';
		echo '<input class="ar-rf-select ar-rf-date ar-rf-date--to" type="text" name="to" value="' . esc_attr( self::activity_input_value( $to_sql ) ) . '" autocomplete="off" />';
		echo '</label>';
		echo '<span class="ar-rf-field ar-rf-field--action">';
		echo '<span class="ar-rf-field__label" aria-hidden="true">&#160;</span>';
		echo '<button type="submit" class="button ar-rf-filter">' . esc_html__( 'Filter', 'agent-role' ) . '</button>';
		echo '</span>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No agent activity yet.', 'agent-role' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat ar-rf-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'agent-role' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'agent-role' ) . '</th>';
		echo '<th>' . esc_html__( 'Event type', 'agent-role' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'agent-role' ) . '</th>';
		echo '<th>' . esc_html__( 'Outcome', 'agent-role' ) . '</th>';
		echo '</tr></thead><tbody>';
		$deleted_at = Agent_Role_Log::deleted_at( array_column( $rows, 'user_id' ) );
		foreach ( $rows as $row ) {
			$user    = get_userdata( (int) $row['user_id'] );
			$name    = $user instanceof WP_User ? $user->display_name : (string) $row['user_id'];
			$local   = Agent_Role_Log::format_timestamp( $row['created_at'] );
			$type    = isset( $labels[ $row['event_type'] ] ) ? $labels[ $row['event_type'] ] : $row['event_type'];
			$outcome = isset( $outcomes[ $row['outcome'] ] ) ? $outcomes[ $row['outcome'] ] : $row['outcome'];
			echo '<tr>';
			echo '<td>' . esc_html( $local ) . '</td>';
			echo '<td class="ar-rf-activity__agent">';
			if ( $user instanceof WP_User ) {
				$edit_url = add_query_arg(
					array(
						'page'    => 'agent-role',
						'user_id' => (int) $user->ID,
					),
					admin_url( 'users.php' )
				);
				echo '<a class="ar-rf-agent-link" href="' . esc_url( $edit_url ) . '">' . esc_html( $name ) . '</a>';
			} else {
				echo esc_html( $name );
				$when = isset( $deleted_at[ (int) $row['user_id'] ] ) ? $deleted_at[ (int) $row['user_id'] ] : '';
				echo self::deleted_agent_mark( $when ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in deleted_agent_mark().
			}
			echo '</td>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td><span class="ar-rf-activity__detail">' . esc_html( $row['detail'] ) . '</span>';
			if ( $row['identifier'] !== $row['detail'] ) {
				echo '<span class="ar-rf-activity__id">' . esc_html( $row['identifier'] ) . '</span>';
			}
			echo '</td>';
			echo '<td><span class="ar-rf-outcome ar-rf-outcome--' . esc_attr( $row['outcome'] ) . '">' . esc_html( $outcome ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Red info mark for an agent account that no longer exists.
	 *
	 * @param string $deleted_at Site-local deletion time, or empty when unknown.
	 */
	private static function deleted_agent_mark( $deleted_at ) {
		if ( '' !== $deleted_at ) {
			$text = sprintf(
				/* translators: %s: date and time the agent account was deleted. */
				__( 'This agent account was deleted on %s.', 'agent-role' ),
				Agent_Role_Log::format_timestamp( $deleted_at )
			);
		} else {
			$text = __( 'This agent account was deleted.', 'agent-role' );
		}

		return '<span class="ar-rf-deleted">'
			. '<button type="button" class="ar-rf-deleted__icon" aria-label="' . esc_attr( $text ) . '">i</button>'
			. '<span class="ar-rf-deleted__tip" role="tooltip">' . esc_html( $text ) . '</span>'
			. '</span>';
	}

	/**
	 * The agent list.
	 */
	private static function render_agents_tab() {
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
			echo '<th>' . esc_html__( 'Agent', 'agent-role' ) . '</th>';
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
					echo '<button type="submit" class="button-link ar-rf-revoke">';
					echo self::circle_x_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in circle_x_icon().
					echo esc_html__( 'Revoke', 'agent-role' );
					echo '</button>';
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
					echo '<button type="button" class="ar-rf-mcp-view" data-template="' . esc_attr( $template_id ) . '">';
					echo self::eye_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in eye_icon().
					echo esc_html__( 'View', 'agent-role' );
					echo '</button>';
					echo '<template id="' . esc_attr( $template_id ) . '">';
					self::render_mcp_preview( $agent_user );
					echo '</template>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'No agents yet. Create one when you are ready to connect a tool.', 'agent-role' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Create-agent and MCP dialogs. Kept outside the folder tab so its shadow cannot trap them.
	 *
	 * @param string        $password One-time password, or empty.
	 * @param WP_User|false $agent    Account the password belongs to.
	 */
	private static function render_agent_dialogs( $password, $agent ) {
		$show_result = '' !== $password && $agent instanceof WP_User;

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
	 * Prompt for an agent that already exists. The password is not stored.
	 *
	 * @param WP_User $agent Agent account.
	 */
	private static function render_mcp_preview( $agent ) {
		echo '<h2>' . esc_html__( 'MCP info', 'agent-role' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste this prompt into your agent. It will add this site and leave a line for the password.', 'agent-role' ) . '</p>';
		self::render_agent_prompt( $agent->user_login );
		echo '<p class="ar-rf-code-label">' . esc_html__( 'Application password', 'agent-role' ) . '</p>';
		echo '<p>' . esc_html__( 'You were provided the application password when you first created this agent. It is shown only that one time for security. If you no longer have that password, you\'ll need to revoke this one and create a new one. Save that password securely and ask your agent where it should be saved in your project.', 'agent-role' ) . '</p>';
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
	 * Circle with an x, for revoking an application password.
	 */
	private static function circle_x_icon() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ar-rf-link-icon" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>'
		);
	}

	/**
	 * Eye, for opening the MCP prompt.
	 */
	private static function eye_icon() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ar-rf-link-icon" aria-hidden="true" focusable="false"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>'
		);
	}

	/**
	 * Plug mark for a tool that comes from another plugin.
	 *
	 * The WordPress 7.1 icon library has no plug, so this is the Lucide plug.
	 */
	private static function plugin_badge_icon() {
		return self::kses_icon(
			'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ar-persona-source__icon" aria-hidden="true" focusable="false"><path d="M12 22v-5"/><path d="M15 8V2"/><path d="M17 8a1 1 0 0 1 1 1v4a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1z"/><path d="M9 8V2"/></svg>'
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
	 * Profile mark for an Agent.
	 */
	private static function agent_mark() {
		return '<img class="ar-rf-profile__mark" src="' . esc_url( plugins_url( 'admin/images/agent-mark.png', AGENT_ROLE_FILE ) ) . '" alt="" width="72" height="72" />';
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
				'svg'    => array(
					'xmlns'               => true,
					'width'               => true,
					'height'              => true,
					'viewbox'             => true,
					'preserveaspectratio' => true,
					'fill'                => true,
					'stroke'              => true,
					'stroke-width'        => true,
					'stroke-linecap'      => true,
					'stroke-linejoin'     => true,
					'class'               => true,
					'aria-hidden'         => true,
					'focusable'           => true,
					'role'                => true,
				),
				'path' => array(
					'class'           => true,
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
		echo esc_html__( 'Back to Agents', 'agent-role' );
		echo '</a></p>';

		// The flag only chooses the notice. Saving is checked in handle_save_agent().
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$persona_notice = isset( $_GET['persona_notice'] ) ? sanitize_key( wp_unslash( $_GET['persona_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages       = array(
				'save_default' => __( 'Saved as the default for this Agent persona.', 'agent-role' ),
				'reset'        => __( 'This agent now matches the saved default for this persona.', 'agent-role' ),
				'factory'      => __( 'This persona is reset to the plugin default. Other customized agent personas remain unchanged.', 'agent-role' ),
			);
			$message        = isset( $messages[ $persona_notice ] ) ? $messages[ $persona_notice ] : __( 'Agent updated.', 'agent-role' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			if ( isset( $_GET['stripped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Some tools were not saved as default because they need a permission this agent does not have.', 'agent-role' ) . '</p></div>';
			}
		}

		$caps  = Agent_Role::cap_map( $agent );
		$state = self::persona_state( $agent );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agent_role_save_agent_' . $agent->ID, 'agent_role_agent_nonce' );
		echo '<input type="hidden" name="action" value="agent_role_save_agent" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $agent->ID ) . '" />';

		echo '<div class="ar-rf-panel ar-rf-agent">';
		echo '<div class="ar-rf-profile"' . ( $state['color'] ? ' style="--ar-persona:' . esc_attr( $state['color'] ) . '"' : '' ) . '>';
		echo self::agent_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL is escaped in agent_mark().
		echo '<div class="ar-rf-profile__text">';
		echo '<h2 class="ar-rf-profile__name">' . esc_html( $agent->display_name ) . '</h2>';
		echo '<p class="ar-rf-profile__meta">';
		echo '<span class="ar-rf-profile__fact">';
		echo '<span class="ar-rf-profile__label">' . esc_html__( 'Username', 'agent-role' ) . '</span>';
		echo '<span class="ar-rf-profile__value">' . esc_html( $agent->user_login ) . '</span>';
		echo '</span>';
		echo '<span class="ar-rf-profile__fact" id="ar-profile-persona-line"' . ( $state['slug'] ? '' : ' hidden' ) . '>';
		echo '<span class="ar-rf-profile__label">' . esc_html__( 'Persona', 'agent-role' ) . '</span>';
		echo '<span class="ar-rf-profile__value" id="ar-profile-persona-name">' . esc_html( $state['label'] ) . '</span>';
		echo '</span>';
		echo '<span class="ar-rf-profile__badge" id="ar-profile-custom-badge"' . ( $state['custom'] ? '' : ' hidden' ) . '>' . esc_html__( 'Customized', 'agent-role' ) . '</span>';
		echo '</p>';
		echo '</div></div>';

		self::render_personas( $agent, $caps );

		submit_button( __( 'Update Agent', 'agent-role' ) );
		echo '</div></form>';
	}

	/**
	 * MCP Adapter setting. Capability and ability switches live on each agent.
	 */
	private static function render_settings_tab() {
		settings_errors();

		if ( Agent_Role_Mcp::is_available() ) {
			self::render_mcp_setting();
		} else {
			echo '<p class="description">' . esc_html__( 'The MCP Adapter is not active.', 'agent-role' ) . '</p>';
		}

		self::render_log_setting();
	}

	/**
	 * On and off labels for a switch.
	 *
	 * @return array{enabled:string,disabled:string}
	 */
	private static function toggle_labels() {
		return array(
			/* translators: Visible state of an on/off switch. */
			'enabled'  => _x( 'Enabled', 'toggle state', 'agent-role' ),
			/* translators: Visible state of an on/off switch. */
			'disabled' => _x( 'Disabled', 'toggle state', 'agent-role' ),
		);
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
		$toggle = self::toggle_labels();
		echo '<span class="ar-rf-toggle__state" id="' . esc_attr( $state_id ) . '" data-enabled="' . esc_attr( $toggle['enabled'] ) . '" data-disabled="' . esc_attr( $toggle['disabled'] ) . '">';
		echo esc_html( $checked ? $toggle['enabled'] : $toggle['disabled'] );
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
		if ( Agent_Role_Mcp::is_available() ) {
			echo '<p>' . esc_html__( 'Paste the prompt into your agent. It will set up MCP and tell you where to put this password. The password is shown once.', 'agent-role' ) . '</p>';
			self::render_agent_prompt( $agent->user_login );
			self::render_copy_row( __( 'Application password', 'agent-role' ), $password );
		} else {
			echo '<p>' . esc_html__( 'Copy these now. They will not be shown again.', 'agent-role' ) . '</p>';
			self::render_copy_row( __( 'Site URL', 'agent-role' ), home_url( '/' ) );
			self::render_copy_row( __( 'Username', 'agent-role' ), $agent->user_login );
			self::render_copy_row( __( 'Application password', 'agent-role' ), $password );
		}
		echo '<p class="ar-rf-modal__actions"><button type="submit" class="button button-primary" value="close">' . esc_html__( 'Close', 'agent-role' ) . '</button></p>';
	}

	/**
	 * Create an agent from the modal without leaving the page.
	 */
	public static function handle_create_ajax() {
		check_ajax_referer( 'agent_role_add_agent', 'nonce' );
		self::require_manage_options( true );

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
		$persona                  = isset( $_POST['agent_role_persona'] ) ? sanitize_key( wp_unslash( $_POST['agent_role_persona'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		try {
			$text = Agent_Role_Brief::draft( $user->ID, $caps, $abilities, $persona );
		} catch ( \Throwable $e ) {
			unset( $e );
			wp_send_json_error( array( 'message' => __( 'AI is not available on this site.', 'agent-role' ) ) );
		}
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}

		wp_send_json_success( array( 'text' => $text ) );
	}

	/**
	 * Put this persona's default instructions in the textarea. Update Agent stores them.
	 */
	public static function handle_reset_instructions() {
		check_ajax_referer( 'agent_role_reset_instructions', 'nonce' );
		$user = self::agent_from_post();
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ), (int) $user->get_error_data() );
		}

		$persona = isset( $_POST['agent_role_persona'] ) ? sanitize_key( wp_unslash( $_POST['agent_role_persona'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$shapes  = self::personas();
		if ( isset( $shapes[ $persona ] ) ) {
			$shape = self::site_persona_shape( $persona );
			$text  = $shape['instructions'];
		} else {
			$text = Agent_Role::compose_instructions( $user );
		}

		wp_send_json_success(
			array(
				'text' => $text,
			)
		);
	}

	/**
	 * Save one agent's capabilities, abilities, and instructions.
	 *
	 * Permissions and instructions stay independent. An untouched instruction
	 * box is not rewritten from the ability list. Picking a persona fills the
	 * box with that persona's default unless the box was already edited.
	 */
	public static function handle_save_agent() {
		self::require_manage_options();

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		check_admin_referer( 'agent_role_save_agent_' . $user_id, 'agent_role_agent_nonce' );

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			wp_die( esc_html__( 'That user is not an Agent.', 'agent-role' ), '', array( 'response' => 400 ) );
		}

		$previous         = Agent_Role::instructions_for( $user );
		$posted           = isset( $_POST['agent_role_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['agent_role_instructions'] ) ) : '';
		$before_caps      = Agent_Role::cap_map( $user );
		$before_abilities = get_user_meta( $user_id, Agent_Role::ABILITIES_META, true );
		$before_note      = metadata_exists( 'user', $user_id, Agent_Role::INSTRUCTIONS_META ) ? (string) get_user_meta( $user_id, Agent_Role::INSTRUCTIONS_META, true ) : '';

		list( $caps, $abilities ) = self::posted_maps();
		$persona_action           = isset( $_POST['agent_role_persona_action'] ) ? sanitize_key( wp_unslash( $_POST['agent_role_persona_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		if ( ! in_array( $persona_action, array( 'save_default', 'reset', 'factory' ), true ) ) {
			$persona_action = '';
		}
		if ( '' === $persona_action && ! isset( $_POST['agent_role_abilities'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$abilities = is_array( $before_abilities ) ? $before_abilities : array();
		}
		$persona          = isset( $_POST['agent_role_persona'] ) ? sanitize_key( wp_unslash( $_POST['agent_role_persona'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$previous_persona = (string) get_user_meta( $user_id, self::PERSONA_META, true );
		$actions          = self::posted_actions();
		$shapes           = self::personas();
		$stripped         = false;
		$persona_shape    = null;
		if ( isset( $shapes[ $persona ] ) ) {
			$applied       = self::apply_persona_post( $user_id, $persona, $persona_action, $caps, $actions, $abilities, $posted );
			$caps          = $applied['caps'];
			$actions       = $applied['actions'];
			$abilities     = $applied['abilities'];
			$stripped      = ! empty( $applied['stripped'] );
			$persona_shape = self::site_persona_shape( $persona );
			update_user_meta( $user_id, self::PERSONA_META, $persona );
			update_user_meta( $user_id, self::PERSONA_CUSTOM_META, $applied['custom'] ? '1' : '' );
			update_user_meta( $user_id, self::ACTIONS_META, $actions );
			self::apply_persona_caps( $user_id, $actions );
		}
		Agent_Role::apply_cap_map( $user_id, $caps );
		update_user_meta( $user_id, Agent_Role::ABILITIES_META, $abilities );

		$note = self::instructions_to_store( $posted, $previous, $persona_action, $persona_shape, $persona, $previous_persona );
		update_user_meta( $user_id, Agent_Role::INSTRUCTIONS_META, $note );

		$after_note = $note;
		if ( $before_caps !== $caps || $before_abilities !== $abilities ) {
			Agent_Role_Log::record( $user_id, 'admin', 'Switches saved', 'switches', 'changed' );
		}
		if ( self::normalize_instructions( $before_note ) !== self::normalize_instructions( $after_note ) ) {
			$from_persona = $persona_shape && self::normalize_instructions( $after_note ) === self::normalize_instructions( $persona_shape['instructions'] );
			Agent_Role_Log::record(
				$user_id,
				'admin',
				$from_persona ? 'Instructions set from the persona' : 'Instructions saved from the box',
				'instructions',
				'changed'
			);
		}

		$redirect = array(
			'page'    => 'agent-role',
			'user_id' => $user_id,
			'updated' => '1',
		);
		if ( '' !== $persona_action ) {
			$redirect['persona_notice'] = $persona_action;
			$redirect['customize']      = '1';
		}
		if ( $stripped ) {
			$redirect['stripped'] = '1';
		}
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'users.php' ) ) );
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
				__( 'That user is not an Agent.', 'agent-role' ),
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
	 * Choose the note to store. Permissions never rewrite this box.
	 *
	 * @param string $posted           Posted textarea.
	 * @param string $previous         Stored note before save.
	 * @param string $persona_action   save_default, reset, factory, or empty.
	 * @param array<string,mixed>|null $persona_shape Site shape for the posted persona.
	 * @param string $persona          Posted persona slug.
	 * @param string $previous_persona Persona stored on the agent.
	 */
	private static function instructions_to_store( $posted, $previous, $persona_action, $persona_shape, $persona, $previous_persona ) {
		$posted   = self::normalize_instructions( $posted );
		$previous = self::normalize_instructions( $previous );
		$default  = ( is_array( $persona_shape ) && isset( $persona_shape['instructions'] ) )
			? self::normalize_instructions( $persona_shape['instructions'] )
			: '';

		if ( in_array( $persona_action, array( 'reset', 'factory' ), true ) && '' !== $default ) {
			return $default;
		}

		if ( 'save_default' === $persona_action ) {
			return $posted;
		}

		if ( $posted !== $previous ) {
			return $posted;
		}

		if ( $persona && $persona !== $previous_persona && '' !== $default ) {
			return $default;
		}

		return $previous;
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
	 * @param string $label      Visible name.
	 * @param string $code       Text to show and copy.
	 * @param string $lang       Language badge. Defaults to JSON.
	 * @param string $copy_label Accessible name for the copy button.
	 */
	private static function render_code_block( $label, $code, $lang = '', $copy_label = '' ) {
		if ( '' === $lang ) {
			$lang = __( 'JSON', 'agent-role' );
		}
		if ( '' === $copy_label ) {
			$copy_label = __( 'Copy client config', 'agent-role' );
		}
		echo '<p class="ar-rf-code-label">' . esc_html( $label ) . '</p>';
		echo '<div class="rf-code">';
		echo '<div class="rf-code__chrome">';
		echo '<div class="rf-code__dots" aria-hidden="true"><span></span><span></span><span></span></div>';
		echo '<button type="button" class="rf-code__copy" aria-label="' . esc_attr( $copy_label ) . '">';
		echo self::kses_icon( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in kses_icon().
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6A2.25 2.25 0 0014.25 3.75H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18A2.25 2.25 0 0120.25 10.5V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6A2.25 2.25 0 008.25 10.5v6"/></svg>'
		);
		echo '</button></div>';
		echo '<pre class="rf-code__body"><code>' . esc_html( $code ) . '</code></pre>';
		echo '<span class="rf-code__lang">' . esc_html( $lang ) . '</span>';
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
	 * Copyable setup prompt for one agent username.
	 *
	 * @param string $username Agent username.
	 */
	private static function render_agent_prompt( $username ) {
		self::render_code_block(
			__( 'Prompt for your Agent', 'agent-role' ),
			Agent_Role_Mcp::setup_prompt( $username ),
			__( 'Text', 'agent-role' ),
			__( 'Copy prompt', 'agent-role' )
		);
	}

	/**
	 * Checkbox that limits the MCP server to Agent accounts.
	 */
	private static function render_mcp_setting() {
		if ( ! Agent_Role_Mcp::is_available() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'MCP Adapter', 'agent-role' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Agent_Role_Mcp::GROUP );
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
	 * Days to keep events, and how many events to keep for each agent.
	 */
	private static function render_log_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Activity log', 'agent-role' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ar-rf-log-settings">';
		wp_nonce_field( 'agent_role_log_setting', 'agent_role_log_nonce' );
		echo '<input type="hidden" name="action" value="agent_role_log_setting" />';
		echo '<p class="ar-rf-log-settings__field"><label for="agent_role_log_days">' . esc_html__( 'Keep records for', 'agent-role' ) . '</label> ';
		echo '<input type="number" class="small-text" id="agent_role_log_days" name="agent_role_log_days" min="1" max="' . esc_attr( (string) Agent_Role_Log::MAX_DAYS ) . '" value="' . esc_attr( (string) Agent_Role_Log::days() ) . '" required /> ';
		echo '<span>' . esc_html__( 'days', 'agent-role' ) . '</span></p>';
		echo '<p class="ar-rf-log-settings__field"><label for="agent_role_log_cap">' . esc_html__( 'Events per agent', 'agent-role' ) . '</label> ';
		echo '<input type="number" class="small-text" id="agent_role_log_cap" name="agent_role_log_cap" min="1" max="' . esc_attr( (string) Agent_Role_Log::MAX_CAP ) . '" value="' . esc_attr( (string) Agent_Role_Log::cap() ) . '" required /></p>';
		echo '<p class="description">' . esc_html__( 'A longer window or a higher cap stores more of what agents tried, including ability names and REST routes, and the Activity screen has to load all of it. The log does not store passwords or request contents. The limit is 365 days and 5,000 events per agent. Saving a smaller number deletes the older rows.', 'agent-role' ) . '</p>';
		submit_button( __( 'Save log settings', 'agent-role' ) );
		echo '</form>';
	}

	/**
	 * Save how long activity rows are kept.
	 */
	public static function handle_log_setting() {
		self::require_manage_options();

		check_admin_referer( 'agent_role_log_setting', 'agent_role_log_nonce' );

		$days = isset( $_POST['agent_role_log_days'] ) ? absint( wp_unslash( $_POST['agent_role_log_days'] ) ) : Agent_Role_Log::DAYS;
		$cap  = isset( $_POST['agent_role_log_cap'] ) ? absint( wp_unslash( $_POST['agent_role_log_cap'] ) ) : Agent_Role_Log::CAP;
		Agent_Role_Log::save_limits( $days, $cap );

		wp_safe_redirect( admin_url( 'users.php?page=agent-role&tab=settings' ) );
		exit;
	}

	/**
	 * Create an agent from the form.
	 */
	public static function handle_add() {
		self::require_manage_options();
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
		self::require_manage_options();
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
		self::require_manage_options();
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

	const PERSONA_META = '_agent_role_persona';

	const PERSONA_CUSTOM_META = '_agent_role_persona_custom';

	const ACTIONS_META = '_agent_role_actions';

	/**
	 * Persona assigned to a newly created Agent.
	 */
	const DEFAULT_PERSONA = 'analyst';

	/**
	 * Stop the request when the current user cannot manage these settings.
	 *
	 * @param bool $ajax True when the response must be JSON.
	 */
	private static function require_manage_options( $ajax = false ) {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = __( 'You do not have permission to manage these settings.', 'agent-role' );
		if ( $ajax ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}

		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	/**
	 * Starting shapes. Caps are the six publishing switches. Actions are the extra rows.
	 *
	 * @return array<string,array{label:string,letters:string,color:string,soft:string,text:string,note:string,caps:string[],actions:string[]}>
	 */
	private static function personas() {
		$writer_caps = array( 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_files' );

		return array(
			'webdev'  => array(
				'label'   => __( 'Web Dev', 'agent-role' ),
				/* translators: Two-letter initials on the Web Dev persona card. */
				'letters' => _x( 'WD', 'persona initials', 'agent-role' ),
				'color'   => '#2c2922',
				'soft'    => '#e6e2da',
				/* translators: WP Rollback is a plugin name. */
				'text'    => __( 'Editor, plus they can update plugins. Roll those updates back with WP Rollback.', 'agent-role' ),
				/* translators: WP Rollback is a plugin name. */
				'note'    => __( 'You are the Web Dev for this WordPress site. Keep the site working. Update plugins when that is the job, and roll a bad update back with WP Rollback. Write and edit content when the site needs it. Do not change settings you were not asked to change.', 'agent-role' ),
				'caps'    => $writer_caps,
				'actions' => array( 'read_others', 'edit_others', 'update_plugins' ),
			),
			'editor'  => array(
				'label'   => __( 'Editor', 'agent-role' ),
				/* translators: Two-letter initials on the Editor persona card. */
				'letters' => _x( 'ED', 'persona initials', 'agent-role' ),
				'color'   => '#d4844e',
				'soft'    => '#f0d5c0',
				'text'    => __( 'Writer, plus they can edit and publish a post or page other users wrote.', 'agent-role' ),
				'note'    => __( 'You are the Editor for this WordPress site. Write when you need to, and edit and publish posts and pages other people wrote. Keep the site’s voice. Do not update plugins or change site settings.', 'agent-role' ),
				'caps'    => $writer_caps,
				'actions' => array( 'read_others', 'edit_others' ),
			),
			'writer'  => array(
				'label'   => __( 'Writer', 'agent-role' ),
				/* translators: Two-letter initials on the Writer persona card. */
				'letters' => _x( 'WR', 'persona initials', 'agent-role' ),
				'color'   => '#62744d',
				'soft'    => '#e4eadc',
				'text'    => __( 'Creates, edits, and publishes their own posts and pages, and can upload files.', 'agent-role' ),
				'note'    => __( 'You are the Writer for this WordPress site. Write and publish this site’s own posts and pages, and upload the files those pieces need. Stay in your own work. Do not take over a post someone else wrote. Do not update plugins or change site settings.', 'agent-role' ),
				'caps'    => $writer_caps,
				'actions' => array(),
			),
			'analyst' => array(
				'label'   => __( 'Analyst', 'agent-role' ),
				/* translators: Two-letter initials on the Analyst persona card. */
				'letters' => _x( 'AN', 'persona initials', 'agent-role' ),
				'color'   => '#8c5a3c',
				'soft'    => '#f3e0d2',
				'text'    => __( 'An expert in reading and exporting data related to your website.', 'agent-role' ),
				'note'    => __( 'You are the Analyst for this WordPress site. Read published content and export data about this site. Report what you find. Do not create, edit, or delete content. Do not update plugins or change site settings.', 'agent-role' ),
				'caps'    => array(),
				'actions' => array( 'read_others', 'export' ),
			),
		);
	}

	/**
	 * Rows in Customize, in group order.
	 *
	 * @return array<int,array{id:string,group:string,label:string,hint:string,kind:string}>
	 */
	private static function persona_rows() {
		return array(
			array(
				'id'    => 'edit_posts',
				'group' => 'create',
				'label' => __( 'Create their own posts and pages', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'cap',
			),
			array(
				'id'    => 'upload_files',
				'group' => 'create',
				'label' => __( 'Upload files', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'cap',
			),
			array(
				'id'    => 'core/get-site-info',
				'group' => 'read',
				'label' => __( 'Read this site’s name, URL, and description', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'ability',
			),
			array(
				'id'    => 'core/get-user-info',
				'group' => 'read',
				'label' => __( 'Read the connected user', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'ability',
			),
			array(
				'id'    => 'core/get-environment-info',
				'group' => 'read',
				'label' => __( 'Read the WordPress version and environment', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'ability',
			),
			array(
				'id'    => 'read_others',
				'group' => 'read',
				'label' => __( 'Read other people’s published posts and pages', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'action',
			),
			array(
				'id'    => 'export',
				'group' => 'read',
				'label' => __( 'Export SEO or website data', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'action',
			),
			array(
				'id'    => 'edit_published_posts',
				'group' => 'undo',
				'label' => __( 'Edit their own posts and pages after they are published', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'cap',
			),
			array(
				'id'    => 'publish_posts',
				'group' => 'undo',
				'label' => __( 'Publish their own posts and pages', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'cap',
			),
			array(
				'id'    => 'edit_others',
				'group' => 'undo',
				'label' => __( 'Edit and publish a post or page a person wrote', 'agent-role' ),
				'hint'  => '',
				'kind'  => 'action',
			),
			array(
				'id'    => 'update_plugins',
				'group' => 'undo',
				'label' => __( 'Update plugins', 'agent-role' ),
				/* translators: WP Rollback is a plugin name. */
				'hint'  => __( 'Roll a plugin update back with WP Rollback.', 'agent-role' ),
				'kind'  => 'action',
			),
			array(
				'id'    => 'delete_posts',
				'group' => 'delete',
				'label' => __( 'Delete their own drafts', 'agent-role' ),
				'hint'  => __( 'This can delete content.', 'agent-role' ),
				'kind'  => 'cap',
			),
			array(
				'id'    => 'delete_published_posts',
				'group' => 'delete',
				'label' => __( 'Delete their own published posts and pages', 'agent-role' ),
				'hint'  => __( 'This can delete content.', 'agent-role' ),
				'kind'  => 'cap',
			),
		);
	}

	/**
	 * Plugin-shipped starting set for a shape.
	 *
	 * @param string $persona Persona slug.
	 * @return array{caps:array<string,bool>,actions:string[],abilities:array<string,bool>,instructions:string}
	 */
	public static function factory_persona_shape( $persona ) {
		$personas = self::personas();
		$enabled  = isset( $personas[ $persona ] ) ? $personas[ $persona ]['caps'] : array();
		$caps     = array();
		foreach ( Agent_Role::cap_slugs() as $cap ) {
			$caps[ $cap ] = in_array( $cap, $enabled, true );
		}

		return array(
			'caps'         => $caps,
			'actions'      => isset( $personas[ $persona ] ) ? array_values( $personas[ $persona ]['actions'] ) : array(),
			'abilities'    => self::core_read_abilities(),
			'instructions' => self::factory_persona_instructions( $persona ),
		);
	}

	/**
	 * Purpose text plus the shared MCP suffix for a shape.
	 *
	 * @param string $persona Persona slug.
	 */
	public static function factory_persona_instructions( $persona ) {
		$personas = self::personas();
		$purpose  = isset( $personas[ $persona ]['note'] ) ? trim( (string) $personas[ $persona ]['note'] ) : '';
		$suffix   = self::instructions_suffix();
		if ( '' === $purpose ) {
			return $suffix;
		}

		return $purpose . "\n\n" . $suffix;
	}

	/**
	 * How to call tools. Shared across personas. Not a list of abilities.
	 */
	private static function instructions_suffix() {
		return implode(
			"\n\n",
			array(
				'Call mcp-adapter-discover-abilities or mcp-adapter-get-ability-info before mcp-adapter-execute-ability when the ability name or its parameters are not already known. Pass only parameters that ability\'s schema lists.',
				'If an ability returns permission denied, say it is off for this agent and stop.',
				'When you report back, lead with the result and use the values the ability returned.',
			)
		);
	}

	/**
	 * Starting set for a shape on this site.
	 *
	 * @param string $persona Persona slug.
	 * @return array{caps:array<string,bool>,actions:string[],abilities:array<string,bool>,instructions:string}
	 */
	public static function site_persona_shape( $persona ) {
		$stored = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $persona ] ) || ! is_array( $stored[ $persona ] ) ) {
			return self::factory_persona_shape( $persona );
		}

		return self::sanitize_persona_shape( $persona, $stored[ $persona ] );
	}

	/**
	 * Whether this site has a saved default for the shape.
	 *
	 * @param string $persona Persona slug.
	 */
	public static function has_site_persona_default( $persona ) {
		$stored = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION, array() );
		return is_array( $stored ) && isset( $stored[ $persona ] ) && is_array( $stored[ $persona ] );
	}

	/**
	 * Store a site default for one shape.
	 *
	 * @param string                                                          $persona Persona slug.
	 * @param array{caps:array<string,bool>,actions:string[],abilities:array<string,bool>,instructions?:string} $shape Shape to store.
	 */
	public static function write_site_persona_default( $persona, array $shape ) {
		$personas = self::personas();
		if ( ! isset( $personas[ $persona ] ) ) {
			return;
		}

		$stored = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $persona ] = self::sanitize_persona_shape( $persona, $shape );
		update_option( Agent_Role::PERSONA_DEFAULTS_OPTION, $stored, false );
	}

	/**
	 * Forget a saved site default so the shape uses the plugin set again.
	 *
	 * @param string $persona Persona slug.
	 */
	public static function clear_site_persona_default( $persona ) {
		$stored = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $persona ] ) ) {
			return;
		}

		unset( $stored[ $persona ] );
		if ( $stored ) {
			update_option( Agent_Role::PERSONA_DEFAULTS_OPTION, $stored, false );
			return;
		}

		delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
	}

	/**
	 * Keep only known caps, extra rows, and listed abilities.
	 *
	 * @param string               $persona Persona slug.
	 * @param array<string,mixed>  $shape   Raw shape.
	 * @return array{caps:array<string,bool>,actions:string[],abilities:array<string,bool>,instructions:string}
	 */
	public static function sanitize_persona_shape( $persona, array $shape ) {
		$factory = self::factory_persona_shape( $persona );
		$caps    = $factory['caps'];
		if ( isset( $shape['caps'] ) && is_array( $shape['caps'] ) ) {
			foreach ( Agent_Role::cap_slugs() as $cap ) {
				$caps[ $cap ] = ! empty( $shape['caps'][ $cap ] );
			}
		}

		$known = self::action_slugs();
		$have  = array();
		if ( isset( $shape['actions'] ) && is_array( $shape['actions'] ) ) {
			foreach ( $shape['actions'] as $action ) {
				if ( is_string( $action ) && in_array( $action, $known, true ) ) {
					$have[] = $action;
				}
			}
		}

		$abilities = array();
		if ( isset( $shape['abilities'] ) && is_array( $shape['abilities'] ) ) {
			foreach ( self::listed_ability_names() as $name ) {
				if ( ! empty( $shape['abilities'][ $name ] ) ) {
					$abilities[ $name ] = true;
				}
			}
		}

		$instructions = $factory['instructions'];
		if ( isset( $shape['instructions'] ) && is_string( $shape['instructions'] ) ) {
			$instructions = sanitize_textarea_field( $shape['instructions'] );
		}

		return array(
			'caps'         => $caps,
			'actions'      => array_values( array_unique( $have ) ),
			'abilities'    => $abilities,
			'instructions' => $instructions,
		);
	}

	/**
	 * File a listed ability into Create, Read, Undo, Delete, or Other.
	 *
	 * Marks file the row. A missing mark is Other. Names are not used.
	 *
	 * @param mixed $ability Ability instance.
	 * @return string
	 */
	public static function file_ability_group( $ability ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return 'other';
		}

		$meta        = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$readonly    = array_key_exists( 'readonly', $annotations ) ? $annotations['readonly'] : null;
		$destructive = array_key_exists( 'destructive', $annotations ) ? $annotations['destructive'] : null;
		$idempotent  = array_key_exists( 'idempotent', $annotations ) ? $annotations['idempotent'] : null;

		if ( true === $readonly ) {
			return 'read';
		}
		if ( true === $destructive ) {
			return 'delete';
		}
		if ( false === $readonly && false === $idempotent ) {
			return 'create';
		}
		if ( false === $readonly ) {
			return 'undo';
		}

		return 'other';
	}

	/**
	 * Apply a persona save, including site-default buttons.
	 *
	 * @param int                $user_id Agent user ID.
	 * @param string             $persona Persona slug.
	 * @param string             $action  save_default, reset, factory, or empty.
	 * @param array<string,bool> $caps    Posted publishing switches.
	 * @param string[]           $actions Posted extra rows.
	 * @param array<string,bool> $abilities Posted ability map.
	 * @param string             $instructions Posted instruction text.
	 * @return array{caps:array<string,bool>,actions:string[],abilities:array<string,bool>,custom:bool,stripped:bool}
	 */
	private static function apply_persona_post( $user_id, $persona, $action, array $caps, array $actions, array $abilities, $instructions = '' ) {
		$stripped = false;
		$custom   = false;

		if ( 'reset' === $action ) {
			$shape     = self::site_persona_shape( $persona );
			$caps      = $shape['caps'];
			$actions   = $shape['actions'];
			$abilities = $shape['abilities'];
		} elseif ( 'factory' === $action ) {
			self::clear_site_persona_default( $persona );
			$shape     = self::factory_persona_shape( $persona );
			$caps      = $shape['caps'];
			$actions   = $shape['actions'];
			$abilities = $shape['abilities'];
		} elseif ( 'save_default' === $action ) {
			Agent_Role::apply_cap_map( $user_id, $caps );
			self::apply_persona_caps( $user_id, $actions );
			$kept      = self::keep_runnable_abilities( $user_id, $abilities );
			$stripped  = $kept['stripped'];
			$abilities = $kept['abilities'];
			self::write_site_persona_default(
				$persona,
				array(
					'caps'         => $caps,
					'actions'      => $actions,
					'abilities'    => $abilities,
					'instructions' => $instructions,
				)
			);
		} else {
			$custom = ! empty( $_POST['agent_role_persona_custom'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
			if ( ! $custom ) {
				$custom = self::persona_is_custom( $persona, $caps, $actions, $abilities, $instructions );
			}
			if ( ! $custom ) {
				$shape     = self::site_persona_shape( $persona );
				$caps      = $shape['caps'];
				$actions   = $shape['actions'];
				$abilities = $shape['abilities'];
			}
		}

		return array(
			'caps'      => $caps,
			'actions'   => $actions,
			'abilities' => $abilities,
			'custom'    => $custom,
			'stripped'  => $stripped,
		);
	}

	/**
	 * Drop listed abilities that this agent cannot run even with the switch on.
	 *
	 * Only tools that can answer with no extra input are stripped. A tool that
	 * needs a post ID or other argument is left as the owner set it.
	 *
	 * @param int                $user_id   Agent user ID.
	 * @param array<string,bool> $abilities Ability map.
	 * @return array{abilities:array<string,bool>,stripped:bool}
	 */
	public static function keep_runnable_abilities( $user_id, array $abilities ) {
		$stripped = false;
		$kept     = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array(
				'abilities' => array(),
				'stripped'  => false,
			);
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		remove_filter( 'wp_ability_permission_result', array( 'Agent_Role', 'filter_ability_permission' ), 10 );
		try {
			foreach ( wp_get_abilities() as $ability ) {
				try {
					if ( ! Agent_Role::is_listed_ability( $ability ) ) {
						continue;
					}
					$name = $ability->get_name();
					if ( empty( $abilities[ $name ] ) ) {
						continue;
					}
					$probe = Agent_Role::probe_ability_permission( $ability );
					if ( false === $probe ) {
						$stripped = true;
						continue;
					}
					$kept[ $name ] = true;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		} finally {
			add_filter( 'wp_ability_permission_result', array( 'Agent_Role', 'filter_ability_permission' ), 10, 4 );
			wp_set_current_user( $previous );
		}

		return array(
			'abilities' => $kept,
			'stripped'  => $stripped,
		);
	}

	/**
	 * Whether the saved rows differ from this site's default for the shape.
	 *
	 * @param string             $persona   Persona slug.
	 * @param array<string,bool> $caps      Publishing switches.
	 * @param string[]           $actions   Extra rows that are on.
	 * @param array<string,bool> $abilities Ability map.
	 */
	private static function persona_is_custom( $persona, array $caps, array $actions, array $abilities, $instructions = null ) {
		$defaults = self::site_persona_shape( $persona );
		foreach ( Agent_Role::cap_slugs() as $cap ) {
			if ( ! empty( $caps[ $cap ] ) !== ! empty( $defaults['caps'][ $cap ] ) ) {
				return true;
			}
		}
		$wanted = $defaults['actions'];
		sort( $wanted );
		$have = array_values( $actions );
		sort( $have );
		if ( $wanted !== $have ) {
			return true;
		}
		foreach ( self::listed_ability_names() as $name ) {
			if ( ! empty( $abilities[ $name ] ) !== ! empty( $defaults['abilities'][ $name ] ) ) {
				return true;
			}
		}

		if ( null !== $instructions ) {
			$default_note = isset( $defaults['instructions'] ) ? self::normalize_instructions( $defaults['instructions'] ) : '';
			if ( self::normalize_instructions( $instructions ) !== $default_note ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extra rows posted from Customize.
	 *
	 * @return string[]
	 */
	private static function posted_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		$chosen = isset( $_POST['agent_role_actions'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['agent_role_actions'] ) ) : array();
		return array_values( array_intersect( self::action_slugs(), $chosen ) );
	}

	/**
	 * Core read tools every shape starts with.
	 *
	 * @return array<string,bool>
	 */
	private static function core_read_abilities() {
		$on = array();
		foreach ( self::mapped_ability_ids() as $name ) {
			$on[ $name ] = true;
		}
		return $on;
	}

	/**
	 * Ability names we treat as named jobs, not catalog leftovers.
	 *
	 * @return string[]
	 */
	private static function mapped_ability_ids() {
		$ids = array();
		foreach ( self::persona_rows() as $row ) {
			if ( 'ability' === $row['kind'] ) {
				$ids[] = $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * Extra row ids we know how to grant.
	 *
	 * @return string[]
	 */
	private static function action_slugs() {
		$known = array();
		foreach ( self::persona_rows() as $row ) {
			if ( 'action' === $row['kind'] ) {
				$known[] = $row['id'];
			}
		}
		return $known;
	}

	/**
	 * Names of abilities the agent screen can turn on or off.
	 *
	 * @return string[]
	 */
	private static function listed_ability_names() {
		$names = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $names;
		}
		foreach ( wp_get_abilities() as $ability ) {
			if ( Agent_Role::is_listed_ability( $ability ) ) {
				$names[] = $ability->get_name();
			}
		}
		return $names;
	}

	/**
	 * Grant or deny the permissions behind the extra rows.
	 *
	 * @param int      $user_id Agent user ID.
	 * @param string[] $actions Extra rows that are on.
	 */
	private static function apply_persona_caps( $user_id, array $actions ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$on = array_fill_keys( $actions, true );
		$user->add_cap( 'edit_others_posts', ! empty( $on['edit_others'] ) );
		$user->add_cap( 'edit_others_pages', ! empty( $on['edit_others'] ) );
		$user->add_cap( 'update_plugins', ! empty( $on['update_plugins'] ) );
	}

	/**
	 * Put this agent on a persona's current site default.
	 *
	 * @param int    $user_id Agent user ID.
	 * @param string $persona Persona slug.
	 */
	public static function assign_persona( $user_id, $persona ) {
		$personas = self::personas();
		if ( ! isset( $personas[ $persona ] ) ) {
			return;
		}

		$shape = self::site_persona_shape( $persona );
		update_user_meta( $user_id, self::PERSONA_META, $persona );
		update_user_meta( $user_id, self::PERSONA_CUSTOM_META, '' );
		update_user_meta( $user_id, self::ACTIONS_META, $shape['actions'] );
		self::apply_persona_caps( $user_id, $shape['actions'] );
		Agent_Role::apply_cap_map( $user_id, $shape['caps'] );
		update_user_meta( $user_id, Agent_Role::ABILITIES_META, $shape['abilities'] );
		update_user_meta( $user_id, Agent_Role::INSTRUCTIONS_META, $shape['instructions'] );
	}

	/**
	 * Saved persona for the header and cards.
	 *
	 * @param WP_User $agent Agent account.
	 * @return array{slug:string,custom:bool,label:string,color:string}
	 */
	private static function persona_state( $agent ) {
		$personas = self::personas();
		$saved    = get_user_meta( $agent->ID, self::PERSONA_META, true );
		$slug     = is_string( $saved ) && isset( $personas[ $saved ] ) ? $saved : '';
		$custom   = $slug && '1' === (string) get_user_meta( $agent->ID, self::PERSONA_CUSTOM_META, true );

		return array(
			'slug'   => $slug,
			'custom' => $custom,
			'label'  => $slug ? $personas[ $slug ]['label'] : '',
			'color'  => $slug ? $personas[ $slug ]['color'] : '',
		);
	}

	/**
	 * Persona cards and the customize groups.
	 *
	 * @param WP_User            $agent Agent account.
	 * @param array<string,bool> $caps  Current publishing switches.
	 */
	private static function render_personas( $agent, array $caps ) {
		$personas  = self::personas();
		$saved     = get_user_meta( $agent->ID, self::PERSONA_META, true );
		$persona   = is_string( $saved ) && isset( $personas[ $saved ] ) ? $saved : '';
		$custom    = $persona && '1' === (string) get_user_meta( $agent->ID, self::PERSONA_CUSTOM_META, true );
		$stored    = get_user_meta( $agent->ID, self::ACTIONS_META, true );
		$actions   = is_array( $stored ) ? $stored : array();
		$abilities = get_user_meta( $agent->ID, Agent_Role::ABILITIES_META, true );
		$abilities = is_array( $abilities ) ? $abilities : array();
		if ( $persona && ! $custom ) {
			$shape     = self::site_persona_shape( $persona );
			$caps      = $shape['caps'];
			$actions   = $shape['actions'];
			$abilities = $shape['abilities'];
		}

		$open              = $persona && ( $custom || isset( $_GET['customize'] ) || isset( $_GET['instructions'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen flags.
		$instructions      = Agent_Role::instructions_for( $agent );
		$instructions_open = isset( $_GET['instructions'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen flag.
		$accent            = ( $persona && isset( $personas[ $persona ] ) ) ? $personas[ $persona ]['color'] : '';

		echo '<fieldset class="ar-rf-section ar-rf-section--persona"' . ( $accent ? ' style="--ar-persona:' . esc_attr( $accent ) . '"' : '' ) . '>';
		echo '<legend>' . esc_html__( 'What this agent is for', 'agent-role' ) . '</legend>';
		echo '<p class="ar-rf-lede">' . esc_html__( 'Pick an Agent persona. Permissions and instructions start from that persona.', 'agent-role' ) . '</p>';
		$slugs     = array_keys( $personas );
		$tab_first = $persona && ( $slugs[0] ?? '' ) === $persona;
		$tab_last  = $persona && ( array_key_last( $personas ) ?? '' ) === $persona;
		$stage_mod = ( $open ? ' is-open' : '' ) . ( $tab_first ? ' is-tab-first' : '' ) . ( $tab_last ? ' is-tab-last' : '' );
		echo '<div class="ar-persona-stage' . esc_attr( $stage_mod ) . '" id="ar-persona-stage">';
		echo '<div class="ar-persona-grid">';
		foreach ( $personas as $slug => $shape ) {
			$selected = $persona === $slug;
			$site     = self::site_persona_shape( $slug );
			$factory  = self::factory_persona_shape( $slug );
			$input_id = 'ar-persona-' . $slug;
			echo '<div class="ar-persona' . ( $selected ? ' is-selected' : '' ) . '" style="--ar-persona:' . esc_attr( $shape['color'] ) . ';--ar-persona-soft:' . esc_attr( $shape['soft'] ) . '">';
			echo '<input class="ar-persona__input" type="radio" name="agent_role_persona" id="' . esc_attr( $input_id ) . '" value="' . esc_attr( $slug ) . '"';
			echo ' data-label="' . esc_attr( $shape['label'] ) . '"';
			echo ' data-caps="' . esc_attr( wp_json_encode( $site['caps'] ) ) . '"';
			echo ' data-actions="' . esc_attr( wp_json_encode( $site['actions'] ) ) . '"';
			echo ' data-abilities="' . esc_attr( wp_json_encode( $site['abilities'] ) ) . '"';
			echo ' data-instructions="' . esc_attr( wp_json_encode( $site['instructions'] ) ) . '"';
			echo ' data-factory-caps="' . esc_attr( wp_json_encode( $factory['caps'] ) ) . '"';
			echo ' data-factory-actions="' . esc_attr( wp_json_encode( $factory['actions'] ) ) . '"';
			echo ' data-factory-abilities="' . esc_attr( wp_json_encode( $factory['abilities'] ) ) . '"';
			echo ' data-factory-instructions="' . esc_attr( wp_json_encode( $factory['instructions'] ) ) . '"';
			echo ' ' . checked( $selected, true, false ) . ' />';
			echo '<label class="ar-persona__face" for="' . esc_attr( $input_id ) . '">';
			echo '<span class="ar-persona__avatar" aria-hidden="true">' . esc_html( $shape['letters'] ) . '</span>';
			echo '<span class="ar-persona__name">' . esc_html( $shape['label'] ) . '</span>';
			echo '<span class="ar-persona__text">' . esc_html( $shape['text'] ) . '</span>';
			echo '</label>';
			echo '<button type="button" class="button ar-persona__select"' . ( $selected ? ' hidden' : '' ) . '>';
			echo esc_html(
				sprintf(
					/* translators: %s is the persona name, such as Writer. */
					__( 'Select %s Persona', 'agent-role' ),
					$shape['label']
				)
			);
			echo '</button>';
			echo '<button type="button" class="button ar-persona__customize" aria-controls="ar-persona-adjust" aria-expanded="' . ( $open && $selected ? 'true' : 'false' ) . '"' . ( $selected ? '' : ' hidden' ) . '>';
			echo esc_html__( 'Customize this Agent', 'agent-role' );
			echo '</button>';
			echo '</div>';
		}
		echo '</div>';
		echo '<input type="hidden" name="agent_role_persona_custom" id="ar-persona-custom-flag" value="' . esc_attr( $custom ? '1' : '0' ) . '" />';

		echo '<div class="ar-persona-adjust" id="ar-persona-adjust"' . ( $open ? '' : ' hidden' ) . '>';
		self::render_persona_groups( $agent, $caps, $actions, $abilities );
		echo '<div class="ar-persona-instructions">';
		echo '<button type="button" class="ar-persona-instructions__toggle" id="ar-instructions-toggle" aria-expanded="' . ( $instructions_open ? 'true' : 'false' ) . '" aria-controls="ar-instructions-panel">';
		echo esc_html__( 'Customize instructions', 'agent-role' );
		echo '</button>';
		echo '<div class="ar-rf-instructions-panel" id="ar-instructions-panel"' . ( $instructions_open ? '' : ' hidden' ) . '>';
		echo '<p class="ar-rf-lede">' . esc_html__(
			/* translators: Quoted words are button labels on this screen: Reset, Generate, and Update Agent. */
			'Sent when this agent connects over MCP. These start from the persona’s job, not from the ability list. "Reset Instructions" puts the box back to this persona’s default. "Generate" drafts a version with AI via your Connector. Changing permissions does not rewrite this box.',
			'agent-role'
		) . '</p>';
		echo '<textarea class="ar-rf-instructions" id="agent_role_instructions" name="agent_role_instructions" rows="14">';
		echo esc_textarea( $instructions );
		echo '</textarea>';
		echo '<p class="ar-rf-draft">';
		if ( current_user_can( 'manage_options' ) && class_exists( 'Agent_Role_Brief', false ) && Agent_Role_Brief::can_draft() ) {
			echo '<button type="button" class="button" id="ar-draft-instructions" data-user="' . esc_attr( (string) $agent->ID ) . '">';
			echo esc_html__( 'Generate Instructions based on your website', 'agent-role' );
			echo '</button>';
		}
		echo '<button type="button" class="button" id="ar-reset-instructions" data-user="' . esc_attr( (string) $agent->ID ) . '">';
		echo esc_html__( 'Reset Instructions', 'agent-role' );
		echo '</button></p>';
		echo '<p class="ar-rf-draft__status" id="ar-draft-instructions-status" aria-live="polite"></p>';
		echo '</div></div>';
		echo '<div class="ar-persona-defaults">';
		echo '<p class="ar-rf-lede">' . esc_html__(
			/* translators: "Save as Default", "Reset", and "Factory Reset" are button labels on this screen. */
			'Save as Default becomes the starting permissions and instructions for this Agent persona. Reset puts this agent on your saved default. Factory Reset puts the persona back to the plugin default. Agents you already customized stay as they are.',
			'agent-role'
		) . '</p>';
		echo '<p class="ar-persona-defaults__actions">';
		echo '<button type="submit" class="button" name="agent_role_persona_action" value="save_default" id="ar-persona-save-default"' . disabled( ! $persona, true, false ) . '>' . esc_html__( 'Save as Default', 'agent-role' ) . '</button> ';
		echo '<button type="submit" class="button" name="agent_role_persona_action" value="reset" id="ar-persona-reset-default"' . disabled( ! $persona, true, false ) . '>' . esc_html__( 'Reset to Default', 'agent-role' ) . '</button> ';
		echo '<button type="submit" class="button" name="agent_role_persona_action" value="factory" id="ar-persona-factory-default"' . disabled( ! $persona, true, false ) . '>' . esc_html__( 'Factory Reset', 'agent-role' ) . '</button>';
		echo '</p></div>';
		echo '</div>';
		echo '</div>';
		echo '</fieldset>';
	}

	/**
	 * Mapped jobs, then quieter catalog rows, then Other.
	 *
	 * @param WP_User            $agent     Agent account.
	 * @param array<string,bool> $caps      Publishing switches.
	 * @param string[]           $actions   Extra rows that are on.
	 * @param array<string,bool> $abilities Ability map.
	 */
	private static function render_persona_groups( $agent, array $caps, array $actions, array $abilities ) {
		$groups = array(
			'create' => array(
				'label' => _x( 'Create', 'ability group title', 'agent-role' ),
				'lede'  => __( 'Abilities related to creating new posts and post types and uploading files.', 'agent-role' ),
			),
			'read'   => array(
				'label' => _x( 'Read', 'ability group title', 'agent-role' ),
				'lede'  => __( 'Abilities related to reading your website information and content. Cannot make changes.', 'agent-role' ),
			),
			'undo'   => array(
				'label' => _x( 'Undo', 'ability group title', 'agent-role' ),
				'lede'  => __( 'Abilities related to changing things on your website that already exist.', 'agent-role' ),
			),
			'delete' => array(
				'label' => _x( 'Delete', 'ability group title', 'agent-role' ),
				'lede'  => __( 'Removes content.', 'agent-role' ),
			),
			'other'  => array(
				'label' => __( 'Other Abilities', 'agent-role' ),
				'lede'  => __( 'Abilities that cannot be easily classified and come from plugins or a theme you installed on this site.', 'agent-role' ),
			),
		);

		$mapped = array();
		foreach ( self::persona_rows() as $row ) {
			$mapped[ $row['group'] ][] = $row;
		}

		$catalog = self::catalog_ability_rows();
		foreach ( array( 'create', 'read', 'undo', 'delete', 'other' ) as $group ) {
			$jobs  = isset( $mapped[ $group ] ) ? $mapped[ $group ] : array();
			$extra = isset( $catalog[ $group ] ) ? $catalog[ $group ] : array();
			if ( ! $jobs && ! $extra ) {
				continue;
			}

			echo '<fieldset class="ar-persona-group' . ( 'other' === $group ? ' ar-persona-group--other' : '' ) . '">';
			echo '<legend class="ar-persona-group__title">' . esc_html( $groups[ $group ]['label'] ) . '</legend>';
			echo '<p class="ar-rf-lede">' . esc_html( $groups[ $group ]['lede'] ) . '</p>';
			echo '<div class="ar-persona-jobs">';
			foreach ( $jobs as $row ) {
				if ( 'ability' === $row['kind'] ) {
					$checked = ! empty( $abilities[ $row['id'] ] );
					$name    = 'agent_role_abilities[]';
				} elseif ( 'cap' === $row['kind'] ) {
					$checked = ! empty( $caps[ $row['id'] ] );
					$name    = 'agent_role_caps[]';
				} else {
					$checked = in_array( $row['id'], $actions, true );
					$name    = 'agent_role_actions[]';
				}
				self::render_persona_row( $row, $name, $checked );
			}
			foreach ( $extra as $source => $rows ) {
				foreach ( $rows as $row ) {
					$row['source'] = $source;
					$checked       = ! empty( $abilities[ $row['id'] ] );
					self::render_persona_row( $row, 'agent_role_abilities[]', $checked );
				}
			}
			echo '</div>';
			echo '</fieldset>';
		}
	}

	/**
	 * Listed abilities this site registered, filed by annotation and grouped by plugin.
	 *
	 * @return array<string,array<string,array<int,array{id:string,group:string,label:string,hint:string,kind:string}>>>
	 */
	private static function catalog_ability_rows() {
		$catalog = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $catalog;
		}

		$mapped = array_fill_keys( self::mapped_ability_ids(), true );
		foreach ( wp_get_abilities() as $ability ) {
			try {
				if ( ! Agent_Role::is_listed_ability( $ability ) ) {
					continue;
				}
				$name = $ability->get_name();
				if ( isset( $mapped[ $name ] ) ) {
					continue;
				}
				$group  = self::file_ability_group( $ability );
				$source = self::ability_source_label( $ability );

				$catalog[ $group ][ $source ][] = array(
					'id'    => $name,
					'group' => $group,
					'label' => wp_strip_all_tags( $ability->get_label() ),
					'hint'  => '',
					'kind'  => 'ability',
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		foreach ( $catalog as $group => $sources ) {
			ksort( $catalog[ $group ] );
			unset( $sources );
		}

		return $catalog;
	}

	/**
	 * Plugin or category label for a listed ability.
	 *
	 * @param mixed $ability Ability instance.
	 */
	private static function ability_source_label( $ability ) {
		$slug = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		if ( '' !== $slug && function_exists( 'wp_get_ability_category' ) ) {
			$category = wp_get_ability_category( $slug );
			if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
				$label = wp_strip_all_tags( $category->get_label() );
				if ( '' !== $label ) {
					return $label;
				}
			}
		}

		if ( '' !== $slug ) {
			return $slug;
		}

		$name = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
		$cut  = strpos( $name, '/' );
		return false === $cut ? $name : substr( $name, 0, $cut );
	}

	/**
	 * One Customize row: the label wraps the control.
	 *
	 * @param array{id:string,label:string,hint:string,source?:string} $row     Row copy.
	 * @param string                                                   $name    Input name.
	 * @param bool                                                     $checked Whether the switch is on.
	 */
	private static function render_persona_row( array $row, $name, $checked ) {
		$safe_id = sanitize_html_class( str_replace( '/', '-', $row['id'] ) );
		$hint_id = $row['hint'] ? 'agent-role-hint-' . $safe_id : '';
		$source  = isset( $row['source'] ) ? $row['source'] : '';
		echo '<label class="ar-persona-row">';
		echo '<span class="ar-persona-row__copy">';
		echo esc_html( $row['label'] );
		if ( '' !== $source ) {
			echo '<span class="ar-persona-source">';
			echo self::plugin_badge_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is escaped in plugin_badge_icon().
			echo esc_html( $source );
			echo '</span>';
		}
		if ( $row['hint'] ) {
			echo '<small id="' . esc_attr( $hint_id ) . '" aria-hidden="true">' . esc_html( $row['hint'] ) . '</small>';
		}
		echo '</span>';
		echo '<span class="ar-rf-toggle">';
		echo '<input class="ar-rf-toggle__input" type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $row['id'] ) . '"';
		if ( $hint_id ) {
			echo ' aria-describedby="' . esc_attr( $hint_id ) . '"';
		}
		echo ' ' . checked( $checked, true, false ) . ' />';
		echo '<span class="ar-rf-toggle__track" aria-hidden="true"></span>';
		$toggle = self::toggle_labels();
		echo '<span class="ar-rf-toggle__state" aria-hidden="true" data-enabled="' . esc_attr( $toggle['enabled'] ) . '" data-disabled="' . esc_attr( $toggle['disabled'] ) . '">';
		echo esc_html( $checked ? $toggle['enabled'] : $toggle['disabled'] );
		echo '</span>';
		echo '</span>';
		echo '</label>';
	}
}
