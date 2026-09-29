<?php
/**
 * Role-switch guard tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers keeping people and Agents in their own roles.
 */
class RoleSwitchTest extends TestCase {

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
		global $wpdb;

		$table = Agent_Role_Log::table();
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_create_marks_the_account(): void {
		$admin  = $this->make_user( 'administrator' );
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];

		$this->assertSame( '1', get_user_meta( $result['user_id'], Agent_Role_Account::META, true ) );
		$this->assertSame( array( Agent_Role::SLUG ), array_values( get_userdata( $result['user_id'] )->roles ) );
	}

	public function test_a_person_assigned_the_agent_role_is_put_back(): void {
		$admin  = $this->make_user( 'administrator' );
		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $admin );

		get_userdata( $editor )->set_role( Agent_Role::SLUG );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $editor )->roles ) );
		$this->assertSame( '', get_user_meta( $editor, Agent_Role_Account::META, true ) );

		ob_start();
		Agent_Role_Account::show_switch_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'was not created as an Agent', $notice );
	}

	public function test_an_agent_moved_to_editor_is_put_back_and_keeps_the_password(): void {
		$admin  = $this->make_user( 'administrator' );
		$result = Agent_Role_Account::create( $this->unique_login(), 'Helper', $admin );
		$this->assertIsArray( $result );
		$this->user_ids[] = $result['user_id'];
		wp_set_current_user( $admin );

		get_userdata( $result['user_id'] )->set_role( 'editor' );

		$user = get_userdata( $result['user_id'] );
		$this->assertSame( array( Agent_Role::SLUG ), array_values( $user->roles ) );
		$this->assertIsArray( Agent_Role_Account::managed_password( $user->ID ) );

		ob_start();
		Agent_Role_Account::show_switch_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'Agent accounts stay Agents', $notice );
	}

	public function test_adding_the_agent_role_beside_a_person_removes_it(): void {
		$editor = $this->make_user( 'editor' );

		get_userdata( $editor )->add_role( Agent_Role::SLUG );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $editor )->roles ) );
	}

	public function test_an_unmarked_agent_can_still_be_moved(): void {
		$user_id = $this->make_user( 'subscriber' );
		remove_action( 'set_user_role', array( 'Agent_Role_Account', 'guard_set_role' ), 10 );
		get_userdata( $user_id )->set_role( Agent_Role::SLUG );
		add_action( 'set_user_role', array( 'Agent_Role_Account', 'guard_set_role' ), 10, 3 );

		get_userdata( $user_id )->set_role( 'editor' );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user_id )->roles ) );
	}

	public function test_profile_note_points_at_add_agent(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		ob_start();
		Agent_Role_Admin::role_note( get_userdata( $admin ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Users → Add Agent', $html );

		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $editor );
		ob_start();
		Agent_Role_Admin::role_note( get_userdata( $editor ) );
		$editor_html = ob_get_clean();
		$this->assertStringNotContainsString( 'Users → Add Agent', $editor_html );
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
