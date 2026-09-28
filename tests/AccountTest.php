<?php
/**
 * Account creation tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the creator, the one-time password, and the admin handler.
 */
class AccountTest extends TestCase {

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
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		$this->user_ids = array();
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		parent::tearDown();
	}

	public function test_create_makes_an_agent_who_cannot_log_in_with_the_discarded_password(): void {
		$admin      = $this->make_admin();
		$plaintext  = null;
		$capture    = static function ( $data, $update, $user_id, $userdata ) use ( &$plaintext ) {
			if ( isset( $userdata['user_pass'] ) ) {
				$plaintext = $userdata['user_pass'];
			}
			return $data;
		};
		add_filter( 'wp_pre_insert_user_data', $capture, 10, 4 );

		$login  = $this->unique_login();
		$result = Agent_Role_Account::create( $login, 'Helper', $admin );
		remove_filter( 'wp_pre_insert_user_data', $capture, 10 );

		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$user = get_userdata( $result['user_id'] );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( Agent_Role::SLUG ), array_values( $user->roles ) );
		$this->assertSame( $user->user_email, is_email( $user->user_email ) );
		$this->assertStringEndsWith( '@example.invalid', $user->user_email );
		$this->assertIsString( $plaintext );

		$login_attempt = wp_authenticate( $user->user_login, $plaintext );
		$this->assertInstanceOf( WP_Error::class, $login_attempt );
		$this->assertSame( 'agent_role_password_login_blocked', $login_attempt->get_error_code() );

		$shown = Agent_Role_Account::take_password( $admin, $user->ID );
		$this->assertNotSame( '', $shown );
		$this->assertNotSame( $plaintext, $shown );
		$this->assertFalse( wp_check_password( $shown, $user->user_pass, $user->ID ) );

		$stored = Agent_Role_Account::managed_password( $user->ID );
		$this->assertIsArray( $stored );
		// wp_authenticate_application_password() strips the display spaces before hashing.
		$raw = preg_replace( '/[^a-z\d]/i', '', $shown );
		$this->assertTrue( WP_Application_Passwords::check_password( $raw, $stored['password'] ) );

		$this->assertPasswordIsNotStored( $shown );
		$this->assertSame( '', Agent_Role_Account::take_password( $admin, $user->ID ) );
	}

	public function test_a_second_password_is_refused_until_revoke(): void {
		$admin  = $this->make_admin();
		$login  = $this->unique_login();
		$result = Agent_Role_Account::create( $login, 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$again = Agent_Role_Account::issue_password( $result['user_id'], $admin );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'agent_role_password_exists', $again->get_error_code() );

		$this->assertTrue( Agent_Role_Account::revoke( $result['user_id'] ) );
		$this->assertNull( Agent_Role_Account::managed_password( $result['user_id'] ) );
		$this->assertTrue( Agent_Role_Account::issue_password( $result['user_id'], $admin ) );
		$this->assertIsArray( Agent_Role_Account::managed_password( $result['user_id'] ) );
	}

	public function test_new_user_notification_does_not_fire(): void {
		$admin = $this->make_admin();
		$fired = false;
		add_filter(
			'wp_new_user_notification_email',
			static function ( $email ) use ( &$fired ) {
				$fired = true;
				return $email;
			}
		);

		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$this->assertFalse( $fired );
	}

	public function test_handler_rejects_a_user_without_create_users_and_a_bad_nonce(): void {
		$subscriber = $this->make_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		$this->catch_wp_die();

		$_POST['agent_role_username'] = $this->unique_login();
		try {
			Agent_Role_Admin::handle_add();
			$this->fail( 'A subscriber should not create an agent.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'permission', strtolower( $exception->getMessage() ) );
		}
		$this->assertFalse( username_exists( $_POST['agent_role_username'] ) );

		$admin = $this->make_admin();
		wp_set_current_user( $admin );
		$_POST['agent_role_username'] = $this->unique_login();
		unset( $_REQUEST['agent_role_nonce'] );
		try {
			Agent_Role_Admin::handle_add();
			$this->fail( 'A missing nonce should stop the handler.' );
		} catch ( RuntimeException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertFalse( username_exists( $_POST['agent_role_username'] ) );
	}

	public function test_successful_handler_redirect_does_not_carry_the_password(): void {
		$admin = $this->make_admin();
		wp_set_current_user( $admin );
		$login = $this->unique_login();

		$_REQUEST['agent_role_nonce']        = wp_create_nonce( 'agent_role_add_agent' );
		$_POST['agent_role_nonce']           = $_REQUEST['agent_role_nonce'];
		$_POST['agent_role_username']        = $login;
		$_POST['agent_role_display_name']    = 'Helper';

		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $target ) use ( &$location ) {
				$location = $target;
				throw new RuntimeException( 'redirect' );
			}
		);

		try {
			Agent_Role_Admin::handle_add();
			$this->fail( 'The handler should redirect.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}

		$user_id = username_exists( $login );
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		$shown = Agent_Role_Account::take_password( $admin, $user_id );
		$this->assertNotSame( '', $shown );
		$this->assertStringNotContainsString( $shown, $location );
		$this->assertStringNotContainsString( rawurlencode( $shown ), $location );
		$this->assertStringContainsString( 'created=' . $user_id, $location );
	}

	public function test_admin_screen_shows_the_password_once(): void {
		$admin  = $this->make_admin();
		wp_set_current_user( $admin );
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$expected = get_transient( Agent_Role_Account::transient_key( $admin, $result['user_id'] ) );
		$this->assertIsString( $expected );
		$this->assertNotSame( '', $expected );

		$_GET['created']  = $result['user_id'];
		$_GET['_wpnonce'] = wp_create_nonce( 'agent_role_show_' . $result['user_id'] );
		ob_start();
		Agent_Role_Admin::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( $expected, $html );
		$this->assertStringNotContainsString( $expected, $this->second_render( $admin, $result['user_id'] ) );
		$this->assertSame( '', Agent_Role_Account::take_password( $admin, $result['user_id'] ) );
	}

	public function test_agent_is_sent_away_from_wp_admin_and_hidden_from_the_role_list(): void {
		$agent = $this->make_user( Agent_Role::SLUG );
		wp_set_current_user( $agent );

		$this->assertFalse( Agent_Role_Account::hide_admin_bar( true ) );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location );
			}
		);

		try {
			Agent_Role_Account::block_admin();
			$this->fail( 'An agent should leave wp-admin.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect:' . home_url( '/' ), $exception->getMessage() );
		}

		$admin = $this->make_admin();
		wp_set_current_user( $admin );
		Agent_Role_Account::block_admin();
		$this->assertTrue( Agent_Role_Account::hide_admin_bar( true ) );
		$this->assertArrayNotHasKey( Agent_Role::SLUG, get_editable_roles() );
		$this->assertArrayHasKey( 'author', get_editable_roles() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rest_basic_auth_still_authenticates_after_account_creation(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$admin  = $this->make_admin();
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$password = Agent_Role_Account::take_password( $admin, $result['user_id'] );
		$user     = get_userdata( $result['user_id'] );
		$auth     = wp_authenticate_application_password( null, $user->user_login, $password );

		$this->assertInstanceOf( WP_User::class, $auth );
		$this->assertSame( $user->ID, $auth->ID );
	}

	public function test_uninstall_removes_the_password_and_keeps_the_user(): void {
		$admin  = $this->make_admin();
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		$this->assertIsArray( Agent_Role_Account::managed_password( $result['user_id'] ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agent-role/agent-role.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertNull( get_role( Agent_Role::SLUG ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $result['user_id'] ) );
		$this->assertNull( Agent_Role_Account::managed_password( $result['user_id'] ) );
	}

	/**
	 * @param string $shown Plaintext application password.
	 */
	private function assertPasswordIsNotStored( string $shown ): void {
		global $wpdb;

		$like   = '%' . $wpdb->esc_like( $shown ) . '%';
		$option = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $like ) );
		$meta   = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_value LIKE %s LIMIT 1", $like ) );

		$this->assertNull( $option );
		$this->assertNull( $meta );
	}

	private function second_render( int $admin_id, int $user_id ): string {
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

	private function make_admin(): int {
		return $this->make_user( 'administrator' );
	}

	private function make_user( string $role ): int {
		$login   = $this->unique_login();
		$user_id = wp_insert_user(
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
