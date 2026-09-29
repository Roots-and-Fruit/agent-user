<?php
/**
 * Optional integration with the WordPress MCP Adapter.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates the adapter's default server to Agents and builds the client config.
 *
 * Nothing here runs unless the MCP Adapter plugin is active.
 */
class Agent_Role_Mcp {

	const OPTION = 'agent_role_mcp_agents_only';

	const CAP = 'agent_role_use_mcp';

	const ROUTE = 'mcp/mcp-adapter-default-server';

	const PASSWORD_PLACEHOLDER = 'YOUR_APPLICATION_PASSWORD';

	const GROUP = 'agent_role_mcp';

	/**
	 * Register hooks once every plugin has loaded.
	 */
	public static function register() {
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'user_has_cap', array( __CLASS__, 'grant_capability' ), 10, 4 );
		add_filter( 'mcp_adapter_default_transport_permission_user_capability', array( __CLASS__, 'transport_capability' ) );
		add_filter( 'mcp_adapter_initialize_response', array( __CLASS__, 'initialize_response' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		self::register_setting();
	}

	/**
	 * Register the agents-only option with the Settings API.
	 */
	public static function register_setting() {
		if ( isset( $GLOBALS['wp_registered_settings'][ self::OPTION ] ) ) {
			return;
		}

		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_agents_only' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Store on only for the posted checkbox value.
	 *
	 * @param mixed $value Raw option value. A missing checkbox arrives as null.
	 */
	public static function sanitize_agents_only( $value ) {
		return ( true === $value || 1 === $value || '1' === $value ) ? '1' : '0';
	}

	/**
	 * Whether the MCP Adapter is loaded.
	 */
	public static function is_available() {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/**
	 * Give Agents the MCP capability at runtime. It is never stored on the role.
	 *
	 * @param array<string,bool> $allcaps Capabilities the user has.
	 * @param string[]           $caps    Primitive capabilities being checked.
	 * @param array<int,mixed>   $args    Arguments passed to has_cap().
	 * @param WP_User            $user    User being checked.
	 * @return array<string,bool>
	 */
	public static function grant_capability( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( self::CAP, (array) $caps, true ) ) {
			return $allcaps;
		}

		if ( Agent_Role::is_agent( $user ) ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;
	}

	/**
	 * Capability the adapter checks before it accepts an MCP request.
	 *
	 * @param string $capability Capability the adapter would check. Default 'read'.
	 * @return string
	 */
	public static function transport_capability( $capability ) {
		if ( self::agents_only() ) {
			return self::CAP;
		}

		return $capability;
	}

	/**
	 * Whether only Agents may use the MCP server.
	 */
	public static function agents_only() {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Save the gate setting.
	 *
	 * @param bool $enabled Whether only Agents may use the MCP server.
	 */
	public static function set_agents_only( $enabled ) {
		update_option( self::OPTION, self::sanitize_agents_only( $enabled ? '1' : null ) );
	}

	/**
	 * Put this agent's instructions on the MCP initialize response.
	 *
	 * @param mixed $result Initialize result from the adapter.
	 * @return mixed
	 */
	public static function initialize_response( $result ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
			return $result;
		}

		$user = wp_get_current_user();
		if ( ! Agent_Role::is_agent( $user ) ) {
			return $result;
		}

		$note = Agent_Role::instructions_for( $user );
		if ( '' === $note || ! class_exists( '\WP\McpSchema\Common\Protocol\DTO\InitializeResult' ) ) {
			return $result;
		}

		$data                 = $result->toArray();
		$data['instructions'] = $note;

		return \WP\McpSchema\Common\Protocol\DTO\InitializeResult::fromArray( $data );
	}

	/**
	 * URL of the adapter's default server.
	 */
	public static function endpoint() {
		return rest_url( self::ROUTE );
	}

	/**
	 * A ready mcpServers entry for @automattic/mcp-wordpress-remote.
	 *
	 * @param string $username Agent username.
	 * @param string $password Application password, as shown to the administrator.
	 * @return array<string,mixed>
	 */
	public static function client_config( $username, $password ) {
		return array(
			'mcpServers' => array(
				'wordpress' => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => self::endpoint(),
						'WP_API_USERNAME' => $username,
						'WP_API_PASSWORD' => $password,
						'OAUTH_ENABLED'   => 'false',
					),
				),
			),
		);
	}
}
