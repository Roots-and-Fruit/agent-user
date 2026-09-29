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

	const CAPS_META = '_agent_role_caps';

	const ABILITIES_META = '_agent_role_abilities';

	const INSTRUCTIONS_META = '_agent_role_instructions';

	const INSTRUCTIONS_REV_OPTION = 'agent_role_instructions_rev';

	const INSTRUCTIONS_REV = 1;

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
				'label'       => __( 'Create and edit posts', 'agent-role' ),
				'destructive' => false,
			),
			'edit_published_posts'   => array(
				'label'       => __( 'Edit posts after they are published', 'agent-role' ),
				'destructive' => false,
			),
			'publish_posts'          => array(
				'label'       => __( 'Publish posts', 'agent-role' ),
				'destructive' => false,
			),
			'upload_files'           => array(
				'label'       => __( 'Upload files', 'agent-role' ),
				'destructive' => false,
			),
			'delete_posts'           => array(
				'label'       => __( 'Delete their own posts', 'agent-role' ),
				'destructive' => true,
			),
			'delete_published_posts' => array(
				'label'       => __( 'Delete published posts', 'agent-role' ),
				'destructive' => true,
			),
		);
	}

	/**
	 * Turn the chosen capabilities on and the others off. Read stays on.
	 *
	 * @param array<string,bool> $enabled Capability slug => whether it is on.
	 */
	public static function apply_cap_choices( array $enabled ) {
		$role = get_role( self::SLUG );
		if ( ! $role ) {
			return;
		}

		$role->add_cap( 'read' );
		foreach ( self::cap_choices() as $cap => $choice ) {
			unset( $choice );
			if ( ! empty( $enabled[ $cap ] ) ) {
				$role->add_cap( $cap );
			} else {
				$role->remove_cap( $cap );
			}
		}
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
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 20, 4 );
		add_filter( 'wp_ability_permission_result', array( __CLASS__, 'filter_ability_permission' ), 10, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'maybe_refresh_instructions' ), 100 );
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
		update_user_meta( $user_id, self::CAPS_META, $caps );
		update_user_meta( $user_id, self::ABILITIES_META, self::abilities_allowed_now( $user_id ) );
		self::store_instructions( $user_id );
	}

	/**
	 * Apply one agent's saved capabilities. Read stays on. Unsaved agents keep the role.
	 *
	 * @param array<string,bool> $allcaps Capabilities the user has.
	 * @param string[]           $caps    Primitive capabilities being checked.
	 * @param array<int,mixed>   $args    Arguments passed to has_cap().
	 * @param WP_User            $user    User being checked.
	 * @return array<string,bool>
	 */
	public static function filter_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );
		if ( ! self::is_agent( $user ) ) {
			return $allcaps;
		}

		$saved = get_user_meta( $user->ID, self::CAPS_META, true );
		if ( ! is_array( $saved ) ) {
			return $allcaps;
		}

		$allcaps['read'] = true;
		foreach ( self::cap_slugs() as $cap ) {
			$allcaps[ $cap ] = ! empty( $saved[ $cap ] );
		}

		return $allcaps;
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

		return ! empty( $saved[ $name ] );
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
		$saved  = is_array( $caps ) ? $caps : get_user_meta( $user->ID, self::CAPS_META, true );
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
