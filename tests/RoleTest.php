<?php
/**
 * Role registration tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers activation, deactivation, and uninstall of the Agent role.
 */
class RoleTest extends TestCase {

	/**
	 * Users created by this process.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = array();
	}

	public function test_activation_sets_the_author_capability_list(): void {
		$this->reset_role();

		activate_plugin( 'agent-role/agent-role.php' );

		$role = get_role( Agent_Role::SLUG );
		$this->assertNotNull( $role );
		$this->assertSame( 'rootsandfruit_agent_role', Agent_Role::SLUG );
		$this->assertSame( 'Agent', $this->display_name() );
		$this->assertSame( $this->expected_caps(), $this->sorted_caps( $role->capabilities ) );

		deactivate_plugins( 'agent-role/agent-role.php' );
	}

	public function test_activating_again_does_not_change_capabilities(): void {
		$this->reset_role();
		Agent_Role::activate();

		$before = $this->sorted_caps( get_role( Agent_Role::SLUG )->capabilities );
		Agent_Role::activate();
		$after = $this->sorted_caps( get_role( Agent_Role::SLUG )->capabilities );

		$this->assertSame( $before, $after );
		$this->assertSame( $this->expected_caps(), $after );
	}

	public function test_deactivation_leaves_the_role_in_place(): void {
		$this->reset_role();
		activate_plugin( 'agent-role/agent-role.php' );

		deactivate_plugins( 'agent-role/agent-role.php' );

		$this->assertNotNull( get_role( Agent_Role::SLUG ) );
		$this->assertSame(
			$this->expected_caps(),
			$this->sorted_caps( get_role( Agent_Role::SLUG )->capabilities )
		);
	}

	public function test_uninstall_removes_the_role_and_keeps_the_user(): void {
		$this->reset_role();
		Agent_Role::activate();

		$user_id = wp_insert_user(
			array(
				'user_login' => 'agentrole_test_keep',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'agentrole_test_keep@example.invalid',
				'role'       => Agent_Role::SLUG,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		$this->run_uninstall();

		$this->assertNull( get_role( Agent_Role::SLUG ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
	}

	public function test_existing_role_is_not_overwritten(): void {
		remove_role( Agent_Role::SLUG );
		add_role( Agent_Role::SLUG, 'Custom', array( 'read' => true ) );

		Agent_Role::activate();

		$role = get_role( Agent_Role::SLUG );
		$this->assertSame( 'Custom', $this->display_name() );
		$this->assertSame( array( 'read' => true ), $this->sorted_caps( $role->capabilities ) );
		$this->assertArrayNotHasKey( 'edit_posts', $role->capabilities );
	}

	private function reset_role(): void {
		remove_role( Agent_Role::SLUG );
		if ( is_plugin_active( 'agent-role/agent-role.php' ) ) {
			deactivate_plugins( 'agent-role/agent-role.php' );
		}
	}

	private function run_uninstall(): void {
		$uninstall = dirname( __DIR__ ) . '/uninstall.php';
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agent-role/agent-role.php' );
		}
		require $uninstall;
	}

	/**
	 * Display name stored by Core. WP_Role::$name is the slug.
	 */
	private function display_name(): string {
		$names = wp_roles()->role_names;
		return isset( $names[ Agent_Role::SLUG ] ) ? $names[ Agent_Role::SLUG ] : '';
	}

	/**
	 * @param array<string,bool> $caps Capabilities to sort.
	 * @return array<string,bool>
	 */
	private function sorted_caps( array $caps ): array {
		ksort( $caps );
		return $caps;
	}

	/**
	 * @return array<string,bool>
	 */
	private function expected_caps(): array {
		return array(
			'delete_posts'           => true,
			'delete_published_posts' => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'read'                   => true,
			'upload_files'           => true,
		);
	}
}
