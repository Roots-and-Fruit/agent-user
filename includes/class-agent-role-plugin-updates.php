<?php
/**
 * Plugin update abilities for the Web Dev persona.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists pending plugin updates for agents that may update plugins.
 *
 * Loaded on every request. MCP and the Abilities REST routes are not wp-admin.
 */
class Agent_Role_Plugin_Updates {

	const CATEGORY = 'agent-role';

	const LIST_UPDATES = 'agent-role/list-plugin-updates';

	const CHANGELOG = 'agent-role/get-plugin-changelog';

	const UPDATE = 'agent-role/update-plugin';

	const ROLLBACK = 'wp-rollback/rollback';

	/**
	 * Upgrader lock shared by the update and rollback abilities.
	 */
	const LOCK = 'agent_role_plugin_update';

	/**
	 * Register the category and abilities when the Abilities API is present.
	 */
	public static function register() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Ability names Web Dev starts with. The rollback name only runs while WP Rollback is active.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array( self::LIST_UPDATES, self::CHANGELOG, self::UPDATE, self::ROLLBACK );
	}

	/**
	 * Category that names Agent Role on the Customize badge.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Agent Role', 'agent-role' ),
				'description' => __( 'Plugin updates an agent can check and apply.', 'agent-role' ),
			)
		);
	}

	/**
	 * Register the abilities this file owns.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::LIST_UPDATES,
			array(
				'label'               => __( 'List plugin updates', 'agent-role' ),
				'description'         => __( 'Plugins WordPress has already marked for update, the same list as the Plugins screen. Does not contact WordPress.org.', 'agent-role' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'updates' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'plugin'         => array( 'type' => 'string' ),
									'slug'           => array( 'type' => 'string' ),
									'name'           => array( 'type' => 'string' ),
									'installed'      => array( 'type' => 'string' ),
									'offered'        => array( 'type' => 'string' ),
									'upgrade_notice' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'list_updates' ),
				'permission_callback' => array( __CLASS__, 'can_update' ),
				'meta'                => self::meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		wp_register_ability(
			self::CHANGELOG,
			array(
				'label'               => __( 'Read a plugin changelog', 'agent-role' ),
				'description'         => __( 'The changelog for one offered version from WordPress.org. Pass installed to also get every version in between.', 'agent-role' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'      => array(
							'type'        => 'string',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
							'description' => __( 'WordPress.org plugin slug, from the update list.', 'agent-role' ),
						),
						'version'   => array(
							'type'        => 'string',
							'description' => __( 'Offered version, from the update list.', 'agent-role' ),
						),
						'installed' => array(
							'type'        => 'string',
							'description' => __( 'Optional. Installed version. Returns every version newer than this, up to version.', 'agent-role' ),
						),
					),
					'required'             => array( 'slug', 'version' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'slug'            => array( 'type' => 'string' ),
						'version'         => array( 'type' => 'string' ),
						'found'           => array( 'type' => 'boolean' ),
						'sections'        => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'version' => array( 'type' => 'string' ),
									'text'    => array( 'type' => 'string' ),
								),
							),
						),
						'listed_versions' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_changelog' ),
				'permission_callback' => array( __CLASS__, 'can_update' ),
				'meta'                => self::meta(
					array(
						'readonly'      => true,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => true,
					)
				),
			)
		);

		wp_register_ability(
			self::UPDATE,
			array(
				'label'               => __( 'Update a plugin', 'agent-role' ),
				'description'         => __( 'Install the offered update for one plugin. If the plugin was active, the homepage is checked in the same request. A fatal error puts the previous version back.', 'agent-role' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'Plugin file, such as akismet/akismet.php, from the update list.', 'agent-role' ),
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'outcome'      => array(
							'type' => 'string',
							'enum' => array( 'updated', 'restored', 'failed' ),
						),
						'plugin'       => array( 'type' => 'string' ),
						'from_version' => array( 'type' => 'string' ),
						'to_version'   => array( 'type' => 'string' ),
						'was_active'   => array( 'type' => 'boolean' ),
						'message'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'update_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_update' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * Whether the current user may update plugins.
	 */
	public static function can_update() {
		return current_user_can( 'update_plugins' );
	}

	/**
	 * Pending updates as rows.
	 *
	 * @return array{updates:array<int,array<string,string>>}
	 */
	public static function list_updates() {
		$rows = array();
		foreach ( self::pending() as $file => $offer ) {
			$rows[] = array(
				'plugin'         => $file,
				'slug'           => $offer['slug'],
				'name'           => $offer['name'],
				'installed'      => $offer['installed'],
				'offered'        => $offer['offered'],
				'upgrade_notice' => $offer['upgrade_notice'],
			);
		}

		return array( 'updates' => $rows );
	}

	/**
	 * Changelog sections for one offered version, or for every version since the installed one.
	 *
	 * @param array{slug:string,version:string,installed?:string} $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_changelog( $input ) {
		$info = self::plugin_info( $input['slug'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$all      = self::parse_changelog( isset( $info->sections['changelog'] ) ? (string) $info->sections['changelog'] : '' );
		$sections = self::pick_sections( $all, $input['version'], isset( $input['installed'] ) ? (string) $input['installed'] : '' );

		return array(
			'slug'            => $input['slug'],
			'version'         => $input['version'],
			'found'           => array() !== $sections,
			'sections'        => $sections,
			'listed_versions' => wp_list_pluck( $all, 'version' ),
		);
	}

	/**
	 * Update one plugin to the offered version. Restore the old files if the homepage fatals.
	 *
	 * @param array{plugin:string} $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function update_plugin( $input ) {
		$file    = (string) $input['plugin'];
		$pending = self::pending();
		if ( ! isset( $pending[ $file ] ) ) {
			return self::outcome( 'failed', $file, '', '', false, __( 'WordPress has no update offered for this plugin. Run agent-role/list-plugin-updates and use a plugin file from it.', 'agent-role' ) );
		}

		$offer = $pending[ $file ];
		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return self::outcome( 'failed', $file, $offer['installed'], $offer['offered'], true, __( 'Network-activated plugins are updated from the network admin.', 'agent-role' ) );
		}
		if ( ! self::take_lock() ) {
			return self::outcome( 'failed', $file, $offer['installed'], $offer['offered'], is_plugin_active( $file ), __( 'Another plugin update or rollback is running. Try again in a few minutes.', 'agent-role' ) );
		}

		try {
			return self::run_update( $file, $offer );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Upgrade, put an active plugin back on the active list, then scrape the homepage.
	 *
	 * @param string               $file  Plugin file.
	 * @param array<string,string> $offer Row from pending().
	 * @return array<string,mixed>
	 */
	private static function run_update( $file, array $offer ) {
		$was_active = is_plugin_active( $file );
		$skin       = new WP_Ajax_Upgrader_Skin();
		$upgrader   = new Plugin_Upgrader( $skin );
		$paused     = self::pause_update_checks();
		try {
			$result = $upgrader->upgrade( $file, array( 'clear_update_cache' => false ) );
		} finally {
			foreach ( $paused as $check ) {
				add_action( 'upgrader_process_complete', $check, 10, 0 );
			}
		}

		if ( true !== $result ) {
			if ( $was_active ) {
				self::set_active( $file, true );
			}
			$why = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', $skin->get_error_messages() );
			/* translators: %s: upgrader error message. */
			return self::outcome( 'failed', $file, $offer['installed'], $offer['offered'], $was_active, sprintf( __( 'The update did not install: %s WordPress puts the previous version back when this request ends.', 'agent-role' ), wp_strip_all_tags( $why ) ) );
		}

		if ( $was_active ) {
			self::set_active( $file, true );
			$fatal = self::scrape_for_fatal();
			if ( true !== $fatal ) {
				return self::restore( $upgrader, $file, $offer, $fatal );
			}
		}

		$upgrader->delete_temp_backup();
		self::forget_offer( $file );

		return self::outcome( 'updated', $file, $offer['installed'], $offer['offered'], $was_active, __( 'Updated.', 'agent-role' ) . ( $was_active ? ' ' . __( 'The homepage loads without a fatal error.', 'agent-role' ) : '' ) );
	}

	/**
	 * Put the pre-update files back. If that fails, deactivate so the homepage is not left fatal.
	 *
	 * @param Plugin_Upgrader      $upgrader Upgrader that ran the update.
	 * @param string               $file     Plugin file.
	 * @param array<string,string> $offer    Row from pending().
	 * @param string               $fatal    What the scrape found.
	 * @return array<string,mixed>
	 */
	private static function restore( $upgrader, $file, array $offer, $fatal ) {
		$restored = $upgrader->restore_temp_backup(
			array(
				array(
					'slug' => dirname( $file ),
					'src'  => WP_PLUGIN_DIR,
					'dir'  => 'plugins',
				),
			)
		);
		wp_clean_plugins_cache( false );

		if ( true !== $restored ) {
			self::set_active( $file, false );
			/* translators: %s: what the homepage check found. */
			return self::outcome( 'failed', $file, $offer['installed'], $offer['offered'], true, sprintf( __( '%s The previous version could not be put back, so the plugin was deactivated. Use wp-rollback/rollback, or reinstall it by hand.', 'agent-role' ), $fatal ) );
		}

		/* translators: 1: what the homepage check found, 2: restored version. */
		return self::outcome( 'restored', $file, $offer['installed'], $offer['offered'], true, sprintf( __( '%1$s Version %2$s was put back and is still active.', 'agent-role' ), $fatal, $offer['installed'] ) );
	}

	/**
	 * Load the homepage with a scrape key, the way core checks plugin edits and auto-updates.
	 *
	 * A loopback that cannot connect counts as a fatal, as in core.
	 *
	 * @return true|string True when clean, otherwise what went wrong.
	 */
	public static function scrape_for_fatal() {
		$nonce     = wp_generate_password( 32, false );
		$key       = md5( $nonce );
		$transient = 'scrape_key_' . $key;
		set_transient( $transient, $nonce, MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'wp_scrape_key'   => $key,
				'wp_scrape_nonce' => $nonce,
			),
			home_url( '/' )
		);
		/** This filter is documented in wp-includes/class-wp-http-streams.php */
		$sslverify = apply_filters( 'https_local_ssl_verify', false, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied like core's own loopback.
		$response  = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				'sslverify' => $sslverify,
			)
		);
		delete_transient( $transient );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: HTTP error message. */
			return sprintf( __( 'The homepage could not be loaded to check the update: %s.', 'agent-role' ), $response->get_error_message() );
		}

		$body   = wp_remote_retrieve_body( $response );
		$start  = "###### wp_scraping_result_start:$key ######";
		$at     = strpos( $body, $start );
		$result = null;
		if ( false !== $at ) {
			$rest   = substr( $body, $at + strlen( $start ) );
			$result = json_decode( trim( substr( $rest, 0, (int) strpos( $rest, "###### wp_scraping_result_end:$key ######" ) ) ), true );
		}
		if ( ! isset( $result['type'] ) ) {
			return true;
		}

		/* translators: 1: PHP error message, 2: file, 3: line. */
		return sprintf( __( 'The homepage had a fatal error: %1$s in %2$s on line %3$d.', 'agent-role' ), $result['message'], $result['file'], $result['line'] );
	}

	/**
	 * Take the shared update lock. Loads the upgrader classes.
	 */
	public static function take_lock() {
		self::load_upgrader();
		return WP_Upgrader::create_lock( self::LOCK, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Release the shared update lock.
	 */
	public static function release_lock() {
		WP_Upgrader::release_lock( self::LOCK );
	}

	/**
	 * Unhook core's WordPress.org checks that run after every upgrade when admin hooks are loaded.
	 *
	 * They add up to three remote calls to an agent's request. Cron and the next admin visit refresh the data.
	 *
	 * @return string[] Callbacks that were hooked.
	 */
	private static function pause_update_checks() {
		$paused = array();
		foreach ( array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' ) as $check ) {
			if ( 10 === has_action( 'upgrader_process_complete', $check ) ) {
				remove_action( 'upgrader_process_complete', $check, 10 );
				$paused[] = $check;
			}
		}

		return $paused;
	}

	/**
	 * Add or remove a plugin on the active list without loading its code in this request.
	 *
	 * @param string $file   Plugin file.
	 * @param bool   $active Whether it should be active.
	 */
	private static function set_active( $file, $active ) {
		$plugins = (array) get_option( 'active_plugins', array() );
		$plugins = array_values( array_diff( $plugins, array( $file ) ) );
		if ( $active ) {
			$plugins[] = $file;
			sort( $plugins );
		}
		update_option( 'active_plugins', $plugins );
	}

	/**
	 * Drop one plugin from the update offers and keep the rest listed.
	 *
	 * @param string $file Plugin file.
	 */
	private static function forget_offer( $file ) {
		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) && isset( $transient->response[ $file ] ) ) {
			unset( $transient->response[ $file ] );
			set_site_transient( 'update_plugins', $transient );
		}
	}

	/**
	 * Update result in the shape the output schema describes.
	 *
	 * @param string $outcome updated, restored, or failed.
	 * @param string $file    Plugin file.
	 * @param string $from    Installed version before the call.
	 * @param string $to      Offered version.
	 * @param bool   $active  Whether the plugin was active.
	 * @param string $message What happened, for the agent.
	 * @return array<string,mixed>
	 */
	private static function outcome( $outcome, $file, $from, $to, $active, $message ) {
		return array(
			'outcome'      => $outcome,
			'plugin'       => $file,
			'from_version' => $from,
			'to_version'   => $to,
			'was_active'   => (bool) $active,
			'message'      => $message,
		);
	}

	/**
	 * Plain-text changelog sections that match a version, or a version range when installed is set.
	 *
	 * @param string $html      sections.changelog from WordPress.org.
	 * @param string $version   Offered version.
	 * @param string $installed Optional installed version.
	 * @return array<int,array{version:string,text:string}>
	 */
	public static function changelog_sections( $html, $version, $installed = '' ) {
		return self::pick_sections( self::parse_changelog( $html ), $version, $installed );
	}

	/**
	 * Split changelog HTML at version headings. Other headings stay inside their version.
	 *
	 * @param string $html Changelog HTML.
	 * @return array<int,array{version:string,html:string}>
	 */
	private static function parse_changelog( $html ) {
		$parts    = preg_split( '#(<h[1-6][^>]*>.*?</h[1-6]>)#is', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$sections = array();
		$current  = null;
		foreach ( (array) $parts as $part ) {
			$version = self::heading_version( $part );
			if ( null !== $version ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}
				$current = array(
					'version' => $version,
					'html'    => '',
				);
				continue;
			}
			if ( null !== $current ) {
				$current['html'] .= $part;
			}
		}
		if ( null !== $current ) {
			$sections[] = $current;
		}

		return $sections;
	}

	/**
	 * Version a heading starts with, such as "1.2.3", "Version 1.2", or "1.2.3 (1 May 2026)".
	 *
	 * @param string $part One preg_split piece.
	 * @return string|null
	 */
	private static function heading_version( $part ) {
		if ( ! preg_match( '#^<h[1-6]#i', $part ) ) {
			return null;
		}

		$text = trim( html_entity_decode( wp_strip_all_tags( $part ), ENT_QUOTES, 'UTF-8' ) );
		if ( preg_match( '/^(?:version\s*|v)?(\d+(?:\.\d+)+)(?![\d.])/i', $text, $match ) ) {
			return $match[1];
		}

		return null;
	}

	/**
	 * Keep the offered version, or every version after installed up to the offer.
	 *
	 * @param array<int,array{version:string,html:string}> $sections  Parsed sections.
	 * @param string                                       $version   Offered version.
	 * @param string                                       $installed Installed version, or empty.
	 * @return array<int,array{version:string,text:string}>
	 */
	private static function pick_sections( array $sections, $version, $installed ) {
		$picked = array();
		foreach ( $sections as $section ) {
			if ( '' === $installed ) {
				$keep = version_compare( $section['version'], $version, '==' );
			} else {
				$keep = version_compare( $section['version'], $installed, '>' ) && version_compare( $section['version'], $version, '<=' );
			}
			if ( $keep ) {
				$picked[] = array(
					'version' => $section['version'],
					'text'    => self::plain_text( $section['html'] ),
				);
			}
		}

		return $picked;
	}

	/**
	 * Changelog HTML as readable plain text. List items become "- " lines.
	 *
	 * @param string $html Section HTML.
	 */
	private static function plain_text( $html ) {
		$html = preg_replace( '#>\s*\n\s*<#', '><', $html );
		$html = preg_replace( '#<li[^>]*>#i', "\n- ", (string) $html );
		$html = preg_replace( '#<br\s*/?>|</(p|h[1-6]|ul|ol|div)>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * WordPress.org plugin information with sections and the version list.
	 *
	 * @param string $slug Plugin slug.
	 * @return object|WP_Error
	 */
	public static function plugin_info( $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections' => true,
					'versions' => true,
				),
			)
		);
		if ( is_wp_error( $info ) || ! is_object( $info ) ) {
			return new WP_Error(
				'agent_role_plugin_info',
				is_wp_error( $info ) ? html_entity_decode( wp_strip_all_tags( $info->get_error_message() ), ENT_QUOTES, 'UTF-8' ) : __( 'WordPress.org did not return plugin information.', 'agent-role' ),
				array( 'status' => 502 )
			);
		}

		return $info;
	}

	/**
	 * Installed plugins WordPress has an offer for, keyed by plugin file.
	 *
	 * @return array<string,array{slug:string,name:string,installed:string,offered:string,upgrade_notice:string,package:string}>
	 */
	private static function pending() {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		self::load_admin_includes();
		$installed = get_plugins();
		$pending   = array();
		foreach ( $transient->response as $file => $offer ) {
			if ( ! is_object( $offer ) || ! isset( $installed[ $file ], $offer->new_version ) ) {
				continue;
			}
			$pending[ $file ] = array(
				'slug'           => isset( $offer->slug ) ? (string) $offer->slug : dirname( $file ),
				'name'           => wp_strip_all_tags( $installed[ $file ]['Name'] ),
				'installed'      => (string) $installed[ $file ]['Version'],
				'offered'        => (string) $offer->new_version,
				'upgrade_notice' => isset( $offer->upgrade_notice ) ? wp_strip_all_tags( (string) $offer->upgrade_notice ) : '',
				'package'        => isset( $offer->package ) ? (string) $offer->package : '',
			);
		}

		return $pending;
	}

	/**
	 * Admin files the update tools need outside wp-admin.
	 */
	private static function load_admin_includes() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Upgrader classes and the admin files they call.
	 */
	private static function load_upgrader() {
		self::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Meta shared by these abilities. Public for REST and MCP.
	 *
	 * @param array<string,bool> $annotations Filing marks.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ) {
		return array(
			'annotations'  => $annotations,
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}
}
