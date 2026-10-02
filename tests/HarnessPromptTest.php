<?php
/**
 * REST setup prompt tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the prompt an agent gets when the site has no MCP server.
 */
class HarnessPromptTest extends TestCase {

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
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_prompt_names_the_site_the_rest_base_and_the_username(): void {
		$prompt = Agent_Role_Harness::setup_prompt( 'helper_bot' );

		$this->assertStringContainsString( home_url( '/' ), $prompt );
		$this->assertStringContainsString( rest_url(), $prompt );
		$this->assertStringContainsString( rest_url( 'wp/v2/users/me' ), $prompt );
		$this->assertStringContainsString( 'Username: helper_bot', $prompt );
		$this->assertStringContainsString( 'Application password: ' . Agent_Role_Connection::PASSWORD_PLACEHOLDER, $prompt );
		$this->assertStringContainsString( 'Do not ask the user to paste the application password into the chat', $prompt );
	}

	public function test_prompt_has_no_mcp_setup(): void {
		$prompt = Agent_Role_Harness::setup_prompt( 'helper_bot' );

		$this->assertStringNotContainsString( 'npx', $prompt );
		$this->assertStringNotContainsString( '@automattic/mcp-wordpress-remote', $prompt );
		$this->assertStringNotContainsString( Agent_Role_Mcp::ROUTE, $prompt );
		$this->assertStringNotContainsString( 'mcp-adapter', $prompt );
		$this->assertStringNotContainsString( 'WP_API_URL', $prompt );
	}

	public function test_prompt_never_contains_the_real_password(): void {
		$admin  = $this->make_user( 'administrator' );
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$password = Agent_Role_Account::take_password( $admin, $result['user_id'] );
		$this->assertNotSame( '', $password );

		$user   = get_userdata( $result['user_id'] );
		$prompt = Agent_Role_Harness::setup_prompt( $user->user_login, $user->ID );

		$this->assertStringNotContainsString( $password, $prompt );
		$this->assertStringNotContainsString( WP_Application_Passwords::chunk_password( $password ), $prompt );
	}

	public function test_prompt_lists_only_the_switches_this_agent_has_on(): void {
		$agent = $this->make_user( Agent_Role::SLUG );
		Agent_Role::apply_cap_map(
			$agent,
			array(
				'edit_posts'   => true,
				'upload_files' => true,
			)
		);
		$choices = Agent_Role::cap_choices();

		$prompt = Agent_Role_Harness::setup_prompt( get_userdata( $agent )->user_login, $agent );

		$this->assertStringContainsString( '- ' . $choices['edit_posts']['label'], $prompt );
		$this->assertStringContainsString( '- ' . $choices['upload_files']['label'], $prompt );
		$this->assertStringNotContainsString( $choices['publish_posts']['label'], $prompt );
		$this->assertStringNotContainsString( $choices['delete_posts']['label'], $prompt );
		$this->assertStringNotContainsString( $choices['delete_published_posts']['label'], $prompt );
	}

	public function test_prompt_without_a_user_has_no_capability_list(): void {
		$this->assertStringNotContainsString( 'This account can:', Agent_Role_Harness::setup_prompt( 'helper_bot' ) );
	}

	public function test_prompt_explains_how_to_list_and_run_abilities(): void {
		$prompt = Agent_Role_Harness::setup_prompt( 'helper_bot' );

		$this->assertStringContainsString( 'GET ' . rest_url( 'wp-abilities/v1/abilities' ), $prompt );
		$this->assertStringContainsString( rest_url( 'wp-abilities/v1/abilities' ) . '/{name}/run', $prompt );
		$this->assertStringContainsString( 'input[', $prompt );
		$this->assertStringContainsString( '{"input":{...}}', $prompt );
	}

	public function test_web_dev_prompt_lists_its_abilities_by_label_and_name_and_editor_does_not(): void {
		$webdev = $this->make_user( Agent_Role::SLUG );
		$editor = $this->make_user( Agent_Role::SLUG );
		Agent_Role_Admin::assign_persona( $webdev, 'webdev' );
		Agent_Role_Admin::assign_persona( $editor, 'editor' );

		$prompt = Agent_Role_Harness::setup_prompt( get_userdata( $webdev )->user_login, $webdev );
		$this->assertStringContainsString( 'This account can run these abilities:', $prompt );
		foreach ( array( Agent_Role_Plugin_Updates::LIST_UPDATES, Agent_Role_Plugin_Updates::CHANGELOG, Agent_Role_Plugin_Updates::UPDATE ) as $name ) {
			$this->assertStringContainsString( '- ' . wp_get_ability( $name )->get_label() . ' (' . $name . ')', $prompt );
		}

		$other = Agent_Role_Harness::setup_prompt( get_userdata( $editor )->user_login, $editor );
		foreach ( Agent_Role_Plugin_Updates::ability_names() as $name ) {
			$this->assertStringNotContainsString( $name, $other );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_check_url_in_the_prompt_answers_for_the_agent_and_refuses_a_wrong_password(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$admin  = $this->make_user( 'administrator' );
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$password         = Agent_Role_Account::take_password( $admin, $result['user_id'] );
		$user             = get_userdata( $result['user_id'] );

		$prompt = Agent_Role_Harness::setup_prompt( $user->user_login, $user->ID );
		$this->assertStringContainsString( rest_url( 'wp/v2/users/me' ), $prompt );

		$this->assertSame( 401, $this->users_me_as( $user->user_login, 'not-the-password' )->get_status() );

		$response = $this->users_me_as( $user->user_login, $password );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $user->ID, $response->get_data()['id'] );
	}

	/**
	 * GET /wp/v2/users/me after Basic auth with these credentials.
	 *
	 * @param string $login    Username.
	 * @param string $password Application password.
	 */
	private function users_me_as( string $login, string $password ): WP_REST_Response {
		$_SERVER['PHP_AUTH_USER'] = $login;
		$_SERVER['PHP_AUTH_PW']   = $password;

		$user_id = apply_filters( 'determine_current_user', false );
		wp_set_current_user( is_int( $user_id ) ? $user_id : 0 );

		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
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
