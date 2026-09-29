<?php
/**
 * Activity log writer tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers ability, REST, and admin events recorded through the real hooks.
 */
class WriterTest extends TestCase {

	const ABILITY = 'agent-role/log-probe';

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		Agent_Role_Log::install();
		$this->user_ids = array();
		$_POST          = array();
		$_GET           = array();
		$_REQUEST       = array();
		$this->register_probe();
	}

	protected function tearDown(): void {
		global $wpdb;

		$table = Agent_Role_Log::table();
		foreach ( $this->user_ids as $user_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		}
		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( self::ABILITY );
		}
		remove_all_filters( 'wp_redirect' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_denied_ability_is_stored_and_a_successful_read_is_not(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$read = wp_get_ability( 'core/get-site-info' );
		$this->assertNotNull( $read );
		$grant = static function ( $allcaps ) {
			$allcaps['manage_options'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant, 99 );
		$read->execute( array() );
		remove_filter( 'user_has_cap', $grant, 99 );

		$probe = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $probe );
		$probe->execute();

		update_user_meta( $agent, '_agent_role_log_allow', '1' );
		$probe->execute();

		$names = $this->identifiers( $agent, 'ability' );
		$this->assertContains( self::ABILITY, $names );
		$this->assertNotContains( 'core/get-site-info', $names );

		$denied = 0;
		$done   = 0;
		foreach ( Agent_Role_Log::query( $agent, 'ability' ) as $row ) {
			if ( self::ABILITY !== $row['identifier'] ) {
				continue;
			}
			if ( 'denied' === $row['outcome'] ) {
				++$denied;
			}
			if ( 'success' === $row['outcome'] ) {
				++$done;
			}
		}
		$this->assertSame( 1, $denied );
		$this->assertSame( 1, $done );
	}

	public function test_post_is_stored_and_get_is_not(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		rest_do_request( new WP_REST_Request( 'POST', '/wp/v2/posts' ) );

		$rows = Agent_Role_Log::query( $agent, 'rest' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'POST /wp/v2/posts', $rows[0]['identifier'] );
	}

	public function test_save_stores_a_switch_row_without_a_password(): void {
		$admin = $this->make_user( 'administrator' );
		$agent = $this->make_agent();
		wp_set_current_user( $admin );

		$issued = Agent_Role_Account::issue_password( $agent, $admin );
		$this->assertTrue( $issued );
		$password = Agent_Role_Account::take_password( $admin, $agent );
		$this->assertNotSame( '', $password );

		update_user_meta( $agent, Agent_Role::INSTRUCTIONS_META, Agent_Role::instructions_for( get_userdata( $agent ) ) );

		$_POST['user_id']                 = (string) $agent;
		$_POST['agent_role_agent_nonce']  = wp_create_nonce( 'agent_role_save_agent_' . $agent );
		$_REQUEST['agent_role_agent_nonce'] = $_POST['agent_role_agent_nonce'];
		$_POST['agent_role_caps']         = array( 'edit_posts' );
		$_POST['agent_role_instructions'] = Agent_Role::instructions_for( get_userdata( $agent ) );

		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirect' );
			}
		);

		try {
			Agent_Role_Admin::handle_save_agent();
			$this->fail( 'The handler should redirect.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}

		$switches = 0;
		foreach ( Agent_Role_Log::query( $agent, 'admin' ) as $row ) {
			$this->assertStringNotContainsString( $password, wp_json_encode( $row ) );
			if ( 'switches' === $row['identifier'] ) {
				++$switches;
				$this->assertSame( 'changed', $row['outcome'] );
			}
		}
		$this->assertSame( 1, $switches );
	}

	public function test_creating_and_deleting_an_agent_are_logged(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$login  = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$result = Agent_Role_Account::create( $login, 'Logged Agent', $admin );
		$this->assertIsArray( $result );
		$agent            = $result['user_id'];
		$this->user_ids[] = $agent;

		$created = 0;
		foreach ( Agent_Role_Log::query( $agent, 'admin' ) as $row ) {
			if ( 'account-created' === $row['identifier'] ) {
				++$created;
				$this->assertSame( 'Agent account created', $row['detail'] );
				$this->assertSame( 'changed', $row['outcome'] );
			}
		}
		$this->assertSame( 1, $created );

		wp_delete_user( $agent );

		$deleted = 0;
		foreach ( Agent_Role_Log::query( $agent, 'admin' ) as $row ) {
			if ( 'account-deleted' === $row['identifier'] ) {
				++$deleted;
				$this->assertSame( 'Agent account deleted', $row['detail'] );
			}
		}
		$this->assertSame( 1, $deleted );
	}

	/**
	 * @return string[]
	 */
	private function identifiers( $user_id, $event_type ) {
		$names = array();
		foreach ( Agent_Role_Log::query( $user_id, $event_type ) as $row ) {
			$names[] = $row['identifier'];
		}
		return $names;
	}

	private function register_probe() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->fail( 'The Abilities API is not loaded.' );
		}
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry && $registry->is_registered( self::ABILITY ) ) {
			return;
		}

		$previous = isset( $GLOBALS['wp_filter']['wp_abilities_api_init'] ) ? $GLOBALS['wp_filter']['wp_abilities_api_init'] : null;
		remove_all_actions( 'wp_abilities_api_init' );
		add_action(
			'wp_abilities_api_init',
			static function () {
				if ( WP_Abilities_Registry::get_instance()->is_registered( WriterTest::ABILITY ) ) {
					return;
				}
				wp_register_ability(
					WriterTest::ABILITY,
					array(
						'label'               => 'Log probe',
						'description'         => 'Probe used by the activity log tests.',
						'category'            => 'site',
						'permission_callback' => static function () {
							return '1' === (string) get_user_meta( get_current_user_id(), '_agent_role_log_allow', true );
						},
						'execute_callback'    => static function () {
							return true;
						},
					)
				);
			}
		);
		do_action( 'wp_abilities_api_init', WP_Abilities_Registry::get_instance() );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( $previous instanceof WP_Hook ) {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $previous;
		}
	}

	private function make_user( $role ) {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
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

	private function make_agent() {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = Agent_Role_Account::insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@example.invalid',
				'role'       => Agent_Role::SLUG,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;
		return $user_id;
	}
}
