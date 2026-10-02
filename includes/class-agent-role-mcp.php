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
				self::server_name( $username ) => array(
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

	/**
	 * Server name for this site and agent. Safe in JSON and TOML.
	 *
	 * The host label and username are lowercased, and anything that is not a
	 * letter or number becomes an underscore. www is dropped.
	 *
	 * @param string $username Agent username.
	 */
	public static function server_name( $username ) {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$dot   = strpos( $host, '.' );
		$label = false === $dot ? $host : substr( $host, 0, $dot );
		$name  = self::server_token( $label ) . '_' . self::server_token( $username );
		$name  = trim( $name, '_' );

		return '' === $name ? 'site' : $name;
	}

	/**
	 * Prompt a person pastes into their agent after creating an account.
	 *
	 * The password line stays a placeholder. The agent finds the config for
	 * the app it is running in, then stops.
	 *
	 * @param string $username Agent username.
	 */
	public static function setup_prompt( $username ) {
		return sprintf(
			/* translators: 1: MCP server name for this site and agent, 2: MCP endpoint URL, 3: agent username, 4: password placeholder. */
			__(
				'Add this WordPress site as an MCP server in the app you are running in now. Use that app\'s own MCP configuration. Merge this server into the existing file and keep every server that is already configured.

Name the server %1$s.

Use this local command and these values. Environment values are strings, so OAUTH_ENABLED is the text false. Write them in the form this app already uses for MCP servers:

command: npx
arguments: -y @automattic/mcp-wordpress-remote@latest
WP_API_URL: %2$s
WP_API_USERNAME: %3$s
OAUTH_ENABLED: "false"
WP_API_PASSWORD: %4$s

Leave the password as the text %4$s. Do not replace it. Do not ask the user to paste the application password into the chat. Do not invent one. Do not repeat one if you see one. Do not open the config again after the user edits it. If the file you write is inside a repository, say so and tell the user not to commit it.

After the file is saved, tell the user three things: which file you changed, that they should replace %4$s in that file with the application password shown in WordPress, and that they should save the file and restart the app. Stop there.',
				'agent-role'
			),
			self::server_name( $username ),
			self::endpoint(),
			$username,
			Agent_Role_Connection::PASSWORD_PLACEHOLDER
		);
	}

	/**
	 * Letters and numbers only, for a server name.
	 *
	 * @param string $value Raw host label or username.
	 */
	private static function server_token( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );

		return trim( (string) $value, '_' );
	}
}
