<?php
/**
 * Prompt routing tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers which setup prompt an agent receives.
 */
class ConnectionPromptTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'agent_role_uses_mcp' );
		parent::tearDown();
	}

	public function test_default_follows_whether_the_adapter_is_loaded(): void {
		$this->assertSame( Agent_Role_Mcp::is_available(), Agent_Role_Connection::uses_mcp() );
	}

	public function test_mcp_mode_returns_the_mcp_prompt(): void {
		add_filter( 'agent_role_uses_mcp', '__return_true' );

		$prompt = Agent_Role_Connection::setup_prompt( 'helper_bot', 0 );

		$this->assertSame( Agent_Role_Mcp::setup_prompt( 'helper_bot' ), $prompt );
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote@latest', $prompt );
		$this->assertStringContainsString( Agent_Role_Mcp::endpoint(), $prompt );
	}

	public function test_rest_mode_returns_the_rest_prompt(): void {
		add_filter( 'agent_role_uses_mcp', '__return_false' );

		$prompt = Agent_Role_Connection::setup_prompt( 'helper_bot', 0 );

		$this->assertSame( Agent_Role_Harness::setup_prompt( 'helper_bot', 0 ), $prompt );
		$this->assertStringNotContainsString( Agent_Role_Mcp::endpoint(), $prompt );
	}

	public function test_both_prompts_leave_the_same_placeholder_for_the_password(): void {
		$mcp  = Agent_Role_Mcp::setup_prompt( 'helper_bot' );
		$rest = Agent_Role_Harness::setup_prompt( 'helper_bot' );

		$this->assertStringContainsString( 'WP_API_PASSWORD: ' . Agent_Role_Connection::PASSWORD_PLACEHOLDER, $mcp );
		$this->assertStringContainsString( 'Application password: ' . Agent_Role_Connection::PASSWORD_PLACEHOLDER, $rest );
		$this->assertStringNotContainsString( '%4$s', $mcp );
		$this->assertStringNotContainsString( '%4$s', $rest );
	}
}
