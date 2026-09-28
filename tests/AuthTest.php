<?php
/**
 * Authentication tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers password login, password reset, REST, and XML-RPC.
 */
class AuthTest extends TestCase {

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
		$this->user_ids = array();
		parent::tearDown();
	}

	public function test_password_login_is_blocked_for_an_agent(): void {
		$user = $this->make_user( Agent_Role::SLUG, 'agent-password-login' );

		$result = wp_authenticate( $user->user_login, 'agent-password-login' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_role_password_login_blocked', $result->get_error_code() );
	}

	public function test_password_login_still_works_for_an_author(): void {
		$user = $this->make_user( 'author', 'author-password-login' );

		$result = wp_authenticate( $user->user_login, 'author-password-login' );

		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( $user->ID, $result->ID );
	}

	public function test_password_reset_is_blocked_for_an_agent_and_allowed_for_an_author(): void {
		$agent  = $this->make_user( Agent_Role::SLUG, 'agent-reset' );
		$author = $this->make_user( 'author', 'author-reset' );

		add_filter(
			'pre_wp_mail',
			static function () {
				return true;
			}
		);

		$agent_result = retrieve_password( $agent->user_login );
		$this->assertInstanceOf( WP_Error::class, $agent_result );
		$this->assertSame( 'no_password_reset', $agent_result->get_error_code() );

		$author_result = retrieve_password( $author->user_login );
		$this->assertTrue( $author_result );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rest_application_password_authenticates_agent_and_author(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$agent  = $this->authenticate_application_password( Agent_Role::SLUG );
		$author = $this->authenticate_application_password( 'author' );

		$this->assertInstanceOf( WP_User::class, $agent );
		$this->assertInstanceOf( WP_User::class, $author );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_xmlrpc_application_password_is_blocked_for_an_agent_only(): void {
		if ( ! defined( 'XMLRPC_REQUEST' ) ) {
			define( 'XMLRPC_REQUEST', true );
		}

		$agent  = $this->authenticate_application_password( Agent_Role::SLUG );
		$author = $this->authenticate_application_password( 'author' );

		$this->assertInstanceOf( WP_Error::class, $agent );
		$this->assertSame( 'agent_role_xmlrpc_blocked', $agent->get_error_code() );
		$this->assertInstanceOf( WP_User::class, $author );
	}

	/**
	 * @param string $role     Role slug.
	 * @param string $password Known login password.
	 */
	private function make_user( string $role, string $password ): WP_User {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => $password,
				'user_email' => $login . '@example.invalid',
				'role'       => $role,
			)
		);

		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		$user = get_userdata( $user_id );
		$this->assertInstanceOf( WP_User::class, $user );

		return $user;
	}

	/**
	 * @param string $role Role slug.
	 * @return WP_User|WP_Error|null
	 */
	private function authenticate_application_password( string $role ) {
		$user    = $this->make_user( $role, wp_generate_password( 24 ) );
		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name' => 'Agent Role Test',
			)
		);

		$this->assertIsArray( $created );

		return wp_authenticate_application_password( null, $user->user_login, $created[0] );
	}
}
