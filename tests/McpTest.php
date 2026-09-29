<?php
/**
 * MCP Adapter integration tests against the real adapter on this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the Agent-only transport gate, the runtime capability, and the client config.
 */
class McpTest extends TestCase {

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * Option value before the test.
	 *
	 * @var mixed
	 */
	private $saved_option;

	protected function setUp(): void {
		parent::setUp();
		$this->assertTrue( Agent_Role_Mcp::is_available(), 'MCP Adapter must be active on this site.' );
		Agent_Role::activate();
		$this->saved_option = get_option( Agent_Role_Mcp::OPTION, null );
		$this->user_ids     = array();
		$_POST              = array();
		$_REQUEST           = array();
		$_GET               = array();
	}

	protected function tearDown(): void {
		if ( null === $this->saved_option ) {
			delete_option( Agent_Role_Mcp::OPTION );
		} else {
			update_option( Agent_Role_Mcp::OPTION, $this->saved_option );
		}
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_redirect' );
		parent::tearDown();
	}

	public function test_gate_off_leaves_the_adapter_as_shipped(): void {
		Agent_Role_Mcp::set_agents_only( false );

		$agent  = $this->make_user( Agent_Role::SLUG );
		$author = $this->make_user( 'author' );

		$this->assertSame( 200, $this->initialize_as( $agent ) );
		$this->assertSame( 200, $this->initialize_as( $author ) );
		$this->assertSame( 401, $this->initialize_as( 0 ) );
	}

	public function test_gate_on_admits_agents_only(): void {
		Agent_Role_Mcp::set_agents_only( true );

		$agent  = $this->make_user( Agent_Role::SLUG );
		$author = $this->make_user( 'author' );
		$admin  = $this->make_user( 'administrator' );

		$this->assertSame( 200, $this->initialize_as( $agent ) );
		$this->assertSame( 403, $this->initialize_as( $author ) );
		$this->assertSame( 403, $this->initialize_as( $admin ) );
		$this->assertSame( 401, $this->initialize_as( 0 ) );
	}

	public function test_capability_is_granted_at_runtime_and_not_stored(): void {
		$agent  = $this->make_user( Agent_Role::SLUG );
		$author = $this->make_user( 'author' );

		$this->assertTrue( user_can( $agent, Agent_Role_Mcp::CAP ) );
		$this->assertFalse( user_can( $author, Agent_Role_Mcp::CAP ) );

		$caps = get_role( Agent_Role::SLUG )->capabilities;
		ksort( $caps );
		$this->assertSame(
			array(
				'delete_posts'           => true,
				'delete_published_posts' => true,
				'edit_posts'             => true,
				'edit_published_posts'   => true,
				'publish_posts'          => true,
				'read'                   => true,
				'upload_files'           => true,
			),
			$caps
		);
		$this->assertArrayNotHasKey( Agent_Role_Mcp::CAP, get_userdata( $agent )->caps );
	}

	public function test_client_config_is_shown_once_with_the_password(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$user             = get_userdata( $result['user_id'] );

		$expected = get_transient( Agent_Role_Account::transient_key( $admin, $user->ID ) );
		$this->assertIsString( $expected );

		$html = $this->render_created( $admin, $user->ID );

		$this->assertStringContainsString( Agent_Role_Mcp::endpoint(), $html );
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote@latest', $html );
		$this->assertStringContainsString( '&quot;WP_API_USERNAME&quot;: &quot;' . $user->user_login . '&quot;', $html );
		$this->assertStringContainsString( '&quot;WP_API_PASSWORD&quot;: &quot;' . $expected . '&quot;', $html );
		$this->assertSame( 2, substr_count( $html, $expected ) );

		$again = $this->render_created( $admin, $user->ID );
		$this->assertStringNotContainsString( $expected, $again );
		$this->assertStringContainsString( Agent_Role_Mcp::PASSWORD_PLACEHOLDER, $again );
	}

	public function test_the_mcp_option_is_a_registered_boolean_setting(): void {
		$this->assertFalse( method_exists( Agent_Role_Admin::class, 'handle_mcp_setting' ) );

		Agent_Role_Mcp::register_setting();
		global $new_allowed_options;
		$this->assertContains( Agent_Role_Mcp::OPTION, $new_allowed_options[ Agent_Role_Mcp::GROUP ] );

		$this->assertSame( '0', Agent_Role_Mcp::sanitize_agents_only( null ) );
		$this->assertSame( '1', Agent_Role_Mcp::sanitize_agents_only( '1' ) );
		$this->assertSame( '0', Agent_Role_Mcp::sanitize_agents_only( 'yes' ) );
	}

	public function test_uninstall_removes_the_option(): void {
		Agent_Role_Mcp::set_agents_only( true );
		$this->assertSame( '1', get_option( Agent_Role_Mcp::OPTION ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agent-role/agent-role.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( Agent_Role_Mcp::OPTION ) );
		$this->saved_option = null;
	}

	/**
	 * Dispatch an MCP initialize request through Core's REST server as a user.
	 *
	 * @param int $user_id User to act as. Zero for anonymous.
	 */
	private function initialize_as( int $user_id ): int {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/' . Agent_Role_Mcp::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => array(),
						'clientInfo'      => array(
							'name'    => 'agent-role-tests',
							'version' => '1.0.0',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		return $response->get_status();
	}

	private function render_created( int $admin_id, int $user_id ): string {
		wp_set_current_user( $admin_id );
		$_GET['created']  = $user_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'agent_role_show_' . $user_id );
		ob_start();
		Agent_Role_Admin::render();
		return (string) ob_get_clean();
	}

	private function catch_wp_die(): void {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( wp_strip_all_tags( (string) $message ) );
				};
			}
		);
	}

	private function make_user( string $role ): int {
		$login   = $this->unique_login();
		$user_id = Agent_Role::SLUG === $role
			? Agent_Role_Account::insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => $login . '@example.invalid',
					'role'       => $role,
				)
			)
			: wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => $login . '@example.invalid',
					'role'       => $role,
				)
			);

		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		return $user_id;
	}

	private function unique_login(): string {
		return 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
	}
}
