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
		global $wpdb;

		$table = Agent_Role_Log::table();
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
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

		update_option(
			Agent_Role::PERSONA_DEFAULTS_OPTION,
			array(
				'writer' => array(
					'caps'      => array( 'edit_posts' => true ),
					'actions'   => array(),
					'abilities' => array(),
				),
			),
			false
		);

		$this->run_uninstall();

		$this->assertNull( get_role( Agent_Role::SLUG ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
		$this->assertFalse( get_option( Agent_Role::PERSONA_DEFAULTS_OPTION ) );
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

	public function test_delete_capability_can_be_turned_off_for_one_agent(): void {
		$this->reset_role();
		Agent_Role::activate();

		$denied = $this->make_managed_agent();
		$kept   = $this->make_managed_agent();
		Agent_Role::apply_cap_map(
			$denied,
			array(
				'edit_posts'             => true,
				'edit_published_posts'   => true,
				'publish_posts'          => true,
				'upload_files'           => true,
				'delete_posts'           => false,
				'delete_published_posts' => false,
			)
		);

		$denied_user = get_userdata( $denied );
		$kept_user   = get_userdata( $kept );
		$this->assertFalse( $denied_user->has_cap( 'delete_posts' ) );
		$this->assertFalse( $denied_user->has_cap( 'delete_published_posts' ) );
		$this->assertTrue( $kept_user->has_cap( 'delete_posts' ) );
		$this->assertTrue( $kept_user->has_cap( 'delete_published_posts' ) );

		$role = get_role( Agent_Role::SLUG );
		$this->assertArrayHasKey( 'delete_posts', $role->capabilities );
		$this->assertArrayHasKey( 'delete_published_posts', $role->capabilities );

		$denied_user->set_role( 'editor' );
		$again = get_userdata( $denied );
		$this->assertTrue( Agent_Role::is_agent( $again ) );
		$this->assertFalse( $again->has_cap( 'delete_posts' ) );
		$this->assertFalse( $again->has_cap( 'delete_published_posts' ) );
	}

	public function test_legacy_cap_meta_is_copied_once(): void {
		$this->reset_role();
		Agent_Role::activate();
		delete_option( Agent_Role::MIGRATED_OPTION );

		$user_id = $this->make_managed_agent();
		update_user_meta(
			$user_id,
			Agent_Role::CAPS_META,
			array(
				'edit_posts'             => true,
				'edit_published_posts'   => true,
				'publish_posts'          => true,
				'upload_files'           => false,
				'delete_posts'           => true,
				'delete_published_posts' => true,
			)
		);

		Agent_Role::migrate_caps();

		$user = get_userdata( $user_id );
		$this->assertFalse( $user->has_cap( 'upload_files' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, Agent_Role::CAPS_META ) );

		$stored = get_user_meta( $user_id, $user->cap_key, true );
		Agent_Role::migrate_caps();
		$this->assertSame( $stored, get_user_meta( $user_id, $user->cap_key, true ) );
	}

	private function make_managed_agent(): int {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		add_filter( 'insert_custom_user_meta', array( 'Agent_Role_Account', 'add_managed_meta' ), 10, 4 );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@example.invalid',
				'role'       => Agent_Role::SLUG,
			)
		);
		remove_filter( 'insert_custom_user_meta', array( 'Agent_Role_Account', 'add_managed_meta' ), 10 );
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;

		return $user_id;
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
