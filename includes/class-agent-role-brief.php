<?php
/**
 * Facts an admin AI may use when drafting MCP instructions.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a short brief for one Agent and asks the site AI to rewrite it.
 */
class Agent_Role_Brief {

	/**
	 * System instruction for the draft. The model may use only the brief.
	 */
	const SYSTEM = 'Write the MCP instructions for this agent in 80 to 140 words. Use only the brief. Lead with what this persona is for, using persona.purpose. Name the site. Do not list abilities or WordPress capabilities. Do not invent tools. End with: use only tools this account is allowed to run; if a tool is denied, stop; lead with the result.';

	/**
	 * JSON packet for one Agent, built at the moment of the request.
	 *
	 * @param int                      $user_id   Agent user ID.
	 * @param array<string,bool>|null $caps      Capability map. Null reads the saved map.
	 * @param array<string,bool>|null $abilities Ability map. Null reads the saved map.
	 * @param string                  $persona   Persona slug. Empty reads the saved persona.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function for_user( $user_id, $caps = null, $abilities = null, $persona = '' ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return new WP_Error( 'agent_role_not_agent', __( 'That user is not an Agent.', 'agent-role' ) );
		}

		$home = home_url( '/' );
		$site = site_url( '/' );
		if ( ! is_string( $persona ) || '' === $persona ) {
			$saved   = get_user_meta( $user->ID, Agent_Role_Admin::PERSONA_META, true );
			$persona = is_string( $saved ) ? $saved : '';
		}

		return array(
			'site'          => array(
				'name'              => get_bloginfo( 'name' ),
				'url'               => $home,
				'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
			),
			'persona'       => self::persona_brief( $persona ),
			'agent'         => array(
				'login'            => $user->user_login,
				'capabilities_on'  => self::capability_names( $user, true, $caps ),
				'capabilities_off' => self::capability_names( $user, false, $caps ),
				'abilities_on'     => self::ability_names( $user, true, $abilities ),
				'abilities_off'    => self::ability_names( $user, false, $abilities ),
			),
			'ability_tools' => array(
				'mcp-adapter-discover-abilities',
				'mcp-adapter-get-ability-info',
				'mcp-adapter-execute-ability',
			),
			'abilities'     => self::catalog(),
			'quirks'        => self::quirks( $home, $site ),
		);
	}

	/**
	 * Ask the site AI to draft instructions. The textarea stays unsaved.
	 *
	 * @param int                      $user_id   Agent user ID.
	 * @param array<string,bool>|null $caps      Capability map. Null reads the saved map.
	 * @param array<string,bool>|null $abilities Ability map. Null reads the saved map.
	 * @param string                  $persona   Persona slug.
	 * @return string|WP_Error
	 */
	public static function draft( $user_id, $caps = null, $abilities = null, $persona = '' ) {
		if ( ! self::can_draft() ) {
			return new WP_Error(
				'agent_role_ai_unavailable',
				self::unavailable_message()
			);
		}

		$brief = self::for_user( $user_id, $caps, $abilities, $persona );
		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		try {
			$text = wp_ai_client_prompt( wp_json_encode( $brief ) )
				->using_system_instruction( self::SYSTEM )
				->using_temperature( 0.2 )
				->generate_text();
		} catch ( \Throwable $e ) {
			unset( $e );
			return new WP_Error(
				'agent_role_ai_unavailable',
				__( 'AI is not available on this site.', 'agent-role' )
			);
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error( 'agent_role_draft_empty', __( 'The AI returned no instructions.', 'agent-role' ) );
		}

		return trim( $text );
	}

	/**
	 * Whether this site can draft instructions. Optional. Never required.
	 */
	public static function can_draft() {
		try {
			if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) ) {
				return false;
			}
			if ( ! wp_supports_ai() ) {
				return false;
			}
			return self::has_text_connector();
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Message when Generate cannot run.
	 */
	private static function unavailable_message() {
		try {
			if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
				/* translators: "Settings" and "Connectors" are WordPress admin menu labels. */
				return __( 'Connect an AI provider under Settings → Connectors, then try again.', 'agent-role' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return __( 'AI is not available on this site.', 'agent-role' );
	}

	/**
	 * Whether a connected AI provider can write text.
	 */
	private static function has_text_connector() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		try {
			$prompt = wp_ai_client_prompt( 'ping' );
			if ( ! is_object( $prompt ) ) {
				return false;
			}
			$supported = $prompt->is_supported_for_text_generation();
			if ( is_wp_error( $supported ) ) {
				return false;
			}
			return (bool) $supported;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Persona slug and the purpose text for that job.
	 *
	 * @param string $persona Persona slug.
	 * @return array{slug:string,purpose:string}
	 */
	private static function persona_brief( $persona ) {
		if ( ! is_string( $persona ) || '' === $persona ) {
			return array(
				'slug'    => '',
				'purpose' => '',
			);
		}

		$shape = Agent_Role_Admin::site_persona_shape( $persona );
		return array(
			'slug'    => $persona,
			'purpose' => isset( $shape['instructions'] ) ? (string) $shape['instructions'] : '',
		);
	}

	/**
	 * Capability labels that are on or off for this agent.
	 *
	 * @param WP_User              $user    Agent account.
	 * @param bool                 $enabled Whether to return the on list.
	 * @param array<string,bool>|null $caps Capability map. Null reads the saved map.
	 * @return string[]
	 */
	private static function capability_names( $user, $enabled, $caps = null ) {
		$saved = is_array( $caps ) ? $caps : Agent_Role::cap_map( $user );
		$names = array();
		foreach ( Agent_Role::cap_choices() as $cap => $choice ) {
			$on = ! empty( $saved[ $cap ] );
			if ( $on === $enabled ) {
				$names[] = $choice['label'];
			}
		}
		return $names;
	}

	/**
	 * Ability names that are on or off for this agent.
	 *
	 * @param WP_User              $user      Agent account.
	 * @param bool                 $enabled   Whether to return the on list.
	 * @param array<string,bool>|null $abilities Ability map. Null reads the saved map.
	 * @return string[]
	 */
	private static function ability_names( $user, $enabled, $abilities = null ) {
		$saved = is_array( $abilities ) ? $abilities : get_user_meta( $user->ID, Agent_Role::ABILITIES_META, true );
		if ( ! is_array( $saved ) ) {
			$saved = Agent_Role::abilities_allowed_now( $user->ID );
		}

		$names = array();
		foreach ( self::catalog() as $ability ) {
			$on = ! empty( $saved[ $ability['name'] ] );
			if ( $on === $enabled ) {
				$names[] = $ability['name'];
			}
		}
		return $names;
	}

	/**
	 * Switchable abilities, one short record each.
	 *
	 * @return array<int,array{name:string,label:string,description:string,destructive:bool}>
	 */
	private static function catalog() {
		$catalog = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $catalog;
		}

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! Agent_Role::is_listed_ability( $ability ) ) {
				continue;
			}
			$meta        = $ability->get_meta();
			$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$catalog[]   = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $description,
				'destructive' => ! empty( $meta['annotations']['destructive'] ),
			);
		}

		return $catalog;
	}

	/**
	 * Facts PHP can prove about this site's URLs.
	 *
	 * @param string $home Home URL.
	 * @param string $site Site URL.
	 * @return array<string,string>
	 */
	private static function quirks( $home, $site ) {
		$quirks = array(
			'home_url' => $home,
		);
		if ( $home !== $site ) {
			$quirks['site_url'] = $site;
		}
		return $quirks;
	}
}
