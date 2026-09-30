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

	const SLUG = 'rootsandfruit_agent_role';

	const NAME = 'Agent';

	/**
	 * Gettext domain. Distinct from the plugin slug so another agent-role plugin cannot collide.
	 */
	const TEXT_DOMAIN = 'rf-agent-role';

	const CAPS_META = '_agent_role_caps';

	const MIGRATED_OPTION = 'agent_role_caps_migrated';

	const ABILITIES_META = '_agent_role_abilities';

	const INSTRUCTIONS_META = '_agent_role_instructions';

	const INSTRUCTIONS_REV_OPTION = 'agent_role_instructions_rev';

	const INSTRUCTIONS_REV = 1;

	const PERSONA_DEFAULTS_OPTION = 'agent_role_persona_defaults';

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
	 * Capability slugs a site owner can turn on or off. No translations, safe during login.
	 *
	 * @return string[]
	 */
	public static function cap_slugs() {
		return array(
			'edit_posts',
			'edit_published_posts',
			'publish_posts',
			'upload_files',
			'delete_posts',
			'delete_published_posts',
		);
	}

	/**
	 * Capabilities a site owner can turn on or off. Read stays on.
	 *
	 * @return array<string,array{label:string,destructive:bool}>
	 */
	public static function cap_choices() {
		return array(
			'edit_posts'             => array(
				'label'       => __( 'Create and edit posts', 'rf-agent-role' ),
				'destructive' => false,
			),
			'edit_published_posts'   => array(
				'label'       => __( 'Edit posts after they are published', 'rf-agent-role' ),
				'destructive' => false,
			),
			'publish_posts'          => array(
				'label'       => __( 'Publish posts', 'rf-agent-role' ),
				'destructive' => false,
			),
			'upload_files'           => array(
				'label'       => __( 'Upload files', 'rf-agent-role' ),
				'destructive' => false,
			),
			'delete_posts'           => array(
				'label'       => __( 'Delete their own posts', 'rf-agent-role' ),
				'destructive' => true,
			),
			'delete_published_posts' => array(
				'label'       => __( 'Delete published posts', 'rf-agent-role' ),
				'destructive' => true,
			),
		);
	}

	/**
	 * The six switches as this user has them now. Read is not included.
	 *
	 * A missing key follows the role. A stored false is off.
	 *
	 * @param WP_User $user User to read.
	 * @return array<string,bool>
	 */
	public static function cap_map( $user ) {
		$map = array();
		if ( ! $user instanceof WP_User ) {
			return $map;
		}

		foreach ( self::cap_slugs() as $cap ) {
			$map[ $cap ] = $user->has_cap( $cap );
		}

		return $map;
	}

	/**
	 * Write the six switches onto this user. Read stays on the role.
	 *
	 * Off is add_cap( $cap, false ). remove_cap() would drop the key and the role grant would return.
	 *
	 * @param int                $user_id User ID.
	 * @param array<string,bool> $enabled Capability slug => whether it is on.
	 */
	public static function apply_cap_map( $user_id, array $enabled ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		foreach ( self::cap_slugs() as $cap ) {
			$user->add_cap( $cap, ! empty( $enabled[ $cap ] ) );
		}
	}

	/**
	 * Copy legacy per-agent meta onto the user once.
	 *
	 * An Agent with no legacy map keeps following the role.
	 */
	public static function maybe_migrate_caps() {
		if ( defined( 'AGENT_ROLE_SKIP_MIGRATE' ) && AGENT_ROLE_SKIP_MIGRATE ) {
			return;
		}

		self::migrate_caps();
	}

	/**
	 * Copy `_agent_role_caps` onto each Agent, then forget that meta.
	 */
	public static function migrate_caps() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$ids = get_users(
			array(
				'role'   => self::SLUG,
				'fields' => 'ID',
			)
		);
		foreach ( $ids as $user_id ) {
			$saved = get_user_meta( (int) $user_id, self::CAPS_META, true );
			if ( ! is_array( $saved ) ) {
				continue;
			}

			self::apply_cap_map( (int) $user_id, $saved );
			delete_user_meta( (int) $user_id, self::CAPS_META );
		}

		update_option( self::MIGRATED_OPTION, '1', false );
	}

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
		delete_option( self::MIGRATED_OPTION );
		delete_option( self::PERSONA_DEFAULTS_OPTION );
		if ( class_exists( 'Agent_Role_Log' ) ) {
			delete_option( Agent_Role_Log::OPTION_DAYS );
			delete_option( Agent_Role_Log::OPTION_CAP );
			Agent_Role_Log::drop();
		}
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

	/**
	 * MCP hint built from this agent's saved abilities and account permissions.
	 *
	 * @param WP_User              $user       Agent account.
	 * @param array<string,bool>|null $caps       Capability map. Null reads the saved map.
	 * @param array<string,bool>|null $abilities  Ability map. Null reads the saved map.
	 */
	public static function compose_instructions( $user, $caps = null, $abilities = null ) {
		if ( ! $user instanceof WP_User ) {
			return '';
		}

		$paragraphs = array(
			sprintf( 'This connection is the WordPress agent %s.', $user->user_login ),
			'Call mcp-adapter-discover-abilities or mcp-adapter-get-ability-info before mcp-adapter-execute-ability when the ability name or its parameters are not already known. Pass only parameters that ability\'s schema lists.',
			self::ability_grant_sentence( $user, $abilities ),
			self::capability_boundary_sentence( $user, $caps ),
			'If an ability returns permission denied, say it is off for this agent and stop.',
			'When you report back, lead with the result and use the values the ability returned. Quote the url field from Get Site Information.',
		);

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Instructions sent when this agent connects. A new account uses the composed hint.
	 *
	 * @param WP_User $user Agent account.
	 */
	public static function instructions_for( $user ) {
		if ( metadata_exists( 'user', $user->ID, self::INSTRUCTIONS_META ) ) {
			$note = get_user_meta( $user->ID, self::INSTRUCTIONS_META, true );
			return is_string( $note ) ? trim( $note ) : '';
		}

		return self::compose_instructions( $user );
	}

	/**
	 * Write the composed hint for one agent.
	 *
	 * @param int $user_id Agent user ID.
	 */
	public static function store_instructions( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		update_user_meta( $user_id, self::INSTRUCTIONS_META, self::compose_instructions( $user ) );
	}

	/**
	 * Per-agent capability and ability checks.
	 */
	public static function register() {
		self::register_meta_keys();
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'map_directory_mofile' ), 10, 2 );
		add_filter( 'load_translation_file', array( __CLASS__, 'map_directory_translation_file' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_caps' ) );
		add_filter( 'wp_ability_permission_result', array( __CLASS__, 'filter_ability_permission' ), 10, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'maybe_refresh_instructions' ), 100 );
	}

	/**
	 * Load bundled translations from /languages.
	 *
	 * WordPress.org packs for the plugin directory still attach through map_directory_mofile().
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( AGENT_ROLE_FILE ) ) . '/languages'
		);
	}

	/**
	 * WordPress.org language packs are named after the plugin directory, not the text domain.
	 *
	 * @param string $mofile Path WordPress tried first.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function map_directory_mofile( $mofile, $domain ) {
		if ( self::TEXT_DOMAIN !== $domain || is_readable( $mofile ) ) {
			return $mofile;
		}

		$alt = self::directory_language_file( $mofile );
		return $alt ? $alt : $mofile;
	}

	/**
	 * Same remap for .l10n.php packs (WordPress 6.5+).
	 *
	 * @param string $file   Path WordPress tried first.
	 * @param string $domain Text domain.
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function map_directory_translation_file( $file, $domain, $locale ) {
		unset( $locale );
		if ( self::TEXT_DOMAIN !== $domain || ( $file && is_readable( $file ) ) ) {
			return $file;
		}

		$alt = self::directory_language_file( $file );
		return $alt ? $alt : $file;
	}

	/**
	 * Swap the text domain prefix for the plugin folder name in a language filename.
	 *
	 * @param string $file Original path.
	 * @return string|false Readable alternate path, or false.
	 */
	private static function directory_language_file( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		$slug     = dirname( plugin_basename( AGENT_ROLE_FILE ) );
		$base     = basename( $file );
		$alt_base = preg_replace( '/^' . preg_quote( self::TEXT_DOMAIN, '/' ) . '-/', $slug . '-', $base, 1 );
		if ( ! is_string( $alt_base ) || $alt_base === $base ) {
			return false;
		}

		$candidates = array(
			dirname( $file ) . '/' . $alt_base,
			WP_LANG_DIR . '/plugins/' . $alt_base,
		);
		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return false;
	}

	/**
	 * Sanitize the user meta this plugin still stores.
	 */
	public static function register_meta_keys() {
		register_meta(
			'user',
			self::ABILITIES_META,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_abilities' ),
			)
		);
		register_meta(
			'user',
			self::INSTRUCTIONS_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_instructions' ),
			)
		);
		register_meta(
			'user',
			Agent_Role_Account::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_managed' ),
			)
		);
	}

	/**
	 * Ability name => whether it is on.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array<string,bool>
	 */
	public static function sanitize_abilities( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $name => $on ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$clean[ $name ] = (bool) $on;
		}

		return $clean;
	}

	/**
	 * Connection note. Tags are stripped. The text is otherwise kept.
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize_instructions( $value ) {
		return sanitize_textarea_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Managed-account flag. Only the literal on value is stored.
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize_managed( $value ) {
		return ( true === $value || 1 === $value || '1' === $value ) ? '1' : '';
	}

	/**
	 * Replace stored hints once after this composer ships.
	 *
	 * Existing agents keep their saved ability map. The hint is rewritten from that map.
	 */
	public static function maybe_refresh_instructions() {
		if ( (int) get_option( self::INSTRUCTIONS_REV_OPTION, 0 ) >= self::INSTRUCTIONS_REV ) {
			return;
		}

		if ( ! function_exists( 'wp_get_abilities' ) || array() === wp_get_abilities() ) {
			return;
		}

		$ids = get_users(
			array(
				'role'   => self::SLUG,
				'fields' => 'ID',
			)
		);
		foreach ( $ids as $user_id ) {
			self::store_instructions( (int) $user_id );
		}

		update_option( self::INSTRUCTIONS_REV_OPTION, self::INSTRUCTIONS_REV, false );
	}

	/**
	 * Give a new Agent a private copy of the role's capabilities and the abilities those allow.
	 *
	 * @param int $user_id Agent user ID.
	 */
	public static function seed_agent( $user_id ) {
		$role = get_role( self::SLUG );
		$caps = array();
		foreach ( self::cap_choices() as $cap => $choice ) {
			unset( $choice );
			$caps[ $cap ] = (bool) ( $role && ! empty( $role->capabilities[ $cap ] ) );
		}
		self::apply_cap_map( $user_id, $caps );
		update_user_meta( $user_id, self::ABILITIES_META, self::abilities_allowed_now( $user_id ) );
		self::store_instructions( $user_id );
	}

	/**
	 * Let a saved ability list grant or block that ability for this Agent.
	 *
	 * Abilities that are not shown on the agent screen keep their own permission check.
	 *
	 * @param bool|WP_Error $result  Permission result from the ability.
	 * @param string        $name    Ability name.
	 * @param mixed         $input   Ability input.
	 * @param WP_Ability    $ability Ability being checked.
	 * @return bool|WP_Error
	 */
	public static function filter_ability_permission( $result, $name, $input, $ability ) {
		unset( $input );
		$user = wp_get_current_user();
		if ( ! self::is_agent( $user ) || ! self::is_listed_ability( $ability ) ) {
			return $result;
		}

		$saved = get_user_meta( $user->ID, self::ABILITIES_META, true );
		if ( ! is_array( $saved ) ) {
			return $result;
		}

		if ( ! empty( $saved[ $name ] ) ) {
			return $result;
		}

		return new WP_Error(
			'agent_role_ability_off',
			__( 'This ability is off for this agent.', 'rf-agent-role' )
		);
	}

	/**
	 * Whether this ability is one the agent screen can turn on or off.
	 *
	 * @param mixed $ability Ability instance.
	 */
	public static function is_listed_ability( $ability ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}

		$meta = $ability->get_meta();
		return ! empty( $meta['public'] ) || ( isset( $meta['mcp']['public'] ) && $meta['mcp']['public'] );
	}

	/**
	 * Abilities this user can run before a saved list exists.
	 *
	 * @param int $user_id User to check as.
	 * @return array<string,bool>
	 */
	public static function abilities_allowed_now( $user_id ) {
		$allowed = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $allowed;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! self::is_listed_ability( $ability ) ) {
				continue;
			}
			$ok = false;
			try {
				$ok = true === $ability->check_permissions( array() );
			} catch ( \Throwable $e ) {
				unset( $e );
				$ok = false;
			}
			$allowed[ $ability->get_name() ] = $ok;
		}
		wp_set_current_user( $previous );

		return $allowed;
	}

	/**
	 * Names of abilities this agent is allowed to run.
	 *
	 * @param WP_User              $user      Agent account.
	 * @param array<string,bool>|null $abilities Ability map. Null reads the saved map.
	 */
	private static function ability_grant_sentence( $user, $abilities = null ) {
		$saved = is_array( $abilities ) ? $abilities : get_user_meta( $user->ID, self::ABILITIES_META, true );
		if ( ! is_array( $saved ) ) {
			return 'Run only abilities this account is allowed to run.';
		}

		$enabled = array();
		$seen    = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! self::is_listed_ability( $ability ) ) {
					continue;
				}
				$name = $ability->get_name();
				if ( empty( $saved[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;
				$enabled[]     = $name . ' (' . wp_strip_all_tags( $ability->get_label() ) . ')';
			}
		}

		foreach ( $saved as $name => $on ) {
			if ( empty( $on ) || ! is_string( $name ) || isset( $seen[ $name ] ) ) {
				continue;
			}
			$enabled[] = $name;
		}

		if ( ! $enabled ) {
			return 'This agent has no abilities turned on.';
		}

		return 'This agent may run ' . implode( ', ', $enabled ) . '. Any other ability is off.';
	}

	/**
	 * Account permissions, named so they are not mistaken for abilities.
	 *
	 * @param WP_User              $user Agent account.
	 * @param array<string,bool>|null $caps Capability map. Null reads the saved map.
	 */
	private static function capability_boundary_sentence( $user, $caps = null ) {
		$saved  = is_array( $caps ) ? $caps : self::cap_map( $user );
		$labels = array();
		if ( is_array( $saved ) ) {
			foreach ( self::cap_choices() as $cap => $choice ) {
				if ( ! empty( $saved[ $cap ] ) ) {
					$labels[] = $choice['label'];
				}
			}
		}

		if ( ! $labels ) {
			return 'WordPress permissions on: read. Post, publish, upload, and delete permissions are off. None of those permissions are abilities on this connection.';
		}

		return 'WordPress permissions on: ' . implode( ', ', $labels ) . '. None of those permissions are abilities on this connection.';
	}
}
