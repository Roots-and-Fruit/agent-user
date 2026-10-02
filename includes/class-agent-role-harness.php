<?php
/**
 * Setup prompt for agents that use the REST API without MCP.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the REST prompt. The password line stays a placeholder.
 */
class Agent_Role_Harness {

	/**
	 * Prompt a person pastes into their agent after creating an account.
	 *
	 * @param string $username Agent username.
	 * @param int    $user_id  Agent user ID. Zero leaves out the capability list.
	 */
	public static function setup_prompt( $username, $user_id = 0 ) {
		$prompt = sprintf(
			/* translators: 1: site URL, 2: REST API base URL, 3: agent username, 4: password placeholder, 5: URL that returns the signed-in user, 6: abilities list URL. Keep input[...] and {"input":{...}} as they are. */
			__(
				'Connect to this WordPress site through its REST API. This site has no MCP server, so do not add one.

Site: %1$s
REST API base: %2$s
Username: %3$s
Application password: %4$s

Send every request over HTTPS with HTTP Basic authentication, using the username and the application password. Check the connection with GET %5$s. Use the wp/v2 routes for posts and media.

Save these four values in a local file for this project, such as a .env file, so later requests can read them. Write the password as the text %4$s. Do not replace it. Do not ask the user to paste the application password into the chat. Do not invent one. Do not repeat one if you see one. If the file is inside a repository, make sure it is ignored by git and tell the user not to commit it.

After the file is saved, tell the user two things: which file you changed, and that they should replace %4$s in that file with the application password shown in WordPress. Stop there.

Later, to see the abilities this site offers, send GET %6$s. To run one, call %6$s/{name}/run. An ability marked readonly takes GET, with its input as input[...] query arguments. Every other ability takes POST with the JSON body {"input":{...}}. Pass only the input fields that ability lists.',
				'agent-role'
			),
			home_url( '/' ),
			rest_url(),
			$username,
			Agent_Role_Connection::PASSWORD_PLACEHOLDER,
			rest_url( 'wp/v2/users/me' ),
			rest_url( 'wp-abilities/v1/abilities' )
		);

		$allowed = self::allowed_labels( $user_id );
		if ( $allowed ) {
			$prompt .= "\n\n" . __( 'This account can:', 'agent-role' ) . "\n- " . implode( "\n- ", $allowed );
		}

		$abilities = self::ability_lines( $user_id );
		if ( $abilities ) {
			$prompt .= "\n\n" . __( 'This account can run these abilities:', 'agent-role' ) . "\n- " . implode( "\n- ", $abilities );
		}

		return $prompt;
	}

	/**
	 * "Label (name)" for each switched-on ability this agent is not known to be refused.
	 *
	 * An ability with required input cannot be probed without arguments, so only a clear refusal drops it.
	 *
	 * @param int $user_id Agent user ID.
	 * @return string[]
	 */
	private static function ability_lines( $user_id ) {
		if ( ! $user_id || ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$on       = get_user_meta( $user_id, Agent_Role::ABILITIES_META, true );
		$on       = is_array( $on ) ? $on : array();
		$lines    = array();
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			foreach ( wp_get_abilities() as $ability ) {
				$name = $ability->get_name();
				if ( empty( $on[ $name ] ) || false === Agent_Role::probe_ability_permission( $ability ) ) {
					continue;
				}
				$lines[] = $ability->get_label() . ' (' . $name . ')';
			}
		} finally {
			wp_set_current_user( $previous );
		}

		return $lines;
	}

	/**
	 * Labels for the switches this agent has on.
	 *
	 * @param int $user_id Agent user ID.
	 * @return string[]
	 */
	private static function allowed_labels( $user_id ) {
		$on     = Agent_Role::cap_map( $user_id ? get_userdata( $user_id ) : false );
		$labels = array();
		foreach ( Agent_Role::cap_choices() as $cap => $choice ) {
			if ( ! empty( $on[ $cap ] ) ) {
				$labels[] = $choice['label'];
			}
		}

		return $labels;
	}
}
