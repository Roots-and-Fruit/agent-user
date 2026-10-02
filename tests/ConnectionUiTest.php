<?php
/**
 * Connection screens when agents use the REST API instead of MCP.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the create window and the Agents list without an MCP server.
 */
class ConnectionUiTest extends TestCase {

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		$this->user_ids = array();
		$_GET           = array();
		$_POST          = array();
		$_REQUEST       = array();
		add_filter( 'agent_role_uses_mcp', '__return_false' );
	}

	protected function tearDown(): void {
		global $wpdb;

		$table = Agent_Role_Log::table();
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		}
		remove_all_filters( 'agent_role_uses_mcp' );
		wp_set_current_user( 0 );
		$_GET = array();
		parent::tearDown();
	}

	public function test_create_window_shows_the_rest_prompt_and_the_password_once(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$user             = get_userdata( $result['user_id'] );
		$password         = get_transient( Agent_Role_Account::transient_key( $admin, $user->ID ) );
		$this->assertIsString( $password );

		$html = $this->render_screen( $admin, $user->ID );

		$this->assertStringContainsString( 'Prompt for your Agent', $html );
		$this->assertStringContainsString( 'connect through the WordPress REST API', $html );
		$this->assertStringContainsString( esc_html( rest_url( 'wp/v2/users/me' ) ), $html );
		$this->assertStringContainsString( 'Username: ' . $user->user_login, $html );
		$this->assertStringContainsString( Agent_Role_Connection::PASSWORD_PLACEHOLDER, $html );
		$this->assertStringNotContainsString( Agent_Role_Mcp::endpoint(), $html );
		$this->assertStringNotContainsString( '@automattic/mcp-wordpress-remote', $html );
		$this->assertStringNotContainsString( 'Copy these now. They will not be shown again.', $html );
		$this->assertSame( 1, substr_count( $html, $password ) );
		$this->assertStringNotContainsString( $password, Agent_Role_Connection::setup_prompt( $user->user_login, $user->ID ) );

		$again = $this->render_screen( $admin, $user->ID );
		$this->assertStringNotContainsString( $password, $again );
	}

	public function test_agents_list_offers_the_rest_prompt_when_the_agent_has_a_password(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$user             = get_userdata( $result['user_id'] );
		Agent_Role_Account::take_password( $admin, $user->ID );

		$html = $this->render_screen( $admin );
		$row  = $this->row_for( $html, $user );

		$this->assertStringContainsString( 'ar-rf-mcp-view', $row );
		$this->assertStringContainsString( 'id="ar-mcp-' . $user->ID . '"', $row );
		$this->assertStringContainsString( 'This site has no MCP server', $row );
		$this->assertStringContainsString( esc_html( rest_url( 'wp/v2/users/me' ) ), $row );
		$this->assertStringNotContainsString( Agent_Role_Mcp::endpoint(), $row );
		$this->assertStringNotContainsString( 'No MCP is currently present', $html );
		$this->assertStringNotContainsString( 'Create a password to connect this agent.', $row );
	}

	public function test_agents_list_asks_for_a_password_when_the_agent_has_none(): void {
		$admin = $this->make_user( 'administrator' );
		$agent = get_userdata( $this->make_user( Agent_Role::SLUG ) );
		$this->assertNull( Agent_Role_Account::managed_password( $agent->ID ) );

		$row = $this->row_for( $this->render_screen( $admin ), $agent );

		$this->assertStringContainsString( 'Create a password to connect this agent.', $row );
		$this->assertStringNotContainsString( 'ar-rf-mcp-view', $row );
		$this->assertStringNotContainsString( 'Prompt for your Agent', $row );
	}

	/**
	 * Render Users → Agents as this admin, optionally right after creating an agent.
	 *
	 * @param int $admin_id   Administrator.
	 * @param int $created_id Agent just created, or zero.
	 */
	private function render_screen( int $admin_id, int $created_id = 0 ): string {
		wp_set_current_user( $admin_id );
		$_GET = array();
		if ( $created_id ) {
			$_GET['created']  = $created_id;
			$_GET['_wpnonce'] = wp_create_nonce( 'agent_role_show_' . $created_id );
		}
		ob_start();
		Agent_Role_Admin::render();
		return (string) ob_get_clean();
	}

	/**
	 * The Agents list row for one agent.
	 *
	 * @param string  $html  Rendered screen.
	 * @param WP_User $agent Agent account.
	 */
	private function row_for( string $html, WP_User $agent ): string {
		$marker = '<span class="ar-rf-agent-login">(' . esc_html( $agent->user_login ) . ')</span>';
		$start  = strpos( $html, $marker );
		$this->assertNotFalse( $start, 'The agent has no row on the Agents list.' );
		$end = strpos( $html, '</tr>', $start );
		$this->assertNotFalse( $end );

		return substr( $html, $start, $end - $start );
	}

	private function make_user( string $role ): int {
		$login   = $this->unique_login();
		$args    = array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.invalid',
			'role'       => $role,
		);
		$user_id = Agent_Role::SLUG === $role ? Agent_Role_Account::insert_user( $args ) : wp_insert_user( $args );

		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		return $user_id;
	}

	private function unique_login(): string {
		return 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
	}
}
